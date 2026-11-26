<?php
/**
 * Mailchimp (Marketing API 3.0): adds or updates audience members.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Mailchimp service.
 */
final class Service_Mailchimp extends Service_Api {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailchimp';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailchimp';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add subscribers to your Mailchimp audiences, with tags and double opt-in.', 'aluleth-smtp' );
	}

	/**
	 * Where to find the key.
	 *
	 * @return string
	 */
	public function key_help() {
		return __( 'In Mailchimp: Profile › Extras › API keys › Create A Key. It ends with your data centre, for example -us21.', 'aluleth-smtp' );
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'tag_names', 'fields', 'double_optin' );
	}

	/**
	 * API base URL for a key (the data centre is the part after the last dash).
	 *
	 * @param string $key API key.
	 * @return string Empty when the key has no valid data centre.
	 */
	private static function base( $key ) {
		$dc = substr( (string) strrchr( $key, '-' ), 1 );
		return preg_match( '/^[a-z]+[0-9]+$/', $dc ) ? 'https://' . $dc . '.api.mailchimp.com/3.0' : '';
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /3.0.
	 * @param array|null $body   JSON body.
	 * @param string     $key    Key (default: the saved one).
	 * @return array|\WP_Error
	 */
	private function call( $method, $path, $body = null, $key = '' ) {
		$key  = '' !== $key ? $key : $this->key();
		$base = self::base( $key );
		if ( '' === $base ) {
			return self::fail( __( 'This does not look like a Mailchimp API key: it should end with your data centre, for example -us21.', 'aluleth-smtp' ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
		return self::http( $method, $base . $path, array( 'Authorization' => 'Basic ' . base64_encode( 'aluleth:' . $key ) ), $body );
	}

	/**
	 * Error text sent by Mailchimp.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function detail( array $response ) {
		$body = $response['body'];
		return trim( ( isset( $body['title'] ) ? $body['title'] . '. ' : '' ) . ( isset( $body['detail'] ) ? $body['detail'] : '' ) );
	}

	/**
	 * Checks the key.
	 *
	 * @param array $credentials Credentials: key.
	 * @return string|\WP_Error Account name.
	 */
	public function verify( array $credentials ) {
		$key      = isset( $credentials['key'] ) ? trim( (string) $credentials['key'] ) : '';
		$response = $this->call( 'GET', '/?fields=account_name,email', null, $key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			return $this->http_error( $response, self::detail( $response ) );
		}
		return isset( $response['body']['account_name'] ) ? (string) $response['body']['account_name'] : 'Mailchimp';
	}

	/**
	 * Audiences.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				$response = $this->call( 'GET', '/lists?count=100&fields=lists.id,lists.name' );
				if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
					return new \WP_Error( 'lists' );
				}
				$out = array();
				foreach ( isset( $response['body']['lists'] ) ? (array) $response['body']['lists'] : array() as $list ) {
					$out[ (string) $list['id'] ] = (string) $list['name'];
				}
				return $out;
			}
		);
	}

	/**
	 * Merge fields of one audience: tag => name.
	 *
	 * @param string $list_id Audience id.
	 * @return array<string, string>
	 */
	private function merge_fields( $list_id ) {
		$key  = 'aluleth_svc_mailchimp_mf_' . md5( $list_id );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$response = $this->call( 'GET', '/lists/' . rawurlencode( $list_id ) . '/merge-fields?count=100&fields=merge_fields.tag,merge_fields.name' );
			if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
				return array();
			}
			$data = array();
			foreach ( isset( $response['body']['merge_fields'] ) ? (array) $response['body']['merge_fields'] : array() as $field ) {
				$data[ (string) $field['tag'] ] = (string) $field['name'];
			}
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * Merge fields of every audience, except the name and phone (filled automatically).
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		return $this->cached(
			'fields',
			function () {
				$out = array();
				foreach ( array_keys( $this->lists() ) as $list_id ) {
					$out += $this->merge_fields( $list_id );
				}
				unset( $out['FNAME'], $out['LNAME'], $out['PHONE'] );
				return $out;
			}
		);
	}

	/**
	 * Adds or updates the member in each chosen audience.
	 *
	 * New members are "subscribed", or "pending" with double opt-in (Mailchimp
	 * then sends its confirmation email). Existing members keep their status:
	 * someone who unsubscribed stays unsubscribed.
	 *
	 * @param array $feed    Feed: lists, tags (names), double_optin, map.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$basics = $this->basics( $feed, $payload );
		if ( is_wp_error( $basics ) ) {
			return $basics;
		}
		list( $email, $lists ) = $basics;
		$hash                  = md5( strtolower( $email ) );
		$names                 = $this->lists();
		$done                  = array();

		foreach ( $lists as $list_id ) {
			$known = $this->merge_fields( $list_id );
			$merge = array_filter(
				array(
					'FNAME' => (string) $payload['first_name'],
					'LNAME' => (string) $payload['last_name'],
					'PHONE' => (string) $payload['phone'],
				),
				'strlen'
			);
			$merge = array_merge( $merge, self::mapped( $feed, $payload ) );
			$merge = $known ? array_intersect_key( $merge, $known ) : array_intersect_key( $merge, array_flip( array( 'FNAME', 'LNAME' ) ) );
			$body  = array(
				'email_address' => $email,
				'status_if_new' => ! empty( $feed['double_optin'] ) ? 'pending' : 'subscribed',
			);
			if ( $merge ) {
				$body['merge_fields'] = (object) $merge;
			}
			$path     = '/lists/' . rawurlencode( $list_id ) . '/members/' . $hash;
			$response = $this->call( 'PUT', $path . '?skip_merge_validation=true', $body );
			if ( ! is_wp_error( $response ) && 400 === $response['code'] && isset( $response['body']['title'] ) && 'Invalid Resource' === $response['body']['title'] && $merge ) {
				// A value Mailchimp does not accept (often a phone format): keep the subscription, send the name only.
				$body['merge_fields'] = (object) array_intersect_key( $merge, array_flip( array( 'FNAME', 'LNAME' ) ) );
				$response             = $this->call( 'PUT', $path . '?skip_merge_validation=true', $body );
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 200 !== $response['code'] ) {
				$title = isset( $response['body']['title'] ) ? (string) $response['body']['title'] : '';
				if ( in_array( $title, array( 'Member In Compliance State', 'Forgotten Email Not Subscribed' ), true ) ) {
					return self::skip( __( 'Mailchimp keeps this person unsubscribed (they unsubscribed or asked to be forgotten), so they were not added again.', 'aluleth-smtp' ) );
				}
				if ( 404 === $response['code'] ) {
					return self::fail( __( 'The chosen Mailchimp audience no longer exists. Choose another one in the form’s Integrations.', 'aluleth-smtp' ) );
				}
				return $this->http_error( $response, self::detail( $response ) );
			}
			$status = isset( $response['body']['status'] ) ? (string) $response['body']['status'] : '';

			$tags = array_values( array_filter( array_map( 'strval', (array) $feed['tags'] ) ) );
			if ( $tags ) {
				$this->call(
					'POST',
					$path . '/tags',
					array(
						'tags' => array_map(
							static function ( $tag ) {
								return array(
									'name'   => $tag,
									'status' => 'active',
								);
							},
							$tags
						),
					)
				);
			}
			$done[] = array( isset( $names[ $list_id ] ) ? $names[ $list_id ] : $list_id, $status );
		}

		$parts = array();
		foreach ( $done as $item ) {
			switch ( $item[1] ) {
				case 'pending':
					/* translators: %s: audience name. */
					$parts[] = sprintf( __( '%s: waiting for the email confirmation', 'aluleth-smtp' ), $item[0] );
					break;
				case 'unsubscribed':
				case 'cleaned':
					/* translators: %s: audience name. */
					$parts[] = sprintf( __( '%s: still unsubscribed', 'aluleth-smtp' ), $item[0] );
					break;
				default:
					/* translators: %s: audience name. */
					$parts[] = sprintf( __( '%s: subscribed', 'aluleth-smtp' ), $item[0] );
			}
		}
		return implode( ' · ', $parts ) . '.';
	}

	/**
	 * Lists are called audiences.
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Audiences', 'aluleth-smtp' );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'First name, last name and phone go to FNAME, LNAME and PHONE. People who unsubscribed in Mailchimp stay unsubscribed.', 'aluleth-smtp' );
	}
}
