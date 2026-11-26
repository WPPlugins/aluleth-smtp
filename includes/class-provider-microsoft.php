<?php
/**
 * Microsoft 365 and Outlook.com, through Microsoft Graph and your own app in Microsoft Entra.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft.
 */
class Provider_Microsoft extends Provider_Oauth {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'microsoft';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Microsoft 365 / Outlook', 'aluleth-smtp' );
	}

	/**
	 * Button.
	 *
	 * @return string
	 */
	public function connect_label() {
		return __( 'Sign in with Microsoft', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'Microsoft 365 or Outlook.com sends from your own mailbox through Microsoft Graph. You register a small app in Microsoft Entra once (steps below), paste its Application (client) ID and a client secret here, then sign in. Attachments are limited to about 3 MB per email.', 'aluleth-smtp' );
	}

	/**
	 * Documentation.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app';
	}

	/**
	 * Steps at the service.
	 *
	 * @return string[]
	 */
	public function setup_steps() {
		return array(
			__( 'In entra.microsoft.com › App registrations, click “New registration”. Who can use it: your organization only for Microsoft 365, or “any organization and personal accounts” for Outlook.com. Redirect URI: platform “Web”, with the address below.', 'aluleth-smtp' ),
			__( 'Copy the “Application (client) ID” here. For a Microsoft 365 app limited to your organization, also copy the “Directory (tenant) ID” in the Tenant field.', 'aluleth-smtp' ),
			__( 'In Certificates & secrets, add a client secret and copy its Value here (not its ID). Note its end date: create a new one before it expires.', 'aluleth-smtp' ),
			__( 'In API permissions, add Microsoft Graph › Delegated: Mail.Send, offline_access and User.Read (grant admin consent if your organization asks). Save here, then click “Sign in with Microsoft”.', 'aluleth-smtp' ),
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
				'label'       => __( 'Application (client) ID', 'aluleth-smtp' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => '00000000-0000-0000-0000-000000000000',
			),
			'client_secret' => array(
				'label'    => __( 'Client secret (Value)', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
			'tenant'        => array(
				'label'       => __( 'Tenant', 'aluleth-smtp' ),
				'type'        => 'text',
				'required'    => false,
				'placeholder' => 'common',
				'help'        => __( '“common” for most cases; your Directory (tenant) ID for an app limited to your organization; “consumers” for Outlook.com only.', 'aluleth-smtp' ),
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
	 * Tenant segment.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private function tenant( array $settings ) {
		$t = isset( $settings['tenant'] ) ? trim( (string) $settings['tenant'] ) : '';
		return preg_match( '/^[A-Za-z0-9.-]{1,100}$/', $t ) ? $t : 'common';
	}

	/**
	 * Sign-in page.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	protected function authorize_endpoint( array $settings ) {
		return 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant( $settings ) ) . '/oauth2/v2.0/authorize';
	}

	/**
	 * Token address.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	protected function token_endpoint( array $settings ) {
		return 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant( $settings ) ) . '/oauth2/v2.0/token';
	}

	/**
	 * Scopes.
	 *
	 * @return string
	 */
	protected function scopes() {
		return 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read';
	}

	/**
	 * Lets people pick the account.
	 *
	 * @param array $c Connection.
	 * @return array
	 */
	protected function authorize_extra( array $c ) {
		unset( $c );
		return array(
			'response_mode' => 'query',
			'prompt'        => 'select_account',
		);
	}

	/**
	 * Address of the account, from Microsoft Graph.
	 *
	 * @param array  $tokens Token answer.
	 * @param string $access Access token.
	 * @return string
	 */
	protected function account_email( array $tokens, $access ) {
		unset( $tokens );
		$res = $this->http( 'GET', 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName', array( 'Authorization' => 'Bearer ' . $access ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return '';
		}
		return ! empty( $res['json']['mail'] ) ? (string) $res['json']['mail'] : ( isset( $res['json']['userPrincipalName'] ) ? (string) $res['json']['userPrincipalName'] : '' );
	}

	/**
	 * Recipients in Graph form.
	 *
	 * @param array $items Addresses.
	 * @return array
	 */
	private function graph_people( array $items ) {
		$out = array();
		foreach ( $items as $a ) {
			$e = array( 'address' => $a['email'] );
			if ( '' !== $a['name'] ) {
				$e['name'] = $a['name'];
			}
			$out[] = array( 'emailAddress' => $e );
		}
		return $out;
	}

	/**
	 * Sends through Microsoft Graph (JSON message, works for Microsoft 365 and Outlook.com).
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		$m = array(
			'subject'      => $msg['subject'],
			'body'         => '' !== $msg['html'] ? array(
				'contentType' => 'HTML',
				'content'     => $msg['html'],
			) : array(
				'contentType' => 'Text',
				'content'     => $msg['text'],
			),
			'toRecipients' => $this->graph_people( $msg['to'] ),
		);
		if ( $msg['cc'] ) {
			$m['ccRecipients'] = $this->graph_people( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$m['bccRecipients'] = $this->graph_people( $msg['bcc'] );
		}
		if ( $msg['reply_to'] ) {
			$m['replyTo'] = $this->graph_people( $msg['reply_to'] );
		}
		$account = isset( $settings['account'] ) ? strtolower( (string) $settings['account'] ) : '';
		if ( '' !== $account && strtolower( $msg['from']['email'] ) !== $account ) {
			// Another address: the mailbox must have "Send as" rights on it.
			$m['from'] = $this->graph_people( array( $msg['from'] ) )[0];
		}
		$headers = array();
		foreach ( $msg['headers'] as $name => $value ) {
			if ( 0 === stripos( $name, 'x-' ) && count( $headers ) < 5 ) {
				$headers[] = array(
					'name'  => $name,
					'value' => (string) $value,
				);
			}
		}
		if ( ! empty( $context['mail_id'] ) && count( $headers ) < 5 ) {
			$headers[] = array(
				'name'  => 'X-Aluleth-Id',
				'value' => $this->ref( $context ),
			);
		}
		if ( $headers ) {
			$m['internetMessageHeaders'] = $headers;
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$m['attachments'][] = array(
				'@odata.type'  => '#microsoft.graph.fileAttachment',
				'name'         => $file['name'],
				'contentType'  => $file['type'],
				'contentBytes' => $file['content'],
			);
		}
		$body = array(
			'message'         => $m,
			'saveToSentItems' => false,
		);
		$res  = $this->with_token(
			$settings,
			function ( $token ) use ( $body ) {
				return $this->http( 'POST', 'https://graph.microsoft.com/v1.0/me/sendMail', array( 'Authorization' => 'Bearer ' . $token ), $body, 60 );
			}
		);
		if ( is_wp_error( $res ) && 'alsmtp_oauth' === $res->get_error_code() ) {
			return $this->fail( $res->get_error_message(), false );
		}
		return $this->result( $res, '' );
	}

	/**
	 * DKIM selectors of Microsoft 365.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 'selector1', 'selector2' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'spf.protection.outlook.com';
	}
}
