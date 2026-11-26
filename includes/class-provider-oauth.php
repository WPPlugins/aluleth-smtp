<?php
/**
 * Base of services you sign in to (Google, Microsoft): your own app's client
 * ID and secret, a "Connect" button, a refresh token kept encrypted.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth service.
 */
abstract class Provider_Oauth extends Provider {

	/**
	 * Sign-in page of the service.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	abstract protected function authorize_endpoint( array $settings );

	/**
	 * Token address of the service.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	abstract protected function token_endpoint( array $settings );

	/**
	 * Scopes asked for.
	 *
	 * @return string
	 */
	abstract protected function scopes();

	/**
	 * The account's email address, after sign-in.
	 *
	 * @param array  $tokens Token answer.
	 * @param string $access Access token.
	 * @return string
	 */
	abstract protected function account_email( array $tokens, $access );

	/**
	 * Name of the button.
	 *
	 * @return string
	 */
	abstract public function connect_label();

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'Sign in, your own app', 'aluleth-smtp' );
	}

	/**
	 * Help under the From address.
	 *
	 * @return string
	 */
	public function from_help() {
		return __( 'The address of the account you sign in with, or an address it may send as (an alias).', 'aluleth-smtp' );
	}

	/**
	 * Why there are no events.
	 *
	 * @return string
	 */
	public function no_events_text() {
		return __( 'This service does not report deliveries to other apps: bounces come back as emails to the mailbox you sign in with.', 'aluleth-smtp' );
	}

	/**
	 * Whether this service signs in.
	 *
	 * @return bool
	 */
	public function is_oauth() {
		return true;
	}

	/**
	 * Address the service sends people back to (to enter in the app at the service).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return rest_url( 'aluleth-smtp/v1/oauth' );
	}

	/**
	 * Extra query arguments of the sign-in page.
	 *
	 * @param array $c Connection.
	 * @return array
	 */
	protected function authorize_extra( array $c ) {
		unset( $c );
		return array();
	}

	/**
	 * Sign-in address.
	 *
	 * @param array  $settings Settings.
	 * @param array  $c        Connection.
	 * @param string $state    Random state.
	 * @return string
	 */
	public function authorize_url( array $settings, array $c, $state ) {
		$args = array_merge(
			array(
				'client_id'     => $settings['client_id'],
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => $this->scopes(),
				'state'         => $state,
				'login_hint'    => $c['from_email'],
			),
			$this->authorize_extra( $c )
		);
		return $this->authorize_endpoint( $settings ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Calls the token address.
	 *
	 * @param array $settings Settings.
	 * @param array $fields   Form fields.
	 * @return array|\WP_Error Token answer.
	 */
	protected function token_call( array $settings, array $fields ) {
		$fields = array_merge(
			array(
				'client_id'     => $settings['client_id'],
				'client_secret' => $settings['client_secret'],
			),
			$fields
		);
		$res    = $this->http( 'POST', $this->token_endpoint( $settings ), array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields, '', '&' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] || empty( $res['json']['access_token'] ) ) {
			$why = isset( $res['json']['error_description'] ) ? (string) $res['json']['error_description'] : ( isset( $res['json']['error'] ) ? (string) $res['json']['error'] : $this->error_text( $res ) );
			return new \WP_Error( 'alsmtp_oauth', $res['code'] . ' · ' . $why, array( 'status' => $res['code'] ) );
		}
		return $res['json'];
	}

	/**
	 * Turns the code from the sign-in into tokens.
	 *
	 * @param array  $settings Settings.
	 * @param string $code     Code.
	 * @return array|\WP_Error refresh, access, expires, account.
	 */
	public function exchange( array $settings, $code ) {
		$fields = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => self::redirect_uri(),
		);
		if ( 'microsoft' === $this->id() ) {
			$fields['scope'] = $this->scopes();
		}
		$t = $this->token_call( $settings, $fields );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( empty( $t['refresh_token'] ) ) {
			return new \WP_Error( 'alsmtp_oauth', __( 'The service gave no lasting access. Remove this site from the app’s access in your account, then connect again.', 'aluleth-smtp' ) );
		}
		return array(
			'refresh' => (string) $t['refresh_token'],
			'access'  => (string) $t['access_token'],
			'expires' => time() + ( isset( $t['expires_in'] ) ? (int) $t['expires_in'] : 3600 ),
			'account' => $this->account_email( $t, (string) $t['access_token'] ),
		);
	}

	/**
	 * A valid access token (kept until shortly before it expires; the refresh token may change).
	 *
	 * @param array $settings Settings (connection id in _connection).
	 * @return string|\WP_Error
	 */
	protected function access_token( array $settings ) {
		if ( empty( $settings['refresh_token'] ) ) {
			/* translators: %s: button name. */
			return new \WP_Error( 'alsmtp_oauth', sprintf( __( 'Not signed in yet: open the connection and click “%s”.', 'aluleth-smtp' ), $this->connect_label() ) );
		}
		$cid   = isset( $settings['_connection'] ) ? (string) $settings['_connection'] : '';
		$cache = 'alsmtp_tok_' . md5( $cid . '|' . $settings['client_id'] . '|' . $settings['refresh_token'] );
		// Same key as forget_token().
		$kept = get_transient( $cache );
		if ( is_string( $kept ) && '' !== $kept ) {
			$token = Secrets::unlock( $kept );
			if ( '' !== $token ) {
				return $token;
			}
		}
		$fields = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $settings['refresh_token'],
		);
		if ( 'microsoft' === $this->id() ) {
			$fields['scope'] = $this->scopes();
		}
		$t = $this->token_call( $settings, $fields );
		if ( is_wp_error( $t ) ) {
			$data = $t->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && 400 === (int) $data['status'] ) {
				/* translators: 1: error, 2: button name. */
				return new \WP_Error( 'alsmtp_oauth', sprintf( __( 'The sign-in is no longer valid (%1$s). Open the connection and click “%2$s” again.', 'aluleth-smtp' ), $t->get_error_message(), $this->connect_label() ) );
			}
			return $t;
		}
		if ( '' !== $cid && ! empty( $t['refresh_token'] ) && $t['refresh_token'] !== $settings['refresh_token'] ) {
			// Microsoft gives a new refresh token each time.
			Connections::store_secret( $cid, 'refresh_token', (string) $t['refresh_token'] );
		}
		$life = max( 60, ( isset( $t['expires_in'] ) ? (int) $t['expires_in'] : 3600 ) - 300 );
		set_transient( $cache, Secrets::lock( (string) $t['access_token'] ), $life );
		return (string) $t['access_token'];
	}

	/**
	 * Forgets the kept access token (the service said it is no longer valid).
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	protected function forget_token( array $settings ) {
		delete_transient( 'alsmtp_tok_' . md5( ( isset( $settings['_connection'] ) ? (string) $settings['_connection'] : '' ) . '|' . $settings['client_id'] . '|' . $settings['refresh_token'] ) );
	}

	/**
	 * Sends with a fresh access token once more when the service says the token expired.
	 *
	 * @param array    $settings Settings.
	 * @param callable $call     Function( $token ) returning an http() answer.
	 * @return array|\WP_Error Answer.
	 */
	protected function with_token( array $settings, $call ) {
		$token = $this->access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$res = $call( $token );
		if ( ! is_wp_error( $res ) && 401 === $res['code'] ) {
			$this->forget_token( $settings );
			$token = $this->access_token( $settings );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$res = $call( $token );
		}
		return $res;
	}

	/**
	 * Checks the sign-in.
	 *
	 * @param array $settings Settings (with _from).
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		if ( empty( $settings['refresh_token'] ) ) {
			/* translators: %s: button name. */
			return new \WP_Error( 'alsmtp_oauth', sprintf( __( 'Saved. Now click “%s” below to sign in.', 'aluleth-smtp' ), $this->connect_label() ), array( 'warning' => true ) );
		}
		$token = $this->access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$account = isset( $settings['account'] ) ? strtolower( (string) $settings['account'] ) : '';
		$from    = isset( $settings['_from'] ) ? strtolower( (string) $settings['_from'] ) : '';
		if ( '' !== $account && '' !== $from && $account !== $from ) {
			/* translators: 1: account address, 2: From address. */
			return new \WP_Error( 'alsmtp_oauth', sprintf( __( 'Signed in as %1$s, but the From address is %2$s. It works only if %2$s is an address this account may send as (an alias); otherwise use %1$s.', 'aluleth-smtp' ), $account, $from ), array( 'warning' => true ) );
		}
		return true;
	}

	/**
	 * Payload of a JWT (an ID token received straight from the service, no signature check needed).
	 *
	 * @param string $jwt Token.
	 * @return array
	 */
	protected function jwt_payload( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( count( $parts ) < 2 ) {
			return array();
		}
		$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payload.
		$data = false === $json ? null : json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}
