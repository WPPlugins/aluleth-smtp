<?php
/**
 * SparkPost (Transmissions API).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * SparkPost.
 */
final class Provider_Sparkpost extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'sparkpost';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SparkPost';
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
		return __( 'In SparkPost › Configuration › API Keys, create a key with “Transmissions: Read/Write” and paste it here.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://support.sparkpost.com/docs/getting-started/create-api-keys';
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
					'us' => __( 'US (sparkpost.com)', 'aluleth-smtp' ),
					'eu' => __( 'EU (eu.sparkpost.com)', 'aluleth-smtp' ),
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
		return isset( $settings['region'] ) && 'eu' === $settings['region'] ? 'https://api.eu.sparkpost.com' : 'https://api.sparkpost.com';
	}

	/**
	 * Sends. Cc and Bcc are recipients that see the To header, as SparkPost asks.
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		$to_header  = implode( ', ', array_map( array( Message::class, 'format' ), $msg['to'] ) );
		$recipients = array();
		foreach ( $msg['to'] as $a ) {
			$recipients[] = array(
				'address' => array_filter(
					array(
						'email' => $a['email'],
						'name'  => $a['name'],
					)
				),
			);
		}
		foreach ( array_merge( $msg['cc'], $msg['bcc'] ) as $a ) {
			$recipients[] = array(
				'address' => array(
					'email'     => $a['email'],
					'header_to' => $to_header,
				),
			);
		}
		$content = array(
			'from'    => array_filter(
				array(
					'email' => $msg['from']['email'],
					'name'  => $msg['from']['name'],
				)
			),
			'subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$content['html'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$content['text'] = $msg['text'];
		}
		if ( $msg['reply_to'] ) {
			$content['reply_to'] = Message::format( $msg['reply_to'][0] );
		}
		$headers = $msg['headers'];
		if ( $msg['cc'] ) {
			$headers['CC'] = implode( ', ', array_map( array( Message::class, 'format' ), $msg['cc'] ) );
		}
		if ( $headers ) {
			$content['headers'] = (object) $headers;
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$content['attachments'][] = array(
				'name' => $file['name'],
				'type' => $file['type'],
				'data' => $file['content'],
			);
		}
		$body = array(
			'options'    => array( 'transactional' => empty( $context['kind'] ) || 'marketing' !== $context['kind'] ),
			'recipients' => $recipients,
			'content'    => $content,
		);
		if ( ! empty( $context['mail_id'] ) ) {
			$body['metadata'] = array( 'aluleth_id' => $this->ref( $context ) );
		}
		$res = $this->http( 'POST', $this->host( $settings ) . '/api/v1/transmissions', array( 'Authorization' => $settings['api_key'] ), $body, 30 );
		$id  = ! is_wp_error( $res ) && isset( $res['json']['results']['id'] ) ? (string) $res['json']['results']['id'] : '';
		return $this->result( $res, $id );
	}

	/**
	 * Checks the key.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		return $this->checked( $this->http( 'GET', $this->host( $settings ) . '/api/v1/account', array( 'Authorization' => $settings['api_key'] ) ) );
	}
	/**
	 * Events: the plugin can add the address when the key may manage webhooks.
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
		return __( 'Or by hand: in SparkPost › Configuration › Webhooks, add a webhook with this address, no authentication, and the events Delivery, Bounce, Out of band, Spam complaint, Policy rejection and Delay.', 'aluleth-smtp' );
	}

	/**
	 * Reads SparkPost events (a list).
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $query, $settings );
		$out = array();
		foreach ( $this->json_list( $body ) as $item ) {
			$msys = is_array( $item ) && isset( $item['msys'] ) && is_array( $item['msys'] ) ? $item['msys'] : array();
			$e    = $msys ? reset( $msys ) : null;
			if ( ! is_array( $e ) || empty( $e['type'] ) ) {
				continue;
			}
			$class = isset( $e['bounce_class'] ) ? (int) $e['bounce_class'] : 0;
			switch ( $e['type'] ) {
				case 'delivery':
					$type = 'delivered';
					break;
				case 'bounce':
				case 'out_of_band':
					if ( in_array( $class, array( 10, 30, 90 ), true ) ) {
						$type = 'hard_bounce';
					} elseif ( in_array( $class, array( 1, 20, 21, 22, 23, 24, 40, 60, 70, 100 ), true ) ) {
						$type = 'soft_bounce';
					} else {
						$type = 'rejected';
					}
					break;
				case 'spam_complaint':
					$type = 'complaint';
					break;
				case 'policy_rejection':
				case 'generation_failure':
				case 'generation_rejection':
					$type = 'rejected';
					break;
				case 'delay':
					$type = 'deferred';
					break;
				case 'list_unsubscribe':
				case 'link_unsubscribe':
					$type = 'unsubscribed';
					break;
				default:
					continue 2;
			}
			$meta   = array_merge( isset( $e['metadata'] ) && is_array( $e['metadata'] ) ? $e['metadata'] : array(), isset( $e['rcpt_meta'] ) && is_array( $e['rcpt_meta'] ) ? $e['rcpt_meta'] : array() );
			$detail = ! empty( $e['raw_reason'] ) ? (string) $e['raw_reason'] : ( isset( $e['reason'] ) ? (string) $e['reason'] : '' );
			if ( $class && 'delivered' !== $type ) {
				/* translators: %d: SparkPost bounce class number. */
				$detail = trim( sprintf( __( 'class %d', 'aluleth-smtp' ), $class ) . ' · ' . $detail, ' ·' );
			}
			$out[] = array(
				'type'       => $type,
				'email'      => isset( $e['rcpt_to'] ) ? (string) $e['rcpt_to'] : '',
				'message_id' => isset( $e['transmission_id'] ) ? (string) $e['transmission_id'] : '',
				'ref'        => isset( $meta['aluleth_id'] ) ? (string) $meta['aluleth_id'] : '',
				'detail'     => $detail,
				'id'         => isset( $e['event_id'] ) ? (string) $e['event_id'] : '',
				'time'       => isset( $e['timestamp'] ) ? $this->time_of( $e['timestamp'] ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Adds the webhook through the API (SparkPost sends a test first).
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
			$this->host( $settings ) . '/api/v1/webhooks',
			array( 'Authorization' => $settings['api_key'] ),
			array(
				'name'      => 'Aluleth SMTP',
				'target'    => $url,
				'auth_type' => 'none',
				'events'    => array( 'delivery', 'bounce', 'out_of_band', 'spam_complaint', 'policy_rejection', 'delay' ),
			)
		);
		if ( ! is_wp_error( $res ) && in_array( $res['code'], array( 401, 403 ), true ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This SparkPost key cannot add webhooks. Add the address by hand, or give the key “Event Webhooks: Read/Write”.', 'aluleth-smtp' ) );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		return array( 'remote' => isset( $res['json']['results']['id'] ) ? (string) $res['json']['results']['id'] : '' );
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
			$this->http( 'DELETE', $this->host( $settings ) . '/api/v1/webhooks/' . rawurlencode( (string) $remote ), array( 'Authorization' => $settings['api_key'] ) );
		}
	}

	/**
	 * Whether SparkPost verified DKIM for the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', $this->host( $settings ) . '/api/v1/sending-domains/' . rawurlencode( $domain ), array( 'Authorization' => $settings['api_key'] ) );
		if ( is_wp_error( $res ) || ! in_array( $res['code'], array( 200, 404 ), true ) ) {
			return null;
		}
		if ( 404 === $res['code'] ) {
			return false;
		}
		return isset( $res['json']['results']['status']['dkim_status'] ) && 'valid' === $res['json']['results']['status']['dkim_status'];
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return '_spf.sparkpostmail.com';
	}
}
