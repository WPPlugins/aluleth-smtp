<?php
/**
 * Slack: a message in a channel for each entry, through an incoming webhook.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Slack service.
 */
final class Service_Slack extends Service {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'slack';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Slack';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'A message in a Slack channel for each entry.', 'aluleth-smtp' );
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'webhook';
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'alerts';
	}

	/**
	 * Settings offered.
	 *
	 * @return string[]
	 */
	public function supports() {
		return array( 'consent_choice', 'alert' );
	}

	/**
	 * A test can be sent from the builders.
	 *
	 * @return bool
	 */
	public function testable() {
		return true;
	}

	/**
	 * Address field.
	 *
	 * @return array
	 */
	public function url_field() {
		return array(
			'label'       => __( 'Slack webhook address', 'aluleth-smtp' ),
			'placeholder' => 'https://hooks.slack.com/services/…',
			'help'        => __( 'In Slack: Tools › Apps › Incoming Webhooks (or api.slack.com/apps › your app › Incoming Webhooks › Add New Webhook), choose the channel, then copy the address.', 'aluleth-smtp' ),
			'prefix'      => 'https://hooks.slack.com/',
		);
	}

	/**
	 * Connections screen text.
	 *
	 * @return string
	 */
	public function ready_text() {
		return __( 'No account to connect here: paste the channel’s webhook address in the form’s Settings › Integrations.', 'aluleth-smtp' );
	}

	/**
	 * Note in the builders.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'Each entry is posted with its answers and a link to it. Answers can hold personal data: post them only in a private channel.', 'aluleth-smtp' );
	}

	/**
	 * Escapes text for Slack's mrkdwn.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	/**
	 * Message body.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public static function message( array $payload ) {
		$a     = self::alert( $payload );
		$lines = array();
		foreach ( $a['lines'] as $line ) {
			$lines[] = '*' . self::esc( $line['label'] ) . '*' . "\n" . self::esc( $line['value'] );
		}
		$blocks = array(
			array(
				'type' => 'header',
				'text' => array(
					'type' => 'plain_text',
					'text' => mb_substr( ( $a['test'] ? '[' . __( 'Test', 'aluleth-smtp' ) . '] ' : '' ) . $a['title'], 0, 150 ),
				),
			),
		);
		foreach ( array_chunk( $lines, 10 ) as $chunk ) {
			$blocks[] = array(
				'type'   => 'section',
				'fields' => array_map(
					static function ( $text ) {
						return array(
							'type' => 'mrkdwn',
							'text' => mb_substr( $text, 0, 2000 ),
						);
					},
					$chunk
				),
			);
		}
		$context = array();
		if ( '' !== $a['payment'] ) {
			$context[] = ':moneybag: ' . self::esc( $a['payment'] );
		}
		if ( '' !== $a['link'] ) {
			$context[] = '<' . esc_url_raw( $a['link'] ) . '|' . self::esc( __( 'Open the entry', 'aluleth-smtp' ) ) . '>';
		}
		if ( $context ) {
			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => implode( '  ·  ', $context ),
				),
			);
		}
		return array(
			'text'   => $a['title'],
			'blocks' => $blocks,
		);
	}

	/**
	 * Posts the message.
	 *
	 * @param array $feed    Feed: url.
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		$url = isset( $feed['url'] ) ? (string) $feed['url'] : '';
		if ( 0 !== strpos( $url, 'https://hooks.slack.com/' ) ) {
			return self::fail( __( 'The Slack webhook address is missing or not valid (it starts with https://hooks.slack.com/).', 'aluleth-smtp' ) );
		}
		$response = self::http( 'POST', $url, array(), self::message( $payload ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 === $response['code'] ) {
			return __( 'Posted in Slack.', 'aluleth-smtp' );
		}
		if ( 404 === $response['code'] || 403 === $response['code'] || 410 === $response['code'] ) {
			return self::fail( __( 'Slack refused the address: the webhook may have been removed. Create a new one and paste it in the form.', 'aluleth-smtp' ) );
		}
		return $this->http_error( $response );
	}
}
