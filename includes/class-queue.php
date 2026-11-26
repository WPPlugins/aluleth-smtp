<?php
/**
 * The sending queue: marketing emails, emails waiting for a sending limit and
 * emails that will be tried again. It runs right after a page has been sent
 * to the visitor, and every minute with WP-Cron (or a real server cron).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Queue.
 */
final class Queue {

	/**
	 * Cron hook (every minute).
	 */
	const HOOK = 'aluleth_smtp_queue';

	/**
	 * Cron hook (once, as soon as possible).
	 */
	const NOW = 'aluleth_smtp_queue_now';

	/**
	 * Daily clean-up hook.
	 */
	const DAILY = 'aluleth_smtp_daily';

	/**
	 * Lock option.
	 */
	const LOCK = 'aluleth_smtp_queue_lock';

	/**
	 * Most attempts for one email.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Waits between attempts, in seconds.
	 */
	const BACKOFF = array( 60, 300, 1800, 7200, 21600 );

	/**
	 * Whether the queue runs at the end of this request.
	 *
	 * @var bool
	 */
	private static $kicked = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one minute is what a sending queue needs.
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( self::NOW, array( __CLASS__, 'cron' ) );
		add_action( self::DAILY, array( Log::class, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Adds the "every minute" schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['aluleth_smtp_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Aluleth SMTP queue)', 'aluleth-smtp' ),
		);
		return $schedules;
	}

	/**
	 * Schedules the queue and the daily clean-up.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'aluleth_smtp_minute', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::DAILY );
		}
	}

	/**
	 * Wait before attempt number $attempt + 1.
	 *
	 * @param int $attempt Attempts done.
	 * @return int Seconds.
	 */
	public static function backoff( $attempt ) {
		return self::BACKOFF[ max( 0, min( count( self::BACKOFF ), (int) $attempt ) - 1 ) ];
	}

	/**
	 * Runs the queue at the end of this request.
	 *
	 * @return void
	 */
	public static function kick() {
		if ( self::$kicked ) {
			return;
		}
		self::$kicked = true;
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), 50 );
	}

	/**
	 * End of the request: the visitor already has the page, the queue runs.
	 *
	 * @return void
	 */
	public static function shutdown() {
		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
			self::run( 20 );
			return;
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
			self::run( 20 );
			return;
		}
		if ( ! wp_next_scheduled( self::NOW ) ) {
			wp_schedule_single_event( time(), self::NOW );
		}
		spawn_cron();
	}

	/**
	 * WP-Cron run.
	 *
	 * @return void
	 */
	public static function cron() {
		self::run( 45 );
	}

	/**
	 * Sends what is due, for at most $seconds seconds.
	 *
	 * @param int $seconds Time budget.
	 * @return int Emails handled.
	 */
	public static function run( $seconds = 20 ) {
		global $wpdb;
		if ( Connections::settings()['paused'] || ! self::lock( $seconds ) ) {
			return 0;
		}
		$end  = microtime( true ) + $seconds;
		$done = 0;
		$skip = array();
		while ( microtime( true ) < $end ) {
			// Emails skipped in this run are left out here rather than in the SQL.
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND run_at <= %s ORDER BY priority ASC, run_at ASC, id ASC LIMIT %d', Installer::table(), 'queued', Util::now(), 50 + count( $skip ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = array_slice( array_values( array_diff( array_map( 'intval', (array) $ids ), array_map( 'intval', $skip ) ) ), 0, 50 );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				if ( microtime( true ) >= $end ) {
					break 2;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- claims one queued email.
				$claimed = $wpdb->update(
					Installer::table(),
					array(
						'status'     => 'sending',
						'updated_at' => Util::now(),
					),
					array(
						'id'     => (int) $id,
						'status' => 'queued',
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! $claimed ) {
					continue;
				}
				$state = self::process( (int) $id );
				++$done;
				if ( 'wait' === $state ) {
					$skip[] = (int) $id;
				}
				self::refresh_lock( $end );
			}
		}
		self::unlock();
		Alerts::flush();
		return $done;
	}

	/**
	 * Sends one queued email.
	 *
	 * @param int $id Log id.
	 * @return string sent, wait, retry or failed.
	 */
	public static function process( $id ) {
		$row = Log::get( $id );
		$msg = $row ? Log::message( $row ) : null;
		if ( ! $msg ) {
			if ( $row ) {
				Log::update(
					$id,
					array(
						'status'   => 'failed',
						'response' => __( 'The email was not kept, it cannot be sent.', 'aluleth-smtp' ),
					)
				);
			}
			return 'failed';
		}
		$route = isset( $msg['route'] ) ? (array) $msg['route'] : array();
		$route = array(
			'connection' => isset( $route['connection'] ) && Connections::get( $route['connection'] ) ? $route['connection'] : Connections::default_id(),
			'fallback'   => isset( $route['fallback'] ) ? (string) $route['fallback'] : '',
		);
		unset( $msg['route'] );
		$attempt = (int) $row->attempts + 1;
		$out     = Sender::deliver( $id, $msg, $route, $row->kind, $attempt, true );
		Sender::settle( $id, $msg, $route, $row->kind, $attempt, $out );
		return $out['status'];
	}

	/**
	 * Takes the queue lock (one runner at a time, so limits hold).
	 *
	 * @param int $seconds Budget.
	 * @return bool
	 */
	private static function lock( $seconds ) {
		global $wpdb;
		$until = time() + (int) $seconds + 30;
		if ( add_option( self::LOCK, $until, '', false ) ) {
			return true;
		}
		// An expired lock (a run that died) is taken over.
		$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value < %d', $wpdb->options, (string) $until, self::LOCK, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK, 'options' );
		return (bool) $taken;
	}

	/**
	 * Keeps the lock while a run lasts.
	 *
	 * @param float $end End of the run.
	 * @return void
	 */
	private static function refresh_lock( $end ) {
		update_option( self::LOCK, (int) ceil( $end ) + 30, false );
	}

	/**
	 * Frees the lock.
	 *
	 * @return void
	 */
	private static function unlock() {
		delete_option( self::LOCK );
	}

	/**
	 * Private folder for attachments of queued emails.
	 *
	 * @return string
	 */
	public static function folder() {
		$up = wp_upload_dir( null, false );
		return wp_normalize_path( trailingslashit( $up['basedir'] ) . 'aluleth-smtp/queue' );
	}

	/**
	 * Copies the attachments of a queued email (the sender may delete its files right after wp_mail()).
	 *
	 * @param array $msg Message.
	 * @param int   $id  Log id.
	 * @return array Message with the copies.
	 */
	public static function keep_attachments( array $msg, $id ) {
		if ( empty( $msg['attachments'] ) ) {
			return $msg;
		}
		$base = self::folder();
		if ( ! wp_mkdir_p( $base ) ) {
			return $msg;
		}
		$parent = dirname( $base );
		foreach ( array( $parent, $base ) as $dir ) {
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		$dir = $base . '/' . (int) $id . '-' . wp_generate_password( 20, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $msg;
		}
		foreach ( $msg['attachments'] as $i => $file ) {
			$source = (string) $file['path'];
			if ( ! is_file( $source ) || ! is_readable( $source ) ) {
				continue;
			}
			// A neutral name that can never run on the server: the email keeps the file's own name ($file['name']) for the recipient.
			$target = $dir . '/' . (int) $i . '.bin';
			if ( copy( $source, $target ) ) {
				$msg['attachments'][ $i ]['path'] = $target;
			}
		}
		return $msg;
	}

	/**
	 * Deletes the copies of a sent or failed email's attachments.
	 *
	 * @param array $msg Message.
	 * @return void
	 */
	public static function drop_attachments( array $msg ) {
		$base = self::folder();
		$dirs = array();
		foreach ( isset( $msg['attachments'] ) ? $msg['attachments'] : array() as $file ) {
			$path = wp_normalize_path( (string) $file['path'] );
			if ( '' !== $path && 0 === strpos( $path, $base . '/' ) && is_file( $path ) ) {
				wp_delete_file( $path );
				$dirs[ dirname( $path ) ] = true;
			}
		}
		foreach ( array_keys( $dirs ) as $dir ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	/**
	 * Deletes attachment copies left behind for more than two days.
	 *
	 * @return void
	 */
	public static function clean_files() {
		$base = self::folder();
		if ( ! is_dir( $base ) ) {
			return;
		}
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( filemtime( $dir ) < time() - 2 * DAY_IN_SECONDS ) {
				foreach ( (array) glob( $dir . '/*' ) as $file ) {
					wp_delete_file( $file );
				}
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}

	/**
	 * Emails waiting, and when the next one is due.
	 *
	 * @return array waiting, due, next (UTC), retrying.
	 */
	public static function status() {
		global $wpdb;
		$t   = Installer::table();
		$now = Util::now();
		return array(
			'waiting'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','sending')", $t ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'due'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND run_at <= %s', $t, 'queued', $now ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'retrying' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND attempts > 0', $t, 'queued' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'next'     => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(run_at) FROM %i WHERE status = %s', $t, 'queued' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}
}
