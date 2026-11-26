<?php
/**
 * MailerLite (connect.mailerlite.com API): adds or updates subscribers in groups.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * MailerLite service.
 */
final class Service_Mailerlite extends Service_Api {

	/**
	 * API base URL.
	 */
	const BASE = 'https://connect.mailerlite.com/api';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailerlite';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'MailerLite';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add subscribers to your MailerLite groups.', 'aluleth-smtp' );
	}

	/**
	 * Where to find the key.
	 *
	 * @return string
	 */
	public function key_help() {
		return __( 'In MailerLite: Integrations › API › Generate new token.', 'aluleth-smtp' );
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
	 * @param string     $path   Path after /api.
	 * @param array|null $body   JSON body.
	 * @param string     $key    Token (default: the saved one).
	 * @return array|\WP_Error
	 */
	private function call( $method, $path, $body = null, $key = '' ) {
		return self::http( $method, self::BASE . $path, array( 'Authorization' => 'Bearer ' . ( '' !== $key ? $key : $this->key() ) ), $body );
	}

	/**
	 * Error text sent by MailerLite.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function detail( array $response ) {
		return isset( $response['body']['message'] ) ? (string) $response['body']['message'] : '';
	}

	/**
	 * Checks the token.
	 *
	 * @param array $credentials Credentials: key.
	 * @return string|\WP_Error
	 */
	public function verify( array $credentials ) {
		$response = $this->call( 'GET', '/groups?limit=1', null, isset( $credentials['key'] ) ? trim( (string) $credentials['key'] ) : '' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			return $this->http_error( $response, self::detail( $response ) );
		}
		return 'MailerLite';
	}

	/**
	 * Groups.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				$out = array();
				for ( $page = 1; $page <= 5; $page++ ) {
					$response = $this->call( 'GET', '/groups?limit=100&page=' . $page );
					if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
						return $out ? $out : new \WP_Error( 'lists' );
					}
					$items = isset( $response['body']['data'] ) ? (array) $response['body']['data'] : array();
					foreach ( $items as $group ) {
						$out[ (string) $group['id'] ] = (string) $group['name'];
					}
					if ( count( $items ) < 100 ) {
						break;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Fields a form field can fill (name, last name and phone are filled automatically).
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		return $this->cached(
			'fields',
			function () {
				$response = $this->call( 'GET', '/fields?limit=100' );
				if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
					return new \WP_Error( 'fields' );
				}
				$out = array();
				foreach ( isset( $response['body']['data'] ) ? (array) $response['body']['data'] : array() as $field ) {
					if ( ! empty( $field['key'] ) && ! in_array( $field['key'], array( 'email', 'name', 'last_name', 'phone' ), true ) ) {
						$out[ (string) $field['key'] ] = isset( $field['name'] ) ? (string) $field['name'] : (string) $field['key'];
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Adds or updates the subscriber in the chosen groups.
	 *
	 * The status is never sent: MailerLite's own double opt-in setting applies
	 * to new subscribers, and unsubscribed or bounced people are not added again.
	 *
	 * @param array $feed    Feed: lists (groups), map.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$basics = $this->basics( $feed, $payload );
		if ( is_wp_error( $basics ) ) {
			return $basics;
		}
		list( $email, $groups ) = $basics;

		$existing = $this->call( 'GET', '/subscribers/' . rawurlencode( $email ) );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( 200 === $existing['code'] ) {
			$status = isset( $existing['body']['data']['status'] ) ? (string) $existing['body']['data']['status'] : '';
			if ( in_array( $status, array( 'unsubscribed', 'bounced', 'junk' ), true ) ) {
				return self::skip( __( 'This person unsubscribed or bounced in MailerLite, so they were not added again.', 'aluleth-smtp' ) );
			}
		} elseif ( 404 !== $existing['code'] ) {
			return $this->http_error( $existing, self::detail( $existing ) );
		}

		$fields = array_filter(
			array(
				'name'      => (string) $payload['first_name'],
				'last_name' => (string) $payload['last_name'],
				'phone'     => (string) $payload['phone'],
			),
			'strlen'
		);
		$fields = array_merge( $fields, array_intersect_key( self::mapped( $feed, $payload ), $this->fields() ) );
		$body   = array(
			'email'  => $email,
			'groups' => $groups,
		);
		if ( $fields ) {
			$body['fields'] = (object) $fields;
		}
		$response = $this->call( 'POST', '/subscribers', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( $response['code'], array( 200, 201 ), true ) ) {
			if ( 404 === $response['code'] || 422 === $response['code'] ) {
				return self::fail( trim( __( 'MailerLite refused the subscriber.', 'aluleth-smtp' ) . ' ' . self::detail( $response ) ) );
			}
			return $this->http_error( $response, self::detail( $response ) );
		}
		$names  = array_intersect_key( $this->lists(), array_flip( $groups ) );
		$text   = $names ? implode( ', ', $names ) : implode( ', ', $groups );
		$status = isset( $response['body']['data']['status'] ) ? (string) $response['body']['data']['status'] : '';
		if ( 'unconfirmed' === $status ) {
			/* translators: %s: names of the lists, groups, forms or tags. */
			return sprintf( __( 'Added to %s, waiting for the person to confirm by email.', 'aluleth-smtp' ), $text );
		}
		/* translators: %s: names of the lists, groups, forms or tags. */
		return sprintf( __( 'Subscribed to %s.', 'aluleth-smtp' ), $text );
	}

	/**
	 * Lists are called groups.
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Groups', 'aluleth-smtp' );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'MailerLite’s own double opt-in setting for API subscribers decides whether they confirm by email. People who unsubscribed are not added again.', 'aluleth-smtp' );
	}
}
