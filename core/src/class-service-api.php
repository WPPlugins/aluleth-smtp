<?php
/**
 * Base class of the services reached with an API key (Mailchimp, Brevo…).
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Service with an account and an API key.
 */
abstract class Service_Api extends Service {

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'api';
	}

	/**
	 * Usable once an account is connected.
	 *
	 * @return bool
	 */
	public function available() {
		return $this->connected();
	}

	/**
	 * Why it is not available.
	 *
	 * @return string
	 */
	public function missing_text() {
		/* translators: %s: service name, e.g. Mailchimp. */
		return sprintf( __( 'Connect your %s account in Aluleth › Connections first.', 'aluleth-smtp' ), $this->label() );
	}

	/**
	 * One API key field.
	 *
	 * @return array<string, array>
	 */
	public function credential_fields() {
		return array(
			'key' => array(
				'label' => __( 'API key', 'aluleth-smtp' ),
				'help'  => $this->key_help(),
			),
		);
	}

	/**
	 * Saved API key.
	 *
	 * @return string
	 */
	protected function key() {
		$credentials = $this->credentials();
		return isset( $credentials['key'] ) ? (string) $credentials['key'] : '';
	}

	/**
	 * Checks that a payload has an email and the feed has at least one list.
	 *
	 * @param array $feed    Feed.
	 * @param array $payload Payload.
	 * @return array|\WP_Error array( email, list ids ) or an error.
	 */
	protected function basics( array $feed, array $payload ) {
		if ( ! $this->connected() ) {
			return self::fail( $this->missing_text() );
		}
		$email = Util::email( $payload['email'] );
		if ( '' === $email ) {
			return self::skip( __( 'No email address in this entry.', 'aluleth-smtp' ) );
		}
		$lists = array_values( array_filter( array_map( 'strval', (array) $feed['lists'] ) ) );
		if ( ! $lists ) {
			/* translators: %s: service name. */
			return self::fail( sprintf( __( 'No %s list is chosen for this form.', 'aluleth-smtp' ), $this->label() ) );
		}
		return array( $email, $lists );
	}
}
