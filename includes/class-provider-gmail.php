<?php
/**
 * Gmail and Google Workspace, through the Gmail API and your own Google Cloud app.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Gmail.
 */
class Provider_Gmail extends Provider_Oauth {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'gmail';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Gmail / Google Workspace', 'aluleth-smtp' );
	}

	/**
	 * Button.
	 *
	 * @return string
	 */
	public function connect_label() {
		return __( 'Sign in with Google', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'Gmail sends from your own Google account through the Gmail API. You make a small app in Google Cloud once (steps below), paste its Client ID and secret here, then sign in. Gmail limits sending to about 500 emails a day (2,000 with Google Workspace): set that limit below.', 'aluleth-smtp' );
	}

	/**
	 * Documentation.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://developers.google.com/workspace/guides/create-credentials';
	}

	/**
	 * Steps at the service.
	 *
	 * @return string[]
	 */
	public function setup_steps() {
		return array(
			__( 'In console.cloud.google.com, create a project (or pick one), then in APIs & Services › Library, enable the Gmail API.', 'aluleth-smtp' ),
			__( 'In Google Auth Platform (OAuth consent screen), fill in the app name and your email. Audience: “Internal” with Google Workspace; otherwise “External”, then click “Publish app” (In production): an app left in “Testing” stops working after 7 days.', 'aluleth-smtp' ),
			__( 'In Clients, create a client of type “Web application” and add the address below as an authorized redirect URI.', 'aluleth-smtp' ),
			__( 'Copy the Client ID and the Client secret here, save, then click “Sign in with Google”. Google may warn that the app is not verified: it is your own app, choose Continue.', 'aluleth-smtp' ),
		);
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'client_id'     => array(
				'label'       => __( 'Client ID', 'aluleth-smtp' ),
				'type'        => 'text',
				'required'    => true,
				// A sample client ID shown in the empty field, not a file loaded from Google.
				'placeholder' => '1234-abcd.apps.googleusercontent.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			),
			'client_secret' => array(
				'label'    => __( 'Client secret', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
		);
	}

	/**
	 * Recipients one email can hold.
	 *
	 * @return int
	 */
	public function max_recipients() {
		return 100;
	}

	/**
	 * Sign-in page.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	protected function authorize_endpoint( array $settings ) {
		unset( $settings );
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	/**
	 * Token address.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	protected function token_endpoint( array $settings ) {
		unset( $settings );
		return 'https://oauth2.googleapis.com/token';
	}

	/**
	 * Scopes: send only, plus the address of the account.
	 *
	 * @return string
	 */
	protected function scopes() {
		return 'https://www.googleapis.com/auth/gmail.send openid email';
	}

	/**
	 * A refresh token every time.
	 *
	 * @param array $c Connection.
	 * @return array
	 */
	protected function authorize_extra( array $c ) {
		unset( $c );
		return array(
			'access_type' => 'offline',
			'prompt'      => 'consent',
		);
	}

	/**
	 * Address of the account, from the ID token.
	 *
	 * @param array  $tokens Token answer.
	 * @param string $access Access token.
	 * @return string
	 */
	protected function account_email( array $tokens, $access ) {
		unset( $access );
		$p = isset( $tokens['id_token'] ) ? $this->jwt_payload( $tokens['id_token'] ) : array();
		return isset( $p['email'] ) ? (string) $p['email'] : '';
	}

	/**
	 * Sends the raw email through the Gmail API.
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		if ( ! empty( $context['mail_id'] ) ) {
			$msg['headers']['X-Aluleth-Id'] = $this->ref( $context );
		}
		$mime = Message::mime( $msg, '', true );
		if ( is_wp_error( $mime ) ) {
			return $this->fail( $mime->get_error_message() );
		}
		$raw = rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of the email, as the Gmail API asks.
		$res = $this->with_token(
			$settings,
			function ( $token ) use ( $raw ) {
				return $this->http( 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', array( 'Authorization' => 'Bearer ' . $token ), array( 'raw' => $raw ), 60 );
			}
		);
		if ( is_wp_error( $res ) && 'alsmtp_oauth' === $res->get_error_code() ) {
			return $this->fail( $res->get_error_message(), false );
		}
		$id = ! is_wp_error( $res ) && isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '';
		return $this->result( $res, $id );
	}

	/**
	 * DKIM: Google signs with the domain once DKIM is turned on in the Admin console.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 'google' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return '_spf.google.com';
	}
}
