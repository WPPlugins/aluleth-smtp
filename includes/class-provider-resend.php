<?php
/**
 * Resend (email API).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Resend.
 */
final class Provider_Resend extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'resend';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Resend';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · developer friendly', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Resend › API Keys, create a key (“Sending access” is enough) and paste it here. Your domain must be verified in Resend.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://resend.com/docs/dashboard/api-keys/introduction';
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
		$list = static function ( array $a ) {
			return array_map( array( Message::class, 'format' ), $a );
		};
		$body = array(
			'from'    => Message::format( $msg['from'] ),
			'to'      => $list( $msg['to'] ),
			'subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$body['html'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$body['text'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$body['cc'] = $list( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$body['bcc'] = $list( $msg['bcc'] );
		}
		if ( $msg['reply_to'] ) {
			$body['reply_to'] = $list( $msg['reply_to'] );
		}
		if ( $msg['headers'] ) {
			$body['headers'] = (object) $msg['headers'];
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['attachments'][] = array(
				'filename'     => $file['name'],
				'content'      => $file['content'],
				'content_type' => $file['type'],
			);
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$body['tags'] = array(
				array(
					'name'  => 'aluleth_id',
					'value' => $this->ref( $context ),
				),
			);
		}
		$headers = array( 'Authorization' => 'Bearer ' . $settings['api_key'] );
		if ( ! empty( $context['mail_id'] ) ) {
			$headers['Idempotency-Key'] = 'aluleth-' . get_current_blog_id() . '-' . $context['mail_id'] . '-' . ( isset( $context['attempt'] ) ? (int) $context['attempt'] : 0 );
		}
		$res = $this->http( 'POST', 'https://api.resend.com/emails', $headers, $body, 30 );
		$id  = ! is_wp_error( $res ) && isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '';
		return $this->result( $res, $id );
	}

	/**
	 * Checks the key. A sending-only key cannot list domains: Resend says so, and the key is good.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$res = $this->http( 'GET', 'https://api.resend.com/domains', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		if ( ! is_wp_error( $res ) && 401 === $res['code'] && isset( $res['json']['name'] ) && 'restricted_api_key' === $res['json']['name'] ) {
			return true;
		}
		return $this->checked( $res );
	}
	/**
	 * Events: the plugin can add the address with a "Full access" key.
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
		return __( 'Or by hand: in Resend › Webhooks, add an endpoint with this address and tick email.delivered, email.bounced, email.complained, email.failed, email.suppressed and email.delivery_delayed. Paste its signing secret below.', 'aluleth-smtp' );
	}

	/**
	 * Signing key.
	 *
	 * @return array
	 */
	public function hook_secret() {
		return array(
			'label' => __( 'Signing secret (whsec_…)', 'aluleth-smtp' ),
			'help'  => __( 'On the webhook page in Resend. With it, only Resend can send events here.', 'aluleth-smtp' ),
		);
	}

	/**
	 * Reads a Resend event.
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array|\WP_Error
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $query );
		if ( ! empty( $settings['_hook_secret'] ) && ! $this->svix_ok( $body, $headers, $settings['_hook_secret'] ) ) {
			return $this->bad_signature();
		}
		$j = json_decode( (string) $body, true );
		if ( ! is_array( $j ) || empty( $j['type'] ) || empty( $j['data'] ) || ! is_array( $j['data'] ) ) {
			return array();
		}
		$d = $j['data'];
		switch ( $j['type'] ) {
			case 'email.delivered':
				$type = 'delivered';
				break;
			case 'email.bounced':
				$type = isset( $d['bounce']['type'] ) && 'Permanent' !== $d['bounce']['type'] ? 'soft_bounce' : 'hard_bounce';
				break;
			case 'email.complained':
				$type = 'complaint';
				break;
			case 'email.failed':
			case 'email.suppressed':
				$type = 'rejected';
				break;
			case 'email.delivery_delayed':
				$type = 'deferred';
				break;
			default:
				return array();
		}
		$ref = '';
		if ( isset( $d['tags'] ) && is_array( $d['tags'] ) ) {
			if ( isset( $d['tags']['aluleth_id'] ) ) {
				$ref = (string) $d['tags']['aluleth_id'];
			} else {
				foreach ( $d['tags'] as $tag ) {
					if ( is_array( $tag ) && isset( $tag['name'], $tag['value'] ) && 'aluleth_id' === $tag['name'] ) {
						$ref = (string) $tag['value'];
					}
				}
			}
		}
		$detail = '';
		if ( isset( $d['bounce']['message'] ) ) {
			$detail = (string) $d['bounce']['message'];
		} elseif ( isset( $d['failed']['reason'] ) ) {
			$detail = (string) $d['failed']['reason'];
		}
		$to     = isset( $d['to'] ) ? array_values( (array) $d['to'] ) : array();
		$single = 1 === count( $to ) || 'delivered' === $type;
		$out    = array();
		foreach ( $single ? $to : array( '' ) as $email ) {
			$out[] = array(
				'type'       => $type,
				'email'      => (string) $email,
				'message_id' => isset( $d['email_id'] ) ? (string) $d['email_id'] : '',
				'ref'        => $ref,
				'detail'     => $single ? $detail : trim( implode( ', ', $to ) . ' · ' . $detail, ' ·' ),
				'id'         => isset( $headers['svix-id'] ) ? $headers['svix-id'] . '-' . $email : '',
				'time'       => isset( $j['created_at'] ) ? $this->time_of( $j['created_at'] ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Adds the webhook through the API (needs a "Full access" key).
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$res = $this->http(
			'POST',
			'https://api.resend.com/webhooks',
			array( 'Authorization' => 'Bearer ' . $settings['api_key'] ),
			array(
				'endpoint' => $url,
				'events'   => array( 'email.delivered', 'email.bounced', 'email.complained', 'email.failed', 'email.suppressed', 'email.delivery_delayed' ),
			)
		);
		if ( ! is_wp_error( $res ) && in_array( $res['code'], array( 401, 403 ), true ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This Resend key can only send. Add the address by hand, or use a key with “Full access” once to add it.', 'aluleth-smtp' ) );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		return array(
			'remote' => isset( $res['json']['id'] ) ? (string) $res['json']['id'] : '',
			'secret' => isset( $res['json']['signing_secret'] ) ? (string) $res['json']['signing_secret'] : '',
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
			$this->http( 'DELETE', 'https://api.resend.com/webhooks/' . rawurlencode( (string) $remote ), array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		}
	}

	/**
	 * Whether Resend verified the domain (a "Full access" key can tell).
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', 'https://api.resend.com/domains', array( 'Authorization' => 'Bearer ' . $settings['api_key'] ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return null;
		}
		foreach ( isset( $res['json']['data'] ) ? (array) $res['json']['data'] : array() as $d ) {
			if ( isset( $d['name'] ) && strtolower( $d['name'] ) === strtolower( $domain ) ) {
				return isset( $d['status'] ) && 'verified' === $d['status'];
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
		return array( 'resend' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'amazonses.com';
	}
}
