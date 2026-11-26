<?php
/**
 * Aluleth › Email sending: menu, actions and notices. The screens are in Screens.
 *
 * @package Aluleth\Smtp
 */

namespace Aluleth\Smtp;

use Aluleth\Core\Admin as Core_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
final class Admin {

	/**
	 * Page slug.
	 */
	const PAGE = 'aluleth-smtp';

	/**
	 * Tabs.
	 */
	const TABS = array( 'overview', 'connections', 'routing', 'log', 'deliverability', 'bounces', 'settings' );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'save_connection', 'delete_connection', 'check_connection', 'test', 'save_routing', 'run_queue', 'resend', 'delete_mail', 'export', 'save_settings', 'test_alert' ) as $action ) {
			add_action( 'admin_post_alsmtp_' . $action, array( __CLASS__, 'action_' . $action ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'admin_post_alsmtp_hide_setup', array( __CLASS__, 'action_hide_setup' ) );
		Admin_Tools::register();
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public static function cap() {
		// Administrators only: whoever controls the connection receives every email, password resets included.
		return 'manage_options';
	}

	/**
	 * Adds the page.
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Core_Admin::MENU, __( 'Email sending', 'aluleth-smtp' ), __( 'Email sending', 'aluleth-smtp' ), self::cap(), self::PAGE, array( Screens::class, 'page' ) );
	}

	/**
	 * Address of a screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Styles and script of the screens.
	 *
	 * @param string $hook Screen.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		$url = plugins_url( 'assets/', ALULETH_SMTP_FILE );
		wp_enqueue_style( 'aluleth-smtp-admin', $url . 'admin.css', array( 'aluleth-core-admin' ), ALULETH_SMTP_VERSION );
		wp_enqueue_script( 'aluleth-smtp-admin', $url . 'admin.js', array(), ALULETH_SMTP_VERSION, true );
		wp_localize_script(
			'aluleth-smtp-admin',
			'alulethSmtp',
			array(
				'copied'  => __( 'Copied', 'aluleth-smtp' ),
				'confirm' => __( 'Delete this connection? Emails will use the other connections.', 'aluleth-smtp' ),
			)
		);
	}

	/**
	 * One notice, on the Plugins screen only, until a connection is set up or the administrator hides it.
	 *
	 * @return void
	 */
	public static function setup_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( self::cap() ) || get_user_meta( get_current_user_id(), 'alsmtp_hide_setup', true ) || Connections::active() ) {
			return;
		}
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=alsmtp_hide_setup' ), 'alsmtp_hide_setup' );
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Aluleth SMTP is active, but your emails still go out the default way.', 'aluleth-smtp' ) . ' <a href="' . esc_url(
			self::url(
				array(
					'tab' => 'connections',
					'add' => 1,
				)
			)
		) . '">' . esc_html__( 'Choose the service that sends them', 'aluleth-smtp' ) . '</a> · <a href="' . esc_url( $hide ) . '">' . esc_html__( 'Hide this notice', 'aluleth-smtp' ) . '</a></p></div>';
	}

	/**
	 * Hides the setup notice for this administrator.
	 *
	 * @return void
	 */
	public static function action_hide_setup() {
		check_admin_referer( 'alsmtp_hide_setup' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-smtp' ), 403 );
		}
		update_user_meta( get_current_user_id(), 'alsmtp_hide_setup', 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Checks the rights and the nonce of an action.
	 *
	 * @param string $action Action.
	 * @return void
	 */
	public static function guard( $action ) {
		check_admin_referer( 'alsmtp_' . $action );
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-smtp' ), 403 );
		}
		self::$action = 'alsmtp_' . $action;
	}

	/**
	 * Nonce action checked by guard() for this request ('' before).
	 *
	 * @var string
	 */
	private static $action = '';

	/**
	 * Saves a notice for the next screen, then goes there.
	 *
	 * @param array  $args Query arguments of the screen.
	 * @param string $type success, error or warning.
	 * @param string $text Text.
	 * @return void
	 */
	public static function back( array $args, $type = '', $text = '' ) {
		if ( '' !== $text ) {
			set_transient( 'alsmtp_notice_' . get_current_user_id(), array( $type, $text ), 120 );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * The notice saved for this screen (and forgets it).
	 *
	 * @return array|null type, text.
	 */
	public static function take_notice() {
		$key    = 'alsmtp_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
		}
		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * A POST value, unslashed and cleaned as plain text (each caller then checks it again: keys, numbers, addresses).
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public static function post( $key, $fallback = '' ) {
		if ( '' === self::$action || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::$action ) ) {
			return $fallback;
		}
		return isset( $_POST[ $key ] ) ? map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' ) : $fallback;
	}

	/**
	 * The service fields of a connection form, cleaned by type.
	 *
	 * @param string $provider Service id.
	 * @return array
	 */
	public static function post_fields( $provider ) {
		$p   = Connections::provider( $provider );
		$out = array();
		if ( ! $p || '' === self::$action || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::$action ) ) {
			return $out;
		}
		foreach ( $p->fields() as $key => $f ) {
			if ( ! isset( $_POST['f'][ $key ] ) || ! is_string( $_POST['f'][ $key ] ) ) {
				continue;
			}
			if ( 'password' === $f['type'] ) {
				// API keys and passwords are kept exactly as typed: cleaning could change a password.
				// They are stored encrypted, never shown again, and only sent to the service itself.
				$out[ $key ] = trim( wp_unslash( $_POST['f'][ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			} else {
				$out[ $key ] = sanitize_text_field( wp_unslash( $_POST['f'][ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * Saves a connection, then checks it with its service.
	 *
	 * @return void
	 */
	public static function action_save_connection() {
		self::guard( 'save_connection' );
		$id    = sanitize_key( (string) self::post( 'id' ) );
		$data  = array(
			'provider'   => sanitize_key( (string) self::post( 'provider' ) ),
			'label'      => (string) self::post( 'label' ),
			'role'       => sanitize_key( (string) self::post( 'role' ) ),
			'from_email' => (string) self::post( 'from_email' ),
			'from_name'  => (string) self::post( 'from_name' ),
			'force_from' => (string) self::post( 'force_from' ),
			'force_name' => (string) self::post( 'force_name' ),
			'limit_n'    => (string) self::post( 'limit_n' ),
			'limit_unit' => sanitize_key( (string) self::post( 'limit_unit' ) ),
			'f'          => self::post_fields( sanitize_key( (string) self::post( 'provider' ) ) ),
		);
		$saved = Connections::save( $data, $id );
		if ( is_wp_error( $saved ) ) {
			$back = '' !== $id ? array(
				'tab'  => 'connections',
				'edit' => $id,
			) : array(
				'tab'      => 'connections',
				'add'      => 1,
				'provider' => $data['provider'],
			);
			self::back( $back, 'error', $saved->get_error_message() );
		}
		$check = Connections::verify( $saved );
		$p     = Connections::provider( Connections::get( $saved )['provider'] );
		if ( $p && $p->is_oauth() ) {
			// Google and Microsoft: stay on the connection, where the sign-in button is.
			self::back(
				array(
					'tab'  => 'connections',
					'edit' => $saved,
				),
				true === $check ? 'success' : 'warning',
				true === $check ? __( 'Saved. The sign-in still works.', 'aluleth-smtp' ) : $check->get_error_message()
			);
		}
		if ( true === $check ) {
			self::back( array( 'tab' => 'connections' ), 'success', __( 'Saved. The service accepted the settings. Send a test email to be sure the From address is allowed.', 'aluleth-smtp' ) );
		}
		if ( null === $check ) {
			self::back( array( 'tab' => 'connections' ), 'success', __( 'Saved. This service has no check without sending: send a test email.', 'aluleth-smtp' ) );
		}
		$data = $check->get_error_data();
		/* translators: %s: error. */
		self::back( array( 'tab' => 'connections' ), is_array( $data ) && ! empty( $data['warning'] ) ? 'warning' : 'error', sprintf( __( 'Saved, but the check found a problem: %s', 'aluleth-smtp' ), $check->get_error_message() ) );
	}

	/**
	 * Deletes a connection.
	 *
	 * @return void
	 */
	public static function action_delete_connection() {
		self::guard( 'delete_connection' );
		$id = sanitize_key( (string) self::post( 'id' ) );
		$c  = Connections::get( $id );
		Hooks::forget( $id, $c ? $c : array() );
		Connections::delete( $id );
		self::back( array( 'tab' => 'connections' ), 'success', __( 'Connection deleted.', 'aluleth-smtp' ) );
	}

	/**
	 * Checks a connection again.
	 *
	 * @return void
	 */
	public static function action_check_connection() {
		self::guard( 'check_connection' );
		$r = Connections::verify( sanitize_key( (string) self::post( 'id' ) ) );
		if ( true === $r ) {
			self::back( array( 'tab' => 'connections' ), 'success', __( 'The service accepted the settings.', 'aluleth-smtp' ) );
		}
		if ( null === $r ) {
			self::back( array( 'tab' => 'connections' ), 'warning', __( 'This service has no check without sending: send a test email.', 'aluleth-smtp' ) );
		}
		$data = $r->get_error_data();
		self::back( array( 'tab' => 'connections' ), is_array( $data ) && ! empty( $data['warning'] ) ? 'warning' : 'error', $r->get_error_message() );
	}

	/**
	 * Sends a test email now.
	 *
	 * @param string $to         Address.
	 * @param string $connection Connection id ('' = the usual route).
	 * @return array ok, text, id.
	 */
	public static function send_test( $to, $connection = '' ) {
		$to = sanitize_email( $to );
		if ( '' === $to || ! is_email( $to ) ) {
			return array(
				'ok'   => false,
				'text' => __( 'Enter a valid email address.', 'aluleth-smtp' ),
				'id'   => 0,
			);
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$c    = '' !== $connection ? Connections::get( $connection ) : Connections::get( Connections::default_id() );
		if ( ! $c ) {
			return array(
				'ok'   => false,
				'text' => __( 'Add a connection first.', 'aluleth-smtp' ),
				'id'   => 0,
			);
		}
		/* translators: 1: connection name, 2: site name. */
		$subject = sprintf( __( 'Test email from %2$s through %1$s', 'aluleth-smtp' ), $c['label'], $site );
		$service = Connections::provider( $c['provider'] )->label();
		/* translators: %s: service name. */
		$which = sprintf( __( 'Connection: %s.', 'aluleth-smtp' ), $service );
		if ( $c['label'] !== $service ) {
			/* translators: 1: connection name, 2: service name. */
			$which = sprintf( __( 'Connection: %1$s (%2$s).', 'aluleth-smtp' ), $c['label'], $service );
		}
		$html = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#0e1116"><p>' . esc_html__( 'This email was sent by Aluleth SMTP to check your settings.', 'aluleth-smtp' ) . '</p><p>' . esc_html( $which ) . '</p><p>' . esc_html__( 'If it reached your inbox (not the spam folder), your emails can go out this way.', 'aluleth-smtp' ) . '</p></div>';
		$msg  = array(
			'from'        => array(
				'email' => $c['from_email'],
				'name'  => '' !== $c['from_name'] ? $c['from_name'] : $site,
			),
			'to'          => array(
				array(
					'email' => $to,
					'name'  => '',
				),
			),
			'cc'          => array(),
			'bcc'         => array(),
			'reply_to'    => array(),
			'subject'     => $subject,
			'html'        => $html,
			'text'        => Message::html_to_text( $html ),
			'charset'     => 'UTF-8',
			'headers'     => array(),
			'attachments' => array(),
			'meta'        => array(
				'connection' => $c['id'],
				'kind'       => 'transactional',
			),
		);
		// A test goes through this connection only, without the fallback.
		$only = static function ( $pick ) use ( $c ) {
			$pick['connection'] = $c['id'];
			$pick['fallback']   = '';
			return $pick;
		};
		add_filter( 'aluleth_smtp/route', $only, 999 );
		$ok = Sender::submit( $msg, 'test', array(), true );
		remove_filter( 'aluleth_smtp/route', $only, 999 );
		global $wpdb;
		$id  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE source = %s', Installer::table(), 'test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $id ? Log::get( $id ) : null;
		if ( $ok && $row && 'sent' === $row->status ) {
			if ( 'warning' !== $c['check_state'] ) {
				// A warning from the check (sender not validated…) stays: the service may still reject the email later.
				Connections::mark( $c['id'], 'ok', __( 'Test email accepted.', 'aluleth-smtp' ) );
			}
			return array(
				'ok'   => true,
				/* translators: 1: connection, 2: address. */
				'text' => sprintf( __( '%1$s accepted the test email for %2$s. Check that inbox (and its spam folder).', 'aluleth-smtp' ), $c['label'], $to ),
				'id'   => $id,
			);
		}
		$error = $row && '' !== (string) $row->response ? (string) $row->response : __( 'The email was not sent.', 'aluleth-smtp' );
		if ( $row && 'queued' === $row->status ) {
			/* translators: %s: error. */
			$error = sprintf( __( 'Not sent yet, it will be tried again: %s', 'aluleth-smtp' ), $error );
		}
		Connections::mark( $c['id'], 'error', $error );
		return array(
			'ok'   => false,
			'text' => $error,
			'id'   => $id,
		);
	}

	/**
	 * Test email from the screens.
	 *
	 * @return void
	 */
	public static function action_test() {
		self::guard( 'test' );
		$r    = self::send_test( (string) self::post( 'to' ), sanitize_key( (string) self::post( 'connection' ) ) );
		$back = array( 'tab' => sanitize_key( (string) self::post( 'back', 'overview' ) ) );
		self::back( $back, $r['ok'] ? 'success' : 'error', $r['text'] );
	}

	/**
	 * Saves routing: default, fallback, rules and the queue options.
	 *
	 * @return void
	 */
	public static function action_save_routing() {
		self::guard( 'save_routing' );
		$rules = array();
		foreach ( (array) self::post( 'rules', array() ) as $r ) {
			if ( is_array( $r ) ) {
				$rules[] = array_map( 'strval', $r );
			}
		}
		$all      = Connections::all();
		$default  = sanitize_key( (string) self::post( 'default' ) );
		$fallback = sanitize_key( (string) self::post( 'fallback' ) );
		Connections::save_settings(
			array(
				'default'         => isset( $all[ $default ] ) ? $default : '',
				'fallback'        => isset( $all[ $fallback ] ) && $fallback !== $default ? $fallback : '',
				'rules'           => Connections::clean_rules( $rules ),
				'queue_marketing' => (bool) self::post( 'queue_marketing' ),
				'paused'          => (bool) self::post( 'paused' ),
			)
		);
		self::back( array( 'tab' => 'routing' ), 'success', __( 'Routing saved.', 'aluleth-smtp' ) );
	}

	/**
	 * Runs the queue now.
	 *
	 * @return void
	 */
	public static function action_run_queue() {
		self::guard( 'run_queue' );
		$n = Queue::run( 20 );
		/* translators: %d: number of emails. */
		self::back( array( 'tab' => 'routing' ), 'success', sprintf( _n( '%d email handled.', '%d emails handled.', $n, 'aluleth-smtp' ), $n ) );
	}

	/**
	 * Sends a logged email again.
	 *
	 * @return void
	 */
	public static function action_resend() {
		self::guard( 'resend' );
		$id  = absint( self::post( 'mail' ) );
		$row = Log::get( $id );
		$msg = $row ? Log::message( $row ) : null;
		if ( ! $msg ) {
			self::back( array( 'tab' => 'log' ), 'error', __( 'Only a summary of this email was kept: it cannot be sent again.', 'aluleth-smtp' ) );
		}
		if ( ! empty( $msg['masked'] ) ) {
			self::back(
				array(
					'tab'  => 'log',
					'mail' => $id,
				),
				'error',
				__( 'This email held a link or a code that gives access to an account; it was hidden in the log, so the email cannot be sent again. Ask the person to request a new one.', 'aluleth-smtp' )
			);
		}
		$attachments = array();
		foreach ( isset( $msg['attachments'] ) ? (array) $msg['attachments'] : array() as $f ) {
			if ( ! empty( $f['path'] ) && is_readable( $f['path'] ) ) {
				$attachments[] = $f;
			}
		}
		$msg['attachments'] = $attachments;
		unset( $msg['route'], $msg['masked'] );
		$msg['meta'] = array( 'kind' => $row->kind );
		$ok          = Sender::submit( $msg, $row->source, array(), true );
		self::back( array( 'tab' => 'log' ), $ok ? 'success' : 'error', $ok ? __( 'Sent again. It is the newest row of the log.', 'aluleth-smtp' ) : __( 'It could not be sent again: see the newest row of the log.', 'aluleth-smtp' ) );
	}

	/**
	 * Deletes one logged email.
	 *
	 * @return void
	 */
	public static function action_delete_mail() {
		self::guard( 'delete_mail' );
		$row = Log::get( absint( self::post( 'mail' ) ) );
		if ( $row ) {
			$msg = Log::message( $row );
			if ( $msg ) {
				Queue::drop_attachments( $msg );
			}
			Log::delete( (int) $row->id );
		}
		self::back( array( 'tab' => 'log' ), 'success', __( 'Deleted from the log.', 'aluleth-smtp' ) );
	}

	/**
	 * Exports the log as CSV (with the screen's filters).
	 *
	 * @return void
	 */
	public static function action_export() {
		self::guard( 'export' );
		$args = array(
			'status'   => sanitize_key( (string) self::post( 'status' ) ),
			'source'   => sanitize_key( (string) self::post( 'source' ) ),
			'search'   => sanitize_text_field( (string) self::post( 's' ) ),
			'per_page' => 200,
			'page'     => 1,
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="email-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'date_utc', 'status', 'source', 'kind', 'connection', 'fallback', 'recipients', 'subject', 'message_id' ) );
		do {
			$res = Log::query( $args );
			foreach ( $res['rows'] as $r ) {
				fputcsv( $out, array( $r->id, $r->created_at, '' !== $r->outcome ? $r->outcome : $r->status, $r->source, $r->kind, $r->connection, $r->fallback, self::csv_cell( $r->recipients ), self::csv_cell( $r->subject ), $r->message_id ) );
			}
			++$args['page'];
			$more = 200 === count( $res['rows'] );
		} while ( $more && $args['page'] <= 250 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * A CSV cell that spreadsheets will not run as a formula.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * Saves the log and alert settings.
	 *
	 * @return void
	 */
	public static function action_save_settings() {
		self::guard( 'save_settings' );
		$s       = Connections::settings();
		$changes = array(
			'log_transactional' => sanitize_key( (string) self::post( 'log_transactional' ) ),
			'log_marketing'     => sanitize_key( (string) self::post( 'log_marketing' ) ),
			'keep_days'         => absint( self::post( 'keep_days', 30 ) ),
			'keep_failed'       => absint( self::post( 'keep_failed', 90 ) ),
			'alert_email'       => (bool) self::post( 'alert_email' ),
			'alert_to'          => sanitize_email( (string) self::post( 'alert_to' ) ),
			'telegram_chat'     => sanitize_text_field( (string) self::post( 'telegram_chat' ) ),
		);
		foreach ( array( 'slack', 'discord', 'telegram_token' ) as $key ) {
			$raw = trim( (string) self::post( $key ) );
			if ( self::post( $key . '_clear' ) ) {
				$changes[ $key ] = '';
			} elseif ( '' !== $raw ) {
				if ( 'slack' === $key && ! preg_match( Alerts::SLACK, $raw ) ) {
					self::back( array( 'tab' => 'settings' ), 'error', __( 'Paste the Slack address that starts with https://hooks.slack.com/services/', 'aluleth-smtp' ) );
				}
				if ( 'discord' === $key && ! preg_match( Alerts::DISCORD, $raw ) ) {
					self::back( array( 'tab' => 'settings' ), 'error', __( 'Paste the Discord address that starts with https://discord.com/api/webhooks/', 'aluleth-smtp' ) );
				}
				$changes[ $key ] = Secrets::lock( $raw );
			} else {
				$changes[ $key ] = $s[ $key ];
			}
		}
		Connections::save_settings( $changes );
		self::back( array( 'tab' => 'settings' ), 'success', __( 'Settings saved.', 'aluleth-smtp' ) );
	}

	/**
	 * Sends a test alert on every channel.
	 *
	 * @return void
	 */
	public static function action_test_alert() {
		self::guard( 'test_alert' );
		$done = Alerts::send(
			0,
			array(
				'subject' => __( 'Test alert', 'aluleth-smtp' ),
				'error'   => __( 'Nothing is wrong: this is a test.', 'aluleth-smtp' ),
			)
		);
		if ( ! $done ) {
			self::back( array( 'tab' => 'settings' ), 'warning', __( 'No alert channel is set up.', 'aluleth-smtp' ) );
		}
		$parts = array();
		foreach ( $done as $channel => $result ) {
			$parts[] = ucfirst( $channel ) . ': ' . ( true === $result ? __( 'sent', 'aluleth-smtp' ) : ( false === $result ? __( 'failed', 'aluleth-smtp' ) : $result ) );
		}
		self::back( array( 'tab' => 'settings' ), 'success', implode( ' · ', $parts ) );
	}
}
