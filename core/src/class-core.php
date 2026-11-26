<?php
/**
 * Aluleth Core service container.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point of the shared core. Products reach every service through it.
 */
final class Core {

	/**
	 * Database schema version of the core tables.
	 */
	const DB_VERSION = '0.3.0';

	/**
	 * Single instance.
	 *
	 * @var Core|null
	 */
	private static $instance = null;

	/**
	 * Service objects, created on first use.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the instance.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks the core into WordPress.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'maybe_install' ), 1 );
		Privacy::register();
		Queue::register();
		Integrations::register();
		Access::register();
		if ( is_admin() ) {
			Admin::register();
			Connections::register();
			Settings::register();
		}

		/**
		 * Fires once the Aluleth core is loaded. Products hook here to register
		 * their triggers, actions and admin pages.
		 *
		 * @param Core $core The core.
		 */
		do_action( 'aluleth/core/loaded', $this );
	}

	/**
	 * Core version that is running.
	 *
	 * @return string
	 */
	public function version() {
		return defined( 'ALULETH_CORE_VERSION' ) ? ALULETH_CORE_VERSION : '0.0.0';
	}

	/**
	 * Whether the running core is at least the given version.
	 *
	 * @param string $version Minimum version.
	 * @return bool
	 */
	public function at_least( $version ) {
		return version_compare( $this->version(), $version, '>=' );
	}

	/**
	 * Creates or upgrades the core tables when needed.
	 *
	 * @return void
	 */
	public function maybe_install() {
		if ( get_option( 'aluleth_core_db_version' ) !== self::DB_VERSION ) {
			Installer::install();
		}
	}

	/**
	 * Contacts service.
	 *
	 * @return Contacts
	 */
	public function contacts() {
		return $this->service( 'contacts', Contacts::class );
	}

	/**
	 * Tags service.
	 *
	 * @return Tags
	 */
	public function tags() {
		return $this->service( 'tags', Tags::class );
	}

	/**
	 * Events service.
	 *
	 * @return Events
	 */
	public function events() {
		return $this->service( 'events', Events::class );
	}

	/**
	 * Consents service.
	 *
	 * @return Consents
	 */
	public function consents() {
		return $this->service( 'consents', Consents::class );
	}

	/**
	 * Mail service.
	 *
	 * @return Mail
	 */
	public function mail() {
		return $this->service( 'mail', Mail::class );
	}

	/**
	 * Triggers, conditions and actions registry.
	 *
	 * @return Automation
	 */
	public function automation() {
		return $this->service( 'automation', Automation::class );
	}

	/**
	 * Returns a service, creating it on first use.
	 *
	 * @param string $key   Service key.
	 * @param string $class_name Class name.
	 * @return object
	 */
	private function service( $key, $class_name ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = new $class_name();
		}
		return $this->services[ $key ];
	}
}
