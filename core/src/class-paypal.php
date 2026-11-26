<?php
/**
 * PayPal payments for every Aluleth plugin (Aluleth › Settings › Payments).
 *
 * Uses the PayPal REST API (Orders v2) with the site owner's own app: the
 * order is created and captured by the site, and the visitor approves it in
 * PayPal's own buttons (PayPal, Pay Later, or a card without a PayPal
 * account). Aluleth takes no fee.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal gateway.
 */
final class PayPal {

	/**
	 * Option name.
	 */
	const OPTION = 'aluleth_payments';

	/**
	 * Currencies PayPal accepts.
	 */
	const CURRENCIES = array( 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD' );

	/**
	 * Currencies without decimals at PayPal.
	 */
	const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'           => 'sandbox',
			'sandbox_client' => '',
			'sandbox_secret' => '',
			'live_client'    => '',
			'live_secret'    => '',
			'currency'       => self::guess_currency(),
			'paylater'       => true,
			'card'           => true,
			'color'          => 'gold',
			'shape'          => 'rect',
			'receipt'        => true,
		);
	}

	/**
	 * A sensible currency from the site language.
	 *
	 * @return string
	 */
	private static function guess_currency() {
		$locale = get_locale();
		$map    = array(
			'en_GB' => 'GBP',
			'en_US' => 'USD',
			'en_CA' => 'CAD',
			'fr_CA' => 'CAD',
			'en_AU' => 'AUD',
			'en_NZ' => 'NZD',
			'de_CH' => 'CHF',
			'fr_CH' => 'CHF',
			'pt_BR' => 'BRL',
			'es_MX' => 'MXN',
			'ja'    => 'JPY',
			'pl_PL' => 'PLN',
			'sv_SE' => 'SEK',
			'da_DK' => 'DKK',
			'nb_NO' => 'NOK',
			'cs_CZ' => 'CZK',
			'hu_HU' => 'HUF',
			'tl'    => 'PHP',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		return in_array( substr( $locale, 0, 2 ), array( 'fr', 'de', 'es', 'it', 'nl', 'pt', 'fi', 'el', 'sk', 'sl', 'et', 'lv', 'lt' ), true ) ? 'EUR' : 'USD';
	}

	/**
	 * Saved settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Client ID and secret of the current mode.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function keys() {
		$conf = self::get();
		$mode = 'live' === $conf['mode'] ? 'live' : 'sandbox';
		return array( (string) $conf[ $mode . '_client' ], (string) $conf[ $mode . '_secret' ] );
	}

	/**
	 * Whether payments can be taken.
	 *
	 * @return bool
	 */
	public static function ready() {
		list( $client, $secret ) = self::keys();
		return '' !== $client && '' !== $secret;
	}

	/**
	 * Whether the test mode is on.
	 *
	 * @return bool
	 */
	public static function sandbox() {
		return 'live' !== self::get()['mode'];
	}

	/**
	 * Public client ID (for the PayPal buttons).
	 *
	 * @return string
	 */
	public static function client_id() {
		return self::keys()[0];
	}

	/**
	 * Currency of the payments.
	 *
	 * @return string
	 */
	public static function currency() {
		$currency = strtoupper( (string) self::get()['currency'] );
		return in_array( $currency, self::CURRENCIES, true ) ? $currency : 'USD';
	}

	/**
	 * Whether PayPal takes payments in this currency.
	 *
	 * @param string $currency ISO code.
	 * @return bool
	 */
	public static function supports( $currency ) {
		return in_array( strtoupper( (string) $currency ), self::CURRENCIES, true );
	}

	/**
	 * Script of the PayPal buttons.
	 *
	 * @param string $currency Currency of the payment (empty: the one of the settings).
	 * @return string
	 */
	public static function script_url( $currency = '' ) {
		$conf     = self::get();
		$currency = strtoupper( (string) $currency );
		$args     = array(
			'client-id'  => self::client_id(),
			'currency'   => in_array( $currency, self::CURRENCIES, true ) ? $currency : self::currency(),
			'intent'     => 'capture',
			'components' => 'buttons',
		);
		$off      = array();
		if ( empty( $conf['card'] ) ) {
			$off[] = 'card';
		}
		if ( empty( $conf['paylater'] ) ) {
			$off[] = 'paylater';
		} else {
			$args['enable-funding'] = 'paylater';
		}
		if ( $off ) {
			$args['disable-funding'] = implode( ',', $off );
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.paypal.com/sdk/js' );
	}

	/**
	 * Look of the buttons.
	 *
	 * @return array{color: string, shape: string}
	 */
	public static function button_style() {
		$conf = self::get();
		return array(
			'color' => in_array( $conf['color'], array( 'gold', 'blue', 'black', 'white', 'silver' ), true ) ? $conf['color'] : 'gold',
			'shape' => 'pill' === $conf['shape'] ? 'pill' : 'rect',
		);
	}

	/**
	 * Amount as PayPal wants it ("49.00", or "49" without decimals).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function value( $amount, $currency ) {
		return in_array( $currency, self::NO_DECIMALS, true ) ? (string) (int) round( $amount ) : number_format( round( (float) $amount, 2 ), 2, '.', '' );
	}

	/**
	 * Amount for people, in the site language ("49,00 €").
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency (default: the payments currency).
	 * @return string
	 */
	public static function format( $amount, $currency = '' ) {
		$currency = '' !== $currency ? $currency : self::currency();
		if ( class_exists( '\\NumberFormatter' ) ) {
			$fmt = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			if ( in_array( $currency, self::NO_DECIMALS, true ) ) {
				$fmt->setAttribute( \NumberFormatter::FRACTION_DIGITS, 0 );
			}
			$out = $fmt->formatCurrency( (float) $amount, $currency );
			if ( false !== $out ) {
				return $out;
			}
		}
		$decimals = in_array( $currency, self::NO_DECIMALS, true ) ? 0 : 2;
		return number_format_i18n( (float) $amount, $decimals ) . ' ' . $currency;
	}

	/**
	 * API address of the current mode.
	 *
	 * @return string
	 */
	private static function api() {
		return self::sandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}

	/**
	 * Access token (cached until it expires).
	 *
	 * @param bool $fresh Ask PayPal again even if one is cached.
	 * @return string|\WP_Error
	 */
	private static function token( $fresh = false ) {
		list( $client, $secret ) = self::keys();
		if ( '' === $client || '' === $secret ) {
			return new \WP_Error( 'aluleth_paypal_keys', __( 'PayPal is not set up yet.', 'aluleth-smtp' ) );
		}
		$key = 'aluleth_paypal_token_' . substr( md5( $client . self::api() ), 0, 12 );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$response = wp_remote_post(
			self::api() . '/v1/oauth2/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
					'Accept'        => 'application/json',
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aluleth_paypal_network', __( 'PayPal could not be reached. Please try again in a moment.', 'aluleth-smtp' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $data['access_token'] ) ) {
			return new \WP_Error( 'aluleth_paypal_auth', __( 'PayPal did not accept the Client ID and secret. Copy them again from your PayPal app, and check the mode (test or live).', 'aluleth-smtp' ) );
		}
		set_transient( $key, (string) $data['access_token'], max( 60, (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ) - 120 ) );
		return (string) $data['access_token'];
	}

	/**
	 * Calls the PayPal API.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Path, e.g. /v2/checkout/orders.
	 * @param array  $body    JSON body (POST).
	 * @param string $request Idempotency key (PayPal-Request-Id).
	 * @return array|\WP_Error Decoded answer.
	 */
	private static function call( $method, $path, array $body = array(), $request = '' ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Prefer'        => 'return=representation',
		);
		if ( '' !== $request ) {
			$headers['PayPal-Request-Id'] = $request;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		);
		if ( 'POST' === $method ) {
			$args['body'] = $body ? wp_json_encode( $body ) : '{}';
		}
		$response = wp_remote_request( self::api() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'aluleth_paypal_network', __( 'PayPal could not be reached. Please try again in a moment.', 'aluleth-smtp' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( 401 === $code ) {
			delete_transient( 'aluleth_paypal_token_' . substr( md5( self::client_id() . self::api() ), 0, 12 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$issue = isset( $data['details'][0]['issue'] ) ? (string) $data['details'][0]['issue'] : ( isset( $data['name'] ) ? (string) $data['name'] : 'HTTP ' . $code );
			return new \WP_Error( 'aluleth_paypal_api', $issue, $data );
		}
		return $data;
	}

	/**
	 * Creates an order.
	 *
	 * @param array $args reference (string, max 127), description, items (name, price, quantity), total (float), request (idempotency key).
	 * @return array|\WP_Error Order (id, status…).
	 */
	public static function create_order( array $args ) {
		$currency = isset( $args['currency'] ) && in_array( strtoupper( (string) $args['currency'] ), self::CURRENCIES, true ) ? strtoupper( (string) $args['currency'] ) : self::currency();
		$items    = array();
		$sum      = 0.0;
		foreach ( (array) $args['items'] as $item ) {
			$qty     = max( 1, (int) ( isset( $item['quantity'] ) ? $item['quantity'] : 1 ) );
			$unit    = (float) self::value( $item['price'], $currency );
			$sum    += $unit * $qty;
			$items[] = array(
				'name'        => mb_substr( wp_strip_all_tags( (string) $item['name'] ), 0, 127 ),
				'quantity'    => (string) $qty,
				'unit_amount' => array(
					'currency_code' => $currency,
					'value'         => self::value( $unit, $currency ),
				),
			);
		}
		$tax       = isset( $args['tax'] ) ? max( 0, (float) self::value( $args['tax'], $currency ) ) : 0.0;
		$discount  = isset( $args['discount'] ) ? max( 0, (float) self::value( $args['discount'], $currency ) ) : 0.0;
		$breakdown = array(
			'item_total' => array(
				'currency_code' => $currency,
				'value'         => self::value( $sum, $currency ),
			),
		);
		if ( $tax > 0 ) {
			$breakdown['tax_total'] = array(
				'currency_code' => $currency,
				'value'         => self::value( $tax, $currency ),
			);
		}
		if ( $discount > 0 ) {
			$breakdown['discount'] = array(
				'currency_code' => $currency,
				'value'         => self::value( $discount, $currency ),
			);
		}
		$unit = array(
			'reference_id' => mb_substr( (string) $args['reference'], 0, 127 ),
			'custom_id'    => mb_substr( (string) $args['reference'], 0, 127 ),
			'description'  => mb_substr( wp_strip_all_tags( (string) $args['description'] ), 0, 127 ),
			'amount'       => array(
				'currency_code' => $currency,
				'value'         => self::value( max( 0, $sum + $tax - $discount ), $currency ),
				'breakdown'     => $breakdown,
			),
			'items'        => $items,
		);
		return self::call(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'              => 'CAPTURE',
				'purchase_units'      => array( $unit ),
				// Works for every button (PayPal, Pay Later, card); payment_source would tie the order to PayPal only.
				'application_context' => array(
					'brand_name'          => mb_substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 127 ),
					'shipping_preference' => 'NO_SHIPPING',
					'user_action'         => 'PAY_NOW',
				),
			),
			isset( $args['request'] ) ? (string) $args['request'] : ''
		);
	}

	/**
	 * Captures an approved order. An order already captured is read back.
	 *
	 * @param string $order_id Order id.
	 * @return array|\WP_Error Order.
	 */
	public static function capture( $order_id ) {
		$order_id = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $order_id ) );
		$result   = self::call( 'POST', '/v2/checkout/orders/' . $order_id . '/capture', array(), 'capture-' . $order_id );
		if ( is_wp_error( $result ) && 'ORDER_ALREADY_CAPTURED' === $result->get_error_message() ) {
			return self::get_order( $order_id );
		}
		return $result;
	}

	/**
	 * Reads an order.
	 *
	 * @param string $order_id Order id.
	 * @return array|\WP_Error
	 */
	public static function get_order( $order_id ) {
		$order_id = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $order_id ) );
		return self::call( 'GET', '/v2/checkout/orders/' . $order_id );
	}

	/**
	 * Payment details of a captured order: capture id, status, amount, payer.
	 *
	 * @param array $order Order from PayPal.
	 * @return array{status: string, capture_id: string, amount: float, currency: string, payer_email: string, payer_name: string, reference: string}
	 */
	public static function summary( array $order ) {
		$unit    = isset( $order['purchase_units'][0] ) ? $order['purchase_units'][0] : array();
		$capture = isset( $unit['payments']['captures'][0] ) ? $unit['payments']['captures'][0] : array();
		$name    = isset( $order['payer']['name'] ) ? trim( ( isset( $order['payer']['name']['given_name'] ) ? $order['payer']['name']['given_name'] : '' ) . ' ' . ( isset( $order['payer']['name']['surname'] ) ? $order['payer']['name']['surname'] : '' ) ) : '';
		return array(
			'status'      => isset( $capture['status'] ) ? (string) $capture['status'] : ( isset( $order['status'] ) ? (string) $order['status'] : '' ),
			'capture_id'  => isset( $capture['id'] ) ? (string) $capture['id'] : '',
			'amount'      => isset( $capture['amount']['value'] ) ? (float) $capture['amount']['value'] : ( isset( $unit['amount']['value'] ) ? (float) $unit['amount']['value'] : 0.0 ),
			'currency'    => isset( $capture['amount']['currency_code'] ) ? (string) $capture['amount']['currency_code'] : ( isset( $unit['amount']['currency_code'] ) ? (string) $unit['amount']['currency_code'] : '' ),
			'payer_email' => isset( $order['payer']['email_address'] ) ? sanitize_email( (string) $order['payer']['email_address'] ) : '',
			'payer_name'  => sanitize_text_field( $name ),
			'reference'   => isset( $unit['custom_id'] ) ? (string) $unit['custom_id'] : ( isset( $unit['reference_id'] ) ? (string) $unit['reference_id'] : '' ),
		);
	}

	/**
	 * Link to a payment in the PayPal account of the site owner.
	 *
	 * @param string $capture_id Capture (transaction) id.
	 * @param bool   $sandbox    Test payment.
	 * @return string
	 */
	public static function activity_url( $capture_id, $sandbox ) {
		return ( $sandbox ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com' ) . '/activity/payment/' . rawurlencode( (string) $capture_id );
	}

	/**
	 * "Save and check": asks PayPal for an access token with the keys.
	 *
	 * @return true|\WP_Error
	 */
	public static function check() {
		$token = self::token( true );
		return is_wp_error( $token ) ? $token : true;
	}

	/**
	 * Saves the Payments section.
	 *
	 * @param array $post Unslashed $_POST.
	 * @return string Notice.
	 */
	public static function save( array $post ) {
		$conf         = self::get();
		$conf['mode'] = isset( $post['pp_mode'] ) && 'live' === $post['pp_mode'] ? 'live' : 'sandbox';
		foreach ( array( 'sandbox', 'live' ) as $mode ) {
			if ( isset( $post[ 'pp_' . $mode . '_client' ] ) ) {
				$conf[ $mode . '_client' ] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $post[ 'pp_' . $mode . '_client' ] ), 0, 200 );
			}
			$secret = isset( $post[ 'pp_' . $mode . '_secret' ] ) ? trim( (string) $post[ 'pp_' . $mode . '_secret' ] ) : '';
			if ( '' !== $secret ) {
				$conf[ $mode . '_secret' ] = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $secret ), 0, 200 );
			}
			if ( ! empty( $post[ 'pp_' . $mode . '_clear' ] ) ) {
				$conf[ $mode . '_client' ] = '';
				$conf[ $mode . '_secret' ] = '';
			}
		}
		$currency         = isset( $post['pp_currency'] ) ? strtoupper( sanitize_key( $post['pp_currency'] ) ) : $conf['currency'];
		$conf['currency'] = in_array( $currency, self::CURRENCIES, true ) ? $currency : $conf['currency'];
		$conf['paylater'] = ! empty( $post['pp_paylater'] );
		$conf['card']     = ! empty( $post['pp_card'] );
		$conf['receipt']  = ! empty( $post['pp_receipt'] );
		$conf['color']    = isset( $post['pp_color'] ) && in_array( $post['pp_color'], array( 'gold', 'blue', 'black', 'white', 'silver' ), true ) ? $post['pp_color'] : 'gold';
		$conf['shape']    = isset( $post['pp_shape'] ) && 'pill' === $post['pp_shape'] ? 'pill' : 'rect';
		update_option( self::OPTION, $conf, false );

		$notice = '';
		if ( ! empty( $post['pp_check'] ) ) {
			$result = self::check();
			if ( is_wp_error( $result ) ) {
				$notice = 'error:' . $result->get_error_message();
			} else {
				$notice = self::sandbox() ? __( 'PayPal accepted the keys. Test mode: no real money moves.', 'aluleth-smtp' ) : __( 'PayPal accepted the keys. Live mode: payments are real.', 'aluleth-smtp' );
			}
		}
		/**
		 * Saves the payment methods added by an add-on to the Payments section.
		 * Return the notice to show ("error:" first for an error).
		 *
		 * @param string $notice Notice so far.
		 * @param array  $post   Unslashed $_POST.
		 */
		return (string) apply_filters( 'aluleth/settings/payments_save', $notice, $post );
	}

	/**
	 * Renders the Payments section.
	 *
	 * @return void
	 */
	public static function render() {
		$conf = self::get();
		echo '<section class="al-card al-sec al-pay-banner"><div><strong>0%</strong><span>' . esc_html__( 'Aluleth takes no fee on your payments. Only PayPal\'s own fees apply, paid to PayPal.', 'aluleth-smtp' ) . '</span></div></section>';

		echo '<section class="al-card al-sec" id="paypal"><div class="al-sec-head"><div><h2>' . esc_html__( 'PayPal', 'aluleth-smtp' ) . '</h2><p>' . esc_html__( 'Visitors pay with PayPal, Pay Later, or a debit or credit card without a PayPal account. The money goes straight to your PayPal account.', 'aluleth-smtp' ) . '</p></div>';
		echo '<span class="al-badge' . ( self::ready() ? ' al-badge--subscribed' : '' ) . '">' . esc_html( self::ready() ? ( self::sandbox() ? __( 'Ready · test mode', 'aluleth-smtp' ) : __( 'Ready · live', 'aluleth-smtp' ) ) : __( 'Not set up', 'aluleth-smtp' ) ) . '</span></div>';
		echo '<fieldset class="al-radios"><legend>' . esc_html__( 'Mode', 'aluleth-smtp' ) . '</legend><label><input type="radio" name="pp_mode" value="sandbox"' . checked( 'live' !== $conf['mode'], true, false ) . '> ' . esc_html__( 'Test (sandbox): no real money', 'aluleth-smtp' ) . '</label><label><input type="radio" name="pp_mode" value="live"' . checked( 'live', $conf['mode'], false ) . '> ' . esc_html__( 'Live: real payments', 'aluleth-smtp' ) . '</label></fieldset>';
		foreach ( array(
			'sandbox' => __( 'Test app (sandbox)', 'aluleth-smtp' ),
			'live'    => __( 'Live app', 'aluleth-smtp' ),
		) as $mode => $title ) {
			echo '<div class="al-svc-panel"><div class="al-svc-row"><strong>' . esc_html( $title ) . '</strong><a href="https://developer.paypal.com/dashboard/applications/' . esc_attr( $mode ) . '" target="_blank" rel="noopener">' . esc_html__( 'Where to find the keys', 'aluleth-smtp' ) . '</a></div><div class="al-grid2">';
			echo '<label class="al-field"><span>' . esc_html__( 'Client ID', 'aluleth-smtp' ) . '</span><input type="text" name="pp_' . esc_attr( $mode ) . '_client" value="' . esc_attr( $conf[ $mode . '_client' ] ) . '" autocomplete="off" spellcheck="false"></label>';
			/* translators: %s: last 4 characters of the saved secret. */
			$placeholder = '' !== $conf[ $mode . '_secret' ] ? sprintf( __( 'Saved, ending in %s', 'aluleth-smtp' ), substr( $conf[ $mode . '_secret' ], -4 ) ) : '';
			echo '<label class="al-field"><span>' . esc_html__( 'Secret', 'aluleth-smtp' ) . '</span><input type="password" name="pp_' . esc_attr( $mode ) . '_secret" value="" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="new-password" spellcheck="false"></label></div>';
			if ( '' !== $conf[ $mode . '_client' ] || '' !== $conf[ $mode . '_secret' ] ) {
				echo '<label class="al-small"><input type="checkbox" name="pp_' . esc_attr( $mode ) . '_clear" value="1"> ' . esc_html__( 'Remove these keys', 'aluleth-smtp' ) . '</label>';
			}
			echo '</div>';
		}
		echo '<div class="al-row"><button type="submit" name="pp_check" value="1" class="al-button al-button--ghost">' . esc_html__( 'Save and check the keys', 'aluleth-smtp' ) . '</button><span class="al-small">' . esc_html__( 'Checks the keys of the mode chosen above.', 'aluleth-smtp' ) . '</span></div></section>';

		echo '<section class="al-card al-sec" id="checkout"><div class="al-sec-head"><div><h2>' . esc_html__( 'Payment options', 'aluleth-smtp' ) . '</h2></div></div><div class="al-grid3">';
		echo '<label class="al-field"><span>' . esc_html__( 'Currency', 'aluleth-smtp' ) . '</span><select name="pp_currency">';
		foreach ( self::CURRENCIES as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $conf['currency'], $code, false ) . '>' . esc_html( $code ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="al-field"><span>' . esc_html__( 'Button colour', 'aluleth-smtp' ) . '</span><select name="pp_color">';
		foreach ( array(
			'gold'   => __( 'Gold (PayPal\'s own)', 'aluleth-smtp' ),
			'blue'   => __( 'Blue', 'aluleth-smtp' ),
			'black'  => __( 'Black', 'aluleth-smtp' ),
			'white'  => __( 'White', 'aluleth-smtp' ),
			'silver' => __( 'Silver', 'aluleth-smtp' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $conf['color'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><label class="al-field"><span>' . esc_html__( 'Button shape', 'aluleth-smtp' ) . '</span><select name="pp_shape"><option value="rect"' . selected( $conf['shape'], 'rect', false ) . '>' . esc_html__( 'Rounded corners', 'aluleth-smtp' ) . '</option><option value="pill"' . selected( $conf['shape'], 'pill', false ) . '>' . esc_html__( 'Pill', 'aluleth-smtp' ) . '</option></select></label></div>';
		echo '<div class="al-grid3">';
		echo '<label class="al-checkcard"><input type="checkbox" name="pp_card" value="1"' . checked( ! empty( $conf['card'] ), true, false ) . '> <span><strong>' . esc_html__( 'Card without a PayPal account', 'aluleth-smtp' ) . '</strong><small>' . esc_html__( 'A "Debit or credit card" button, filled in on PayPal\'s side.', 'aluleth-smtp' ) . '</small></span></label>';
		echo '<label class="al-checkcard"><input type="checkbox" name="pp_paylater" value="1"' . checked( ! empty( $conf['paylater'] ), true, false ) . '> <span><strong>' . esc_html__( 'Pay Later', 'aluleth-smtp' ) . '</strong><small>' . esc_html__( 'Shown by PayPal where it is offered (amount and country rules apply).', 'aluleth-smtp' ) . '</small></span></label>';
		echo '<label class="al-checkcard"><input type="checkbox" name="pp_receipt" value="1"' . checked( ! empty( $conf['receipt'] ), true, false ) . '> <span><strong>' . esc_html__( 'Email a receipt to the payer', 'aluleth-smtp' ) . '</strong><small>' . esc_html__( 'Sent when the form has an email field and no confirmation email of its own.', 'aluleth-smtp' ) . '</small></span></label></div>';
		echo '<p class="al-help">' . esc_html( self::privacy_text() ) . '</p></section>';
		/**
		 * Fires at the end of the Payments section, inside its form (add-ons add
		 * their payment methods here).
		 */
		do_action( 'aluleth/settings/payments_render' );
	}

	/**
	 * What PayPal receives, for the privacy policy.
	 *
	 * @return string
	 */
	public static function privacy_text() {
		return __( 'PayPal receives the amount, the items, the visitor\'s IP address and browser details, and the payment details the visitor types in PayPal\'s window. Mention it in your privacy policy.', 'aluleth-smtp' );
	}
}
