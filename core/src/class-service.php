<?php
/**
 * Base class of an outside tool contacts can be sent to.
 *
 * A service is written once in the core; every Aluleth plugin (forms, popups,
 * membership, funnels) uses it through Integrations with a "feed": the
 * owner's settings for one form or popup (list, tags, double opt-in…).
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Outside service.
 */
abstract class Service {

	/**
	 * Unique id, e.g. 'mailpoet'.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Sends one contact.
	 *
	 * @param array $feed    Clean feed (see Integrations::clean_feeds()).
	 * @param array $payload Payload (see Integrations).
	 * @return string|\WP_Error Short result, or an error (data: 'retry' or 'skip').
	 */
	abstract public function send( array $feed, array $payload );

	/**
	 * One-line description.
	 *
	 * @return string
	 */
	public function description() {
		return '';
	}

	/**
	 * 'plugin' (a plugin on this site), 'webhook' or 'api' (an account with a key).
	 *
	 * @return string
	 */
	public function kind() {
		return 'api';
	}

	/**
	 * Whether the service can be used on this site right now.
	 *
	 * @return bool
	 */
	public function available() {
		return true;
	}

	/**
	 * Why it cannot be used, when it is not available.
	 *
	 * @return string
	 */
	public function missing_text() {
		return '';
	}

	/**
	 * What the feed settings offer: 'lists', 'tags' (chosen in a list), 'tag_names'
	 * (free text), 'fields' (custom fields to map), 'double_optin', 'consent_choice'
	 * (the owner may also send people without newsletter consent).
	 *
	 * @return string[]
	 */
	public function supports() {
		return array();
	}

	/**
	 * Group on the Connections screen: 'marketing', 'alerts' or 'automation'.
	 *
	 * @return string
	 */
	public function group() {
		return 'marketing';
	}

	/**
	 * Whether "Send a test" is offered in the builders.
	 *
	 * @return bool
	 */
	public function testable() {
		return false;
	}

	/**
	 * Address the feed needs (webhooks, Slack, Discord): label, placeholder,
	 * help and the start an address must have. Null: no address.
	 *
	 * @return array|null
	 */
	public function url_field() {
		return null;
	}

	/**
	 * Text shown on the Connections screen when nothing needs connecting.
	 *
	 * @return string
	 */
	public function ready_text() {
		return '';
	}

	/**
	 * Lists, groups or audiences.
	 *
	 * @return array<string, string> Id => name.
	 */
	public function lists() {
		return array();
	}

	/**
	 * Keeps the lists a person saving a feed may send to (all by default). A service whose lists are private
	 * to some people (boards, say) drops the others here.
	 *
	 * @param string[] $lists Cleaned list ids or names.
	 * @return string[]
	 */
	public function clean_lists( array $lists ) {
		return $lists;
	}

	/**
	 * Tags that exist in the service.
	 *
	 * @return array<string, string> Id => name.
	 */
	public function tags() {
		return array();
	}

	/**
	 * Custom fields of the service a form field can fill.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function fields() {
		return array();
	}

	/**
	 * Name of the service's lists in its own words (audiences, groups, forms…).
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Lists', 'aluleth-smtp' );
	}

	/**
	 * Short note shown in the feed settings.
	 *
	 * @return string
	 */
	public function note() {
		return '';
	}

	/**
	 * Fields the owner fills in to connect an account ('api' services).
	 *
	 * @return array<string, array> Key => label, help.
	 */
	public function credential_fields() {
		return array();
	}

	/**
	 * Where the owner finds the key (shown under the field).
	 *
	 * @return string
	 */
	public function key_help() {
		return '';
	}

	/**
	 * Checks credentials against the service.
	 *
	 * @param array $credentials Credentials.
	 * @return string|\WP_Error Account name to show, or an error.
	 */
	public function verify( array $credentials ) {
		unset( $credentials );
		return '';
	}

	/**
	 * Saved connection: credentials, account, connected_at.
	 *
	 * @return array
	 */
	public function connection() {
		$saved = get_option( 'aluleth_connection_' . $this->id(), array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Saved credentials.
	 *
	 * @return array
	 */
	public function credentials() {
		$saved = $this->connection();
		return isset( $saved['credentials'] ) && is_array( $saved['credentials'] ) ? $saved['credentials'] : array();
	}

	/**
	 * Whether an account is connected ('api' services).
	 *
	 * @return bool
	 */
	public function connected() {
		return 'api' !== $this->kind() || (bool) $this->credentials();
	}

	/**
	 * Remembers lists, tags and fields for 10 minutes (each read is a call to the service).
	 *
	 * @param string   $what     'lists', 'tags' or 'fields'.
	 * @param callable $callback Returns id => name.
	 * @return array<string, string>
	 */
	protected function cached( $what, $callback ) {
		$key  = 'aluleth_svc_' . $this->id() . '_' . $what;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = call_user_func( $callback );
			if ( is_wp_error( $data ) ) {
				// Do not ask a service that is down on every page load: try again in 2 minutes.
				set_transient( $key, array(), 2 * MINUTE_IN_SECONDS );
				return array();
			}
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * Forgets the remembered lists, tags and fields.
	 *
	 * @return void
	 */
	public function flush() {
		foreach ( array( 'lists', 'tags', 'fields', 'attributes' ) as $what ) {
			delete_transient( 'aluleth_svc_' . $this->id() . '_' . $what );
		}
	}

	/**
	 * Calls a JSON API.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $url     URL.
	 * @param array      $headers Headers.
	 * @param array|null $body    JSON body.
	 * @return array|\WP_Error array( 'code' => int, 'body' => array ), or an error that can be tried again.
	 */
	protected static function http( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Aluleth/' . aluleth_core()->version() . '; ' . home_url( '/' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message. */
			return self::retry( sprintf( __( 'The service did not answer (%s).', 'aluleth-smtp' ), $response->get_error_message() ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Turns an unexpected HTTP answer into an error: tried again on 429 and 5xx.
	 *
	 * @param array  $response Response from http().
	 * @param string $detail   Error text from the service, if any.
	 * @return \WP_Error
	 */
	protected function http_error( array $response, $detail = '' ) {
		$code = (int) $response['code'];
		if ( 401 === $code || 403 === $code ) {
			/* translators: %s: service name. */
			return self::fail( sprintf( __( '%s refused the key. Check the connection in Aluleth › Connections.', 'aluleth-smtp' ), $this->label() ) );
		}
		/* translators: 1: service name, 2: HTTP status code, 3: error detail. */
		$message = trim( sprintf( __( '%1$s answered with an error (HTTP %2$d). %3$s', 'aluleth-smtp' ), $this->label(), $code, wp_strip_all_tags( (string) $detail ) ) );
		return ( 429 === $code || $code >= 500 ) ? self::retry( $message ) : self::fail( $message );
	}

	/**
	 * Data for the builders' settings screens.
	 *
	 * @return array
	 */
	public function catalog() {
		$available = $this->available();
		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'description' => $this->description(),
			'kind'        => $this->kind(),
			'list_label'  => $this->list_label(),
			'note'        => $this->note(),
			'available'   => $available,
			'missing'     => $available ? '' : $this->missing_text(),
			'supports'    => $this->supports(),
			'lists'       => $available ? self::pairs( $this->lists() ) : array(),
			'tags'        => $available ? self::pairs( $this->tags() ) : array(),
			'fields'      => $available ? self::pairs( $this->fields() ) : array(),
			'group'       => $this->group(),
			'testable'    => $this->testable(),
			'url'         => $this->url_field(),
		);
	}

	/**
	 * Turns id => name into a list of { id, name } (keeps the order in JSON).
	 *
	 * @param array $items Id => name.
	 * @return array[]
	 */
	protected static function pairs( array $items ) {
		$out = array();
		foreach ( $items as $id => $name ) {
			$out[] = array(
				'id'   => (string) $id,
				'name' => (string) $name,
			);
		}
		return $out;
	}

	/**
	 * Error that will be tried again later.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	protected static function retry( $message ) {
		return new \WP_Error( 'retry', $message, array( 'retry' => true ) );
	}

	/**
	 * Nothing to send (not an error).
	 *
	 * @param string $message Why.
	 * @return \WP_Error
	 */
	protected static function skip( $message ) {
		return new \WP_Error( 'skip', $message, array( 'skip' => true ) );
	}

	/**
	 * Final error.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	protected static function fail( $message ) {
		return new \WP_Error( 'failed', $message );
	}

	/**
	 * Content of a team alert: title, answers, payment and a link to the entry.
	 *
	 * @param array $payload Payload.
	 * @return array{title: string, lines: array, link: string, payment: string, test: bool}
	 */
	protected static function alert( array $payload ) {
		$source = isset( $payload['source'] ) ? (array) $payload['source'] : array();
		$title  = isset( $source['title'] ) && '' !== $source['title'] ? (string) $source['title'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lines  = array();
		foreach ( isset( $payload['fields'] ) ? (array) $payload['fields'] : array() as $field ) {
			$value = trim( (string) $field['value'] );
			if ( '' !== $value ) {
				$lines[] = array(
					'label' => (string) $field['label'],
					'value' => mb_substr( $value, 0, 900 ),
				);
			}
		}
		$link = '';
		if ( ! empty( $source['entry'] ) ) {
			/** This filter is documented in src/class-queue.php */
			$target = apply_filters( 'aluleth/queue/object_link', '', 'entry', (int) $source['entry'] );
			$link   = is_array( $target ) && ! empty( $target['url'] ) ? (string) $target['url'] : '';
		}
		$payment = '';
		if ( ! empty( $payload['payment'] ) && is_array( $payload['payment'] ) ) {
			/* translators: %s: amount. */
			$payment = sprintf( __( 'Paid: %s', 'aluleth-smtp' ), PayPal::format( (float) $payload['payment']['amount'], (string) $payload['payment']['currency'] ) );
		}
		return array(
			/* translators: %s: form name. */
			'title'   => sprintf( __( 'New entry: %s', 'aluleth-smtp' ), $title ),
			'lines'   => array_slice( $lines, 0, 24 ),
			'link'    => $link,
			'payment' => $payment,
			'test'    => isset( $payload['event'] ) && 'test' === $payload['event'],
		);
	}

	/**
	 * Values of the mapped custom fields: service key => text.
	 *
	 * @param array $feed    Feed.
	 * @param array $payload Payload.
	 * @return array<string, string>
	 */
	protected static function mapped( array $feed, array $payload ) {
		$out = array();
		foreach ( isset( $feed['map'] ) ? (array) $feed['map'] : array() as $remote => $local ) {
			if ( '' !== $local && isset( $payload['fields'][ $local ] ) && '' !== (string) $payload['fields'][ $local ]['value'] ) {
				$out[ $remote ] = (string) $payload['fields'][ $local ]['value'];
			}
		}
		return $out;
	}
}
