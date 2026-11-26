<?php
/**
 * SMTP2GO (email API v3).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP2GO.
 */
final class Provider_Smtp2go extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'smtp2go';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'SMTP2GO';
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In SMTP2GO › Sending › API Keys, create a key that can send emails and paste it here.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://support.smtp2go.com/hc/en-gb/articles/20733554698137-API-Keys';
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
		return 100;
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
			'sender'  => Message::format( $msg['from'] ),
			'to'      => $list( $msg['to'] ),
			'subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$body['html_body'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$body['text_body'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$body['cc'] = $list( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$body['bcc'] = $list( $msg['bcc'] );
		}
		$headers = $msg['headers'];
		if ( $msg['reply_to'] ) {
			$headers['Reply-To'] = implode( ', ', $list( $msg['reply_to'] ) );
		}
		foreach ( $headers as $name => $value ) {
			$body['custom_headers'][] = array(
				'header' => $name,
				'value'  => $value,
			);
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$body['attachments'][] = array(
				'filename' => $file['name'],
				'fileblob' => $file['content'],
				'mimetype' => $file['type'],
			);
		}
		unset( $context );
		$res = $this->http( 'POST', 'https://api.smtp2go.com/v3/email/send', array( 'X-Smtp2go-Api-Key' => $settings['api_key'] ), $body, 30 );
		if ( is_wp_error( $res ) ) {
			return $this->result( $res );
		}
		$data = isset( $res['json']['data'] ) ? $res['json']['data'] : array();
		if ( $res['code'] < 300 && ! empty( $data['succeeded'] ) ) {
			return $this->ok( isset( $data['email_id'] ) ? (string) $data['email_id'] : '', 'succeeded ' . (int) $data['succeeded'] );
		}
		$error = isset( $data['error'] ) ? (string) $data['error'] : ( isset( $data['failures'][0] ) ? (string) $data['failures'][0] : '' );
		return $this->result( array_merge( $res, array( 'code' => $res['code'] < 300 ? 400 : $res['code'] ) ), '', $error );
	}

	/**
	 * Checks the key (a key without the statistics right still sends: that answer counts as good).
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$res = $this->http( 'POST', 'https://api.smtp2go.com/v3/stats/email_cycle', array( 'X-Smtp2go-Api-Key' => $settings['api_key'] ), array() );
		if ( ! is_wp_error( $res ) && 403 === $res['code'] && false !== stripos( $res['body'], 'permission' ) ) {
			return true;
		}
		return $this->checked( $res );
	}
	/**
	 * Events: SMTP2GO can add the address when the key may manage webhooks.
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
		return __( 'Or by hand: in SMTP2GO › Reports › Webhooks (or Settings › Webhooks), add this address, choose JSON and tick Delivered, Bounce, Spam and Reject.', 'aluleth-smtp' );
	}

	/**
	 * Reads an SMTP2GO event (JSON or form).
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $query, $settings );
		$e = json_decode( (string) $body, true );
		if ( ! is_array( $e ) ) {
			$e = array();
			parse_str( (string) $body, $e );
		}
		$event = isset( $e['event'] ) ? (string) $e['event'] : '';
		switch ( $event ) {
			case 'delivered':
				$type = 'delivered';
				break;
			case 'bounce':
				$type = isset( $e['bounce'] ) && 'hard' === $e['bounce'] ? 'hard_bounce' : 'soft_bounce';
				break;
			case 'spam':
				$type = 'complaint';
				break;
			case 'reject':
				$type = 'rejected';
				break;
			case 'unsubscribe':
				$type = 'unsubscribed';
				break;
			default:
				return array();
		}
		$detail = trim( ( isset( $e['message'] ) ? (string) $e['message'] : '' ) . ( ! empty( $e['host'] ) ? ' (' . $e['host'] . ')' : '' ) );
		return array(
			array(
				'type'       => $type,
				'email'      => isset( $e['rcpt'] ) ? (string) $e['rcpt'] : ( isset( $e['email'] ) ? (string) $e['email'] : '' ),
				'message_id' => isset( $e['email_id'] ) ? (string) $e['email_id'] : '',
				'ref'        => '',
				'detail'     => $detail,
				'id'         => '',
				'time'       => isset( $e['time'] ) ? $this->time_of( $e['time'] ) : 0,
			),
		);
	}

	/**
	 * Adds the webhook through the API.
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
			'https://api.smtp2go.com/v3/webhook/add',
			array( 'X-Smtp2go-Api-Key' => $settings['api_key'] ),
			array(
				'url'           => $url,
				'events'        => array( 'delivered', 'bounce', 'spam', 'reject' ),
				'output_format' => 'json',
			)
		);
		if ( ! is_wp_error( $res ) && in_array( $res['code'], array( 401, 403 ), true ) ) {
			return new \WP_Error( 'alsmtp_hook', __( 'This SMTP2GO key cannot add webhooks. Add the address by hand, or allow the webhook endpoints on the key.', 'aluleth-smtp' ) );
		}
		$check = $this->checked( $res );
		if ( true !== $check ) {
			return $check;
		}
		$data = isset( $res['json']['data'] ) ? (array) $res['json']['data'] : array();
		if ( ! empty( $data['error'] ) || ! empty( $data['error_code'] ) ) {
			return new \WP_Error( 'alsmtp_hook', (string) ( isset( $data['error'] ) ? $data['error'] : $data['error_code'] ) );
		}
		$id = isset( $data['id'] ) ? $data['id'] : ( isset( $data['webhook_id'] ) ? $data['webhook_id'] : '' );
		return array( 'remote' => (string) $id );
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
			$this->http( 'POST', 'https://api.smtp2go.com/v3/webhook/remove', array( 'X-Smtp2go-Api-Key' => $settings['api_key'] ), array( 'id' => is_numeric( $remote ) ? (int) $remote : (string) $remote ) );
		}
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'spf.smtp2go.com';
	}
}
