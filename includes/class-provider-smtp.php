<?php
/**
 * Any SMTP server (host, port, login), with PHPMailer from WordPress.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property names.

/**
 * Other SMTP.
 */
final class Provider_Smtp extends Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'smtp';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Other SMTP', 'aluleth-smtp' );
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'Host, port, login', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'Your email service or host gives these settings. Port 587 with TLS is the usual choice; 465 uses SSL.', 'aluleth-smtp' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'host'       => array(
				'label'       => __( 'SMTP host', 'aluleth-smtp' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'smtp.example.com',
			),
			'port'       => array(
				'label'       => __( 'Port', 'aluleth-smtp' ),
				'type'        => 'number',
				'required'    => true,
				'placeholder' => '587',
			),
			'encryption' => array(
				'label'   => __( 'Encryption', 'aluleth-smtp' ),
				'type'    => 'select',
				'options' => array(
					'tls'  => __( 'TLS (port 587)', 'aluleth-smtp' ),
					'ssl'  => __( 'SSL (port 465)', 'aluleth-smtp' ),
					'none' => __( 'None (not recommended)', 'aluleth-smtp' ),
				),
			),
			'username'   => array(
				'label' => __( 'Username', 'aluleth-smtp' ),
				'type'  => 'text',
			),
			'password'   => array(
				'label' => __( 'Password', 'aluleth-smtp' ),
				'type'  => 'password',
			),
		);
	}

	/**
	 * Sets the transport on a PHPMailer object.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mail     Mailer.
	 * @param array                          $settings Settings.
	 * @return void
	 */
	private function transport( $mail, array $settings ) {
		$mail->isSMTP();
		$mail->Host        = (string) $settings['host'];
		$mail->Port        = ! empty( $settings['port'] ) ? (int) $settings['port'] : 587;
		$mail->Timeout     = 20;
		$enc               = isset( $settings['encryption'] ) ? $settings['encryption'] : 'tls';
		$mail->SMTPSecure  = 'ssl' === $enc ? 'ssl' : ( 'tls' === $enc ? 'tls' : '' );
		$mail->SMTPAutoTLS = 'none' !== $enc;
		if ( ! empty( $settings['username'] ) ) {
			$mail->SMTPAuth = true;
			$mail->Username = (string) $settings['username'];
			$mail->Password = isset( $settings['password'] ) ? (string) $settings['password'] : '';
		}
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
		$mail = Message::phpmailer( $msg );
		if ( is_wp_error( $mail ) ) {
			return $this->fail( $mail->get_error_message() );
		}
		if ( ! empty( $context['mail_id'] ) ) {
			$mail->addCustomHeader( 'X-Aluleth-Id', (string) $context['mail_id'] );
		}
		$this->transport( $mail, $settings );
		try {
			$mail->send();
			$id = trim( (string) $mail->getLastMessageID(), '<>' );
			$mail->smtpClose();
			return $this->ok( $id, 'SMTP ' . (string) $mail->Host );
		} catch ( \Exception $e ) {
			$error = '' !== $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
			$smtp  = $mail->getSMTPInstance();
			$code  = 0;
			if ( $smtp ) {
				$last = $smtp->getError();
				$code = isset( $last['smtp_code'] ) ? (int) $last['smtp_code'] : 0;
			}
			$mail->smtpClose();
			// A refused login or a 5xx answer will not work later; 4xx answers and connection problems can.
			$lower = strtolower( $error );
			if ( false !== strpos( $lower, 'could not authenticate' ) || false !== strpos( $lower, 'recipients failed' ) ) {
				$retry = false;
			} elseif ( false !== strpos( $lower, 'connect' ) || false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
				$retry = true;
			} elseif ( $code >= 500 && $code < 600 ) {
				$retry = false;
			} else {
				$retry = true;
			}
			return $this->fail( $error, $retry, $code );
		}
	}

	/**
	 * Connects and logs in, without sending.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$mail = Message::phpmailer(
			array(
				'from'        => array(
					'email' => 'check@example.com',
					'name'  => '',
				),
				'to'          => array(),
				'cc'          => array(),
				'bcc'         => array(),
				'reply_to'    => array(),
				'subject'     => '',
				'html'        => '',
				'text'        => '',
				'charset'     => 'UTF-8',
				'headers'     => array(),
				'attachments' => array(),
			)
		);
		if ( is_wp_error( $mail ) ) {
			return $mail;
		}
		$this->transport( $mail, $settings );
		try {
			$ok = $mail->smtpConnect();
			$mail->smtpClose();
			return $ok ? true : new \WP_Error( 'alsmtp_verify', $mail->ErrorInfo );
		} catch ( \Exception $e ) {
			$mail->smtpClose();
			return new \WP_Error( 'alsmtp_verify', '' !== $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage() );
		}
	}
}
