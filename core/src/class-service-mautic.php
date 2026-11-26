<?php
/**
 * Mautic (self-hosted or cloud): create or update the contact, add tags and
 * segments. Uses the Mautic API with Basic authentication.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Mautic service.
 */
final class Service_Mautic extends Service_Api {

	/**
	 * Core contact fields filled from the contact (not offered for mapping).
	 */
	const CORE = array( 'email', 'firstname', 'lastname', 'phone', 'mobile', 'tags' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mautic';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mautic';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create or update Mautic contacts, with tags and segments.', 'aluleth-smtp' );
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'tag_names', 'fields' );
	}

	/**
	 * Name of the lists.
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Segments (optional)', 'aluleth-smtp' );
	}

	/**
	 * Credential fields.
	 *
	 * @return array<string, array>
	 */
	public function credential_fields() {
		return array(
			'url'      => array(
				'label' => __( 'Mautic address', 'aluleth-smtp' ),
				'help'  => __( 'For example https://mautic.example.com. In Mautic › Settings › Configuration › API Settings, turn on "API enabled" and "Enable HTTP basic auth".', 'aluleth-smtp' ),
				'type'  => 'url',
			),
			'username' => array(
				'label' => __( 'Mautic username', 'aluleth-smtp' ),
				'help'  => __( 'A Mautic user that may create contacts. A user made just for this is best.', 'aluleth-smtp' ),
				'type'  => 'text',
			),
			'password' => array(
				'label' => __( 'Password', 'aluleth-smtp' ),
				'help'  => '',
				'type'  => 'password',
			),
		);
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method      Method.
	 * @param string     $path        Path after /api.
	 * @param array|null $body        JSON body.
	 * @param array      $credentials Credentials (default: the saved ones).
	 * @return array|\WP_Error
	 */
	private function call( $method, $path, $body = null, array $credentials = array() ) {
		$c   = $credentials ? $credentials : $this->credentials();
		$url = isset( $c['url'] ) ? untrailingslashit( (string) $c['url'] ) : '';
		// Only administrators enter this address (Aluleth › Connections); a self-hosted Mautic may be on a private network.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return self::fail( __( 'The Mautic address is not valid.', 'aluleth-smtp' ) );
		}
		return self::http(
			$method,
			$url . '/api' . $path,
			array( 'Authorization' => 'Basic ' . base64_encode( ( isset( $c['username'] ) ? $c['username'] : '' ) . ':' . ( isset( $c['password'] ) ? $c['password'] : '' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
			$body
		);
	}

	/**
	 * Checks the credentials.
	 *
	 * @param array $credentials Credentials.
	 * @return string|\WP_Error Account.
	 */
	public function verify( array $credentials ) {
		$response = $this->call( 'GET', '/users/self', null, $credentials );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 === $response['code'] && ! empty( $response['body']['username'] ) ) {
			return $response['body']['username'] . ' · ' . wp_parse_url( (string) $credentials['url'], PHP_URL_HOST );
		}
		if ( 401 === $response['code'] || 403 === $response['code'] ) {
			return self::fail( __( 'Mautic refused the username or password, or HTTP basic auth is off (Mautic › Settings › Configuration › API Settings).', 'aluleth-smtp' ) );
		}
		return self::fail( __( 'No Mautic API answered at this address. Check the address, and that "API enabled" is on in Mautic › Settings › Configuration › API Settings.', 'aluleth-smtp' ) );
	}

	/**
	 * Segments.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				$response = $this->call( 'GET', '/segments?limit=200&minimal=1' );
				if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
					return is_wp_error( $response ) ? $response : $this->http_error( $response );
				}
				$out = array();
				foreach ( isset( $response['body']['lists'] ) ? (array) $response['body']['lists'] : array() as $list ) {
					if ( isset( $list['id'], $list['name'] ) ) {
						$out[ (string) $list['id'] ] = (string) $list['name'];
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Custom contact fields.
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		return $this->cached(
			'fields',
			function () {
				$response = $this->call( 'GET', '/fields/contact?limit=200&minimal=1' );
				if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
					return is_wp_error( $response ) ? $response : $this->http_error( $response );
				}
				$out = array();
				foreach ( isset( $response['body']['fields'] ) ? (array) $response['body']['fields'] : array() as $field ) {
					if ( empty( $field['alias'] ) || in_array( $field['alias'], self::CORE, true ) || ( isset( $field['isPublished'] ) && ! $field['isPublished'] ) ) {
						continue;
					}
					$out[ (string) $field['alias'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['alias'];
				}
				return $out;
			}
		);
	}

	/**
	 * Creates or updates the contact, then adds it to the segments.
	 *
	 * @param array $feed    Feed.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		if ( ! $this->connected() ) {
			return self::fail( $this->missing_text() );
		}
		$email = Util::email( $payload['email'] );
		if ( '' === $email ) {
			return self::skip( __( 'No email address in this entry.', 'aluleth-smtp' ) );
		}
		$body = array_merge(
			self::mapped( $feed, $payload ),
			array_filter(
				array(
					'email'     => $email,
					'firstname' => (string) $payload['first_name'],
					'lastname'  => (string) $payload['last_name'],
					'phone'     => (string) $payload['phone'],
				)
			)
		);
		if ( ! empty( $feed['tags'] ) ) {
			$body['tags'] = array_values( (array) $feed['tags'] );
		}
		$body['overwriteWithBlank'] = false;
		$response                   = $this->call( 'POST', '/contacts/new', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( $response['code'], array( 200, 201 ), true ) || empty( $response['body']['contact']['id'] ) ) {
			$detail = isset( $response['body']['errors'][0]['message'] ) ? (string) $response['body']['errors'][0]['message'] : '';
			return $this->http_error( $response, $detail );
		}
		$contact = (int) $response['body']['contact']['id'];
		$added   = 0;
		foreach ( (array) $feed['lists'] as $segment ) {
			$segment = absint( $segment );
			if ( ! $segment ) {
				continue;
			}
			$result = $this->call( 'POST', '/segments/' . $segment . '/contact/' . $contact . '/add', array() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( 200 !== $result['code'] ) {
				return $this->http_error( $result );
			}
			++$added;
		}
		/* translators: 1: Mautic contact id, 2: number of segments. */
		return sprintf( _n( 'Mautic contact #%1$d saved, added to %2$d segment.', 'Mautic contact #%1$d saved, added to %2$d segments.', $added, 'aluleth-smtp' ), $contact, $added );
	}
}
