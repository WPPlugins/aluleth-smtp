<?php
/**
 * Which plugin sends an email, what kind of email it is, and which
 * connection sends it.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Router.
 */
final class Router {

	/**
	 * Plugins whose emails are marketing emails.
	 */
	const MARKETING = array( 'aluleth-crm', 'fluent-crm', 'mailpoet', 'newsletter', 'mailster', 'sendpress', 'email-subscribers', 'noptin', 'mail-mint' );

	/**
	 * The plugin (folder name), "WordPress", "theme" or "other" that called wp_mail().
	 *
	 * @return string
	 */
	public static function source() {
		$trace   = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu      = wp_normalize_path( WPMU_PLUGIN_DIR ) . '/';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		$own     = wp_normalize_path( ALULETH_SMTP_DIR ) . '/';
		$after   = false;
		$core    = false;
		foreach ( $trace as $frame ) {
			if ( ! $after ) {
				if ( isset( $frame['function'] ) && 'wp_mail' === $frame['function'] && ! isset( $frame['class'] ) ) {
					$after = true;
				} else {
					continue;
				}
			}
			$file = isset( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';
			if ( '' === $file || 0 === strpos( $file, $own ) ) {
				continue;
			}
			// The shared Aluleth core may be loaded from another Aluleth plugin: look further.
			if ( preg_match( '#/aluleth-[a-z-]+/core/#', $file ) ) {
				$core = true;
				continue;
			}
			if ( 0 === strpos( $file, $plugins ) ) {
				$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
				return sanitize_key( (string) $slug );
			}
			if ( 0 === strpos( $file, $mu ) ) {
				return 'mu-plugin';
			}
			if ( 0 === strpos( $file, $themes ) ) {
				return 'theme';
			}
			if ( 0 === strpos( $file, wp_normalize_path( ABSPATH ) ) ) {
				// wp-includes (password reset, new user), wp-admin or wp-login.php: WordPress itself,
				// unless a plugin called it further up (WooCommerce calls wp_new_user_notification…).
				continue;
			}
		}
		unset( $core );
		return 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- an id, not the name.
	}

	/**
	 * Kind of an email.
	 *
	 * @param array  $msg    Message.
	 * @param string $source Source.
	 * @return string marketing or transactional.
	 */
	public static function kind( array $msg, $source ) {
		if ( ! empty( $msg['meta']['kind'] ) ) {
			return 'marketing' === $msg['meta']['kind'] ? 'marketing' : 'transactional';
		}
		$kind = in_array( $source, self::MARKETING, true ) ? 'marketing' : 'transactional';
		/**
		 * Filters the kind of an email (marketing emails can wait in the queue and use their own connection).
		 *
		 * @param string $kind   marketing or transactional.
		 * @param array  $msg    Message.
		 * @param string $source Plugin folder name, "WordPress" or "theme".
		 */
		return 'marketing' === apply_filters( 'aluleth_smtp/kind', $kind, $msg, $source ) ? 'marketing' : 'transactional';
	}

	/**
	 * Connection and fallback for an email.
	 *
	 * @param array  $msg    Message.
	 * @param string $source Source.
	 * @param string $kind   Kind.
	 * @return array connection, fallback, rule (index or -1).
	 */
	public static function route( array $msg, $source, $kind ) {
		$s    = Connections::settings();
		$all  = Connections::all();
		$from = strtolower( isset( $msg['from']['email'] ) ? $msg['from']['email'] : '' );
		$pick = array(
			'connection' => Connections::default_id(),
			'fallback'   => $s['fallback'],
			'rule'       => -1,
		);
		if ( ! empty( $msg['meta']['connection'] ) && isset( $all[ $msg['meta']['connection'] ] ) ) {
			$pick['connection'] = $msg['meta']['connection'];
		} else {
			foreach ( $s['rules'] as $i => $r ) {
				if ( '' !== $r['source'] && $r['source'] !== $source ) {
					continue;
				}
				if ( '' !== $r['kind'] && $r['kind'] !== $kind ) {
					continue;
				}
				if ( '' !== $r['from'] && false === strpos( $from, $r['from'] ) ) {
					continue;
				}
				if ( ! isset( $all[ $r['connection'] ] ) ) {
					continue;
				}
				$pick = array(
					'connection' => $r['connection'],
					'fallback'   => '' !== $r['fallback'] ? $r['fallback'] : $s['fallback'],
					'rule'       => $i,
				);
				break;
			}
		}
		if ( ! isset( $all[ $pick['fallback'] ] ) || $pick['fallback'] === $pick['connection'] ) {
			$pick['fallback'] = '';
		}
		/**
		 * Filters the connection (and fallback) of an email.
		 *
		 * @param array  $pick   connection, fallback, rule.
		 * @param array  $msg    Message.
		 * @param string $source Source.
		 * @param string $kind   Kind.
		 */
		return (array) apply_filters( 'aluleth_smtp/route', $pick, $msg, $source, $kind );
	}

	/**
	 * Name of a source for people.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source_label( $source ) {
		static $names = null;
		$fixed        = array(
			'wordpress' => 'WordPress',
			'theme'     => __( 'Theme', 'aluleth-smtp' ),
			'mu-plugin' => __( 'Must-use plugin', 'aluleth-smtp' ),
			'test'      => __( 'Test email', 'aluleth-smtp' ),
		);
		if ( isset( $fixed[ $source ] ) ) {
			return $fixed[ $source ];
		}
		if ( null === $names ) {
			$names = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$slug = sanitize_key( strtok( $file, '/' ) );
				if ( ! isset( $names[ $slug ] ) ) {
					$names[ $slug ] = $data['Name'];
				}
			}
		}
		return isset( $names[ $source ] ) ? $names[ $source ] : ( '' !== $source ? $source : __( 'Unknown', 'aluleth-smtp' ) );
	}
}
