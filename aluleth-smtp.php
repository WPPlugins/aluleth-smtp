<?php
/**
 * Plugin Name:       Aluleth SMTP
 * Description:       Send your WordPress emails through a real email service: Amazon SES, SendGrid, Brevo, Mailgun, Postmark, Resend, Gmail, Microsoft 365 and more, with a fallback, routing rules, a sending queue with limits, a safe email log, bounce handling and deliverability checks.
 * Version:           0.3.6
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Aluleth
 * Author URI:        https://profiles.wordpress.org/aluleth/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aluleth-smtp
 * Domain Path:       /languages
 *
 * @package Aluleth\Smtp
 */

defined( 'ABSPATH' ) || exit;

define( 'ALULETH_SMTP_VERSION', '0.3.6' );
define( 'ALULETH_SMTP_FILE', __FILE__ );
define( 'ALULETH_SMTP_DIR', __DIR__ );
define( 'ALULETH_SMTP_CORE_MIN', '0.18.0' );

require_once __DIR__ . '/core/loader.php';

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Aluleth\\Smtp\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = ALULETH_SMTP_DIR . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Starts the plugin once the core is loaded.
 *
 * @return void
 */
function aluleth_smtp_boot() {
	if ( ! function_exists( 'aluleth_core' ) || ! aluleth_core()->at_least( ALULETH_SMTP_CORE_MIN ) ) {
		add_action( 'admin_notices', 'aluleth_smtp_core_notice' );
		return;
	}
	\Aluleth\Smtp\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'aluleth_smtp_boot', 10 );

/**
 * Notice on the Plugins screen when another Aluleth plugin ships an older core.
 *
 * @return void
 */
function aluleth_smtp_core_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Aluleth SMTP needs a newer version of your other Aluleth plugins. Please update them.', 'aluleth-smtp' ) . '</p></div>';
}

/**
 * Activation: tables and the minute schedule of the queue.
 *
 * @return void
 */
function aluleth_smtp_activate() {
	aluleth_core_elect();
	if ( ! function_exists( 'aluleth_core' ) ) {
		return;
	}
	\Aluleth\Core\Installer::install();
	\Aluleth\Smtp\Installer::install();
}
register_activation_hook( __FILE__, 'aluleth_smtp_activate' );

/**
 * Deactivation: the queue schedule stops; no data is deleted.
 *
 * @return void
 */
function aluleth_smtp_deactivate() {
	wp_clear_scheduled_hook( 'aluleth_smtp_queue' );
	wp_clear_scheduled_hook( 'aluleth_smtp_queue_now' );
	wp_clear_scheduled_hook( 'aluleth_smtp_daily' );
	require_once __DIR__ . '/core/uninstall.php';
	aluleth_core_deactivate( __DIR__ );
}
register_deactivation_hook( __FILE__, 'aluleth_smtp_deactivate' );
