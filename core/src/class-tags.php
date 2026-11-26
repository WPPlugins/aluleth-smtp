<?php
/**
 * Tags shared by every Aluleth plugin.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Tags service.
 */
final class Tags {

	/**
	 * Returns the id of a tag, creating it when needed.
	 *
	 * @param string $title Tag title (its slug is derived from it).
	 * @return int
	 */
	public function ensure( $title ) {
		global $wpdb;
		$title = substr( sanitize_text_field( (string) $title ), 0, 190 );
		$slug  = Util::slug( $title );
		if ( '' === $slug ) {
			return 0;
		}
		$table = $wpdb->prefix . 'aluleth_tags';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', $table, $slug ) );
		if ( ! $id ) {
			$wpdb->insert(
				$table,
				array(
					'slug'  => $slug,
					'title' => $title,
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Adds tags to a contact.
	 *
	 * @param int      $contact_id Contact id.
	 * @param string[] $titles     Tag titles.
	 * @return void
	 */
	public function add( $contact_id, array $titles ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aluleth_contact_tags';
		foreach ( $titles as $title ) {
			$tag_id = $this->ensure( $title );
			if ( ! $tag_id ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has = $wpdb->get_var( $wpdb->prepare( 'SELECT tag_id FROM %i WHERE contact_id = %d AND tag_id = %d', $table, (int) $contact_id, $tag_id ) );
			if ( ! $has ) {
				$wpdb->insert(
					$table,
					array(
						'contact_id' => (int) $contact_id,
						'tag_id'     => $tag_id,
						'created_at' => Util::now(),
					)
				);
				aluleth_core()->events()->record(
					'contact.tagged',
					array(
						'contact' => (int) $contact_id,
						'object'  => array( 'tag', $tag_id ),
						'data'    => array( 'tag' => $title ),
					)
				);
			}
			// phpcs:enable
		}
	}

	/**
	 * All tag titles, for pickers.
	 *
	 * @param int $limit Maximum number of tags.
	 * @return string[]
	 */
	public function all( $limit = 500 ) {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}aluleth_tags ORDER BY title LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Tags of a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @return string[] Titles.
	 */
	public function of( $contact_id ) {
		global $wpdb;
		return (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT t.title FROM {$wpdb->prefix}aluleth_tags t INNER JOIN {$wpdb->prefix}aluleth_contact_tags ct ON ct.tag_id = t.id WHERE ct.contact_id = %d ORDER BY t.title",
				(int) $contact_id
			)
		);
	}
}
