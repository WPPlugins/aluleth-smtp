<?php
/**
 * Aluleth Core loader.
 *
 * Every Aluleth plugin bundles a copy of the core. Each copy registers its
 * version here when its plugin file is loaded; on `plugins_loaded` (priority
 * -90) the newest registered copy is loaded, once.
 *
 * @package Aluleth\Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'aluleth_core_register' ) ) {

	/**
	 * Registers a bundled copy of the core.
	 *
	 * @param string $version Core version of this copy.
	 * @param string $path    Absolute path of this copy's folder.
	 * @return void
	 */
	function aluleth_core_register( $version, $path ) {
		if ( ! isset( $GLOBALS['aluleth_core_versions'] ) || ! is_array( $GLOBALS['aluleth_core_versions'] ) ) {
			$GLOBALS['aluleth_core_versions'] = array();
		}
		$GLOBALS['aluleth_core_versions'][ (string) $version ] = (string) $path;
	}

	/**
	 * Loads the newest registered copy of the core. Safe to call more than once.
	 *
	 * @return void
	 */
	function aluleth_core_elect() {
		if ( defined( 'ALULETH_CORE_VERSION' ) ) {
			return;
		}
		$versions = isset( $GLOBALS['aluleth_core_versions'] ) && is_array( $GLOBALS['aluleth_core_versions'] ) ? $GLOBALS['aluleth_core_versions'] : array();
		if ( empty( $versions ) ) {
			return;
		}
		uksort( $versions, 'version_compare' );
		$path    = end( $versions );
		$version = key( $versions );

		define( 'ALULETH_CORE_VERSION', $version );
		define( 'ALULETH_CORE_PATH', $path );

		require_once $path . '/bootstrap.php';
	}

	add_action( 'plugins_loaded', 'aluleth_core_elect', -90 );
}

aluleth_core_register( '0.18.3', __DIR__ );
