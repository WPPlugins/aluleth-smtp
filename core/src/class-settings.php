<?php
/**
 * Aluleth › Settings: one page with a menu on the left. Each section
 * (Security, Data and privacy, Licences…) has its own screen and saves on
 * its own. Products add sections with the "aluleth/settings/sections" filter.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen.
 */
final class Settings {

	/**
	 * Page slug.
	 */
	const PAGE = 'aluleth-settings';

	/**
	 * Option: delete the shared data when the last Aluleth plugin is deleted.
	 */
	const CORE_OPTION = 'aluleth_core_delete_data';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 100 );
		add_action( 'admin_post_aluleth_settings', array( __CLASS__, 'save' ) );
	}

	/**
	 * Adds the page at the end of the Aluleth menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Admin::MENU, __( 'Settings', 'aluleth-smtp' ), __( 'Settings', 'aluleth-smtp' ), Admin::cap( 'settings' ), self::PAGE, array( __CLASS__, 'screen' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @param string $section Section id ('' = the first one).
	 * @return string
	 */
	public static function url( $section = '' ) {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $section ) {
			$args['section'] = $section;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Sections of the page, in menu order.
	 *
	 * Each: label, render (callable printing the fields, inside the form),
	 * save (callable receiving the unslashed $_POST, returning a notice or
	 * 'error:…'), optional anchors (sub-items: anchor => label), optional
	 * url (a menu item that opens another screen) and form (false: the
	 * section prints its own forms).
	 *
	 * @return array<string, array>
	 */
	public static function sections() {
		$sections = array(
			'security' => array(
				'label'   => __( 'Security', 'aluleth-smtp' ),
				'intro'   => __( 'Keep spam out of your entries. These settings apply to every form.', 'aluleth-smtp' ),
				'render'  => array( Security::class, 'render' ),
				'save'    => array( Security::class, 'save' ),
				'anchors' => array(
					'builtin'   => __( 'Built-in protection', 'aluleth-smtp' ),
					'captcha'   => __( 'Captcha', 'aluleth-smtp' ),
					'blocklist' => __( 'Blocklist and Akismet', 'aluleth-smtp' ),
				),
			),
		);
		/**
		 * Filters the sections of Aluleth › Settings.
		 *
		 * @param array $sections Sections (see Settings::sections()).
		 */
		$sections = (array) apply_filters( 'aluleth/settings/sections', $sections );

		/**
		 * Filters whether the Payments section is shown (Aluleth Forms turns it on).
		 *
		 * @param bool $show Show it.
		 */
		if ( apply_filters( 'aluleth/settings/payments', false ) ) {
			$sections['payments'] = array(
				'label'   => __( 'Payments', 'aluleth-smtp' ),
				'intro'   => __( 'Take payments in your forms with your own PayPal account.', 'aluleth-smtp' ),
				'render'  => array( PayPal::class, 'render' ),
				'save'    => array( PayPal::class, 'save' ),
				/**
				 * Filters the anchors of the Payments section (add-ons add their payment methods).
				 *
				 * @param array $anchors Anchor => label.
				 */
				'anchors' => (array) apply_filters(
					'aluleth/settings/payments_anchors',
					array(
						'paypal'   => __( 'PayPal', 'aluleth-smtp' ),
						'checkout' => __( 'Payment options', 'aluleth-smtp' ),
					)
				),
			);
		}

		// Who can do what: site administrators only, even when another role may change the other settings.
		if ( current_user_can( 'manage_options' ) ) {
			$sections['access'] = array(
				'label'   => __( 'Access', 'aluleth-smtp' ),
				'intro'   => __( 'Choose what each WordPress role can do. Administrators can always do everything.', 'aluleth-smtp' ),
				'render'  => array( Access::class, 'render' ),
				'save'    => array( Access::class, 'save' ),
				'anchors' => array( 'roles' => __( 'Roles', 'aluleth-smtp' ) ),
			);
		}
		/**
		 * Filters the anchors of the Access section (Aluleth Forms adds "AI agents").
		 *
		 * @param array $anchors Anchor => label.
		 */
		if ( isset( $sections['access'] ) ) {
			$sections['access']['anchors'] = (array) apply_filters( 'aluleth/settings/access_anchors', $sections['access']['anchors'] );
		}

		$sections['connections'] = array(
			'label' => __( 'Connections', 'aluleth-smtp' ),
			'url'   => Connections::url(),
		);
		$sections['data']        = array(
			'label'  => __( 'Data and privacy', 'aluleth-smtp' ),
			'intro'  => __( 'What happens to your data when you delete a plugin.', 'aluleth-smtp' ),
			'render' => array( __CLASS__, 'render_data' ),
			'save'   => array( __CLASS__, 'save_data' ),
		);
		if ( has_action( 'aluleth/settings/top' ) ) {
			$sections['licences'] = array(
				'label'  => __( 'Licences', 'aluleth-smtp' ),
				'intro'  => __( 'Licences of your Aluleth Pro add-ons.', 'aluleth-smtp' ),
				'form'   => false,
				/**
				 * Fires in the Licences section (the Pro add-ons show their licences here).
				 */
				'render' => static function () {
					do_action( 'aluleth/settings/top' );
				},
			);
		}
		return $sections;
	}

	/**
	 * Current section id.
	 *
	 * @param array $sections Sections.
	 * @return string
	 */
	private static function current( array $sections ) {
		$asked = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $sections[ $asked ] ) && empty( $sections[ $asked ]['url'] ) ) {
			return $asked;
		}
		foreach ( $sections as $id => $section ) {
			if ( empty( $section['url'] ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function screen() {
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'aluleth-smtp' ) );
		}
		$sections = self::sections();
		$current  = self::current( $sections );
		$section  = $sections[ $current ];
		$notice   = get_transient( 'aluleth_settings_notice_' . get_current_user_id() );
		delete_transient( 'aluleth_settings_notice_' . get_current_user_id() );

		echo '<div class="wrap al-wrap al-settings"><div class="al-settings-layout">';
		echo '<aside class="al-settings-nav"><strong class="al-settings-nav-title">' . esc_html__( 'Settings', 'aluleth-smtp' ) . '</strong><nav aria-label="' . esc_attr__( 'Settings sections', 'aluleth-smtp' ) . '"><ul>';
		foreach ( $sections as $id => $item ) {
			$is   = $id === $current;
			$href = ! empty( $item['url'] ) ? $item['url'] : self::url( $id );
			echo '<li><a href="' . esc_url( $href ) . '"' . ( $is ? ' class="is-current" aria-current="page"' : '' ) . '>' . esc_html( $item['label'] ) . '</a>';
			if ( $is && ! empty( $item['anchors'] ) ) {
				echo '<ul class="al-settings-sub">';
				foreach ( $item['anchors'] as $anchor => $label ) {
					echo '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $label ) . '</a></li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul></nav></aside>';

		echo '<div class="al-settings-main">';
		echo '<div class="al-head"><div><span class="al-kicker">' . esc_html__( 'Settings', 'aluleth-smtp' ) . '</span><h1 class="al-title">' . esc_html( $section['label'] ) . '</h1>';
		if ( ! empty( $section['intro'] ) ) {
			echo '<p class="al-lead">' . esc_html( $section['intro'] ) . '</p>';
		}
		echo '</div></div>';
		if ( is_string( $notice ) && '' !== $notice ) {
			$error = 0 === strpos( $notice, 'error:' );
			echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( $error ? substr( $notice, 6 ) : $notice ) . '</p></div>';
		}
		$form = ! isset( $section['form'] ) || false !== $section['form'];
		if ( $form ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-settings-form">';
			wp_nonce_field( 'aluleth_settings' );
			echo '<input type="hidden" name="action" value="aluleth_settings"><input type="hidden" name="section" value="' . esc_attr( $current ) . '">';
		}
		if ( isset( $section['render'] ) && is_callable( $section['render'] ) ) {
			call_user_func( $section['render'] );
		}
		if ( $form ) {
			echo '<div class="al-settings-save"><button type="submit" class="al-button">' . esc_html__( 'Save changes', 'aluleth-smtp' ) . '</button></div></form>';
		}
		echo '</div></div></div>';
	}

	/**
	 * Saves a section (admin-post).
	 *
	 * @return void
	 */
	public static function save() {
		check_admin_referer( 'aluleth_settings' );
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'aluleth-smtp' ) );
		}
		$id       = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$sections = self::sections();
		if ( ! isset( $sections[ $id ]['save'] ) || ! is_callable( $sections[ $id ]['save'] ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		// Every value is plain text (line breaks kept for the lists typed one per line); each section then checks its own fields again (numbers, keys, lists).
		$post   = map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' );
		$notice = (string) call_user_func( $sections[ $id ]['save'], $post );
		set_transient( 'aluleth_settings_notice_' . get_current_user_id(), '' !== $notice ? $notice : __( 'Settings saved.', 'aluleth-smtp' ), MINUTE_IN_SECONDS );
		$back = self::url( $id );
		if ( isset( $_POST['check'] ) ) {
			$back = add_query_arg( 'service', sanitize_key( wp_unslash( $_POST['check'] ) ), $back ) . '#captcha';
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * "Delete on uninstall" choices: one per plugin, then the shared data.
	 *
	 * @return array<string, array{option: string, label: string, help: string}>
	 */
	public static function uninstall_choices() {
		/**
		 * Filters the "delete my data when this plugin is deleted" choices.
		 * Each plugin adds its own: key => option, label, help.
		 *
		 * @param array $choices Choices.
		 */
		$choices = (array) apply_filters( 'aluleth/settings/uninstall', array() );
		$out     = array();
		foreach ( $choices as $key => $choice ) {
			if ( is_array( $choice ) && ! empty( $choice['option'] ) && ! empty( $choice['label'] ) ) {
				$out[ sanitize_key( $key ) ] = array(
					'option' => sanitize_key( $choice['option'] ),
					'label'  => (string) $choice['label'],
					'help'   => isset( $choice['help'] ) ? (string) $choice['help'] : '',
				);
			}
		}
		$out['core'] = array(
			'option' => self::CORE_OPTION,
			'label'  => __( 'Contacts, consents, connections and send log', 'aluleth-smtp' ),
			'help'   => __( 'Shared by every Aluleth plugin, so they are deleted only with the last one.', 'aluleth-smtp' ),
		);
		return $out;
	}

	/**
	 * Data and privacy section.
	 *
	 * @return void
	 */
	public static function render_data() {
		echo '<section class="al-card al-sec"><h2>' . esc_html__( 'When you delete a plugin', 'aluleth-smtp' ) . '</h2>';
		echo '<p>' . esc_html__( 'By default your data is kept, so nothing is lost if you delete a plugin and install it again. Tick what should be erased for good when you delete the plugin from the Plugins screen. Deactivating a plugin never deletes anything.', 'aluleth-smtp' ) . '</p>';
		echo '<fieldset class="al-checks"><legend class="screen-reader-text">' . esc_html__( 'Delete on uninstall', 'aluleth-smtp' ) . '</legend>';
		foreach ( self::uninstall_choices() as $key => $choice ) {
			$id = 'al-del-' . $key;
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="delete[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) get_option( $choice['option'] ), true, false ) . '> <span><strong>' . esc_html( $choice['label'] ) . '</strong>';
			if ( '' !== $choice['help'] ) {
				echo '<small>' . esc_html( $choice['help'] ) . '</small>';
			}
			echo '</span></label>';
		}
		echo '</fieldset></section>';

		$texts = array();
		foreach ( array_keys( Security::services() ) as $service ) {
			if ( Security::ready( $service ) ) {
				$texts[] = Security::privacy_text( $service );
			}
		}
		if ( PayPal::ready() ) {
			$texts[] = PayPal::privacy_text();
		}
		if ( ! empty( Security::get()['akismet'] ) && Security::akismet_ready() ) {
			$texts[] = __( 'Akismet (Automattic) receives the answers, the email address and the visitor\'s IP address of each entry to check it for spam.', 'aluleth-smtp' );
		}
		/**
		 * Sentences for the privacy policy about the outside services an add-on uses.
		 *
		 * @param string[] $texts Sentences.
		 */
		$texts = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'aluleth/settings/privacy', $texts ) ) ) );
		if ( $texts ) {
			echo '<section class="al-card al-sec"><h2>' . esc_html__( 'For your privacy policy', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'The outside services you turned on receive some visitor data:', 'aluleth-smtp' ) . '</p><ul class="al-list">';
			foreach ( $texts as $text ) {
				echo '<li>' . esc_html( $text ) . '</li>';
			}
			echo '</ul></section>';
		}
	}

	/**
	 * Saves the Data and privacy section.
	 *
	 * @param array $post Unslashed $_POST.
	 * @return string
	 */
	public static function save_data( array $post ) {
		$ticked = isset( $post['delete'] ) && is_array( $post['delete'] ) ? array_map( 'sanitize_key', array_keys( $post['delete'] ) ) : array();
		foreach ( self::uninstall_choices() as $key => $choice ) {
			if ( in_array( $key, $ticked, true ) ) {
				update_option( $choice['option'], '1', false );
			} else {
				delete_option( $choice['option'] );
			}
		}
		return '';
	}
}
