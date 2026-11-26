<?php
/**
 * FluentCRM (a plugin on the same site): creates or updates FluentCRM contacts.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * FluentCRM service.
 */
final class Service_Fluentcrm extends Service {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'fluentcrm';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'FluentCRM';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Create or update FluentCRM contacts with lists and tags, on this site.', 'aluleth-smtp' );
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'plugin';
	}

	/**
	 * Whether FluentCRM is active.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'FluentCrmApi' );
	}

	/**
	 * Why it is not available.
	 *
	 * @return string
	 */
	public function missing_text() {
		return __( 'Install and activate the FluentCRM plugin to use it.', 'aluleth-smtp' );
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'tags', 'fields', 'double_optin', 'consent_choice' );
	}

	/**
	 * Lists or tags as id => title.
	 *
	 * @param string $kind 'lists' or 'tags'.
	 * @return array<string, string>
	 */
	private function items( $kind ) {
		if ( ! $this->available() ) {
			return array();
		}
		$out = array();
		try {
			foreach ( FluentCrmApi( $kind )->all() as $item ) {
				$out[ (string) $item->id ] = (string) $item->title;
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * Lists.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->items( 'lists' );
	}

	/**
	 * Tags.
	 *
	 * @return array<string, string>
	 */
	public function tags() {
		return $this->items( 'tags' );
	}

	/**
	 * Custom contact fields.
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		$out = array();
		if ( function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
			foreach ( (array) fluentcrm_get_custom_contact_fields() as $field ) {
				if ( is_array( $field ) && ! empty( $field['slug'] ) ) {
					$out[ (string) $field['slug'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['slug'];
				}
			}
		}
		return $out;
	}

	/**
	 * Creates or updates the contact.
	 *
	 * With newsletter consent the contact is "subscribed" (or "pending" with
	 * double opt-in, and FluentCRM sends its confirmation email). Without
	 * consent, when the owner allowed it, a new contact is "transactional".
	 * FluentCRM itself never re-subscribes an unsubscribed, bounced or
	 * complained contact.
	 *
	 * @param array $feed    Feed: lists, tags (ids), double_optin, map.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		if ( ! $this->available() ) {
			return self::skip( $this->missing_text() );
		}
		$email = Util::email( $payload['email'] );
		if ( '' === $email ) {
			return self::skip( __( 'No email address in this entry.', 'aluleth-smtp' ) );
		}

		$consent = ! empty( $payload['consent'] );
		$data    = array_filter(
			array(
				'email'      => $email,
				'first_name' => (string) $payload['first_name'],
				'last_name'  => (string) $payload['last_name'],
				'phone'      => (string) $payload['phone'],
			),
			'strlen'
		);
		$lists   = array_map( 'intval', array_intersect( array_map( 'strval', (array) $feed['lists'] ), array_keys( $this->lists() ) ) );
		$tags    = array_map( 'intval', array_intersect( array_map( 'strval', (array) $feed['tags'] ), array_keys( $this->tags() ) ) );
		if ( $lists ) {
			$data['lists'] = array_values( $lists );
		}
		if ( $tags ) {
			$data['tags'] = array_values( $tags );
		}
		$custom = array_intersect_key( self::mapped( $feed, $payload ), $this->fields() );
		if ( $custom ) {
			$data['custom_values'] = $custom;
		}

		try {
			$existing   = FluentCrmApi( 'contacts' )->getContact( $email );
			$old_status = $existing ? (string) $existing->status : '';
			if ( $consent ) {
				$data['status'] = ! empty( $feed['double_optin'] ) ? 'pending' : 'subscribed';
			} elseif ( ! $existing ) {
				$data['status'] = 'transactional';
			}
			$contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
			if ( ! $contact ) {
				return self::fail( __( 'FluentCRM did not accept the contact.', 'aluleth-smtp' ) );
			}
			if ( 'pending' === $contact->status && 'pending' !== $old_status && method_exists( $contact, 'sendDoubleOptinEmail' ) ) {
				$contact->sendDoubleOptinEmail();
			}
		} catch ( \Throwable $e ) {
			/* translators: %s: error message from FluentCRM. */
			return self::fail( sprintf( __( 'FluentCRM refused the contact: %s', 'aluleth-smtp' ), wp_strip_all_tags( $e->getMessage() ) ) );
		}

		$labels = array(
			'subscribed'    => __( 'subscribed', 'aluleth-smtp' ),
			'pending'       => __( 'waiting for the email confirmation', 'aluleth-smtp' ),
			'transactional' => __( 'not subscribed to emails', 'aluleth-smtp' ),
			'unsubscribed'  => __( 'still unsubscribed', 'aluleth-smtp' ),
			'bounced'       => __( 'still bounced', 'aluleth-smtp' ),
			'complained'    => __( 'still marked as spam', 'aluleth-smtp' ),
		);
		$status = isset( $labels[ $contact->status ] ) ? $labels[ $contact->status ] : (string) $contact->status;
		return $existing
			/* translators: %s: contact status, e.g. "subscribed". */
			? sprintf( __( 'Contact updated (%s).', 'aluleth-smtp' ), $status )
			/* translators: %s: contact status, e.g. "subscribed". */
			: sprintf( __( 'Contact created (%s).', 'aluleth-smtp' ), $status );
	}
}
