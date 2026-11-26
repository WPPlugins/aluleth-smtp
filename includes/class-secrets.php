<?php
/**
 * Keys and passwords of the connections, encrypted in the database.
 *
 * The key comes from the site's own secret salts (wp-config.php). If those
 * salts change, the saved keys can no longer be read and the connections ask
 * for them again. A constant in wp-config.php can also hold a key.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets.
 */
final class Secrets {

	/**
	 * Prefix of encrypted values.
	 */
	const PREFIX = 'alsmtp1:';

	/**
	 * Encryption key.
	 *
	 * @return string 32 bytes.
	 */
	private static function key() {
		$material = ( defined( 'ALULETH_SMTP_SECRET' ) && ALULETH_SMTP_SECRET ) ? (string) ALULETH_SMTP_SECRET : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash( 'sha256', 'aluleth-smtp|' . $material, true );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Value.
	 * @return string
	 */
	public static function lock( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $enc, self::key(), true );
		return 'alsmtp2:' . base64_encode( $iv . $mac . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value ('' when it cannot be read).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function unlock( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, self::PREFIX ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $stored, 'alsmtp2:' ) ) {
			$raw = base64_decode( substr( $stored, 8 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 49 ) {
				return '';
			}
			$iv  = substr( $raw, 0, 16 );
			$mac = substr( $raw, 16, 32 );
			$enc = substr( $raw, 48 );
			if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $enc, self::key(), true ) ) ) {
				return '';
			}
			$plain = openssl_decrypt( $enc, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	/**
	 * Last 4 characters of a secret, to show which key is saved.
	 *
	 * @param string $plain Secret.
	 * @return string
	 */
	public static function hint( $plain ) {
		$plain = (string) $plain;
		return strlen( $plain ) > 8 ? '…' . substr( $plain, -4 ) : '';
	}
}
