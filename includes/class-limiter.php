<?php
/**
 * Sending limits of each connection (so many emails per second, minute, hour or day).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Limiter.
 */
final class Limiter {

	/**
	 * Counter key of a connection in the current window.
	 *
	 * @param array $c   Connection.
	 * @param int   $now Time.
	 * @return array key, seconds left in the window.
	 */
	private static function window( array $c, $now ) {
		$len    = Connections::UNITS[ $c['limit_unit'] ];
		$window = (int) floor( $now / $len );
		return array(
			'key'  => 'alsmtp_rl_' . md5( get_current_blog_id() . '|' . $c['id'] . '|' . $c['limit_unit'] . '|' . $window ),
			'left' => ( $window + 1 ) * $len - $now,
			'len'  => $len,
		);
	}

	/**
	 * Seconds to wait before this connection may send again (0 = now).
	 *
	 * @param array $c Connection.
	 * @return int
	 */
	public static function wait( array $c ) {
		if ( $c['limit_n'] < 1 ) {
			return 0;
		}
		$w    = self::window( $c, time() );
		$used = (int) get_transient( $w['key'] );
		return $used < $c['limit_n'] ? 0 : max( 1, $w['left'] );
	}

	/**
	 * Counts emails sent by a connection.
	 *
	 * @param array $c     Connection.
	 * @param int   $count Emails (recipients count for the services that charge per recipient).
	 * @return void
	 */
	public static function hit( array $c, $count = 1 ) {
		if ( $c['limit_n'] < 1 ) {
			return;
		}
		$w = self::window( $c, time() );
		set_transient( $w['key'], (int) get_transient( $w['key'] ) + max( 1, (int) $count ), $w['len'] + 60 );
	}

	/**
	 * Use of a connection in the current window: used, limit, unit.
	 *
	 * @param array $c Connection.
	 * @return array
	 */
	public static function usage( array $c ) {
		if ( $c['limit_n'] < 1 ) {
			return array(
				'used'  => 0,
				'limit' => 0,
				'unit'  => $c['limit_unit'],
			);
		}
		$w = self::window( $c, time() );
		return array(
			'used'  => (int) get_transient( $w['key'] ),
			'limit' => $c['limit_n'],
			'unit'  => $c['limit_unit'],
		);
	}
}
