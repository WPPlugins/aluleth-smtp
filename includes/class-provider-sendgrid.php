<?php
/**
 * SendGrid (Mail Send API v3).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * SendGrid.
 */
final class Provider_Sendgrid extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'sendgrid';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SendGrid';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · global or EU', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In SendGrid › Settings › API Keys, create a key with “Mail Send” access and paste it here.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://www.twilio.com/docs/sendgrid/ui/account-and-settings/api-keys';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'api_key' => array(
				'label'    => __( 'API key', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
			'region'  => array(
				'label'   => __( 'Data region', 'aluleth-smtp' ),
				'type'    => 'select',
				'options' => array(
					'global' => __( 'Global', 'aluleth-smtp' ),
					'eu'     => __( 'EU (sendgrid.eu)', 'aluleth-smtp' ),
				),
			),
		);
	}

	/**
	 * API host.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private function host( array $settings ) {
		return isset( $settings['region'] ) && 'eu' === $settings['region'] ? 'https://api.eu.sendgrid.com' : 'https://api.sendgrid.com';
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
		$personal = array( 'to' => $this->people( $msg['to'] ) );
		if ( $msg['cc'] ) {
			$personal['cc'] = $this->people( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$personal['bcc'] = $this->people( $msg['bcc'] );
		}
		$content = array(
			array(
				'type'  => 'text/plain',
				'value' => '' !== $msg['text'] ? $msg['text'] : ' ',
			),
		);
		if ( '' !== $msg['html'] ) {
			$content[] = array(
				'type'  => 'text/html',
				'value' => $msg['html'],
			);
		}
		$body = array(
			'personalizations' => array( $personal ),
			'from'             => $this->people( array( $msg['from'] ) )[0],
			'subject'          => $msg['subject'],
			'content'          => $content,
		);
		if ( $msg['reply_to'] ) {
			if ( 1 === count( $msg['reply_to'] ) ) {
				$body['reply_to'] = $this->people( $msg['reply_to'] )[0];
			} else {
				$body['reply_to_list'] = $this->people( $msg['reply_to'] );
			}
		}
		if ( $msg['headers'] ) {
			$body['headers'] = (object) $msg['headers'];
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['attachments'][] = array(
				'content'     => $file['content'],
				'filename'    => $file['name'],
				'type'        => $file['type'],
				'disposition' => 'attachment',
			);
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$body['custom_args'] = array( 'aluleth_id' => $this->ref( $context ) );
		}
		if ( ! empty( $context['kind'] ) ) {
			$body['categories'] = array( 'marketing' === $context['kind'] ? 'marketing' : 'transactional' );
		}
		$res = $this->http(
			'POST',
			$this->host( $settings ) . '/v3/mail/send',
			array( 'Authorization' => 'Bearer ' . $settings['api_key'] ),
			$body,
			30
		);
		$id  = '';
		if ( ! is_wp_error( $res ) && isset( $res['headers']['x-message-id'] ) ) {
			$id = (string) $res['headers']['x-message-id'];
		}
		return $this->result( $res, $id );
	}

	/**
	 * Checks the key: it must have the "mail.send" scope.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$res = $this->http( 'GET', $this->host( $settings ) . '/v3/scopes', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		$ok  = $this->checked( $res );
		if ( true !== $ok ) {
			return $ok;
		}
		$scopes = isset( $res['json']['scopes'] ) ? (array) $res['json']['scopes'] : array();
		if ( ! in_array( 'mail.send', $scopes, true ) ) {
			return new \WP_Error( 'alsmtp_verify', __( 'This key cannot send mail. In SendGrid, give it “Mail Send” access.', 'aluleth-smtp' ) );
		}
		return true;
	}
	/**
	 * Events: the plugin can try to add the address (the key needs access to webhooks).
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
		return __( 'Or by hand: in SendGrid › Settings › Mail Settings › Event Webhooks, create a webhook with this address and tick Delivered, Bounced, Dropped, Deferred and Spam reports. Then turn on Signature verification and paste the verification key below.', 'aluleth-smtp' );
	}

	/**
	 * Signing key.
	 *
	 * @return array
	 */
	public function hook_secret() {
		return array(
			'label' => __( 'Verification key (signed webhook)', 'aluleth-smtp' ),
			'help'  => __( 'Shown in SendGrid when Signature verification is on. With it, only SendGrid can send events here.', 'aluleth-smtp' ),
		);
	}

	/**
	 * Reads SendGrid events (always a list).
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
			$sig  = isset( $headers['x-twilio-email-event-webhook-signature'] ) ? base64_decode( $headers['x-twilio-email-event-webhook-signature'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature from SendGrid.
			$time = isset( $headers['x-twilio-email-event-webhook-timestamp'] ) ? (string) $headers['x-twilio-email-event-webhook-timestamp'] : '';
			$key  = openssl_pkey_get_public( "-----BEGIN PUBLIC KEY-----\n" . chunk_split( preg_replace( '/\s+/', '', (string) $settings['_hook_secret'] ), 64, "\n" ) . "-----END PUBLIC KEY-----\n" );
			if ( ! $sig || '' === $time || ! $key || 1 !== openssl_verify( $time . $body, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
				return $this->bad_signature();
			}
		}
		$out = array();
		foreach ( $this->json_list( $body ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['event'] ) ) {
				continue;
			}
			switch ( $e['event'] ) {
				case 'delivered':
					$type = 'delivered';
					break;
				case 'bounce':
					$type = isset( $e['type'] ) && 'blocked' === $e['type'] ? 'rejected' : 'hard_bounce';
					break;
				case 'dropped':
					$type = 'rejected';
					break;
				case 'deferred':
					$type = 'deferred';
					break;
				case 'spamreport':
					$type = 'complaint';
					break;
				case 'unsubscribe':
				case 'group_unsubscribe':
					$type = 'unsubscribed';
					break;
				default:
					continue 2;
			}
			$detail = isset( $e['reason'] ) ? (string) $e['reason'] : ( isset( $e['response'] ) ? (string) $e['response'] : '' );
			if ( ! empty( $e['status'] ) && false === strpos( $detail, (string) $e['status'] ) ) {
				$detail = trim( $e['status'] . ' ' . $detail );
			}
			$sg    = isset( $e['sg_message_id'] ) ? (string) $e['sg_message_id'] : '';
			$out[] = array(
				'type'       => $type,
				'email'      => isset( $e['email'] ) ? (string) $e['email'] : '',
				'message_id' => '' !== $sg ? strtok( $sg, '.' ) : '',
				'ref'        => isset( $e['aluleth_id'] ) ? (string) $e['aluleth_id'] : '',
				'detail'     => $detail,
				'id'         => isset( $e['sg_event_id'] ) ? (string) $e['sg_event_id'] : '',
				'time'       => isset( $e['timestamp'] ) ? (int) $e['timestamp'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Adds a signed Event Webhook through the API.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$auth = array( 'Authorization' => 'Bearer ' . $settings['api_key'] );
		$body = array(
			'enabled'       => true,
			'url'           => $url,
			'friendly_name' => 'Aluleth SMTP',
			'delivered'     => true,
			'bounce'        => true,
			'dropped'       => true,
			'deferred'      => true,
			'spam_report'   => true,
		);
		$res  = $this->http( 'POST', $this->host( $settings ) . '/v3/user/webhooks/event/settings', $auth, $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 401 === $res['code'] || 403 === $res['code'] ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This SendGrid key cannot add webhooks (it only sends). Add the address by hand, or give the key access to Mail Settings and try again.', 'aluleth-smtp' ) );
		}
		if ( 400 === $res['code'] && false !== stripos( (string) wp_json_encode( $res['json'] ), 'maximum number of webhooks' ) ) {
			return new \WP_Error( 'alsmtp_hook', $this->limit_text( $settings ) );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		$remote = isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '';
		$signed = $this->http( 'PATCH', $this->host( $settings ) . '/v3/user/webhooks/event/settings/signed/' . rawurlencode( $remote ), $auth, array( 'enabled' => true ) );
		$secret = ! is_wp_error( $signed ) && isset( $signed['json']['public_key'] ) ? (string) $signed['json']['public_key'] : '';
		return array(
			'remote' => $remote,
			'secret' => $secret,
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
			$this->http( 'DELETE', $this->host( $settings ) . '/v3/user/webhooks/event/settings/' . rawurlencode( (string) $remote ), array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		}
	}

	/**
	 * Explains that the plan's Event Webhooks are all used, and by which addresses.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private function limit_text( array $settings ) {
		$res   = $this->http( 'GET', $this->host( $settings ) . '/v3/user/webhooks/event/settings/all', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		$max   = 0;
		$hosts = array();
		if ( ! is_wp_error( $res ) && 200 === $res['code'] ) {
			$max = isset( $res['json']['max_allowed'] ) ? (int) $res['json']['max_allowed'] : 0;
			foreach ( isset( $res['json']['webhooks'] ) ? (array) $res['json']['webhooks'] : array() as $w ) {
				$host = isset( $w['url'] ) ? (string) wp_parse_url( (string) $w['url'], PHP_URL_HOST ) : '';
				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}
		}
		$how = __( 'In SendGrid › Settings › Mail Settings › Event Webhooks, remove one you no longer need, then click this button again. Emails are sent anyway; only delivery and bounce reports are missing.', 'aluleth-smtp' );
		if ( $max > 0 && $hosts ) {
			/* translators: 1: number of webhooks, 2: addresses. */
			return sprintf( _n( 'Your SendGrid plan allows %1$d event webhook and it is already used by %2$s.', 'Your SendGrid plan allows %1$d event webhooks and they are all used by %2$s.', $max, 'aluleth-smtp' ), $max, implode( ', ', array_unique( $hosts ) ) ) . ' ' . $how;
		}
		return __( 'Your SendGrid plan has no free event webhook left.', 'aluleth-smtp' ) . ' ' . $how;
	}

	/**
	 * Whether the domain is authenticated in SendGrid (needs a key that can read it).
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', $this->host( $settings ) . '/v3/whitelabel/domains?domain=' . rawurlencode( $domain ), array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return null;
		}
		foreach ( $res['json'] as $d ) {
			if ( is_array( $d ) && ! empty( $d['valid'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * DKIM selectors.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 's1', 's2' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'sendgrid.net';
	}
}
