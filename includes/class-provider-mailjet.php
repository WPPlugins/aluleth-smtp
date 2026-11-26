<?php
/**
 * Mailjet (Send API v3.1).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Mailjet.
 */
final class Provider_Mailjet extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'mailjet';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Mailjet';
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Mailjet › Account settings › API key management, copy the API key and the secret key.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://app.mailjet.com/account/apikeys';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'api_key'    => array(
				'label'    => __( 'API key', 'aluleth-smtp' ),
				'type'     => 'text',
				'required' => true,
			),
			'secret_key' => array(
				'label'    => __( 'Secret key', 'aluleth-smtp' ),
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
	 * Authorization header.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private function auth( array $settings ) {
		return array( 'Authorization' => 'Basic ' . base64_encode( $settings['api_key'] . ':' . $settings['secret_key'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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
		$m = array(
			'From'    => $this->people( array( $msg['from'] ), 'Email', 'Name' )[0],
			'To'      => $this->people( $msg['to'], 'Email', 'Name' ),
			'Subject' => $msg['subject'],
		);
		if ( '' !== $msg['html'] ) {
			$m['HTMLPart'] = $msg['html'];
		}
		if ( '' !== $msg['text'] ) {
			$m['TextPart'] = $msg['text'];
		}
		if ( $msg['cc'] ) {
			$m['Cc'] = $this->people( $msg['cc'], 'Email', 'Name' );
		}
		if ( $msg['bcc'] ) {
			$m['Bcc'] = $this->people( $msg['bcc'], 'Email', 'Name' );
		}
		if ( $msg['reply_to'] ) {
			$m['ReplyTo'] = $this->people( $msg['reply_to'], 'Email', 'Name' )[0];
		}
		if ( $msg['headers'] ) {
			$m['Headers'] = (object) $msg['headers'];
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$m['Attachments'][] = array(
				'ContentType'   => $file['type'],
				'Filename'      => $file['name'],
				'Base64Content' => $file['content'],
			);
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$m['CustomID'] = $this->ref( $context );
		}
		$res = $this->http( 'POST', 'https://api.mailjet.com/v3.1/send', $this->auth( $settings ), array( 'Messages' => array( $m ) ), 30 );
		if ( is_wp_error( $res ) ) {
			return $this->result( $res );
		}
		$first = isset( $res['json']['Messages'][0] ) ? $res['json']['Messages'][0] : array();
		if ( $res['code'] < 300 && isset( $first['Status'] ) && 'success' === $first['Status'] ) {
			$id = isset( $first['To'][0]['MessageID'] ) ? (string) $first['To'][0]['MessageID'] : '';
			return $this->ok( $id, 'success' );
		}
		$error = isset( $first['Errors'][0]['ErrorMessage'] ) ? (string) $first['Errors'][0]['ErrorMessage'] : '';
		return $this->result( array_merge( $res, array( 'code' => $res['code'] < 300 ? 400 : $res['code'] ) ), '', $error );
	}

	/**
	 * Checks the keys.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		return $this->checked( $this->http( 'GET', 'https://api.mailjet.com/v3/REST/myprofile', $this->auth( $settings ) ) );
	}
	/**
	 * Events: Mailjet can add the address for you.
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
		return __( 'Or by hand: in Mailjet › Account settings › Event notifications (webhooks), paste this address for Sent, Bounce, Blocked and Spam.', 'aluleth-smtp' );
	}

	/**
	 * Reads Mailjet events (one object, or a list with grouped events).
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
			if ( ! is_array( $e ) || empty( $e['event'] ) ) {
				continue;
			}
			switch ( $e['event'] ) {
				case 'sent':
					$type = 'delivered';
					break;
				case 'bounce':
					$type = ! empty( $e['hard_bounce'] ) ? 'hard_bounce' : 'soft_bounce';
					break;
				case 'blocked':
					$type = 'rejected';
					break;
				case 'spam':
					$type = 'complaint';
					break;
				case 'unsub':
					$type = 'unsubscribed';
					break;
				default:
					continue 2;
			}
			$detail = trim( implode( ': ', array_filter( array( isset( $e['error_related_to'] ) ? (string) $e['error_related_to'] : '', isset( $e['error'] ) ? (string) $e['error'] : '' ) ) ) . ( ! empty( $e['comment'] ) ? ' · ' . $e['comment'] : ( ! empty( $e['smtp_reply'] ) ? ' ' . $e['smtp_reply'] : '' ) ) );
			$out[]  = array(
				'type'       => $type,
				'email'      => isset( $e['email'] ) ? (string) $e['email'] : '',
				'message_id' => isset( $e['MessageID'] ) ? (string) $e['MessageID'] : '',
				'ref'        => isset( $e['CustomID'] ) ? (string) $e['CustomID'] : '',
				'detail'     => $detail,
				'id'         => '',
				'time'       => isset( $e['time'] ) ? (int) $e['time'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Adds the address for the four events.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $c );
		$ids = array();
		foreach ( array( 'sent', 'bounce', 'blocked', 'spam' ) as $event ) {
			$res = $this->http(
				'POST',
				'https://api.mailjet.com/v3/REST/eventcallbackurl',
				$this->auth( $settings ),
				array(
					'EventType' => $event,
					'Url'       => $url,
					'Version'   => 2,
				)
			);
			if ( ! is_wp_error( $res ) && 400 === $res['code'] && false !== stripos( $res['body'], 'already' ) ) {
				$this->hook_remove( $settings, implode( ',', $ids ) );
				/* translators: %s: event name. */
				return new \WP_Error( 'alsmtp_hook', sprintf( __( 'Mailjet already sends “%s” events to another address. Replace it by hand with this one.', 'aluleth-smtp' ), $event ) );
			}
			$check = $this->checked( $res );
			if ( true !== $check ) {
				$this->hook_remove( $settings, implode( ',', $ids ) );
				return $check;
			}
			if ( isset( $res['json']['Data'][0]['ID'] ) ) {
				$ids[] = (string) $res['json']['Data'][0]['ID'];
			}
		}
		return array( 'remote' => implode( ',', $ids ) );
	}

	/**
	 * Removes the addresses the plugin added.
	 *
	 * @param array  $settings Settings.
	 * @param string $remote   Ids, comma separated.
	 * @return void
	 */
	public function hook_remove( array $settings, $remote ) {
		foreach ( array_filter( explode( ',', (string) $remote ) ) as $id ) {
			$this->http( 'DELETE', 'https://api.mailjet.com/v3/REST/eventcallbackurl/' . rawurlencode( $id ), $this->auth( $settings ) );
		}
	}

	/**
	 * Whether Mailjet found the DKIM record of the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', 'https://api.mailjet.com/v3/REST/dns/' . rawurlencode( $domain ), $this->auth( $settings ) );
		if ( is_wp_error( $res ) || ! in_array( $res['code'], array( 200, 404 ), true ) ) {
			return null;
		}
		if ( 404 === $res['code'] ) {
			return false;
		}
		$d = isset( $res['json']['Data'][0] ) ? (array) $res['json']['Data'][0] : array();
		return isset( $d['DKIMStatus'] ) && 'OK' === $d['DKIMStatus'];
	}

	/**
	 * DKIM selectors.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 'mailjet' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'spf.mailjet.com';
	}
}
