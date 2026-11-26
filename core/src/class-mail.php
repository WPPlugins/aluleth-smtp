<?php
/**
 * Email sending for every Aluleth plugin.
 *
 * Version 0.1 sends through wp_mail() right away. The queue, priorities and
 * provider routing described in the core spec arrive with Aluleth SMTP.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Mail service.
 */
final class Mail {

	/**
	 * Sends an email.
	 *
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $html    HTML body (wrapped in the core layout).
	 * @param array           $args    'reply_to', 'contact' (id, for the timeline), 'kind' ('transactional' or 'marketing'), 'title', 'from_name', 'from_email', 'attachments' (file paths).
	 * @return bool
	 */
	public function send( $to, $subject, $html, array $args = array() ) {
		$recipients = array();
		foreach ( (array) $to as $address ) {
			$address = Util::email( $address );
			if ( $address && ! $this->is_suppressed( $address, isset( $args['kind'] ) ? $args['kind'] : 'transactional' ) ) {
				$recipients[] = $address;
			}
		}
		if ( ! $recipients ) {
			return false;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		// Aluleth SMTP reads the kind (marketing emails can wait in its queue and use their own connection).
		if ( defined( 'ALULETH_SMTP_VERSION' ) && isset( $args['kind'] ) && 'marketing' === $args['kind'] ) {
			$headers[] = 'X-Aluleth-Kind: marketing';
		}
		if ( ! empty( $args['reply_to'] ) && Util::email( $args['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . Util::email( $args['reply_to'] );
		}
		$from_email = ! empty( $args['from_email'] ) ? Util::email( $args['from_email'] ) : '';
		$from_name  = ! empty( $args['from_name'] ) ? trim( str_replace( array( "\r", "\n", '"', '<', '>' ), '', (string) $args['from_name'] ) ) : '';
		if ( $from_email ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? '"' . $from_name . '" ' : '' ) . '<' . $from_email . '>';
		} elseif ( '' !== $from_name ) {
			$filter = static function () use ( $from_name ) {
				return $from_name;
			};
			add_filter( 'wp_mail_from_name', $filter );
		}

		$attachments = array();
		foreach ( isset( $args['attachments'] ) ? (array) $args['attachments'] : array() as $file ) {
			if ( is_string( $file ) && '' !== $file && is_readable( $file ) ) {
				$attachments[] = $file;
			}
		}
		$body = $this->layout( $html );
		$sent = wp_mail( $recipients, wp_specialchars_decode( (string) $subject, ENT_QUOTES ), $body, $headers, $attachments );
		if ( isset( $filter ) ) {
			remove_filter( 'wp_mail_from_name', $filter );
		}

		if ( $sent && ! empty( $args['contact'] ) ) {
			aluleth_core()->events()->record(
				'email.sent',
				array(
					'contact' => (int) $args['contact'],
					'data'    => array( 'title' => wp_strip_all_tags( (string) $subject ) ),
				)
			);
		}
		return (bool) $sent;
	}

	/**
	 * Whether an address must not receive this kind of email.
	 *
	 * Bounced and complained addresses get nothing; unsubscribed ones still get
	 * transactional emails (a receipt, a reply to their own form).
	 *
	 * @param string $email Address.
	 * @param string $kind  'transactional' or 'marketing'.
	 * @return bool
	 */
	public function is_suppressed( $email, $kind = 'transactional' ) {
		global $wpdb;
		$reason = $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$wpdb->prefix}aluleth_suppression WHERE email_hash = %s", hash( 'sha256', strtolower( $email ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $reason ) {
			return false;
		}
		$blocked = 'marketing' === $kind || in_array( $reason, array( 'bounced', 'complained', 'manual' ), true );

		/**
		 * Filters whether a suppressed address is skipped (Aluleth SMTP applies its own rules).
		 *
		 * @param bool   $blocked Whether the address is skipped.
		 * @param string $reason  unsubscribed, bounced, soft, complained or manual.
		 * @param string $email   Address.
		 * @param string $kind    transactional or marketing.
		 */
		return (bool) apply_filters( 'aluleth/mail/suppressed', $blocked, $reason, strtolower( $email ), $kind );
	}

	/**
	 * Removes an address from the suppression list.
	 *
	 * @param string $email Address.
	 * @return bool Whether it was on the list.
	 */
	public function unsuppress( $email ) {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'aluleth_suppression', array( 'email_hash' => hash( 'sha256', strtolower( trim( (string) $email ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Adds an address to the suppression list (never emailed again for marketing;
	 * bounced and complained addresses get nothing at all).
	 *
	 * @param string $email    Address.
	 * @param string $reason   'unsubscribed', 'bounced', 'soft' (repeated soft bounces), 'complained' or 'manual'.
	 * @param string $provider Where the information comes from, e.g. 'import'.
	 * @return void
	 */
	public function suppress( $email, $reason, $provider = '' ) {
		global $wpdb;
		$email = Util::email( $email );
		if ( '' === $email || ! in_array( $reason, array( 'unsubscribed', 'bounced', 'soft', 'complained', 'manual' ), true ) ) {
			return;
		}
		$hash = hash( 'sha256', $email );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table  = $wpdb->prefix . 'aluleth_suppression';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT reason FROM %i WHERE email_hash = %s', $table, $hash ) );
		if ( null === $exists ) {
			$wpdb->insert(
				$table,
				array(
					'email_hash' => $hash,
					'email'      => $email,
					'reason'     => $reason,
					'provider'   => substr( sanitize_key( $provider ), 0, 40 ),
					'created_at' => Util::now(),
				)
			);
		} else {
			// A stronger reason replaces a weaker one (an unsubscribed address that bounces is now bounced).
			$rank = array(
				'unsubscribed' => 1,
				'soft'         => 2,
				'complained'   => 3,
				'bounced'      => 4,
				'manual'       => 5,
			);
			$old  = isset( $rank[ $exists ] ) ? $rank[ $exists ] : 0;
			if ( $rank[ $reason ] > $old ) {
				$wpdb->update(
					$table,
					array(
						'reason'   => $reason,
						'provider' => substr( sanitize_key( $provider ), 0, 40 ),
					),
					array( 'email_hash' => $hash )
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * Wraps a body in a simple, readable email layout.
	 *
	 * @param string $html Body HTML.
	 * @return string
	 */
	private function layout( $html ) {
		$site = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$out  = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#0e1116">';
		$out .= '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;font-size:15px;line-height:1.55">' . wp_kses_post( $html ) . '</div>';
		$out .= '<p style="max-width:600px;margin:14px auto 0;font-size:12px;color:#5b6370;text-align:center">' . $site . '</p>';
		$out .= '</body></html>';

		/**
		 * Filters the full HTML of emails sent by Aluleth plugins.
		 *
		 * @param string $out  Full HTML.
		 * @param string $html Body HTML.
		 */
		return (string) apply_filters( 'aluleth/mail/html', $out, $html );
	}
}
