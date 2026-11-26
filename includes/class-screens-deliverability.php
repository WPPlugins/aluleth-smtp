<?php
/**
 * Deliverability tab: will your emails reach the inbox?
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Deliverability screen.
 */
final class Screens_Deliverability {

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		$domains = Deliverability::domains();
		if ( ! $domains ) {
			echo '<div class="als-head"><div><h1>' . esc_html__( 'Will your emails reach the inbox?', 'aluleth-smtp' ) . '</h1></div></div>';
			echo '<section class="als-card als-empty"><p>' . esc_html__( 'Add a connection first: the checks look at the domain of its From address.', 'aluleth-smtp' ) . '</p></section>';
			return;
		}
		$domain = strtolower( Screens::get( 'domain' ) );
		if ( ! isset( $domains[ $domain ] ) ) {
			$domain = (string) key( $domains );
		}
		$result = Deliverability::last( $domain );
		if ( ! $result ) {
			// Nothing is checked or saved by opening the page: the button runs the first check.
			echo '<div class="als-head"><div><h1>' . esc_html__( 'Will your emails reach the inbox?', 'aluleth-smtp' ) . '</h1></div></div>';
			echo '<section class="als-card als-empty"><p>' . esc_html__( 'Check your sending domain against the rules of Gmail, Yahoo and Microsoft: SPF, DKIM, DMARC and their alignment, the MX records, the From address and your bounce and spam report rates.', 'aluleth-smtp' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-search als-inline"><input type="hidden" name="action" value="alsmtp_check_domain">';
			wp_nonce_field( 'alsmtp_check_domain' );
			if ( count( $domains ) > 1 ) {
				echo '<label class="screen-reader-text" for="als-domain">' . esc_html__( 'Sending domain', 'aluleth-smtp' ) . '</label><select id="als-domain" name="domain">';
				foreach ( array_keys( $domains ) as $d ) {
					echo '<option value="' . esc_attr( $d ) . '"' . selected( $domain, $d, false ) . '>' . esc_html( $d ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="hidden" name="domain" value="' . esc_attr( $domain ) . '">';
			}
			/* translators: %s: domain. */
			echo '<button type="submit" class="als-btn is-accent">' . esc_html( count( $domains ) > 1 ? __( 'Check now', 'aluleth-smtp' ) : sprintf( __( 'Check %s now', 'aluleth-smtp' ), $domain ) ) . '</button></form></section>';
			return;
		}

		echo '<div class="als-head"><div><h1>' . esc_html__( 'Will your emails reach the inbox?', 'aluleth-smtp' ) . '</h1>';
		/* translators: 1: domain, 2: date and time. */
		echo '<p class="als-sub">' . esc_html( sprintf( __( 'Checked against the rules of Gmail, Yahoo and Microsoft for %1$s · %2$s', 'aluleth-smtp' ), $domain, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['time'] ) ) ) . '</p></div>';
		echo '<div class="als-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-search als-inline"><input type="hidden" name="action" value="alsmtp_check_domain">';
		wp_nonce_field( 'alsmtp_check_domain' );
		if ( count( $domains ) > 1 ) {
			echo '<label class="screen-reader-text" for="als-domain">' . esc_html__( 'Sending domain', 'aluleth-smtp' ) . '</label><select id="als-domain" name="domain">';
			foreach ( array_keys( $domains ) as $d ) {
				echo '<option value="' . esc_attr( $d ) . '"' . selected( $domain, $d, false ) . '>' . esc_html( $d ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="domain" value="' . esc_attr( $domain ) . '">';
		}
		echo '<button type="submit" class="als-btn">' . esc_html__( 'Check again', 'aluleth-smtp' ) . '</button></form></div></div>';

		echo '<div class="als-tiles">';
		foreach ( $result['inboxes'] as $inbox => $score ) {
			list( $pass, $count, $todo ) = $score;
			$pct                         = $count ? round( $pass * 100 / $count ) : 0;
			if ( ! $count ) {
				$note = __( 'Not enough could be checked from here.', 'aluleth-smtp' );
			} elseif ( $pass === $count ) {
				$note = __( 'Everything it looks at passes.', 'aluleth-smtp' );
			} else {
				/* translators: %s: name of a check. */
				$note = sprintf( __( 'To improve: %s', 'aluleth-smtp' ), $todo );
			}
			echo '<div class="als-tile als-inbox"><span class="als-inbox-head"><strong>' . esc_html( $inbox ) . '</strong><span class="als-mono">' . esc_html( $count ? $pass . ' / ' . $count : '–' ) . '</span></span>';
			echo '<span class="als-meter-bar" role="img" aria-label="' . esc_attr( $count ? $pass . ' / ' . $count : '–' ) . '"><i style="width:' . esc_attr( (string) $pct ) . '%"></i></span><span>' . esc_html( $note ) . '</span></div>';
		}
		echo '</div>';

		echo '<div class="als-cols"><div class="als-main"><section class="als-card is-flush als-checklist">';
		$fix = null;
		foreach ( $result['checks'] as $c ) {
			$icon = array(
				'ok'      => array( 'is-ok', __( 'Passes', 'aluleth-smtp' ) ),
				'warn'    => array( 'is-warn', __( 'To improve', 'aluleth-smtp' ) ),
				'bad'     => array( 'is-bad', __( 'Problem', 'aluleth-smtp' ) ),
				'unknown' => array( 'is-unknown', __( 'Not checked', 'aluleth-smtp' ) ),
			);
			$i    = isset( $icon[ $c['state'] ] ) ? $icon[ $c['state'] ] : $icon['unknown'];
			if ( null === $fix && in_array( $c['state'], array( 'bad', 'warn' ), true ) && '' !== $c['code'] ) {
				$fix = $c;
			}
			echo '<div class="als-check ' . esc_attr( $i[0] ) . '"><span class="als-check-icon" aria-hidden="true"></span><div><p class="als-check-title"><strong>' . esc_html( $c['title'] ) . '</strong> <span class="screen-reader-text">' . esc_html( $i[1] ) . '</span><span class="als-help">' . esc_html( $c['who'] ) . '</span></p><p>' . esc_html( $c['text'] ) . '</p>';
			if ( '' !== $c['code'] ) {
				echo '<code class="als-code">' . esc_html( $c['code'] ) . '</code>';
			}
			echo '</div></div>';
		}
		echo '</section></div><aside class="als-side">';

		if ( $fix ) {
			/* translators: %s: name of a check. */
			echo '<section class="als-card is-strong"><h2>' . esc_html( sprintf( __( 'Fix first: %s', 'aluleth-smtp' ), $fix['title'] ) ) . '</h2><p>' . esc_html( $fix['text'] ) . '</p><code class="als-code" id="als-fix-code">' . esc_html( $fix['code'] ) . '</code>';
			echo '<p><button type="button" class="als-btn is-accent" data-alsmtp-copy="als-fix-code">' . esc_html__( 'Copy the value', 'aluleth-smtp' ) . '</button></p>';
			echo '<p class="als-help">' . esc_html__( 'DNS changes can take a few hours to show. Then click “Check again”.', 'aluleth-smtp' ) . '</p></section>';
		}

		$n = count( Connections::all() );
		echo '<section class="als-card"><h2>' . esc_html__( 'Send a check email', 'aluleth-smtp' ) . '</h2><p class="als-help">' . esc_html__( 'One email through each connection, to your own Gmail or Outlook address. Open it, choose “Show original” (Gmail) or “View source”, and look for SPF, DKIM and DMARC: PASS.', 'aluleth-smtp' ) . '</p>';
		Screens::form_start( 'send_checks' );
		echo '<label class="als-field">' . esc_html__( 'Send to', 'aluleth-smtp' ) . '<input type="email" name="to" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"></label>';
		/* translators: %d: number of connections. */
		echo '<button type="submit" class="als-btn">' . esc_html( sprintf( _n( 'Send through %d connection', 'Send through all %d connections', $n, 'aluleth-smtp' ), $n ) ) . '</button></form></section>';
		echo '</aside></div>';
	}
}
