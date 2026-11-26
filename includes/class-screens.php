<?php
/**
 * Screens of Aluleth › Email sending.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Screens.
 */
final class Screens {

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private static function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, Admin::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * A GET value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function get( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The page.
	 *
	 * @return void
	 */
	public static function page() {
		if ( ! current_user_can( Admin::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'aluleth-smtp' ) );
		}
		$tab    = self::tab();
		$labels = array(
			'overview'       => __( 'Overview', 'aluleth-smtp' ),
			'connections'    => __( 'Connections', 'aluleth-smtp' ),
			'routing'        => __( 'Routing and queue', 'aluleth-smtp' ),
			'log'            => __( 'Email log', 'aluleth-smtp' ),
			'deliverability' => __( 'Deliverability', 'aluleth-smtp' ),
			'bounces'        => __( 'Bounces', 'aluleth-smtp' ),
			'settings'       => __( 'Log and alerts', 'aluleth-smtp' ),
		);
		echo '<div class="wrap al-wrap als">';
		echo '<nav class="als-tabs" aria-label="' . esc_attr__( 'Email sending', 'aluleth-smtp' ) . '">';
		foreach ( $labels as $key => $label ) {
			echo '<a href="' . esc_url( Admin::url( array( 'tab' => $key ) ) ) . '"' . ( $key === $tab ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		$notice = Admin::take_notice();
		if ( $notice ) {
			$class = 'success' === $notice[0] ? 'is-ok' : ( 'warning' === $notice[0] ? 'is-warn' : 'is-error' );
			echo '<div class="als-notice ' . esc_attr( $class ) . '" role="status"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
		echo '<hr class="wp-header-end">';
		if ( in_array( $tab, array( 'overview', 'connections' ), true ) ) {
			self::conflict_notice();
		}
		if ( 'bounces' === $tab ) {
			Screens_Bounces::render();
		} elseif ( 'deliverability' === $tab ) {
			Screens_Deliverability::render();
		} else {
			call_user_func( array( __CLASS__, $tab ) );
		}
		echo '</div>';
	}

	/**
	 * HTML allowed in the markup of these screens (escaped again when printed).
	 *
	 * @return array
	 */
	public static function allowed_html() {
		static $allowed = null;
		if ( null !== $allowed ) {
			return $allowed;
		}
		$common = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'hidden'           => true,
			'tabindex'         => true,
			'aria-label'       => true,
			'aria-hidden'      => true,
			'aria-current'     => true,
			'aria-describedby' => true,
			'aria-live'        => true,
			'data-*'           => true,
		);
		$fields = array(
			'form'     => array( 'method', 'action', 'enctype', 'target' ),
			'input'    => array( 'type', 'name', 'value', 'checked', 'disabled', 'placeholder', 'min', 'max', 'step', 'size', 'maxlength', 'readonly', 'required', 'autocomplete', 'form', 'spellcheck' ),
			'select'   => array( 'name', 'multiple', 'disabled', 'required', 'form' ),
			'option'   => array( 'value', 'selected', 'disabled' ),
			'optgroup' => array( 'label', 'disabled' ),
			'button'   => array( 'type', 'name', 'value', 'disabled', 'form' ),
			'label'    => array( 'for' ),
			'textarea' => array( 'name', 'rows', 'cols', 'placeholder', 'readonly', 'required', 'disabled', 'spellcheck' ),
			'fieldset' => array(),
			'legend'   => array(),
			'details'  => array( 'open' ),
			'summary'  => array(),
			// Small charts drawn as SVG.
			'svg'      => array( 'viewbox', 'width', 'height', 'fill', 'stroke', 'xmlns', 'focusable', 'preserveaspectratio' ),
			'g'        => array( 'fill', 'stroke', 'transform', 'opacity' ),
			'path'     => array( 'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'transform' ),
			'rect'     => array( 'x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity' ),
			'circle'   => array( 'cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'opacity' ),
			'line'     => array( 'x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-linecap', 'opacity' ),
			'polyline' => array( 'points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin' ),
			'polygon'  => array( 'points', 'fill', 'stroke', 'stroke-width' ),
			'text'     => array( 'x', 'y', 'dx', 'dy', 'fill', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline' ),
			'tspan'    => array( 'x', 'y', 'dx', 'dy', 'fill' ),
			'title'    => array(),
		);
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( $fields as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( isset( $allowed[ $tag ] ) ? $allowed[ $tag ] : array(), array_fill_keys( $attrs, true ) );
		}
		foreach ( $allowed as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $common );
		}
		return $allowed;
	}

	/**
	 * A chip.
	 *
	 * @param string $text  Text.
	 * @param string $style Style: ok, warn, bad, dark or nothing.
	 * @return string
	 */
	public static function chip( $text, $style = '' ) {
		return '<span class="als-chip' . ( '' !== $style ? ' is-' . esc_attr( $style ) : '' ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Status of a log row for people.
	 *
	 * @param object $r Row.
	 * @return string Chip HTML.
	 */
	public static function status_chip( $r ) {
		$outcome = isset( $r->outcome ) ? (string) $r->outcome : '';
		if ( 'sent' === $r->status && '' !== $outcome ) {
			$labels = array(
				'delivered'  => array( __( 'Delivered', 'aluleth-smtp' ), 'ok' ),
				'bounced'    => array( __( 'Bounced', 'aluleth-smtp' ), 'bad' ),
				'complained' => array( __( 'Spam report', 'aluleth-smtp' ), 'dark' ),
				'rejected'   => array( __( 'Rejected', 'aluleth-smtp' ), 'bad' ),
			);
			if ( isset( $labels[ $outcome ] ) ) {
				return self::chip( $labels[ $outcome ][0], $labels[ $outcome ][1] );
			}
		}
		switch ( $r->status ) {
			case 'sent':
				return self::chip( (int) $r->fallback ? __( 'Sent by fallback', 'aluleth-smtp' ) : __( 'Sent', 'aluleth-smtp' ), 'ok' );
			case 'failed':
				return self::chip( __( 'Failed', 'aluleth-smtp' ), 'bad' );
			case 'blocked':
				return self::chip( __( 'Not sent', 'aluleth-smtp' ), 'dark' );
			default:
				return self::chip( (int) $r->attempts > 0 ? __( 'Will try again', 'aluleth-smtp' ) : __( 'Waiting', 'aluleth-smtp' ), 'warn' );
		}
	}

	/**
	 * Date for people, in the site's time zone.
	 *
	 * @param string $utc UTC date time.
	 * @param string $format Format.
	 * @return string
	 */
	public static function when( $utc, $format = '' ) {
		$t = strtotime( $utc . ' UTC' );
		if ( ! $t ) {
			return '';
		}
		if ( '' === $format ) {
			$format = wp_date( 'Y-m-d' ) === wp_date( 'Y-m-d', $t ) ? get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}
		return wp_date( $format, $t );
	}

	/**
	 * Name of a connection.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function connection_label( $id ) {
		$c = Connections::get( $id );
		return $c ? $c['label'] : ( '' !== $id ? $id : '–' );
	}

	/**
	 * Hidden fields of an action form.
	 *
	 * @param string $action Action.
	 * @return void
	 */
	public static function form_start( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-form">';
		echo '<input type="hidden" name="action" value="alsmtp_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'alsmtp_' . $action );
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action Action.
	 * @param array  $fields Hidden fields.
	 * @param string $label  Button text.
	 * @param string $style  Button class.
	 * @param string $extra  Extra attributes for the button.
	 * @return void
	 */
	public static function button_form( $action, array $fields, $label, $style = 'als-btn', $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="als-inline">';
		echo '<input type="hidden" name="action" value="alsmtp_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'alsmtp_' . $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo wp_kses( '<button type="submit" class="' . esc_attr( $style ) . '"' . $extra . '>' . esc_html( $label ) . '</button></form>', self::allowed_html() );
	}

	/**
	 * Overview.
	 *
	 * @return void
	 */
	private static function overview() {
		$all   = Connections::all();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$n     = Log::counts( $since );
		$q     = Queue::status();
		echo '<div class="als-head"><div><h1>' . esc_html__( 'Email sending', 'aluleth-smtp' ) . '</h1>';
		if ( ! $all ) {
			echo '<p class="als-sub">' . esc_html__( 'Your emails still go out the default way (PHP mail). Many of them end up in spam or are never delivered.', 'aluleth-smtp' ) . '</p></div></div>';
			echo '<section class="als-card als-empty"><h2>' . esc_html__( 'Choose the service that sends your emails', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Amazon SES, SendGrid, Brevo, Mailgun, Postmark, Resend and more, or any SMTP server. Every service is free in Aluleth SMTP.', 'aluleth-smtp' ) . '</p><p><a class="als-btn is-accent" href="' . esc_url(
				Admin::url(
					array(
						'tab' => 'connections',
						'add' => 1,
					)
				)
			) . '">' . esc_html__( 'Add a connection', 'aluleth-smtp' ) . '</a></p></section>';
			return;
		}
		$fail_line = $n['failed'] > 0 ? self::chip( __( 'Some emails failed', 'aluleth-smtp' ), 'bad' ) : self::chip( __( 'All emails are going out', 'aluleth-smtp' ), 'ok' );
		/* translators: 1: number of connections, 2: number of emails waiting. */
		echo wp_kses( '<p class="als-sub">' . $fail_line . ' ' . esc_html( sprintf( _n( '%1$d connection · %2$d waiting in the queue · last 30 days', '%1$d connections · %2$d waiting in the queue · last 30 days', count( $all ), 'aluleth-smtp' ), count( $all ), $q['waiting'] ) ) . '</p></div>', self::allowed_html() );
		echo '<div class="als-actions"><a class="als-btn is-accent" href="' . esc_url(
			Admin::url(
				array(
					'tab' => 'connections',
					'add' => 1,
				)
			)
		) . '">' . esc_html__( 'Add a connection', 'aluleth-smtp' ) . '</a></div></div>';

		$total = $n['sent'] + $n['failed'];
		$tiles = array(
			array( __( 'Sent', 'aluleth-smtp' ), number_format_i18n( $n['sent'] ), __( 'accepted by your services', 'aluleth-smtp' ) ),
			array( __( 'Delivery rate', 'aluleth-smtp' ), $total ? number_format_i18n( $n['sent'] * 100 / $total, 1 ) . '%' : '–', __( 'sent out of all tried', 'aluleth-smtp' ) ),
			array( __( 'Saved by fallback', 'aluleth-smtp' ), number_format_i18n( $n['fallback'] ), __( 'the first connection failed', 'aluleth-smtp' ) ),
			array( __( 'Failed', 'aluleth-smtp' ), number_format_i18n( $n['failed'] ), __( 'after every try', 'aluleth-smtp' ) ),
			array( __( 'Waiting', 'aluleth-smtp' ), number_format_i18n( $q['waiting'] ), __( 'in the queue now', 'aluleth-smtp' ) ),
		);
		if ( $n['delivered'] + $n['problem'] > 0 ) {
			// The services report events: real deliveries, bounces and spam reports.
			$tiles[1] = array( __( 'Delivered', 'aluleth-smtp' ), number_format_i18n( $n['delivered'] ), __( 'confirmed by your services', 'aluleth-smtp' ) );
			array_splice(
				$tiles,
				4,
				0,
				array(
					array( __( 'Bounced', 'aluleth-smtp' ), number_format_i18n( $n['bounced'] + $n['rejected'] ), __( 'refused by the inbox', 'aluleth-smtp' ) ),
					array( __( 'Spam reports', 'aluleth-smtp' ), number_format_i18n( $n['complained'] ), __( 'marked as spam', 'aluleth-smtp' ) ),
				)
			);
		}
		echo '<div class="als-tiles">';
		foreach ( $tiles as $t ) {
			echo '<div class="als-tile"><span class="als-label">' . esc_html( $t[0] ) . '</span><strong>' . esc_html( $t[1] ) . '</strong><span>' . esc_html( $t[2] ) . '</span></div>';
		}
		echo '</div>';

		echo '<div class="als-cols"><div class="als-main">';
		self::connection_cards( $all );

		$days = Log::per_day( 30 );
		$max  = 1;
		foreach ( $days as $d ) {
			$max = max( $max, $d[0] + $d[1] );
		}
		echo '<section class="als-card"><div class="als-card-head"><h2>' . esc_html__( 'Emails per day', 'aluleth-smtp' ) . '</h2><span class="als-legend"><i class="is-ok"></i>' . esc_html__( 'Sent', 'aluleth-smtp' ) . ' <i class="is-bad"></i>' . esc_html__( 'Failed', 'aluleth-smtp' ) . '</span></div>';
		echo '<div class="als-bars" role="img" aria-label="' . esc_attr__( 'Emails sent and failed per day, last 30 days', 'aluleth-smtp' ) . '">';
		foreach ( $days as $day => $d ) {
			/* translators: 1: date, 2: sent, 3: failed. */
			$title = sprintf( __( '%1$s: %2$d sent, %3$d failed', 'aluleth-smtp' ), wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00' ) ), $d[0], $d[1] );
			echo '<span title="' . esc_attr( $title ) . '"><i class="is-bad" style="height:' . esc_attr( (string) round( $d[1] * 100 / $max, 2 ) ) . '%"></i><i class="is-ok" style="height:' . esc_attr( (string) round( $d[0] * 100 / $max, 2 ) ) . '%"></i></span>';
		}
		echo '</div><div class="als-bars-foot"><span>' . esc_html( wp_date( get_option( 'date_format' ), time() - 29 * DAY_IN_SECONDS ) ) . '</span><span>' . esc_html__( 'Today', 'aluleth-smtp' ) . '</span></div></section>';

		$sources = Log::per_source( $since );
		echo '<section class="als-card is-flush"><div class="als-card-head"><h2>' . esc_html__( 'Who sends the emails', 'aluleth-smtp' ) . '</h2></div>';
		if ( ! $sources ) {
			echo '<p class="als-pad">' . esc_html__( 'No email yet.', 'aluleth-smtp' ) . '</p>';
		} else {
			echo '<div class="als-scroll"><table class="als-table"><thead><tr><th scope="col">' . esc_html__( 'Source', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Kind', 'aluleth-smtp' ) . '</th><th scope="col" class="num">' . esc_html__( 'Sent', 'aluleth-smtp' ) . '</th><th scope="col" class="num">' . esc_html__( 'Failed', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
			foreach ( $sources as $src ) {
				echo '<tr><td><a href="' . esc_url(
					Admin::url(
						array(
							'tab'    => 'log',
							'source' => $src['source'],
						)
					)
				) . '"><strong>' . esc_html( Router::source_label( $src['source'] ) ) . '</strong></a></td><td>' . esc_html( 'marketing' === $src['kind'] ? __( 'Marketing', 'aluleth-smtp' ) : __( 'Transactional', 'aluleth-smtp' ) ) . '</td><td class="num">' . esc_html( number_format_i18n( (int) $src['sent'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( (int) $src['failed'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section></div><aside class="als-side">';
		if ( $n['failed'] > 0 ) {
			echo '<section class="als-card"><h2>' . esc_html__( 'Needs a look', 'aluleth-smtp' ) . '</h2><a class="als-row-link" href="' . esc_url(
				Admin::url(
					array(
						'tab'    => 'log',
						'status' => 'failed',
					)
				)
			) . '"><span>' . esc_html(
				/* translators: %d: number of emails. */
				sprintf( _n( '%d email failed in the last 30 days', '%d emails failed in the last 30 days', $n['failed'], 'aluleth-smtp' ), $n['failed'] )
			) . '</span><strong>' . esc_html__( 'Open', 'aluleth-smtp' ) . '</strong></a></section>';
		}
		self::test_box( 'overview' );
		$s        = Connections::settings();
		$channels = array();
		if ( $s['alert_email'] ) {
			$channels[] = __( 'Email', 'aluleth-smtp' );
		}
		foreach ( array(
			'slack'          => 'Slack',
			'discord'        => 'Discord',
			'telegram_token' => 'Telegram',
		) as $key => $name ) {
			if ( '' !== $s[ $key ] ) {
				$channels[] = $name;
			}
		}
		echo '<section class="als-card"><h2>' . esc_html__( 'Alerts when sending fails', 'aluleth-smtp' ) . '</h2><p>' . esc_html( $channels ? implode( ', ', $channels ) : __( 'No alert set up.', 'aluleth-smtp' ) ) . ' · ' . esc_html__( 'at most one every 15 minutes', 'aluleth-smtp' ) . '</p><a href="' . esc_url( Admin::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Change the alerts', 'aluleth-smtp' ) . '</a></section>';
		echo '</aside></div>';
	}

	/**
	 * Cards of the connections.
	 *
	 * @param array $all Connections.
	 * @return void
	 */
	private static function connection_cards( array $all ) {
		$s = Connections::settings();
		echo '<section class="als-card"><div class="als-card-head"><h2>' . esc_html__( 'Connections', 'aluleth-smtp' ) . '</h2><a href="' . esc_url( Admin::url( array( 'tab' => 'routing' ) ) ) . '">' . esc_html__( 'Which email goes where', 'aluleth-smtp' ) . '</a></div><div class="als-conns">';
		foreach ( $all as $id => $c ) {
			$p    = Connections::provider( $c['provider'] );
			$role = Connections::default_id() === $id ? __( 'Main', 'aluleth-smtp' ) : ( $s['fallback'] === $id ? __( 'Fallback', 'aluleth-smtp' ) : __( 'Through rules', 'aluleth-smtp' ) );
			if ( 'error' === $c['check_state'] ) {
				$state = self::chip( __( 'Problem', 'aluleth-smtp' ), 'bad' );
			} elseif ( 'warning' === $c['check_state'] ) {
				$state = self::chip( __( 'Check this', 'aluleth-smtp' ), 'warn' );
			} elseif ( 'ok' === $c['check_state'] ) {
				$state = self::chip( __( 'Working', 'aluleth-smtp' ), 'ok' );
			} else {
				$state = self::chip( __( 'Not checked', 'aluleth-smtp' ) );
			}
			$use = Limiter::usage( $c );
			echo wp_kses( '<div class="als-conn"><div class="als-conn-head"><span class="als-logo" aria-hidden="true">' . esc_html( strtoupper( substr( $p->label(), 0, 2 ) ) ) . '</span><span><strong>' . esc_html( $c['label'] ) . '</strong><small>' . esc_html( $p->label() . ' · ' . $role ) . '</small></span>' . $state . '</div>', self::allowed_html() );
			echo '<span class="als-conn-from">' . esc_html( $c['from_email'] ) . '</span>';
			if ( '' !== $c['check_note'] && 'ok' !== $c['check_state'] ) {
				echo '<span class="als-conn-note">' . esc_html( $c['check_note'] ) . '</span>';
			}
			if ( $use['limit'] > 0 ) {
				/* translators: 1: used, 2: limit, 3: unit (second, minute…). */
				echo '<span class="als-mono">' . esc_html( sprintf( __( '%1$d / %2$d per %3$s', 'aluleth-smtp' ), $use['used'], $use['limit'], self::unit( $use['unit'] ) ) ) . '</span>';
			} else {
				echo '<span class="als-mono">' . esc_html__( 'no sending limit set', 'aluleth-smtp' ) . '</span>';
			}
			echo '</div>';
		}
		echo '</div></section>';
	}

	/**
	 * Name of a limit unit.
	 *
	 * @param string $unit Unit.
	 * @return string
	 */
	public static function unit( $unit ) {
		$names = array(
			'second' => __( 'second', 'aluleth-smtp' ),
			'minute' => __( 'minute', 'aluleth-smtp' ),
			'hour'   => __( 'hour', 'aluleth-smtp' ),
			'day'    => __( 'day', 'aluleth-smtp' ),
		);
		return isset( $names[ $unit ] ) ? $names[ $unit ] : $unit;
	}

	/**
	 * Test email box.
	 *
	 * @param string $back Tab to come back to.
	 * @return void
	 */
	private static function test_box( $back ) {
		$all = Connections::all();
		if ( ! $all ) {
			return;
		}
		$user = wp_get_current_user();
		echo '<section class="als-card"><h2>' . esc_html__( 'Send a test email', 'aluleth-smtp' ) . '</h2>';
		self::form_start( 'test' );
		echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		echo '<label class="als-field">' . esc_html__( 'Send to', 'aluleth-smtp' ) . '<input type="email" name="to" required value="' . esc_attr( $user ? $user->user_email : '' ) . '"></label>';
		echo '<label class="als-field">' . esc_html__( 'Through', 'aluleth-smtp' ) . '<select name="connection">';
		foreach ( $all as $id => $c ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( Connections::default_id(), $id, false ) . '>' . esc_html( $c['label'] ) . '</option>';
		}
		echo '</select></label><button type="submit" class="als-btn">' . esc_html__( 'Send the test email', 'aluleth-smtp' ) . '</button></form></section>';
	}

	/**
	 * Connections: list, or the form to add or edit one.
	 *
	 * @return void
	 */
	private static function connections() {
		$edit = sanitize_key( self::get( 'edit' ) );
		if ( '' !== $edit && Connections::get( $edit ) ) {
			self::connection_form( Connections::get( $edit ) );
			Screens_Bounces::connection_events( Connections::get( $edit ) );
			return;
		}
		if ( '' !== self::get( 'add' ) ) {
			$provider = Connections::provider( sanitize_key( self::get( 'provider' ) ) );
			if ( $provider ) {
				self::connection_form( null, $provider );
			} else {
				self::provider_picker();
			}
			return;
		}
		$all = Connections::all();
		echo '<div class="als-head"><div><h1>' . esc_html__( 'Connections', 'aluleth-smtp' ) . '</h1><p class="als-sub">' . esc_html__( 'Each connection is an account at an email service. Add a second one as a fallback: it takes over when the first one fails.', 'aluleth-smtp' ) . '</p></div><div class="als-actions"><a class="als-btn is-accent" href="' . esc_url(
			Admin::url(
				array(
					'tab' => 'connections',
					'add' => 1,
				)
			)
		) . '">' . esc_html__( 'Add a connection', 'aluleth-smtp' ) . '</a></div></div>';
		self::import_banner();
		if ( ! $all ) {
			echo '<section class="als-card als-empty"><p>' . esc_html__( 'No connection yet.', 'aluleth-smtp' ) . '</p></section>';
			return;
		}
		echo '<section class="als-card is-flush"><div class="als-scroll"><table class="als-table"><thead><tr><th scope="col">' . esc_html__( 'Connection', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'From', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Use', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Last check', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Bounces', 'aluleth-smtp' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'aluleth-smtp' ) . '</span></th></tr></thead><tbody>';
		$s = Connections::settings();
		foreach ( $all as $id => $c ) {
			$p    = Connections::provider( $c['provider'] );
			$role = Connections::default_id() === $id ? __( 'Main', 'aluleth-smtp' ) : ( $s['fallback'] === $id ? __( 'Fallback', 'aluleth-smtp' ) : __( 'Through rules', 'aluleth-smtp' ) );
			$bad  = Connections::unreadable( $c );
			echo '<tr><td><strong>' . esc_html( $c['label'] ) . '</strong><br><small>' . esc_html( $p->label() ) . '</small></td>';
			echo '<td>' . esc_html( ( '' !== $c['from_name'] ? $c['from_name'] . ' ' : '' ) . '<' . $c['from_email'] . '>' ) . '</td>';
			echo '<td>' . esc_html( $role ) . '</td><td>';
			if ( $bad ) {
				echo wp_kses( self::chip( __( 'Paste the key again', 'aluleth-smtp' ), 'bad' ), self::allowed_html() );
			} elseif ( $c['checked'] ) {
				echo wp_kses( self::chip( 'ok' === $c['check_state'] ? __( 'Working', 'aluleth-smtp' ) : ( 'warning' === $c['check_state'] ? __( 'Check this', 'aluleth-smtp' ) : __( 'Problem', 'aluleth-smtp' ) ), 'ok' === $c['check_state'] ? 'ok' : ( 'warning' === $c['check_state'] ? 'warn' : 'bad' ) ), self::allowed_html() );
				echo '<br><small>' . esc_html( $c['check_note'] ) . ' · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $c['checked'] ) ) . '</small>';
			} else {
				echo wp_kses( self::chip( __( 'Not checked', 'aluleth-smtp' ) ), self::allowed_html() );
			}
			$hook = Hooks::state( $c );
			echo '</td><td>';
			if ( 'none' === $hook ) {
				echo '<small>' . esc_html__( 'Not reported', 'aluleth-smtp' ) . '</small>';
			} else {
				$hooks = array(
					'working' => array( __( 'Reported', 'aluleth-smtp' ), 'ok' ),
					'waiting' => array( __( 'Waiting', 'aluleth-smtp' ), 'warn' ),
					'off'     => array( __( 'To set up', 'aluleth-smtp' ), 'warn' ),
				);
				echo '<a href="' . esc_url(
					Admin::url(
						array(
							'tab'  => 'connections',
							'edit' => $id,
						)
					) . '#als-events'
				) . '">' . wp_kses( self::chip( $hooks[ $hook ][0], $hooks[ $hook ][1] ), self::allowed_html() ) . '</a>';
			}
			echo '</td><td class="als-row-actions"><a class="als-btn is-small" href="' . esc_url(
				Admin::url(
					array(
						'tab'  => 'connections',
						'edit' => $id,
					)
				)
			) . '">' . esc_html__( 'Edit', 'aluleth-smtp' ) . '</a>';
			self::button_form( 'check_connection', array( 'id' => $id ), __( 'Check', 'aluleth-smtp' ), 'als-btn is-small' );
			self::button_form( 'delete_connection', array( 'id' => $id ), __( 'Delete', 'aluleth-smtp' ), 'als-btn is-small is-danger', ' data-alsmtp-confirm' );
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
		self::test_box( 'connections' );
	}

	/**
	 * Steps to make the app at Google or Microsoft, with the redirect address to copy.
	 *
	 * @param Provider_Oauth $provider Service.
	 * @param bool           $saved    Whether the connection exists.
	 * @return void
	 */
	private static function oauth_steps( $provider, $saved ) {
		echo '<details class="als-steps-box"' . ( $saved ? '' : ' open' ) . '><summary>' . esc_html__( 'Make the app (once, about 5 minutes)', 'aluleth-smtp' ) . '</summary><ol>';
		foreach ( $provider->setup_steps() as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol>';
		echo '<label class="als-field">' . esc_html__( 'Redirect URI to enter in the app', 'aluleth-smtp' ) . '<span class="als-copy"><input type="text" readonly id="als-redirect" value="' . esc_attr( Provider_Oauth::redirect_uri() ) . '"><button type="button" class="als-btn" data-alsmtp-copy="als-redirect">' . esc_html__( 'Copy', 'aluleth-smtp' ) . '</button></span></label>';
		if ( false !== strpos( Provider_Oauth::redirect_uri(), '?' ) ) {
			echo '<p class="als-help is-warn">' . esc_html__( 'Your permalinks are set to “Plain”: Google and Microsoft may refuse this address. Choose any other permalink structure in Settings › Permalinks.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * Sign-in state and button of a Google or Microsoft connection.
	 *
	 * @param array $c Connection.
	 * @return void
	 */
	private static function oauth_card( array $c ) {
		$p = Connections::provider( $c['provider'] );
		if ( ! $p || ! $p->is_oauth() ) {
			return;
		}
		$signed  = ! empty( $c['secrets']['refresh_token'] );
		$account = isset( $c['settings']['account'] ) ? (string) $c['settings']['account'] : '';
		echo wp_kses( '<section class="als-card als-oauth"><div class="als-card-head"><h2>' . esc_html( $p->label() ) . '</h2>' . ( $signed ? self::chip( __( 'Signed in', 'aluleth-smtp' ), 'ok' ) : self::chip( __( 'Not signed in', 'aluleth-smtp' ), 'warn' ) ) . '</div>', self::allowed_html() );
		if ( $signed ) {
			$who = '' !== $account ? $account : __( 'your account', 'aluleth-smtp' );
			/* translators: 1: email address, 2: date. */
			echo '<p>' . esc_html( sprintf( __( 'Sends as %1$s (signed in on %2$s).', 'aluleth-smtp' ), $who, ! empty( $c['settings']['signed_in'] ) ? wp_date( get_option( 'date_format' ), (int) $c['settings']['signed_in'] ) : '–' ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Paste the client ID and secret of your app below and save, then sign in with the account that sends the emails.', 'aluleth-smtp' ) . '</p>';
		}
		echo '<div class="als-form-actions is-start">';
		self::button_form( 'oauth_start', array( 'id' => $c['id'] ), $signed ? __( 'Sign in again', 'aluleth-smtp' ) : $p->connect_label(), $signed ? 'als-btn' : 'als-btn is-accent' );
		if ( $signed ) {
			self::button_form( 'oauth_disconnect', array( 'id' => $c['id'] ), __( 'Sign out', 'aluleth-smtp' ), 'als-btn is-danger', ' data-alsmtp-confirm="' . esc_attr__( 'Emails will no longer go through this connection. Sign out?', 'aluleth-smtp' ) . '"' );
		}
		echo '</div></section>';
	}

	/**
	 * Settings of another SMTP plugin that can be imported.
	 *
	 * @return void
	 */
	private static function import_banner() {
		$found   = Migrate::found();
		$sources = Migrate::sources();
		foreach ( $found as $source => $list ) {
			$names = array();
			foreach ( $list as $item ) {
				$p       = Connections::provider( $item['provider'] );
				$names[] = $p ? $p->label() : $item['provider'];
			}
			/* translators: 1: plugin name, 2: services. */
			echo '<section class="als-card als-banner"><p><strong>' . esc_html( sprintf( __( '%1$s settings found: %2$s.', 'aluleth-smtp' ), $sources[ $source ][0], implode( ', ', array_unique( $names ) ) ) ) . '</strong> ' . esc_html__( 'Import them: the keys come along, and each connection is checked with its service.', 'aluleth-smtp' ) . '</p>';
			/* translators: %s: plugin name. */
			self::button_form( 'import', array( 'source' => $source ), sprintf( __( 'Import from %s', 'aluleth-smtp' ), $sources[ $source ][0] ), 'als-btn is-accent' );
			echo '</section>';
		}
	}

	/**
	 * Other plugins that also take over wp_mail().
	 *
	 * @return string[] Names.
	 */
	public static function conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$known = array(
			'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
			'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
			'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
			'post-smtp/postman-smtp.php'        => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		);
		$out   = array();
		foreach ( $known as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Warning when another SMTP plugin is active.
	 *
	 * @return void
	 */
	public static function conflict_notice() {
		$names = self::conflicts();
		if ( $names ) {
			/* translators: %s: plugin names. */
			echo '<div class="als-notice is-warn" role="status"><p>' . esc_html( sprintf( __( 'Also active: %s. Two plugins that send emails get in each other’s way, and some send every email themselves. Import its settings (Connections), then deactivate it.', 'aluleth-smtp' ), implode( ', ', $names ) ) ) . '</p></div>';
		}
	}

	/**
	 * Step 1: choose the service.
	 *
	 * @return void
	 */
	private static function provider_picker() {
		echo '<div class="als-head"><div><span class="als-label">' . esc_html__( 'Step 1 of 2', 'aluleth-smtp' ) . '</span><h1>' . esc_html__( 'Which service sends your emails?', 'aluleth-smtp' ) . '</h1><p class="als-sub">' . esc_html__( 'All of them are free in Aluleth SMTP. You need an account with the service.', 'aluleth-smtp' ) . '</p></div></div>';
		echo '<div class="als-providers">';
		foreach ( Connections::providers() as $p ) {
			echo '<a class="als-provider" href="' . esc_url(
				Admin::url(
					array(
						'tab'      => 'connections',
						'add'      => 1,
						'provider' => $p->id(),
					)
				)
			) . '"><strong>' . esc_html( $p->label() ) . '</strong><span>' . esc_html( $p->note() ) . '</span></a>';
		}
		echo '</div>';
	}

	/**
	 * Step 2: the settings of a connection.
	 *
	 * @param array|null    $c        Existing connection.
	 * @param Provider|null $provider Service for a new connection.
	 * @return void
	 */
	private static function connection_form( $c, $provider = null ) {
		$provider = $c ? Connections::provider( $c['provider'] ) : $provider;
		$values   = $c ? $c['settings'] : array();
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';
		$s        = Connections::settings();
		$first    = ! Connections::all();
		echo '<div class="als-head"><div>' . ( $c ? '' : '<span class="als-label">' . esc_html__( 'Step 2 of 2', 'aluleth-smtp' ) . '</span>' );
		/* translators: %s: service name. */
		echo '<h1>' . esc_html( $c ? sprintf( __( 'Edit %s', 'aluleth-smtp' ), $c['label'] ) : sprintf( __( 'Connect %s', 'aluleth-smtp' ), $provider->label() ) ) . '</h1></div>';
		if ( '' !== $provider->docs() ) {
			echo '<div class="als-actions"><a href="' . esc_url( $provider->docs() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Where do I find these?', 'aluleth-smtp' ) . '</a></div>';
		}
		echo '</div>';
		if ( $c ) {
			self::oauth_card( $c );
		}
		echo '<section class="als-card als-form-card">';
		self::form_start( 'save_connection' );
		echo '<input type="hidden" name="id" value="' . esc_attr( $c ? $c['id'] : '' ) . '"><input type="hidden" name="provider" value="' . esc_attr( $provider->id() ) . '">';
		if ( '' !== $provider->help() ) {
			echo '<p class="als-help">' . esc_html( $provider->help() ) . '</p>';
		}
		if ( $provider->is_oauth() ) {
			self::oauth_steps( $provider, (bool) $c );
		}
		echo '<div class="als-grid">';
		foreach ( $provider->fields() as $key => $f ) {
			$name = 'f[' . $key . ']';
			$req  = ! empty( $f['required'] );
			echo '<label class="als-field' . ( 'password' === $f['type'] ? ' is-wide' : '' ) . '">' . esc_html( $f['label'] ) . ( $req ? ' <span class="als-req" aria-hidden="true">*</span>' : '' );
			if ( 'select' === $f['type'] ) {
				echo '<select name="' . esc_attr( $name ) . '">';
				foreach ( $f['options'] as $ov => $ol ) {
					echo '<option value="' . esc_attr( $ov ) . '"' . selected( isset( $values[ $key ] ) ? $values[ $key ] : '', $ov, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				echo '</select>';
			} elseif ( 'password' === $f['type'] ) {
				$const = Connections::constant( $c ? $c['id'] : Connections::next_id( $provider->id() ), $key );
				$saved = $c && ! empty( $c['secrets'][ $key ] );
				$hint  = $saved && ! empty( $c['hints'][ $key ] ) ? $c['hints'][ $key ] : '';
				/* translators: %s: last characters of the saved key. */
				$ph = $saved ? sprintf( __( 'Saved (%s). Leave empty to keep it.', 'aluleth-smtp' ), $hint ) : '';
				if ( defined( $const ) ) {
					$ph    = __( 'Set in wp-config.php', 'aluleth-smtp' );
					$saved = true;
				}
				echo '<input type="password" name="' . esc_attr( $name ) . '" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $ph ) . '"' . ( $req && ! $saved ? ' required' : '' ) . '>';
				if ( defined( $const ) ) {
					/* translators: %s: name of a PHP constant. */
					echo '<small>' . esc_html( sprintf( __( 'The constant %s in wp-config.php is used.', 'aluleth-smtp' ), $const ) ) . '</small>';
				} else {
					echo '<small>' . esc_html__( 'Paste it here yourself. It is stored encrypted and never shown again.', 'aluleth-smtp' ) . ' ';
					/* translators: %s: PHP code to add to wp-config.php. */
					echo esc_html( sprintf( __( 'Or keep it out of the database with %s in wp-config.php.', 'aluleth-smtp' ), "define( '" . $const . "', '…' );" ) ) . '</small>';
				}
			} else {
				echo '<input type="' . esc_attr( 'number' === $f['type'] ? 'number' : 'text' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( isset( $values[ $key ] ) ? $values[ $key ] : '' ) . '" placeholder="' . esc_attr( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ) . '"' . ( $req ? ' required' : '' ) . ' spellcheck="false">';
			}
			if ( ! empty( $f['help'] ) ) {
				echo '<small>' . esc_html( $f['help'] ) . '</small>';
			}
			echo '</label>';
		}
		echo '</div><div class="als-grid">';
		echo '<label class="als-field">' . esc_html__( 'From email', 'aluleth-smtp' ) . ' <span class="als-req" aria-hidden="true">*</span><input type="email" name="from_email" required value="' . esc_attr( $c ? $c['from_email'] : ( '' !== $host ? 'hello@' . $host : '' ) ) . '"><small>' . esc_html( $provider->from_help() ) . '</small></label>';
		echo '<label class="als-field">' . esc_html__( 'From name', 'aluleth-smtp' ) . '<input type="text" name="from_name" value="' . esc_attr( $c ? $c['from_name'] : $site ) . '"></label>';
		echo '</div><div class="als-checks">';
		echo '<label><input type="checkbox" name="force_from" value="1"' . checked( $c ? $c['force_from'] : true, true, false ) . '> ' . esc_html__( 'Use this From email for every email (recommended: other addresses would fail the checks of Gmail and Outlook)', 'aluleth-smtp' ) . '</label>';
		echo '<label><input type="checkbox" name="force_name" value="1"' . checked( $c ? $c['force_name'] : false, true, false ) . '> ' . esc_html__( 'Use this From name for every email (otherwise each plugin keeps its own)', 'aluleth-smtp' ) . '</label>';
		echo '</div>';
		$role = $c ? $c['role'] : ( $first ? 'main' : ( '' === $s['fallback'] ? 'fallback' : 'rules' ) );
		echo '<fieldset class="als-roles"><legend>' . esc_html__( 'Use this connection', 'aluleth-smtp' ) . '</legend>';
		foreach ( array(
			'main'     => array( __( 'For every email', 'aluleth-smtp' ), __( 'unless a routing rule says otherwise', 'aluleth-smtp' ) ),
			'fallback' => array( __( 'As a fallback', 'aluleth-smtp' ), __( 'when another connection fails', 'aluleth-smtp' ) ),
			'rules'    => array( __( 'Only through routing rules', 'aluleth-smtp' ), __( 'for example for marketing emails', 'aluleth-smtp' ) ),
		) as $val => $txt ) {
			echo '<label><input type="radio" name="role" value="' . esc_attr( $val ) . '"' . checked( $role, $val, false ) . '> <span><strong>' . esc_html( $txt[0] ) . '</strong> ' . esc_html( $txt[1] ) . '</span></label>';
		}
		echo '</fieldset>';
		echo '<div class="als-limit"><span class="als-field-label">' . esc_html__( 'Sending limit (optional)', 'aluleth-smtp' ) . '</span><div><label><span class="screen-reader-text">' . esc_html__( 'Number of emails', 'aluleth-smtp' ) . '</span><input type="number" min="0" max="100000" name="limit_n" value="' . esc_attr( $c && $c['limit_n'] ? (string) $c['limit_n'] : '' ) . '" placeholder="0"></label> <label><span class="screen-reader-text">' . esc_html__( 'Per', 'aluleth-smtp' ) . '</span><select name="limit_unit">';
		foreach ( array_keys( Connections::UNITS ) as $u ) {
			/* translators: %s: unit. */
			echo '<option value="' . esc_attr( $u ) . '"' . selected( $c ? $c['limit_unit'] : 'minute', $u, false ) . '>' . esc_html( sprintf( __( 'per %s', 'aluleth-smtp' ), self::unit( $u ) ) ) . '</option>';
		}
		echo '</select></label></div><small>' . esc_html__( 'Your plan’s limit at the service. Above it, emails wait in the queue instead of being refused. Empty or 0: no limit.', 'aluleth-smtp' ) . '</small></div>';
		echo '<label class="als-field">' . esc_html__( 'Name in Aluleth (optional)', 'aluleth-smtp' ) . '<input type="text" name="label" value="' . esc_attr( $c ? $c['label'] : '' ) . '" placeholder="' . esc_attr( $provider->label() ) . '"></label>';
		echo '<div class="als-form-actions"><a class="als-btn" href="' . esc_url( Admin::url( array( 'tab' => 'connections' ) ) ) . '">' . esc_html__( 'Cancel', 'aluleth-smtp' ) . '</a><button type="submit" class="als-btn is-accent">' . esc_html__( 'Save and check', 'aluleth-smtp' ) . '</button></div>';
		echo '</form></section>';
	}

	/**
	 * Routing and queue.
	 *
	 * @return void
	 */
	private static function routing() {
		$all = Connections::all();
		$s   = Connections::settings();
		$q   = Queue::status();
		echo '<div class="als-head"><div><h1>' . esc_html__( 'Which email goes where', 'aluleth-smtp' ) . '</h1><p class="als-sub">' . esc_html__( 'Rules are read from the top. The first one that matches decides. Emails no rule matches use the main connection.', 'aluleth-smtp' ) . '</p></div></div>';
		if ( ! $all ) {
			echo '<section class="als-card als-empty"><p><a href="' . esc_url(
				Admin::url(
					array(
						'tab' => 'connections',
						'add' => 1,
					)
				)
			) . '">' . esc_html__( 'Add a connection first.', 'aluleth-smtp' ) . '</a></p></section>';
			return;
		}
		$options = static function ( $current, $none ) use ( $all ) {
			$html = '' !== $none ? '<option value="">' . esc_html( $none ) . '</option>' : '';
			foreach ( $all as $id => $c ) {
				$html .= '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $c['label'] ) . '</option>';
			}
			return $html;
		};
		$sources = self::sources();
		echo '<div class="als-cols"><div class="als-main">';
		self::form_start( 'save_routing' );
		echo '<section class="als-card"><div class="als-grid">';
		echo wp_kses( '<label class="als-field">' . esc_html__( 'Main connection', 'aluleth-smtp' ) . '<select name="default">' . $options( Connections::default_id(), '' ) . '</select></label>', self::allowed_html() );
		echo wp_kses( '<label class="als-field">' . esc_html__( 'Fallback', 'aluleth-smtp' ) . '<select name="fallback">' . $options( $s['fallback'], __( 'No fallback', 'aluleth-smtp' ) ) . '</select></label>', self::allowed_html() );
		echo '</div></section>';
		echo '<section class="als-card"><div class="als-card-head"><h2>' . esc_html__( 'Rules', 'aluleth-smtp' ) . '</h2><button type="button" class="als-btn is-small" data-alsmtp-add-rule>' . esc_html__( 'Add a rule', 'aluleth-smtp' ) . '</button></div>';
		echo '<div class="als-rules" data-alsmtp-rules>';
		$rules = $s['rules'];
		foreach ( $rules as $i => $r ) {
			self::rule_row( $i, $r, $sources, $options );
		}
		echo '</div><template data-alsmtp-rule-template>';
		self::rule_row(
			'__i__',
			array(
				'name'       => '',
				'source'     => '',
				'kind'       => '',
				'from'       => '',
				'connection' => Connections::default_id(),
				'fallback'   => '',
			),
			$sources,
			$options
		);
		echo '</template>';
		if ( ! $rules ) {
			echo '<p class="als-help" data-alsmtp-no-rules>' . esc_html__( 'No rule: every email uses the main connection, then the fallback. Add a rule to send, for example, marketing emails through another service.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</section>';
		echo '<section class="als-card"><h2>' . esc_html__( 'Queue', 'aluleth-smtp' ) . '</h2><div class="als-checks">';
		echo '<label><input type="checkbox" name="queue_marketing" value="1"' . checked( $s['queue_marketing'], true, false ) . '> ' . esc_html__( 'Send marketing emails in the background (recommended: visitors never wait, limits are kept)', 'aluleth-smtp' ) . '</label>';
		echo '<label><input type="checkbox" name="paused" value="1"' . checked( $s['paused'], true, false ) . '> ' . esc_html__( 'Pause the queue (emails sent right away still go out)', 'aluleth-smtp' ) . '</label>';
		echo '</div></section><div class="als-form-actions"><button type="submit" class="als-btn is-accent">' . esc_html__( 'Save routing', 'aluleth-smtp' ) . '</button></div></form></div>';

		echo '<aside class="als-side"><section class="als-card"><h2>' . esc_html__( 'Queue now', 'aluleth-smtp' ) . '</h2>';
		/* translators: %d: number of emails. */
		echo '<p class="als-big">' . esc_html( number_format_i18n( $q['waiting'] ) ) . ' <span>' . esc_html( _n( 'email waiting', 'emails waiting', $q['waiting'], 'aluleth-smtp' ) ) . '</span></p>';
		if ( $q['retrying'] ) {
			/* translators: %d: number of emails. */
			echo '<p>' . esc_html( sprintf( _n( '%d will be tried again.', '%d will be tried again.', $q['retrying'], 'aluleth-smtp' ), $q['retrying'] ) ) . '</p>';
		}
		if ( $s['paused'] ) {
			echo wp_kses( '<p>' . self::chip( __( 'Paused', 'aluleth-smtp' ), 'warn' ) . '</p>', self::allowed_html() );
		}
		foreach ( $all as $c ) {
			$use = Limiter::usage( $c );
			if ( $use['limit'] < 1 ) {
				continue;
			}
			$pct = min( 100, round( $use['used'] * 100 / $use['limit'] ) );
			/* translators: 1: used, 2: limit, 3: unit (second, minute…). */
			echo '<div class="als-meter"><span><strong>' . esc_html( $c['label'] ) . '</strong> <span class="als-mono">' . esc_html( sprintf( __( '%1$d / %2$d per %3$s', 'aluleth-smtp' ), $use['used'], $use['limit'], self::unit( $use['unit'] ) ) ) . '</span></span><span class="als-meter-bar"><i style="width:' . esc_attr( (string) $pct ) . '%"></i></span></div>';
		}
		if ( $q['due'] > 0 ) {
			self::button_form( 'run_queue', array(), __( 'Send what is due now', 'aluleth-smtp' ) );
		}
		echo '</section>';
		echo '<section class="als-card"><h2>' . esc_html__( 'How emails leave', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Order and password emails go out at once. Queued emails go out right after the page has been sent to the visitor, and every minute with WordPress’s scheduler. For big lists, a real server cron keeps the pace steady:', 'aluleth-smtp' ) . '</p><code class="als-code">* * * * * wp aluleth-smtp queue --path=' . esc_html( untrailingslashit( ABSPATH ) ) . '</code></section>';
		echo '</aside></div>';
	}

	/**
	 * Sources to choose in a rule.
	 *
	 * @return array slug => name
	 */
	private static function sources() {
		global $wpdb;
		$out = array( 'wordpress' => 'WordPress' );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_option( 'active_plugins', array() ) as $file ) {
			$slug = sanitize_key( strtok( $file, '/' ) );
			if ( 'aluleth-smtp' !== $slug ) {
				$out[ $slug ] = Router::source_label( $slug );
			}
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i LIMIT 50', Installer::table() ) ) as $slug ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( '' !== $slug && 'test' !== $slug && ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = Router::source_label( $slug );
			}
		}
		asort( $out );
		return $out;
	}

	/**
	 * One rule row.
	 *
	 * @param int|string $i       Index.
	 * @param array      $r       Rule.
	 * @param array      $sources Sources.
	 * @param callable   $options Connection options builder.
	 * @return void
	 */
	private static function rule_row( $i, array $r, array $sources, $options ) {
		$n = 'rules[' . $i . ']';
		echo '<div class="als-rule" data-alsmtp-rule><span class="als-rule-num" aria-hidden="true"></span><div class="als-rule-fields">';
		echo '<label class="als-field">' . esc_html__( 'Rule name', 'aluleth-smtp' ) . '<input type="text" name="' . esc_attr( $n . '[name]' ) . '" value="' . esc_attr( $r['name'] ) . '" placeholder="' . esc_attr__( 'Campaigns', 'aluleth-smtp' ) . '"></label>';
		echo '<label class="als-field">' . esc_html__( 'Sent by', 'aluleth-smtp' ) . '<select name="' . esc_attr( $n . '[source]' ) . '"><option value="">' . esc_html__( 'Any plugin', 'aluleth-smtp' ) . '</option>';
		foreach ( $sources as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $r['source'], $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="als-field">' . esc_html__( 'Kind of email', 'aluleth-smtp' ) . '<select name="' . esc_attr( $n . '[kind]' ) . '"><option value="">' . esc_html__( 'Any', 'aluleth-smtp' ) . '</option><option value="marketing"' . selected( $r['kind'], 'marketing', false ) . '>' . esc_html__( 'Marketing (newsletter, campaign)', 'aluleth-smtp' ) . '</option><option value="transactional"' . selected( $r['kind'], 'transactional', false ) . '>' . esc_html__( 'Transactional (order, account, form)', 'aluleth-smtp' ) . '</option></select></label>';
		echo '<label class="als-field">' . esc_html__( 'From email contains', 'aluleth-smtp' ) . '<input type="text" name="' . esc_attr( $n . '[from]' ) . '" value="' . esc_attr( $r['from'] ) . '" placeholder="news@"></label>';
		echo wp_kses( '<label class="als-field">' . esc_html__( 'Send with', 'aluleth-smtp' ) . '<select name="' . esc_attr( $n . '[connection]' ) . '">' . $options( $r['connection'], '' ) . '</select></label>', self::allowed_html() );
		echo wp_kses( '<label class="als-field">' . esc_html__( 'If it fails', 'aluleth-smtp' ) . '<select name="' . esc_attr( $n . '[fallback]' ) . '">' . $options( $r['fallback'], __( 'The usual fallback', 'aluleth-smtp' ) ) . '</select></label>', self::allowed_html() );
		echo '</div><button type="button" class="als-btn is-small" data-alsmtp-remove-rule>' . esc_html__( 'Remove', 'aluleth-smtp' ) . '</button></div>';
	}

	/**
	 * Email log: list or one email.
	 *
	 * @return void
	 */
	private static function log() {
		$mail = absint( self::get( 'mail' ) );
		if ( $mail ) {
			$row = Log::get( $mail );
			if ( $row ) {
				self::mail( $row );
				return;
			}
		}
		$status = sanitize_key( self::get( 'status' ) );
		$source = sanitize_key( self::get( 'source' ) );
		$search = self::get( 's' );
		$page   = max( 1, absint( self::get( 'paged' ) ) );
		$res    = Log::query(
			array(
				'status'   => $status,
				'source'   => $source,
				'search'   => $search,
				'page'     => $page,
				'per_page' => 50,
			)
		);
		$s      = Connections::settings();
		echo '<div class="als-head"><div><h1>' . esc_html__( 'Email log', 'aluleth-smtp' ) . '</h1></div><div class="als-actions">';
		echo '<form method="get" class="als-search"><input type="hidden" name="page" value="' . esc_attr( Admin::PAGE ) . '"><input type="hidden" name="tab" value="log"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Search the log', 'aluleth-smtp' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Email address or subject', 'aluleth-smtp' ) . '"></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Source', 'aluleth-smtp' ) . '</span><select name="source"><option value="">' . esc_html__( 'All sources', 'aluleth-smtp' ) . '</option>';
		foreach ( self::sources() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $source, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><button type="submit" class="als-btn">' . esc_html__( 'Filter', 'aluleth-smtp' ) . '</button></form>';
		self::button_form(
			'export',
			array(
				'status' => $status,
				'source' => $source,
				's'      => $search,
			),
			__( 'Export CSV', 'aluleth-smtp' )
		);
		echo '</div></div>';
		$n       = Log::counts( '1970-01-01 00:00:00' );
		$filters = array(
			''        => array( __( 'All', 'aluleth-smtp' ), $n['sent'] + $n['failed'] + $n['waiting'] + $n['blocked'] ),
			'sent'    => array( __( 'Sent', 'aluleth-smtp' ), $n['sent'] ),
			'waiting' => array( __( 'Waiting', 'aluleth-smtp' ), $n['waiting'] ),
			'failed'  => array( __( 'Failed', 'aluleth-smtp' ), $n['failed'] ),
			'bounced' => array( __( 'Bounced', 'aluleth-smtp' ), $n['problem'] ),
			'blocked' => array( __( 'Not sent', 'aluleth-smtp' ), $n['blocked'] ),
		);
		echo '<div class="als-filters">';
		foreach ( $filters as $key => $f ) {
			echo '<a href="' . esc_url(
				Admin::url(
					array_filter(
						array(
							'tab'    => 'log',
							'status' => $key,
							'source' => $source,
							's'      => $search,
						)
					)
				)
			) . '"' . ( $key === $status ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( $f[0] ) . ' <span>' . esc_html( number_format_i18n( $f[1] ) ) . '</span></a>';
		}
		echo '</div>';
		echo '<section class="als-card is-flush">';
		if ( ! $res['rows'] ) {
			echo '<p class="als-pad">' . esc_html__( 'No email here.', 'aluleth-smtp' ) . '</p>';
		} else {
			echo '<div class="als-scroll"><table class="als-table"><thead><tr><th scope="col">' . esc_html__( 'Status', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'To and subject', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Source', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html__( 'Via', 'aluleth-smtp' ) . '</th><th scope="col" class="num">' . esc_html__( 'When', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
			foreach ( $res['rows'] as $r ) {
				echo wp_kses( '<tr><td>' . self::status_chip( $r ) . '</td>', self::allowed_html() );
				echo '<td><a href="' . esc_url(
					Admin::url(
						array(
							'tab'  => 'log',
							'mail' => (int) $r->id,
						)
					)
				) . '"><strong>' . esc_html( '' !== $r->subject ? $r->subject : __( '(no subject)', 'aluleth-smtp' ) ) . '</strong></a><br><small>' . esc_html( $r->recipients ) . '</small></td>';
				echo '<td>' . esc_html( Router::source_label( $r->source ) ) . '</td><td>' . esc_html( self::connection_label( $r->connection ) ) . '</td><td class="num als-mono">' . esc_html( self::when( $r->created_at ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		$pages = (int) ceil( $res['total'] / 50 );
		echo '<div class="als-card-foot"><span>';
		/* translators: 1: days kept, 2: days kept for failed emails. */
		echo esc_html( sprintf( __( 'Emails are kept %1$d days, failed ones %2$d days. Links that give access to an account are never kept.', 'aluleth-smtp' ), $s['keep_days'], $s['keep_failed'] ) );
		echo '</span><a href="' . esc_url( Admin::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Change what is kept', 'aluleth-smtp' ) . '</a></div>';
		if ( $pages > 1 ) {
			echo '<nav class="als-pages" aria-label="' . esc_attr__( 'Pages', 'aluleth-smtp' ) . '">';
			$last = min( $pages, $page + 3 );
			for ( $p = max( 1, $page - 3 ); $p <= $last; $p++ ) {
				echo '<a href="' . esc_url(
					Admin::url(
						array_filter(
							array(
								'tab'    => 'log',
								'status' => $status,
								'source' => $source,
								's'      => $search,
								'paged'  => $p,
							)
						)
					)
				) . '"' . ( $p === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( (string) $p ) . '</a>';
			}
			echo '</nav>';
		}
		echo '</section>';
	}

	/**
	 * One logged email.
	 *
	 * @param object $row Row.
	 * @return void
	 */
	private static function mail( $row ) {
		$msg   = Log::message( $row );
		$shown = $msg ? Log::for_log( $msg ) : null;
		$steps = json_decode( (string) $row->steps, true );
		echo '<p><a href="' . esc_url( Admin::url( array( 'tab' => 'log' ) ) ) . '">&larr; ' . esc_html__( 'Email log', 'aluleth-smtp' ) . '</a></p>';
		echo wp_kses( '<div class="als-head"><div><h1>' . esc_html( '' !== $row->subject ? $row->subject : __( '(no subject)', 'aluleth-smtp' ) ) . '</h1><p class="als-sub">' . self::status_chip( $row ) . ' ' . esc_html( Router::source_label( $row->source ) . ' · ' . ( 'marketing' === $row->kind ? __( 'Marketing', 'aluleth-smtp' ) : __( 'Transactional', 'aluleth-smtp' ) ) ) . '</p></div><div class="als-actions">', self::allowed_html() );
		if ( $shown && empty( $shown['masked'] ) && in_array( $row->status, array( 'sent', 'failed' ), true ) ) {
			self::button_form( 'resend', array( 'mail' => (int) $row->id ), __( 'Send again', 'aluleth-smtp' ), 'als-btn is-accent' );
		}
		self::button_form( 'delete_mail', array( 'mail' => (int) $row->id ), __( 'Delete from the log', 'aluleth-smtp' ), 'als-btn is-danger' );
		echo '</div></div><div class="als-cols"><div class="als-main">';
		echo '<section class="als-card"><dl class="als-dl">';
		$from  = $shown ? Message::format( $shown['from'] ) : '';
		$reply = $shown && ! empty( $shown['reply_to'] ) ? implode( ', ', array_map( array( Message::class, 'format' ), $shown['reply_to'] ) ) : '';
		$dl    = array(
			__( 'To', 'aluleth-smtp' )         => $row->recipients,
			__( 'From', 'aluleth-smtp' )       => $from,
			__( 'Reply to', 'aluleth-smtp' )   => $reply,
			__( 'Connection', 'aluleth-smtp' ) => self::connection_label( $row->connection ) . ( (int) $row->fallback ? ' (' . __( 'fallback', 'aluleth-smtp' ) . ')' : '' ),
			__( 'Message ID', 'aluleth-smtp' ) => $row->message_id,
			__( 'Created', 'aluleth-smtp' )    => self::when( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			__( 'Attempts', 'aluleth-smtp' )   => (string) (int) $row->attempts,
		);
		if ( '' !== (string) $row->response && 'sent' !== $row->status ) {
			$dl[ __( 'Last answer', 'aluleth-smtp' ) ] = $row->response;
		}
		foreach ( $dl as $k => $v ) {
			if ( '' !== (string) $v ) {
				echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
			}
		}
		echo '</dl></section>';
		echo '<section class="als-card"><h2>' . esc_html__( 'What was sent', 'aluleth-smtp' ) . '</h2>';
		if ( ! $shown ) {
			echo '<p class="als-help">' . esc_html__( 'Only a summary of this email was kept (to, subject, status), as set in Log and alerts.', 'aluleth-smtp' ) . '</p>';
		} else {
			if ( ! empty( $shown['masked'] ) ) {
				echo '<p class="als-help">' . esc_html__( 'Links and codes that give access to an account were hidden before saving, so an old log can never be used to get into the site.', 'aluleth-smtp' ) . '</p>';
			}
			if ( '' !== $shown['html'] ) {
				echo '<iframe class="als-preview" sandbox="" referrerpolicy="no-referrer" title="' . esc_attr__( 'Email content', 'aluleth-smtp' ) . '" srcdoc="' . esc_attr( '<base target="_blank"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'">' . wp_kses_post( $shown['html'] ) ) . '"></iframe>';
			} else {
				echo '<pre class="als-text">' . esc_html( $shown['text'] ) . '</pre>';
			}
			if ( ! empty( $shown['attachments'] ) ) {
				echo '<p class="als-help">' . esc_html__( 'Attachments:', 'aluleth-smtp' ) . ' ' . esc_html( implode( ', ', array_column( $shown['attachments'], 'name' ) ) ) . '</p>';
			}
		}
		echo '</section></div><aside class="als-side"><section class="als-card"><h2>' . esc_html__( 'What happened', 'aluleth-smtp' ) . '</h2><ol class="als-steps">';
		foreach ( is_array( $steps ) ? $steps : array() as $st ) {
			echo '<li class="is-' . esc_attr( isset( $st['s'] ) ? $st['s'] : 'info' ) . '"><strong>' . esc_html( $st['w'] ) . '</strong> <span class="als-mono">' . esc_html( wp_date( get_option( 'time_format' ), (int) $st['t'] ) ) . '</span>' . ( '' !== $st['d'] ? '<br><span>' . esc_html( $st['d'] ) . '</span>' : '' ) . '</li>';
		}
		echo '</ol></section></aside></div>';
	}

	/**
	 * Log and alert settings.
	 *
	 * @return void
	 */
	private static function settings() {
		$s      = Connections::settings();
		$levels = array(
			'full'    => __( 'The whole email (links that give access hidden)', 'aluleth-smtp' ),
			'summary' => __( 'Only a summary: to, subject, status', 'aluleth-smtp' ),
			'none'    => __( 'Nothing once sent (failed ones stay)', 'aluleth-smtp' ),
		);
		echo '<div class="als-head"><div><h1>' . esc_html__( 'Log and alerts', 'aluleth-smtp' ) . '</h1></div></div>';
		echo '<div class="als-cols"><div class="als-main">';
		self::form_start( 'save_settings' );
		echo '<section class="als-card"><h2>' . esc_html__( 'What the log keeps', 'aluleth-smtp' ) . '</h2><div class="als-grid">';
		foreach ( array(
			'log_transactional' => __( 'Transactional emails (orders, accounts, forms)', 'aluleth-smtp' ),
			'log_marketing'     => __( 'Marketing emails (newsletters, campaigns)', 'aluleth-smtp' ),
		) as $key => $label ) {
			echo '<label class="als-field">' . esc_html( $label ) . '<select name="' . esc_attr( $key ) . '">';
			foreach ( $levels as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $s[ $key ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '<label class="als-field">' . esc_html__( 'Keep sent emails (days)', 'aluleth-smtp' ) . '<input type="number" min="1" max="3650" name="keep_days" value="' . esc_attr( (string) $s['keep_days'] ) . '"></label>';
		echo '<label class="als-field">' . esc_html__( 'Keep failed emails (days)', 'aluleth-smtp' ) . '<input type="number" min="1" max="3650" name="keep_failed" value="' . esc_attr( (string) $s['keep_failed'] ) . '"></label>';
		echo '</div><p class="als-help">' . esc_html__( 'A summary for big mailings keeps your database small: 100,000 summaries take about 30 MB, full emails can take gigabytes.', 'aluleth-smtp' ) . '</p></section>';

		echo '<section class="als-card"><h2>' . esc_html__( 'Alerts when emails cannot be sent', 'aluleth-smtp' ) . '</h2><p class="als-help">' . esc_html__( 'At most one alert every 15 minutes, with the number of failed emails and the last error.', 'aluleth-smtp' ) . '</p><div class="als-checks"><label><input type="checkbox" name="alert_email" value="1"' . checked( $s['alert_email'], true, false ) . '> ' . esc_html__( 'Email', 'aluleth-smtp' ) . '</label></div>';
		echo '<div class="als-grid"><label class="als-field">' . esc_html__( 'Send the email alert to', 'aluleth-smtp' ) . '<input type="email" name="alert_to" value="' . esc_attr( $s['alert_to'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"></label></div><div class="als-grid">';
		foreach ( array(
			'slack'          => array( __( 'Slack webhook address', 'aluleth-smtp' ), 'https://hooks.slack.com/services/…' ),
			'discord'        => array( __( 'Discord webhook address', 'aluleth-smtp' ), 'https://discord.com/api/webhooks/…' ),
			'telegram_token' => array( __( 'Telegram bot token', 'aluleth-smtp' ), '' ),
		) as $key => $f ) {
			$saved = '' !== $s[ $key ];
			echo '<label class="als-field">' . esc_html( $f[0] ) . '<input type="password" name="' . esc_attr( $key ) . '" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'aluleth-smtp' ) : $f[1] ) . '">';
			if ( $saved ) {
				echo '<span class="als-check-inline"><input type="checkbox" name="' . esc_attr( $key . '_clear' ) . '" value="1" id="' . esc_attr( 'alsmtp-' . $key . '-clear' ) . '"> <label for="' . esc_attr( 'alsmtp-' . $key . '-clear' ) . '">' . esc_html__( 'Remove', 'aluleth-smtp' ) . '</label></span>';
			}
			echo '</label>';
		}
		echo '<label class="als-field">' . esc_html__( 'Telegram chat ID', 'aluleth-smtp' ) . '<input type="text" name="telegram_chat" value="' . esc_attr( $s['telegram_chat'] ) . '"></label>';
		echo '</div></section><div class="als-form-actions"><button type="submit" class="als-btn is-accent">' . esc_html__( 'Save', 'aluleth-smtp' ) . '</button></div></form></div>';
		echo '<aside class="als-side"><section class="als-card"><h2>' . esc_html__( 'Try the alerts', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Sends a test alert on every channel saved above.', 'aluleth-smtp' ) . '</p>';
		self::button_form( 'test_alert', array(), __( 'Send a test alert', 'aluleth-smtp' ) );
		echo '</section><section class="als-card"><h2>' . esc_html__( 'Your data', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'The log can be exported and erased per person with the WordPress privacy tools (Tools › Export / Erase Personal Data). What happens when the plugin is deleted is set in Aluleth › Settings.', 'aluleth-smtp' ) . '</p></section></aside></div>';
	}
}
