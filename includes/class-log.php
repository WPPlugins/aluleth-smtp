<?php
/**
 * The email log (the same table holds the queue).
 *
 * What is kept depends on the settings: the full email (with links that give
 * access hidden), only a summary (to, subject, status) or nothing once sent.
 * Old rows are deleted every day.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

use Aluleth\Core\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Log.
 */
final class Log {

	/**
	 * Statuses.
	 */
	const STATUSES = array( 'queued', 'sending', 'sent', 'failed', 'blocked' );

	/**
	 * Adds a row.
	 *
	 * @param array $data Columns.
	 * @return int Id.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$now = Util::now();
		$row = array_merge(
			array(
				'created_at'      => $now,
				'updated_at'      => $now,
				'run_at'          => $now,
				'status'          => 'sending',
				'priority'        => 5,
				'source'          => '',
				'kind'            => 'transactional',
				'connection'      => '',
				'fallback'        => 0,
				'attempts'        => 0,
				'recipients'      => '',
				'recipient_count' => 0,
				'subject'         => '',
				'message_id'      => '',
				'response'        => '',
				'steps'           => '[]',
				'payload'         => null,
				'keep'            => 'full',
			),
			$data
		);
		$wpdb->insert( Installer::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a row.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = Util::now();
		$wpdb->update( Installer::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Adds a step to a row's story (queued, accepted by…, failed at…).
	 *
	 * @param int    $id     Id.
	 * @param string $what   Short title.
	 * @param string $detail Detail.
	 * @param string $state  ok, error or info.
	 * @param bool   $mask   Whether to hide links and codes in the detail.
	 * @return void
	 */
	public static function step( $id, $what, $detail = '', $state = 'info', $mask = true ) {
		global $wpdb;
		$steps   = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT steps FROM %i WHERE id = %d', Installer::table(), (int) $id ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$steps   = is_array( $steps ) ? $steps : array();
		$steps[] = array(
			't' => time(),
			'w' => substr( (string) $what, 0, 120 ),
			'd' => substr( $mask ? Message::mask( (string) $detail ) : (string) $detail, 0, 400 ),
			's' => $state,
		);
		$steps   = array_slice( $steps, -20 );
		$wpdb->update( Installer::table(), array( 'steps' => wp_json_encode( $steps ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Short list of recipients for the log.
	 *
	 * @param array $msg Message.
	 * @return string
	 */
	public static function recipients_text( array $msg ) {
		$all  = Message::recipients( $msg );
		$text = implode( ', ', array_slice( $all, 0, 5 ) );
		if ( count( $all ) > 5 ) {
			/* translators: %d: number of other recipients. */
			$text .= ' ' . sprintf( _n( 'and %d more', 'and %d more', count( $all ) - 5, 'aluleth-smtp' ), count( $all ) - 5 );
		}
		return substr( $text, 0, 500 );
	}

	/**
	 * Final state of a row: sent or failed. Keeps what the log policy says.
	 *
	 * @param int    $id     Id.
	 * @param string $status sent or failed.
	 * @param array  $data   Columns (message_id, response, connection, fallback).
	 * @param array  $msg    Message (to keep it in the log).
	 * @return void
	 */
	public static function finish( $id, $status, array $data, array $msg ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}
		$data['status'] = $status;
		$keep           = $row->keep;
		if ( 'sent' === $status && 'none' === $keep ) {
			self::delete( $id );
			return;
		}
		if ( 'sent' === $status && 'summary' === $keep ) {
			$data['payload'] = null;
		} else {
			$data['payload'] = wp_json_encode( self::for_log( $msg ) );
		}
		self::update( $id, $data );
	}

	/**
	 * A message as it stays in the log: links that give access hidden, attachments by name only.
	 *
	 * @param array $msg Message.
	 * @return array
	 */
	public static function for_log( array $msg ) {
		$html               = Message::mask( $msg['html'] );
		$text               = Message::mask( $msg['text'] );
		$out                = $msg;
		$out['masked']      = ! empty( $msg['masked'] ) || $html !== $msg['html'] || $text !== $msg['text'];
		$out['html']        = $html;
		$out['text']        = $text;
		$out['attachments'] = array();
		foreach ( $msg['attachments'] as $file ) {
			$out['attachments'][] = array(
				'path' => '',
				'name' => $file['name'],
				'type' => $file['type'],
			);
		}
		unset( $out['route'], $out['meta'] );
		return $out;
	}

	/**
	 * Deletes a row.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes old rows (daily).
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;
		$s     = Connections::settings();
		$table = Installer::table();
		$done  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status IN (%s, %s) AND created_at < %s', $table, 'sent', 'blocked', gmdate( 'Y-m-d H:i:s', time() - $s['keep_days'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$done += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND created_at < %s', $table, 'failed', gmdate( 'Y-m-d H:i:s', time() - $s['keep_failed'] * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// An email stuck in "sending" for an hour (server stopped mid-way) goes back to the queue.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE status = %s AND updated_at < %s', $table, 'queued', 'sending', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Queue::clean_files();
		Tracking::prune( $s['keep_failed'] );
		return $done;
	}

	/**
	 * Rows for the log screen.
	 *
	 * @param array $args status, source, search, page, per_page.
	 * @return array rows, total.
	 */
	public static function query( array $args ) {
		global $wpdb;
		$mode   = '';
		$status = '';
		if ( ! empty( $args['status'] ) ) {
			if ( 'waiting' === $args['status'] || 'bounced' === $args['status'] ) {
				$mode = $args['status'];
			} elseif ( in_array( $args['status'], self::STATUSES, true ) ) {
				$mode   = 'status';
				$status = $args['status'];
			}
		}
		$source = ! empty( $args['source'] ) ? sanitize_key( $args['source'] ) : '';
		$search = ! empty( $args['search'] ) ? (string) $args['search'] : '';
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$per    = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 50;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$table  = Installer::table();
		// Each filter is on only when its first value is not empty: the SQL stays the same.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR ( %s = 'waiting' AND status IN ('queued','sending') ) OR ( %s = 'bounced' AND outcome IN ('bounced','complained','rejected') ) OR ( %s = 'status' AND status = %s ) ) AND ( %s = '' OR source = %s ) AND ( %s = '' OR recipients LIKE %s OR subject LIKE %s )",
				$table,
				$mode,
				$mode,
				$mode,
				$mode,
				$status,
				$source,
				$source,
				$search,
				$like,
				$like
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, updated_at, status, outcome, source, kind, connection, fallback, attempts, recipients, recipient_count, subject, message_id, keep FROM %i WHERE ( %s = '' OR ( %s = 'waiting' AND status IN ('queued','sending') ) OR ( %s = 'bounced' AND outcome IN ('bounced','complained','rejected') ) OR ( %s = 'status' AND status = %s ) ) AND ( %s = '' OR source = %s ) AND ( %s = '' OR recipients LIKE %s OR subject LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$mode,
				$mode,
				$mode,
				$mode,
				$status,
				$source,
				$source,
				$search,
				$like,
				$like,
				$per,
				( $page - 1 ) * $per
			)
		);
		// phpcs:enable
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Numbers per status since a date.
	 *
	 * @param string $since UTC date time.
	 * @return array
	 */
	public static function counts( $since ) {
		global $wpdb;
		$out             = array_fill_keys( self::STATUSES, 0 );
		$rows            = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, SUM(fallback) AS fb FROM %i WHERE created_at >= %s GROUP BY status', Installer::table(), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out['fallback'] = 0;
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
			if ( 'sent' === $r->status ) {
				$out['fallback'] = (int) $r->fb;
			}
		}
		$out['waiting'] = $out['queued'] + $out['sending'];
		foreach ( array( 'delivered', 'bounced', 'complained', 'rejected' ) as $k ) {
			$out[ $k ] = 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT outcome, COUNT(*) AS n FROM %i WHERE created_at >= %s AND outcome <> %s GROUP BY outcome', Installer::table(), $since, '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$out[ $r->outcome ] = (int) $r->n;
		}
		$out['problem'] = $out['bounced'] + $out['complained'] + $out['rejected'];
		return $out;
	}

	/**
	 * Emails per day (sent, failed) since a date, in the site's time zone.
	 *
	 * @param int $days Days.
	 * @return array day => [sent, failed]
	 */
	public static function per_day( $days ) {
		global $wpdb;
		$offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, SUM(status = %s) AS sent, SUM(status = %s) AS failed FROM %i WHERE created_at >= %s GROUP BY d', $offset, 'sent', 'failed', Installer::table(), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out    = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array( 0, 0 );
		}
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r->d ] ) ) {
				$out[ $r->d ] = array( (int) $r->sent, (int) $r->failed );
			}
		}
		return $out;
	}

	/**
	 * Emails per source since a date.
	 *
	 * @param string $since UTC date time.
	 * @return array[] source, kind, sent, failed, connection.
	 */
	public static function per_source( $since ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT source, MAX(kind) AS kind, SUM(status = %s) AS sent, SUM(status = %s) AS failed, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY source ORDER BY total DESC LIMIT 20', 'sent', 'failed', Installer::table(), $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Message kept in a row (null when only a summary was kept).
	 *
	 * @param object $row Row.
	 * @return array|null
	 */
	public static function message( $row ) {
		if ( empty( $row->payload ) ) {
			return null;
		}
		$msg = json_decode( (string) $row->payload, true );
		return is_array( $msg ) ? $msg : null;
	}
}
