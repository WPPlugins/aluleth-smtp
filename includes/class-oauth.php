<?php
/**
 * Sign-in with Google and Microsoft: start, come back, disconnect.
 *
 * The service sends people back to wp-json/aluleth-smtp/v1/oauth (one fixed address
 * to enter in the app), which forwards to an admin action where the logged-in
 * administrator and the one-time state are checked.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth flow.
 */
final class Oauth {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_post_alsmtp_oauth_start', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_alsmtp_oauth_back', array( __CLASS__, 'back' ) );
		add_action( 'admin_post_alsmtp_oauth_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	/**
	 * The address the service sends people back to.
	 *
	 * @return void
	 */
	public static function route() {
		register_rest_route(
			'aluleth-smtp/v1',
			'/oauth',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'forward' ),
				// Only forwards the answer to wp-admin, where the user and the state are checked.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Forwards the service's answer to the admin action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public static function forward( \WP_REST_Request $request ) {
		$args  = array( 'action' => 'alsmtp_oauth_back' );
		$query = (array) $request->get_query_params();
		// WordPress removes "error" from $_GET while parsing the request: read it from the query string.
		if ( ! isset( $query['error'] ) && isset( $_SERVER['QUERY_STRING'] ) ) {
			$raw = array();
			parse_str( sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ), $raw );
			if ( isset( $raw['error'] ) && is_string( $raw['error'] ) ) {
				$query['error'] = $raw['error'];
			}
		}
		foreach ( array( 'code', 'state', 'error', 'error_description' ) as $key ) {
			$v = isset( $query[ $key ] ) ? $query[ $key ] : null;
			if ( is_string( $v ) && '' !== $v ) {
				$args[ $key ] = substr( $v, 0, 2000 );
			}
		}
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) ) );
		exit;
	}

	/**
	 * Back to the connection.
	 *
	 * @param string $id   Connection id.
	 * @param string $type Notice type.
	 * @param string $text Notice.
	 * @return void
	 */
	private static function to_connection( $id, $type, $text ) {
		Admin::back(
			'' !== $id ? array(
				'tab'  => 'connections',
				'edit' => $id,
			) : array( 'tab' => 'connections' ),
			$type,
			$text
		);
	}

	/**
	 * Sends the administrator to the service's sign-in page.
	 *
	 * @return void
	 */
	public static function start() {
		Admin::guard( 'oauth_start' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		$c  = Connections::get( $id );
		$p  = $c ? Connections::provider( $c['provider'] ) : null;
		if ( ! $c || ! $p || ! $p->is_oauth() ) {
			self::to_connection( '', 'error', __( 'This connection does not sign in.', 'aluleth-smtp' ) );
		}
		$settings = Connections::credentials( $c );
		if ( '' === (string) $settings['client_id'] || '' === (string) $settings['client_secret'] ) {
			self::to_connection( $id, 'error', __( 'Paste the client ID and secret first, then save.', 'aluleth-smtp' ) );
		}
		// One-time random part + a WordPress nonce, checked again when the service sends the administrator back.
		$state = wp_generate_password( 32, false, false ) . '.' . wp_create_nonce( 'alsmtp_oauth_back' );
		set_transient(
			'alsmtp_oauth_' . hash( 'sha256', $state ),
			array(
				'user' => get_current_user_id(),
				'id'   => $id,
			),
			15 * MINUTE_IN_SECONDS
		);
		// The sign-in page is at the service: not a local address.
		wp_redirect( $p->authorize_url( $settings, $c, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google or Microsoft sign-in page.
		exit;
	}

	/**
	 * The WordPress nonce at the end of a state ("random.nonce").
	 *
	 * @param string $state State sent back by the service.
	 * @return string
	 */
	private static function state_nonce( $state ) {
		return (string) substr( (string) strrchr( (string) $state, '.' ), 1 );
	}

	/**
	 * The service sent the administrator back: turns the code into tokens.
	 *
	 * @return void
	 */
	public static function back() {
		if ( ! current_user_can( Admin::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-smtp' ), 403 );
		}
		// The WordPress nonce comes back at the end of the one-time state.
		if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( self::state_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ), 'alsmtp_oauth_back' ) ) {
			self::to_connection( '', 'error', __( 'This sign-in link is not valid any more. Click the sign-in button again.', 'aluleth-smtp' ) );
		}
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ( isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '' );
		$key   = 'alsmtp_oauth_' . hash( 'sha256', $state );
		$data  = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) || get_current_user_id() !== (int) $data['user'] ) {
			self::to_connection( '', 'error', __( 'This sign-in link is not valid any more. Click the sign-in button again.', 'aluleth-smtp' ) );
		}
		$id = (string) $data['id'];
		$c  = Connections::get( $id );
		$p  = $c ? Connections::provider( $c['provider'] ) : null;
		if ( ! $c || ! $p || ! $p->is_oauth() ) {
			self::to_connection( '', 'error', __( 'This connection does not exist any more.', 'aluleth-smtp' ) );
		}
		if ( '' !== $error || '' === $code ) {
			/* translators: %s: error from the service. */
			self::to_connection( $id, 'error', sprintf( __( 'Sign-in cancelled or refused: %s', 'aluleth-smtp' ), '' !== $error ? $error : __( 'no code received.', 'aluleth-smtp' ) ) );
		}
		$t = $p->exchange( Connections::credentials( $c ), $code );
		if ( is_wp_error( $t ) ) {
			/* translators: %s: error from the service. */
			self::to_connection( $id, 'error', sprintf( __( 'The service refused the sign-in: %s', 'aluleth-smtp' ), $t->get_error_message() ) );
		}
		Connections::store_secret( $id, 'refresh_token', $t['refresh'] );
		Connections::store_settings(
			$id,
			array(
				'account'   => $t['account'],
				'signed_in' => time(),
			)
		);
		$check = Connections::verify( $id );
		if ( true === $check ) {
			$who = '' !== $t['account'] ? $t['account'] : __( 'your account', 'aluleth-smtp' );
			/* translators: %s: email address. */
			self::to_connection( $id, 'success', sprintf( __( 'Signed in as %s. Send a test email to be sure.', 'aluleth-smtp' ), $who ) );
		}
		self::to_connection( $id, 'warning', is_wp_error( $check ) ? $check->get_error_message() : __( 'Signed in.', 'aluleth-smtp' ) );
	}

	/**
	 * Forgets the sign-in.
	 *
	 * @return void
	 */
	public static function disconnect() {
		Admin::guard( 'oauth_disconnect' );
		$id = sanitize_key( (string) Admin::post( 'id' ) );
		Connections::store_secret( $id, 'refresh_token', '' );
		Connections::store_settings(
			$id,
			array(
				'account'   => '',
				'signed_in' => 0,
			)
		);
		Connections::verify( $id );
		self::to_connection( $id, 'success', __( 'Signed out. Emails cannot go through this connection until you sign in again.', 'aluleth-smtp' ) );
	}
}
