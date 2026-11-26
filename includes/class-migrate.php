<?php
/**
 * Import from WP Mail SMTP, FluentSMTP and Post SMTP: their connections become
 * Aluleth connections, keys included (read where those plugins keep them).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Migrate.
 */
final class Migrate {

	/**
	 * Plugins that can be imported.
	 *
	 * @return array id => [label, plugin file].
	 */
	public static function sources() {
		return array(
			'wpmailsmtp' => array( 'WP Mail SMTP', 'wp-mail-smtp/wp_mail_smtp.php' ),
			'fluentsmtp' => array( 'FluentSMTP', 'fluent-smtp/fluent-smtp.php' ),
			'postsmtp'   => array( 'Post SMTP', 'post-smtp/postman-smtp.php' ),
		);
	}

	/**
	 * Settings found on this site, by source.
	 *
	 * @return array id => list of connections to import (see read()).
	 */
	public static function found() {
		$out = array();
		foreach ( array_keys( self::sources() ) as $id ) {
			$list = self::read( $id );
			if ( $list ) {
				$out[ $id ] = $list;
			}
		}
		return $out;
	}

	/**
	 * Connections found in one plugin.
	 *
	 * @param string $source Source id.
	 * @return array List of: provider, label, from_email, from_name, force_from, force_name, f (fields), role, missing (fields that could not be read).
	 */
	public static function read( $source ) {
		switch ( $source ) {
			case 'wpmailsmtp':
				return self::wpmailsmtp();
			case 'fluentsmtp':
				return self::fluentsmtp();
			case 'postsmtp':
				return self::postsmtp();
		}
		return array();
	}

	/**
	 * Imports the connections of a plugin.
	 *
	 * @param string $source Source id.
	 * @return array done (labels), skipped (label => reason).
	 */
	public static function run( $source ) {
		$done    = array();
		$skipped = array();
		$first   = ! Connections::all();
		$labels  = wp_list_pluck( Connections::all(), 'label' );
		foreach ( self::read( $source ) as $item ) {
			if ( in_array( $item['label'], $labels, true ) ) {
				$skipped[ $item['label'] ] = __( 'already imported.', 'aluleth-smtp' );
				continue;
			}
			if ( '' === $item['from_email'] ) {
				$item['from_email'] = (string) get_option( 'admin_email' );
			}
			if ( $item['missing'] ) {
				/* translators: %s: names of settings. */
				$skipped[ $item['label'] ] = sprintf( __( 'could not read: %s (kept in wp-config.php or another place). Add this connection by hand.', 'aluleth-smtp' ), implode( ', ', $item['missing'] ) );
				continue;
			}
			if ( ! $first && 'main' === $item['role'] ) {
				// The site already sends through Aluleth: imported connections wait for routing rules.
				$item['role'] = 'rules';
			}
			$id = Connections::save( $item );
			if ( is_wp_error( $id ) ) {
				$skipped[ $item['label'] ] = $id->get_error_message();
				continue;
			}
			Connections::verify( $id );
			$done[] = $item['label'];
		}
		return array(
			'done'    => $done,
			'skipped' => $skipped,
		);
	}

	/**
	 * A connection to import.
	 *
	 * @param string $provider   Aluleth service id.
	 * @param string $label      Name.
	 * @param array  $fields     Settings.
	 * @param array  $from       email, name, force_from, force_name.
	 * @param string $role       main, fallback or rules.
	 * @return array
	 */
	private static function item( $provider, $label, array $fields, array $from, $role = 'main' ) {
		$p       = Connections::provider( $provider );
		$missing = array();
		foreach ( $p ? $p->fields() : array() as $key => $f ) {
			if ( ! empty( $f['required'] ) && ( ! isset( $fields[ $key ] ) || '' === trim( (string) $fields[ $key ] ) ) ) {
				$missing[] = $f['label'];
			}
		}
		return array(
			'provider'   => $provider,
			'label'      => $label,
			'from_email' => isset( $from['email'] ) ? (string) $from['email'] : '',
			'from_name'  => isset( $from['name'] ) ? (string) $from['name'] : '',
			'force_from' => ! empty( $from['force_from'] ),
			'force_name' => ! empty( $from['force_name'] ),
			'f'          => array_map( 'strval', $fields ),
			'role'       => $role,
			'missing'    => $missing,
		);
	}

	/**
	 * WP Mail SMTP (option "wp_mail_smtp"; only the SMTP password is encrypted).
	 *
	 * @return array
	 */
	private static function wpmailsmtp() {
		$o = get_option( 'wp_mail_smtp' );
		if ( ! is_array( $o ) || empty( $o['mail']['mailer'] ) ) {
			return array();
		}
		$get    = static function ( $group, $key ) use ( $o ) {
			if ( class_exists( '\WPMailSMTP\Options' ) ) {
				$v = \WPMailSMTP\Options::init()->get( $group, $key );
				return is_scalar( $v ) ? (string) $v : '';
			}
			$v = isset( $o[ $group ][ $key ] ) ? $o[ $group ][ $key ] : '';
			if ( 'smtp' === $group && 'pass' === $key && '' !== $v ) {
				$v = self::wpms_decrypt( (string) $v );
			}
			return is_scalar( $v ) ? (string) $v : '';
		};
		$mail   = $o['mail'];
		$from   = array(
			'email'      => isset( $mail['from_email'] ) ? $mail['from_email'] : '',
			'name'       => isset( $mail['from_name'] ) ? $mail['from_name'] : '',
			'force_from' => ! empty( $mail['from_email_force'] ),
			'force_name' => ! empty( $mail['from_name_force'] ),
		);
		$label  = 'WP Mail SMTP';
		$mailer = (string) $mail['mailer'];
		switch ( $mailer ) {
			case 'smtp':
			case 'pepipost':
				$enc = $get( $mailer, 'encryption' );
				return array(
					self::item(
						'smtp',
						$label,
						array(
							'host'       => $get( $mailer, 'host' ),
							'port'       => $get( $mailer, 'port' ),
							'encryption' => in_array( $enc, array( 'ssl', 'tls' ), true ) ? $enc : 'none',
							'username'   => $get( $mailer, 'auth' ) ? $get( $mailer, 'user' ) : '',
							'password'   => $get( $mailer, 'auth' ) ? $get( $mailer, 'pass' ) : '',
						),
						$from
					),
				);
			case 'sendgrid':
				return array( self::item( 'sendgrid', $label, array( 'api_key' => $get( 'sendgrid', 'api_key' ) ), $from ) );
			case 'sendinblue':
				return array( self::item( 'brevo', $label, array( 'api_key' => $get( 'sendinblue', 'api_key' ) ), $from ) );
			case 'mailgun':
				return array(
					self::item(
						'mailgun',
						$label,
						array(
							'api_key' => $get( 'mailgun', 'api_key' ),
							'domain'  => $get( 'mailgun', 'domain' ),
							'region'  => 'EU' === strtoupper( $get( 'mailgun', 'region' ) ) ? 'eu' : 'us',
						),
						$from
					),
				);
			case 'postmark':
				return array(
					self::item(
						'postmark',
						$label,
						array(
							'token'  => $get( 'postmark', 'server_api_token' ),
							'stream' => $get( 'postmark', 'message_stream' ),
						),
						$from
					),
				);
			case 'sparkpost':
				return array(
					self::item(
						'sparkpost',
						$label,
						array(
							'api_key' => $get( 'sparkpost', 'api_key' ),
							'region'  => 'EU' === strtoupper( $get( 'sparkpost', 'region' ) ) ? 'eu' : 'us',
						),
						$from
					),
				);
			case 'amazonses':
				return array(
					self::item(
						'ses',
						$label,
						array(
							'access_key' => $get( 'amazonses', 'client_id' ),
							'secret_key' => $get( 'amazonses', 'client_secret' ),
							'region'     => $get( 'amazonses', 'region' ),
						),
						$from
					),
				);
			case 'mailjet':
				return array(
					self::item(
						'mailjet',
						$label,
						array(
							'api_key'    => $get( 'mailjet', 'api_key' ),
							'secret_key' => $get( 'mailjet', 'secret_key' ),
						),
						$from
					),
				);
			case 'resend':
			case 'mailersend':
			case 'smtp2go':
			case 'elasticemail':
				return array( self::item( $mailer, $label, array( 'api_key' => $get( $mailer, 'api_key' ) ), $from ) );
			case 'gmail':
			case 'outlook':
				// The app's keys come along; the sign-in is made again here.
				return array(
					self::item(
						'gmail' === $mailer ? 'gmail' : 'microsoft',
						$label,
						array(
							'client_id'     => $get( $mailer, 'client_id' ),
							'client_secret' => $get( $mailer, 'client_secret' ),
						),
						$from
					),
				);
		}
		return array();
	}

	/**
	 * Reads a WP Mail SMTP encrypted value (sodium secretbox, key in "wp_mail_smtp_mail_key").
	 *
	 * @param string $value Value.
	 * @return string The plain value ('' when it cannot be read; a value that is not encrypted comes back as is).
	 */
	private static function wpms_decrypt( $value ) {
		$key = base64_decode( (string) get_option( 'wp_mail_smtp_mail_key' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading WP Mail SMTP's key.
		$raw = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted value.
		if ( ! $key || false === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return $value;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		} catch ( \Exception $e ) {
			return '';
		}
		return false === $plain ? $value : $plain;
	}

	/**
	 * FluentSMTP (option "fluentmail-settings"; keys encrypted with the site's LOGGED_IN keys).
	 *
	 * @return array
	 */
	private static function fluentsmtp() {
		if ( function_exists( 'fluentMailGetSettings' ) ) {
			$o = fluentMailGetSettings( array(), false );
		} else {
			$o = get_option( 'fluentmail-settings' );
			if ( is_array( $o ) && ! empty( $o['use_encrypt'] ) && ! empty( $o['connections'] ) ) {
				foreach ( $o['connections'] as $k => $conn ) {
					foreach ( array( 'password', 'secret_key', 'access_key', 'api_key', 'client_secret' ) as $field ) {
						if ( ! empty( $conn['provider_settings'][ $field ] ) ) {
							$o['connections'][ $k ]['provider_settings'][ $field ] = self::fluent_decrypt( (string) $conn['provider_settings'][ $field ] );
						}
					}
				}
			}
		}
		if ( ! is_array( $o ) || empty( $o['connections'] ) || ! is_array( $o['connections'] ) ) {
			return array();
		}
		$default  = isset( $o['misc']['default_connection'] ) ? (string) $o['misc']['default_connection'] : '';
		$fallback = isset( $o['misc']['fallback_connection'] ) ? (string) $o['misc']['fallback_connection'] : '';
		$out      = array();
		foreach ( $o['connections'] as $key => $conn ) {
			$p = isset( $conn['provider_settings'] ) && is_array( $conn['provider_settings'] ) ? $conn['provider_settings'] : array();
			if ( empty( $p['provider'] ) ) {
				continue;
			}
			$in_config = isset( $p['key_store'] ) && 'wp_config' === $p['key_store'];
			$v         = static function ( $k ) use ( $p, $in_config ) {
				if ( $in_config && in_array( $k, array( 'password', 'api_key', 'secret_key', 'access_key', 'client_secret' ), true ) ) {
					return '';
				}
				return isset( $p[ $k ] ) && is_scalar( $p[ $k ] ) ? (string) $p[ $k ] : '';
			};
			$from      = array(
				'email'      => $v( 'sender_email' ),
				'name'       => $v( 'sender_name' ),
				'force_from' => true,
				'force_name' => 'yes' === $v( 'force_from_name' ),
			);
			$role      = (string) $key === $fallback ? 'fallback' : ( (string) $key === $default || ! $default ? 'main' : 'rules' );
			/* translators: %s: email address. */
			$label = sprintf( __( 'FluentSMTP: %s', 'aluleth-smtp' ), '' !== $from['email'] ? $from['email'] : $p['provider'] );
			switch ( $p['provider'] ) {
				case 'smtp':
					$enc   = $v( 'encryption' );
					$auth  = 'yes' === $v( 'auth' );
					$out[] = self::item(
						'smtp',
						$label,
						array(
							'host'       => $v( 'host' ),
							'port'       => $v( 'port' ),
							'encryption' => in_array( $enc, array( 'ssl', 'tls' ), true ) ? $enc : 'none',
							'username'   => $auth ? $v( 'username' ) : '',
							'password'   => $auth ? $v( 'password' ) : '',
						),
						$from,
						$role
					);
					break;
				case 'ses':
					$out[] = self::item(
						'ses',
						$label,
						array(
							'access_key' => $v( 'access_key' ),
							'secret_key' => $v( 'secret_key' ),
							'region'     => $v( 'region' ),
						),
						$from,
						$role
					);
					break;
				case 'mailgun':
					$out[] = self::item(
						'mailgun',
						$label,
						array(
							'api_key' => $v( 'api_key' ),
							'domain'  => $v( 'domain_name' ),
							'region'  => 'eu' === strtolower( $v( 'region' ) ) ? 'eu' : 'us',
						),
						$from,
						$role
					);
					break;
				case 'postmark':
					$out[] = self::item(
						'postmark',
						$label,
						array(
							'token'  => $v( 'api_key' ),
							'stream' => $v( 'message_stream' ),
						),
						$from,
						$role
					);
					break;
				case 'gmail':
				case 'outlook':
					$out[] = self::item(
						'gmail' === $p['provider'] ? 'gmail' : 'microsoft',
						$label,
						array(
							'client_id'     => $v( 'client_id' ),
							'client_secret' => $v( 'client_secret' ),
						),
						$from,
						$role
					);
					break;
				case 'sendgrid':
				case 'sendinblue':
				case 'sparkpost':
				case 'elasticmail':
				case 'smtp2go':
					$map   = array(
						'sendgrid'    => 'sendgrid',
						'sendinblue'  => 'brevo',
						'sparkpost'   => 'sparkpost',
						'elasticmail' => 'elasticemail',
						'smtp2go'     => 'smtp2go',
					);
					$out[] = self::item( $map[ $p['provider'] ], $label, array( 'api_key' => $v( 'api_key' ) ), $from, $role );
					break;
			}
		}
		return $out;
	}

	/**
	 * Reads a FluentSMTP encrypted value (AES-256-CTR with LOGGED_IN_KEY, LOGGED_IN_SALT at the end).
	 *
	 * @param string $value Value.
	 * @return string Plain value (a value that is not encrypted comes back as is).
	 */
	private static function fluent_decrypt( $value ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}
		$salt = defined( 'FLUENTMAIL_ENCRYPT_SALT' ) ? FLUENTMAIL_ENCRYPT_SALT : ( defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ? LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure' );
		$key  = defined( 'FLUENTMAIL_ENCRYPT_KEY' ) ? FLUENTMAIL_ENCRYPT_KEY : ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ? LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure' );
		$raw  = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted value.
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return $value;
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-ctr', $key, 0, substr( $raw, 0, 16 ) );
		if ( ! is_string( $plain ) || substr( $plain, -strlen( $salt ) ) !== $salt ) {
			return $value;
		}
		return substr( $plain, 0, -strlen( $salt ) );
	}

	/**
	 * Post SMTP (option "postman_options"; keys base64 encoded).
	 *
	 * @return array
	 */
	private static function postsmtp() {
		$o = get_option( 'postman_options' );
		if ( ! is_array( $o ) || empty( $o['transport_type'] ) ) {
			return array();
		}
		$b64   = static function ( $k ) use ( $o ) {
			if ( empty( $o[ $k ] ) ) {
				return '';
			}
			$d = base64_decode( (string) $o[ $k ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Post SMTP stores keys base64 encoded.
			return false === $d ? '' : $d;
		};
		$v     = static function ( $k ) use ( $o ) {
			return isset( $o[ $k ] ) && is_scalar( $o[ $k ] ) ? (string) $o[ $k ] : '';
		};
		$from  = array(
			'email'      => $v( 'sender_email' ),
			'name'       => $v( 'sender_name' ),
			'force_from' => ! empty( $o['prevent_sender_email_override'] ),
			'force_name' => ! empty( $o['prevent_sender_name_override'] ),
		);
		$label = 'Post SMTP';
		$out   = array();
		switch ( $o['transport_type'] ) {
			case 'smtp':
				$enc   = $v( 'enc_type' );
				$auth  = ! in_array( $v( 'auth_type' ), array( '', 'none' ), true );
				$out[] = self::item(
					'smtp',
					$label,
					array(
						'host'       => $v( 'hostname' ),
						'port'       => $v( 'port' ),
						'encryption' => in_array( $enc, array( 'ssl', 'tls' ), true ) ? $enc : 'none',
						'username'   => $auth ? $v( 'basic_auth_username' ) : '',
						'password'   => $auth ? ( defined( 'POST_SMTP_AUTH_PASSWORD' ) ? '' : $b64( 'basic_auth_password' ) ) : '',
					),
					$from
				);
				break;
			case 'sendgrid_api':
				$out[] = self::item(
					'sendgrid',
					$label,
					array(
						'api_key' => $b64( 'sendgrid_api_key' ),
						'region'  => 'EU' === strtoupper( $v( 'sendgrid_region' ) ) ? 'eu' : 'global',
					),
					$from
				);
				break;
			case 'mailgun_api':
				$out[] = self::item(
					'mailgun',
					$label,
					array(
						'api_key' => $b64( 'mailgun_api_key' ),
						'domain'  => $v( 'mailgun_domain_name' ),
						'region'  => isset( $o['mailgun_region'] ) && null !== $o['mailgun_region'] && '' !== $o['mailgun_region'] ? 'eu' : 'us',
					),
					$from
				);
				break;
			case 'mailjet_api':
				$out[] = self::item(
					'mailjet',
					$label,
					array(
						'api_key'    => $b64( 'mailjet_api_key' ),
						'secret_key' => $b64( 'mailjet_secret_key' ),
					),
					$from
				);
				break;
			case 'postmark_api':
				$out[] = self::item( 'postmark', $label, array( 'token' => $b64( 'postmark_api_key' ) ), $from );
				break;
			case 'sendinblue_api':
			case 'sparkpost_api':
			case 'elasticemail_api':
			case 'smtp2go_api':
			case 'mailersend_api':
				$map   = array(
					'sendinblue_api'   => array( 'brevo', 'sendinblue_api_key' ),
					'sparkpost_api'    => array( 'sparkpost', 'sparkpost_api_key' ),
					'elasticemail_api' => array( 'elasticemail', 'elasticemail_api_key' ),
					'smtp2go_api'      => array( 'smtp2go', 'smtp2go_api_key' ),
					'mailersend_api'   => array( 'mailersend', 'mailersend_api_key' ),
				);
				$m     = $map[ $o['transport_type'] ];
				$out[] = self::item( $m[0], $label, array( 'api_key' => defined( 'POST_SMTP_API_KEY' ) ? '' : $b64( $m[1] ) ), $from );
				break;
		}
		return $out;
	}
}
