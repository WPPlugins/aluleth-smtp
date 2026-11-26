<?php
/**
 * An email as Aluleth SMTP handles it: built from what wp_mail() received,
 * with the same rules as WordPress (headers, From filters, content type).
 *
 * The message is a plain array so it can wait in the queue:
 * from, to, cc, bcc, reply_to (lists of ['email' => …, 'name' => …]), subject,
 * html, text, charset, headers (other headers, name => value), attachments
 * (lists of ['path' => …, 'name' => …, 'type' => …]).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property names.

/**
 * Message helpers.
 */
final class Message {

	/**
	 * Headers handled by Aluleth SMTP itself (never passed on).
	 */
	const OWN_HEADERS = array( 'x-aluleth-kind', 'x-aluleth-source', 'x-aluleth-connection' );

	/**
	 * Builds a message from the arguments of wp_mail() (after the "wp_mail" filter).
	 *
	 * @param array $atts to, subject, message, headers, attachments.
	 * @return array Message, plus 'meta' (kind, source and connection asked by the sender).
	 */
	public static function from_wp_mail( array $atts ) {
		$to          = isset( $atts['to'] ) ? $atts['to'] : array();
		$subject     = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$body        = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		$msg = array(
			'from'        => array(),
			'to'          => self::addresses( $to ),
			'cc'          => array(),
			'bcc'         => array(),
			'reply_to'    => array(),
			'subject'     => $subject,
			'html'        => '',
			'text'        => '',
			'charset'     => '',
			'headers'     => array(),
			'attachments' => array(),
			'meta'        => array(),
		);

		$content_type = '';
		$charset      = '';
		$from_email   = '';
		$from_name    = '';

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}
		foreach ( $headers as $key => $header ) {
			if ( is_string( $key ) && ! is_numeric( $key ) ) {
				$header = $key . ': ' . $header;
			}
			$header = (string) $header;
			if ( false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $content ) = explode( ':', trim( $header ), 2 );
			$name                   = trim( $name );
			$content                = trim( $content );
			$lower                  = strtolower( $name );
			switch ( $lower ) {
				case 'from':
					$parsed = self::address( $content );
					if ( $parsed ) {
						$from_email = $parsed['email'];
						$from_name  = $parsed['name'];
					}
					break;
				case 'content-type':
					if ( false !== strpos( $content, ';' ) ) {
						list( $type, $rest ) = explode( ';', $content, 2 );
						$content_type        = trim( $type );
						if ( preg_match( '/charset\s*=\s*"?([^";\s]+)/i', $rest, $m ) ) {
							$charset = $m[1];
						}
					} elseif ( '' !== $content ) {
						$content_type = $content;
					}
					break;
				case 'cc':
					$msg['cc'] = array_merge( $msg['cc'], self::addresses( $content ) );
					break;
				case 'bcc':
					$msg['bcc'] = array_merge( $msg['bcc'], self::addresses( $content ) );
					break;
				case 'reply-to':
					$msg['reply_to'] = array_merge( $msg['reply_to'], self::addresses( $content ) );
					break;
				case 'x-aluleth-kind':
					$msg['meta']['kind'] = sanitize_key( $content );
					break;
				case 'x-aluleth-source':
					$msg['meta']['source'] = sanitize_key( $content );
					break;
				case 'x-aluleth-connection':
					$msg['meta']['connection'] = sanitize_key( $content );
					break;
				case 'mime-version':
				case 'to':
				case 'subject':
				case 'date':
				case 'message-id':
				case 'content-transfer-encoding':
					break;
				default:
					if ( '' !== $name && preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
						$msg['headers'][ $name ] = str_replace( array( "\r", "\n" ), '', $content );
					}
			}
		}

		// The same filters as WordPress, so other plugins keep working.
		if ( '' === $from_email ) {
			$host       = wp_parse_url( network_home_url(), PHP_URL_HOST );
			$host       = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'localhost';
			$from_email = 'wordpress@' . $host;
		}
		if ( '' === $from_name ) {
			$from_name = 'WordPress';
		}
		$from_email = (string) apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		$from_name  = (string) apply_filters( 'wp_mail_from_name', $from_name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		if ( '' === $content_type ) {
			$content_type = 'text/plain';
		}
		$content_type = (string) apply_filters( 'wp_mail_content_type', $content_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		if ( '' === $charset ) {
			$charset = get_bloginfo( 'charset' );
		}
		$charset = (string) apply_filters( 'wp_mail_charset', $charset ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.

		$msg['from']    = array(
			'email' => sanitize_email( $from_email ),
			'name'  => self::clean_name( $from_name ),
		);
		$msg['charset'] = $charset;
		if ( false !== stripos( $content_type, 'html' ) ) {
			$msg['html'] = $body;
			$msg['text'] = self::html_to_text( $body );
		} else {
			$msg['text'] = $body;
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		foreach ( $attachments as $key => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$name                 = is_string( $key ) && '' !== $key ? $key : wp_basename( $path );
			$type                 = wp_check_filetype( $name );
			$msg['attachments'][] = array(
				'path' => $path,
				'name' => sanitize_file_name( $name ),
				'type' => ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream',
			);
		}
		return $msg;
	}

	/**
	 * Parses one address ("Name <email>" or "email").
	 *
	 * @param string $value Address.
	 * @return array|null ['email' => …, 'name' => …]
	 */
	public static function address( $value ) {
		$value = trim( (string) $value );
		$name  = '';
		if ( preg_match( '/^(.*)<\s*([^>]+)\s*>\s*$/', $value, $m ) ) {
			$name  = trim( trim( $m[1] ), "\"' " );
			$value = trim( $m[2] );
		}
		$email = sanitize_email( $value );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		return array(
			'email' => $email,
			'name'  => self::clean_name( $name ),
		);
	}

	/**
	 * Parses a list of addresses (array, or a comma-separated string that may hold names with commas in quotes).
	 *
	 * @param string|array $items Addresses.
	 * @return array[]
	 */
	public static function addresses( $items ) {
		if ( ! is_array( $items ) ) {
			// Split on commas outside quotes and angle brackets ("Doe, Jane" <jane@…>).
			$items    = (string) $items;
			$parts    = array();
			$current  = '';
			$in_quote = false;
			$in_angle = false;
			$length   = strlen( $items );
			for ( $i = 0; $i < $length; $i++ ) {
				$ch = $items[ $i ];
				if ( '"' === $ch ) {
					$in_quote = ! $in_quote;
				} elseif ( '<' === $ch ) {
					$in_angle = true;
				} elseif ( '>' === $ch ) {
					$in_angle = false;
				} elseif ( ( ',' === $ch || ';' === $ch ) && ! $in_quote && ! $in_angle ) {
					$parts[] = $current;
					$current = '';
					continue;
				}
				$current .= $ch;
			}
			$parts[] = $current;
			$items   = $parts;
		}
		$addresses = array();
		$seen      = array();
		foreach ( $items as $item ) {
			$parsed = self::address( (string) $item );
			if ( $parsed && ! isset( $seen[ strtolower( $parsed['email'] ) ] ) ) {
				$seen[ strtolower( $parsed['email'] ) ] = true;
				$addresses[]                            = $parsed;
			}
		}
		return $addresses;
	}

	/**
	 * A name safe for a header.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		return trim( str_replace( array( "\r", "\n", '"', '<', '>' ), '', wp_specialchars_decode( (string) $name, ENT_QUOTES ) ) );
	}

	/**
	 * "Name <email>" for an address.
	 *
	 * @param array $a Address.
	 * @return string
	 */
	public static function format( array $a ) {
		$name = isset( $a['name'] ) ? (string) $a['name'] : '';
		if ( '' === $name ) {
			return $a['email'];
		}
		// Quote names with special characters, encode non-ASCII ones.
		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			$name = '=?UTF-8?B?' . base64_encode( $name ) . '?='; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} elseif ( preg_match( '/[(),.:;@\[\]\\\\]/', $name ) ) {
			$name = '"' . addcslashes( $name, '"\\' ) . '"';
		}
		return $name . ' <' . $a['email'] . '>';
	}

	/**
	 * Every recipient of a message (to, cc, bcc).
	 *
	 * @param array $msg Message.
	 * @return string[] Emails.
	 */
	public static function recipients( array $msg ) {
		$out = array();
		foreach ( array( 'to', 'cc', 'bcc' ) as $key ) {
			foreach ( isset( $msg[ $key ] ) ? (array) $msg[ $key ] : array() as $a ) {
				$out[] = $a['email'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A plain-text version of an HTML body.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(head|style|script)[^>]*>.*?</\1>#is', '', (string) $html );
		// Hidden parts (the preview text of newsletters) are not part of the text version.
		$html = preg_replace( '#<(div|span)\s[^>]*style="[^"]*display:\s*none[^"]*"[^>]*>.*?</\1>#is', '', $html );
		// Links keep their address.
		$html = preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$label = trim( wp_strip_all_tags( $m[2] ) );
				$url   = trim( $m[1] );
				if ( '' === $label || $label === $url || 0 === strpos( $url, 'mailto:' ) ) {
					return '' === $label ? $url : $label;
				}
				return $label . ' (' . $url . ')';
			},
			$html
		);
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $html );
		$html = preg_replace( '#<li[^>]*>#i', '- ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		// Invisible spacing characters become plain spaces.
		$text = preg_replace( "/[ \t\x{00A0}\x{034F}\x{200B}\x{200C}\x{200D}\x{FEFF}]+/u", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * The whole message as MIME text (for services that take raw emails, like Amazon SES).
	 *
	 * @param array  $msg        Message.
	 * @param string $message_id Optional Message-ID domain part.
	 * @param bool   $with_bcc   Whether the Bcc header is kept.
	 * @return string|\WP_Error
	 */
	public static function mime( array $msg, $message_id = '', $with_bcc = false ) {
		$mail = self::phpmailer( $msg );
		if ( is_wp_error( $mail ) ) {
			return $mail;
		}
		// With "smtp" the Bcc stays out of the headers; services that read the recipients
		// from the headers (Gmail) need it there and remove it themselves.
		$mail->Mailer = $with_bcc ? 'sendmail' : 'smtp';
		if ( '' !== $message_id ) {
			$mail->MessageID = $message_id;
		}
		try {
			if ( ! $mail->preSend() ) {
				return new \WP_Error( 'alsmtp_mime', $mail->ErrorInfo );
			}
			return $mail->getSentMIMEMessage();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'alsmtp_mime', $e->getMessage() );
		}
	}

	/**
	 * A PHPMailer object holding the message (no transport set).
	 *
	 * @param array $msg Message.
	 * @return \PHPMailer\PHPMailer\PHPMailer|\WP_Error
	 */
	public static function phpmailer( array $msg ) {
		if ( ! class_exists( '\PHPMailer\PHPMailer\PHPMailer', false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}
		$mail = new \PHPMailer\PHPMailer\PHPMailer( true );
		try {
			$mail->CharSet  = ! empty( $msg['charset'] ) ? $msg['charset'] : 'UTF-8';
			$mail->Encoding = 'base64';
			$mail->setFrom( $msg['from']['email'], $msg['from']['name'], false );
			foreach ( $msg['to'] as $a ) {
				$mail->addAddress( $a['email'], $a['name'] );
			}
			foreach ( $msg['cc'] as $a ) {
				$mail->addCC( $a['email'], $a['name'] );
			}
			foreach ( $msg['bcc'] as $a ) {
				$mail->addBCC( $a['email'], $a['name'] );
			}
			foreach ( $msg['reply_to'] as $a ) {
				$mail->addReplyTo( $a['email'], $a['name'] );
			}
			$mail->Subject = $msg['subject'];
			if ( '' !== $msg['html'] ) {
				$mail->isHTML( true );
				$mail->Body    = $msg['html'];
				$mail->AltBody = $msg['text'];
			} else {
				$mail->isHTML( false );
				$mail->Body = $msg['text'];
			}
			foreach ( $msg['headers'] as $name => $value ) {
				$mail->addCustomHeader( $name, $value );
			}
			foreach ( $msg['attachments'] as $file ) {
				$mail->addAttachment( $file['path'], $file['name'], 'base64', $file['type'] );
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'alsmtp_message', $e->getMessage() );
		}
		return $mail;
	}

	/**
	 * Contents of the attachments, base64 encoded, for the email services' APIs.
	 *
	 * @param array $msg Message.
	 * @return array[] name, type, content.
	 */
	public static function attachments_base64( array $msg ) {
		$out = array();
		foreach ( isset( $msg['attachments'] ) ? $msg['attachments'] : array() as $file ) {
			$data = is_readable( $file['path'] ) ? file_get_contents( $file['path'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $data ) {
				continue;
			}
			$out[] = array(
				'name'    => $file['name'],
				'type'    => $file['type'],
				'content' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
		}
		return $out;
	}

	/**
	 * Hides what must never stay in a log: password-reset and login links,
	 * codes and long tokens.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	public static function mask( $text ) {
		$hidden = '[' . __( 'link hidden in the log', 'aluleth-smtp' ) . ']';
		$text   = (string) $text;
		// Links whose address carries a key, a token or a code.
		$text = preg_replace_callback(
			'#https?://[^\s"\'<>()]+#i',
			static function ( $m ) use ( $hidden ) {
				$url   = $m[0];
				$query = (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY );
				$path  = (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_PATH );
				if ( preg_match( '/(^|&)(key|token|code|hash|nonce|reset|rp|password|pass|pwd|login|auth|otp|magic|signature|sig|secret|access_token|activation|verify|confirm)[^=&]*=/i', $query )
					|| preg_match( '#/(reset|lost-password|set-password|activate|verify|confirm|magic)[^/]*/[A-Za-z0-9_-]{16,}#i', $path ) ) {
					return $hidden;
				}
				return $url;
			},
			$text
		);
		// Login codes and passwords written in the text ("Your code is 123456", "Password: …").
		$text = preg_replace( '/\b((?:code|pin|otp|passcode)\b\s*(?:is|:|=)\s*)(\d{4,10})\b/i', '$1[' . __( 'hidden', 'aluleth-smtp' ) . ']', $text );
		$text = preg_replace( '/\b(password\b\s*(?:is|:|=)\s*)(\S{4,64})/i', '$1[' . __( 'hidden', 'aluleth-smtp' ) . ']', $text );
		// Long tokens.
		$text = preg_replace( '/\b[A-Za-z0-9_\-]{40,}\b/', '[' . __( 'hidden', 'aluleth-smtp' ) . ']', $text );
		return $text;
	}
}
