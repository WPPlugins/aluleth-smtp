<?php
/**
 * WP-CLI commands: wp aluleth-smtp queue, wp aluleth-smtp test, wp aluleth-smtp status.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Command line.
 */
final class Cli {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register() {
		\WP_CLI::add_command( 'aluleth-smtp', __CLASS__ );
	}

	/**
	 * Sends the queued emails that are due (for a server cron every minute).
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Longest run. Default 50.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function queue( $args, $assoc_args ) {
		unset( $args );
		$n = Queue::run( isset( $assoc_args['seconds'] ) ? max( 1, (int) $assoc_args['seconds'] ) : 50 );
		\WP_CLI::success( sprintf( '%d emails handled.', $n ) );
	}

	/**
	 * Sends a test email.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Address.
	 *
	 * [--connection=<id>]
	 * : Connection id (default: the main connection).
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$result = Admin::send_test( (string) $args[0], isset( $assoc_args['connection'] ) ? sanitize_key( $assoc_args['connection'] ) : '' );
		if ( $result['ok'] ) {
			\WP_CLI::success( $result['text'] );
		} else {
			\WP_CLI::error( $result['text'] );
		}
	}

	/**
	 * Shows the connections and the queue.
	 *
	 * @return void
	 */
	public function status() {
		foreach ( Connections::all() as $c ) {
			\WP_CLI::line( sprintf( '%s (%s) · %s · %s', $c['label'], $c['provider'], $c['role'], $c['from_email'] ) );
		}
		$q = Queue::status();
		\WP_CLI::line( sprintf( 'Queue: %d waiting, %d due now.', $q['waiting'], $q['due'] ) );
	}
}
