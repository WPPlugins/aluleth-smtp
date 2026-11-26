<?php
/**
 * MailPoet (a plugin on the same site): adds subscribers to MailPoet lists.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * MailPoet service.
 */
final class Service_Mailpoet extends Service {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailpoet';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'MailPoet';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Add newsletter subscribers to your MailPoet lists, on this site.', 'aluleth-smtp' );
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
	 * Whether MailPoet is active.
	 *
	 * @return bool
	 */
	public function available() {
		return class_exists( '\MailPoet\API\API' );
	}

	/**
	 * Why it is not available.
	 *
	 * @return string
	 */
	public function missing_text() {
		return __( 'Install and activate the MailPoet plugin to use it.', 'aluleth-smtp' );
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'lists', 'tag_names', 'fields' );
	}

	/**
	 * MailPoet API.
	 *
	 * @return object|null
	 */
	private function api() {
		if ( ! $this->available() ) {
			return null;
		}
		try {
			return \MailPoet\API\API::MP( 'v1' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Lists.
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		$api = $this->api();
		$out = array();
		if ( $api ) {
			try {
				foreach ( (array) $api->getLists() as $list ) {
					if ( empty( $list['deleted_at'] ) ) {
						$out[ (string) $list['id'] ] = (string) $list['name'];
					}
				}
			} catch ( \Throwable $e ) {
				return array();
			}
		}
		return $out;
	}

	/**
	 * Custom fields (MailPoet's cf_ fields).
	 *
	 * @return array<string, string>
	 */
	public function fields() {
		$api = $this->api();
		$out = array();
		if ( $api ) {
			try {
				foreach ( (array) $api->getSubscriberFields() as $field ) {
					if ( 0 === strpos( (string) $field['id'], 'cf_' ) ) {
						$out[ (string) $field['id'] ] = (string) $field['name'];
					}
				}
			} catch ( \Throwable $e ) {
				return array();
			}
		}
		return $out;
	}

	/**
	 * Adds or updates the subscriber and subscribes them to the chosen lists.
	 *
	 * MailPoet's own "signup confirmation" setting decides whether a
	 * confirmation email is sent. People who unsubscribed or bounced in MailPoet
	 * are never subscribed again.
	 *
	 * @param array $feed    Feed: lists, tags (names), map.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$api = $this->api();
		if ( ! $api ) {
			return self::skip( $this->missing_text() );
		}
		$email = Util::email( $payload['email'] );
		if ( '' === $email ) {
			return self::skip( __( 'No email address in this entry.', 'aluleth-smtp' ) );
		}
		$lists = array_values( array_intersect( array_map( 'strval', (array) $feed['lists'] ), array_keys( $this->lists() ) ) );
		if ( ! $lists ) {
			return self::fail( __( 'No MailPoet list is chosen, or the chosen list was deleted.', 'aluleth-smtp' ) );
		}

		$data = array_filter(
			array(
				'first_name' => (string) $payload['first_name'],
				'last_name'  => (string) $payload['last_name'],
			),
			'strlen'
		);
		$data = array_merge( $data, array_intersect_key( self::mapped( $feed, $payload ), $this->fields() ) );
		$tags = array_values( array_filter( array_map( 'strval', (array) $feed['tags'] ) ) );
		$args = array(
			'send_confirmation_email' => true,
			'schedule_welcome_email'  => true,
		);

		try {
			$existing = null;
			try {
				$existing = $api->getSubscriber( $email );
			} catch ( \Throwable $e ) {
				$existing = null; // Not a subscriber yet.
			}

			if ( $existing ) {
				if ( in_array( $existing['status'], array( 'unsubscribed', 'bounced' ), true ) ) {
					return self::skip( __( 'This person unsubscribed or bounced in MailPoet, so they were not subscribed again.', 'aluleth-smtp' ) );
				}
				if ( $data ) {
					$api->updateSubscriber( $existing['id'], $data );
				}
				$result = $api->subscribeToLists( $existing['id'], $lists, $args );
				foreach ( $tags as $tag ) {
					try {
						$api->tagSubscriber( $existing['id'], $tag );
					} catch ( \Throwable $e ) {
						continue; // A tag that cannot be added must not cancel the subscription.
					}
				}
			} else {
				$data['email'] = $email;
				if ( $tags ) {
					$data['tags'] = $tags;
				}
				$result = $api->addSubscriber( $data, $lists, $args );
			}
		} catch ( \Throwable $e ) {
			$names = implode( ', ', array_intersect_key( $this->lists(), array_flip( $lists ) ) );
			// MailPoet codes: 10 = confirmation email not sent, 17 = welcome email not sent (the subscriber is saved in both cases).
			if ( 10 === (int) $e->getCode() ) {
				/* translators: %s: names of the lists, groups, forms or tags. */
				return self::fail( sprintf( __( 'Added to %s, but MailPoet could not send its confirmation email, so the person cannot confirm yet. Check how MailPoet sends emails (MailPoet › Settings › Send with…).', 'aluleth-smtp' ), $names ) );
			}
			if ( 17 === (int) $e->getCode() ) {
				/* translators: %s: names of the lists, groups, forms or tags. */
				return sprintf( __( 'Subscribed to %s (MailPoet could not send its welcome email).', 'aluleth-smtp' ), $names );
			}
			/* translators: %s: error message from MailPoet. */
			return self::fail( sprintf( __( 'MailPoet refused the subscriber: %s', 'aluleth-smtp' ), wp_strip_all_tags( $e->getMessage() ) ) );
		}

		$names = array_intersect_key( $this->lists(), array_flip( $lists ) );
		$text  = implode( ', ', $names );
		if ( isset( $result['status'] ) && 'unconfirmed' === $result['status'] ) {
			/* translators: %s: names of the lists, groups, forms or tags. */
			return sprintf( __( 'Added to %s, waiting for the person to confirm by email.', 'aluleth-smtp' ), $text );
		}
		/* translators: %s: names of the lists, groups, forms or tags. */
		return sprintf( __( 'Subscribed to %s.', 'aluleth-smtp' ), $text );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'MailPoet’s own “sign-up confirmation” setting decides whether people confirm by email. People who unsubscribed in MailPoet are never added again.', 'aluleth-smtp' );
	}
}
