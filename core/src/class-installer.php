<?php
/**
 * Creates and upgrades the core tables.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Core tables installer.
 */
final class Installer {

	/**
	 * Short names of the core tables.
	 */
	const NAMES = array( 'contacts', 'contact_meta', 'identities', 'tags', 'contact_tags', 'lists', 'contact_lists', 'events', 'consents', 'suppression', 'queue' );

	/**
	 * Core table names without the WordPress prefix.
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array_map(
			static function ( $name ) {
				return 'aluleth_' . $name;
			},
			self::NAMES
		);
	}

	/**
	 * Full name of a core table, e.g. table( 'contacts' ) gives wp_aluleth_contacts.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_' . $name;
	}

	/**
	 * Whether a table exists (exact name).
	 *
	 * @param string $name Full table name.
	 * @return bool
	 */
	private static function exists( $name ) {
		global $wpdb;
		// Exact names compared in PHP: some databases (SQLite) do not read an escaped LIKE pattern.
		return in_array( $name, (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Renames tables from an older name (before 0.18.0 the core tables were "al_*").
	 *
	 * Call it before creating the tables, then finish_renames() after.
	 *
	 * @param array $map Old full name => new full name.
	 * @return void
	 */
	public static function rename_tables( array $map ) {
		global $wpdb;
		foreach ( $map as $old => $new ) {
			if ( self::exists( $old ) && ! self::exists( $new ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $old, $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
	}

	/**
	 * Where a database could not rename a table (SQLite, used by WordPress Playground), copies
	 * the rows into the new, still empty table, then drops the old one once every row is there.
	 *
	 * @param array $map Old full name => new full name.
	 * @return void
	 */
	public static function finish_renames( array $map ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		foreach ( $map as $old => $new ) {
			if ( ! self::exists( $old ) || ! self::exists( $new ) ) {
				continue;
			}
			$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $old ) );
			if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new ) ) > 0 ) {
				continue;
			}
			if ( $before > 0 ) {
				$columns = array_values( array_intersect( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $old ) ), (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $new ) ) ) );
				if ( ! $columns ) {
					continue;
				}
				// One %i placeholder per column: every name goes through prepare().
				$list = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( ' . $list . ' ) SELECT ' . $list . ' FROM %i', array_merge( array( $new ), $columns, $columns, array( $old ) ) ) );
			}
			if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new ) ) === $before ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$map = array();
		foreach ( self::NAMES as $name ) {
			$map[ $wpdb->prefix . 'al_' . $name ] = self::table( $name );
		}
		self::rename_tables( $map );

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$p}aluleth_contacts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  email varchar(190) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  phone varchar(50) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'transactional',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  language varchar(20) NOT NULL DEFAULT '',
  country varchar(2) NOT NULL DEFAULT '',
  timezone varchar(50) NOT NULL DEFAULT '',
  source varchar(100) NOT NULL DEFAULT '',
  score int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  last_activity_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  UNIQUE KEY uuid (uuid),
  KEY status (status),
  KEY user_id (user_id)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_contact_meta (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL,
  field_key varchar(100) NOT NULL,
  value longtext,
  PRIMARY KEY  (id),
  UNIQUE KEY contact_field (contact_id,field_key)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_identities (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL,
  service varchar(50) NOT NULL,
  external_id varchar(100) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY service_external (service,external_id),
  KEY contact_id (contact_id)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_tags (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL,
  title varchar(190) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_contact_tags (
  contact_id bigint(20) unsigned NOT NULL,
  tag_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (contact_id,tag_id),
  KEY tag_id (tag_id)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_lists (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(100) NOT NULL,
  title varchar(190) NOT NULL,
  double_optin tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_contact_lists (
  contact_id bigint(20) unsigned NOT NULL,
  list_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'subscribed',
  created_at datetime NOT NULL,
  PRIMARY KEY  (contact_id,list_id),
  KEY list_id (list_id)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(60) NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  value decimal(16,4) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT '',
  data longtext,
  source varchar(60) NOT NULL DEFAULT '',
  session_id varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY contact_created (contact_id,created_at),
  KEY type_created (type,created_at),
  KEY object (object_type,object_id)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_consents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL,
  purpose varchar(30) NOT NULL,
  status varchar(20) NOT NULL,
  method varchar(40) NOT NULL DEFAULT '',
  text_hash char(64) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY contact_purpose (contact_id,purpose)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_suppression (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email_hash char(64) NOT NULL,
  email varchar(190) NOT NULL,
  reason varchar(30) NOT NULL,
  provider varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_hash (email_hash)
) $charset;";

		$sql[] = "CREATE TABLE {$p}aluleth_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hook varchar(60) NOT NULL,
  args longtext NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  label varchar(190) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  result varchar(255) NOT NULL DEFAULT '',
  last_error text NOT NULL,
  run_at datetime NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_run (status,run_at),
  KEY object (object_type,object_id),
  KEY contact_id (contact_id)
) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		self::finish_renames( $map );

		update_option( 'aluleth_core_db_version', Core::DB_VERSION, false );
	}
}
