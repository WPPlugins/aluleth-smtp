<?php
/**
 * Aluleth › Settings › Access: what each WordPress role can do in the Aluleth
 * plugins. Administrators can always do everything.
 *
 * Each area is a capability named "aluleth_{area}" (see Admin::cap()), given
 * on the fly through the user_has_cap filter: no role is changed in the
 * database, so nothing is left behind when the plugins are deleted.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Role access.
 */
final class Access {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_access';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
	}

	/**
	 * Areas, in display order. Plugins add theirs with the "aluleth/access/areas" filter.
	 *
	 * @return array<string, array{label: string, default: string[], help?: string}>
	 */
	public static function areas() {
		$areas = array(
			'contacts' => array(
				'label'   => __( 'See contacts and export them', 'aluleth-smtp' ),
				'default' => array(),
			),
			'settings' => array(
				'label'   => __( 'Settings and connections', 'aluleth-smtp' ),
				'default' => array(),
				'help'    => __( 'Includes API keys of your tools: give it only to people you trust.', 'aluleth-smtp' ),
			),
		);
		/**
		 * Filters the access areas shown in Aluleth › Settings › Access.
		 *
		 * @param array $areas Area id => label, default roles, optional help.
		 */
		return (array) apply_filters( 'aluleth/access/areas', $areas );
	}

	/**
	 * Roles that can be given access (every role but the administrator).
	 *
	 * @return array<string, string> Role => translated name.
	 */
	public static function roles() {
		$out = array();
		foreach ( wp_roles()->role_names as $role => $name ) {
			if ( 'administrator' === $role || 'subscriber' === $role || 'customer' === $role ) {
				continue;
			}
			$out[ $role ] = translate_user_role( $name );
		}
		return $out;
	}

	/**
	 * Saved choices merged with the defaults.
	 *
	 * @return array<string, string[]> Area => roles.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::areas() as $id => $area ) {
			$out[ $id ] = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? array_values( array_map( 'strval', $saved[ $id ] ) ) : (array) $area['default'];
		}
		return $out;
	}

	/**
	 * Gives the Aluleth capabilities to the roles chosen for each area.
	 *
	 * @param array    $allcaps All capabilities of the user.
	 * @param string[] $caps    Primitive capabilities asked for.
	 * @param array    $args    Arguments (the capability asked for first).
	 * @param \WP_User $user    User.
	 * @return array
	 */
	public static function grant( $allcaps, $caps, $args, $user ) {
		$wanted = false;
		foreach ( (array) $caps as $cap ) {
			if ( is_string( $cap ) && ( 0 === strpos( $cap, 'aluleth_' ) || false !== strpos( $cap, '_aluleth_form' ) ) ) {
				$wanted = true;
				break;
			}
		}
		if ( ! $wanted || ! $user instanceof \WP_User ) {
			return $allcaps;
		}
		$admin  = ! empty( $allcaps['manage_options'] ) || ( is_multisite() && is_super_admin( $user->ID ) );
		$roles  = (array) $user->roles;
		$access = self::get();
		$has    = array();
		foreach ( array_keys( self::areas() ) as $area ) {
			$has[ $area ] = $admin || (bool) array_intersect( $roles, isset( $access[ $area ] ) ? $access[ $area ] : array() );
		}
		// Managing entries includes seeing them.
		if ( ! empty( $has['entries_manage'] ) ) {
			$has['entries'] = true;
		}
		foreach ( $has as $area => $yes ) {
			if ( $yes ) {
				$allcaps[ 'aluleth_' . $area ] = true;
			}
		}
		if ( $admin || in_array( true, $has, true ) ) {
			$allcaps['aluleth_menu'] = true;
		}
		if ( $admin || ! empty( $has['entries'] ) || ! empty( $has['contacts'] ) ) {
			$allcaps['aluleth_dashboard'] = true;
		}
		// The forms post type (capability type "aluleth_form").
		if ( ! empty( $has['forms'] ) ) {
			foreach ( array( 'edit', 'edit_others', 'edit_published', 'edit_private', 'publish', 'read_private', 'delete', 'delete_others', 'delete_published', 'delete_private' ) as $action ) {
				$allcaps[ $action . '_aluleth_forms' ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Renders the Access section.
	 *
	 * @return void
	 */
	public static function render() {
		$areas  = self::areas();
		$roles  = self::roles();
		$access = self::get();
		echo '<section class="al-card al-sec" id="roles"><div class="al-sec-head"><div><h2>' . esc_html__( 'What each role can do', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Administrators can always do everything. People only see the screens they are allowed to use.', 'aluleth-smtp' ) . '</p></div></div>';
		echo '<div class="al-matrix-wrap"><table class="al-matrix"><thead><tr><th scope="col">' . esc_html__( 'What they can do', 'aluleth-smtp' ) . '</th><th scope="col">' . esc_html( translate_user_role( 'Administrator' ) ) . '</th>';
		foreach ( $roles as $name ) {
			echo '<th scope="col">' . esc_html( $name ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $areas as $id => $area ) {
			echo '<tr><th scope="row">' . esc_html( $area['label'] );
			if ( ! empty( $area['help'] ) ) {
				echo '<small>' . esc_html( $area['help'] ) . '</small>';
			}
			echo '</th><td><input type="checkbox" checked disabled aria-label="' . esc_attr( sprintf( /* translators: 1: role, 2: what the role can do. */ __( '%1$s: %2$s', 'aluleth-smtp' ), translate_user_role( 'Administrator' ), $area['label'] ) ) . '"></td>';
			foreach ( $roles as $role => $name ) {
				echo '<td><input type="checkbox" name="access[' . esc_attr( $id ) . '][]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, $access[ $id ], true ), true, false ) . ' aria-label="' . esc_attr( sprintf( /* translators: 1: role, 2: what the role can do. */ __( '%1$s: %2$s', 'aluleth-smtp' ), $name, $area['label'] ) ) . '"></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';

		/**
		 * Fires after the roles table (Aluleth Forms adds its AI agents card here).
		 */
		do_action( 'aluleth/settings/access' );
	}

	/**
	 * Saves the Access section.
	 *
	 * @param array $post Unslashed $_POST.
	 * @return string Notice.
	 */
	public static function save( array $post ) {
		$roles = array_keys( self::roles() );
		$raw   = isset( $post['access'] ) && is_array( $post['access'] ) ? $post['access'] : array();
		$out   = array();
		foreach ( array_keys( self::areas() ) as $area ) {
			$chosen       = isset( $raw[ $area ] ) && is_array( $raw[ $area ] ) ? array_map( 'sanitize_key', $raw[ $area ] ) : array();
			$out[ $area ] = array_values( array_intersect( $roles, $chosen ) );
		}
		update_option( self::OPTION, $out, true );
		/**
		 * Fires when the Access section is saved (Aluleth Forms saves its AI agents choices).
		 *
		 * @param array $post Unslashed $_POST.
		 */
		do_action( 'aluleth/settings/access_save', $post );
		return '';
	}
}
