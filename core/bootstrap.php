<?php
/**
 * Aluleth Core bootstrap: autoloader and the aluleth_core() accessor.
 *
 * @package Aluleth\Core
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Aluleth\\Core\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = ALULETH_CORE_PATH . '/src/class-' . strtolower( str_replace( array( '\\', '_' ), array( '/', '-' ), $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

if ( ! function_exists( 'aluleth_core' ) ) {
	/**
	 * Returns the running core.
	 *
	 * @return \Aluleth\Core\Core
	 */
	function aluleth_core() {
		return \Aluleth\Core\Core::instance();
	}
}

\Aluleth\Core\Core::instance()->boot();
