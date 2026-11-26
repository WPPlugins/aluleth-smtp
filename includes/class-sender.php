<?php
/**
 * Takes over wp_mail(): every email goes through the chosen connection,
 * with the fallback, the limits, the queue and the log.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Sender.
 */
final class Sender {

	/**
	 * When true, wp_mail() works as without the plugin (alert emails when every connection fails).
	 *
	 * @var bool
	 */
	public static $bypass = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'intercept' ), 100, 2 );
	}

	/**
	 * Sends an email given to wp_mail().
	 *
	 * @param null|bool $pre  Answer of an earlier filter.
	 * @param array     $atts to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public static function intercept( $pre, $atts ) {
		if ( null !== $pre || self::$bypass || ! Connections::active() ) {
			return $pre;
		}
		$msg    = Message::from_wp_mail( (array) $atts );
		$source = ! empty( $msg['meta']['source'] ) ? $msg['meta']['source'] : Router::source();
		return self::submit( $msg, $source, (array) $atts );
	}

	/**
	 * Logs and sends (or queues) a message.
	 *
	 * @param array  $msg    Message.
	 * @param string $source Source.
	 * @param array  $atts   Original wp_mail() arguments (for the WordPress actions).
	 * @param bool   $now    Send now even if it is a marketing email (tests, resend).
	 * @return bool Whether the email was sent or is waiting to be sent.
	 */
	public static function submit( array $msg, $source, array $atts = array(), $now = false ) {
		$s      = Connections::settings();
		$kind   = Router::kind( $msg, $source );
		$route  = Router::route( $msg, $source, $kind );
		$keep   = 'marketing' === $kind ? $s['log_marketing'] : $s['log_transactional'];
		$queued = 'marketing' === $kind && $s['queue_marketing'] && ! $now;
		$count  = count( Message::recipients( $msg ) );
		unset( $msg['meta'] );

		if ( 0 === $count ) {
			self::failed_action( $atts, __( 'The email has no valid recipient.', 'aluleth-smtp' ) );
			return false;
		}

		/**
		 * Filters a message before it is sent (or false to block it).
		 *
		 * @param array|false $msg    Message.
		 * @param string      $source Source.
		 * @param string      $kind   Kind.
		 */
		$msg = apply_filters( 'aluleth_smtp/message', $msg, $source, $kind );
		if ( ! is_array( $msg ) ) {
			return false;
		}

		// Blocked addresses (bounced, spam reports…) are taken out, by the rules set in Bounces.
		$blocked = Blocklist::filter( $msg, $kind );
		$skipped = $blocked['removed'];
		if ( $skipped ) {
			$msg   = $blocked['msg'];
			$count = count( Message::recipients( $msg ) );
			$why   = array();
			foreach ( $skipped as $email => $reason ) {
				$why[] = $email . ' (' . Blocklist::reason_label( $reason ) . ')';
			}
			$why = implode( ', ', $why );
			if ( 0 === $count ) {
				$id = Log::create(
					array(
						'status'          => 'blocked',
						'source'          => substr( $source, 0, 60 ),
						'kind'            => $kind,
						'recipients'      => substr( implode( ', ', array_keys( $skipped ) ), 0, 500 ),
						'recipient_count' => count( $skipped ),
						'subject'         => substr( wp_strip_all_tags( $msg['subject'] ), 0, 255 ),
						'keep'            => 'summary',
						'payload'         => null,
					)
				);
				if ( $id ) {
					Log::step( $id, __( 'Not sent: blocked address', 'aluleth-smtp' ), $why, 'error', false );
				}
				self::failed_action( $atts, __( 'Every recipient is on the blocked list.', 'aluleth-smtp' ) );
				return false;
			}
		}

		$id = Log::create(
			array(
				'status'          => $queued ? 'queued' : 'sending',
				'priority'        => 'marketing' === $kind ? 9 : 1,
				'source'          => substr( $source, 0, 60 ),
				'kind'            => $kind,
				'connection'      => (string) $route['connection'],
				'recipients'      => Log::recipients_text( $msg ),
				'recipient_count' => $count,
				'subject'         => substr( wp_strip_all_tags( $msg['subject'] ), 0, 255 ),
				'keep'            => $keep,
				'payload'         => null,
			)
		);
		if ( $id && $skipped ) {
			/* translators: %s: addresses and reasons. */
			Log::step( $id, __( 'Some recipients were left out', 'aluleth-smtp' ), sprintf( __( 'Blocked: %s', 'aluleth-smtp' ), $why ), 'info', false );
		}
		if ( ! $id ) {
			// No log table: send anyway.
			$out = self::deliver( 0, $msg, $route, $kind, 1, false );
			return 'sent' === $out['status'];
		}

		if ( $queued ) {
			$msg = Queue::keep_attachments( $msg, $id );
			Log::update( $id, array( 'payload' => wp_json_encode( self::payload( $msg, $route ) ) ) );
			Log::step( $id, __( 'Queued', 'aluleth-smtp' ), __( 'Marketing email, sent in the background.', 'aluleth-smtp' ) );
			Queue::kick();
			return true;
		}

		$out = self::deliver( $id, $msg, $route, $kind, 1, false );
		return self::settle( $id, $msg, $route, $kind, 1, $out, $atts );
	}

	/**
	 * What waits in the queue: the message and its route.
	 *
	 * @param array $msg   Message.
	 * @param array $route Route.
	 * @return array
	 */
	public static function payload( array $msg, array $route ) {
		$msg['route'] = array(
			'connection' => (string) $route['connection'],
			'fallback'   => (string) $route['fallback'],
		);
		return $msg;
	}

	/**
	 * Records the outcome of a sending attempt.
	 *
	 * @param int    $id      Log id.
	 * @param array  $msg     Message.
	 * @param array  $route   Route.
	 * @param string $kind    Kind.
	 * @param int    $attempt Attempt number.
	 * @param array  $out     Outcome of deliver().
	 * @param array  $atts    Original wp_mail() arguments.
	 * @return bool
	 */
	public static function settle( $id, array $msg, array $route, $kind, $attempt, array $out, array $atts = array() ) {
		unset( $kind );
		if ( 'sent' === $out['status'] ) {
			if ( ! empty( $out['from'] ) ) {
				// The log shows the From and Reply-To addresses the connection really used.
				$msg['from']     = $out['from'];
				$msg['reply_to'] = $out['reply_to'];
			}
			Log::finish(
				$id,
				'sent',
				array(
					'message_id' => $out['id'],
					'response'   => $out['response'],
					'connection' => $out['connection'],
					'fallback'   => $out['fallback'] ? 1 : 0,
					'attempts'   => $attempt,
				),
				$msg
			);
			Queue::drop_attachments( $msg );
			if ( $atts ) {
				do_action( 'wp_mail_succeeded', self::mail_data( $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core action.
			}
			return true;
		}
		if ( 'wait' === $out['status'] || ( 'retry' === $out['status'] && $attempt < Queue::MAX_ATTEMPTS ) ) {
			$delay = 'wait' === $out['status'] ? max( 1, (int) $out['wait'] ) : Queue::backoff( $attempt );
			if ( empty( $msg['attachments'] ) || false === strpos( (string) $msg['attachments'][0]['path'], Queue::folder() ) ) {
				$msg = Queue::keep_attachments( $msg, $id );
			}
			Log::update(
				$id,
				array(
					'status'   => 'queued',
					'run_at'   => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					'attempts' => 'wait' === $out['status'] ? max( 0, $attempt - 1 ) : $attempt,
					'response' => $out['error'],
					'payload'  => wp_json_encode( self::payload( $msg, $route ) ),
				)
			);
			if ( 'wait' === $out['status'] ) {
				/* translators: %d: seconds. */
				Log::step( $id, __( 'Waiting for the sending limit', 'aluleth-smtp' ), sprintf( __( 'Next try in %d s.', 'aluleth-smtp' ), $delay ) );
			} else {
				/* translators: %s: time to wait, like "5 min". */
				Log::step( $id, __( 'Will try again', 'aluleth-smtp' ), sprintf( __( 'In %s.', 'aluleth-smtp' ), human_time_diff( time(), time() + $delay ) ) );
			}
			Queue::kick();
			return true;
		}
		Log::finish(
			$id,
			'failed',
			array(
				'response'   => $out['error'],
				'connection' => $out['connection'],
				'attempts'   => $attempt,
			),
			$msg
		);
		Queue::drop_attachments( $msg );
		Alerts::failed( $id, $msg['subject'], $out['error'] );
		if ( $atts ) {
			self::failed_action( $atts, $out['error'] );
		}
		return false;
	}

	/**
	 * Fires the WordPress "wp_mail_failed" action, as wp_mail() does.
	 *
	 * @param array  $atts  Arguments.
	 * @param string $error Error.
	 * @return void
	 */
	private static function failed_action( array $atts, $error ) {
		$data                             = self::mail_data( $atts );
		$data['phpmailer_exception_code'] = 0;
		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', $error, $data ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core action.
	}

	/**
	 * Mail data as WordPress gives it to its actions.
	 *
	 * @param array $atts Arguments.
	 * @return array
	 */
	private static function mail_data( array $atts ) {
		return array(
			'to'          => isset( $atts['to'] ) ? $atts['to'] : array(),
			'subject'     => isset( $atts['subject'] ) ? $atts['subject'] : '',
			'message'     => isset( $atts['message'] ) ? $atts['message'] : '',
			'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : array(),
			'attachments' => isset( $atts['attachments'] ) ? $atts['attachments'] : array(),
		);
	}

	/**
	 * The message as a connection sends it (its From address when asked or when WordPress's default is set).
	 *
	 * @param array $msg Message.
	 * @param array $c   Connection.
	 * @return array
	 */
	public static function for_connection( array $msg, array $c ) {
		$asked         = $msg['from'];
		$default_email = 0 === strpos( strtolower( $asked['email'] ), 'wordpress@' );
		if ( $c['force_from'] || $default_email || '' === $asked['email'] ) {
			$msg['from']['email'] = $c['from_email'];
		}
		// A different From address still lets people reply to the address the plugin asked for.
		if ( ! $default_email && '' !== $asked['email'] && empty( $msg['reply_to'] ) && strtolower( $asked['email'] ) !== strtolower( $msg['from']['email'] ) ) {
			$msg['reply_to'] = array( $asked );
		}
		if ( '' !== $c['from_name'] && ( $c['force_name'] || 'WordPress' === $msg['from']['name'] || '' === $msg['from']['name'] ) ) {
			$msg['from']['name'] = $c['from_name'];
		}
		return $msg;
	}

	/**
	 * Tries the connection, then the fallback.
	 *
	 * @param int    $id      Log id (0 when there is no log).
	 * @param array  $msg     Message.
	 * @param array  $route   connection, fallback.
	 * @param string $kind    Kind.
	 * @param int    $attempt Attempt number.
	 * @param bool   $queued  Whether the queue runs it (it waits for limits instead of sleeping).
	 * @return array status (sent, retry, failed, wait), id, response, error, connection, fallback, wait, from and reply_to (when sent).
	 */
	public static function deliver( $id, array $msg, array $route, $kind, $attempt, $queued ) {
		$tries = array();
		foreach ( array( $route['connection'], $route['fallback'] ) as $i => $cid ) {
			if ( '' !== (string) $cid && ! in_array( $cid, $tries, true ) ) {
				$tries[ $i ] = (string) $cid;
			}
		}
		$out   = array(
			'status'     => 'failed',
			'id'         => '',
			'response'   => '',
			'error'      => __( 'No connection can send this email. Add one in Aluleth › Email sending.', 'aluleth-smtp' ),
			'connection' => (string) $route['connection'],
			'fallback'   => false,
			'wait'       => 0,
		);
		$retry = false;
		$waits = array();
		$count = count( Message::recipients( $msg ) );
		foreach ( $tries as $i => $cid ) {
			$c = Connections::get( $cid );
			if ( ! $c ) {
				continue;
			}
			$provider = Connections::provider( $c['provider'] );
			$wait     = Limiter::wait( $c );
			if ( $wait > 0 ) {
				if ( ! $queued && $wait <= 2 && 'second' === $c['limit_unit'] ) {
					sleep( $wait );
				} else {
					$waits[] = $wait;
					continue;
				}
			}
			if ( Connections::unreadable( $c ) ) {
				$result = array(
					'ok'    => false,
					'error' => __( 'The saved key can no longer be read (the site’s secret keys changed). Paste it again in the connection.', 'aluleth-smtp' ),
					'retry' => false,
				);
			} else {
				$send   = self::for_connection( $msg, $c );
				$result = self::send_split( $provider, $send, Connections::credentials( $c ), $kind, $id, $attempt );
				Limiter::hit( $c, $count );
			}
			if ( ! empty( $result['ok'] ) ) {
				if ( $id ) {
					/* translators: %s: connection name. */
					Log::step( $id, sprintf( __( 'Accepted by %s', 'aluleth-smtp' ), $c['label'] ), '' !== $result['id'] ? 'ID ' . $result['id'] : '', 'ok', false );
				}
				return array(
					'status'     => 'sent',
					'id'         => $result['id'],
					'response'   => isset( $result['response'] ) ? $result['response'] : '',
					'error'      => '',
					'connection' => $cid,
					'fallback'   => $i > 0,
					'wait'       => 0,
					'from'       => $send['from'],
					'reply_to'   => $send['reply_to'],
				);
			}
			if ( $id ) {
				/* translators: %s: connection name. */
				Log::step( $id, sprintf( __( '%s did not send it', 'aluleth-smtp' ), $c['label'] ), $result['error'], 'error' );
			}
			$retry             = $retry || ! empty( $result['retry'] );
			$out['error']      = $c['label'] . ': ' . $result['error'];
			$out['connection'] = $cid;
		}
		if ( $waits && ! $retry && count( $waits ) === count( $tries ) ) {
			$out['status'] = 'wait';
			$out['wait']   = min( $waits );
			$out['error']  = __( 'Waiting for the sending limit of the connection.', 'aluleth-smtp' );
			return $out;
		}
		if ( $waits ) {
			// One connection failed, the other one is only busy: wait for it.
			$out['status'] = 'wait';
			$out['wait']   = min( $waits );
			return $out;
		}
		$out['status'] = $retry ? 'retry' : 'failed';
		return $out;
	}

	/**
	 * Sends with one service, in several requests when there are more recipients than it accepts at once.
	 *
	 * @param Provider $provider Service.
	 * @param array    $msg      Message.
	 * @param array    $creds    Settings with secrets.
	 * @param string   $kind     Kind.
	 * @param int      $id       Log id.
	 * @param int      $attempt  Attempt.
	 * @return array Result of the last request (or the first failure).
	 */
	private static function send_split( Provider $provider, array $msg, array $creds, $kind, $id, $attempt ) {
		$context = array(
			'kind'    => $kind,
			'mail_id' => $id,
			'attempt' => $attempt,
			'ref'     => Tracking::ref( $id ),
		);
		$max     = max( 1, $provider->max_recipients() );
		if ( count( $msg['to'] ) + count( $msg['cc'] ) + count( $msg['bcc'] ) <= $max ) {
			return $provider->send( $msg, $creds, $context );
		}
		// Many recipients: the To and Cc stay together, Bcc goes in batches.
		$bcc    = $msg['bcc'];
		$first  = true;
		$result = null;
		$room   = max( 1, $max - count( $msg['to'] ) - count( $msg['cc'] ) );
		do {
			$part        = $msg;
			$part['bcc'] = array_splice( $bcc, 0, $room );
			if ( ! $first ) {
				$part['to'] = array();
				$part['cc'] = array();
				if ( ! $part['bcc'] ) {
					break;
				}
				$part['to'] = array( array_shift( $part['bcc'] ) );
			}
			$result = $provider->send( $part, $creds, $context );
			if ( empty( $result['ok'] ) ) {
				return $result;
			}
			$first = false;
			$room  = $max;
		} while ( $bcc );
		return $result;
	}
}
