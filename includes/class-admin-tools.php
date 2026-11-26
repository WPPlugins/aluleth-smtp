<?php
/**
 * Admin actions of bounces, webhooks, deliverability and import.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Admin tools.
 */
final class Admin_Tools {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( array( 'hook_setup', 'hook_renew', 'hook_secret', 'hook_mark', 'allow_address', 'block_address', 'export_blocked', 'save_bounce_rules', 'check_domain', 'send_checks', 'import' ) as $action ) {
			add_action( 'admin_post_alsmtp_' . $action, array( __CLASS__, $action ) );
		}
	}

	/**
	 * Back to the connection.
	 *
	 * @param string $id   Connection id.
	 * @param string $type Notice type.
	 * @param string $text Notice.
	 * @return void
	 */
	private static function to_connection( $id, $type, $text ) {
		Admin::back(
			array(
				'tab'  => 'connections',
				'edit' => $id,
			),
			$type,
			$text
		);
	}

	/**
	 * Adds the webhook at the service.
	 *
	 * @return void
	 */
	public static function hook_setup() {
		Admin::guard( 'hook_setup' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		$r  = Hooks::setup( $id );
		if ( is_wp_error( $r ) ) {
			self::to_connection( $id, 'error', $r->get_error_message() );
		}
		self::to_connection( $id, 'success', __( 'Done: the service will now report delivered emails, bounces and spam reports here.', 'aluleth-smtp' ) );
	}

	/**
	 * New secret address.
	 *
	 * @return void
	 */
	public static function hook_renew() {
		Admin::guard( 'hook_renew' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		if ( ! Connections::get( $id ) ) {
			self::to_connection( '', 'error', __( 'This connection does not exist any more.', 'aluleth-smtp' ) );
		}
		$c = Connections::get( $id );
		$h = Hooks::get( $id );
		if ( '' !== $h['remote'] ) {
			Connections::provider( $c['provider'] )->hook_remove( Connections::credentials( $c ), $h['remote'] );
		}
		Hooks::renew( $id );
		self::to_connection( $id, 'warning', __( 'New address made. The old one no longer works: give the new one to the service.', 'aluleth-smtp' ) );
	}

	/**
	 * Saves or clears the signing key.
	 *
	 * @return void
	 */
	public static function hook_secret() {
		Admin::guard( 'hook_secret' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		if ( ! Connections::get( $id ) ) {
			self::to_connection( '', 'error', __( 'This connection does not exist any more.', 'aluleth-smtp' ) );
		}
		if ( '' !== (string) Admin::post( 'clear' ) ) {
			Hooks::clear_secret( $id );
			self::to_connection( $id, 'success', __( 'Signing key removed: events are accepted with the secret address only.', 'aluleth-smtp' ) );
		}
		Hooks::save_secret( $id, (string) Admin::post( 'secret' ) );
		self::to_connection( $id, 'success', __( 'Signing key saved: events without the service’s signature are now refused.', 'aluleth-smtp' ) );
	}

	/**
	 * The person added the address by hand.
	 *
	 * @return void
	 */
	public static function hook_mark() {
		Admin::guard( 'hook_mark' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		if ( ! Connections::get( $id ) ) {
			self::to_connection( '', 'error', __( 'This connection does not exist any more.', 'aluleth-smtp' ) );
		}
		Hooks::mark_set( $id );
		self::to_connection( $id, 'success', __( 'Noted. The state changes to “Working” when the first event arrives.', 'aluleth-smtp' ) );
	}

	/**
	 * Allow an address again.
	 *
	 * @return void
	 */
	public static function allow_address() {
		Admin::guard( 'allow_address' );
		$email = sanitize_email( (string) Admin::post( 'email' ) );
		$done  = '' !== $email && Blocklist::remove( $email );
		/* translators: %s: email address. */
		Admin::back( array( 'tab' => 'bounces' ), $done ? 'success' : 'error', $done ? sprintf( __( '%s gets emails again.', 'aluleth-smtp' ), $email ) : __( 'This address was not on the list.', 'aluleth-smtp' ) );
	}

	/**
	 * Blocks an address by hand.
	 *
	 * @return void
	 */
	public static function block_address() {
		Admin::guard( 'block_address' );
		$email = sanitize_email( (string) Admin::post( 'email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			Admin::back( array( 'tab' => 'bounces' ), 'error', __( 'Enter a valid email address.', 'aluleth-smtp' ) );
		}
		Blocklist::add( $email, 'manual', 'manual' );
		/* translators: %s: email address. */
		Admin::back( array( 'tab' => 'bounces' ), 'success', sprintf( __( '%s no longer gets any email from this site.', 'aluleth-smtp' ), $email ) );
	}

	/**
	 * CSV of the blocked addresses.
	 *
	 * @return void
	 */
	public static function export_blocked() {
		Admin::guard( 'export_blocked' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blocked-addresses-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'email', 'reason', 'source', 'since_utc', 'detail' ) );
		$page = 1;
		do {
			$res = Blocklist::query(
				array(
					'per_page' => 1000,
					'page'     => $page,
				)
			);
			foreach ( $res['rows'] as $r ) {
				fputcsv( $out, array( Admin::csv_cell( $r->email ), $r->reason, $r->provider, $r->created_at, Admin::csv_cell( $r->detail ) ) );
			}
			++$page;
			$more = 1000 === count( $res['rows'] );
		} while ( $more && $page <= 100 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Saves what happens after a bounce.
	 *
	 * @return void
	 */
	public static function save_bounce_rules() {
		Admin::guard( 'save_bounce_rules' );
		Connections::save_settings(
			array(
				'hard_policy' => sanitize_key( (string) Admin::post( 'hard_policy' ) ),
				'soft_policy' => sanitize_key( (string) Admin::post( 'soft_policy' ) ),
				'spam_policy' => sanitize_key( (string) Admin::post( 'spam_policy' ) ),
			)
		);
		Admin::back( array( 'tab' => 'bounces' ), 'success', __( 'Rules saved.', 'aluleth-smtp' ) );
	}

	/**
	 * Checks a domain again.
	 *
	 * @return void
	 */
	public static function check_domain() {
		Admin::guard( 'check_domain' );
		$domain = strtolower( sanitize_text_field( (string) Admin::post( 'domain' ) ) );
		if ( ! isset( Deliverability::domains()[ $domain ] ) ) {
			Admin::back( array( 'tab' => 'deliverability' ), 'error', __( 'No connection sends from this domain.', 'aluleth-smtp' ) );
		}
		Deliverability::check( $domain );
		Admin::back(
			array(
				'tab'    => 'deliverability',
				'domain' => $domain,
			)
		);
	}

	/**
	 * Sends one check email through each connection.
	 *
	 * @return void
	 */
	public static function send_checks() {
		Admin::guard( 'send_checks' );
		$to    = sanitize_email( (string) Admin::post( 'to' ) );
		$lines = array();
		$bad   = false;
		foreach ( Connections::all() as $id => $c ) {
			$r       = Admin::send_test( $to, $id );
			$lines[] = $r['text'];
			$bad     = $bad || ! $r['ok'];
		}
		if ( ! $lines ) {
			$lines[] = __( 'Add a connection first.', 'aluleth-smtp' );
			$bad     = true;
		}
		Admin::back( array( 'tab' => 'deliverability' ), $bad ? 'warning' : 'success', implode( ' ', $lines ) . ( $bad ? '' : ' ' . __( 'In Gmail, open each one and choose “Show original”: SPF, DKIM and DMARC should say PASS.', 'aluleth-smtp' ) ) );
	}

	/**
	 * Imports another SMTP plugin's connections.
	 *
	 * @return void
	 */
	public static function import() {
		Admin::guard( 'import' );
		$source  = sanitize_key( (string) Admin::post( 'source' ) );
		$sources = Migrate::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			Admin::back( array( 'tab' => 'connections' ), 'error', __( 'Nothing to import.', 'aluleth-smtp' ) );
		}
		$r     = Migrate::run( $source );
		$parts = array();
		if ( $r['done'] ) {
			/* translators: 1: number of connections, 2: plugin name. */
			$parts[] = sprintf( _n( '%1$d connection imported from %2$s and checked.', '%1$d connections imported from %2$s and checked.', count( $r['done'] ), 'aluleth-smtp' ), count( $r['done'] ), $sources[ $source ][0] );
			if ( is_plugin_active( $sources[ $source ][1] ) ) {
				/* translators: %s: plugin name. */
				$parts[] = sprintf( __( 'Now deactivate %s, so that only Aluleth SMTP sends your emails.', 'aluleth-smtp' ), $sources[ $source ][0] );
			}
		}
		foreach ( $r['skipped'] as $label => $why ) {
			$parts[] = $label . ': ' . $why;
		}
		Admin::back( array( 'tab' => 'connections' ), $r['skipped'] ? 'warning' : 'success', implode( ' ', $parts ) );
	}
}
