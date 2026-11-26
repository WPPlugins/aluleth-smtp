<?php
/**
 * Base of the email services (SMTP, SendGrid, Amazon SES…).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Email service.
 */
abstract class Provider {

	/**
	 * Id of the service (sendgrid, ses…).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Name shown to people.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Short note under the name.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API', 'aluleth-smtp' );
	}

	/**
	 * Where to find the keys, in one sentence.
	 *
	 * @return string
	 */
	public function help() {
		return '';
	}

	/**
	 * Address of the service's documentation about keys.
	 *
	 * @return string
	 */
	public function docs() {
		return '';
	}

	/**
	 * Settings fields: key => [label, type (text, password, email, number, select), required, options, help, placeholder].
	 * Password fields are stored encrypted and never shown again.
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * Sends one message.
	 *
	 * @param array $msg      Message (see Message).
	 * @param array $settings Connection settings (secrets readable).
	 * @param array $context  kind, mail_id.
	 * @return array ok, id (message id at the service), error, retry, code.
	 */
	abstract public function send( array $msg, array $settings, array $context = array() );

	/**
	 * Checks the settings with the service (without sending).
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error|null Null: the service has no check, the test email decides.
	 */
	public function verify( array $settings ) {
		unset( $settings );
		return null;
	}

	/**
	 * Reference of the email sent with it, so events can be matched (see Tracking::ref()).
	 *
	 * @param array $context Context.
	 * @return string
	 */
	protected function ref( array $context ) {
		return ! empty( $context['ref'] ) ? (string) $context['ref'] : (string) ( isset( $context['mail_id'] ) ? $context['mail_id'] : '' );
	}

	/**
	 * Help under the From address.
	 *
	 * @return string
	 */
	public function from_help() {
		return __( 'An address of a domain verified at the service.', 'aluleth-smtp' );
	}

	/**
	 * Why this service reports no events (shown on the connection).
	 *
	 * @return string
	 */
	public function no_events_text() {
		return __( 'An SMTP server does not report what happens after sending. Use an email service with an API to see delivered emails, bounces and spam reports.', 'aluleth-smtp' );
	}

	/**
	 * Whether this service signs in with a Google or Microsoft account (OAuth).
	 *
	 * @return bool
	 */
	public function is_oauth() {
		return false;
	}

	/**
	 * Whether the service reports events: "auto" (the plugin can add the address through the API),
	 * "manual" (the person adds it in the service) or "none".
	 *
	 * @return string
	 */
	public function hook_support() {
		return 'none';
	}

	/**
	 * Where to add the address at the service and which events to choose.
	 *
	 * @return string
	 */
	public function hook_help() {
		return '';
	}

	/**
	 * The signing key the service gives for its webhooks, when it signs them.
	 *
	 * @return array|null label, help.
	 */
	public function hook_secret() {
		return null;
	}

	/**
	 * Reads a webhook request.
	 *
	 * @param string $body     Raw body.
	 * @param array  $headers  Headers (lower case names, with dashes).
	 * @param array  $query    Query arguments.
	 * @param array  $settings Settings (with _hook_secret).
	 * @return array|\WP_Error|null Events (see Tracking::handle()), an error (with a status), null when not supported.
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $body, $headers, $query, $settings );
		return null;
	}

	/**
	 * Adds the webhook at the service.
	 *
	 * @param array  $settings Settings.
	 * @param string $url      Address.
	 * @param array  $c        Connection.
	 * @return array|\WP_Error remote (id at the service), secret (signing key, if given).
	 */
	public function hook_setup( array $settings, $url, array $c ) {
		unset( $settings, $url, $c );
		return new \WP_Error( 'alsmtp_manual', __( 'Add the address in your account at the service.', 'aluleth-smtp' ) );
	}

	/**
	 * Removes a webhook the plugin added at the service.
	 *
	 * @param array  $settings Settings.
	 * @param string $remote   Id at the service.
	 * @return void
	 */
	public function hook_remove( array $settings, $remote ) {
		unset( $settings, $remote );
	}

	/**
	 * Whether the service signs emails (DKIM) for a domain, from its API.
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null True: authenticated; false: not; null: the API cannot tell.
	 */
	public function domain_check( array $settings, $domain ) {
		unset( $settings, $domain );
		return null;
	}

	/**
	 * DKIM selectors the service usually uses, to look for them in the DNS.
	 *
	 * @return string[]
	 */
	public function dkim_selectors() {
		return array();
	}

	/**
	 * Domain to look for in an SPF record (include:…).
	 *
	 * @return string
	 */
	public function spf_include() {
		return '';
	}

	/**
	 * Checks a Svix signature (Resend and other services that use Svix).
	 *
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @param string $secret  Signing secret (whsec_…).
	 * @return bool
	 */
	protected function svix_ok( $body, array $headers, $secret ) {
		$id   = isset( $headers['svix-id'] ) ? (string) $headers['svix-id'] : '';
		$time = isset( $headers['svix-timestamp'] ) ? (string) $headers['svix-timestamp'] : '';
		$sigs = isset( $headers['svix-signature'] ) ? (string) $headers['svix-signature'] : '';
		if ( '' === $id || ! ctype_digit( $time ) || '' === $sigs || abs( time() - (int) $time ) > 10 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$secret = (string) $secret;
		if ( 0 === strpos( $secret, 'whsec_' ) ) {
			$secret = substr( $secret, 6 );
		}
		$key = base64_decode( $secret, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signing key.
		if ( false === $key ) {
			return false;
		}
		$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $time . '.' . $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signature.
		foreach ( preg_split( '/\s+/', trim( $sigs ) ) as $part ) {
			$bits = explode( ',', $part, 2 );
			if ( 2 === count( $bits ) && 'v1' === $bits[0] && hash_equals( $expected, $bits[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An error for a webhook request that is not signed right.
	 *
	 * @return \WP_Error
	 */
	protected function bad_signature() {
		return new \WP_Error( 'alsmtp_signature', 'Bad signature', array( 'status' => 401 ) );
	}

	/**
	 * Reads a JSON body: a list of events, or one event.
	 *
	 * @param string $body Body.
	 * @return array List.
	 */
	protected function json_list( $body ) {
		$j = json_decode( (string) $body, true );
		if ( ! is_array( $j ) ) {
			return array();
		}
		return array_keys( $j ) === range( 0, count( $j ) - 1 ) ? $j : array( $j );
	}

	/**
	 * A timestamp from a date or a number.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	protected function time_of( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$t = strtotime( (string) $value );
		return $t ? $t : 0;
	}

	/**
	 * Most recipients one request can hold.
	 *
	 * @return int
	 */
	public function max_recipients() {
		return 1000;
	}

	/**
	 * A successful result.
	 *
	 * @param string $id       Message id at the service.
	 * @param string $response Short answer of the service.
	 * @return array
	 */
	protected function ok( $id, $response = '' ) {
		return array(
			'ok'       => true,
			'id'       => substr( (string) $id, 0, 190 ),
			'error'    => '',
			'retry'    => false,
			'code'     => 200,
			'response' => substr( (string) $response, 0, 500 ),
		);
	}

	/**
	 * A failed result.
	 *
	 * @param string $error Message for people.
	 * @param bool   $retry Whether trying again later can work.
	 * @param int    $code  HTTP or SMTP code.
	 * @return array
	 */
	protected function fail( $error, $retry = false, $code = 0 ) {
		return array(
			'ok'       => false,
			'id'       => '',
			'error'    => substr( wp_strip_all_tags( (string) $error ), 0, 1000 ),
			'retry'    => (bool) $retry,
			'code'     => (int) $code,
			'response' => '',
		);
	}

	/**
	 * Calls the service's API.
	 *
	 * @param string       $method  GET or POST.
	 * @param string       $url     Address.
	 * @param array        $headers Headers.
	 * @param string|array $body    Body (arrays are sent as JSON).
	 * @param int          $timeout Seconds.
	 * @return array|\WP_Error code, body, json, headers.
	 */
	protected function http( $method, $url, array $headers = array(), $body = null, $timeout = 20 ) {
		if ( is_array( $body ) ) {
			$body                    = wp_json_encode( $body );
			$headers['Content-Type'] = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : 'application/json';
		}
		$headers['Accept']     = isset( $headers['Accept'] ) ? $headers['Accept'] : 'application/json';
		$headers['User-Agent'] = 'AlulethSMTP/' . ALULETH_SMTP_VERSION . '; ' . home_url( '/' );
		$args                  = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $text, true );
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'body'    => $text,
			'json'    => is_array( $json ) ? $json : array(),
			'headers' => wp_remote_retrieve_headers( $res ),
		);
	}

	/**
	 * Result of an API answer: 2xx = sent; 429 and 5xx = try again later.
	 *
	 * @param array|\WP_Error $res   Answer.
	 * @param string          $id    Message id found in the answer.
	 * @param string          $error Error text found in the answer.
	 * @return array
	 */
	protected function result( $res, $id = '', $error = '' ) {
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error from the network. */
			return $this->fail( sprintf( __( 'Could not reach the service: %s', 'aluleth-smtp' ), $res->get_error_message() ), true );
		}
		$code = (int) $res['code'];
		if ( $code >= 200 && $code < 300 ) {
			return $this->ok( $id, $code . ' ' . trim( substr( $res['body'], 0, 200 ) ) );
		}
		if ( '' === $error ) {
			$error = $this->error_text( $res );
		}
		if ( 401 === $code || 403 === $code ) {
			/* translators: %s: error text from the service. */
			$error = sprintf( __( 'The service refused the key: %s', 'aluleth-smtp' ), $error );
		}
		return $this->fail( $code . ' · ' . $error, 429 === $code || $code >= 500 || 408 === $code, $code );
	}

	/**
	 * A readable error from an API answer.
	 *
	 * @param array $res Answer.
	 * @return string
	 */
	protected function error_text( array $res ) {
		$j = $res['json'];
		foreach ( array( 'message', 'Message', 'error', 'ErrorMessage', 'detail', 'title' ) as $key ) {
			if ( isset( $j[ $key ] ) && is_string( $j[ $key ] ) && '' !== $j[ $key ] ) {
				return $j[ $key ];
			}
		}
		if ( isset( $j['errors'] ) && is_array( $j['errors'] ) ) {
			$first = reset( $j['errors'] );
			if ( is_array( $first ) && isset( $first['message'] ) ) {
				return (string) $first['message'];
			}
			if ( is_string( $first ) ) {
				return $first;
			}
		}
		if ( isset( $j['error'] ) && is_array( $j['error'] ) && isset( $j['error']['message'] ) ) {
			return (string) $j['error']['message'];
		}
		$body = trim( wp_strip_all_tags( $res['body'] ) );
		return '' !== $body ? substr( $body, 0, 300 ) : __( 'No details from the service.', 'aluleth-smtp' );
	}

	/**
	 * Result of a check with the service (verify()).
	 *
	 * @param array|\WP_Error $res Answer.
	 * @return true|\WP_Error
	 */
	protected function checked( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			return true;
		}
		return new \WP_Error( 'alsmtp_verify', $res['code'] . ' · ' . $this->error_text( $res ) );
	}

	/**
	 * Recipients as a list of ['email' => …, 'name' => …] without empty names.
	 *
	 * @param array  $items Addresses.
	 * @param string $email Key for the email.
	 * @param string $name  Key for the name.
	 * @return array
	 */
	protected function people( array $items, $email = 'email', $name = 'name' ) {
		$out = array();
		foreach ( $items as $a ) {
			$item = array( $email => $a['email'] );
			if ( '' !== $a['name'] ) {
				$item[ $name ] = $a['name'];
			}
			$out[] = $item;
		}
		return $out;
	}
}
