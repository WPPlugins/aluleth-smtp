<?php
/**
 * Small helpers shared by the core services.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers.
 */
final class Util {

	/**
	 * Current time in UTC, MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * One-way hash of a value with the site's salt (used for IP addresses and
	 * consent texts, so no raw IP address is stored).
	 *
	 * @param string $value Value to hash.
	 * @return string 64 hex characters, or '' for an empty value.
	 */
	public static function hash( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Hash of the visitor's IP address.
	 *
	 * @return string
	 */
	public static function ip_hash() {
		return self::hash( self::client_ip() );
	}

	/**
	 * Visitor's IP address (never stored: only its hash is, and anti-spam
	 * services the site owner turned on receive it).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limits (only its hash is stored).
		 * Behind a trusted proxy or CDN, return the real visitor IP from its header.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'aluleth/client_ip', $ip );
	}

	/**
	 * Normalises an email address, or returns '' when it is not valid.
	 *
	 * @param mixed $email Raw email.
	 * @return string
	 */
	public static function email( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Turns a label into a slug usable as a tag or list key.
	 *
	 * @param string $text Label.
	 * @return string
	 */
	public static function slug( $text ) {
		return substr( sanitize_title( (string) $text ), 0, 100 );
	}
}
