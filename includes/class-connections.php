<?php
/**
 * Connections (one email service account each) and the plugin's settings.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Connections.
 */
final class Connections {

	/**
	 * Option holding the connections.
	 */
	const OPTION = 'aluleth_smtp_connections';

	/**
	 * Option holding the settings.
	 */
	const SETTINGS = 'aluleth_smtp_settings';

	/**
	 * Units of the sending limits, in seconds.
	 */
	const UNITS = array(
		'second' => 1,
		'minute' => 60,
		'hour'   => 3600,
		'day'    => 86400,
	);

	/**
	 * Services, by id.
	 *
	 * @var Provider[]|null
	 */
	private static $providers = null;

	/**
	 * Services.
	 *
	 * @return Provider[]
	 */
	public static function providers() {
		if ( null === self::$providers ) {
			$list = array( new Provider_Sendgrid(), new Provider_Ses(), new Provider_Brevo(), new Provider_Mailgun(), new Provider_Postmark(), new Provider_Resend(), new Provider_Mailersend(), new Provider_Mailjet(), new Provider_Smtp2go(), new Provider_Sparkpost(), new Provider_Elasticemail(), new Provider_Gmail(), new Provider_Microsoft(), new Provider_Smtp() );
			/**
			 * Filters the email services Aluleth SMTP can send with.
			 *
			 * @param Provider[] $list Services.
			 */
			$list            = (array) apply_filters( 'aluleth_smtp/providers', $list );
			self::$providers = array();
			foreach ( $list as $p ) {
				if ( $p instanceof Provider ) {
					self::$providers[ $p->id() ] = $p;
				}
			}
		}
		return self::$providers;
	}

	/**
	 * One service.
	 *
	 * @param string $id Id.
	 * @return Provider|null
	 */
	public static function provider( $id ) {
		$all = self::providers();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Saved connections, secrets still encrypted.
	 *
	 * @return array[] By id.
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $c ) {
			if ( is_array( $c ) && isset( $c['provider'] ) && self::provider( $c['provider'] ) ) {
				$out[ $id ] = self::normalize( $c, $id );
			}
		}
		return $out;
	}

	/**
	 * Whether at least one connection can send.
	 *
	 * @return bool
	 */
	public static function active() {
		return '' !== self::default_id();
	}

	/**
	 * Id of the connection used when no rule applies.
	 *
	 * @return string
	 */
	public static function default_id() {
		$all = self::all();
		$s   = self::settings();
		if ( '' !== $s['default'] && isset( $all[ $s['default'] ] ) ) {
			return $s['default'];
		}
		foreach ( $all as $id => $c ) {
			if ( 'fallback' !== $c['role'] && 'rules' !== $c['role'] ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * A connection with its fields filled in.
	 *
	 * @param array  $c  Stored connection.
	 * @param string $id Id.
	 * @return array
	 */
	private static function normalize( array $c, $id ) {
		return array(
			'id'          => (string) $id,
			'provider'    => (string) $c['provider'],
			'label'       => isset( $c['label'] ) && '' !== $c['label'] ? (string) $c['label'] : self::provider( $c['provider'] )->label(),
			'role'        => isset( $c['role'] ) && in_array( $c['role'], array( 'main', 'fallback', 'rules' ), true ) ? $c['role'] : 'main',
			'from_email'  => isset( $c['from_email'] ) ? (string) $c['from_email'] : '',
			'from_name'   => isset( $c['from_name'] ) ? (string) $c['from_name'] : '',
			'force_from'  => ! empty( $c['force_from'] ),
			'force_name'  => ! empty( $c['force_name'] ),
			'limit_n'     => isset( $c['limit_n'] ) ? max( 0, (int) $c['limit_n'] ) : 0,
			'limit_unit'  => isset( $c['limit_unit'] ) && isset( self::UNITS[ $c['limit_unit'] ] ) ? $c['limit_unit'] : 'minute',
			'settings'    => isset( $c['settings'] ) && is_array( $c['settings'] ) ? $c['settings'] : array(),
			'secrets'     => isset( $c['secrets'] ) && is_array( $c['secrets'] ) ? $c['secrets'] : array(),
			'hints'       => isset( $c['hints'] ) && is_array( $c['hints'] ) ? $c['hints'] : array(),
			'checked'     => isset( $c['checked'] ) ? (int) $c['checked'] : 0,
			'check_note'  => isset( $c['check_note'] ) ? (string) $c['check_note'] : '',
			'check_state' => isset( $c['check_state'] ) ? (string) $c['check_state'] : '',
			'created'     => isset( $c['created'] ) ? (int) $c['created'] : 0,
		);
	}

	/**
	 * One connection.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Settings of a connection for its service, secrets readable.
	 * A constant ALULETH_SMTP_{ID}_{FIELD} in wp-config.php wins over the saved value.
	 *
	 * @param array $c Connection.
	 * @return array
	 */
	public static function credentials( array $c ) {
		$out                = $c['settings'];
		$out['_connection'] = $c['id'];
		foreach ( $c['secrets'] as $key => $stored ) {
			$out[ $key ] = Secrets::unlock( $stored );
		}
		$provider = self::provider( $c['provider'] );
		foreach ( array_keys( $provider ? $provider->fields() : array() ) as $key ) {
			$const = self::constant( $c['id'], $key );
			if ( defined( $const ) ) {
				$out[ $key ] = (string) constant( $const );
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = '';
			}
		}
		return $out;
	}

	/**
	 * Saves one secret of a connection (a token given by the service), encrypted.
	 *
	 * @param string $id    Connection id.
	 * @param string $key   Key.
	 * @param string $value Value ('' removes it).
	 * @return void
	 */
	public static function store_secret( $id, $key, $value ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
			return;
		}
		if ( '' === (string) $value ) {
			unset( $all[ $id ]['secrets'][ $key ] );
		} else {
			$all[ $id ]['secrets'][ $key ] = Secrets::lock( (string) $value );
		}
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Saves some settings of a connection (not shown in its form, like the signed-in account).
	 *
	 * @param string $id      Connection id.
	 * @param array  $changes Settings.
	 * @return void
	 */
	public static function store_settings( $id, array $changes ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ]['settings'] = array_merge( isset( $all[ $id ]['settings'] ) && is_array( $all[ $id ]['settings'] ) ? $all[ $id ]['settings'] : array(), $changes );
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Name of the wp-config.php constant that can hold a setting of a connection.
	 *
	 * @param string $id  Connection id.
	 * @param string $key Field.
	 * @return string
	 */
	public static function constant( $id, $key ) {
		return strtoupper( 'ALULETH_SMTP_' . preg_replace( '/[^a-z0-9]/i', '_', $id . '_' . $key ) );
	}

	/**
	 * Id the next connection of a service will get (sendgrid, then sendgrid-2…).
	 *
	 * @param string $provider Service id.
	 * @return string
	 */
	public static function next_id( $provider ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = sanitize_key( $provider );
		$n   = 2;
		while ( isset( $all[ $id ] ) ) {
			$id = sanitize_key( $provider . '-' . $n );
			++$n;
		}
		return $id;
	}

	/**
	 * Whether a saved secret can still be read (salts unchanged).
	 *
	 * @param array $c Connection.
	 * @return string[] Fields that need to be pasted again.
	 */
	public static function unreadable( array $c ) {
		$bad = array();
		foreach ( $c['secrets'] as $key => $stored ) {
			if ( '' !== $stored && '' === Secrets::unlock( $stored ) ) {
				$bad[] = $key;
			}
		}
		return $bad;
	}

	/**
	 * Saves a connection from a form.
	 *
	 * @param array  $data Raw values (unslashed).
	 * @param string $id   Existing id, or '' for a new one.
	 * @return string|\WP_Error Id.
	 */
	public static function save( array $data, $id = '' ) {
		$all      = get_option( self::OPTION, array() );
		$all      = is_array( $all ) ? $all : array();
		$old      = '' !== $id && isset( $all[ $id ] ) ? self::normalize( $all[ $id ], $id ) : null;
		$provider = self::provider( $old ? $old['provider'] : ( isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : '' ) );
		if ( ! $provider ) {
			return new \WP_Error( 'alsmtp_provider', __( 'Choose an email service.', 'aluleth-smtp' ) );
		}
		$from_email = isset( $data['from_email'] ) ? sanitize_email( (string) $data['from_email'] ) : '';
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return new \WP_Error( 'alsmtp_from', __( 'Enter the From email address, as it is set up at your email service.', 'aluleth-smtp' ) );
		}
		$c = $old ? $old : array(
			'provider' => $provider->id(),
			'settings' => array(),
			'secrets'  => array(),
			'hints'    => array(),
			'created'  => time(),
		);
		if ( '' === $id ) {
			$id = self::next_id( $provider->id() );
		}
		$c['label']      = isset( $data['label'] ) && '' !== trim( (string) $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : $provider->label();
		$c['role']       = isset( $data['role'] ) && in_array( $data['role'], array( 'main', 'fallback', 'rules' ), true ) ? $data['role'] : 'main';
		$c['from_email'] = $from_email;
		$c['from_name']  = isset( $data['from_name'] ) ? Message::clean_name( sanitize_text_field( (string) $data['from_name'] ) ) : '';
		$c['force_from'] = ! empty( $data['force_from'] );
		$c['force_name'] = ! empty( $data['force_name'] );
		$c['limit_n']    = isset( $data['limit_n'] ) ? max( 0, min( 100000, (int) $data['limit_n'] ) ) : 0;
		$c['limit_unit'] = isset( $data['limit_unit'] ) && isset( self::UNITS[ $data['limit_unit'] ] ) ? $data['limit_unit'] : 'minute';
		$missing         = array();
		foreach ( $provider->fields() as $key => $f ) {
			$raw = isset( $data['f'][ $key ] ) ? trim( (string) $data['f'][ $key ] ) : '';
			if ( 'password' === $f['type'] ) {
				if ( '' !== $raw ) {
					$c['secrets'][ $key ] = Secrets::lock( $raw );
					$c['hints'][ $key ]   = Secrets::hint( $raw );
				}
				$has = '' !== $raw || ! empty( $c['secrets'][ $key ] ) || defined( self::constant( $id, $key ) );
			} else {
				if ( 'select' === $f['type'] ) {
					$raw = isset( $f['options'][ $raw ] ) ? $raw : (string) key( $f['options'] );
				} elseif ( 'number' === $f['type'] ) {
					$raw = '' === $raw ? '' : (string) absint( $raw );
				} else {
					$raw = sanitize_text_field( $raw );
				}
				$c['settings'][ $key ] = $raw;
				$has                   = '' !== $raw;
			}
			if ( ! empty( $f['required'] ) && ! $has ) {
				$missing[] = $f['label'];
			}
		}
		if ( $missing ) {
			/* translators: %s: names of the empty fields. */
			return new \WP_Error( 'alsmtp_missing', sprintf( __( 'Fill in: %s.', 'aluleth-smtp' ), implode( ', ', $missing ) ) );
		}
		$all[ $id ] = $c;
		update_option( self::OPTION, $all, true );
		// The secret key of its event address is made with the connection (never on a page view).
		Hooks::token( $id, true );
		// The first connection becomes the default one.
		$s = self::settings();
		if ( '' === $s['default'] && 'main' === $c['role'] ) {
			self::save_settings( array( 'default' => $id ) );
		}
		if ( 'fallback' === $c['role'] && '' === $s['fallback'] ) {
			self::save_settings( array( 'fallback' => $id ) );
		}
		return $id;
	}

	/**
	 * Saves the result of a check.
	 *
	 * @param string $id    Id.
	 * @param string $state ok, warning or error.
	 * @param string $note  Text.
	 * @return void
	 */
	public static function mark( $id, $state, $note ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $id ] ) ) {
			return;
		}
		$all[ $id ]['checked']     = time();
		$all[ $id ]['check_state'] = sanitize_key( $state );
		$all[ $id ]['check_note']  = substr( sanitize_text_field( $note ), 0, 500 );
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Deletes a connection (and its place in the settings and rules).
	 *
	 * @param string $id Id.
	 * @return void
	 */
	public static function delete( $id ) {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, true );
		}
		$s = self::settings();
		$u = array();
		if ( $s['default'] === $id ) {
			$u['default'] = '';
		}
		if ( $s['fallback'] === $id ) {
			$u['fallback'] = '';
		}
		$rules = array();
		foreach ( $s['rules'] as $r ) {
			if ( $r['connection'] !== $id ) {
				if ( $r['fallback'] === $id ) {
					$r['fallback'] = '';
				}
				$rules[] = $r;
			}
		}
		$u['rules'] = $rules;
		self::save_settings( $u );
	}

	/**
	 * Checks a connection with its service.
	 *
	 * @param string $id Id.
	 * @return true|\WP_Error|null
	 */
	public static function verify( $id ) {
		$c = self::get( $id );
		if ( ! $c ) {
			return new \WP_Error( 'alsmtp_none', __( 'This connection does not exist.', 'aluleth-smtp' ) );
		}
		$bad = self::unreadable( $c );
		if ( $bad ) {
			$r = new \WP_Error( 'alsmtp_secret', __( 'The saved key can no longer be read (the site’s secret keys changed). Paste it again.', 'aluleth-smtp' ) );
		} else {
			$r = self::provider( $c['provider'] )->verify( array_merge( self::credentials( $c ), array( '_from' => $c['from_email'] ) ) );
		}
		if ( true === $r ) {
			self::mark( $id, 'ok', __( 'The service accepted the settings.', 'aluleth-smtp' ) );
		} elseif ( is_wp_error( $r ) ) {
			$data = $r->get_error_data();
			self::mark( $id, is_array( $data ) && ! empty( $data['warning'] ) ? 'warning' : 'error', $r->get_error_message() );
		}
		return $r;
	}

	/**
	 * Defaults of the settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default'           => '',
			'fallback'          => '',
			'rules'             => array(),
			'queue_marketing'   => true,
			'paused'            => false,
			'log_transactional' => 'full',
			'log_marketing'     => 'summary',
			'keep_days'         => 30,
			'keep_failed'       => 90,
			'alert_email'       => true,
			'alert_to'          => '',
			'slack'             => '',
			'discord'           => '',
			'telegram_token'    => '',
			'telegram_chat'     => '',
			'hard_policy'       => 'except_transactional',
			'soft_policy'       => '3_15',
			'spam_policy'       => 'marketing',
		);
	}

	/**
	 * Settings (clean).
	 *
	 * @return array
	 */
	public static function settings() {
		$raw                    = get_option( self::SETTINGS, array() );
		$raw                    = is_array( $raw ) ? $raw : array();
		$s                      = array_merge( self::defaults(), $raw );
		$s['rules']             = self::clean_rules( $s['rules'] );
		$s['log_transactional'] = in_array( $s['log_transactional'], array( 'full', 'summary', 'none' ), true ) ? $s['log_transactional'] : 'full';
		$s['log_marketing']     = in_array( $s['log_marketing'], array( 'full', 'summary', 'none' ), true ) ? $s['log_marketing'] : 'summary';
		$s['keep_days']         = max( 1, min( 3650, (int) $s['keep_days'] ) );
		$s['keep_failed']       = max( 1, min( 3650, (int) $s['keep_failed'] ) );
		$s['hard_policy']       = in_array( $s['hard_policy'], array( 'except_transactional', 'all', 'mark' ), true ) ? $s['hard_policy'] : 'except_transactional';
		$s['soft_policy']       = in_array( $s['soft_policy'], array( '3_15', '5_30', 'never' ), true ) ? $s['soft_policy'] : '3_15';
		$s['spam_policy']       = in_array( $s['spam_policy'], array( 'marketing', 'all' ), true ) ? $s['spam_policy'] : 'marketing';
		foreach ( array( 'queue_marketing', 'paused', 'alert_email' ) as $k ) {
			$s[ $k ] = (bool) $s[ $k ];
		}
		return $s;
	}

	/**
	 * Saves some settings.
	 *
	 * @param array $changes Changes.
	 * @return void
	 */
	public static function save_settings( array $changes ) {
		$raw = get_option( self::SETTINGS, array() );
		$raw = is_array( $raw ) ? $raw : array();
		update_option( self::SETTINGS, array_merge( $raw, $changes ), true );
	}

	/**
	 * Cleans routing rules.
	 *
	 * @param mixed $rules Rules.
	 * @return array[] name, source, kind, from, connection, fallback.
	 */
	public static function clean_rules( $rules ) {
		$out = array();
		foreach ( is_array( $rules ) ? $rules : array() as $r ) {
			if ( ! is_array( $r ) || empty( $r['connection'] ) ) {
				continue;
			}
			$out[] = array(
				'name'       => isset( $r['name'] ) ? substr( sanitize_text_field( (string) $r['name'] ), 0, 80 ) : '',
				'source'     => isset( $r['source'] ) ? sanitize_key( (string) $r['source'] ) : '',
				'kind'       => isset( $r['kind'] ) && in_array( $r['kind'], array( 'marketing', 'transactional' ), true ) ? $r['kind'] : '',
				'from'       => isset( $r['from'] ) ? strtolower( substr( sanitize_text_field( (string) $r['from'] ), 0, 120 ) ) : '',
				'connection' => sanitize_key( (string) $r['connection'] ),
				'fallback'   => isset( $r['fallback'] ) ? sanitize_key( (string) $r['fallback'] ) : '',
			);
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}
}
