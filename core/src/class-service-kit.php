<?php
/**
 * Kit, formerly ConvertKit (API v4): adds subscribers to forms and tags.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Kit service.
 */
final class Service_Kit extends Service_Api {

	/**
	 * API base URL.
	 */
	const BASE = 'https://api.kit.com/v4';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'kit';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Kit';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add subscribers to your Kit (ConvertKit) forms and tags.', 'aluleth-smtp' );
	}

	/**
	 * Where to find the key.
	 *
	 * @return string
	 */
	public function key_help() {
		return __( 'In Kit: Settings › Developer › Add a new key (a v4 API key).', 'aluleth-smtp' );
	}

	/**
	 * Settings offered ("lists" are Kit forms).
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'tags', 'fields', 'double_optin' );
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path after /v4.
	 * @param array|null $body   JSON body.
	 * @param string     $key    Key (default: the saved one).
	 * @return array|\WP_Error
	 */
	private function call( $method, $path, $body = null, $key = '' ) {
		return self::http( $method, self::BASE . $path, array( 'X-Kit-Api-Key' => '' !== $key ? $key : $this->key() ), $body );
	}

	/**
	 * Error text sent by Kit.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function detail( array $response ) {
		return isset( $response['body']['errors'] ) ? implode( ' ', array_map( 'strval', (array) $response['body']['errors'] ) ) : '';
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
		return ! empty( $response['body']['account']['name'] ) ? (string) $response['body']['account']['name'] : 'Kit';
	}

	/**
	 * Reads a list endpoint: id => name.
	 *
	 * @param string $path  Path with query string.
	 * @param string $items Key of the items in the response.
	 * @param string $id    Item key used as id.
	 * @param string $name  Item key used as name.
	 * @return array|\WP_Error
	 */
	private function read( $path, $items, $id = 'id', $name = 'name' ) {
		$response = $this->call( 'GET', $path );
		if ( is_wp_error( $response ) || 200 !== $response['code'] ) {
			return new \WP_Error( 'read' );
		}
		$out = array();
		foreach ( isset( $response['body'][ $items ] ) ? (array) $response['body'][ $items ] : array() as $item ) {
			if ( isset( $item[ $id ] ) ) {
				$out[ (string) $item[ $id ] ] = isset( $item[ $name ] ) ? (string) $item[ $name ] : (string) $item[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Active forms.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				return $this->read( '/forms?status=active&per_page=1000', 'forms' );
			}
		);
	}

	/**
	 * Tags.
	 *
	 * @return array<string, string>
	 */
	public function tags() {
		return $this->cached(
			'tags',
			function () {
				return $this->read( '/tags?per_page=1000', 'tags' );
			}
		);
	}

	/**
	 * Custom fields, keyed by their key.
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		return $this->cached(
			'fields',
			function () {
				return $this->read( '/custom_fields?per_page=1000', 'custom_fields', 'key', 'label' );
			}
		);
	}

	/**
	 * Creates or updates the subscriber, then adds them to the chosen forms and tags.
	 *
	 * With double opt-in, a new subscriber is created as "inactive" and Kit sends
	 * the form's incentive (confirmation) email. People who unsubscribed,
	 * bounced or complained are not added again.
	 *
	 * @param array $feed    Feed: lists (forms), tags (ids), double_optin, map.
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
		$forms = array_values( array_filter( array_map( 'strval', (array) $feed['lists'] ) ) );
		$tags  = array_values( array_filter( array_map( 'strval', (array) $feed['tags'] ) ) );
		if ( ! $forms && ! $tags ) {
			return self::fail( __( 'Choose at least one Kit form or tag for this form.', 'aluleth-smtp' ) );
		}

		$found = $this->call( 'GET', '/subscribers?status=all&email_address=' . rawurlencode( $email ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( 200 !== $found['code'] ) {
			return $this->http_error( $found, self::detail( $found ) );
		}
		$current = isset( $found['body']['subscribers'][0] ) ? (array) $found['body']['subscribers'][0] : array();
		if ( $current && in_array( isset( $current['state'] ) ? $current['state'] : '', array( 'cancelled', 'bounced', 'complained' ), true ) ) {
			return self::skip( __( 'This person unsubscribed or bounced in Kit, so they were not added again.', 'aluleth-smtp' ) );
		}

		$body = array( 'email_address' => $email );
		if ( '' !== (string) $payload['first_name'] ) {
			$body['first_name'] = (string) $payload['first_name'];
		}
		if ( ! $current ) {
			$body['state'] = ! empty( $feed['double_optin'] ) && $forms ? 'inactive' : 'active';
		}
		$fields = array_intersect_key( self::mapped( $feed, $payload ), $this->fields() );
		if ( '' !== (string) $payload['last_name'] && isset( $this->fields()['last_name'] ) && ! isset( $fields['last_name'] ) ) {
			$fields['last_name'] = (string) $payload['last_name'];
		}
		if ( $fields ) {
			$body['fields'] = (object) $fields;
		}
		$response = $this->call( 'POST', '/subscribers', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! in_array( $response['code'], array( 200, 201, 202 ), true ) ) {
			return $this->http_error( $response, self::detail( $response ) );
		}
		$state = isset( $response['body']['subscriber']['state'] ) ? (string) $response['body']['subscriber']['state'] : '';

		$problems = array();
		foreach ( $forms as $form_id ) {
			$r = $this->call( 'POST', '/forms/' . rawurlencode( $form_id ) . '/subscribers', array( 'email_address' => $email ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( ! in_array( $r['code'], array( 200, 201 ), true ) ) {
				$problems[] = 404 === $r['code'] ? __( 'a chosen form no longer exists', 'aluleth-smtp' ) : $this->http_error( $r, self::detail( $r ) )->get_error_message();
			}
		}
		foreach ( $tags as $tag_id ) {
			$r = $this->call( 'POST', '/tags/' . rawurlencode( $tag_id ) . '/subscribers', array( 'email_address' => $email ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( ! in_array( $r['code'], array( 200, 201 ), true ) ) {
				$problems[] = 404 === $r['code'] ? __( 'a chosen tag no longer exists', 'aluleth-smtp' ) : $this->http_error( $r, self::detail( $r ) )->get_error_message();
			}
		}
		if ( $problems ) {
			/* translators: %s: problems. */
			return self::fail( sprintf( __( 'The subscriber is saved in Kit, but: %s.', 'aluleth-smtp' ), implode( '; ', $problems ) ) );
		}

		$names = array_merge( array_values( array_intersect_key( $this->lists(), array_flip( $forms ) ) ), array_values( array_intersect_key( $this->tags(), array_flip( $tags ) ) ) );
		$text  = implode( ', ', $names );
		if ( 'inactive' === $state ) {
			/* translators: %s: names of the lists, groups, forms or tags. */
			return sprintf( __( 'Added to %s, waiting for the person to confirm by email.', 'aluleth-smtp' ), $text );
		}
		/* translators: %s: names of the lists, groups, forms or tags. */
		return sprintf( __( 'Subscribed to %s.', 'aluleth-smtp' ), $text );
	}

	/**
	 * Lists are Kit forms.
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Forms', 'aluleth-smtp' );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'With double opt-in, choose a Kit form that has double opt-in turned on: Kit sends its confirmation email. People who unsubscribed in Kit are not added again.', 'aluleth-smtp' );
	}
}
