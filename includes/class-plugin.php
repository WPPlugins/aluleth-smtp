<?php
/**
 * Aluleth SMTP main class.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
		add_filter( 'aluleth/settings/uninstall', array( $this, 'uninstall_choice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ALULETH_SMTP_FILE ), array( $this, 'action_links' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'eraser' ) );
		Sender::register();
		Queue::register();
		Hooks::register();
		Oauth::register();
		Blocklist::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Updates the table after an update.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'aluleth_smtp_db_version' ) !== Installer::DB_VERSION ) {
			Installer::install();
		}
	}


	/**
	 * Choice in Aluleth › Settings › Data: delete the log and connections with the plugin.
	 *
	 * @param array $choices Choices.
	 * @return array
	 */
	public function uninstall_choice( $choices ) {
		$choices['smtp'] = array(
			'option' => 'aluleth_smtp_delete_data',
			'label'  => __( 'Aluleth SMTP: connections, settings and the email log', 'aluleth-smtp' ),
			'help'   => __( 'Deleted with the plugin.', 'aluleth-smtp' ),
		);
		return $choices;
	}

	/**
	 * Links under the plugin's name on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=aluleth-smtp' ) ) . '">' . esc_html__( 'Set up', 'aluleth-smtp' ) . '</a>' );
		return $links;
	}

	/**
	 * Personal data export: the emails sent to an address.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function exporter( $exporters ) {
		$exporters['aluleth-smtp'] = array(
			'exporter_friendly_name' => __( 'Aluleth SMTP email log', 'aluleth-smtp' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Personal data erasure: the log rows of an address.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function eraser( $erasers ) {
		$erasers['aluleth-smtp'] = array(
			'eraser_friendly_name' => __( 'Aluleth SMTP email log', 'aluleth-smtp' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the log rows of an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, status, subject FROM %i WHERE recipients LIKE %s ORDER BY id LIMIT 100 OFFSET %d', Installer::table(), '%' . $wpdb->esc_like( $email ) . '%', ( max( 1, (int) $page ) - 1 ) * 100 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'aluleth-smtp',
				'group_label' => __( 'Emails sent to you', 'aluleth-smtp' ),
				'item_id'     => 'aluleth-smtp-' . $r->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'aluleth-smtp' ),
						'value' => $r->created_at,
					),
					array(
						'name'  => __( 'Subject', 'aluleth-smtp' ),
						'value' => $r->subject,
					),
					array(
						'name'  => __( 'Status', 'aluleth-smtp' ),
						'value' => $r->status,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Erases the log rows of an address (sent and failed ones; queued emails are still sent).
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$n  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE recipients LIKE %s AND status IN (%s, %s, %s)', Installer::table(), '%' . $wpdb->esc_like( $email ) . '%', 'sent', 'failed', 'blocked' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE email = %s', Installer::events_table(), strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'items_removed'  => $n,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
