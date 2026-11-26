<?php
/**
 * WordPress privacy tools (export and erase personal data).
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy exporter and eraser for the core data.
 */
final class Privacy {

	/**
	 * Hooks into the WordPress privacy tools.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['aluleth-contacts'] = array(
			'exporter_friendly_name' => __( 'Aluleth contacts', 'aluleth-smtp' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['aluleth-contacts'] = array(
			'eraser_friendly_name' => __( 'Aluleth contacts', 'aluleth-smtp' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports the contact record, custom fields, tags and timeline.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public static function export( $email ) {
		$core    = aluleth_core();
		$contact = $core->contacts()->get_by_email( $email );
		if ( ! $contact ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$data = array();
		foreach ( array( 'email', 'first_name', 'last_name', 'phone', 'status', 'language', 'country', 'created_at' ) as $key ) {
			if ( '' !== (string) $contact->$key ) {
				$data[] = array(
					'name'  => $key,
					'value' => (string) $contact->$key,
				);
			}
		}
		foreach ( $core->contacts()->get_meta( (int) $contact->id ) as $key => $value ) {
			$data[] = array(
				'name'  => $key,
				'value' => (string) $value,
			);
		}
		$tags = $core->tags()->of( (int) $contact->id );
		if ( $tags ) {
			$data[] = array(
				'name'  => __( 'Tags', 'aluleth-smtp' ),
				'value' => implode( ', ', $tags ),
			);
		}
		$items = array(
			array(
				'group_id'    => 'aluleth-contact',
				'group_label' => __( 'Contact', 'aluleth-smtp' ),
				'item_id'     => 'aluleth-contact-' . $contact->id,
				'data'        => $data,
			),
		);
		foreach ( $core->events()->for_contact( (int) $contact->id, 500 ) as $event ) {
			$items[] = array(
				'group_id'    => 'aluleth-timeline',
				'group_label' => __( 'Contact history', 'aluleth-smtp' ),
				'item_id'     => 'aluleth-event-' . $event->id,
				'data'        => array(
					array(
						'name'  => __( 'Event', 'aluleth-smtp' ),
						'value' => $core->events()->label( $event ),
					),
					array(
						'name'  => __( 'Date (UTC)', 'aluleth-smtp' ),
						'value' => $event->created_at,
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erases the contact and everything attached to it.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public static function erase( $email ) {
		$contact = aluleth_core()->contacts()->get_by_email( $email );
		$removed = Queue::erase( $contact ? (int) $contact->id : 0, (string) $email );
		if ( $contact ) {
			aluleth_core()->contacts()->delete( (int) $contact->id );
		}
		return array(
			'items_removed'  => $contact || $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
