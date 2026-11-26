<?php
/**
 * What happens after sending: the events email services report (delivered,
 * bounced, marked as spam, rejected), matched to the emails of this site.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking.
 */
final class Tracking {

	/**
	 * Kinds of events.
	 */
	const TYPES = array( 'delivered', 'hard_bounce', 'soft_bounce', 'complaint', 'rejected', 'deferred', 'unsubscribed' );

	/**
	 * Short key of this site, sent with each email so events of other sites on the same account are left alone.
	 *
	 * @return string
	 */
	public static function site_key() {
		$key = get_option( 'aluleth_smtp_site_key' );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9]{8}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 8, false, false ) );
			update_option( 'aluleth_smtp_site_key', $key, false );
		}
		return $key;
	}

	/**
	 * Reference of an email for the services: "als-<site key>-<log id>".
	 *
	 * @param int $mail_id Log id.
	 * @return string
	 */
	public static function ref( $mail_id ) {
		return $mail_id ? 'als-' . self::site_key() . '-' . (int) $mail_id : '';
	}

	/**
	 * Log id in a reference, 0 when it is not an email of this site, -1 when there is no reference.
	 *
	 * @param string $ref Reference.
	 * @return int
	 */
	public static function from_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return -1;
		}
		if ( ! preg_match( '/als-([a-z0-9]{8})-(\d+)/', $ref, $m ) ) {
			return -1;
		}
		return self::site_key() === $m[1] ? (int) $m[2] : 0;
	}

	/**
	 * Records what a service reported.
	 *
	 * @param string $connection Connection id.
	 * @param array  $events     Events: type, email, message_id, ref, detail, id (unique at the service), time.
	 * @return array counts: used, ignored, duplicate.
	 */
	public static function handle( $connection, array $events ) {
		global $wpdb;
		$done = array(
			'used'      => 0,
			'ignored'   => 0,
			'duplicate' => 0,
		);
		foreach ( array_slice( $events, 0, 1000 ) as $e ) {
			$e = wp_parse_args(
				(array) $e,
				array(
					'type'       => '',
					'email'      => '',
					'message_id' => '',
					'ref'        => '',
					'detail'     => '',
					'id'         => '',
					'time'       => 0,
				)
			);
			if ( ! in_array( $e['type'], self::TYPES, true ) ) {
				++$done['ignored'];
				continue;
			}
			$email = strtolower( trim( (string) $e['email'] ) );
			$row   = self::match( $e );
			if ( ! $row['ours'] ) {
				++$done['ignored'];
				continue;
			}
			$unique = sha1( $connection . '|' . ( '' !== (string) $e['id'] ? (string) $e['id'] : $e['type'] . '|' . $email . '|' . $e['message_id'] . '|' . (int) $e['time'] ) );
			$detail = substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( Message::mask( (string) $e['detail'] ) ) ) ), 0, 500 );
			$when   = (int) $e['time'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $e['time'] ) : current_time( 'mysql', true );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$seen = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE ref = %s', Installer::events_table(), $unique ) );
			$in   = ! $seen && $wpdb->insert(
				Installer::events_table(),
				array(
					'created_at' => $when,
					'connection' => substr( (string) $connection, 0, 40 ),
					'mail_id'    => $row['id'],
					'type'       => $e['type'],
					'email'      => substr( $email, 0, 190 ),
					'detail'     => $detail,
					'ref'        => $unique,
				)
			);
			// phpcs:enable
			if ( ! $in ) {
				++$done['duplicate'];
				continue;
			}
			++$done['used'];
			self::apply( $connection, $e['type'], $email, $detail, $row['id'], $when );
		}
		if ( $done['used'] || $done['ignored'] ) {
			Hooks::seen( $connection );
		}
		return $done;
	}

	/**
	 * Finds the email an event is about.
	 *
	 * @param array $e Event.
	 * @return array ours (bool), id (log id or 0).
	 */
	private static function match( array $e ) {
		global $wpdb;
		$from = self::from_ref( $e['ref'] );
		if ( 0 === $from ) {
			return array(
				'ours' => false,
				'id'   => 0,
			);
		}
		if ( $from > 0 ) {
			// The log row may be gone ("keep nothing"): the email is still ours.
			return array(
				'ours' => true,
				'id'   => Log::get( $from ) ? $from : 0,
			);
		}
		$mid = trim( (string) $e['message_id'], " <>\t" );
		if ( '' === $mid ) {
			return array(
				'ours' => false,
				'id'   => 0,
			);
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE message_id IN (%s, %s) ORDER BY id DESC LIMIT 1', Installer::table(), $mid, '<' . $mid . '>' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'ours' => $id > 0,
			'id'   => $id,
		);
	}

	/**
	 * What an event changes: the log, the blocked addresses, the contact.
	 *
	 * @param string $connection Connection id.
	 * @param string $type       Type.
	 * @param string $email      Address.
	 * @param string $detail     Detail.
	 * @param int    $mail_id    Log id (0 when gone).
	 * @param string $when       UTC date time.
	 * @return void
	 */
	private static function apply( $connection, $type, $email, $detail, $mail_id, $when ) {
		$row     = $mail_id ? Log::get( $mail_id ) : null;
		$s       = Connections::settings();
		$subject = $row ? (string) $row->subject : '';
		$who     = '' !== $email ? $email : __( 'a recipient', 'aluleth-smtp' );
		switch ( $type ) {
			case 'delivered':
				if ( $row && '' === $row->outcome ) {
					Log::update( $mail_id, array( 'outcome' => 'delivered' ) );
				}
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( 'Delivered to %s', 'aluleth-smtp' ), $who ), $detail, 'ok' );
				break;
			case 'hard_bounce':
				self::outcome( $row, 'bounced' );
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( 'Bounced: %s does not exist', 'aluleth-smtp' ), $who ), $detail, 'error' );
				if ( 'mark' !== $s['hard_policy'] ) {
					Blocklist::add( $email, 'bounced', $connection );
				}
				self::contact( 'email.bounced', $email, $subject, $detail );
				break;
			case 'soft_bounce':
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( 'Soft bounce for %s', 'aluleth-smtp' ), $who ), $detail, 'error' );
				self::soft( $email, $connection );
				break;
			case 'complaint':
				self::outcome( $row, 'complained' );
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( '%s marked it as spam', 'aluleth-smtp' ), $who ), $detail, 'error' );
				Blocklist::add( $email, 'complained', $connection );
				self::contact( 'email.complained', $email, $subject, $detail );
				break;
			case 'rejected':
				self::outcome( $row, 'rejected' );
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( 'Rejected by the service after it was accepted (%s)', 'aluleth-smtp' ), $who ), $detail, 'error' );
				Alerts::failed( $mail_id, '' !== $subject ? $subject : __( '(no subject)', 'aluleth-smtp' ), $detail );
				break;
			case 'deferred':
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( 'Delayed for %s, the service tries again', 'aluleth-smtp' ), $who ), $detail, 'info' );
				break;
			case 'unsubscribed':
				if ( '' !== $email ) {
					Blocklist::add( $email, 'unsubscribed', $connection );
				}
				/* translators: %s: email address. */
				self::step( $mail_id, sprintf( __( '%s unsubscribed', 'aluleth-smtp' ), $who ), $detail, 'info' );
				break;
		}
		unset( $when );
	}

	/**
	 * Sets the outcome of a log row (a worse one wins).
	 *
	 * @param object|null $row     Row.
	 * @param string      $outcome Outcome.
	 * @return void
	 */
	private static function outcome( $row, $outcome ) {
		if ( ! $row ) {
			return;
		}
		$rank = array(
			''           => 0,
			'delivered'  => 1,
			'rejected'   => 2,
			'bounced'    => 3,
			'complained' => 4,
		);
		$old  = isset( $rank[ $row->outcome ] ) ? $rank[ $row->outcome ] : 0;
		if ( $rank[ $outcome ] > $old ) {
			Log::update( (int) $row->id, array( 'outcome' => $outcome ) );
		}
	}

	/**
	 * A step on the log row, when there is one.
	 *
	 * @param int    $mail_id Log id.
	 * @param string $what    Title.
	 * @param string $detail  Detail.
	 * @param string $state   State.
	 * @return void
	 */
	private static function step( $mail_id, $what, $detail, $state ) {
		if ( $mail_id ) {
			Log::step( $mail_id, $what, $detail, $state );
		}
	}

	/**
	 * Soft bounces: blocks marketing emails after too many in a short time.
	 *
	 * @param string $email      Address.
	 * @param string $connection Connection id.
	 * @return void
	 */
	private static function soft( $email, $connection ) {
		global $wpdb;
		$policy = Connections::settings()['soft_policy'];
		if ( '' === $email || 'never' === $policy ) {
			return;
		}
		list( $limit, $days ) = array_map( 'intval', explode( '_', $policy ) );
		$since                = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$n                    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE email = %s AND type = %s AND created_at >= %s', Installer::events_table(), $email, 'soft_bounce', $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $n >= $limit ) {
			Blocklist::add( $email, 'soft', $connection );
		}
	}

	/**
	 * Adds the event to the contact's history in Aluleth › Contacts, when the address is a contact.
	 *
	 * @param string $type    Event type.
	 * @param string $email   Address.
	 * @param string $subject Subject of the email.
	 * @param string $detail  Detail.
	 * @return void
	 */
	private static function contact( $type, $email, $subject, $detail ) {
		if ( '' === $email || ! function_exists( 'aluleth_core' ) ) {
			return;
		}
		$contact = aluleth_core()->contacts()->get_by_email( $email );
		if ( ! $contact ) {
			return;
		}
		aluleth_core()->events()->record(
			$type,
			array(
				'contact' => (int) $contact->id,
				'data'    => array(
					'title'  => '' !== $subject ? $subject : $detail,
					'detail' => $detail,
				),
				'source'  => 'aluleth-smtp',
			)
		);
	}

	/**
	 * Numbers of events since a date.
	 *
	 * @param string $since UTC date time.
	 * @return array type => count.
	 */
	public static function counts( $since ) {
		global $wpdb;
		$out = array_fill_keys( self::TYPES, 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT type, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY type', Installer::events_table(), $since ) ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ $r->type ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Deletes old events (soft bounces are counted over 30 days at most).
	 *
	 * @param int $days Days to keep.
	 * @return void
	 */
	public static function prune( $days ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Installer::events_table(), gmdate( 'Y-m-d H:i:s', time() - max( 31, (int) $days ) * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
