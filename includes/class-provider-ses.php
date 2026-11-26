<?php
/**
 * Amazon SES (API v2, raw emails, AWS Signature Version 4).
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon SES.
 */
final class Provider_Ses extends Provider {

	/**
	 * Regions where SES sends email.
	 */
	const REGIONS = array( 'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'sa-east-1', 'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-central-2', 'eu-south-1', 'eu-north-1', 'ap-south-1', 'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3', 'af-south-1', 'me-south-1', 'il-central-1', 'us-gov-west-1' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'ses';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Amazon SES';
	}

	/**
	 * Note.
	 *
	 * @return string
	 */
	public function note() {
		return __( 'API · pay as you go', 'aluleth-smtp' );
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return __( 'In AWS › IAM, create a user with the “ses:SendEmail” and “ses:SendRawEmail” rights (and “ses:GetAccount” for the check), then an access key for it. Choose the region where your domain is verified in SES.', 'aluleth-smtp' );
	}

	/**
	 * Docs.
	 *
	 * @return string
	 */
	public function docs() {
		return 'https://docs.aws.amazon.com/ses/latest/dg/setting-up.html';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			'access_key' => array(
				'label'    => __( 'Access key ID', 'aluleth-smtp' ),
				'type'     => 'text',
				'required' => true,
			),
			'secret_key' => array(
				'label'    => __( 'Secret access key', 'aluleth-smtp' ),
				'type'     => 'password',
				'required' => true,
			),
			'region'     => array(
				'label'   => __( 'Region', 'aluleth-smtp' ),
				'type'    => 'select',
				'options' => array_combine( self::REGIONS, self::REGIONS ),
			),
			'config_set' => array(
				'label' => __( 'Configuration set (optional)', 'aluleth-smtp' ),
				'type'  => 'text',
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
	 * Region of a connection.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private function region( array $settings ) {
		return isset( $settings['region'] ) && in_array( $settings['region'], self::REGIONS, true ) ? $settings['region'] : 'us-east-1';
	}

	/**
	 * Sends the message as a raw email.
	 *
	 * @param array $msg      Message.
	 * @param array $settings Settings.
	 * @param array $context  Context.
	 * @return array
	 */
	public function send( array $msg, array $settings, array $context = array() ) {
		$mime = Message::mime( $msg );
		if ( is_wp_error( $mime ) ) {
			return $this->fail( $mime->get_error_message() );
		}
		$body = array(
			'FromEmailAddress' => Message::format( $msg['from'] ),
			'Destination'      => array(
				'ToAddresses' => array_column( $msg['to'], 'email' ),
			),
			'Content'          => array(
				'Raw' => array( 'Data' => base64_encode( $mime ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);
		if ( $msg['cc'] ) {
			$body['Destination']['CcAddresses'] = array_column( $msg['cc'], 'email' );
		}
		if ( $msg['bcc'] ) {
			$body['Destination']['BccAddresses'] = array_column( $msg['bcc'], 'email' );
		}
		if ( ! empty( $settings['config_set'] ) ) {
			$body['ConfigurationSetName'] = $settings['config_set'];
		}
		$tags = array();
		if ( ! empty( $context['mail_id'] ) ) {
			$tags[] = array(
				'Name'  => 'aluleth_id',
				'Value' => $this->ref( $context ),
			);
		}
		if ( ! empty( $context['kind'] ) ) {
			$tags[] = array(
				'Name'  => 'kind',
				'Value' => 'marketing' === $context['kind'] ? 'marketing' : 'transactional',
			);
		}
		if ( $tags ) {
			$body['EmailTags'] = $tags;
		}
		$res = $this->signed( 'POST', '/v2/email/outbound-emails', wp_json_encode( $body ), $settings );
		$id  = ! is_wp_error( $res ) && isset( $res['json']['MessageId'] ) ? (string) $res['json']['MessageId'] : '';
		if ( ! is_wp_error( $res ) && 400 === $res['code'] && false !== strpos( $res['body'], 'not verified' ) ) {
			/* translators: %s: error text from Amazon SES. */
			return $this->fail( sprintf( __( 'Amazon SES: %s (while your account is in the sandbox, recipients must be verified too).', 'aluleth-smtp' ), $this->error_text( $res ) ), false, 400 );
		}
		return $this->result( $res, $id );
	}

	/**
	 * Checks the keys and tells whether the account is still in the sandbox.
	 *
	 * @param array $settings Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $settings ) {
		$res = $this->signed( 'GET', '/v2/email/account', '', $settings );
		$ok  = $this->checked( $res );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( isset( $res['json']['ProductionAccessEnabled'] ) && ! $res['json']['ProductionAccessEnabled'] ) {
			return new \WP_Error( 'alsmtp_sandbox', __( 'The keys work, but this Amazon SES account is still in the sandbox: it can only send to verified addresses. Ask AWS for production access.', 'aluleth-smtp' ), array( 'warning' => true ) );
		}
		return true;
	}

	/**
	 * A request signed with AWS Signature Version 4.
	 *
	 * @param string $method   GET or POST.
	 * @param string $path     Path.
	 * @param string $body     JSON body.
	 * @param array  $settings Settings.
	 * @return array|\WP_Error
	 */
	private function signed( $method, $path, $body, array $settings ) {
		$region = $this->region( $settings );
		// The Amazon SES API of the region you chose (an API call, not a file loaded in pages).
		$host    = 'email.' . $region . '.amazonaws.com'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
		$headers = self::sign( $method, $host, $path, (string) $body, $region, 'ses', (string) $settings['access_key'], (string) $settings['secret_key'], time() );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		return $this->http( $method, 'https://' . $host . $path, $headers, '' !== $body ? $body : null, 30 );
	}

	/**
	 * Headers of a request signed with AWS Signature Version 4 (no query string).
	 *
	 * @param string $method  Method.
	 * @param string $host    Host.
	 * @param string $path    Path.
	 * @param string $body    Body.
	 * @param string $region  Region.
	 * @param string $service Service.
	 * @param string $key     Access key id.
	 * @param string $secret  Secret key.
	 * @param int    $time    Unix time.
	 * @return array
	 */
	public static function sign( $method, $host, $path, $body, $region, $service, $key, $secret, $time ) {
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$date      = gmdate( 'Ymd', $time );
		$hash      = hash( 'sha256', $body );
		$canonical = $method . "\n" . $path . "\n\nhost:" . $host . "\nx-amz-content-sha256:" . $hash . "\nx-amz-date:" . $amz_date . "\n\nhost;x-amz-content-sha256;x-amz-date\n" . $hash;
		$scope     = $date . '/' . $region . '/' . $service . '/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$k         = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k         = hash_hmac( 'sha256', $region, $k, true );
		$k         = hash_hmac( 'sha256', $service, $k, true );
		$k         = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k );
		return array(
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=' . $signature,
			'X-Amz-Date'           => $amz_date,
			'X-Amz-Content-Sha256' => $hash,
		);
	}
	/**
	 * Events: through an Amazon SNS topic, set up in AWS.
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
		return __( 'In AWS › Amazon SNS, create a Standard topic in the same region and add an HTTPS subscription with this address (it is confirmed automatically; leave raw message delivery off). Then in Amazon SES › Identities › your domain › Notifications, send Bounce, Complaint and Delivery feedback to this topic.', 'aluleth-smtp' );
	}

	/**
	 * Reads an Amazon SNS message: checks Amazon's signature, confirms the subscription, reads SES notifications.
	 *
	 * @param string $body     Body.
	 * @param array  $headers  Headers.
	 * @param array  $query    Query.
	 * @param array  $settings Settings.
	 * @return array|\WP_Error
	 */
	public function hook_parse( $body, array $headers, array $query, array $settings ) {
		unset( $headers, $query, $settings );
		$sns = json_decode( (string) $body, true );
		if ( ! is_array( $sns ) || empty( $sns['Type'] ) ) {
			return new \WP_Error( 'alsmtp_sns', 'Not an Amazon SNS message', array( 'status' => 400 ) );
		}
		if ( ! self::sns_ok( $sns ) ) {
			return $this->bad_signature();
		}
		if ( 'SubscriptionConfirmation' === $sns['Type'] ) {
			if ( ! empty( $sns['SubscribeURL'] ) && self::amazon_url( (string) $sns['SubscribeURL'] ) ) {
				wp_safe_remote_get( (string) $sns['SubscribeURL'], array( 'timeout' => 15 ) );
			}
			return array();
		}
		if ( 'Notification' !== $sns['Type'] ) {
			return array();
		}
		$m = json_decode( (string) $sns['Message'], true );
		if ( ! is_array( $m ) ) {
			return array();
		}
		$kind = isset( $m['notificationType'] ) ? $m['notificationType'] : ( isset( $m['eventType'] ) ? $m['eventType'] : '' );
		$mail = isset( $m['mail'] ) && is_array( $m['mail'] ) ? $m['mail'] : array();
		$ref  = isset( $mail['tags']['aluleth_id'][0] ) ? (string) $mail['tags']['aluleth_id'][0] : '';
		$base = array(
			'message_id' => isset( $mail['messageId'] ) ? (string) $mail['messageId'] : '',
			'ref'        => $ref,
			'time'       => isset( $mail['timestamp'] ) ? $this->time_of( $mail['timestamp'] ) : 0,
		);
		$list = array();
		switch ( $kind ) {
			case 'Bounce':
				$b    = isset( $m['bounce'] ) ? (array) $m['bounce'] : array();
				$bt   = isset( $b['bounceType'] ) ? $b['bounceType'] : '';
				$type = 'Permanent' === $bt ? 'hard_bounce' : ( 'Transient' === $bt ? 'soft_bounce' : 'rejected' );
				foreach ( isset( $b['bouncedRecipients'] ) ? (array) $b['bouncedRecipients'] : array() as $r ) {
					$list[] = array(
						'type'   => $type,
						'email'  => isset( $r['emailAddress'] ) ? (string) $r['emailAddress'] : '',
						'detail' => trim( ( isset( $b['bounceSubType'] ) ? $b['bounceSubType'] . ' · ' : '' ) . ( isset( $r['diagnosticCode'] ) ? (string) $r['diagnosticCode'] : ( isset( $r['status'] ) ? (string) $r['status'] : '' ) ), ' ·' ),
					);
				}
				break;
			case 'Complaint':
				$cmp = isset( $m['complaint'] ) ? (array) $m['complaint'] : array();
				foreach ( isset( $cmp['complainedRecipients'] ) ? (array) $cmp['complainedRecipients'] : array() as $r ) {
					$list[] = array(
						'type'   => 'complaint',
						'email'  => isset( $r['emailAddress'] ) ? (string) $r['emailAddress'] : '',
						'detail' => isset( $cmp['complaintFeedbackType'] ) ? (string) $cmp['complaintFeedbackType'] : '',
					);
				}
				break;
			case 'Delivery':
				$d = isset( $m['delivery'] ) ? (array) $m['delivery'] : array();
				foreach ( isset( $d['recipients'] ) ? (array) $d['recipients'] : array() as $email ) {
					$list[] = array(
						'type'   => 'delivered',
						'email'  => (string) $email,
						'detail' => isset( $d['smtpResponse'] ) ? (string) $d['smtpResponse'] : '',
					);
				}
				break;
			case 'Reject':
				$list[] = array(
					'type'   => 'rejected',
					'email'  => '',
					'detail' => isset( $m['reject']['reason'] ) ? (string) $m['reject']['reason'] : '',
				);
				break;
			case 'DeliveryDelay':
				$d = isset( $m['deliveryDelay'] ) ? (array) $m['deliveryDelay'] : array();
				foreach ( isset( $d['delayedRecipients'] ) ? (array) $d['delayedRecipients'] : array() as $r ) {
					$list[] = array(
						'type'   => 'deferred',
						'email'  => isset( $r['emailAddress'] ) ? (string) $r['emailAddress'] : '',
						'detail' => isset( $r['diagnosticCode'] ) ? (string) $r['diagnosticCode'] : ( isset( $d['delayType'] ) ? (string) $d['delayType'] : '' ),
					);
				}
				break;
		}
		$out = array();
		foreach ( $list as $item ) {
			$out[] = array_merge(
				$base,
				$item,
				array( 'id' => ( isset( $sns['MessageId'] ) ? $sns['MessageId'] : '' ) . '-' . $item['email'] )
			);
		}
		return $out;
	}

	/**
	 * Whether an address is an Amazon SNS address (certificates, confirmations).
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	private static function amazon_url( $url ) {
		$parts = wp_parse_url( $url );
		return isset( $parts['scheme'], $parts['host'] ) && 'https' === $parts['scheme'] && (bool) preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $parts['host'] );
	}

	/**
	 * Checks the signature of an Amazon SNS message with Amazon's certificate.
	 *
	 * @param array $sns Message.
	 * @return bool
	 */
	public static function sns_ok( array $sns ) {
		if ( empty( $sns['Signature'] ) || empty( $sns['SigningCertURL'] ) || ! self::amazon_url( (string) $sns['SigningCertURL'] ) ) {
			return false;
		}
		if ( 'Notification' === $sns['Type'] ) {
			$keys = array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' );
		} else {
			$keys = array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		}
		$text = '';
		foreach ( $keys as $k ) {
			if ( isset( $sns[ $k ] ) ) {
				$text .= $k . "\n" . $sns[ $k ] . "\n";
			}
		}
		$cache = 'alsmtp_sns_' . md5( (string) $sns['SigningCertURL'] );
		$cert  = get_transient( $cache );
		if ( ! $cert ) {
			$res  = wp_safe_remote_get( (string) $sns['SigningCertURL'], array( 'timeout' => 15 ) );
			$cert = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
			if ( false === strpos( $cert, 'BEGIN CERTIFICATE' ) ) {
				return false;
			}
			set_transient( $cache, $cert, DAY_IN_SECONDS );
		}
		$key = openssl_pkey_get_public( $cert );
		$sig = base64_decode( (string) $sns['Signature'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Amazon signature.
		if ( ! $key || false === $sig ) {
			return false;
		}
		return 1 === openssl_verify( $text, $sig, $key, isset( $sns['SignatureVersion'] ) && '2' === (string) $sns['SignatureVersion'] ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1 );
	}

	/**
	 * Whether SES signs with DKIM for the domain (needs "ses:GetEmailIdentity").
	 *
	 * @param array  $settings Settings.
	 * @param string $domain   Domain.
	 * @return bool|null
	 */
	public function domain_check( array $settings, $domain ) {
		$res = $this->signed( 'GET', '/v2/email/identities/' . rawurlencode( $domain ), '', $settings );
		if ( is_wp_error( $res ) || ! in_array( $res['code'], array( 200, 404 ), true ) ) {
			return null;
		}
		if ( 404 === $res['code'] ) {
			return false;
		}
		return isset( $res['json']['DkimAttributes']['Status'] ) && 'SUCCESS' === $res['json']['DkimAttributes']['Status'];
	}

	/**
	 * SPF include.
	 *
	 * @return string
	 */
	public function spf_include() {
		return 'amazonses.com';
	}
}
