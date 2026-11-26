<?php
/**
 * Contacts: one row per person, shared by every Aluleth plugin.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Contacts service.
 */
final class Contacts {

	/**
	 * Statuses a contact can have.
	 */
	const STATUSES = array( 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained', 'transactional' );

	/**
	 * Standard columns that upsert() can write.
	 */
	const COLUMNS = array( 'first_name', 'last_name', 'phone', 'language', 'country', 'timezone' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'aluleth_contacts';
	}

	/**
	 * Creates the contact or merges new data into the existing one.
	 *
	 * Rules: empty values never erase existing ones; a contact who
	 * unsubscribed, bounced or complained is never subscribed again unless
	 * `$context['resubscribe']` is true (a confirmed re-subscription): a form
	 * anyone can send must not be able to subscribe someone else.
	 *
	 * @param string $email   Email address.
	 * @param array  $data    Columns (first_name, last_name, phone…) and 'meta' => [ key => value ].
	 * @param array  $context 'source' (e.g. 'aluleth-forms'), 'consent' (bool), 'status' (wanted status),
	 *                        'fill_only' (bool: only fill columns and fields that are still empty),
	 *                        'resubscribe' (bool: allow subscribing a contact who unsubscribed, bounced or complained),
	 *                        'link_user' (bool, default true: link a new contact to the WordPress user with this email).
	 * @return int Contact id, or 0 when the email is not valid.
	 */
	public function upsert( $email, array $data = array(), array $context = array() ) {
		global $wpdb;

		$email = Util::email( $email );
		if ( '' === $email ) {
			return 0;
		}

		$now      = Util::now();
		$existing = $this->get_by_email( $email );
		$columns  = array();
		foreach ( self::COLUMNS as $column ) {
			if ( isset( $data[ $column ] ) && '' !== trim( (string) $data[ $column ] ) ) {
				$columns[ $column ] = substr( sanitize_text_field( (string) $data[ $column ] ), 0, 'phone' === $column ? 50 : 100 );
			}
		}

		$wanted = isset( $context['status'] ) && in_array( $context['status'], self::STATUSES, true ) ? $context['status'] : '';

		$fill_only = ! empty( $context['fill_only'] );

		if ( $existing ) {
			$id     = (int) $existing->id;
			$update = $columns;
			if ( $fill_only ) {
				foreach ( array_keys( $update ) as $column ) {
					if ( '' !== (string) $existing->$column ) {
						unset( $update[ $column ] );
					}
				}
			}
			if ( $wanted ) {
				$current = $existing->status;
				$blocked = in_array( $current, array( 'unsubscribed', 'bounced', 'complained' ), true );
				// Someone who unsubscribed, bounced or complained is never subscribed again by a form or an
				// import: only an explicit re-subscription (for example a confirmed double opt-in) can do it.
				if ( 'subscribed' === $wanted && ( ! $blocked || ! empty( $context['resubscribe'] ) ) ) {
					$update['status'] = 'subscribed';
				} elseif ( 'subscribed' !== $wanted && 'transactional' !== $wanted ) {
					$update['status'] = $wanted;
				}
			}
			$update['updated_at']       = $now;
			$update['last_activity_at'] = $now;
			$wpdb->update( $this->table(), $update, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$created = false;
		} else {
			$row  = array_merge(
				$columns,
				array(
					'uuid'             => wp_generate_uuid4(),
					'email'            => $email,
					'status'           => $wanted ? $wanted : 'transactional',
					'source'           => isset( $context['source'] ) ? substr( sanitize_key( $context['source'] ), 0, 100 ) : '',
					'created_at'       => $now,
					'updated_at'       => $now,
					'last_activity_at' => $now,
				)
			);
			$user = ! isset( $context['link_user'] ) || $context['link_user'] ? get_user_by( 'email', $email ) : false;
			if ( $user ) {
				$row['user_id'] = (int) $user->ID;
			}
			$wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id      = (int) $wpdb->insert_id;
			$created = true;
			if ( $id && $user ) {
				$this->link( $id, 'wp_user', (string) $user->ID );
			}
		}

		if ( ! $id ) {
			return 0;
		}

		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$current = $fill_only && ! $created ? $this->get_meta( $id ) : array();
			foreach ( $data['meta'] as $key => $value ) {
				if ( '' !== (string) $value && ( ! isset( $current[ sanitize_key( (string) $key ) ] ) || '' === (string) $current[ sanitize_key( (string) $key ) ] ) ) {
					$this->set_meta( $id, (string) $key, $value );
				}
			}
		}

		wp_cache_delete( 'contact_' . $email, 'aluleth' );

		if ( $created ) {
			aluleth_core()->events()->record(
				'contact.created',
				array(
					'contact' => $id,
					'source'  => isset( $context['source'] ) ? $context['source'] : '',
				)
			);
		}

		return $id;
	}

	/**
	 * Finds a contact by email.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public function get_by_email( $email ) {
		global $wpdb;
		$email = Util::email( $email );
		if ( '' === $email ) {
			return null;
		}
		$row = wp_cache_get( 'contact_' . $email, 'aluleth' );
		if ( false === $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aluleth_contacts WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_set( 'contact_' . $email, $row ? $row : 0, 'aluleth' );
		}
		return $row ? $row : null;
	}

	/**
	 * Finds a contact by id.
	 *
	 * @param int $id Contact id.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aluleth_contacts WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * Lists contacts for admin screens.
	 *
	 * @param array $args 'search', 'status', 'per_page', 'page'.
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$like     = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' : '';
		$status   = ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? (string) $args['status'] : '';
		$table    = $this->table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR email LIKE %s OR first_name LIKE %s OR last_name LIKE %s ) AND ( %s = '' OR status = %s )", $table, $like, $like, $like, $like, $status, $status ) );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = '' OR email LIKE %s OR first_name LIKE %s OR last_name LIKE %s ) AND ( %s = '' OR status = %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $like, $like, $like, $like, $status, $status, $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Sets a custom field value.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $key        Field key.
	 * @param mixed  $value      Value (arrays are stored as JSON).
	 * @return void
	 */
	public function set_meta( $contact_id, $key, $value ) {
		global $wpdb;
		$key   = substr( sanitize_key( $key ), 0, 100 );
		$value = is_array( $value ) ? wp_json_encode( $value ) : sanitize_textarea_field( (string) $value );
		if ( '' === $key ) {
			return;
		}
		$table = $wpdb->prefix . 'aluleth_contact_meta';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE contact_id = %d AND field_key = %s', $table, (int) $contact_id, $key ) );
		if ( $exists ) {
			$wpdb->update( $table, array( 'value' => $value ), array( 'id' => (int) $exists ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'contact_id' => (int) $contact_id,
					'field_key'  => $key,
					'value'      => $value,
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * Returns all custom field values of a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @return array<string, string>
	 */
	public function get_meta( $contact_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT field_key, value FROM {$wpdb->prefix}aluleth_contact_meta WHERE contact_id = %d", (int) $contact_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->field_key ] = $row->value;
		}
		return $out;
	}

	/**
	 * Links the contact to a record in another system.
	 *
	 * @param int    $contact_id  Contact id.
	 * @param string $system      For example wp_user, woocommerce, fluentcart or fluentcrm.
	 * @param string $external_id Id in that system.
	 * @return void
	 */
	public function link( $contact_id, $system, $external_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aluleth_identities';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE service = %s AND external_id = %s', $table, $system, $external_id ) );
		if ( ! $exists ) {
			$wpdb->insert(
				$table,
				array(
					'contact_id'  => (int) $contact_id,
					'service'     => sanitize_key( $system ),
					'external_id' => substr( (string) $external_id, 0, 100 ),
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * Deletes a contact and everything attached to it (used by the privacy eraser).
	 *
	 * @param int $contact_id Contact id.
	 * @return void
	 */
	public function delete( $contact_id ) {
		global $wpdb;
		$contact_id = (int) $contact_id;
		$contact    = $this->get( $contact_id );
		foreach ( array( 'aluleth_contact_meta', 'aluleth_identities', 'aluleth_contact_tags', 'aluleth_contact_lists', 'aluleth_events', 'aluleth_consents' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'contact_id' => $contact_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		Queue::erase( $contact_id, $contact ? (string) $contact->email : '' );
		$wpdb->delete( $this->table(), array( 'id' => $contact_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $contact ) {
			wp_cache_delete( 'contact_' . $contact->email, 'aluleth' );
		}

		/**
		 * Fires after a contact was deleted, so products can remove their own data.
		 *
		 * @param int $contact_id Contact id.
		 */
		do_action( 'aluleth/contact/deleted', $contact_id );
	}
}
