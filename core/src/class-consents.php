<?php
/**
 * Proof of consent.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Consents service.
 */
final class Consents {

	/**
	 * Records a consent decision.
	 *
	 * @param int    $contact_id Contact id.
	 * @param string $purpose    'email_marketing', 'sms', 'whatsapp' or 'privacy'.
	 * @param bool   $given      True when given, false when withdrawn.
	 * @param string $method     How it was collected, e.g. 'form:12'.
	 * @param string $text       The exact text the person agreed to (only its hash is stored).
	 * @return void
	 */
	public function record( $contact_id, $purpose, $given, $method = '', $text = '' ) {
		global $wpdb;
		$purpose = in_array( $purpose, array( 'email_marketing', 'sms', 'whatsapp', 'privacy' ), true ) ? $purpose : 'privacy';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'aluleth_consents',
			array(
				'contact_id' => (int) $contact_id,
				'purpose'    => $purpose,
				'status'     => $given ? 'given' : 'withdrawn',
				'method'     => substr( sanitize_text_field( (string) $method ), 0, 40 ),
				'text_hash'  => '' !== (string) $text ? hash( 'sha256', wp_strip_all_tags( (string) $text ) ) : '',
				'ip_hash'    => Util::ip_hash(),
				'created_at' => Util::now(),
			)
		);
		aluleth_core()->events()->record(
			$given ? 'consent.given' : 'consent.withdrawn',
			array(
				'contact' => (int) $contact_id,
				'data'    => array(
					'purpose' => $purpose,
					'method'  => $method,
				),
			)
		);
	}
}
