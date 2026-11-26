<?php
/**
 * Elastic Email (API v4).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Elastic Email.
 */
final class Provider_Elasticemail extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'elasticemail';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Elastic Email';
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In Elastic Email › Settings › Manage API keys, create a key that can send emails and paste it here.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://help.elasticemail.com/en/articles/5162182-how-to-create-an-api-key';
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
		$list  = static function ( array $a ) {
			return array_map( array( Message::class, 'format' ), $a );
		};
		$parts = array();
		if ( '' !== $msg['html'] ) {
			$parts[] = array(
				'ContentType' => 'HTML',
				'Content'     => $msg['html'],
			);
		}
		if ( '' !== $msg['text'] ) {
			$parts[] = array(
				'ContentType' => 'PlainText',
				'Content'     => $msg['text'],
			);
		}
		$content = array(
			'From'    => Message::format( $msg['from'] ),
			'Subject' => $msg['subject'],
			'Body'    => $parts,
		);
		if ( $msg['reply_to'] ) {
			$content['ReplyTo'] = Message::format( $msg['reply_to'][0] );
		}
		if ( $msg['headers'] ) {
			$content['Headers'] = (object) $msg['headers'];
		}
		foreach ( Message::attachments_base64( $msg ) as $file ) {
			$content['Attachments'][] = array(
				'BinaryContent' => $file['content'],
				'Name'          => $file['name'],
				'ContentType'   => $file['type'],
			);
		}
		$recipients = array( 'To' => $list( $msg['to'] ) );
		if ( $msg['cc'] ) {
			$recipients['CC'] = $list( $msg['cc'] );
		}
		if ( $msg['bcc'] ) {
			$recipients['BCC'] = $list( $msg['bcc'] );
		}
		$body = array(
			'Recipients' => $recipients,
			'Content'    => $content,
		);
		if ( ! empty( $context['kind'] ) && 'marketing' === $context['kind'] ) {
			$body['Options'] = array( 'ChannelName' => 'marketing' );
		}
		$res = $this->http( 'POST', 'https://api.elasticemail.com/v4/emails/transactional', array( 'X-ElasticEmail-ApiKey' => $settings['api_key'] ), $body, 30 );
		// The transaction covers every recipient; notifications carry it.
		$id = ! is_wp_error( $res ) && isset( $res['json']['TransactionID'] ) ? (string) $res['json']['TransactionID'] : ( ! is_wp_error( $res ) && isset( $res['json']['MessageID'] ) ? (string) $res['json']['MessageID'] : '' );
		return $this->result( $res, $id );
	}
	/**
	 * Events: added by hand in Elastic Email (Notifications).
	 *
	 * @return string
	 */
	public function hook_support() {
		return 'manual';
	}

	/**
	 * Where to add the address.
	 *
	 * @return string
	 */
	public function hook_help() {
		return __( 'In Elastic Email › Settings › Notifications (webhooks), add a webhook with this address and tick Sent, Bounce/Error, Abuse report and Unsubscribed.', 'aluleth-smtp' );
	}

	/**
	 * Reads an Elastic Email notification (query string, form or JSON).
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $settings );
		$e = $query;
		if ( empty( $e['status'] ) && '' !== (string) $body ) {
			$j = json_decode( (string) $body, true );
			if ( is_array( $j ) ) {
				$e = array_change_key_case( $j, CASE_LOWER );
			} else {
				parse_str( (string) $body, $e );
			}
		}
		$e        = array_change_key_case( (array) $e, CASE_LOWER );
		$status   = isset( $e['status'] ) ? (string) $e['status'] : '';
		$category = isset( $e['category'] ) ? (string) $e['category'] : '';
		switch ( $status ) {
			case 'Sent':
				$type = 'delivered';
				break;
			case 'Error':
			case 'Bounced':
				if ( in_array( $category, array( 'NoMailbox', 'AccountProblem', 'DNSProblem' ), true ) ) {
					$type = 'hard_bounce';
				} elseif ( in_array( $category, array( 'GreyListed', 'Throttled', 'Timeout', 'ConnectionProblem', 'ConnectionTerminated' ), true ) ) {
					$type = 'soft_bounce';
				} else {
					$type = 'rejected';
				}
				break;
			case 'AbuseReport':
				$type = 'complaint';
				break;
			case 'Unsubscribed':
				$type = 'unsubscribed';
				break;
			default:
				return array();
		}
		return array(
			array(
				'type'       => $type,
				'email'      => isset( $e['to'] ) ? (string) $e['to'] : '',
				'message_id' => isset( $e['transaction'] ) ? (string) $e['transaction'] : ( isset( $e['messageid'] ) ? (string) $e['messageid'] : '' ),
				'ref'        => '',
				'detail'     => $category,
				'id'         => isset( $e['messageid'] ) ? $e['messageid'] . '-' . $status : '',
				'time'       => isset( $e['date'] ) ? $this->time_of( $e['date'] ) : 0,
			),
		);
	}

	/**
	 * Whether Elastic Email found the DKIM record of the domain.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->http( 'GET', 'https://api.elasticemail.com/v4/domains/' . rawurlencode( $domain ), array( 'X-ElasticEmail-ApiKey' => $settings['api_key'] ) );
		if ( is_wp_error( $res ) || ! in_array( $res['code'], array( 200, 404 ), true ) ) {
			return null;
		}
		if ( 404 === $res['code'] ) {
			return false;
		}
		return isset( $res['json']['Dkim'] ) ? (bool) $res['json']['Dkim'] : null;
	}

	/**
	 * DKIM selectors.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array( 'api' );
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return '_spf.elasticemail.com';
	}
}
