<?php
/**
 * Aluleth SMTP tables: one row per email (the sending queue and the log) and the events services report (delivered, bounced…).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	/**
	 * Database version.
	 */
	const DB_VERSION = '0.3.0';

	/**
	 * Name of the table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'alsmtp_mail';
	}

	/**
	 * Name of the events table.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . 'alsmtp_events';
	}

	/**
	 * Creates or updates the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  run_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  priority tinyint(3) unsigned NOT NULL DEFAULT 5,
  source varchar(60) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'transactional',
  connection varchar(40) NOT NULL DEFAULT '',
  fallback tinyint(1) unsigned NOT NULL DEFAULT 0,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  recipients varchar(500) NOT NULL DEFAULT '',
  recipient_count int(10) unsigned NOT NULL DEFAULT 0,
  subject varchar(255) NOT NULL DEFAULT '',
  message_id varchar(190) NOT NULL DEFAULT '',
  response text NULL,
  steps text NULL,
  payload longtext NULL,
  keep varchar(10) NOT NULL DEFAULT 'full',
  outcome varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY status_run (status,run_at),
  KEY created_at (created_at),
  KEY source (source),
  KEY message_id (message_id)
) $charset;"
		);
		$events = self::events_table();
		dbDelta(
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  connection varchar(40) NOT NULL DEFAULT '',
  mail_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  detail varchar(500) NOT NULL DEFAULT '',
  ref char(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY ref (ref),
  KEY email_type (email,type,created_at),
  KEY created_at (created_at)
) $charset;"
		);
		// Connections saved before 0.3.4 get the secret key of their event address here.
		foreach ( array_keys( Connections::all() ) as $id ) {
			Hooks::token( (string) $id, true );
		}
		update_option( 'aluleth_smtp_db_version', self::DB_VERSION, false );
		if ( ! get_option( 'aluleth_smtp_since' ) ) {
			update_option( 'aluleth_smtp_since', wp_date( 'Y-m-d' ), false );
		}
		Queue::schedule();
	}
}
