<?php
/**
 * Mailgun (Messages API v3).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Mailgun.
 */
final class Provider_Mailgun extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailgun';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailgun';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · US or EU', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Mailgun › Sending › Domain settings › Sending API keys, create a key for your sending domain, then write that domain below.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://help.mailgun.com/hc/en-us/articles/203380100';
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
			'domain'  => array(
				'label'       => __( 'Sending domain', 'aluleth-smtp' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'mg.example.com',
			),
			'region'  => array(
				'label'   => __( 'Data region', 'aluleth-smtp' ),
				'type'    => 'select',
				'options' => array(
					'us' => __( 'US (api.mailgun.net)', 'aluleth-smtp' ),
					'eu' => __( 'EU (api.eu.mailgun.net)', 'aluleth-smtp' ),
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
		return isset( $settings['region'] ) && 'eu' === $settings['region'] ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
	}

	/**
	 * Authorization header.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private function auth( array $settings ) {
		return array( 'Authorization' => 'Basic ' . base64_encode( 'api:' . $settings['api_key'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Sends (multipart form, as Mailgun asks).
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		$fields   = array();
		$fields[] = array( 'from', Message::format( $msg['from'] ) );
		foreach ( array( 'to', 'cc', 'bcc' ) as $key ) {
			foreach ( $msg[ $key ] as $a ) {
				$fields[] = array( $key, Message::format( $a ) );
			}
		}
		$fields[] = array( 'subject', $msg['subject'] );
		if ( '' !== $msg['text'] ) {
			$fields[] = array( 'text', $msg['text'] );
		}
		if ( '' !== $msg['html'] ) {
			$fields[] = array( 'html', $msg['html'] );
		}
		if ( $msg['reply_to'] ) {
			$fields[] = array( 'h:Reply-To', implode( ', ', array_map( array( Message::class, 'format' ), $msg['reply_to'] ) ) );
		}
		foreach ( $msg['headers'] as $name => $value ) {
			$fields[] = array( 'h:' . $name, $value );
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$fields[] = array( 'v:aluleth_id', $this->ref( $context ) );
		}
		if ( ! empty( $context['kind'] ) ) {
			$fields[] = array( 'o:tag', 'marketing' === $context['kind'] ? 'marketing' : 'transactional' );
		}
		$boundary = 'aluleth' . wp_generate_password( 24, false );
		$body     = '';
		foreach ( $fields as $f ) {
			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $f[0] . "\"\r\n\r\n" . $f[1] . "\r\n";
		}
		foreach ( $msg['attachments'] as $file ) {
			$data = is_readable( $file['path'] ) ? file_get_contents( $file['path'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $data ) {
				continue;
			}
			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="attachment"; filename="' . str_replace( '"', '', $file['name'] ) . "\"\r\nContent-Type: " . $file['type'] . "\r\n\r\n" . $data . "\r\n";
		}
		$body                   .= '--' . $boundary . "--\r\n";
		$headers                 = $this->auth( $settings );
		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
		$res                     = $this->http( 'POST', $this->host( $settings ) . '/v3/' . rawurlencode( $settings['domain'] ) . '/messages', $headers, $body, 30 );
		$id                      = ! is_wp_error( $res ) && isset( $res['json']['id'] ) ? trim( (string) $res['json']['id'], '<>' ) : '';
		return $this->result( $res, $id );
	}

	/**
	 * Checks the key and the domain.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$res = $this->http( 'GET', $this->host( $settings ) . '/v3/domains/' . rawurlencode( $settings['domain'] ), $this->auth( $settings ) );
		// Sending keys of one domain cannot read the domain: Mailgun answers 401 "Forbidden" for those; only a sent test tells.
		if ( ! is_wp_error( $res ) && in_array( $res['code'], array( 401, 403 ), true ) && false !== stripos( $res['body'], 'forbidden' ) ) {
			return null;
		}
		return $this->checked( $res );
	}
	/**
	 * Events: the plugin can add the address with an account API key.
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
		return __( 'Or by hand: in Mailgun › Sending › Webhooks, choose your domain and add this address for Delivered, Permanent failure, Temporary failure and Spam complaints. Paste the HTTP webhook signing key below.', 'aluleth-smtp' );
	}

	/**
	 * Signing key.
	 *
	 * @return array
	 */
	public function hook_secret() {
		return array(
			'label' => __( 'HTTP webhook signing key', 'aluleth-smtp' ),
			'help'  => __( 'In Mailgun › API Security (or Sending › Webhooks). With it, only Mailgun can send events here.', 'aluleth-smtp' ),
		);
	}

	/**
	 * Reads a Mailgun event.
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array|\WP_Error
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $query );
		$j = json_decode( (string) $body, true );
		if ( ! is_array( $j ) || empty( $j['event-data'] ) || ! is_array( $j['event-data'] ) ) {
			return array();
		}
		if ( ! empty( $settings['_hook_secret'] ) ) {
			$sig = isset( $j['signature'] ) && is_array( $j['signature'] ) ? $j['signature'] : array();
			if ( empty( $sig['timestamp'] ) || empty( $sig['token'] ) || empty( $sig['signature'] ) || ! hash_equals( hash_hmac( 'sha256', $sig['timestamp'] . $sig['token'], (string) $settings['_hook_secret'] ), (string) $sig['signature'] ) ) {
				return $this->bad_signature();
			}
		}
		$e      = $j['event-data'];
		$event  = isset( $e['event'] ) ? (string) $e['event'] : '';
		$reason = isset( $e['reason'] ) ? (string) $e['reason'] : '';
		switch ( $event ) {
			case 'delivered':
				$type = 'delivered';
				break;
			case 'failed':
				if ( isset( $e['severity'] ) && 'temporary' === $e['severity'] ) {
					$type = 'deferred';
				} elseif ( 'old' === $reason ) {
					$type = 'soft_bounce';
				} elseif ( 0 === strpos( $reason, 'suppress' ) || 'espblock' === $reason ) {
					$type = 'rejected';
				} else {
					$type = 'hard_bounce';
				}
				break;
			case 'complained':
				$type = 'complaint';
				break;
			case 'unsubscribed':
				$type = 'unsubscribed';
				break;
			default:
				return array();
		}
		$ds     = isset( $e['delivery-status'] ) && is_array( $e['delivery-status'] ) ? $e['delivery-status'] : array();
		$detail = trim( ( ! empty( $ds['code'] ) ? $ds['code'] . ' ' : '' ) . ( ! empty( $ds['message'] ) ? (string) $ds['message'] : ( ! empty( $ds['description'] ) ? (string) $ds['description'] : $reason ) ) );
		return array(
			array(
				'type'       => $type,
				'email'      => isset( $e['recipient'] ) ? (string) $e['recipient'] : '',
				'message_id' => isset( $e['message']['headers']['message-id'] ) ? (string) $e['message']['headers']['message-id'] : '',
				'ref'        => isset( $e['user-variables']['aluleth_id'] ) ? (string) $e['user-variables']['aluleth_id'] : '',
				'detail'     => $detail,
				'id'         => isset( $e['id'] ) ? (string) $e['id'] : '',
				'time'       => isset( $e['timestamp'] ) ? (int) $e['timestamp'] : 0,
			),
		);
	}

	/**
	 * Adds the address for the four events of the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$base    = $this->host( $settings ) . '/v3/domains/' . rawurlencode( $settings['domain'] ) . '/webhooks';
		$headers = $this->auth( $settings ) + array( 'Content-Type' => 'application/x-www-form-urlencoded' );
		$done    = array();
		foreach ( array( 'delivered', 'permanent_fail', 'temporary_fail', 'complained' ) as $event ) {
			$res = $this->http(
				'POST',
				$base,
				$headers,
				http_build_query(
					array(
						'id'  => $event,
						'url' => $url,
					),
					'',
					'&'
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( 401 === $res['code'] || 403 === $res['code'] ) {
				return new \WP_Error( 'alsmtp_hook', __( 'This Mailgun key cannot add webhooks (a sending key of one domain cannot). Add the address by hand, or use an account API key.', 'aluleth-smtp' ) );
			}
			if ( 400 === $res['code'] && false !== stripos( $res['body'], 'exist' ) ) {
				/* translators: %s: event name. */
				return new \WP_Error( 'alsmtp_hook', sprintf( __( 'Mailgun already has a webhook for “%s” on this domain. Add this address next to it by hand.', 'aluleth-smtp' ), $event ) );
			}
			$check = $this->checked( $res );
			if ( true !== $check ) {
				return $check;
			}
			$done[] = $event;
		}
		return array( 'remote' => implode( ',', $done ) );
	}

	/**
	 * Removes the webhooks the plugin added.
	 *
	 * @param array  $settings Settings.
	 * @param string $remote   Events, comma separated.
	 * @return void
	 */
	public function hook_remove( array $settings, $remote ) {
		foreach ( array_filter( explode( ',', (string) $remote ) ) as $event ) {
			$this->http( 'DELETE', $this->host( $settings ) . '/v3/domains/' . rawurlencode( $settings['domain'] ) . '/webhooks/' . rawurlencode( $event ), $this->auth( $settings ) );
		}
	}

	/**
	 * Whether Mailgun verified the DKIM record of the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', $this->host( $settings ) . '/v3/domains/' . rawurlencode( $domain ), $this->auth( $settings ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return null;
		}
		foreach ( isset( $res['json']['sending_dns_records'] ) ? (array) $res['json']['sending_dns_records'] : array() as $r ) {
			if ( isset( $r['name'] ) && false !== strpos( (string) $r['name'], '_domainkey' ) ) {
				return isset( $r['valid'] ) && 'valid' === $r['valid'];
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
		return array( 'mx', 'k1', 'smtp', 'krs', 'pic', 'mailo' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'mailgun.org';
	}
}
