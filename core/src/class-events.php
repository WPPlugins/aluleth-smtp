<?php
/**
 * Event timeline shared by every Aluleth plugin.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Events service.
 */
final class Events {

	/**
	 * Records an event and lets products react to it.
	 *
	 * @param string $type Event type, e.g. 'form.submitted' (see the catalogue in the core spec).
	 * @param array  $args 'contact' (id or email), 'object' => [ type, id ], 'value', 'currency', 'data' (array), 'source'.
	 * @return int Event id.
	 */
	public function record( $type, array $args = array() ) {
		global $wpdb;

		$type       = substr( preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) $type ) ), 0, 60 );
		$contact_id = 0;
		if ( isset( $args['contact'] ) ) {
			if ( is_numeric( $args['contact'] ) ) {
				$contact_id = (int) $args['contact'];
			} else {
				$contact    = aluleth_core()->contacts()->get_by_email( (string) $args['contact'] );
				$contact_id = $contact ? (int) $contact->id : 0;
			}
		}
		$object = isset( $args['object'] ) && is_array( $args['object'] ) ? array_values( $args['object'] ) : array( '', 0 );

		$row = array(
			'contact_id'  => $contact_id,
			'type'        => $type,
			'object_type' => substr( sanitize_key( isset( $object[0] ) ? $object[0] : '' ), 0, 40 ),
			'object_id'   => isset( $object[1] ) ? (int) $object[1] : 0,
			'value'       => isset( $args['value'] ) ? (float) $args['value'] : 0,
			'currency'    => isset( $args['currency'] ) ? substr( strtoupper( sanitize_key( $args['currency'] ) ), 0, 3 ) : '',
			'data'        => ! empty( $args['data'] ) ? wp_json_encode( $args['data'] ) : null,
			'source'      => isset( $args['source'] ) ? substr( sanitize_key( $args['source'] ), 0, 60 ) : '',
			'created_at'  => Util::now(),
		);

		$wpdb->insert( $wpdb->prefix . 'aluleth_events', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;

		$event       = (object) $row;
		$event->id   = $id;
		$event->data = isset( $args['data'] ) ? $args['data'] : array();

		/**
		 * Fires for every recorded event.
		 *
		 * @param object $event The event (id, contact_id, type, object_type, object_id, value, currency, data, source, created_at).
		 */
		do_action( 'aluleth/event', $event );

		/**
		 * Fires for one event type, e.g. `aluleth/event/form.submitted`.
		 *
		 * @param object $event The event.
		 */
		do_action( 'aluleth/event/' . $type, $event );

		return $id;
	}

	/**
	 * Timeline of a contact, newest first.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $limit      Maximum number of events.
	 * @return object[]
	 */
	public function for_contact( $contact_id, $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}aluleth_events WHERE contact_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
				(int) $contact_id,
				max( 1, min( 500, (int) $limit ) )
			)
		);
		foreach ( (array) $rows as $row ) {
			$row->data = $row->data ? json_decode( $row->data, true ) : array();
		}
		return $rows ? $rows : array();
	}

	/**
	 * Human label for an event, for timelines.
	 *
	 * @param object $event Event row.
	 * @return string
	 */
	public function label( $event ) {
		$data   = is_array( $event->data ) ? $event->data : array();
		$labels = array(
			'contact.created'   => __( 'Became a contact', 'aluleth-smtp' ),
			'contact.tagged'    => __( 'Tagged', 'aluleth-smtp' ),
			'form.submitted'    => __( 'Sent a form', 'aluleth-smtp' ),
			'email.sent'        => __( 'Received an email', 'aluleth-smtp' ),
			'email.bounced'     => __( 'An email bounced', 'aluleth-smtp' ),
			'email.complained'  => __( 'Marked an email as spam', 'aluleth-smtp' ),
			'consent.given'     => __( 'Gave consent', 'aluleth-smtp' ),
			'consent.withdrawn' => __( 'Withdrew consent', 'aluleth-smtp' ),
		);
		$label  = isset( $labels[ $event->type ] ) ? $labels[ $event->type ] : $event->type;
		if ( ! empty( $data['title'] ) ) {
			$label .= ': ' . $data['title'];
		} elseif ( ! empty( $data['tag'] ) ) {
			$label .= ': ' . $data['tag'];
		}

		/**
		 * Filters the label of an event in timelines.
		 *
		 * @param string $label Label.
		 * @param object $event Event.
		 */
		return (string) apply_filters( 'aluleth/event/label', $label, $event );
	}
}
