<?php
/**
 * The shared "Aluleth" admin menu: dashboard and contacts.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Core admin screens.
 */
final class Admin {

	/**
	 * Slug of the top-level menu. Products add their pages under it.
	 */
	const MENU = 'aluleth';

	/**
	 * HTML allowed in the markup of the shared screens (escaped again when printed).
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
	 * Capability needed for an area of the suite.
	 *
	 * @param string $area For example contacts, settings or forms.
	 * @return string
	 */
	public static function cap( $area = 'settings' ) {
		/**
		 * Filters the capability needed for an Aluleth admin area.
		 *
		 * Each area is a capability "aluleth_{area}" given to administrators and to
		 * the roles chosen in Aluleth › Settings › Access (see Access::grant()).
		 * Areas: menu, dashboard, forms, entries, entries_manage, contacts, settings.
		 *
		 * @param string $cap  Capability.
		 * @param string $area Area.
		 */
		return (string) apply_filters( 'aluleth/capability', 'aluleth_' . sanitize_key( $area ), $area );
	}

	/**
	 * Hooks the menu and assets.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_late' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Import_Admin::register();
	}

	/**
	 * Adds the top-level menu and the Dashboard page.
	 *
	 * @return void
	 */
	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 2 2.5 18h3.2l1.5-3.4h5.6l1.5 3.4h3.2L10 2Zm-1.6 9.9L10 8.2l1.6 3.7H8.4Z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		add_menu_page( __( 'Aluleth', 'aluleth-smtp' ), __( 'Aluleth', 'aluleth-smtp' ), self::cap( 'dashboard' ), self::MENU, array( __CLASS__, 'dashboard' ), $icon, 26 );
		add_submenu_page( self::MENU, __( 'Dashboard', 'aluleth-smtp' ), __( 'Dashboard', 'aluleth-smtp' ), self::cap( 'dashboard' ), self::MENU, array( __CLASS__, 'dashboard' ) );
	}

	/**
	 * Adds Contacts at the end of the product pages.
	 *
	 * @return void
	 */
	public static function menu_late() {
		add_submenu_page( self::MENU, __( 'Contacts', 'aluleth-smtp' ), __( 'Contacts', 'aluleth-smtp' ), self::cap( 'contacts' ), 'aluleth-contacts', array( __CLASS__, 'contacts' ) );
	}

	/**
	 * Loads the shared admin stylesheet on Aluleth screens.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'aluleth' ) && false === strpos( (string) $hook, 'aluleth' ) ) {
			return;
		}
		wp_enqueue_style( 'aluleth-core-admin', plugins_url( 'assets/admin.css', ALULETH_CORE_PATH . '/bootstrap.php' ), array(), ALULETH_CORE_VERSION );
	}

	/**
	 * Dashboard (key numbers, chart, activity: see Dashboard).
	 *
	 * @return void
	 */
	public static function dashboard() {
		if ( ! current_user_can( self::cap( 'dashboard' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'aluleth-smtp' ) );
		}
		Dashboard::render();
	}

	/**
	 * Contacts list, or one contact's profile.
	 *
	 * @return void
	 */
	public static function contacts() {
		if ( ! current_user_can( self::cap( 'contacts' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see contacts.', 'aluleth-smtp' ) );
		}
		$core = aluleth_core();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screens.
		$id = isset( $_GET['contact'] ) ? absint( $_GET['contact'] ) : 0;
		if ( isset( $_GET['view'] ) && 'import' === $_GET['view'] ) {
			Import_Admin::screen();
			return;
		}
		if ( $id ) {
			self::profile( $id );
			return;
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$result = $core->contacts()->query(
			array(
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 25,
			)
		);

		echo '<div class="wrap al-wrap"><div class="al-head"><h1 class="al-title">' . esc_html__( 'Contacts', 'aluleth-smtp' ) . '</h1><a class="al-button" href="' . esc_url( Import_Admin::url() ) . '">' . esc_html__( 'Import contacts', 'aluleth-smtp' ) . '</a></div>';
		echo '<form method="get" class="al-search"><input type="hidden" name="page" value="aluleth-contacts"><label class="screen-reader-text" for="al-s">' . esc_html__( 'Search contacts', 'aluleth-smtp' ) . '</label><input id="al-s" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name or email', 'aluleth-smtp' ) . '"> <button class="button">' . esc_html__( 'Search', 'aluleth-smtp' ) . '</button></form>';

		if ( ! $result['items'] ) {
			echo '<div class="al-empty"><p>' . esc_html( '' !== $search ? __( 'No contact matches your search.', 'aluleth-smtp' ) : __( 'No contacts yet. They appear here when someone sends one of your forms, or import the list you had in another tool.', 'aluleth-smtp' ) ) . '</p>' . ( '' !== $search ? '' : '<p><a class="al-button" href="' . esc_url( Import_Admin::url() ) . '">' . esc_html__( 'Import contacts', 'aluleth-smtp' ) . '</a></p>' ) . '</div></div>';
			return;
		}

		echo '<table class="widefat striped al-table"><thead><tr><th>' . esc_html__( 'Name', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Email', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Status', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Last activity', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
		foreach ( $result['items'] as $contact ) {
			$url  = add_query_arg(
				array(
					'page'    => 'aluleth-contacts',
					'contact' => (int) $contact->id,
				),
				admin_url( 'admin.php' )
			);
			$name = trim( $contact->first_name . ' ' . $contact->last_name );
			echo '<tr><td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( '' !== $name ? $name : __( '(no name)', 'aluleth-smtp' ) ) . '</strong></a></td><td>' . esc_html( $contact->email ) . '</td><td><span class="al-badge al-badge--' . esc_attr( $contact->status ) . '">' . esc_html( self::status_label( $contact->status ) ) . '</span></td><td>' . esc_html( self::when( $contact->last_activity_at ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / 25 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * One contact: details, custom fields, tags and timeline.
	 *
	 * @param int $id Contact id.
	 * @return void
	 */
	private static function profile( $id ) {
		$core    = aluleth_core();
		$contact = $core->contacts()->get( $id );
		echo '<div class="wrap al-wrap">';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aluleth-contacts' ) ) . '">&larr; ' . esc_html__( 'All contacts', 'aluleth-smtp' ) . '</a></p>';
		if ( ! $contact ) {
			echo '<p>' . esc_html__( 'This contact does not exist.', 'aluleth-smtp' ) . '</p></div>';
			return;
		}
		$name = trim( $contact->first_name . ' ' . $contact->last_name );
		echo '<h1 class="al-title">' . esc_html( '' !== $name ? $name : $contact->email ) . '</h1>';
		echo '<div class="al-profile"><section class="al-card"><dl class="al-dl">';
		$rows = array(
			__( 'Email', 'aluleth-smtp' )         => $contact->email,
			__( 'Phone', 'aluleth-smtp' )         => $contact->phone,
			__( 'Status', 'aluleth-smtp' )        => self::status_label( $contact->status ),
			__( 'Contact since', 'aluleth-smtp' ) => self::when( $contact->created_at ),
		);
		foreach ( $core->contacts()->get_meta( $id ) as $key => $value ) {
			$rows[ ucfirst( str_replace( '_', ' ', $key ) ) ] = $value;
		}
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
		}
		echo '</dl>';
		$tags = $core->tags()->of( $id );
		if ( $tags ) {
			echo '<p class="al-tags">';
			foreach ( $tags as $tag ) {
				echo '<span class="al-tag">' . esc_html( $tag ) . '</span> ';
			}
			echo '</p>';
		}
		echo '</section><section class="al-card"><h2>' . esc_html__( 'History', 'aluleth-smtp' ) . '</h2><ol class="al-timeline">';
		foreach ( $core->events()->for_contact( $id ) as $event ) {
			echo '<li><strong>' . esc_html( $core->events()->label( $event ) ) . '</strong><span>' . esc_html( self::when( $event->created_at ) ) . '</span></li>';
		}
		echo '</ol></section></div></div>';
	}

	/**
	 * Translated label of a contact status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'subscribed'    => __( 'Subscribed', 'aluleth-smtp' ),
			'pending'       => __( 'Waiting for confirmation', 'aluleth-smtp' ),
			'unsubscribed'  => __( 'Unsubscribed', 'aluleth-smtp' ),
			'bounced'       => __( 'Bounced', 'aluleth-smtp' ),
			'complained'    => __( 'Marked as spam', 'aluleth-smtp' ),
			'transactional' => __( 'Not subscribed', 'aluleth-smtp' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Formats a UTC datetime in the site's time zone.
	 *
	 * @param string|null $utc MySQL datetime in UTC.
	 * @return string
	 */
	public static function when( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}
		$time = strtotime( $utc . ' UTC' );
		return $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}
}
