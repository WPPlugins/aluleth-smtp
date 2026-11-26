<?php
/**
 * Webhooks: the address where each email service reports delivered emails,
 * bounces and spam reports. One secret address per connection.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks.
 */
final class Hooks {

	/**
	 * Option holding the webhooks.
	 */
	const OPTION = 'aluleth_smtp_hooks';

	/**
	 * Biggest body read (bytes).
	 */
	const MAX_BODY = 2097152;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * REST route.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'aluleth-smtp/v1',
			'/events/(?P<connection>[a-z0-9_-]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'receive' ),
				// Services call this address without a WordPress login: the secret key in the address is checked in receive().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * All webhooks.
	 *
	 * @return array id => hook.
	 */
	private static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * The webhook of a connection.
	 *
	 * @param string $id Connection id.
	 * @return array token, remote, secret, hint, auto, last, set.
	 */
	public static function get( $id ) {
		$all = self::all();
		$h   = isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		return array_merge(
			array(
				'token'  => '',
				'remote' => '',
				'secret' => '',
				'hint'   => '',
				'auto'   => false,
				'last'   => 0,
				'set'    => 0,
			),
			$h
		);
	}

	/**
	 * Saves the webhook of a connection.
	 *
	 * @param string $id     Connection id.
	 * @param array  $change Changes.
	 * @return array
	 */
	private static function put( $id, array $change ) {
		$all        = self::all();
		$h          = array_merge( self::get( $id ), $change );
		$all[ $id ] = $h;
		update_option( self::OPTION, $all, false );
		return $h;
	}

	/**
	 * Forgets the webhook of a deleted connection (and removes it at the service when it was made there).
	 *
	 * @param string $id Connection id.
	 * @param array  $c  Connection, before deletion.
	 * @return void
	 */
	public static function forget( $id, array $c = array() ) {
		$h = self::get( $id );
		if ( $c && '' !== $h['remote'] ) {
			$provider = Connections::provider( $c['provider'] );
			if ( $provider ) {
				$provider->hook_remove( Connections::credentials( $c ), $h['remote'] );
			}
		}
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Secret key of the address. Made when the connection is saved (never on a page view).
	 *
	 * @param string $id   Connection id.
	 * @param bool   $make Make it when there is none yet.
	 * @return string
	 */
	public static function token( $id, $make = false ) {
		$h = self::get( $id );
		if ( '' === $h['token'] && $make ) {
			$h = self::put( $id, array( 'token' => wp_generate_password( 32, false, false ) ) );
		}
		return $h['token'];
	}

	/**
	 * Address to give the service ('' while it has no secret key yet).
	 *
	 * @param string $id   Connection id.
	 * @param bool   $make Make the key when there is none yet.
	 * @return string
	 */
	public static function url( $id, $make = false ) {
		$token = self::token( $id, $make );
		return '' === $token ? '' : add_query_arg( 'key', $token, rest_url( 'aluleth-smtp/v1/events/' . $id ) );
	}

	/**
	 * A new secret key: the old address stops working.
	 *
	 * @param string $id Connection id.
	 * @return void
	 */
	public static function renew( $id ) {
		self::put(
			$id,
			array(
				'token'  => wp_generate_password( 32, false, false ),
				'remote' => '',
				'auto'   => false,
				'set'    => 0,
			)
		);
	}

	/**
	 * Saves the signing key the service gives (stored encrypted).
	 *
	 * @param string $id     Connection id.
	 * @param string $secret Key ('' keeps the saved one).
	 * @return void
	 */
	public static function save_secret( $id, $secret ) {
		$secret = trim( (string) $secret );
		if ( '' === $secret ) {
			return;
		}
		self::put(
			$id,
			array(
				'secret' => Secrets::lock( $secret ),
				'hint'   => Secrets::hint( $secret ),
			)
		);
	}

	/**
	 * Clears the signing key.
	 *
	 * @param string $id Connection id.
	 * @return void
	 */
	public static function clear_secret( $id ) {
		self::put(
			$id,
			array(
				'secret' => '',
				'hint'   => '',
			)
		);
	}

	/**
	 * The person added the address at the service by hand.
	 *
	 * @param string $id Connection id.
	 * @return void
	 */
	public static function mark_set( $id ) {
		self::put( $id, array( 'set' => time() ) );
	}

	/**
	 * An event arrived (saved at most once a minute).
	 *
	 * @param string $id Connection id.
	 * @return void
	 */
	public static function seen( $id ) {
		$h = self::get( $id );
		if ( $h['last'] < time() - MINUTE_IN_SECONDS ) {
			self::put( $id, array( 'last' => time() ) );
		}
	}

	/**
	 * State of a connection's webhook.
	 *
	 * @param array $c Connection.
	 * @return string none (the service has no webhooks), off, waiting or working.
	 */
	public static function state( array $c ) {
		$provider = Connections::provider( $c['provider'] );
		if ( ! $provider || 'none' === $provider->hook_support() ) {
			return 'none';
		}
		$h = self::get( $c['id'] );
		if ( $h['last'] ) {
			return 'working';
		}
		return $h['set'] ? 'waiting' : 'off';
	}

	/**
	 * Creates the webhook at the service through its API.
	 *
	 * @param string $id Connection id.
	 * @return true|\WP_Error
	 */
	public static function setup( $id ) {
		$c = Connections::get( $id );
		if ( ! $c ) {
			return new \WP_Error( 'alsmtp_none', __( 'This connection does not exist.', 'aluleth-smtp' ) );
		}
		$provider = Connections::provider( $c['provider'] );
		if ( 'auto' !== $provider->hook_support() ) {
			return new \WP_Error( 'alsmtp_manual', __( 'This service has no way to add the address for you: follow the steps.', 'aluleth-smtp' ) );
		}
		$h = self::get( $id );
		if ( '' !== $h['remote'] ) {
			$provider->hook_remove( Connections::credentials( $c ), $h['remote'] );
		}
		$r = $provider->hook_setup( Connections::credentials( $c ), self::url( $id, true ), $c );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$change = array(
			'remote' => isset( $r['remote'] ) ? (string) $r['remote'] : '',
			'auto'   => true,
			'set'    => time(),
		);
		if ( ! empty( $r['secret'] ) ) {
			$change['secret'] = Secrets::lock( (string) $r['secret'] );
			$change['hint']   = Secrets::hint( (string) $r['secret'] );
		}
		self::put( $id, $change );
		return true;
	}

	/**
	 * Receives events from a service.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function receive( \WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request['connection'] );
		$c   = Connections::get( $id );
		$h   = self::get( $id );
		$key = $request->get_param( 'key' );
		if ( ! $c || '' === $h['token'] || ! is_string( $key ) || ! hash_equals( $h['token'], $key ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 401 );
		}
		$provider = Connections::provider( $c['provider'] );
		$body     = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_REST_Response( array( 'ok' => false ), 413 );
		}
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', strtolower( $name ) ) ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
		}
		$query = $request->get_query_params();
		unset( $query['key'] );
		$settings                 = Connections::credentials( $c );
		$settings['_hook_secret'] = '' !== $h['secret'] ? Secrets::unlock( $h['secret'] ) : '';
		$events                   = $provider->hook_parse( $body, $headers, (array) $query, $settings );
		if ( null === $events ) {
			return new \WP_REST_Response( array( 'ok' => false ), 404 );
		}
		if ( is_wp_error( $events ) ) {
			$data = $events->get_error_data();
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $events->get_error_message(),
				),
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400
			);
		}
		$done       = Tracking::handle( $id, (array) $events );
		$done['ok'] = true;
		if ( ! $events ) {
			// A test request from the service (or a confirmation): the address works.
			self::seen( $id );
		}
		return new \WP_REST_Response( $done, 200 );
	}
}
