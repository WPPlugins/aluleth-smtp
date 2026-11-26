<?php
/**
 * Background jobs shared by the suite (sending to outside tools, later more).
 *
 * A job is a row in `aluleth_queue`. It runs after the visitor already got the
 * response (fastcgi_finish_request when the server has it, otherwise through
 * WP-Cron), is tried again on temporary errors (1 min, 5 min, 30 min, 2 h),
 * and stays visible as a log: done, skipped or failed.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Queue service.
 */
final class Queue {

	/**
	 * WP-Cron hook that runs due jobs.
	 */
	const HOOK = 'aluleth_core_queue';

	/**
	 * Daily housekeeping hook.
	 */
	const DAILY = 'aluleth_core_daily';

	/**
	 * Attempts before a job is marked as failed.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Wait before attempt 2, 3, 4 and 5, in seconds.
	 */
	const BACKOFF = array( 60, 300, 1800, 7200 );

	/**
	 * Job handlers by hook.
	 *
	 * @var array<string, callable>
	 */
	private static $handlers = array();

	/**
	 * Whether a job was added during this request.
	 *
	 * @var bool
	 */
	private static $added = false;

	/**
	 * Hooks the runners.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( self::DAILY, array( __CLASS__, 'prune' ) );
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), 100 );
		add_action( 'admin_post_aluleth_queue_retry', array( __CLASS__, 'retry_action' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily' ) );
	}

	/**
	 * Registers the function that runs one kind of job.
	 *
	 * The callback receives ( array $args, object $row ) and returns a short
	 * result text, or a WP_Error. Error data `array( 'retry' => true )` asks for
	 * another attempt later; `array( 'skip' => true )` marks the job as skipped
	 * (nothing to do, not an error).
	 *
	 * @param string   $hook     Job kind, e.g. 'integration'.
	 * @param callable $callback Callback.
	 * @return void
	 */
	public static function handle( $hook, $callback ) {
		self::$handlers[ (string) $hook ] = $callback;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_queue';
	}

	/**
	 * Adds a job.
	 *
	 * @param string $hook Job kind.
	 * @param array  $args Data for the handler (stored as JSON).
	 * @param array  $meta 'object' => array( type, id ), 'contact' => id, 'label' => text, 'status' => 'pending'|'skipped', 'result' => text.
	 * @return int Job id (0 on failure).
	 */
	public static function add( $hook, array $args, array $meta = array() ) {
		global $wpdb;
		$object = isset( $meta['object'] ) && is_array( $meta['object'] ) ? array_values( $meta['object'] ) : array( '', 0 );
		$status = isset( $meta['status'] ) && 'skipped' === $meta['status'] ? 'skipped' : 'pending';
		$now    = Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'hook'        => substr( sanitize_key( $hook ), 0, 60 ),
				'args'        => wp_json_encode( $args ),
				'object_type' => substr( sanitize_key( isset( $object[0] ) ? $object[0] : '' ), 0, 40 ),
				'object_id'   => isset( $object[1] ) ? (int) $object[1] : 0,
				'contact_id'  => isset( $meta['contact'] ) ? (int) $meta['contact'] : 0,
				'label'       => isset( $meta['label'] ) ? substr( sanitize_text_field( $meta['label'] ), 0, 190 ) : '',
				'status'      => $status,
				'attempts'    => 0,
				'result'      => isset( $meta['result'] ) ? substr( sanitize_text_field( $meta['result'] ), 0, 255 ) : '',
				'last_error'  => '',
				'run_at'      => $now,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id && 'pending' === $status ) {
			self::$added = true;
			self::schedule( time() );
		}
		return $id;
	}

	/**
	 * Makes sure the runner is scheduled no later than the given time.
	 *
	 * @param int $timestamp Unix time.
	 * @return void
	 */
	public static function schedule( $timestamp ) {
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && $next <= $timestamp ) {
			return;
		}
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
		wp_schedule_single_event( (int) $timestamp, self::HOOK );
	}

	/**
	 * Schedules the daily housekeeping once.
	 *
	 * @return void
	 */
	public static function schedule_daily() {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	/**
	 * Runs the jobs added during this request once the response is sent.
	 *
	 * @return void
	 */
	public static function shutdown() {
		if ( ! self::$added ) {
			return;
		}
		self::$added = false;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			// The visitor must not wait: WP-Cron runs the jobs in its own request.
			spawn_cron();
			return;
		}
		self::run( 10, 25 );
	}

	/**
	 * WP-Cron runner.
	 *
	 * @return void
	 */
	public static function cron() {
		self::run( 25, 40 );
	}

	/**
	 * Runs due jobs.
	 *
	 * @param int $limit   Maximum jobs.
	 * @param int $seconds Time budget.
	 * @return int Jobs run.
	 */
	public static function run( $limit = 10, $seconds = 25 ) {
		global $wpdb;
		$table = self::table();
		$start = microtime( true );
		$now   = Util::now();
		$done  = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		// Jobs stuck in "running" (a request that died) go back to the queue.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending' WHERE status = 'running' AND updated_at < %s", $table, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'pending' AND run_at <= %s ORDER BY run_at ASC, id ASC LIMIT %d", $table, $now, (int) $limit ) );
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $start > $seconds ) {
				break;
			}
			if ( self::claim( (int) $id ) ) {
				self::execute( (int) $id );
				++$done;
			}
		}

		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(run_at) FROM %i WHERE status = %s', $table, 'pending' ) );
		// phpcs:enable
		if ( $next ) {
			self::schedule( max( time(), (int) strtotime( $next . ' UTC' ) ) );
		}
		return $done;
	}

	/**
	 * Marks a pending job as running. Only one request can win.
	 *
	 * @param int $id Job id.
	 * @return bool
	 */
	private static function claim( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending'", $table, Util::now(), $id ) );
	}

	/**
	 * Runs one claimed job and saves the outcome.
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	private static function execute( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}
		$args = json_decode( (string) $row->args, true );
		$args = is_array( $args ) ? $args : array();

		/**
		 * Filters the handler of a job kind (for kinds not registered with Queue::handle()).
		 *
		 * @param callable|null $callback Handler.
		 * @param object        $row      Job.
		 */
		$callback = apply_filters( 'aluleth/queue/handler', isset( self::$handlers[ $row->hook ] ) ? self::$handlers[ $row->hook ] : null, $row );
		if ( ! is_callable( $callback ) ) {
			$result = new \WP_Error( 'no_handler', __( 'The plugin that runs this task is not active.', 'aluleth-smtp' ) );
		} else {
			try {
				$result = call_user_func( $callback, $args, $row );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'exception', $e->getMessage() );
			}
		}

		$data = array( 'updated_at' => Util::now() );
		if ( is_wp_error( $result ) ) {
			$error   = $result->get_error_data();
			$retry   = is_array( $error ) && ! empty( $error['retry'] ) && (int) $row->attempts < self::MAX_ATTEMPTS;
			$skipped = is_array( $error ) && ! empty( $error['skip'] );
			$message = substr( wp_strip_all_tags( $result->get_error_message() ), 0, 1000 );
			if ( $skipped ) {
				$data['status']     = 'skipped';
				$data['result']     = substr( $message, 0, 255 );
				$data['last_error'] = '';
			} elseif ( $retry ) {
				$wait               = self::BACKOFF[ min( count( self::BACKOFF ), max( 1, (int) $row->attempts ) ) - 1 ];
				$data['status']     = 'pending';
				$data['run_at']     = gmdate( 'Y-m-d H:i:s', time() + $wait );
				$data['last_error'] = $message;
			} else {
				$data['status']     = 'failed';
				$data['last_error'] = $message;
			}
		} else {
			$data['status']     = 'done';
			$data['result']     = substr( sanitize_text_field( (string) $result ), 0, 255 );
			$data['last_error'] = '';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$row = self::get( $id );
		/**
		 * Fires after a job ran.
		 *
		 * @param object          $row    Job after the update (status: done, skipped, pending = will try again, failed).
		 * @param string|\WP_Error $result Handler result.
		 */
		do_action( 'aluleth/queue/ran', $row, $result );
	}

	/**
	 * One job.
	 *
	 * @param int $id Job id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
	}

	/**
	 * Jobs attached to an object (for example an entry), oldest first.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return object[]
	 */
	public static function for_object( $type, $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, hook, object_type, object_id, contact_id, label, status, attempts, result, last_error, run_at, created_at, updated_at FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id ASC LIMIT 50', $table, sanitize_key( $type ), (int) $id ) );
	}

	/**
	 * Latest jobs, newest first.
	 *
	 * @param int    $limit  Maximum rows.
	 * @param string $status Only this status ('' for all; 'problem' for failed and retrying).
	 * @return object[]
	 */
	public static function recent( $limit = 50, $status = '' ) {
		global $wpdb;
		$table = self::table();
		$mode  = 'problem' === $status || in_array( $status, array( 'done', 'failed', 'skipped', 'pending' ), true ) ? $status : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, hook, object_type, object_id, contact_id, label, status, attempts, result, last_error, run_at, created_at, updated_at FROM %i WHERE %s = '' OR ( %s = 'problem' AND ( status = 'failed' OR ( status = 'pending' AND attempts > 0 ) ) ) OR ( %s NOT IN ( '', 'problem' ) AND status = %s ) ORDER BY id DESC LIMIT %d", $table, $mode, $mode, $mode, $mode, (int) $limit ) );
	}

	/**
	 * Number of failed jobs since a date.
	 *
	 * @param string $since UTC datetime.
	 * @return int
	 */
	public static function failed_count( $since ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed' AND updated_at >= %s", $table, $since ) );
	}

	/**
	 * Puts a failed job back in the queue and runs it now.
	 *
	 * @param int $id Job id.
	 * @return object|null The job after the attempt.
	 */
	public static function retry( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! in_array( $row->status, array( 'failed', 'pending' ), true ) ) {
			return $row;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'     => 'pending',
				'attempts'   => 0,
				'run_at'     => Util::now(),
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		);
		if ( self::claim( (int) $id ) ) {
			self::execute( (int) $id );
		}
		return self::get( $id );
	}

	/**
	 * "Send again" button (admin-post).
	 *
	 * @return void
	 */
	public static function retry_action() {
		check_admin_referer( 'aluleth_queue_retry' );
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-smtp' ) );
		}
		$id   = isset( $_POST['job'] ) ? absint( wp_unslash( $_POST['job'] ) ) : 0;
		$row  = self::retry( $id );
		$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), '' ) : '';
		if ( '' === $back ) {
			$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=aluleth-connections' );
		}
		wp_safe_redirect( add_query_arg( 'aluleth_retried', $row && 'done' === $row->status ? 'ok' : 'error', remove_query_arg( 'aluleth_retried', $back ) ) );
		exit;
	}

	/**
	 * Deletes old log rows: done and skipped after 30 days, failed after 90.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('done','skipped') AND updated_at < %s", $table, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status = 'failed' AND updated_at < %s", $table, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	/**
	 * Deletes the jobs of a contact (privacy erasure or contact deleted).
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $email      Email address, also searched in the job data.
	 * @return int Rows deleted.
	 */
	public static function erase( $contact_id, $email = '' ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$count = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE contact_id = %d AND contact_id > 0', $table, (int) $contact_id ) );
		if ( '' !== $email ) {
			$count += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE args LIKE %s', $table, '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%' ) );
		}
		// phpcs:enable
		return $count;
	}

	/**
	 * Deletes the jobs of an object (for example a deleted entry).
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return int Rows deleted.
	 */
	public static function delete_for_object( $type, $id ) {
		global $wpdb;
		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'object_type' => sanitize_key( $type ),
				'object_id'   => (int) $id,
			)
		);
	}

	/**
	 * Human label of a job status.
	 *
	 * @param object $row Job.
	 * @return string
	 */
	public static function status_label( $row ) {
		switch ( $row->status ) {
			case 'done':
				return __( 'Sent', 'aluleth-smtp' );
			case 'skipped':
				return __( 'Not sent', 'aluleth-smtp' );
			case 'failed':
				return __( 'Failed', 'aluleth-smtp' );
			case 'running':
				return __( 'Sending…', 'aluleth-smtp' );
			default:
				return (int) $row->attempts > 0 ? __( 'Will try again', 'aluleth-smtp' ) : __( 'Waiting', 'aluleth-smtp' );
		}
	}

	/**
	 * Detail line of a job: result, error or next attempt.
	 *
	 * @param object $row Job.
	 * @return string
	 */
	public static function detail( $row ) {
		if ( 'pending' === $row->status && (int) $row->attempts > 0 ) {
			$time = strtotime( $row->run_at . ' UTC' );
			/* translators: 1: error message, 2: time of the next attempt. */
			return sprintf( __( '%1$s Next try at %2$s.', 'aluleth-smtp' ), rtrim( $row->last_error, '. ' ) . '.', $time ? wp_date( get_option( 'time_format' ), $time ) : '' );
		}
		return 'failed' === $row->status ? (string) $row->last_error : (string) $row->result;
	}
}
