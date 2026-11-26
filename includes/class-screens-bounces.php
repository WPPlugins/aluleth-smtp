<?php
/**
 * Bounces tab: blocked addresses, the rules after a bounce, where events come from.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Bounces screen.
 */
final class Screens_Bounces {

	/**
	 * Renders the tab.
	 *
	 * @return void
	 */
	public static function render() {
		$counts = Blocklist::counts();
		$reason = sanitize_key( Screens::get( 'reason' ) );
		$search = Screens::get( 's' );
		$page   = max( 1, absint( Screens::get( 'paged' ) ) );
		$res    = Blocklist::query(
			array(
				'reason'   => $reason,
				'search'   => $search,
				'page'     => $page,
				'per_page' => 50,
			)
		);

		echo '<div class="als-head"><div><h1>' . esc_html__( 'Bounces and blocked addresses', 'aluleth-smtp' ) . '</h1><p class="als-sub">' . esc_html__( 'One list for the whole site: Aluleth Forms, Popups and the other Aluleth plugins skip these addresses too.', 'aluleth-smtp' ) . '</p></div></div>';

		$tiles = array(
			array( __( 'Blocked now', 'aluleth-smtp' ), $counts['all'], __( 'checked before each email', 'aluleth-smtp' ) ),
			array( __( 'Hard bounces', 'aluleth-smtp' ), $counts['bounced'], __( 'the address does not exist', 'aluleth-smtp' ) ),
			array( __( 'Spam reports', 'aluleth-smtp' ), $counts['complained'], __( 'marketing stopped at once', 'aluleth-smtp' ) ),
			array( __( 'Unsubscribed', 'aluleth-smtp' ), $counts['unsubscribed'], __( 'no marketing emails', 'aluleth-smtp' ) ),
		);
		if ( $counts['soft'] || $counts['manual'] ) {
			$tiles[] = array( __( 'Soft bounces and by hand', 'aluleth-smtp' ), $counts['soft'] + $counts['manual'], __( 'too many soft bounces, or blocked by you', 'aluleth-smtp' ) );
		}
		echo '<div class="als-tiles">';
		foreach ( $tiles as $t ) {
			echo '<div class="als-tile"><span class="als-label">' . esc_html( $t[0] ) . '</span><strong>' . esc_html( number_format_i18n( $t[1] ) ) . '</strong><span>' . esc_html( $t[2] ) . '</span></div>';
		}
		echo '</div>';

		echo '<div class="als-cols"><div class="als-main">';
		echo '<section class="als-card is-flush"><div class="als-card-head"><h2>' . esc_html__( 'Blocked addresses', 'aluleth-smtp' ) . '</h2><div class="als-row-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-search als-inline"><input type="hidden" name="action" value="alsmtp_block_address">';
		wp_nonce_field( 'alsmtp_block_address' );
		echo '<label class="screen-reader-text" for="als-block-email">' . esc_html__( 'Address to block', 'aluleth-smtp' ) . '</label><input id="als-block-email" type="email" name="email" required placeholder="' . esc_attr__( 'name@example.com', 'aluleth-smtp' ) . '"><button type="submit" class="als-btn">' . esc_html__( 'Block an address', 'aluleth-smtp' ) . '</button></form>';
		Screens::button_form( 'export_blocked', array(), __( 'Export CSV', 'aluleth-smtp' ) );
		echo '</div></div>';

		echo '<form method="get" class="als-search als-pad"><input type="hidden" name="page" value="' . esc_attr( Admin::PAGE ) . '"><input type="hidden" name="tab" value="bounces">';
		echo '<label class="screen-reader-text" for="als-bounce-s">' . esc_html__( 'Search an address', 'aluleth-smtp' ) . '</label> <input id="als-bounce-s" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search an address', 'aluleth-smtp' ) . '">';
		echo '<label class="screen-reader-text" for="als-bounce-reason">' . esc_html__( 'Why', 'aluleth-smtp' ) . '</label><select id="als-bounce-reason" name="reason"><option value="">' . esc_html__( 'Every reason', 'aluleth-smtp' ) . '</option>';
		foreach ( array( 'bounced', 'soft', 'complained', 'unsubscribed', 'manual' ) as $r ) {
			echo '<option value="' . esc_attr( $r ) . '"' . selected( $reason, $r, false ) . '>' . esc_html( Blocklist::reason_label( $r ) ) . '</option>';
		}
		echo '</select> <button type="submit" class="als-btn">' . esc_html__( 'Filter', 'aluleth-smtp' ) . '</button></form>';

		if ( ! $res['rows'] ) {
			echo '<p class="als-pad">' . esc_html( '' !== $search || '' !== $reason ? __( 'No address matches.', 'aluleth-smtp' ) : __( 'No blocked address. When a service reports a bounce or a spam report, the address appears here and stops getting emails it should not get.', 'aluleth-smtp' ) ) . '</p>';
		} else {
			echo '<div class="als-scroll"><table class="als-table"><thead><tr><th scope="col">' . esc_html__( 'Address', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Why', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'What they still get', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Since', 'aluleth-smtp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Action', 'aluleth-smtp' ) . '</span></th></tr></thead><tbody>';
			foreach ( $res['rows'] as $r ) {
				$style   = in_array( $r->reason, array( 'bounced', 'manual' ), true ) ? 'bad' : ( 'complained' === $r->reason ? 'dark' : ( 'soft' === $r->reason ? 'warn' : '' ) );
				$contact = function_exists( 'aluleth_core' ) ? aluleth_core()->contacts()->get_by_email( $r->email ) : null;
				$name    = $contact ? trim( (string) ( isset( $contact->first_name ) ? $contact->first_name : '' ) . ' ' . (string) ( isset( $contact->last_name ) ? $contact->last_name : '' ) ) : '';
				echo '<tr><td><strong>' . esc_html( $r->email ) . '</strong>' . ( $contact ? '<br><span class="als-help">' . esc_html( '' !== $name ? $name : __( 'Contact', 'aluleth-smtp' ) ) . '</span>' : '' ) . '</td>';
				echo wp_kses( '<td>' . Screens::chip( Blocklist::reason_label( $r->reason ), $style ) . ( '' !== $r->detail ? '<br><span class="als-mono als-help">' . esc_html( $r->detail ) . '</span>' : '' ) . ( '' !== $r->provider ? '<br><span class="als-help">' . esc_html( self::source_label( $r->provider ) ) . '</span>' : '' ) . '</td>', Screens::allowed_html() );
				echo '<td>' . esc_html( Blocklist::still_gets( $r->reason ) ) . '</td>';
				echo '<td class="als-mono">' . esc_html( Screens::when( $r->created_at, get_option( 'date_format' ) ) ) . '</td><td class="num">';
				Screens::button_form( 'allow_address', array( 'email' => $r->email ), __( 'Allow again', 'aluleth-smtp' ), 'als-btn is-small', ' data-alsmtp-confirm="' . esc_attr__( 'Send emails to this address again?', 'aluleth-smtp' ) . '"' );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			$pages = (int) ceil( $res['total'] / 50 );
			if ( $pages > 1 ) {
				echo '<nav class="als-pages als-pad" aria-label="' . esc_attr__( 'Pages', 'aluleth-smtp' ) . '">';
				for ( $i = 1; $i <= min( $pages, 20 ); $i++ ) {
					$url = Admin::url(
						array_filter(
							array(
								'tab'    => 'bounces',
								'reason' => $reason,
								's'      => $search,
								'paged'  => $i,
							)
						)
					);
					echo '<a href="' . esc_url( $url ) . '"' . ( $i === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $i ) . '</a>';
				}
				echo '</nav>';
			}
		}
		echo '</section></div>';

		echo '<aside class="als-side">';
		self::rules();
		self::sources();
		echo '</aside></div>';
	}

	/**
	 * Name of where a block came from.
	 *
	 * @param string $provider Connection id, "manual" or "import".
	 * @return string
	 */
	private static function source_label( $provider ) {
		if ( 'manual' === $provider ) {
			return __( 'Blocked by hand', 'aluleth-smtp' );
		}
		if ( 'import' === $provider ) {
			return __( 'Imported', 'aluleth-smtp' );
		}
		$c = Connections::get( $provider );
		return $c ? $c['label'] : $provider;
	}

	/**
	 * The rules after a bounce.
	 *
	 * @return void
	 */
	private static function rules() {
		$s = Connections::settings();
		echo '<section class="als-card"><h2>' . esc_html__( 'What happens after a bounce', 'aluleth-smtp' ) . '</h2>';
		Screens::form_start( 'save_bounce_rules' );
		$selects = array(
			'hard_policy' => array(
				__( 'Address does not exist (hard bounce)', 'aluleth-smtp' ),
				array(
					'except_transactional' => __( 'Block, except passwords and orders', 'aluleth-smtp' ),
					'all'                  => __( 'Block everything', 'aluleth-smtp' ),
					'mark'                 => __( 'Only show it here', 'aluleth-smtp' ),
				),
			),
			'soft_policy' => array(
				__( 'Mailbox full or server down (soft bounce)', 'aluleth-smtp' ),
				array(
					'3_15'  => __( 'Block marketing after 3 in 15 days', 'aluleth-smtp' ),
					'5_30'  => __( 'Block marketing after 5 in 30 days', 'aluleth-smtp' ),
					'never' => __( 'Never block', 'aluleth-smtp' ),
				),
			),
			'spam_policy' => array(
				__( 'Marked as spam', 'aluleth-smtp' ),
				array(
					'marketing' => __( 'Stop all marketing at once', 'aluleth-smtp' ),
					'all'       => __( 'Block everything', 'aluleth-smtp' ),
				),
			),
		);
		foreach ( $selects as $key => $sel ) {
			echo '<fieldset class="als-field als-radios"><legend>' . esc_html( $sel[0] ) . '</legend>';
			foreach ( $sel[1] as $v => $l ) {
				echo '<label><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $v ) . '"' . checked( $s[ $key ], $v, false ) . '> ' . esc_html( $l ) . '</label>';
			}
			echo '</fieldset>';
		}
		echo '<p class="als-help">' . esc_html__( 'Unsubscribed addresses never get marketing emails. When the address is a contact, its page in Aluleth › Contacts shows the bounce or the spam report.', 'aluleth-smtp' ) . '</p>';
		echo '<div class="als-form-actions"><button type="submit" class="als-btn is-accent">' . esc_html__( 'Save the rules', 'aluleth-smtp' ) . '</button></div></form></section>';
	}

	/**
	 * Where bounces come from: the webhook of each connection.
	 *
	 * @return void
	 */
	private static function sources() {
		echo '<section class="als-card"><h2>' . esc_html__( 'Where bounces come from', 'aluleth-smtp' ) . '</h2>';
		$all = Connections::all();
		if ( ! $all ) {
			echo '<p class="als-help">' . esc_html__( 'Add a connection first.', 'aluleth-smtp' ) . '</p></section>';
			return;
		}
		echo '<ul class="als-hooks">';
		foreach ( $all as $id => $c ) {
			$state = Hooks::state( $c );
			$h     = Hooks::get( $id );
			switch ( $state ) {
				case 'working':
					/* translators: %s: time ago. */
					$detail = sprintf( __( 'Last event %s ago', 'aluleth-smtp' ), human_time_diff( $h['last'] ) );
					$chip   = Screens::chip( __( 'Working', 'aluleth-smtp' ), 'ok' );
					break;
				case 'waiting':
					$detail = __( 'Set up, no event yet', 'aluleth-smtp' );
					$chip   = Screens::chip( __( 'Waiting', 'aluleth-smtp' ), 'warn' );
					break;
				case 'off':
					$detail = __( 'Not set up yet', 'aluleth-smtp' );
					$chip   = Screens::chip( __( 'To set up', 'aluleth-smtp' ), 'warn' );
					break;
				default:
					$detail = __( 'This service sends no events', 'aluleth-smtp' );
					$chip   = Screens::chip( __( 'None', 'aluleth-smtp' ) );
			}
			echo wp_kses( '<li><span><strong>' . esc_html( $c['label'] ) . '</strong><span class="als-help">' . esc_html( $detail ) . '</span></span>' . $chip, Screens::allowed_html() );
			if ( 'none' !== $state ) {
				echo ' <a href="' . esc_url(
					Admin::url(
						array(
							'tab'  => 'connections',
							'edit' => $id,
						)
					) . '#als-events'
				) . '">' . esc_html( 'working' === $state ? __( 'Settings', 'aluleth-smtp' ) : __( 'Set up', 'aluleth-smtp' ) ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul></section>';
	}
	/**
	 * Bounces and spam reports of one connection (on its edit screen).
	 *
	 * @param array $c Connection.
	 * @return void
	 */
	public static function connection_events( array $c ) {
		$p     = Connections::provider( $c['provider'] );
		$state = Hooks::state( $c );
		echo '<section class="als-card als-form-card" id="als-events"><div class="als-card-head"><h2>' . esc_html__( 'Bounces and spam reports', 'aluleth-smtp' ) . '</h2>';
		if ( 'none' === $state ) {
			echo '</div><p class="als-help">' . esc_html( $p->no_events_text() ) . '</p></section>';
			return;
		}
		$h      = Hooks::get( $c['id'] );
		$labels = array(
			'working' => array( __( 'Working', 'aluleth-smtp' ), 'ok' ),
			'waiting' => array( __( 'Waiting for the first event', 'aluleth-smtp' ), 'warn' ),
			'off'     => array( __( 'Not set up', 'aluleth-smtp' ), 'warn' ),
		);
		echo wp_kses( Screens::chip( $labels[ $state ][0], $labels[ $state ][1] ) . '</div>', Screens::allowed_html() );
		/* translators: %s: service name. */
		echo '<p class="als-help">' . esc_html( sprintf( __( '%s tells this site which emails were delivered, which bounced and who marked one as spam. Bounced addresses are then blocked by the rules in Bounces, and the log shows what happened to each email.', 'aluleth-smtp' ), $p->label() ) ) . '</p>';
		if ( 'working' === $state ) {
			/* translators: %s: time ago. */
			echo '<p>' . esc_html( sprintf( __( 'Last event received %s ago.', 'aluleth-smtp' ), human_time_diff( $h['last'] ) ) ) . '</p>';
		}
		if ( 'auto' === $p->hook_support() ) {
			echo '<div class="als-form-actions is-start">';
			/* translators: %s: service name. */
			Screens::button_form( 'hook_setup', array( 'id' => $c['id'] ), $h['auto'] ? __( 'Set it up again', 'aluleth-smtp' ) : sprintf( __( 'Set it up in %s for me', 'aluleth-smtp' ), $p->label() ), $h['auto'] ? 'als-btn' : 'als-btn is-accent' );
			echo '</div>';
		}
		$hook_url = Hooks::url( $c['id'] );
		if ( '' === $hook_url ) {
			echo '<p class="als-help">' . esc_html__( 'Save the connection once more to get its event address.', 'aluleth-smtp' ) . '</p>';
		} else {
			echo '<label class="als-field">' . esc_html__( 'Address for the service (secret: anyone with it can send events)', 'aluleth-smtp' ) . '<span class="als-copy"><input type="text" readonly id="als-hook-url" value="' . esc_attr( $hook_url ) . '"><button type="button" class="als-btn" data-alsmtp-copy="als-hook-url">' . esc_html__( 'Copy', 'aluleth-smtp' ) . '</button></span></label>';
		}
		echo '<p class="als-help">' . esc_html( $p->hook_help() ) . '</p>';
		$secret = $p->hook_secret();
		if ( $secret ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-form"><input type="hidden" name="action" value="alsmtp_hook_secret"><input type="hidden" name="id" value="' . esc_attr( $c['id'] ) . '">';
			wp_nonce_field( 'alsmtp_hook_secret' );
			/* translators: %s: last characters of the saved key. */
			$ph = '' !== $h['secret'] ? sprintf( __( 'Saved (%s). Leave empty to keep it.', 'aluleth-smtp' ), $h['hint'] ) : '';
			echo '<label class="als-field">' . esc_html( $secret['label'] ) . '<input type="password" name="secret" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $ph ) . '"><small>' . esc_html( $secret['help'] ) . ' ' . esc_html__( 'Paste it here yourself. It is stored encrypted.', 'aluleth-smtp' ) . '</small></label>';
			echo '<div class="als-form-actions is-start"><button type="submit" class="als-btn">' . esc_html__( 'Save the signing key', 'aluleth-smtp' ) . '</button>';
			if ( '' !== $h['secret'] ) {
				echo '<button type="submit" name="clear" value="1" class="als-btn is-danger">' . esc_html__( 'Remove it', 'aluleth-smtp' ) . '</button>';
			}
			echo '</div></form>';
		}
		echo '<div class="als-form-actions is-start">';
		if ( 'off' === $state ) {
			Screens::button_form( 'hook_mark', array( 'id' => $c['id'] ), __( 'I added the address', 'aluleth-smtp' ) );
		}
		Screens::button_form( 'hook_renew', array( 'id' => $c['id'] ), __( 'Make a new address', 'aluleth-smtp' ), 'als-btn', ' data-alsmtp-confirm="' . esc_attr__( 'The current address will stop working. Continue?', 'aluleth-smtp' ) . '"' );
		echo '</div></section>';
	}
}
