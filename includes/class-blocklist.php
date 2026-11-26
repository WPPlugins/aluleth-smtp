<?php
/**
 * Blocked addresses: the shared Aluleth suppression list, with the rules of Aluleth SMTP.
 *
 * One list for the whole site (core table aluleth_suppression): Aluleth Forms, Popups
 * and the CRM skip these addresses too.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Blocklist.
 */
final class Blocklist {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'aluleth/mail/suppressed', array( __CLASS__, 'core_filter' ), 10, 4 );
	}

	/**
	 * Name of the core table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_suppression';
	}

	/**
	 * Whether an address with this reason gets this kind of email, by the rules set in Bounces.
	 *
	 * @param string $reason unsubscribed, bounced, soft, complained or manual.
	 * @param string $kind   transactional or marketing.
	 * @return bool True when the email must not be sent.
	 */
	public static function blocks( $reason, $kind ) {
		$s         = Connections::settings();
		$marketing = 'marketing' === $kind;
		switch ( $reason ) {
			case 'bounced':
				if ( 'mark' === $s['hard_policy'] ) {
					return false;
				}
				return 'all' === $s['hard_policy'] || $marketing;
			case 'complained':
				return 'all' === $s['spam_policy'] || $marketing;
			case 'manual':
				return true;
			case 'soft':
			case 'unsubscribed':
				return $marketing;
		}
		return false;
	}

	/**
	 * Applies these rules to emails the Aluleth plugins send.
	 *
	 * @param bool   $blocked Core answer.
	 * @param string $reason  Reason.
	 * @param string $email   Address.
	 * @param string $kind    Kind.
	 * @return bool
	 */
	public static function core_filter( $blocked, $reason, $email, $kind ) {
		unset( $blocked, $email );
		return self::blocks( (string) $reason, (string) $kind );
	}

	/**
	 * Entries for some addresses.
	 *
	 * @param string[] $emails Addresses.
	 * @return array email => row (reason, provider, created_at).
	 */
	public static function find( array $emails ) {
		global $wpdb;
		$hashes = array();
		foreach ( $emails as $e ) {
			$e = strtolower( trim( (string) $e ) );
			if ( '' !== $e ) {
				$hashes[ hash( 'sha256', $e ) ] = $e;
			}
		}
		if ( ! $hashes || ! self::exists() ) {
			return array();
		}
		// One short query per recipient (an email has few): no SQL built on the fly.
		$out = array();
		foreach ( $hashes as $hash => $email ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT email_hash, email, reason, provider, created_at FROM %i WHERE email_hash = %s', self::table(), $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $r ) {
				$out[ $email ] = $r;
			}
		}
		return $out;
	}

	/**
	 * Takes blocked addresses out of a message.
	 *
	 * @param array  $msg  Message.
	 * @param string $kind Kind.
	 * @return array msg (without them), removed (email => reason).
	 */
	public static function filter( array $msg, $kind ) {
		$all     = Message::recipients( $msg );
		$entries = self::find( $all );
		$removed = array();
		if ( ! $entries ) {
			return array(
				'msg'     => $msg,
				'removed' => array(),
			);
		}
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			$keep = array();
			foreach ( $msg[ $field ] as $a ) {
				$e = strtolower( $a['email'] );
				if ( isset( $entries[ $e ] ) && self::blocks( $entries[ $e ]->reason, $kind ) ) {
					$removed[ $e ] = $entries[ $e ]->reason;
					continue;
				}
				$keep[] = $a;
			}
			$msg[ $field ] = $keep;
		}
		return array(
			'msg'     => $msg,
			'removed' => $removed,
		);
	}

	/**
	 * Adds an address.
	 *
	 * @param string $email  Address.
	 * @param string $reason bounced, soft, complained, unsubscribed or manual.
	 * @param string $source Connection id or "manual".
	 * @return void
	 */
	public static function add( $email, $reason, $source = '' ) {
		if ( function_exists( 'aluleth_core' ) ) {
			aluleth_core()->mail()->suppress( $email, $reason, $source );
		}
	}

	/**
	 * Removes an address (Allow again).
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function remove( $email ) {
		if ( ! function_exists( 'aluleth_core' ) ) {
			return false;
		}
		$done = aluleth_core()->mail()->unsuppress( $email );
		if ( $done ) {
			global $wpdb;
			// Soft bounces before this date no longer count.
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Installer::events_table(),
				array(
					'email' => strtolower( trim( (string) $email ) ),
					'type'  => 'soft_bounce',
				)
			);
		}
		return $done;
	}

	/**
	 * Whether the core table is there.
	 *
	 * @return bool
	 */
	private static function exists() {
		static $exists = null;
		if ( null === $exists ) {
			global $wpdb;
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $exists;
	}

	/**
	 * Page of blocked addresses with their last event.
	 *
	 * @param array $args reason, search, page, per_page.
	 * @return array rows, total.
	 */
	public static function query( array $args ) {
		global $wpdb;
		if ( ! self::exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$reason = ! empty( $args['reason'] ) ? sanitize_key( $args['reason'] ) : '';
		$search = ! empty( $args['search'] ) ? (string) $args['search'] : '';
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$per    = isset( $args['per_page'] ) ? max( 1, min( 5000, (int) $args['per_page'] ) ) : 50;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		// Each filter is on only when its first value is not empty: the SQL stays the same.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR reason = %s ) AND ( %s = '' OR email LIKE %s )", self::table(), $reason, $reason, $search, $like ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT email, reason, provider, created_at FROM %i WHERE ( %s = '' OR reason = %s ) AND ( %s = '' OR email LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d", self::table(), $reason, $reason, $search, $like, $per, ( $page - 1 ) * $per ) );
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$r->detail = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM %i WHERE email = %s AND type IN (%s, %s, %s) ORDER BY id DESC LIMIT 1', Installer::events_table(), $r->email, 'hard_bounce', 'soft_bounce', 'complaint' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Numbers per reason.
	 *
	 * @return array reason => count, plus "all".
	 */
	public static function counts() {
		global $wpdb;
		$out = array(
			'all'          => 0,
			'bounced'      => 0,
			'soft'         => 0,
			'complained'   => 0,
			'unsubscribed' => 0,
			'manual'       => 0,
		);
		if ( ! self::exists() ) {
			return $out;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT reason, COUNT(*) AS n FROM %i GROUP BY reason', self::table() ) ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ $r->reason ] = (int) $r->n;
			$out['all']       += (int) $r->n;
		}
		return $out;
	}

	/**
	 * What an address with this reason still gets, in words.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	public static function still_gets( $reason ) {
		$t = self::blocks( $reason, 'transactional' );
		$m = self::blocks( $reason, 'marketing' );
		if ( $t && $m ) {
			return __( 'Nothing', 'aluleth-smtp' );
		}
		if ( $m ) {
			return __( 'Everything except marketing emails', 'aluleth-smtp' );
		}
		return __( 'Everything (only marked)', 'aluleth-smtp' );
	}

	/**
	 * Name of a reason.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'bounced'      => __( 'Hard bounce', 'aluleth-smtp' ),
			'soft'         => __( 'Soft bounces', 'aluleth-smtp' ),
			'complained'   => __( 'Spam report', 'aluleth-smtp' ),
			'unsubscribed' => __( 'Unsubscribed', 'aluleth-smtp' ),
			'manual'       => __( 'Blocked by hand', 'aluleth-smtp' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}
}
