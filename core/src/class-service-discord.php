<?php
/**
 * Discord: a message in a channel for each entry, through a channel webhook.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Discord service.
 */
final class Service_Discord extends Service {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'discord';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Discord';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'A message in a Discord channel for each entry.', 'aluleth-smtp' );
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
			'label'       => __( 'Discord webhook address', 'aluleth-smtp' ),
			'placeholder' => 'https://discord.com/api/webhooks/…',
			'help'        => __( 'In Discord: the channel’s settings › Integrations › Webhooks › New Webhook › Copy Webhook URL.', 'aluleth-smtp' ),
			'prefix'      => 'https://discord.com/api/webhooks/',
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
	 * Whether an address is a Discord webhook.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	private static function valid( $url ) {
		return (bool) preg_match( '#^https://(?:(?:canary|ptb)\.)?(?:discord|discordapp)\.com/api/webhooks/\d+/[\w\-]+#', (string) $url );
	}

	/**
	 * Message body (one embed).
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public static function message( array $payload ) {
		$a      = self::alert( $payload );
		$fields = array();
		foreach ( $a['lines'] as $line ) {
			$fields[] = array(
				'name'   => mb_substr( $line['label'], 0, 256 ),
				'value'  => mb_substr( $line['value'], 0, 1024 ),
				'inline' => mb_strlen( $line['value'] ) < 40,
			);
		}
		$embed = array(
			'title'  => mb_substr( ( $a['test'] ? '[' . __( 'Test', 'aluleth-smtp' ) . '] ' : '' ) . $a['title'], 0, 256 ),
			'color'  => 0xFF6A1F,
			'fields' => $fields,
		);
		if ( '' !== $a['link'] ) {
			$embed['url'] = esc_url_raw( $a['link'] );
		}
		if ( '' !== $a['payment'] ) {
			$embed['description'] = $a['payment'];
		}
		return array(
			'username'         => mb_substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 80 ),
			'embeds'           => array( $embed ),
			'allowed_mentions' => array( 'parse' => array() ),
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
		if ( ! self::valid( $url ) ) {
			return self::fail( __( 'The Discord webhook address is missing or not valid (it starts with https://discord.com/api/webhooks/).', 'aluleth-smtp' ) );
		}
		$response = self::http( 'POST', $url, array(), self::message( $payload ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( in_array( $response['code'], array( 200, 204 ), true ) ) {
			return __( 'Posted in Discord.', 'aluleth-smtp' );
		}
		if ( 404 === $response['code'] || 401 === $response['code'] ) {
			return self::fail( __( 'Discord refused the address: the webhook may have been deleted. Create a new one and paste it in the form.', 'aluleth-smtp' ) );
		}
		return $this->http_error( $response, isset( $response['body']['message'] ) ? (string) $response['body']['message'] : '' );
	}
}
