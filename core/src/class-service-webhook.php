<?php
/**
 * Outgoing webhook: posts the data to an address the site owner chooses
 * (Zapier, Make, n8n, a CRM, their own code).
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook service.
 */
final class Service_Webhook extends Service {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'webhook';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Webhook', 'aluleth-smtp' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send the data to any address: Zapier, Make, n8n, your CRM or your own code.', 'aluleth-smtp' );
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'webhook';
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'consent_choice' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'automation';
	}

	/**
	 * A test can be sent from the builders.
	 *
	 * @return bool
	 */
	public function testable() {
		return true;
	}

	/**
	 * Address field.
	 *
	 * @return array
	 */
	public function url_field() {
		return array(
			'label'       => __( 'Address (URL)', 'aluleth-smtp' ),
			'placeholder' => 'https://hooks.zapier.com/…',
			'help'        => __( 'Each entry is posted to this address. Addresses on a local or private network are refused for safety.', 'aluleth-smtp' ),
			'prefix'      => '',
		);
	}

	/**
	 * Connections screen text.
	 *
	 * @return string
	 */
	public function ready_text() {
		return __( 'Add the address in the form’s Settings › Integrations. Works with Zapier, Make and n8n.', 'aluleth-smtp' );
	}

	/**
	 * Body sent, before encoding.
	 *
	 * @param array $feed    Feed.
	 * @param array $payload Payload.
	 * @return array
	 */
	public static function body( array $feed, array $payload ) {
		$values = array();
		$labels = array();
		foreach ( (array) $payload['fields'] as $key => $field ) {
			$values[ $key ] = (string) $field['value'];
			$labels[ $key ] = (string) $field['label'];
		}
		$contact = array(
			'email'             => (string) $payload['email'],
			'first_name'        => (string) $payload['first_name'],
			'last_name'         => (string) $payload['last_name'],
			'phone'             => (string) $payload['phone'],
			'marketing_consent' => ! empty( $payload['consent'] ),
		);
		$source  = (array) $payload['source'];

		if ( isset( $feed['format'] ) && 'form' === $feed['format'] ) {
			// Flat key => value pairs, the easiest shape for no-code tools.
			$flat                      = $contact;
			$flat['marketing_consent'] = $contact['marketing_consent'] ? 'yes' : 'no';
			foreach ( $values as $key => $value ) {
				if ( ! isset( $flat[ $key ] ) ) {
					$flat[ $key ] = $value;
				}
			}
			$flat['event']     = (string) $payload['event'];
			$flat['source']    = isset( $source['type'] ) ? (string) $source['type'] : '';
			$flat['source_id'] = isset( $source['id'] ) ? (string) $source['id'] : '';
			$flat['title']     = isset( $source['title'] ) ? (string) $source['title'] : '';
			$flat['entry_id']  = isset( $source['entry'] ) ? (string) $source['entry'] : '';
			$flat['page_url']  = isset( $source['url'] ) ? (string) $source['url'] : '';
			$flat['sent_at']   = (string) $payload['time'];
			$flat['site']      = home_url( '/' );
			return $flat;
		}

		return array(
			'event'   => (string) $payload['event'],
			'site'    => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'source'  => $source,
			'contact' => $contact,
			'fields'  => (object) $values,
			'labels'  => (object) $labels,
			'sent_at' => (string) $payload['time'],
		);
	}

	/**
	 * Posts the payload.
	 *
	 * @param array $feed    Feed: url, format (json|form), secret.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$url = isset( $feed['url'] ) ? (string) $feed['url'] : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return self::fail( __( 'The webhook address is missing or not valid.', 'aluleth-smtp' ) );
		}
		$body = self::body( $feed, $payload );
		$form = isset( $feed['format'] ) && 'form' === $feed['format'];
		$raw  = $form ? http_build_query( $body, '', '&' ) : (string) wp_json_encode( $body );
		$time = (string) time();

		$headers = array(
			'Content-Type'        => $form ? 'application/x-www-form-urlencoded; charset=utf-8' : 'application/json; charset=utf-8',
			'User-Agent'          => 'Aluleth/' . aluleth_core()->version() . '; ' . home_url( '/' ),
			'X-Aluleth-Event'     => (string) $payload['event'],
			'X-Aluleth-Timestamp' => $time,
		);
		if ( ! empty( $feed['secret'] ) ) {
			// Lets the receiver check the request comes from this site: HMAC-SHA256 of "timestamp.body".
			$headers['X-Aluleth-Signature'] = 'sha256=' . hash_hmac( 'sha256', $time . '.' . $raw, (string) $feed['secret'] );
		}

		/**
		 * Filters the webhook request arguments.
		 *
		 * @param array $args    Arguments for wp_safe_remote_post().
		 * @param array $feed    Feed.
		 * @param array $payload Payload.
		 */
		$args = (array) apply_filters(
			'aluleth/webhook/request',
			array(
				'body'        => $raw,
				'headers'     => $headers,
				'timeout'     => 15,
				'redirection' => 2,
				'data_format' => 'body',
			),
			$feed,
			$payload
		);

		// wp_safe_remote_post() refuses local and private network addresses.
		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			return self::retry( sprintf( __( 'The address did not answer (%s).', 'aluleth-smtp' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			/* translators: %d: HTTP status code, e.g. 200. */
			return sprintf( __( 'Delivered (HTTP %d).', 'aluleth-smtp' ), $code );
		}
		/* translators: %d: HTTP status code, e.g. 500. */
		$message = sprintf( __( 'The address answered with an error (HTTP %d).', 'aluleth-smtp' ), $code );
		return ( $code >= 500 || 408 === $code || 429 === $code ) ? self::retry( $message ) : self::fail( $message );
	}
}
