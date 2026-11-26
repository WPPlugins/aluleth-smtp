<?php
/**
 * The Aluleth dashboard: key numbers, a chart, what to handle, where contacts
 * come from, recent activity and the Aluleth tools of the site.
 *
 * Every product adds its own numbers through filters, so the dashboard grows
 * with the suite:
 * - aluleth/dashboard/kpis    (array $kpis, array $period)
 * - aluleth/dashboard/series  (array $series, array $period)
 * - aluleth/dashboard/todo    (array $todo)
 * - aluleth/dashboard/panels  (array $panels, array $period)
 * - aluleth/dashboard/tools   (array $tools)
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard.
 */
final class Dashboard {

	/**
	 * Colours of the chart series, in order (checked for colour blindness).
	 */
	const COLORS = array( '#2f5bea', '#e8590c', '#7c3aed', '#0e7490' );

	/**
	 * The period shown: 7 days, 30 days or 12 months.
	 *
	 * @return array{days: int, unit: string, start: string, end: string, prev_start: string, buckets: array<string, string>, offset: int}
	 */
	public static function period() {
		// Remembered only when chosen with the dashboard's own links (they carry a nonce); other links just show it.
		$chosen = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'aluleth_dashboard_period' );
		$days   = isset( $_GET['period'] ) ? absint( wp_unslash( $_GET['period'] ) ) : 0;
		if ( ! in_array( $days, array( 7, 30, 365 ), true ) ) {
			$days = (int) get_user_meta( get_current_user_id(), 'aluleth_dashboard_period', true );
			$days = in_array( $days, array( 7, 30, 365 ), true ) ? $days : 30;
		} elseif ( $chosen && current_user_can( Admin::cap( 'dashboard' ) ) ) {
			update_user_meta( get_current_user_id(), 'aluleth_dashboard_period', $days );
		}
		$tz      = wp_timezone();
		$offset  = (int) $tz->getOffset( new \DateTime( 'now', $tz ) );
		$today   = new \DateTimeImmutable( 'today', $tz );
		$buckets = array();
		if ( 365 === $days ) {
			$first = $today->modify( 'first day of this month' )->modify( '-11 months' );
			for ( $i = 0; $i < 12; $i++ ) {
				$month                              = $first->modify( '+' . $i . ' months' );
				$buckets[ $month->format( 'Y-m' ) ] = wp_date( 'M', $month->getTimestamp() );
			}
			$start = $first;
			$prev  = $first->modify( '-12 months' );
			$unit  = 'month';
		} else {
			$first = $today->modify( '-' . ( $days - 1 ) . ' days' );
			for ( $i = 0; $i < $days; $i++ ) {
				$day                                = $first->modify( '+' . $i . ' days' );
				$buckets[ $day->format( 'Y-m-d' ) ] = wp_date( 7 === $days ? 'D j' : 'j M', $day->getTimestamp() );
			}
			$start = $first;
			$prev  = $first->modify( '-' . $days . ' days' );
			$unit  = 'day';
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			'days'       => $days,
			'unit'       => $unit,
			'start'      => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'        => gmdate( 'Y-m-d H:i:s' ),
			'prev_start' => $prev->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'buckets'    => $buckets,
			'offset'     => $offset,
		);
	}

	/**
	 * Counts rows per day or month of the period (in the site's time zone).
	 * Products use it for their own tables.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Datetime column (UTC).
	 * @param array  $period Period.
	 * @param array  $where  Optional filters: 'equal' => array( column, number ) and 'not' => array( column, text ).
	 *                       Older callers passed an SQL string; the two strings Aluleth Forms used are still understood.
	 * @param array  $params Values of the older SQL string.
	 * @return array<string, int> Bucket key => count, for every bucket.
	 */
	public static function count_by_bucket( $table, $column, array $period, $where = array(), array $params = array() ) {
		global $wpdb;
		if ( is_string( $where ) ) {
			$where = self::legacy_where( $where, $params );
		}
		$length = 'month' === $period['unit'] ? 7 : 10;
		$column = preg_replace( '/[^a-z0-9_]/', '', $column );
		$table  = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		$equal  = isset( $where['equal'] ) && is_array( $where['equal'] ) ? array( preg_replace( '/[^a-z0-9_]/', '', (string) $where['equal'][0] ), (int) $where['equal'][1] ) : null;
		$not    = isset( $where['not'] ) && is_array( $where['not'] ) ? array( preg_replace( '/[^a-z0-9_]/', '', (string) $where['not'][0] ), (string) $where['not'][1] ) : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT LEFT(DATE_ADD(%i, INTERVAL %d SECOND), %d) AS bucket, COUNT(*) AS n FROM %i WHERE %i >= %s AND ( %d = 0 OR %i = %d ) AND ( %d = 0 OR %i <> %s ) GROUP BY bucket',
				$column,
				(int) $period['offset'],
				$length,
				$table,
				$column,
				$period['start'],
				$equal ? 1 : 0,
				$equal ? $equal[0] : $column,
				$equal ? $equal[1] : 0,
				$not ? 1 : 0,
				$not ? $not[0] : $column,
				$not ? $not[1] : ''
			)
		);
		$out  = array_fill_keys( array_keys( $period['buckets'] ), 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->bucket ] ) ) {
				$out[ $row->bucket ] = (int) $row->n;
			}
		}
		return $out;
	}

	/**
	 * Filters from the SQL strings older Aluleth Forms versions passed.
	 *
	 * @param string $where  SQL condition.
	 * @param array  $params Values.
	 * @return array
	 */
	private static function legacy_where( $where, array $params ) {
		$where = trim( $where );
		if ( 'status <> %s' === $where && isset( $params[0] ) ) {
			return array( 'not' => array( 'status', (string) $params[0] ) );
		}
		if ( 'form_id = %d AND status <> %s' === $where && isset( $params[1] ) ) {
			return array(
				'equal' => array( 'form_id', (int) $params[0] ),
				'not'   => array( 'status', (string) $params[1] ),
			);
		}
		return array();
	}

	/**
	 * Change between two totals, in percent (null when there is nothing to compare).
	 *
	 * @param int $now    Current total.
	 * @param int $before Previous total.
	 * @return float|null
	 */
	public static function delta( $now, $before ) {
		if ( $before <= 0 ) {
			return null;
		}
		return round( ( $now - $before ) * 100 / $before );
	}

	/**
	 * Text of a change, e.g. "+18% vs the 30 days before".
	 *
	 * @param float|null $delta  Change in percent.
	 * @param array      $period Period.
	 * @return string
	 */
	public static function delta_text( $delta, array $period ) {
		if ( null === $delta ) {
			return '';
		}
		$sign = $delta > 0 ? '+' : '';
		if ( 365 === $period['days'] ) {
			/* translators: %s: change in percent, e.g. +18%. */
			return sprintf( __( '%s vs the 12 months before', 'aluleth-smtp' ), $sign . number_format_i18n( $delta ) . '%' );
		}
		/* translators: 1: change in percent, e.g. +18%, 2: number of days. */
		return sprintf( __( '%1$s vs the %2$d days before', 'aluleth-smtp' ), $sign . number_format_i18n( $delta ) . '%', $period['days'] );
	}

	/**
	 * Prints the dashboard.
	 *
	 * @return void
	 */
	public static function render() {
		global $wpdb;
		wp_enqueue_style( 'aluleth-core-dashboard', plugins_url( 'assets/dashboard.css', ALULETH_CORE_PATH . '/bootstrap.php' ), array( 'aluleth-core-admin' ), ALULETH_CORE_VERSION );
		wp_enqueue_script( 'aluleth-core-dashboard', plugins_url( 'assets/dashboard.js', ALULETH_CORE_PATH . '/bootstrap.php' ), array(), ALULETH_CORE_VERSION, true );

		$period = self::period();
		$p      = $wpdb->prefix;

		// Core numbers: contacts.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table prefix only.
		$new_now     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aluleth_contacts WHERE created_at >= %s", $period['start'] ) );
		$new_before  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aluleth_contacts WHERE created_at >= %s AND created_at < %s", $period['prev_start'], $period['start'] ) );
		$imported    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aluleth_contacts WHERE created_at >= %s AND source = %s", $period['start'], 'import' ) );
		$all         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aluleth_contacts" );
		$subscribers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}aluleth_contacts WHERE status = %s", 'subscribed' ) );
		$sources     = $wpdb->get_results( $wpdb->prepare( "SELECT source, COUNT(*) AS n FROM {$p}aluleth_contacts WHERE created_at >= %s GROUP BY source ORDER BY n DESC", $period['start'] ) );
		// phpcs:enable

		$note = '';
		if ( $imported ) {
			/* translators: %s: number of contacts. */
			$note = sprintf( _n( '%s from an import', '%s from an import', $imported, 'aluleth-smtp' ), number_format_i18n( $imported ) );
		}
		$delta = self::delta_text( self::delta( $new_now, $new_before ), $period );
		$kpis  = array(
			array(
				'key'   => 'contacts',
				'label' => __( 'New contacts', 'aluleth-smtp' ),
				'value' => number_format_i18n( $new_now ),
				'note'  => trim( $delta . ( $delta && $note ? ' · ' : '' ) . $note ),
				'order' => 20,
			),
			array(
				'key'   => 'subscribers',
				'label' => __( 'Newsletter subscribers', 'aluleth-smtp' ),
				'value' => number_format_i18n( $subscribers ),
				/* translators: %s: percentage of contacts. */
				'note'  => $all ? sprintf( __( '%s of your contacts', 'aluleth-smtp' ), number_format_i18n( round( $subscribers * 100 / $all ) ) . '%' ) : '',
				'bar'   => $all ? (int) round( $subscribers * 100 / $all ) : null,
				'order' => 40,
			),
		);

		/**
		 * Key numbers of the dashboard. Each: key, label, value (formatted), note, delta, bar (0–100), order.
		 *
		 * @param array[] $kpis   Key numbers.
		 * @param array   $period Period (days, unit, start, end, prev_start, buckets, offset).
		 */
		$kpis = (array) apply_filters( 'aluleth/dashboard/kpis', $kpis, $period );
		usort(
			$kpis,
			static function ( $a, $b ) {
				return ( isset( $a['order'] ) ? $a['order'] : 50 ) - ( isset( $b['order'] ) ? $b['order'] : 50 );
			}
		);

		$series = array(
			array(
				'key'   => 'contacts',
				'label' => __( 'New contacts', 'aluleth-smtp' ),
				'data'  => self::count_by_bucket( $p . 'aluleth_contacts', 'created_at', $period ),
				'order' => 20,
			),
		);

		/**
		 * Lines of the dashboard chart. Each: key, label, data (bucket => count), order.
		 *
		 * @param array[] $series Series.
		 * @param array   $period Period.
		 */
		$series = (array) apply_filters( 'aluleth/dashboard/series', $series, $period );
		usort(
			$series,
			static function ( $a, $b ) {
				return ( isset( $a['order'] ) ? $a['order'] : 50 ) - ( isset( $b['order'] ) ? $b['order'] : 50 );
			}
		);
		$series = array_slice( $series, 0, count( self::COLORS ) );

		/**
		 * "To handle" list. Keys: title, count, link, link_label, items (each: name, text, when, url).
		 *
		 * @param array $todo List, empty when no product provides one.
		 */
		$todo = (array) apply_filters( 'aluleth/dashboard/todo', array() );

		/**
		 * Wide panels (for example the forms table). Each: title, link, link_label, html (already escaped).
		 *
		 * @param array[] $panels Panels.
		 * @param array   $period Period.
		 */
		$panels = (array) apply_filters( 'aluleth/dashboard/panels', array(), $period );

		$tools = array();
		if ( defined( 'ALULETH_AIW_VERSION' ) ) {
			$tools[] = array(
				'name'  => 'AI Writer',
				'text'  => __( 'Writes your pages, forms and emails', 'aluleth-smtp' ),
				'state' => 'active',
			);
		}

		/**
		 * Aluleth tools of the site. Each: name, text, state (active|soon).
		 *
		 * @param array[] $tools Tools.
		 */
		$tools   = (array) apply_filters( 'aluleth/dashboard/tools', $tools );
		$names   = wp_list_pluck( $tools, 'name' );
		$coming  = array(
			'Popups' => __( 'Show your forms at the right moment', 'aluleth-smtp' ),
			'SMTP'   => __( 'Emails that land in the inbox', 'aluleth-smtp' ),
		);
		$coming2 = array();
		foreach ( $coming as $name => $text ) {
			if ( ! in_array( $name, $names, true ) ) {
				$coming2[] = array(
					'name'  => $name,
					'text'  => $text,
					'state' => 'soon',
				);
			}
		}
		$tools = array_merge( $tools, $coming2 );

		// Header.
		$user = wp_get_current_user();
		$name = $user->first_name ? $user->first_name : $user->display_name;
		$hour = (int) wp_date( 'G' );
		if ( $hour < 12 ) {
			/* translators: %s: first name of the user. */
			$hello = __( 'Good morning, %s', 'aluleth-smtp' );
		} elseif ( $hour < 18 ) {
			/* translators: %s: first name of the user. */
			$hello = __( 'Good afternoon, %s', 'aluleth-smtp' );
		} else {
			/* translators: %s: first name of the user. */
			$hello = __( 'Good evening, %s', 'aluleth-smtp' );
		}

		echo '<div class="wrap al-wrap al-dash">';
		echo '<header class="al-dash-head"><div>';
		echo '<span class="al-dash-date">' . esc_html( wp_date( 'l j F' ) ) . '</span>';
		echo '<h1 class="al-dash-title">' . esc_html( sprintf( $hello, $name ) ) . '</h1>';
		if ( ! empty( $todo['count'] ) ) {
			/* translators: %s: number of entries. */
			echo '<p class="al-dash-lead">' . esc_html( sprintf( _n( '%s new entry is waiting for you.', '%s new entries are waiting for you.', (int) $todo['count'], 'aluleth-smtp' ), number_format_i18n( (int) $todo['count'] ) ) ) . '</p>';
		} else {
			echo '<p class="al-dash-lead">' . esc_html__( 'Everything is handled. Here is how your site is doing.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</div><div class="al-dash-actions"><nav class="al-seg" aria-label="' . esc_attr__( 'Period', 'aluleth-smtp' ) . '">';
		foreach ( array(
			7   => __( '7 days', 'aluleth-smtp' ),
			30  => __( '30 days', 'aluleth-smtp' ),
			365 => __( '12 months', 'aluleth-smtp' ),
		) as $days => $label ) {
			echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'period', $days, admin_url( 'admin.php?page=' . Admin::MENU ) ), 'aluleth_dashboard_period' ) ) . '"' . ( $days === $period['days'] ? ' class="is-on" aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav><a class="al-button al-button--ghost" href="' . esc_url( Import_Admin::url() ) . '">' . esc_html__( 'Import contacts', 'aluleth-smtp' ) . '</a>';

		/**
		 * Main button of the dashboard header.
		 *
		 * @param array|null $action label, url.
		 */
		$action = apply_filters( 'aluleth/dashboard/action', null );
		if ( is_array( $action ) && ! empty( $action['url'] ) ) {
			echo '<a class="al-button al-button--accent" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
		}
		echo '</div></header>';

		// Sends to outside tools that failed for good in the last 7 days.
		$failed = Queue::failed_count( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) );
		if ( $failed ) {
			/* translators: %s: number of failed sends. */
			echo '<p class="al-alert" role="status"><span>' . esc_html( sprintf( _n( '%s send to one of your tools failed this week.', '%s sends to your tools failed this week.', $failed, 'aluleth-smtp' ), number_format_i18n( $failed ) ) ) . '</span> <a href="' . esc_url( Connections::url( 'problem' ) ) . '">' . esc_html__( 'See what happened', 'aluleth-smtp' ) . '</a></p>';
		}

		// Key numbers.
		echo '<section class="al-kpis" aria-label="' . esc_attr__( 'Key numbers', 'aluleth-smtp' ) . '">';
		foreach ( $kpis as $kpi ) {
			echo '<article class="al-kpi"><span class="al-kpi-label">' . esc_html( $kpi['label'] ) . '</span><strong class="al-kpi-value">' . esc_html( $kpi['value'] ) . '</strong>';
			if ( isset( $kpi['bar'] ) && null !== $kpi['bar'] ) {
				echo '<span class="al-kpi-bar" aria-hidden="true"><span style="width:' . esc_attr( (string) max( 0, min( 100, (int) $kpi['bar'] ) ) ) . '%"></span></span>';
			}
			if ( ! empty( $kpi['note'] ) ) {
				echo '<span class="al-kpi-note">' . esc_html( $kpi['note'] ) . '</span>';
			}
			echo '</article>';
		}
		echo '</section>';

		// Chart + to handle.
		echo '<div class="al-dash-row">';
		self::chart( $series, $period );
		echo '<section class="al-card al-todo"><div class="al-card-head"><h2>' . esc_html( ! empty( $todo['title'] ) ? $todo['title'] : __( 'To handle', 'aluleth-smtp' ) ) . '</h2>';
		if ( ! empty( $todo['link'] ) ) {
			echo '<a href="' . esc_url( $todo['link'] ) . '">' . esc_html( $todo['link_label'] ) . '</a>';
		}
		echo '</div>';
		if ( ! empty( $todo['items'] ) ) {
			echo '<ul class="al-todo-list">';
			foreach ( $todo['items'] as $item ) {
				$initials = self::initials( $item['name'] );
				echo '<li><a href="' . esc_url( $item['url'] ) . '"><span class="al-avatar" aria-hidden="true">' . esc_html( $initials ) . '</span><span class="al-todo-main"><strong>' . esc_html( $item['name'] ) . '</strong><span>' . esc_html( $item['text'] ) . '</span></span><span class="al-todo-when">' . esc_html( $item['when'] ) . '</span></a></li>';
			}
			echo '</ul>';
			$more = (int) $todo['count'] - count( $todo['items'] );
			if ( $more > 0 ) {
				/* translators: %s: number of entries. */
				echo '<p class="al-muted">' . esc_html( sprintf( _n( '+%s more', '+%s more', $more, 'aluleth-smtp' ), number_format_i18n( $more ) ) ) . '</p>';
			}
		} else {
			echo '<p class="al-muted">' . esc_html__( 'Nothing to handle right now.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</section></div>';

		// Panels + sources.
		echo '<div class="al-dash-row">';
		echo '<div class="al-dash-stack">';
		foreach ( $panels as $panel ) {
			echo '<section class="al-card al-panel-card"><div class="al-card-head"><h2>' . esc_html( $panel['title'] ) . '</h2>';
			if ( ! empty( $panel['link'] ) ) {
				echo '<a href="' . esc_url( $panel['link'] ) . '">' . esc_html( $panel['link_label'] ) . '</a>';
			}
			echo '</div>' . wp_kses( (string) $panel['html'], Admin::allowed_html() ) . '</section>';
		}
		self::activity( $period );
		echo '</div><div class="al-dash-stack">';
		self::sources( $sources, $new_now );
		self::tools( $tools );
		echo '</div></div>';
		echo '</div>';
	}

	/**
	 * Initials for an avatar.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( $name ) {
		$parts = preg_split( '/[\s@._-]+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( array_filter( $parts ), 0, 2 ) as $part ) {
			$out .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $out ) : strtoupper( $out );
	}

	/**
	 * The line chart (inline SVG, a tooltip on hover and a table for screen readers).
	 *
	 * @param array[] $series Series.
	 * @param array   $period Period.
	 * @return void
	 */
	private static function chart( array $series, array $period ) {
		$keys  = array_keys( $period['buckets'] );
		$count = count( $keys );
		$max   = 0;
		$total = 0;
		foreach ( $series as $line ) {
			foreach ( $line['data'] as $n ) {
				$max    = max( $max, (int) $n );
				$total += (int) $n;
			}
		}
		$step = max( 1, (int) ceil( $max / 4 ) );
		if ( $step > 5 ) {
			$magnitude = pow( 10, floor( log10( $step ) ) );
			$step      = (int) ( ceil( $step / $magnitude ) * $magnitude );
		}
		$top    = $step * 4;
		$width  = 760;
		$height = 230;
		$left   = 40;
		$right  = 14;
		$y0     = 18;
		$y1     = 196;
		$x      = static function ( $i ) use ( $count, $width, $left, $right ) {
			return $count > 1 ? $left + $i * ( $width - $left - $right ) / ( $count - 1 ) : ( $width + $left ) / 2;
		};
		$y      = static function ( $v ) use ( $top, $y0, $y1 ) {
			return $y1 - ( $y1 - $y0 ) * $v / $top;
		};

		$title = array();
		foreach ( $series as $line ) {
			$title[] = $line['label'];
		}
		echo '<section class="al-card al-chart-card"><div class="al-card-head"><h2>' . esc_html( implode( ' · ', $title ) ) . '</h2>';
		if ( count( $series ) > 1 ) {
			echo '<ul class="al-legend">';
			foreach ( $series as $i => $line ) {
				echo '<li><span style="background:' . esc_attr( self::COLORS[ $i ] ) . '"></span>' . esc_html( $line['label'] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		$points = array();
		foreach ( $keys as $i => $key ) {
			$values = array();
			foreach ( $series as $line ) {
				$values[] = isset( $line['data'][ $key ] ) ? (int) $line['data'][ $key ] : 0;
			}
			$points[] = array(
				'label'  => $period['buckets'][ $key ],
				'values' => $values,
			);
		}
		$meta = array(
			'labels' => wp_list_pluck( $series, 'label' ),
			'colors' => array_slice( self::COLORS, 0, count( $series ) ),
			'points' => $points,
		);
		echo '<div class="al-chart" data-chart="' . esc_attr( wp_json_encode( $meta ) ) . '">';
		echo '<svg viewBox="0 0 ' . esc_attr( (string) $width ) . ' ' . esc_attr( (string) $height ) . '" role="img" aria-label="' . esc_attr__( 'Chart of the period, the same numbers are in the table below.', 'aluleth-smtp' ) . '">';
		for ( $g = 0; $g <= 4; $g++ ) {
			$gy = $y( $g * $step );
			echo '<line x1="' . esc_attr( (string) $left ) . '" x2="' . esc_attr( (string) ( $width - $right ) ) . '" y1="' . esc_attr( (string) $gy ) . '" y2="' . esc_attr( (string) $gy ) . '" class="' . ( 0 === $g ? 'al-axis' : 'al-grid' ) . '"/>';
			echo '<text x="' . esc_attr( (string) ( $left - 8 ) ) . '" y="' . esc_attr( (string) ( $gy + 4 ) ) . '" class="al-tick" text-anchor="end">' . esc_html( number_format_i18n( $g * $step ) ) . '</text>';
		}
		$label_every = max( 1, (int) ceil( $count / 6 ) );
		foreach ( $keys as $i => $key ) {
			if ( 0 === $i % $label_every || $i === $count - 1 ) {
				if ( $i !== $count - 1 && $count - 1 - $i < $label_every / 2 ) {
					continue;
				}
				echo '<text x="' . esc_attr( (string) $x( $i ) ) . '" y="' . esc_attr( (string) ( $height - 8 ) ) . '" class="al-tick" text-anchor="' . ( 0 === $i ? 'start' : ( $i === $count - 1 ? 'end' : 'middle' ) ) . '">' . esc_html( $period['buckets'][ $key ] ) . '</text>';
			}
		}
		foreach ( $series as $s => $line ) {
			$d = '';
			foreach ( $keys as $i => $key ) {
				$d .= ( $i ? ' L' : 'M' ) . round( $x( $i ), 1 ) . ' ' . round( $y( isset( $line['data'][ $key ] ) ? (int) $line['data'][ $key ] : 0 ), 1 );
			}
			echo '<path d="' . esc_attr( $d ) . '" fill="none" stroke="' . esc_attr( self::COLORS[ $s ] ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
			$last = end( $keys );
			echo '<circle cx="' . esc_attr( (string) round( $x( $count - 1 ), 1 ) ) . '" cy="' . esc_attr( (string) round( $y( isset( $line['data'][ $last ] ) ? (int) $line['data'][ $last ] : 0 ), 1 ) ) . '" r="4" fill="' . esc_attr( self::COLORS[ $s ] ) . '"/>';
		}
		echo '<line class="al-cross" x1="0" x2="0" y1="' . esc_attr( (string) $y0 ) . '" y2="' . esc_attr( (string) $y1 ) . '" hidden/>';
		echo '</svg><div class="al-tip" hidden></div>';
		if ( ! $total ) {
			echo '<p class="al-chart-empty">' . esc_html__( 'Your first entries and contacts will draw this chart.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</div>';

		echo '<table class="screen-reader-text"><caption>' . esc_html( implode( ', ', $title ) ) . '</caption><thead><tr><th>' . esc_html__( 'Date', 'aluleth-smtp' ) . '</th>';
		foreach ( $series as $line ) {
			echo '<th>' . esc_html( $line['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $points as $point ) {
			echo '<tr><th>' . esc_html( $point['label'] ) . '</th>';
			foreach ( $point['values'] as $v ) {
				echo '<td>' . esc_html( number_format_i18n( $v ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Where new contacts come from.
	 *
	 * @param object[] $rows  source, n.
	 * @param int      $total Total of the period.
	 * @return void
	 */
	private static function sources( array $rows, $total ) {
		$labels = array(
			'aluleth-forms' => __( 'Forms', 'aluleth-smtp' ),
			'import'        => __( 'Import', 'aluleth-smtp' ),
			'woocommerce'   => __( 'Shop customers', 'aluleth-smtp' ),
			'fluentcart'    => __( 'Shop customers', 'aluleth-smtp' ),
			'wp_user'       => __( 'New user accounts', 'aluleth-smtp' ),
		);
		$merged = array();
		foreach ( $rows as $row ) {
			$label = isset( $labels[ $row->source ] ) ? $labels[ $row->source ] : __( 'Other', 'aluleth-smtp' );

			/**
			 * Label of a contact source on the dashboard.
			 *
			 * @param string $label  Label.
			 * @param string $source Source key.
			 */
			$label            = (string) apply_filters( 'aluleth/dashboard/source_label', $label, $row->source );
			$merged[ $label ] = ( isset( $merged[ $label ] ) ? $merged[ $label ] : 0 ) + (int) $row->n;
		}
		arsort( $merged );
		echo '<section class="al-card"><div class="al-card-head"><h2>' . esc_html__( 'Where new contacts come from', 'aluleth-smtp' ) . '</h2></div>';
		if ( ! $merged ) {
			echo '<p class="al-muted">' . esc_html__( 'No new contact in this period.', 'aluleth-smtp' ) . '</p></section>';
			return;
		}
		echo '<ul class="al-bars">';
		foreach ( $merged as $label => $n ) {
			$pct = $total ? round( $n * 100 / $total ) : 0;
			echo '<li><span class="al-bars-top"><span>' . esc_html( $label ) . '</span><b>' . esc_html( number_format_i18n( $n ) ) . '</b></span><span class="al-bars-track" aria-hidden="true"><span style="width:' . esc_attr( (string) max( 2, $pct ) ) . '%"></span></span></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Recent activity of contacts and imports.
	 *
	 * @param array $period Period.
	 * @return void
	 */
	private static function activity( array $period ) {
		global $wpdb;
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT e.*, c.email, c.first_name, c.last_name FROM {$wpdb->prefix}aluleth_events e LEFT JOIN {$wpdb->prefix}aluleth_contacts c ON c.id = e.contact_id WHERE e.type = 'form.submitted' ORDER BY e.id DESC LIMIT %d",
				8
			)
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$row->data = $row->data ? json_decode( $row->data, true ) : array();
			$name      = trim( $row->first_name . ' ' . $row->last_name );
			$items[]   = array(
				'time' => strtotime( $row->created_at . ' UTC' ),
				'who'  => '' !== $name ? $name : (string) $row->email,
				'what' => aluleth_core()->events()->label( $row ),
				'url'  => $row->contact_id ? add_query_arg(
					array(
						'page'    => 'aluleth-contacts',
						'contact' => (int) $row->contact_id,
					),
					admin_url( 'admin.php' )
				) : '',
			);
		}
		foreach ( Import::history() as $job ) {
			$items[] = array(
				'time' => (int) $job['ended_at'],
				'who'  => '',
				/* translators: 1: number of contacts, 2: list name. */
				'what' => sprintf( _n( '%1$s contact imported from %2$s', '%1$s contacts imported from %2$s', (int) $job['counts']['created'], 'aluleth-smtp' ), number_format_i18n( (int) $job['counts']['created'] ), $job['name'] ),
				'url'  => Import_Admin::url( $job['id'] ),
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		echo '<section class="al-card"><div class="al-card-head"><h2>' . esc_html__( 'Recent activity', 'aluleth-smtp' ) . '</h2><a href="' . esc_url( admin_url( 'admin.php?page=aluleth-contacts' ) ) . '">' . esc_html__( 'All contacts', 'aluleth-smtp' ) . '</a></div>';
		if ( ! $items ) {
			echo '<p class="al-muted">' . esc_html__( 'Nothing yet.', 'aluleth-smtp' ) . '</p></section>';
			return;
		}
		echo '<ol class="al-activity">';
		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			$text = '' !== $item['who'] ? '<b>' . esc_html( $item['who'] ) . '</b> · ' . esc_html( $item['what'] ) : esc_html( $item['what'] );
			echo wp_kses( '<li><span class="al-activity-when">' . esc_html( self::short_time( $item['time'] ) ) . '</span><span>' . ( $item['url'] ? '<a href="' . esc_url( $item['url'] ) . '">' . $text . '</a>' : $text ) . '</span></li>', Admin::allowed_html() );
		}
		echo '</ol></section>';
	}

	/**
	 * Time of the day today, "Yesterday", or a date.
	 *
	 * @param int $time Timestamp.
	 * @return string
	 */
	public static function short_time( $time ) {
		if ( wp_date( 'Ymd', $time ) === wp_date( 'Ymd' ) ) {
			return wp_date( get_option( 'time_format' ), $time );
		}
		if ( wp_date( 'Ymd', $time ) === wp_date( 'Ymd', time() - DAY_IN_SECONDS ) ) {
			return __( 'Yesterday', 'aluleth-smtp' );
		}
		return wp_date( 'j M', $time );
	}

	/**
	 * The Aluleth tools of the site.
	 *
	 * @param array[] $tools Tools.
	 * @return void
	 */
	private static function tools( array $tools ) {
		echo '<section class="al-card al-tools"><h2>' . esc_html__( 'Your Aluleth tools', 'aluleth-smtp' ) . '</h2><ul>';
		foreach ( $tools as $tool ) {
			$active = 'active' === $tool['state'];
			echo '<li><span><b>' . esc_html( $tool['name'] ) . '</b><span>' . esc_html( $tool['text'] ) . '</span></span><span class="al-tool-state' . ( $active ? ' is-active' : '' ) . '">' . esc_html( $active ? __( 'Active', 'aluleth-smtp' ) : __( 'Soon', 'aluleth-smtp' ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}
}
