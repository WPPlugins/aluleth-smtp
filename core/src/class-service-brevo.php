<?php
/**
 * Brevo (API v3): creates or updates contacts and adds them to lists.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Brevo service.
 */
final class Service_Brevo extends Service_Api {

	/**
	 * API base URL.
	 */
	const BASE = 'https://api.brevo.com/v3';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'brevo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Brevo';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create or update Brevo contacts and add them to your lists.', 'aluleth-smtp' );
	}

	/**
	 * Where to find the key.
	 *
	 * @return string
	 */
	public function key_help() {
		return __( 'In Brevo: your name (top right) › SMTP & API › API Keys › Generate a new API key.', 'aluleth-smtp' );
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'fields' );
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /v3.
	 * @param array|null $body   JSON body.
	 * @param string     $key    Key (default: the saved one).
	 * @return array|\WP_Error
	 */
	private function call( $method, $path, $body = null, $key = '' ) {
		return self::http( $method, self::BASE . $path, array( 'api-key' => '' !== $key ? $key : $this->key() ), $body );
	}

	/**
	 * Error text sent by Brevo.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function detail( array $response ) {
		return isset( $response['body']['message'] ) ? (string) $response['body']['message'] : '';
	}

	/**
	 * Checks the key.
	 *
	 * @param array $credentials Credentials: key.
	 * @return string|\WP_Error Account name.
	 */
	public function verify( array $credentials ) {
		$response = $this->call( 'GET', '/account', null, isset( $credentials['key'] ) ? trim( (string) $credentials['key'] ) : '' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			return $this->http_error( $response, self::detail( $response ) );
		}
		$body = $response['body'];
		return ! empty( $body['companyName'] ) ? (string) $body['companyName'] : ( isset( $body['email'] ) ? (string) $body['email'] : 'Brevo' );
	}

	/**
	 * Lists (Brevo returns 50 per page).
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				$out = array();
				for ( $offset = 0; $offset < 500; $offset += 50 ) {
					$response = $this->call( 'GET', '/contacts/lists?limit=50&offset=' . $offset . '&sort=desc' );
					if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
						return $out ? $out : new \WP_Error( 'lists' );
					}
					$page = isset( $response['body']['lists'] ) ? (array) $response['body']['lists'] : array();
					foreach ( $page as $list ) {
						$out[ (string) $list['id'] ] = (string) $list['name'];
					}
					if ( count( $page ) < 50 ) {
						break;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Contact attributes of the "normal" category: name => name.
	 *
	 * @return array<string, string>
	 */
	private function attributes() {
		$key  = 'aluleth_svc_brevo_attributes';
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$response = $this->call( 'GET', '/contacts/attributes' );
			if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
				return array();
			}
			$data = array();
			foreach ( isset( $response['body']['attributes'] ) ? (array) $response['body']['attributes'] : array() as $attribute ) {
				if ( isset( $attribute['category'] ) && 'normal' === $attribute['category'] && ! empty( $attribute['name'] ) ) {
					$data[ (string) $attribute['name'] ] = (string) $attribute['name'];
				}
			}
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * Attribute names used for the first and last name (French accounts use PRENOM and NOM).
	 *
	 * @return array{0: string, 1: string}
	 */
	private function name_attributes() {
		$attributes = $this->attributes();
		$first      = isset( $attributes['FIRSTNAME'] ) || ! isset( $attributes['PRENOM'] ) ? 'FIRSTNAME' : 'PRENOM';
		$last       = isset( $attributes['LASTNAME'] ) || ! isset( $attributes['NOM'] ) ? 'LASTNAME' : 'NOM';
		return array( $first, $last );
	}

	/**
	 * Attributes a form field can fill (the name ones are filled automatically).
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		$out = $this->attributes();
		unset( $out['FIRSTNAME'], $out['LASTNAME'], $out['PRENOM'], $out['NOM'], $out['SMS'], $out['OPT_IN'], $out['DOUBLE_OPT-IN'], $out['EXT_ID'], $out['CONTACT_TIMEZONE'] );
		return $out;
	}

	/**
	 * Creates or updates the contact and adds it to the chosen lists.
	 *
	 * Contacts who unsubscribed (blocklisted in Brevo) are not added again.
	 *
	 * @param array $feed    Feed: lists, map.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$basics = $this->basics( $feed, $payload );
		if ( is_wp_error( $basics ) ) {
			return $basics;
		}
		list( $email, $lists ) = $basics;

		$existing = $this->call( 'GET', '/contacts/' . rawurlencode( $email ) . '?identifierType=email_id' );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( 200 === $existing['code'] && ! empty( $existing['body']['emailBlacklisted'] ) ) {
			return self::skip( __( 'This person unsubscribed in Brevo, so they were not added again.', 'aluleth-smtp' ) );
		}
		if ( 200 !== $existing['code'] && 404 !== $existing['code'] ) {
			return $this->http_error( $existing, self::detail( $existing ) );
		}

		list( $first, $last ) = $this->name_attributes();
		$attributes           = array_filter(
			array(
				$first => (string) $payload['first_name'],
				$last  => (string) $payload['last_name'],
			),
			'strlen'
		);
		// Brevo refuses phone numbers that are not in international format, which would cancel the whole contact.
		$phone = preg_replace( '/[\s().-]/', '', (string) $payload['phone'] );
		if ( preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ) {
			$attributes['SMS'] = $phone;
		}
		$attributes = array_merge( $attributes, array_intersect_key( self::mapped( $feed, $payload ), $this->fields() ) );

		$body = array(
			'email'         => $email,
			'listIds'       => array_map( 'intval', $lists ),
			'updateEnabled' => true,
		);
		if ( $attributes ) {
			$body['attributes'] = (object) $attributes;
		}
		$response = $this->call( 'POST', '/contacts', $body );
		if ( ! is_wp_error( $response ) && 400 === $response['code'] && isset( $attributes['SMS'] ) ) {
			// The number may already belong to another contact: keep the subscription without it.
			unset( $attributes['SMS'] );
			$body['attributes'] = (object) $attributes;
			$response           = $this->call( 'POST', '/contacts', $body );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( $response['code'], array( 200, 201, 204 ), true ) ) {
			return $this->http_error( $response, self::detail( $response ) );
		}
		$names = array_intersect_key( $this->lists(), array_flip( $lists ) );
		$text  = $names ? implode( ', ', $names ) : implode( ', ', $lists );
		return 404 === $existing['code']
			/* translators: %s: names of the lists, groups, forms or tags. */
			? sprintf( __( 'Contact created and added to %s.', 'aluleth-smtp' ), $text )
			/* translators: %s: names of the lists, groups, forms or tags. */
			: sprintf( __( 'Contact updated and added to %s.', 'aluleth-smtp' ), $text );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'The phone is sent only in international format (for example +33 6 12 34 56 78). People who unsubscribed in Brevo are not added again.', 'aluleth-smtp' );
	}
}
