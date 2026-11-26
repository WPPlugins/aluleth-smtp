<?php
/**
 * Spam protection shared by every Aluleth product that receives visitor
 * input: built-in limits, captcha services (Cloudflare Turnstile, hCaptcha,
 * Google reCAPTCHA, CleanTalk), a blocklist and Akismet.
 *
 * Settings live in the option "aluleth_security", edited in
 * Aluleth › Settings › Security. Secret keys are never printed back.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Security settings and checks.
 */
final class Security {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_security';

	/**
	 * Captcha and anti-spam services.
	 *
	 * @return array<string, array{label: string, text: string, widget: bool, fields: string[]}>
	 */
	public static function services() {
		return array(
			'turnstile' => array(
				'label'  => 'Cloudflare Turnstile',
				'text'   => __( 'Free, usually invisible', 'aluleth-smtp' ),
				'widget' => true,
				'fields' => array( 'site', 'secret' ),
			),
			'hcaptcha'  => array(
				'label'  => 'hCaptcha',
				'text'   => __( 'Privacy focused', 'aluleth-smtp' ),
				'widget' => true,
				'fields' => array( 'site', 'secret' ),
			),
			'recaptcha' => array(
				'label'  => 'Google reCAPTCHA',
				'text'   => __( 'v2 checkbox or v3 invisible', 'aluleth-smtp' ),
				'widget' => true,
				'fields' => array( 'site', 'secret' ),
			),
			'cleantalk' => array(
				'label'  => 'CleanTalk',
				'text'   => __( 'Checks entries in the background', 'aluleth-smtp' ),
				'widget' => false,
				'fields' => array( 'key' ),
			),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'min_seconds'     => 3,
			'per_hour'        => 10,
			'default_captcha' => '',
			'turnstile'       => array(
				'site'       => '',
				'secret'     => '',
				'appearance' => 'interaction-only',
			),
			'hcaptcha'        => array(
				'site'   => '',
				'secret' => '',
			),
			'recaptcha'       => array(
				'version' => 'v3',
				'site'    => '',
				'secret'  => '',
				'score'   => 0.5,
			),
			'cleantalk'       => array(
				'key' => '',
			),
			'block_emails'    => array(),
			'block_words'     => array(),
			'akismet'         => false,
		);
	}

	/**
	 * Saved settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$out      = wp_parse_args( $saved, $defaults );
		foreach ( array_keys( self::services() ) as $id ) {
			$out[ $id ] = wp_parse_args( is_array( $out[ $id ] ) ? $out[ $id ] : array(), $defaults[ $id ] );
		}
		return $out;
	}

	/**
	 * Whether a service has its keys.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public static function ready( $id ) {
		$services = self::services();
		if ( ! isset( $services[ $id ] ) ) {
			return false;
		}
		$conf = self::get()[ $id ];
		foreach ( $services[ $id ]['fields'] as $field ) {
			if ( empty( $conf[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The service a form uses: its own choice, or the default one.
	 *
	 * @param string $choice 'default', 'none' or a service id.
	 * @return string Service id, or '' for none.
	 */
	public static function resolve( $choice ) {
		$choice = (string) $choice;
		if ( 'none' === $choice ) {
			return '';
		}
		if ( '' === $choice || 'default' === $choice ) {
			$choice = (string) self::get()['default_captcha'];
		}
		return self::ready( $choice ) ? $choice : '';
	}

	/**
	 * What the front end needs to show a captcha widget.
	 *
	 * @param string $id Service id.
	 * @return array|null provider, site key, script URL, options; null for a service without widget.
	 */
	public static function widget( $id ) {
		$services = self::services();
		if ( ! isset( $services[ $id ] ) || ! $services[ $id ]['widget'] || ! self::ready( $id ) ) {
			return null;
		}
		$conf = self::get()[ $id ];
		$out  = array(
			'provider' => $id,
			'site'     => (string) $conf['site'],
		);
		if ( 'turnstile' === $id ) {
			// Turnstile is a remote CAPTCHA service: Cloudflare only serves its script from this address and it cannot be bundled. Disclosed in readme.txt.
			$out['script']     = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			$out['appearance'] = 'always' === $conf['appearance'] ? 'always' : 'interaction-only';
		} elseif ( 'hcaptcha' === $id ) {
			$out['script'] = 'https://js.hcaptcha.com/1/api.js?render=explicit';
		} else {
			$out['version'] = 'v2' === $conf['version'] ? 'v2' : 'v3';
			$out['script']  = 'v3' === $out['version'] ? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $conf['site'] ) : 'https://www.google.com/recaptcha/api.js?render=explicit';
		}
		return $out;
	}

	/**
	 * Checks a captcha answer with the service.
	 *
	 * @param string $id    Service id (turnstile, hcaptcha, recaptcha).
	 * @param string $token Answer sent by the visitor's browser.
	 * @return true|\WP_Error
	 */
	public static function verify( $id, $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new \WP_Error( 'aluleth_captcha', __( 'Please complete the anti-spam check, then send the form again.', 'aluleth-smtp' ) );
		}
		$data = self::siteverify( $id, $token );
		if ( is_wp_error( $data ) ) {
			// The service could not be reached: let the entry in rather than lose it (the other checks still apply).
			return 'aluleth_captcha_network' === $data->get_error_code() ? true : $data;
		}
		if ( empty( $data['success'] ) ) {
			return new \WP_Error( 'aluleth_captcha', __( 'The anti-spam check failed. Please try again.', 'aluleth-smtp' ) );
		}
		if ( 'recaptcha' === $id && 'v3' === self::get()['recaptcha']['version'] && isset( $data['score'] ) && (float) $data['score'] < (float) self::get()['recaptcha']['score'] ) {
			return new \WP_Error( 'aluleth_captcha', __( 'The anti-spam check failed. Please try again.', 'aluleth-smtp' ) );
		}
		return true;
	}

	/**
	 * Calls the service's verification address.
	 *
	 * @param string $id     Service id.
	 * @param string $token  Answer.
	 * @param string $secret Secret key (default: the saved one).
	 * @return array|\WP_Error Decoded answer.
	 */
	private static function siteverify( $id, $token, $secret = '' ) {
		$urls = array(
			// Server-side check of the Turnstile answer (an API call, nothing is loaded in the page). Disclosed in readme.txt.
			'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
			'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
		);
		if ( ! isset( $urls[ $id ] ) ) {
			return new \WP_Error( 'aluleth_captcha', __( 'Unknown anti-spam service.', 'aluleth-smtp' ) );
		}
		$response = wp_remote_post(
			$urls[ $id ],
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => '' !== $secret ? $secret : (string) self::get()[ $id ]['secret'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aluleth_captcha_network', $response->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new \WP_Error( 'aluleth_captcha_network', 'Unreadable answer' );
	}

	/**
	 * "Check the keys".
	 *
	 * With an answer from the test check shown on the settings screen, the service
	 * confirms both keys and the site's domain. Without it, a made-up answer is
	 * sent: Cloudflare then says whether it knows the secret key; hCaptcha and
	 * Google do not tell, so the test check is needed for them.
	 *
	 * @param string $id    Service id.
	 * @param string $token Answer of the test check ('' when there is none).
	 * @return string|\WP_Error Message when the keys work.
	 */
	public static function check_keys( $id, $token = '' ) {
		if ( ! self::ready( $id ) ) {
			return new \WP_Error( 'aluleth_captcha_keys', __( 'Paste the keys first.', 'aluleth-smtp' ) );
		}
		$label = self::services()[ $id ]['label'];
		if ( 'cleantalk' === $id ) {
			$data = self::cleantalk_call(
				array(
					'method_name' => 'check_message',
					'message'     => 'Aluleth key check',
					'sender_ip'   => Util::client_ip(),
					'js_on'       => 1,
					'submit_time' => 10,
					'agent'       => 'aluleth',
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$codes = isset( $data['codes'] ) ? (string) $data['codes'] : '';
			if ( false !== strpos( $codes, 'KEY_NOT_FOUND' ) || ! empty( $data['inactive'] ) || ( isset( $data['account_status'] ) && 1 !== (int) $data['account_status'] ) ) {
				return new \WP_Error( 'aluleth_captcha_keys', __( 'CleanTalk did not accept this access key, or the account is not active.', 'aluleth-smtp' ) );
			}
			/* translators: %s: service name. */
			return sprintf( __( 'Keys accepted by %s.', 'aluleth-smtp' ), $label );
		}
		$token = trim( (string) $token );
		$data  = self::siteverify( $id, '' !== $token ? $token : 'aluleth-key-check' );
		if ( is_wp_error( $data ) ) {
			return new \WP_Error( 'aluleth_captcha_keys', __( 'The service could not be reached. Please try again in a moment.', 'aluleth-smtp' ) );
		}
		$codes = isset( $data['error-codes'] ) ? array_map( 'strval', (array) $data['error-codes'] ) : array();
		if ( array_intersect( $codes, array( 'invalid-input-secret', 'missing-input-secret' ) ) ) {
			return new \WP_Error( 'aluleth_captcha_keys', __( 'The service did not accept the secret key. Copy it again from your account.', 'aluleth-smtp' ) );
		}
		if ( '' !== $token ) {
			if ( empty( $data['success'] ) ) {
				return new \WP_Error( 'aluleth_captcha_keys', __( 'The test check did not pass. Check that the site key and the secret key come from the same site in your account, and that this domain is allowed there.', 'aluleth-smtp' ) );
			}
			/* translators: %s: service name. */
			return sprintf( __( 'Keys confirmed by %s: the test check passed on this site.', 'aluleth-smtp' ), $label );
		}
		if ( 'turnstile' === $id ) {
			/* translators: %s: service name. */
			return sprintf( __( 'Secret key accepted by %s. Complete the test check and click again to confirm the site key too.', 'aluleth-smtp' ), $label );
		}
		return new \WP_Error( 'aluleth_captcha_keys', __( 'Saved. To confirm the keys, complete the test check below and click "Save and check the keys" again.', 'aluleth-smtp' ) );
	}

	/**
	 * Calls the CleanTalk API.
	 *
	 * @param array $body Request (method_name and its data; the key is added).
	 * @return array|\WP_Error
	 */
	private static function cleantalk_call( array $body ) {
		$body['auth_key'] = (string) self::get()['cleantalk']['key'];
		$response         = wp_remote_post(
			'https://moderate.cleantalk.org/api2.0',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aluleth_captcha_network', __( 'The service could not be reached. Please try again in a moment.', 'aluleth-smtp' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new \WP_Error( 'aluleth_captcha_network', __( 'The service could not be reached. Please try again in a moment.', 'aluleth-smtp' ) );
	}

	/**
	 * Background checks after validation: blocklist, Akismet, CleanTalk.
	 * Returns the reason when the entry looks like spam (it is then kept
	 * aside, never lost), or '' when it looks fine.
	 *
	 * @param array $args email, name, message (all text answers), service (the form's service id), seconds (time to fill).
	 * @return string Reason, or ''.
	 */
	public static function spam_reason( array $args ) {
		$args  = wp_parse_args(
			$args,
			array(
				'email'   => '',
				'name'    => '',
				'message' => '',
				'service' => '',
				'seconds' => 0,
			)
		);
		$conf  = self::get();
		$email = strtolower( (string) $args['email'] );
		foreach ( (array) $conf['block_emails'] as $rule ) {
			$rule = strtolower( trim( (string) $rule ) );
			if ( '' === $rule || '' === $email ) {
				continue;
			}
			if ( $email === $rule || ( '@' === $rule[0] && substr( $email, -strlen( $rule ) ) === $rule ) ) {
				return __( 'Blocked email address or domain', 'aluleth-smtp' );
			}
		}
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $args['message'] . ' ' . $args['name'] ) : strtolower( $args['message'] . ' ' . $args['name'] );
		foreach ( (array) $conf['block_words'] as $word ) {
			$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $word ) ) : strtolower( trim( (string) $word ) );
			if ( '' !== $word && false !== strpos( $text, $word ) ) {
				return __( 'Blocked word', 'aluleth-smtp' );
			}
		}
		if ( ! empty( $conf['akismet'] ) && self::akismet_ready() && self::akismet_spam( $args ) ) {
			return 'Akismet';
		}
		if ( 'cleantalk' === $args['service'] && self::ready( 'cleantalk' ) ) {
			$data = self::cleantalk_call(
				array(
					'method_name'     => 'check_message',
					'sender_email'    => (string) $args['email'],
					'sender_nickname' => (string) $args['name'],
					'sender_ip'       => Util::client_ip(),
					'message'         => (string) $args['message'],
					'js_on'           => 1,
					'submit_time'     => (int) $args['seconds'],
					'agent'           => 'aluleth',
				)
			);
			if ( ! is_wp_error( $data ) && isset( $data['allow'] ) && 0 === (int) $data['allow'] ) {
				return 'CleanTalk';
			}
		}
		return '';
	}

	/**
	 * Whether Akismet is installed with a key.
	 *
	 * @return bool
	 */
	public static function akismet_ready() {
		return class_exists( '\Akismet' ) && method_exists( '\Akismet', 'get_api_key' ) && '' !== (string) \Akismet::get_api_key();
	}

	/**
	 * Asks Akismet about an entry.
	 *
	 * @param array $args email, name, message.
	 * @return bool True when Akismet says spam.
	 */
	private static function akismet_spam( array $args ) {
		if ( ! method_exists( '\Akismet', 'http_post' ) ) {
			return false;
		}
		$query    = array(
			'blog'                 => home_url(),
			'user_ip'              => Util::client_ip(),
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'             => wp_get_referer() ? wp_get_referer() : '',
			'comment_type'         => 'contact-form',
			'comment_author'       => (string) $args['name'],
			'comment_author_email' => (string) $args['email'],
			'comment_content'      => (string) $args['message'],
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
		);
		$response = \Akismet::http_post( http_build_query( $query ), 'comment-check' );
		return is_array( $response ) && isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}

	/**
	 * Saves the Security section (called by Settings).
	 *
	 * @param array $post Unslashed $_POST.
	 * @return string Notice to show.
	 */
	public static function save( array $post ) {
		$conf                = self::get();
		$conf['min_seconds'] = isset( $post['min_seconds'] ) ? max( 0, min( 60, (int) $post['min_seconds'] ) ) : $conf['min_seconds'];
		$conf['per_hour']    = isset( $post['per_hour'] ) ? max( 1, min( 500, (int) $post['per_hour'] ) ) : $conf['per_hour'];
		$default             = isset( $post['default_captcha'] ) ? sanitize_key( $post['default_captcha'] ) : '';
		foreach ( self::services() as $id => $service ) {
			foreach ( $service['fields'] as $field ) {
				$name = 'svc_' . $id . '_' . $field;
				if ( ! isset( $post[ $name ] ) ) {
					continue;
				}
				$value = trim( sanitize_text_field( (string) $post[ $name ] ) );
				// An empty secret field keeps the saved secret (it is never shown again).
				if ( '' === $value && in_array( $field, array( 'secret', 'key' ), true ) && empty( $post[ 'svc_' . $id . '_clear' ] ) ) {
					continue;
				}
				$conf[ $id ][ $field ] = substr( $value, 0, 200 );
			}
			if ( ! empty( $post[ 'svc_' . $id . '_clear' ] ) ) {
				foreach ( $service['fields'] as $field ) {
					$conf[ $id ][ $field ] = '';
				}
			}
		}
		$conf['turnstile']['appearance'] = isset( $post['svc_turnstile_appearance'] ) && 'always' === $post['svc_turnstile_appearance'] ? 'always' : 'interaction-only';
		$conf['recaptcha']['version']    = isset( $post['svc_recaptcha_version'] ) && 'v2' === $post['svc_recaptcha_version'] ? 'v2' : 'v3';
		$conf['recaptcha']['score']      = isset( $post['svc_recaptcha_score'] ) ? max( 0.1, min( 0.9, round( (float) $post['svc_recaptcha_score'], 1 ) ) ) : $conf['recaptcha']['score'];
		$conf['default_captcha']         = isset( self::services()[ $default ] ) ? $default : '';
		$conf['block_emails']            = self::list_from( isset( $post['block_emails'] ) ? (string) $post['block_emails'] : '', true );
		$conf['block_words']             = self::list_from( isset( $post['block_words'] ) ? (string) $post['block_words'] : '', false );
		$conf['akismet']                 = ! empty( $post['akismet'] );
		update_option( self::OPTION, $conf, true );

		if ( ! empty( $post['check'] ) && isset( self::services()[ sanitize_key( $post['check'] ) ] ) ) {
			$id     = sanitize_key( $post['check'] );
			$result = self::check_keys( $id, isset( $post['captcha_test_token'] ) ? sanitize_text_field( (string) $post['captcha_test_token'] ) : '' );
			return is_wp_error( $result ) ? 'error:' . $result->get_error_message() : $result;
		}
		if ( '' !== $conf['default_captcha'] && ! self::ready( $conf['default_captcha'] ) ) {
			return 'error:' . __( 'Saved. The default service has no keys yet, so forms do not show it until you add them.', 'aluleth-smtp' );
		}
		return '';
	}

	/**
	 * One item per line or comma, cleaned.
	 *
	 * @param string $raw    Text.
	 * @param bool   $emails Email rules (an address, or a domain starting with @).
	 * @return string[]
	 */
	private static function list_from( $raw, $emails ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $item ) {
			$item = trim( sanitize_text_field( $item ) );
			if ( '' === $item ) {
				continue;
			}
			if ( $emails ) {
				$item = strtolower( $item );
				if ( '@' !== $item[0] && false === strpos( $item, '@' ) ) {
					$item = '@' . $item;
				}
			}
			$out[] = substr( $item, 0, 100 );
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 300 );
	}

	/**
	 * Renders the Security section of Aluleth › Settings.
	 *
	 * @return void
	 */
	public static function render() {
		$conf     = self::get();
		$services = self::services();
		$current  = isset( $_GET['service'] ) && isset( $services[ sanitize_key( wp_unslash( $_GET['service'] ) ) ] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : ( '' !== $conf['default_captcha'] ? $conf['default_captcha'] : 'turnstile' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		echo '<section class="al-card al-sec" id="builtin"><div class="al-sec-head"><div><h2>' . esc_html__( 'Built-in protection', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Always on, invisible to visitors. No cookie and no outside service.', 'aluleth-smtp' ) . '</p></div><span class="al-badge al-badge--subscribed">' . esc_html__( 'Active', 'aluleth-smtp' ) . '</span></div>';
		echo '<div class="al-grid3"><div class="al-tile"><strong>' . esc_html__( 'Hidden trap field', 'aluleth-smtp' ) . '</strong><span>' . esc_html__( 'Robots fill it, people never see it.', 'aluleth-smtp' ) . '</span></div>';
		echo '<div class="al-tile"><label for="al-minsec">' . esc_html__( 'Minimum time to fill a form', 'aluleth-smtp' ) . '</label><span class="al-inline"><input id="al-minsec" type="number" min="0" max="60" name="min_seconds" value="' . esc_attr( (string) $conf['min_seconds'] ) . '"> ' . esc_html__( 'seconds', 'aluleth-smtp' ) . '</span></div>';
		echo '<div class="al-tile"><label for="al-perhour">' . esc_html__( 'Entries per visitor and per form', 'aluleth-smtp' ) . '</label><span class="al-inline"><input id="al-perhour" type="number" min="1" max="500" name="per_hour" value="' . esc_attr( (string) $conf['per_hour'] ) . '"> ' . esc_html__( 'per hour', 'aluleth-smtp' ) . '</span></div></div>';
		echo '<p class="al-help">' . esc_html__( 'A form can set its own minimum time in its Settings › Spam protection.', 'aluleth-smtp' ) . '</p></section>';

		echo '<section class="al-card al-sec" id="captcha"><div class="al-sec-head"><div><h2>' . esc_html__( 'Captcha and anti-spam services', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Set up as many as you like. New forms use the default one; each form can pick another one, or none, in its Settings › Spam protection.', 'aluleth-smtp' ) . '</p></div>';
		echo '<label class="al-field al-narrow"><span>' . esc_html__( 'Default for forms', 'aluleth-smtp' ) . '</span><select name="default_captcha"><option value="">' . esc_html__( 'None (built-in protection only)', 'aluleth-smtp' ) . '</option>';
		foreach ( $services as $id => $service ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $conf['default_captcha'], $id, false ) . '>' . esc_html( $service['label'] ) . '</option>';
		}
		echo '</select></label></div>';

		echo '<nav class="al-svc-tabs" aria-label="' . esc_attr__( 'Services', 'aluleth-smtp' ) . '">';
		foreach ( $services as $id => $service ) {
			$ready = self::ready( $id );
			echo '<a class="al-svc-tab' . ( $current === $id ? ' is-current' : '' ) . '"' . ( $current === $id ? ' aria-current="true"' : '' ) . ' href="' . esc_url( add_query_arg( 'service', $id, Settings::url( 'security' ) ) . '#captcha' ) . '"><span class="al-svc-row"><strong>' . esc_html( $service['label'] ) . '</strong><span class="al-badge ' . ( $ready ? 'al-badge--subscribed' : '' ) . '">' . esc_html( $ready ? __( 'Ready', 'aluleth-smtp' ) : __( 'Not set up', 'aluleth-smtp' ) ) . '</span></span><span class="al-svc-text">' . esc_html( $service['text'] ) . '</span></a>';
		}
		echo '</nav>';

		$c = $conf[ $current ];
		echo '<div class="al-svc-panel">';
		echo '<div class="al-svc-row"><strong>' . esc_html( $services[ $current ]['label'] ) . '</strong><a href="' . esc_url( self::help_url( $current ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Where to find the keys', 'aluleth-smtp' ) . '</a></div>';
		echo '<div class="al-grid2">';
		if ( 'cleantalk' === $current ) {
			self::secret_field( 'cleantalk', 'key', __( 'Access key', 'aluleth-smtp' ), $c['key'] );
		} else {
			echo '<label class="al-field"><span>' . esc_html__( 'Site key', 'aluleth-smtp' ) . '</span><input type="text" name="svc_' . esc_attr( $current ) . '_site" value="' . esc_attr( $c['site'] ) . '" autocomplete="off" spellcheck="false"></label>';
			self::secret_field( $current, 'secret', __( 'Secret key', 'aluleth-smtp' ), $c['secret'] );
		}
		echo '</div>';
		if ( 'turnstile' === $current ) {
			echo '<fieldset class="al-radios"><legend>' . esc_html__( 'Appearance', 'aluleth-smtp' ) . '</legend><label><input type="radio" name="svc_turnstile_appearance" value="interaction-only"' . checked( 'always' !== $c['appearance'], true, false ) . '> ' . esc_html__( 'Only when needed', 'aluleth-smtp' ) . '</label><label><input type="radio" name="svc_turnstile_appearance" value="always"' . checked( 'always', $c['appearance'], false ) . '> ' . esc_html__( 'Always visible', 'aluleth-smtp' ) . '</label></fieldset>';
		} elseif ( 'recaptcha' === $current ) {
			echo '<div class="al-grid2"><fieldset class="al-radios"><legend>' . esc_html__( 'Version', 'aluleth-smtp' ) . '</legend><label><input type="radio" name="svc_recaptcha_version" value="v3"' . checked( 'v2' !== $c['version'], true, false ) . '> ' . esc_html__( 'v3, invisible', 'aluleth-smtp' ) . '</label><label><input type="radio" name="svc_recaptcha_version" value="v2"' . checked( 'v2', $c['version'], false ) . '> ' . esc_html__( 'v2, checkbox', 'aluleth-smtp' ) . '</label></fieldset>';
			echo '<label class="al-field"><span>' . esc_html__( 'Lowest score accepted (v3)', 'aluleth-smtp' ) . '</span><input type="number" step="0.1" min="0.1" max="0.9" name="svc_recaptcha_score" value="' . esc_attr( (string) $c['score'] ) . '"></label></div>';
			echo '<p class="al-help">' . esc_html__( 'The keys of a v2 site do not work for v3, and the other way round: create them for the version you choose.', 'aluleth-smtp' ) . '</p>';
		} elseif ( 'cleantalk' === $current ) {
			echo '<p class="al-help">' . esc_html__( 'CleanTalk is a paid service. It checks each entry in the background, with nothing shown to the visitor. Entries it flags go to Spam.', 'aluleth-smtp' ) . '</p>';
		}
		$widget = self::widget( $current );
		if ( $widget ) {
			// Test check: the widget of the saved site key, so "Save and check the keys" can confirm both keys and the domain.
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the service's own script, served and versioned by the service.
			wp_enqueue_script( 'aluleth-captcha-' . $current, $widget['script'], array(), null, true );
			wp_enqueue_script( 'aluleth-core-settings', plugins_url( 'assets/settings.js', ALULETH_CORE_PATH . '/bootstrap.php' ), array(), ALULETH_CORE_VERSION, true );
			$v3 = isset( $widget['version'] ) && 'v3' === $widget['version'];
			echo '<div class="al-captcha-test"><strong>' . esc_html__( 'Test check', 'aluleth-smtp' ) . '</strong><p class="al-help">' . esc_html( $v3 ? __( 'reCAPTCHA v3 runs invisibly when you click "Save and check the keys": it confirms both keys and this domain.', 'aluleth-smtp' ) : __( 'Complete the check here, then click "Save and check the keys": it confirms both keys and this domain.', 'aluleth-smtp' ) ) . '</p>';
			echo '<div class="al-captcha-box" data-provider="' . esc_attr( $current ) . '" data-sitekey="' . esc_attr( $widget['site'] ) . '"' . ( $v3 ? ' data-version="v3"' : '' ) . '></div><input type="hidden" name="captcha_test_token" value=""></div>';
		}
		echo '<div class="al-row"><button type="submit" name="check" value="' . esc_attr( $current ) . '" class="al-button al-button--ghost">' . esc_html__( 'Save and check the keys', 'aluleth-smtp' ) . '</button>';
		if ( self::ready( $current ) ) {
			echo '<label class="al-small"><input type="checkbox" name="svc_' . esc_attr( $current ) . '_clear" value="1"> ' . esc_html__( 'Remove these keys', 'aluleth-smtp' ) . '</label>';
		}
		echo '</div>';
		echo '<p class="al-help">' . esc_html( self::privacy_text( $current ) ) . '</p>';
		echo '</div></section>';

		echo '<section class="al-card al-sec" id="blocklist"><div class="al-sec-head"><div><h2>' . esc_html__( 'Blocklist and Akismet', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Entries that match are kept aside in Spam instead of reaching your inbox and your tools. Nothing is lost.', 'aluleth-smtp' ) . '</p></div></div>';
		echo '<div class="al-grid2"><label class="al-field"><span>' . esc_html__( 'Email addresses and domains', 'aluleth-smtp' ) . '</span><textarea name="block_emails" rows="4" placeholder="@example-spam.com">' . esc_textarea( implode( "\n", (array) $conf['block_emails'] ) ) . '</textarea><small>' . esc_html__( 'One per line: a full address, or a domain starting with @.', 'aluleth-smtp' ) . '</small></label>';
		echo '<label class="al-field"><span>' . esc_html__( 'Words in the answers', 'aluleth-smtp' ) . '</span><textarea name="block_words" rows="4">' . esc_textarea( implode( "\n", (array) $conf['block_words'] ) ) . '</textarea><small>' . esc_html__( 'One per line. Not case sensitive.', 'aluleth-smtp' ) . '</small></label></div>';
		$akismet = self::akismet_ready();
		echo '<label class="al-checkcard"><input type="checkbox" name="akismet" value="1"' . checked( ! empty( $conf['akismet'] ), true, false ) . ( $akismet ? '' : ' disabled' ) . '> <span><strong>' . esc_html__( 'Check entries with Akismet', 'aluleth-smtp' ) . '</strong><small>' . esc_html( $akismet ? __( 'Uses the key of the Akismet plugin set up on this site. The answers, the email and the visitor\'s IP address are sent to Akismet.', 'aluleth-smtp' ) : __( 'Install and set up the Akismet plugin to use it.', 'aluleth-smtp' ) ) . '</small></span></label>';
		echo '</section>';
	}

	/**
	 * A secret key field: never shows the saved value, only its last characters.
	 *
	 * @param string $id    Service id.
	 * @param string $field Field.
	 * @param string $label Label.
	 * @param string $saved Saved value.
	 * @return void
	 */
	private static function secret_field( $id, $field, $label, $saved ) {
		/* translators: %s: last 4 characters of the saved key. */
		$placeholder = '' !== $saved ? sprintf( __( 'Saved, ending in %s', 'aluleth-smtp' ), substr( $saved, -4 ) ) : '';
		echo '<label class="al-field"><span>' . esc_html( $label ) . '</span><input type="password" name="svc_' . esc_attr( $id ) . '_' . esc_attr( $field ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password" spellcheck="false"></label>';
	}

	/**
	 * Where each service gives its keys.
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	private static function help_url( $id ) {
		$urls = array(
			// Link to the Turnstile documentation shown in the settings, nothing is loaded.
			'turnstile' => 'https://developers.cloudflare.com/turnstile/get-started/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			'hcaptcha'  => 'https://docs.hcaptcha.com/#your-api-keys',
			'recaptcha' => 'https://www.google.com/recaptcha/admin',
			'cleantalk' => 'https://cleantalk.org/my',
		);
		return isset( $urls[ $id ] ) ? $urls[ $id ] : '#';
	}

	/**
	 * What each service receives, for the privacy policy.
	 *
	 * @param string $id Service id.
	 * @return string
	 */
	public static function privacy_text( $id ) {
		$texts = array(
			'turnstile' => __( 'Cloudflare receives the visitor\'s IP address and browser details to run the check. Mention it in your privacy policy.', 'aluleth-smtp' ),
			'hcaptcha'  => __( 'hCaptcha (Intuition Machines) receives the visitor\'s IP address and browser details to run the check. Mention it in your privacy policy.', 'aluleth-smtp' ),
			'recaptcha' => __( 'Google receives the visitor\'s IP address, browser details and how they use the page to run the check. Mention it in your privacy policy.', 'aluleth-smtp' ),
			'cleantalk' => __( 'CleanTalk receives the answers, the email address and the visitor\'s IP address for each entry. Mention it in your privacy policy.', 'aluleth-smtp' ),
		);
		return isset( $texts[ $id ] ) ? $texts[ $id ] : '';
	}
}
