<?php
/**
 * Telegram: a message from your own bot to you, a group or a channel, for each entry.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Telegram service.
 */
final class Service_Telegram extends Service_Api {

	/**
	 * Chats the bot has seen (Telegram keeps updates only 24 hours).
	 */
	const CHATS = 'aluleth_telegram_chats';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'telegram';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Telegram';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'A message from your own Telegram bot to you, a group or a channel, for each entry.', 'aluleth-smtp' );
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
		return array( 'lists', 'consent_choice', 'alert' );
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
	 * Name of the lists.
	 *
	 * @return string
	 */
	public function list_label() {
		return __( 'Send to', 'aluleth-smtp' );
	}

	/**
	 * Note in the builders.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'A chat appears here once someone wrote to your bot (send it /start) or once the bot was added to the group or channel. Then click Refresh.', 'aluleth-smtp' );
	}

	/**
	 * Credential field.
	 *
	 * @return array<string, array>
	 */
	public function credential_fields() {
		return array(
			'key' => array(
				'label' => __( 'Bot token', 'aluleth-smtp' ),
				'help'  => __( 'In Telegram, write to @BotFather: /newbot, choose a name, then copy the token it gives you. Then send /start to your new bot (or add it to your group).', 'aluleth-smtp' ),
			),
		);
	}

	/**
	 * Calls the Bot API.
	 *
	 * @param string     $method Method name, e.g. getMe.
	 * @param array|null $body   JSON body.
	 * @param string     $token  Token (default: the saved one).
	 * @return array|\WP_Error
	 */
	private function call( $method, $body = null, $token = '' ) {
		$token = '' !== $token ? $token : $this->key();
		if ( ! preg_match( '/^\d+:[\w\-]+$/', $token ) ) {
			return self::fail( __( 'This does not look like a bot token (numbers, a colon, then letters).', 'aluleth-smtp' ) );
		}
		return self::http( null === $body ? 'GET' : 'POST', 'https://api.telegram.org/bot' . $token . '/' . $method, array(), $body );
	}

	/**
	 * Checks the token.
	 *
	 * @param array $credentials Credentials.
	 * @return string|\WP_Error Bot name.
	 */
	public function verify( array $credentials ) {
		$response = $this->call( 'getMe', null, isset( $credentials['key'] ) ? (string) $credentials['key'] : '' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] || empty( $response['body']['result']['username'] ) ) {
			return self::fail( __( 'Telegram did not accept this bot token. Copy it again from @BotFather.', 'aluleth-smtp' ) );
		}
		return '@' . $response['body']['result']['username'];
	}

	/**
	 * Chats the bot can write to (new ones read from its recent updates).
	 *
	 * @return array<string, string>
	 */
	public function lists() {
		return $this->cached(
			'lists',
			function () {
				$known    = get_option( self::CHATS, array() );
				$known    = is_array( $known ) ? $known : array();
				$response = $this->call( 'getUpdates', array( 'allowed_updates' => array( 'message', 'channel_post', 'my_chat_member' ) ) );
				if ( ! is_wp_error( $response ) && 200 === $response['code'] && ! empty( $response['body']['result'] ) ) {
					foreach ( (array) $response['body']['result'] as $update ) {
						foreach ( array( 'message', 'channel_post', 'my_chat_member' ) as $kind ) {
							if ( empty( $update[ $kind ]['chat']['id'] ) ) {
								continue;
							}
							$chat = $update[ $kind ]['chat'];
							$name = ! empty( $chat['title'] ) ? $chat['title'] : trim( ( isset( $chat['first_name'] ) ? $chat['first_name'] : '' ) . ' ' . ( isset( $chat['last_name'] ) ? $chat['last_name'] : '' ) );
							if ( 'private' === $chat['type'] ) {
								/* translators: %s: person's name in Telegram. */
								$name = sprintf( __( '%s (private chat)', 'aluleth-smtp' ), $name );
							}
							$known[ (string) $chat['id'] ] = sanitize_text_field( $name );
						}
					}
					update_option( self::CHATS, array_slice( $known, -50, null, true ), false );
				}
				return $known;
			}
		);
	}

	/**
	 * Escapes text for Telegram's HTML mode.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	/**
	 * Message text.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	public static function text( array $payload ) {
		$a   = self::alert( $payload );
		$out = '<b>' . self::esc( ( $a['test'] ? '[' . __( 'Test', 'aluleth-smtp' ) . '] ' : '' ) . $a['title'] ) . '</b>' . "\n";
		foreach ( $a['lines'] as $line ) {
			$out .= "\n<b>" . self::esc( $line['label'] ) . '</b>: ' . self::esc( $line['value'] );
		}
		if ( '' !== $a['payment'] ) {
			$out .= "\n\n" . self::esc( $a['payment'] );
		}
		if ( '' !== $a['link'] ) {
			$out .= "\n\n" . '<a href="' . esc_url( $a['link'] ) . '">' . self::esc( __( 'Open the entry', 'aluleth-smtp' ) ) . '</a>';
		}
		return mb_substr( $out, 0, 4000 );
	}

	/**
	 * Sends the message to each chosen chat.
	 *
	 * @param array $feed    Feed: lists (chat ids).
	 * @param array $payload Payload.
	 * @return string|\WP_Error
	 */
	public function send( array $feed, array $payload ) {
		if ( ! $this->connected() ) {
			return self::fail( $this->missing_text() );
		}
		$chats = array_values( array_filter( array_map( 'strval', (array) $feed['lists'] ) ) );
		if ( ! $chats ) {
			return self::fail( __( 'No Telegram chat is chosen for this form.', 'aluleth-smtp' ) );
		}
		$text = self::text( $payload );
		$sent = 0;
		foreach ( $chats as $chat ) {
			$response = $this->call(
				'sendMessage',
				array(
					'chat_id'                  => $chat,
					'text'                     => $text,
					'parse_mode'               => 'HTML',
					'disable_web_page_preview' => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 200 !== $response['code'] ) {
				return $this->http_error( $response, isset( $response['body']['description'] ) ? (string) $response['body']['description'] : '' );
			}
			++$sent;
		}
		/* translators: %d: number of chats. */
		return sprintf( _n( 'Sent to %d Telegram chat.', 'Sent to %d Telegram chats.', $sent, 'aluleth-smtp' ), $sent );
	}
}
