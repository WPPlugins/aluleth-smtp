<?php
/**
 * Sending contacts to outside tools, for every Aluleth plugin.
 *
 * A product (Forms, later Popups, Membership, Funnels) stores "feeds" with
 * its own settings and calls dispatch() with a payload in this shape:
 *
 *     array(
 *         'event'      => 'form.submitted',
 *         'email'      => 'ana@example.com',
 *         'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '',
 *         'consent'    => true,                         // newsletter consent given
 *         'fields'     => array( 'key' => array( 'label' => 'Your name', 'value' => 'Ana Lopez' ) ),
 *         'source'     => array( 'type' => 'form', 'id' => 12, 'title' => 'Contact', 'entry' => 40, 'url' => 'https://…' ),
 *         'time'       => '2026-10-03T11:05:00+00:00',
 *     )
 *
 * Each feed becomes a background job (see Queue): a slow or broken tool never
 * slows down or blocks the visitor.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations service.
 */
final class Integrations {

	/**
	 * Maximum feeds per form or popup.
	 */
	const MAX_FEEDS = 10;

	/**
	 * Service objects by id.
	 *
	 * @var Service[]|null
	 */
	private static $services = null;

	/**
	 * Hooks the job handler, routes and labels.
	 *
	 * @return void
	 */
	public static function register() {
		Queue::handle( 'integration', array( __CLASS__, 'run_job' ) );
		add_action( 'aluleth/queue/ran', array( __CLASS__, 'after_job' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'aluleth/event/label', array( __CLASS__, 'event_label' ), 10, 2 );
	}

	/**
	 * Available services.
	 *
	 * @return Service[] By id.
	 */
	public static function services() {
		if ( null === self::$services ) {
			$list = array( new Service_Mailchimp(), new Service_Brevo(), new Service_Mailerlite(), new Service_Kit(), new Service_Mautic(), new Service_Mailpoet(), new Service_Fluentcrm(), new Service_Slack(), new Service_Discord(), new Service_Telegram(), new Service_Webhook() );

			/**
			 * Filters the outside services contacts can be sent to.
			 *
			 * @param Service[] $list Services.
			 */
			$list           = (array) apply_filters( 'aluleth/integrations/services', $list );
			self::$services = array();
			foreach ( $list as $service ) {
				if ( $service instanceof Service ) {
					self::$services[ $service->id() ] = $service;
				}
			}
		}
		return self::$services;
	}

	/**
	 * One service.
	 *
	 * @param string $id Service id.
	 * @return Service|null
	 */
	public static function service( $id ) {
		$services = self::services();
		return isset( $services[ $id ] ) ? $services[ $id ] : null;
	}

	/**
	 * Data for the builders: every service with its lists, tags and fields.
	 *
	 * @return array[]
	 */
	public static function catalog() {
		$out = array();
		foreach ( self::services() as $service ) {
			$out[] = $service->catalog();
		}
		return $out;
	}

	/**
	 * Cleans feeds coming from a builder.
	 *
	 * @param mixed    $raw        Raw feeds.
	 * @param string[] $local_keys Keys of the product's fields that can be mapped.
	 * @return array[]
	 */
	public static function clean_feeds( $raw, array $local_keys ) {
		$out = array();
		$ids = array();
		foreach ( is_array( $raw ) ? $raw : array() as $feed ) {
			if ( ! is_array( $feed ) || empty( $feed['service'] ) || count( $out ) >= self::MAX_FEEDS ) {
				continue;
			}
			$service = sanitize_key( $feed['service'] );
			$svc     = self::service( $service );
			if ( ! $svc ) {
				continue;
			}
			$id = isset( $feed['id'] ) ? substr( sanitize_key( $feed['id'] ), 0, 20 ) : '';
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'f' . strtolower( wp_generate_password( 8, false, false ) );
			}
			$ids[ $id ] = true;

			$map = array();
			foreach ( isset( $feed['map'] ) && is_array( $feed['map'] ) ? $feed['map'] : array() as $remote => $local ) {
				$remote = substr( sanitize_text_field( (string) $remote ), 0, 64 );
				$local  = sanitize_key( (string) $local );
				if ( '' !== $remote && in_array( $local, $local_keys, true ) ) {
					$map[ $remote ] = $local;
				}
			}
			$url = isset( $feed['url'] ) ? esc_url_raw( trim( (string) $feed['url'] ), array( 'http', 'https' ) ) : '';

			$out[] = array(
				'id'           => $id,
				'service'      => $service,
				'name'         => isset( $feed['name'] ) ? substr( sanitize_text_field( $feed['name'] ), 0, 60 ) : '',
				'enabled'      => ! isset( $feed['enabled'] ) || ! empty( $feed['enabled'] ),
				'lists'        => array_values( $svc->clean_lists( self::clean_list( isset( $feed['lists'] ) ? $feed['lists'] : array() ) ) ),
				'tags'         => self::clean_list( isset( $feed['tags'] ) ? $feed['tags'] : array() ),
				'double_optin' => ! empty( $feed['double_optin'] ),
				'consent'      => isset( $feed['consent'] ) && 'always' === $feed['consent'] ? 'always' : 'marketing',
				'map'          => $map,
				'url'          => $url,
				'format'       => isset( $feed['format'] ) && 'form' === $feed['format'] ? 'form' : 'json',
				'secret'       => isset( $feed['secret'] ) ? substr( sanitize_text_field( (string) $feed['secret'] ), 0, 100 ) : '',
			);
		}
		return $out;
	}

	/**
	 * Cleans a list of ids or names (at most 20).
	 *
	 * @param mixed $raw Raw list or comma separated text.
	 * @return string[]
	 */
	private static function clean_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $item ) {
			$item = substr( trim( sanitize_text_field( (string) $item ) ), 0, 100 );
			if ( '' !== $item && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Whether a feed may send this payload (consent rule).
	 *
	 * Services without 'consent_choice' (newsletter tools) always need consent.
	 *
	 * @param array   $feed    Feed.
	 * @param array   $payload Payload.
	 * @param Service $service Service.
	 * @return bool
	 */
	public static function allowed( array $feed, array $payload, Service $service ) {
		if ( ! empty( $payload['consent'] ) ) {
			return true;
		}
		return 'always' === $feed['consent'] && in_array( 'consent_choice', $service->supports(), true );
	}

	/**
	 * Queues one job per active feed.
	 *
	 * @param array $feeds   Clean feeds.
	 * @param array $payload Payload.
	 * @param array $context 'object' => array( type, id ), 'contact' => id.
	 * @return int Jobs queued.
	 */
	public static function dispatch( array $feeds, array $payload, array $context = array() ) {
		$payload = wp_parse_args(
			$payload,
			array(
				'event'      => '',
				'email'      => '',
				'first_name' => '',
				'last_name'  => '',
				'phone'      => '',
				'consent'    => false,
				'fields'     => array(),
				'source'     => array(),
				'time'       => gmdate( 'c' ),
			)
		);
		$count   = 0;
		foreach ( $feeds as $feed ) {
			$service = isset( $feed['service'] ) ? self::service( $feed['service'] ) : null;
			if ( ! $service || empty( $feed['enabled'] ) ) {
				continue;
			}
			$meta = array(
				'object'  => isset( $context['object'] ) ? $context['object'] : array( '', 0 ),
				'contact' => isset( $context['contact'] ) ? (int) $context['contact'] : 0,
				'label'   => $service->label() . ( '' !== $feed['name'] ? ' · ' . $feed['name'] : '' ),
			);
			if ( ! self::allowed( $feed, $payload, $service ) ) {
				// Kept in the log so the owner sees why nothing was sent.
				$meta['status'] = 'skipped';
				$meta['result'] = __( 'No newsletter consent was given, so nothing was sent.', 'aluleth-smtp' );
				Queue::add( 'integration', array( 'feed' => array( 'service' => $feed['service'] ) ), $meta );
				continue;
			}
			if ( Queue::add(
				'integration',
				array(
					'feed'    => $feed,
					'payload' => $payload,
				),
				$meta
			) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Runs one queued send.
	 *
	 * @param array  $args Job data: feed, payload.
	 * @param object $row  Job.
	 * @return string|\WP_Error
	 */
	public static function run_job( array $args, $row ) {
		unset( $row );
		$feed    = isset( $args['feed'] ) && is_array( $args['feed'] ) ? $args['feed'] : array();
		$payload = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
		$service = isset( $feed['service'] ) ? self::service( $feed['service'] ) : null;
		if ( ! $service ) {
			return new \WP_Error( 'missing', __( 'This tool is no longer available on this site.', 'aluleth-smtp' ) );
		}
		if ( ! $service->available() ) {
			return new \WP_Error( 'missing', $service->missing_text() );
		}
		if ( ! self::allowed( $feed, $payload, $service ) ) {
			return new \WP_Error( 'skip', __( 'No newsletter consent was given, so nothing was sent.', 'aluleth-smtp' ), array( 'skip' => true ) );
		}
		return $service->send( $feed, $payload );
	}

	/**
	 * Writes the outcome in the contact's timeline (sent, or failed for good).
	 *
	 * @param object           $row    Job.
	 * @param string|\WP_Error $result Result.
	 * @return void
	 */
	public static function after_job( $row, $result ) {
		unset( $result );
		if ( ! $row || 'integration' !== $row->hook || ! $row->contact_id || ! in_array( $row->status, array( 'done', 'failed' ), true ) ) {
			return;
		}
		aluleth_core()->events()->record(
			'done' === $row->status ? 'integration.sent' : 'integration.failed',
			array(
				'contact' => (int) $row->contact_id,
				'object'  => array( $row->object_type, (int) $row->object_id ),
				'source'  => 'aluleth-smtp',
				'data'    => array( 'title' => $row->label ),
			)
		);
	}

	/**
	 * Timeline labels.
	 *
	 * @param string $label Label.
	 * @param object $event Event.
	 * @return string
	 */
	public static function event_label( $label, $event ) {
		$title = is_array( $event->data ) && ! empty( $event->data['title'] ) ? $event->data['title'] : '';
		if ( 'integration.sent' === $event->type ) {
			/* translators: %s: tool name, e.g. "MailPoet". */
			return sprintf( __( 'Sent to %s', 'aluleth-smtp' ), $title );
		}
		if ( 'integration.failed' === $event->type ) {
			/* translators: %s: tool name, e.g. "MailPoet". */
			return sprintf( __( 'Could not be sent to %s', 'aluleth-smtp' ), $title );
		}
		return $label;
	}

	/**
	 * REST routes for the builders.
	 *
	 * @return void
	 */
	public static function routes() {
		$admin = static function () {
			return current_user_can( Admin::cap( 'settings' ) );
		};
		register_rest_route(
			'aluleth/v1',
			'/integrations',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( $request ) {
					if ( $request->get_param( 'refresh' ) ) {
						foreach ( self::services() as $service ) {
							$service->flush();
						}
					}
					return rest_ensure_response( self::catalog() );
				},
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			'aluleth/v1',
			'/integrations/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_test' ),
				'permission_callback' => $admin,
			)
		);
	}

	/**
	 * Sends a test right away (webhooks, team alerts): sample data built from the fields given.
	 *
	 * @param \WP_REST_Request $request Request: feed, fields ( [ {key, label} ] ), title.
	 * @return \WP_REST_Response
	 */
	public static function rest_test( $request ) {
		$feeds   = self::clean_feeds( array( $request->get_param( 'feed' ) ), array() );
		$service = $feeds ? self::service( $feeds[0]['service'] ) : null;
		if ( ! $service || ! $service->testable() ) {
			return new \WP_REST_Response( array( 'message' => __( 'This tool cannot be tested this way.', 'aluleth-smtp' ) ), 400 );
		}
		$fields = array();
		foreach ( (array) $request->get_param( 'fields' ) as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				$key            = sanitize_key( $field['key'] );
				$fields[ $key ] = array(
					'label' => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key,
					'value' => isset( $field['sample'] ) ? sanitize_text_field( $field['sample'] ) : __( 'Example answer', 'aluleth-smtp' ),
				);
			}
		}
		$payload = array(
			'event'      => 'test',
			'email'      => 'test@example.com',
			'first_name' => __( 'Test', 'aluleth-smtp' ),
			'last_name'  => __( 'Person', 'aluleth-smtp' ),
			'phone'      => '',
			'consent'    => true,
			'fields'     => $fields,
			'source'     => array(
				'type'  => sanitize_key( (string) $request->get_param( 'type' ) ),
				'id'    => absint( $request->get_param( 'id' ) ),
				'title' => sanitize_text_field( (string) $request->get_param( 'title' ) ),
				'entry' => 0,
				'url'   => home_url( '/' ),
			),
			'time'       => gmdate( 'c' ),
		);
		$result  = $service->send( $feeds[0], $payload );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				),
				200
			);
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => $result,
			)
		);
	}
}
