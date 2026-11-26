<?php
/**
 * Brevo (transactional email API v3).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Brevo.
 */
final class Provider_Brevo extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'brevo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Brevo';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · free plan available', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Brevo › SMTP & API › API keys, create a key and paste it here. The From address must be a sender or a domain validated in Brevo.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://help.brevo.com/hc/en-us/articles/209467485';
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
		);
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
			'sender'  => $this->people( array( $msg['from'] ) )[0],
			'to'      => $this->people( $msg['to'] ),
			'subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$body['htmlContent'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$body['textContent'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$body['cc'] = $this->people( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$body['bcc'] = $this->people( $msg['bcc'] );
		}
		if ( $msg['reply_to'] ) {
			$body['replyTo'] = $this->people( $msg['reply_to'] )[0];
		}
		$headers = $msg['headers'];
		if ( ! empty( $context['mail_id'] ) ) {
			$headers['X-Aluleth-Id']    = (string) $context['mail_id'];
			$headers['X-Mailin-custom'] = $this->ref( $context );
		}
		if ( $headers ) {
			$body['headers'] = (object) $headers;
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['attachment'][] = array(
				'content' => $file['content'],
				'name'    => $file['name'],
			);
		}
		if ( ! empty( $context['kind'] ) ) {
			$body['tags'] = array( 'marketing' === $context['kind'] ? 'marketing' : 'transactional' );
		}
		$res = $this->http( 'POST', 'https://api.brevo.com/v3/smtp/email', array( 'api-key' => $settings['api_key'] ), $body, 30 );
		$id  = ! is_wp_error( $res ) && isset( $res['json']['messageId'] ) ? (string) $res['json']['messageId'] : '';
		return $this->result( $res, $id );
	}

	/**
	 * Checks the key, then the From address: Brevo accepts emails from an unknown sender and rejects them afterwards.
	 *
	 * @param array $settings Settings (_from: the connection's From address).
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$auth  = array( 'api-key' => $settings['api_key'] );
		$check = $this->checked( $this->http( 'GET', 'https://api.brevo.com/v3/account', $auth ) );
		$from  = isset( $settings['_from'] ) ? strtolower( (string) $settings['_from'] ) : '';
		if ( true !== $check || '' === $from ) {
			return $check;
		}
		$known   = array();
		$senders = $this->http( 'GET', 'https://api.brevo.com/v3/senders', $auth );
		if ( is_wp_error( $senders ) || 200 !== $senders['code'] ) {
			return true; // The key may not read senders: the test email decides.
		}
		foreach ( isset( $senders['json']['senders'] ) ? (array) $senders['json']['senders'] : array() as $sender ) {
			if ( isset( $sender['email'] ) && ! empty( $sender['active'] ) ) {
				if ( strtolower( $sender['email'] ) === $from ) {
					return true;
				}
				$known[] = $sender['email'];
			}
		}
		$domain  = substr( strrchr( $from, '@' ), 1 );
		$domains = $this->http( 'GET', 'https://api.brevo.com/v3/senders/domains', $auth );
		if ( ! is_wp_error( $domains ) && 200 === $domains['code'] ) {
			foreach ( isset( $domains['json']['domains'] ) ? (array) $domains['json']['domains'] : array() as $d ) {
				if ( isset( $d['domain_name'] ) && strtolower( $d['domain_name'] ) === $domain && ( ! empty( $d['authenticated'] ) || ! empty( $d['verified'] ) ) ) {
					return true;
				}
			}
		}
		/* translators: %s: email address. */
		$text = sprintf( __( 'The key works, but %s is not a validated sender in Brevo: Brevo would accept the emails, then reject them. Add it in Brevo › Senders, domains & dedicated IPs, or authenticate its domain.', 'aluleth-smtp' ), $from );
		if ( $known ) {
			/* translators: %s: email addresses. */
			$text .= ' ' . sprintf( __( 'Validated senders: %s.', 'aluleth-smtp' ), implode( ', ', array_slice( $known, 0, 5 ) ) );
		}
		return new \WP_Error( 'alsmtp_sender', $text, array( 'warning' => true ) );
	}
	/**
	 * Events: Brevo can add the address for you.
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
		return __( 'Or by hand: in Brevo, open the webhooks of transactional emails, add one with this address and tick Delivered, Hard bounce, Soft bounce, Blocked, Spam (complaint), Invalid email, Deferred and Error.', 'aluleth-smtp' );
	}

	/**
	 * Reads Brevo events (one object, or a list when batched).
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $query, $settings );
		$map = array(
			'delivered'     => 'delivered',
			'hard_bounce'   => 'hard_bounce',
			'invalid_email' => 'hard_bounce',
			'soft_bounce'   => 'soft_bounce',
			'spam'          => 'complaint',
			'blocked'       => 'rejected',
			'error'         => 'rejected',
			'deferred'      => 'deferred',
			'unsubscribed'  => 'unsubscribed',
		);
		$out = array();
		foreach ( $this->json_list( $body ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['event'] ) || ! isset( $map[ $e['event'] ] ) ) {
				continue;
			}
			$out[] = array(
				'type'       => $map[ $e['event'] ],
				'email'      => isset( $e['email'] ) ? (string) $e['email'] : '',
				'message_id' => isset( $e['message-id'] ) ? (string) $e['message-id'] : '',
				'ref'        => isset( $e['X-Mailin-custom'] ) ? (string) $e['X-Mailin-custom'] : '',
				'detail'     => isset( $e['reason'] ) ? (string) $e['reason'] : '',
				'id'         => '',
				'time'       => isset( $e['ts_event'] ) ? (int) $e['ts_event'] : ( isset( $e['date'] ) ? $this->time_of( $e['date'] ) : 0 ),
			);
		}
		return $out;
	}

	/**
	 * Adds the webhook through the Brevo API.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$events = array( 'delivered', 'hardBounce', 'softBounce', 'blocked', 'spam', 'invalid', 'deferred', 'error' );
		$body   = array(
			'url'         => $url,
			'description' => 'Aluleth SMTP – ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'type'        => 'transactional',
			'events'      => $events,
		);
		$res    = $this->http( 'POST', 'https://api.brevo.com/v3/webhooks', array( 'api-key' => $settings['api_key'] ), $body );
		if ( ! is_wp_error( $res ) && 400 === $res['code'] ) {
			// Older accounts do not know the "error" event.
			$body['events'] = array_values( array_diff( $events, array( 'error' ) ) );
			$res            = $this->http( 'POST', 'https://api.brevo.com/v3/webhooks', array( 'api-key' => $settings['api_key'] ), $body );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		return array( 'remote' => isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '' );
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
			$this->http( 'DELETE', 'https://api.brevo.com/v3/webhooks/' . rawurlencode( (string) $remote ), array( 'api-key' => $settings['api_key'] ) );
		}
	}

	/**
	 * Whether Brevo authenticated the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', 'https://api.brevo.com/v3/senders/domains', array( 'api-key' => $settings['api_key'] ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return null;
		}
		foreach ( isset( $res['json']['domains'] ) ? (array) $res['json']['domains'] : array() as $d ) {
			if ( isset( $d['domain_name'] ) && strtolower( $d['domain_name'] ) === strtolower( $domain ) ) {
				return ! empty( $d['authenticated'] );
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
		return array( 'brevo1', 'brevo2', 'mail' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'spf.brevo.com';
	}
}
