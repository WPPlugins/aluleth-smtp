<?php
/**
 * Alerts when emails cannot be sent: an email to the site owner and a message
 * in Slack, Discord or Telegram. At most one alert every 15 minutes.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts.
 */
final class Alerts {

	/**
	 * Slack incoming webhook addresses (the only ones alerts are posted to).
	 */
	const SLACK = '#^https://hooks\\.slack\\.com/services/[A-Za-z0-9/_-]+$#';

	/**
	 * Discord channel webhook addresses (the only ones alerts are posted to).
	 */
	const DISCORD = '#^https://(?:(?:canary|ptb)\\.)?discord(?:app)?\\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$#';

	/**
	 * Failures waiting for the next alert.
	 */
	const PENDING = 'aluleth_smtp_alert_pending';

	/**
	 * Time of the last alert.
	 */
	const LAST = 'aluleth_smtp_alert_last';

	/**
	 * Minutes between alerts.
	 */
	const EVERY = 15;

	/**
	 * Notes a failure and alerts if it is time.
	 *
	 * @param int    $id      Log id.
	 * @param string $subject Subject of the email.
	 * @param string $error   Error.
	 * @return void
	 */
	public static function failed( $id, $subject, $error ) {
		$p          = get_option( self::PENDING, array() );
		$p          = is_array( $p ) ? $p : array();
		$p['count'] = ( isset( $p['count'] ) ? (int) $p['count'] : 0 ) + 1;
		$p['last']  = array(
			'id'      => (int) $id,
			'subject' => substr( wp_strip_all_tags( (string) $subject ), 0, 150 ),
			'error'   => substr( Message::mask( (string) $error ), 0, 300 ),
		);
		update_option( self::PENDING, $p, false );
		self::flush();
	}

	/**
	 * Sends the alert when failures wait and the last alert is old enough.
	 *
	 * @return void
	 */
	public static function flush() {
		$p = get_option( self::PENDING, array() );
		if ( ! is_array( $p ) || empty( $p['count'] ) ) {
			return;
		}
		if ( (int) get_option( self::LAST, 0 ) > time() - self::EVERY * MINUTE_IN_SECONDS ) {
			return;
		}
		update_option( self::LAST, time(), false );
		delete_option( self::PENDING );
		self::send( (int) $p['count'], isset( $p['last'] ) ? (array) $p['last'] : array() );
	}

	/**
	 * Sends the alert on every channel set up.
	 *
	 * @param int   $count Failures.
	 * @param array $last  Last failure.
	 * @return array Channel => true or error text.
	 */
	public static function send( $count, array $last ) {
		$s    = Connections::settings();
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$link = admin_url( 'admin.php?page=aluleth-smtp&tab=log&status=failed' );
		/* translators: 1: number of emails, 2: site name. */
		$title = sprintf( _n( '%1$d email could not be sent on %2$s', '%1$d emails could not be sent on %2$s', $count, 'aluleth-smtp' ), $count, $site );
		if ( 0 === $count ) {
			/* translators: %s: site name. */
			$title = sprintf( __( 'Test alert from Aluleth SMTP on %s', 'aluleth-smtp' ), $site );
		}
		$lines = array( $title );
		if ( ! empty( $last['subject'] ) ) {
			/* translators: 1: subject, 2: error. */
			$lines[] = sprintf( __( 'Last one: “%1$s” · %2$s', 'aluleth-smtp' ), $last['subject'], $last['error'] );
		}
		/* translators: %s: link to the log. */
		$lines[] = sprintf( __( 'Open the email log: %s', 'aluleth-smtp' ), $link );
		$text    = implode( "\n", $lines );
		$done    = array();

		if ( $s['alert_email'] ) {
			$to = '' !== $s['alert_to'] ? $s['alert_to'] : get_option( 'admin_email' );
			// Sent the WordPress way: the connections may be what is broken.
			Sender::$bypass = true;
			$done['email']  = wp_mail( $to, '[' . $site . '] ' . $title, $text );
			Sender::$bypass = false;
		}
		$slack = Secrets::unlock( $s['slack'] );
		if ( '' !== $slack && preg_match( self::SLACK, $slack ) ) {
			$done['slack'] = self::post( $slack, array( 'text' => $text ) );
		}
		$discord = Secrets::unlock( $s['discord'] );
		if ( '' !== $discord && preg_match( self::DISCORD, $discord ) ) {
			$done['discord'] = self::post( $discord, array( 'content' => substr( $text, 0, 1900 ) ) );
		}
		$token = Secrets::unlock( $s['telegram_token'] );
		if ( '' !== $token && '' !== $s['telegram_chat'] ) {
			$done['telegram'] = self::post(
				'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage',
				array(
					'chat_id' => $s['telegram_chat'],
					'text'    => $text,
				)
			);
		}
		return $done;
	}

	/**
	 * Posts JSON to a chat service.
	 *
	 * @param string $url  Address.
	 * @param array  $data Data.
	 * @return true|string
	 */
	private static function post( $url, array $data ) {
		$res = wp_safe_remote_post(
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $data ),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300 ? true : 'HTTP ' . $code;
	}
}
