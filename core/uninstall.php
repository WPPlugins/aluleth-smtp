<?php
/**
 * Aluleth Core clean-up, used by each plugin's uninstall.php and deactivation.
 *
 * Loaded on its own (the core is not booted while a plugin is uninstalled).
 * The shared data (contacts, consents, connections, send log) is deleted only
 * when the site owner asked for it in Aluleth › Settings and no other Aluleth
 * plugin is left on the site.
 *
 * @package Aluleth\Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'aluleth_core_other_plugins' ) ) {

	/**
	 * Other plugins that bundle the Aluleth core.
	 *
	 * @param string $self_dir    Folder of the plugin calling (ignored).
	 * @param bool   $active_only Only the active ones.
	 * @return string[] Plugin folder names.
	 */
	function aluleth_core_other_plugins( $self_dir, $active_only = false ) {
		$self   = basename( (string) $self_dir );
		$others = array();
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*/core/loader.php' ) as $loader ) {
			$folder = basename( dirname( dirname( $loader ) ) );
			if ( $folder === $self ) {
				continue;
			}
			$others[] = $folder;
		}
		if ( ! $active_only || ! $others ) {
			return $others;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active_folders = array_map(
			static function ( $file ) {
				return dirname( (string) $file );
			},
			$active
		);
		return array_values( array_intersect( $others, $active_folders ) );
	}

	/**
	 * Stops the core's scheduled tasks when no other Aluleth plugin is active.
	 *
	 * @param string $self_dir Folder of the plugin being deactivated.
	 * @return void
	 */
	function aluleth_core_deactivate( $self_dir ) {
		if ( aluleth_core_other_plugins( $self_dir, true ) ) {
			return;
		}
		wp_clear_scheduled_hook( 'aluleth_core_daily' );
		wp_clear_scheduled_hook( 'aluleth_core_queue' );
		wp_clear_scheduled_hook( 'aluleth_core_license_check' );
	}

	/**
	 * Deletes the shared data of the current site, if asked and if this is the last Aluleth plugin.
	 *
	 * @param string $self_dir Folder of the plugin being deleted.
	 * @return void
	 */
	function aluleth_core_uninstall( $self_dir ) {
		global $wpdb;
		wp_clear_scheduled_hook( 'aluleth_core_daily' );
		wp_clear_scheduled_hook( 'aluleth_core_queue' );
		wp_clear_scheduled_hook( 'aluleth_core_license_check' );
		if ( ! get_option( 'aluleth_core_delete_data' ) || aluleth_core_other_plugins( $self_dir ) ) {
			return;
		}
		$names = array( 'contacts', 'contact_meta', 'identities', 'tags', 'contact_tags', 'lists', 'contact_lists', 'events', 'consents', 'suppression', 'queue' );
		foreach ( $names as $name ) {
			// Current names, and the names used before core 0.18.0.
			foreach ( array( 'aluleth_', 'al_' ) as $prefix ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $prefix . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
		// Connections (API keys), import jobs and cached lists from the outside tools.
		$like = array( 'aluleth\_connection\_%', 'aluleth\_core\_import\_%', '\_transient\_aluleth\_%', '\_transient\_timeout\_aluleth\_%' );
		foreach ( $like as $pattern ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( 'aluleth_core_db_version' );
		delete_option( 'aluleth_core_delete_data' );
		delete_option( 'aluleth_security' );
		delete_option( 'aluleth_access' );
		delete_option( 'aluleth_payments' );

		// Files waiting to be imported.
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'aluleth-imports';
		if ( is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( is_file( $dir . '/' . $name ) ) {
					wp_delete_file( $dir . '/' . $name );
				}
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own empty folder.
		}
	}
}
