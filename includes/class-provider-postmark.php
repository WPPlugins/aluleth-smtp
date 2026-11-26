<?php
/**
 * Postmark (email API).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Postmark.
 */
final class Provider_Postmark extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'postmark';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Postmark';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · fast receipts', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Postmark, open your server › API Tokens and copy the Server API token. Marketing emails can use a broadcast stream.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://postmarkapp.com/support/article/1008-what-are-the-account-and-server-api-tokens';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'token'            => array(
				'label'    => __( 'Server API token', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
			'stream'           => array(
				'label'       => __( 'Message stream', 'aluleth-smtp' ),
				'type'        => 'text',
				'placeholder' => 'outbound',
			),
			'marketing_stream' => array(
				'label'       => __( 'Stream for marketing emails (optional)', 'aluleth-smtp' ),
				'type'        => 'text',
				'placeholder' => 'broadcast',
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
		$join = static function ( array $items ) {
			return implode( ', ', array_map( array( Message::class, 'format' ), $items ) );
		};
		$body = array(
			'From'    => Message::format( $msg['from'] ),
			'To'      => $join( $msg['to'] ),
			'Subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$body['HtmlBody'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$body['TextBody'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$body['Cc'] = $join( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$body['Bcc'] = $join( $msg['bcc'] );
		}
		if ( $msg['reply_to'] ) {
			$body['ReplyTo'] = $join( $msg['reply_to'] );
		}
		foreach ( $msg['headers'] as $name => $value ) {
			$body['Headers'][] = array(
				'Name'  => $name,
				'Value' => $value,
			);
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['Attachments'][] = array(
				'Name'        => $file['name'],
				'Content'     => $file['content'],
				'ContentType' => $file['type'],
			);
		}
		$stream = ! empty( $settings['stream'] ) ? $settings['stream'] : '';
		if ( ! empty( $context['kind'] ) && 'marketing' === $context['kind'] && ! empty( $settings['marketing_stream'] ) ) {
			$stream = $settings['marketing_stream'];
		}
		if ( '' !== $stream ) {
			$body['MessageStream'] = $stream;
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$body['Metadata'] = array( 'aluleth_id' => $this->ref( $context ) );
		}
		$res = $this->http( 'POST', 'https://api.postmarkapp.com/email', array( 'X-Postmark-Server-Token' => $settings['token'] ), $body, 30 );
		if ( ! is_wp_error( $res ) && 422 === $res['code'] && isset( $res['json']['ErrorCode'] ) && 406 === (int) $res['json']['ErrorCode'] ) {
			return $this->fail( __( 'Postmark: this address is inactive (it bounced or marked an email as spam).', 'aluleth-smtp' ), false, 422 );
		}
		$id = ! is_wp_error( $res ) && isset( $res['json']['MessageID'] ) ? (string) $res['json']['MessageID'] : '';
		return $this->result( $res, $id );
	}

	/**
	 * Checks the token.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		return $this->checked( $this->http( 'GET', 'https://api.postmarkapp.com/server', array( 'X-Postmark-Server-Token' => $settings['token'] ) ) );
	}
	/**
	 * Events: Postmark can add the address for you.
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
		return __( 'Or by hand: in Postmark, open your server › your message stream › Webhooks, add a webhook with this address and tick Delivery, Bounce and Spam complaint.', 'aluleth-smtp' );
	}

	/**
	 * Reads a Postmark event.
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
		foreach ( $this->json_list( $body ) as $e ) {
			if ( ! is_array( $e ) || empty( $e['RecordType'] ) ) {
				continue;
			}
			$kind = isset( $e['Type'] ) ? (string) $e['Type'] : '';
			switch ( $e['RecordType'] ) {
				case 'Delivery':
					$type = 'delivered';
					break;
				case 'SpamComplaint':
					$type = 'complaint';
					break;
				case 'Bounce':
					if ( in_array( $kind, array( 'HardBounce', 'BadEmailAddress' ), true ) ) {
						$type = 'hard_bounce';
					} elseif ( in_array( $kind, array( 'SoftBounce', 'Transient', 'DnsError', 'AutoResponder', 'ChallengeVerification' ), true ) ) {
						$type = in_array( $kind, array( 'AutoResponder', 'ChallengeVerification' ), true ) ? 'deferred' : 'soft_bounce';
					} elseif ( 'SpamComplaint' === $kind ) {
						$type = 'complaint';
					} else {
						$type = 'rejected';
					}
					break;
				case 'SubscriptionChange':
					if ( empty( $e['SuppressSending'] ) ) {
						continue 2;
					}
					$type = 'unsubscribed';
					break;
				default:
					continue 2;
			}
			$detail = trim( ( isset( $e['Name'] ) ? $e['Name'] . ': ' : '' ) . ( ! empty( $e['Details'] ) ? (string) $e['Details'] : ( isset( $e['Description'] ) ? (string) $e['Description'] : '' ) ), ': ' );
			$when   = isset( $e['BouncedAt'] ) ? $e['BouncedAt'] : ( isset( $e['DeliveredAt'] ) ? $e['DeliveredAt'] : ( isset( $e['ChangedAt'] ) ? $e['ChangedAt'] : '' ) );
			$out[]  = array(
				'type'       => $type,
				'email'      => isset( $e['Email'] ) ? (string) $e['Email'] : ( isset( $e['Recipient'] ) ? (string) $e['Recipient'] : '' ),
				'message_id' => isset( $e['MessageID'] ) ? (string) $e['MessageID'] : '',
				'ref'        => isset( $e['Metadata']['aluleth_id'] ) ? (string) $e['Metadata']['aluleth_id'] : '',
				'detail'     => $detail,
				'id'         => isset( $e['ID'] ) ? $e['RecordType'] . '-' . $e['ID'] : '',
				'time'       => $this->time_of( $when ),
			);
		}
		return $out;
	}

	/**
	 * Adds the webhook to the message streams used (and the broadcast stream).
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$streams = array_unique( array_filter( array( ! empty( $settings['stream'] ) ? $settings['stream'] : 'outbound', ! empty( $settings['marketing_stream'] ) ? $settings['marketing_stream'] : '' ) ) );
		$ids     = array();
		foreach ( $streams as $stream ) {
			$res   = $this->http(
				'POST',
				'https://api.postmarkapp.com/webhooks',
				array( 'X-Postmark-Server-Token' => $settings['token'] ),
				array(
					'Url'           => $url,
					'MessageStream' => $stream,
					'Triggers'      => array(
						'Delivery'      => array( 'Enabled' => true ),
						'Bounce'        => array(
							'Enabled'        => true,
							'IncludeContent' => false,
						),
						'SpamComplaint' => array(
							'Enabled'        => true,
							'IncludeContent' => false,
						),
					),
				)
			);
			$check = $this->checked( $res );
			if ( true !== $check ) {
				if ( $ids ) {
					$this->hook_remove( $settings, implode( ',', $ids ) );
				}
				return $check;
			}
			$ids[] = isset( $res['json']['ID'] ) ? (string) $res['json']['ID'] : '';
		}
		return array( 'remote' => implode( ',', array_filter( $ids ) ) );
	}

	/**
	 * Removes the webhooks.
	 *
	 * @param array  $settings Settings.
	 * @param string $remote   Ids, comma separated.
	 * @return void
	 */
	public function hook_remove( array $settings, $remote ) {
		foreach ( array_filter( explode( ',', (string) $remote ) ) as $id ) {
			$this->http( 'DELETE', 'https://api.postmarkapp.com/webhooks/' . rawurlencode( $id ), array( 'X-Postmark-Server-Token' => $settings['token'] ) );
		}
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'spf.mtasv.net';
	}
}
