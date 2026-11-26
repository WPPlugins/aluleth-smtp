<?php
/**
 * Uninstall. Nothing is deleted unless the site owner asked for it in
 * Aluleth › Settings:
 * - "Aluleth SMTP": connections, settings and the email log;
 * - shared data (contacts, consents…): only when no other Aluleth plugin is left.
 *
 * @package Aluleth\Smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/core/uninstall.php';

/**
 * Cleans the current site.
 *
 * @return void
 */
function aluleth_smtp_uninstall_site() {
	global $wpdb;
	wp_clear_scheduled_hook( 'aluleth_smtp_queue' );
	wp_clear_scheduled_hook( 'aluleth_smtp_queue_now' );
	wp_clear_scheduled_hook( 'aluleth_smtp_daily' );
	if ( get_option( 'aluleth_smtp_delete_data' ) ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'alsmtp_mail' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'alsmtp_events' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		// Short-lived data: access tokens, sign-in states, notices.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_alsmtp_' ) . '%', $wpdb->esc_like( '_transient_timeout_alsmtp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( 'aluleth_smtp_connections', 'aluleth_smtp_settings', 'aluleth_smtp_db_version', 'aluleth_smtp_since', 'aluleth_smtp_alert_pending', 'aluleth_smtp_alert_last', 'aluleth_smtp_queue_lock', 'aluleth_smtp_hooks', 'aluleth_smtp_deliverability', 'aluleth_smtp_site_key', 'aluleth_smtp_delete_data' ) as $option ) {
			delete_option( $option );
		}
		delete_metadata( 'user', 0, 'alsmtp_hide_setup', '', true );
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'aluleth-smtp';
		if ( is_dir( $dir ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$wp_filesystem->delete( $dir, true );
			}
		}
	}
	aluleth_core_uninstall( __DIR__ );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $aluleth_smtp_site ) {
		switch_to_blog( $aluleth_smtp_site );
		aluleth_smtp_uninstall_site();
		restore_current_blog();
	}
} else {
	aluleth_smtp_uninstall_site();
}
