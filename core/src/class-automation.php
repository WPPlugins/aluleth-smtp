<?php
/**
 * Registry of triggers, conditions and actions shared by the suite.
 *
 * Version 0.1 only stores the definitions; the CRM's automation engine and
 * the funnel editor will read them.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Automation registry.
 */
final class Automation {

	/**
	 * Registered definitions by kind.
	 *
	 * @var array<string, array<string, array>>
	 */
	private $items = array(
		'trigger'   => array(),
		'condition' => array(),
		'action'    => array(),
	);

	/**
	 * Registers a trigger.
	 *
	 * @param string $id   Unique id, e.g. 'aluleth-forms/submitted'.
	 * @param array  $args 'label', 'group', 'event', 'settings' (JSON schema), 'provides'.
	 * @return void
	 */
	public function register_trigger( $id, array $args ) {
		$this->items['trigger'][ $id ] = $args;
	}

	/**
	 * Registers a condition.
	 *
	 * @param string $id   Unique id.
	 * @param array  $args 'label', 'settings', 'callback'.
	 * @return void
	 */
	public function register_condition( $id, array $args ) {
		$this->items['condition'][ $id ] = $args;
	}

	/**
	 * Registers an action.
	 *
	 * @param string $id   Unique id.
	 * @param array  $args 'label', 'settings', 'callback', 'async'.
	 * @return void
	 */
	public function register_action( $id, array $args ) {
		$this->items['action'][ $id ] = $args;
	}

	/**
	 * Returns the registered definitions of one kind.
	 *
	 * @param string $kind 'trigger', 'condition' or 'action'.
	 * @return array<string, array>
	 */
	public function all( $kind ) {
		return isset( $this->items[ $kind ] ) ? $this->items[ $kind ] : array();
	}
}
