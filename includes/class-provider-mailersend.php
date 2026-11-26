<?php
/**
 * MailerSend (email API v1).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * MailerSend.
 */
final class Provider_Mailersend extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailersend';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'MailerSend';
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In MailerSend › Integrations › API tokens, create a token with “Email” full access and paste it here.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://www.mailersend.com/help/managing-api-tokens';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'api_key' => array(
				'label'    => __( 'API token', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
		);
	}

	/**
	 * Recipients per request.
	 *
	 * @return int
	 */
	public function max_recipients() {
		return 50;
	}

	/**
	 * Sends.
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		$body = array(
			'from'    => $this->people( array( $msg['from'] ) )[0],
			'to'      => $this->people( $msg['to'] ),
			'subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$body['html'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$body['text'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$body['cc'] = $this->people( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$body['bcc'] = $this->people( $msg['bcc'] );
		}
		if ( $msg['reply_to'] ) {
			$body['reply_to'] = $this->people( $msg['reply_to'] )[0];
		}
		foreach ( $msg['headers'] as $name => $value ) {
			$body['headers'][] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['attachments'][] = array(
				'content'     => $file['content'],
				'filename'    => $file['name'],
				'disposition' => 'attachment',
			);
		}
		if ( ! empty( $context['kind'] ) ) {
			$body['tags'] = array( 'marketing' === $context['kind'] ? 'marketing' : 'transactional' );
		}
		$res = $this->http( 'POST', 'https://api.mailersend.com/v1/email', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ), $body, 30 );
		$id  = '';
		if ( ! is_wp_error( $res ) && isset( $res['headers']['x-message-id'] ) ) {
			$id = (string) $res['headers']['x-message-id'];
		}
		return $this->result( $res, $id );
	}

	/**
	 * Checks the token.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		return $this->checked( $this->http( 'GET', 'https://api.mailersend.com/v1/api-quota', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) ) );
	}
	/**
	 * Events: the plugin can add the address when the token may manage webhooks.
	 *
	 * @return string
	 */
	public function hook_support() {
		return 'auto';
	}

	/**
	 * Where to add the address.
	 *
	 * @return string
	 */
	public function hook_help() {
		return __( 'Or by hand: in MailerSend › Domains › your domain › Webhooks, add a webhook with this address and tick Delivered, Soft bounced, Hard bounced, Spam complaint, Suppressed and Deferred. Paste its signing secret below.', 'aluleth-smtp' );
	}

	/**
	 * Signing key.
	 *
	 * @return array
	 */
	public function hook_secret() {
		return array(
			'label' => __( 'Signing secret', 'aluleth-smtp' ),
			'help'  => __( 'On the webhook page in MailerSend. With it, only MailerSend can send events here.', 'aluleth-smtp' ),
		);
	}

	/**
	 * Reads a MailerSend event.
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array|\WP_Error
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $query );
		if ( ! empty( $settings['_hook_secret'] ) ) {
			$sig = isset( $headers['signature'] ) ? (string) $headers['signature'] : '';
			if ( '' === $sig || ! hash_equals( hash_hmac( 'sha256', (string) $body, (string) $settings['_hook_secret'] ), $sig ) ) {
				return $this->bad_signature();
			}
		}
		$j = json_decode( (string) $body, true );
		if ( ! is_array( $j ) || empty( $j['type'] ) || empty( $j['data'] ) || ! is_array( $j['data'] ) ) {
			return array();
		}
		$map = array(
			'activity.delivered'       => 'delivered',
			'activity.hard_bounced'    => 'hard_bounce',
			'activity.soft_bounced'    => 'soft_bounce',
			'activity.spam_complaint'  => 'complaint',
			'activity.spam_complaints' => 'complaint',
			'activity.suppressed'      => 'rejected',
			'activity.deferred'        => 'deferred',
			'activity.unsubscribed'    => 'unsubscribed',
		);
		if ( ! isset( $map[ $j['type'] ] ) ) {
			return array();
		}
		$d     = $j['data'];
		$meta  = isset( $d['meta'] ) && is_array( $d['meta'] ) ? $d['meta'] : array();
		$email = isset( $d['recipient'] ) ? $d['recipient'] : ( isset( $d['email']['recipient']['email'] ) ? $d['email']['recipient']['email'] : '' );
		$mid   = isset( $d['message_id'] ) ? $d['message_id'] : ( isset( $d['email']['message']['id'] ) ? $d['email']['message']['id'] : '' );
		$why   = '';
		if ( ! empty( $meta['bounce_reason'] ) ) {
			$why = trim( ( ! empty( $meta['bounce_code'] ) ? $meta['bounce_code'] . ' ' : '' ) . $meta['bounce_reason'] );
		} elseif ( ! empty( $meta['suppression_reason'] ) ) {
			$why = (string) $meta['suppression_reason'];
		} elseif ( isset( $d['morph']['reason'] ) ) {
			$why = (string) $d['morph']['reason'];
		}
		return array(
			array(
				'type'       => $map[ $j['type'] ],
				'email'      => (string) $email,
				'message_id' => (string) $mid,
				'ref'        => '',
				'detail'     => $why,
				'id'         => isset( $d['id'] ) ? (string) $d['id'] : '',
				'time'       => isset( $j['created_at'] ) ? $this->time_of( $j['created_at'] ) : 0,
			),
		);
	}

	/**
	 * The MailerSend domain of the From address.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return array|null|\WP_Error Domain data, null when not found.
	 */
	private function find_domain( array $settings, $domain ) {
		$res = $this->http( 'GET', 'https://api.mailersend.com/v1/domains?limit=100', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['code'] ) {
			return new \WP_Error( 'alsmtp_hook', $res['code'] . ' · ' . $this->error_text( $res ) );
		}
		foreach ( isset( $res['json']['data'] ) ? (array) $res['json']['data'] : array() as $d ) {
			if ( isset( $d['name'] ) && strtolower( $d['name'] ) === strtolower( $domain ) ) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * Adds the webhook to the domain of the From address.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		$domain = $this->find_domain( $settings, substr( strrchr( $c['from_email'], '@' ), 1 ) );
		if ( is_wp_error( $domain ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This MailerSend token cannot read your domains. Add the address by hand, or give the token access to Domains and Webhooks.', 'aluleth-smtp' ) );
		}
		if ( ! $domain || empty( $domain['id'] ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'The domain of the From address is not in this MailerSend account.', 'aluleth-smtp' ) );
		}
		$res = $this->http(
			'POST',
			'https://api.mailersend.com/v1/webhooks',
			array( 'Authorization' => 'Bearer ' . $settings['api_key'] ),
			array(
				'url'       => $url,
				'name'      => 'Aluleth SMTP',
				'events'    => array( 'activity.delivered', 'activity.soft_bounced', 'activity.hard_bounced', 'activity.spam_complaint', 'activity.suppressed', 'activity.deferred' ),
				'domain_id' => (string) $domain['id'],
				'enabled'   => true,
				'version'   => 2,
			)
		);
		if ( ! is_wp_error( $res ) && in_array( $res['code'], array( 401, 403 ), true ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This MailerSend token cannot add webhooks. Add the address by hand, or give the token “Webhooks: full access”.', 'aluleth-smtp' ) );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		$data = isset( $res['json']['data'] ) ? (array) $res['json']['data'] : array();
		return array(
			'remote' => isset( $data['id'] ) ? (string) $data['id'] : '',
			'secret' => isset( $data['signing_secret'] ) ? (string) $data['signing_secret'] : '',
		);
	}

	/**
	 * Removes the webhook.
	 *
	 * @param array  $settings Settings.
	 * @param string $remote   Id.
	 * @return void
	 */
	public function hook_remove( array $settings, $remote ) {
		if ( '' !== (string) $remote ) {
			$this->http( 'DELETE', 'https://api.mailersend.com/v1/webhooks/' . rawurlencode( (string) $remote ), array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		}
	}

	/**
	 * Whether MailerSend verified the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$d = $this->find_domain( $settings, $domain );
		if ( is_wp_error( $d ) ) {
			return null;
		}
		if ( ! $d ) {
			return false;
		}
		if ( isset( $d['dkim'] ) ) {
			return (bool) $d['dkim'];
		}
		return ! empty( $d['is_verified'] );
	}

	/**
	 * DKIM selectors.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 'mlsend', 'mlsend2' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return '_spf.mailersend.net';
	}
}
