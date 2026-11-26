<?php
/**
 * Aluleth › Connections: the tools contacts can be sent to, and the log of
 * recent sends with a "Send again" button for failed ones.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Connections screen.
 */
final class Connections {

	/**
	 * Page slug.
	 */
	const PAGE = 'aluleth-connections';

	/**
	 * Hooks the menu entry.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 98 );
		add_action( 'admin_post_aluleth_connection', array( __CLASS__, 'action' ) );
	}

	/**
	 * Connect, test or disconnect an account (admin-post).
	 *
	 * @return void
	 */
	public static function action() {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// Also "aluleth_connection_sheets", for the Google Sheets "Test the connection" button of Aluleth Forms Pro (only for that service).
		$legacy = ! wp_verify_nonce( $nonce, 'aluleth_connection' );
		if ( $legacy && ! wp_verify_nonce( $nonce, 'aluleth_connection_sheets' ) ) {
			wp_nonce_ays( 'aluleth_connection' );
		}
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'aluleth-smtp' ) );
		}
		$id = isset( $_POST['service'] ) ? sanitize_key( wp_unslash( $_POST['service'] ) ) : '';
		if ( $legacy && 'sheets' !== $id ) {
			wp_nonce_ays( 'aluleth_connection' );
		}
		$service = Integrations::service( $id );
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $service || 'api' !== $service->kind() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$notice = array( 'service' => $id );

		if ( 'remove' === $do ) {
			delete_option( 'aluleth_connection_' . $id );
			$service->flush();
			/* translators: %s: service name. */
			$notice['ok'] = sprintf( __( '%s is disconnected. Forms that send to it will show the sends as failed until you connect it again.', 'aluleth-smtp' ), $service->label() );
		} else {
			if ( 'save' === $do ) {
				$credentials = array();
				foreach ( $service->credential_fields() as $field => $def ) {
					$type  = isset( $def['type'] ) ? $def['type'] : 'password';
					$value = isset( $_POST[ 'cred_' . $field ] ) ? wp_unslash( $_POST[ 'cred_' . $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned just below, by type.
					if ( 'url' === $type ) {
						$value = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
					} elseif ( 'text' === $type ) {
						$value = trim( sanitize_text_field( (string) $value ) );
					} else {
						// Keys and passwords are kept as typed (only control characters and outer spaces removed): never shown again.
						$value = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) );
					}
					$credentials[ $field ] = $value;
				}
			} else {
				$credentials = $service->credentials();
			}
			$account = in_array( '', $credentials, true ) || ! $credentials ? new \WP_Error( 'empty', __( 'Paste the key first.', 'aluleth-smtp' ) ) : $service->verify( $credentials );
			if ( is_wp_error( $account ) ) {
				$notice['error'] = $account->get_error_message();
			} else {
				$saved = $service->connection();
				update_option(
					'aluleth_connection_' . $id,
					array(
						'credentials'  => $credentials,
						'account'      => (string) $account,
						'connected_at' => 'save' === $do || empty( $saved['connected_at'] ) ? Util::now() : $saved['connected_at'],
						'checked_at'   => Util::now(),
					),
					false
				);
				$service->flush();
				$notice['ok'] = 'save' === $do
					/* translators: 1: service name, 2: account name. */
					? sprintf( __( '%1$s is connected (%2$s). Choose it in a form’s Settings › Integrations.', 'aluleth-smtp' ), $service->label(), $account )
					/* translators: %s: service name. */
					: sprintf( __( 'The %s connection works.', 'aluleth-smtp' ), $service->label() );
			}
		}
		set_transient( 'aluleth_conn_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() . '#al-svc-' . $id );
		exit;
	}

	/**
	 * Adds the page under Aluleth (before Contacts).
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page( Admin::MENU, __( 'Connections', 'aluleth-smtp' ), __( 'Connections', 'aluleth-smtp' ), Admin::cap( 'settings' ), self::PAGE, array( __CLASS__, 'screen' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @param string $show '' for every send, 'problem' for failed ones.
	 * @return string
	 */
	public static function url( $show = '' ) {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $show ) {
			$args['show'] = $show;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function screen() {
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'aluleth-smtp' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$show    = isset( $_GET['show'] ) && 'problem' === $_GET['show'] ? 'problem' : '';
		$retried = isset( $_GET['aluleth_retried'] ) ? sanitize_key( wp_unslash( $_GET['aluleth_retried'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap al-wrap al-connections">';
		echo '<h1 class="al-title">' . esc_html__( 'Connections', 'aluleth-smtp' ) . '</h1>';
		echo '<p class="al-lead">' . esc_html__( 'Send your contacts to the tools you already use. Each form chooses where its entries go, in its Settings › Integrations. Sending happens in the background, so a slow tool never slows down your forms.', 'aluleth-smtp' ) . '</p>';

		if ( 'ok' === $retried ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Sent again successfully.', 'aluleth-smtp' ) . '</p></div>';
		} elseif ( 'error' === $retried ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'It failed again. The reason is shown in the list below.', 'aluleth-smtp' ) . '</p></div>';
		}

		$notice = get_transient( 'aluleth_conn_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'aluleth_conn_notice_' . get_current_user_id() );
			if ( ! empty( $notice['error'] ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $notice['error'] ) . '</p></div>';
			} elseif ( ! empty( $notice['ok'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $notice['ok'] ) . '</p></div>';
			}
		}

		$groups = array(
			'marketing'  => __( 'Email marketing and CRM', 'aluleth-smtp' ),
			'alerts'     => __( 'Team alerts', 'aluleth-smtp' ),
			'automation' => __( 'Automation', 'aluleth-smtp' ),
		);
		$by     = array();
		foreach ( Integrations::services() as $service ) {
			$group          = isset( $groups[ $service->group() ] ) ? $service->group() : 'marketing';
			$by[ $group ][] = $service;
		}
		foreach ( $groups as $group => $title ) {
			if ( empty( $by[ $group ] ) ) {
				continue;
			}
			echo '<h2 class="al-kicker al-svc-group">' . esc_html( $title ) . '</h2><div class="al-cards al-services">';
			foreach ( $by[ $group ] as $service ) {
				self::card( $service );
			}
			echo '</div>';
		}

		self::log( $show );
	}

	/**
	 * Card of one service.
	 *
	 * @param Service $service Service.
	 * @return void
	 */
	private static function card( Service $service ) {
		// A service with its own way to connect (a "Connect" button, OAuth…) draws its card.
		if ( method_exists( $service, 'render_card' ) ) {
			$service->render_card();
			return;
		}
		if ( 'api' === $service->kind() ) {
			self::api_card( $service );
			return;
		}
		$available = $service->available();
		if ( 'webhook' === $service->kind() ) {
			$state = __( 'Ready', 'aluleth-smtp' );
			$text  = $service->ready_text();
		} elseif ( $available ) {
			$state = __( 'Ready', 'aluleth-smtp' );
			/* translators: %s: plugin name, e.g. MailPoet. */
			$text = sprintf( __( '%s is active on this site: no key needed.', 'aluleth-smtp' ), $service->label() );
		} else {
			$state = __( 'Not installed', 'aluleth-smtp' );
			$text  = $service->missing_text();
		}
		echo '<section class="al-card al-service' . ( $available ? ' is-ready' : '' ) . '" id="al-svc-' . esc_attr( $service->id() ) . '">';
		echo '<div class="al-service-head"><span class="al-service-logo" aria-hidden="true">' . esc_html( strtoupper( substr( $service->label(), 0, 1 ) ) ) . '</span><h2>' . esc_html( $service->label() ) . '</h2><span class="al-badge' . ( $available ? ' al-badge--subscribed' : '' ) . '">' . esc_html( $state ) . '</span></div>';
		echo '<p>' . esc_html( $service->description() ) . '</p><p class="al-help">' . esc_html( $text ) . '</p>';
		if ( ! $available && current_user_can( 'install_plugins' ) ) {
			echo '<a class="al-button al-button--ghost" href="' . esc_url( admin_url( 'plugin-install.php?s=' . rawurlencode( $service->label() ) . '&tab=search&type=term' ) ) . '">' . esc_html__( 'Find the plugin', 'aluleth-smtp' ) . '</a>';
		}
		echo '</section>';
	}

	/**
	 * Recent sends.
	 *
	 * @param string $show '' or 'problem'.
	 * @return void
	 */
	private static function log( $show ) {
		$rows = Queue::recent( 50, $show );
		echo '<div class="al-head al-log-head"><h2 class="al-subtitle">' . esc_html__( 'Recent sends', 'aluleth-smtp' ) . '</h2><nav class="al-seg" aria-label="' . esc_attr__( 'Filter', 'aluleth-smtp' ) . '">';
		echo '<a href="' . esc_url( self::url() ) . '"' . ( '' === $show ? ' class="is-on" aria-current="true"' : '' ) . '>' . esc_html__( 'All', 'aluleth-smtp' ) . '</a>';
		echo '<a href="' . esc_url( self::url( 'problem' ) ) . '"' . ( 'problem' === $show ? ' class="is-on" aria-current="true"' : '' ) . '>' . esc_html__( 'Problems', 'aluleth-smtp' ) . '</a>';
		echo '</nav></div>';

		if ( ! $rows ) {
			echo '<div class="al-empty"><p>' . esc_html( 'problem' === $show ? __( 'No problem: every send went through.', 'aluleth-smtp' ) : __( 'Nothing sent yet. Sends appear here once a form has an integration and receives an entry.', 'aluleth-smtp' ) ) . '</p></div></div>';
			return;
		}

		echo '<div class="al-table-scroll"><table class="widefat striped al-table al-log"><thead><tr><th>' . esc_html__( 'When', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Tool', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'From', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Status', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Details', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo wp_kses( '<tr><td>' . esc_html( Admin::when( $row->created_at ) ) . '</td><td><strong>' . esc_html( $row->label ) . '</strong></td><td>' . self::object_link( $row ) . '</td>', Admin::allowed_html() );
			echo wp_kses( '<td>' . self::badge( $row ) . '</td><td>' . esc_html( Queue::detail( $row ) ), Admin::allowed_html() );
			if ( 'failed' === $row->status ) {
				echo wp_kses( self::retry_form( $row ), Admin::allowed_html() );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Card of a service connected with an API key.
	 *
	 * @param Service $service Service.
	 * @return void
	 */
	private static function api_card( Service $service ) {
		$connection = $service->connection();
		$connected  = $service->connected();
		$id         = $service->id();
		echo '<section class="al-card al-service' . ( $connected ? ' is-ready' : '' ) . '" id="al-svc-' . esc_attr( $id ) . '">';
		echo '<div class="al-service-head"><span class="al-service-logo" aria-hidden="true">' . esc_html( strtoupper( substr( $service->label(), 0, 1 ) ) ) . '</span><h2>' . esc_html( $service->label() ) . '</h2><span class="al-badge' . ( $connected ? ' al-badge--subscribed' : '' ) . '">' . esc_html( $connected ? __( 'Connected', 'aluleth-smtp' ) : __( 'Not connected', 'aluleth-smtp' ) ) . '</span></div>';
		echo '<p>' . esc_html( $service->description() ) . '</p>';
		$open = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-connect">' . wp_nonce_field( 'aluleth_connection', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="aluleth_connection"><input type="hidden" name="service" value="' . esc_attr( $id ) . '">';
		if ( $connected ) {
			$key  = $service->credentials();
			$key  = isset( $key['key'] ) ? (string) $key['key'] : '';
			$tail = strlen( $key ) > 8 ? substr( $key, -4 ) : '';
			/* translators: 1: account name, 2: date. */
			echo '<p class="al-help">' . esc_html( sprintf( __( 'Account: %1$s · connected on %2$s', 'aluleth-smtp' ), isset( $connection['account'] ) ? $connection['account'] : '', Admin::when( isset( $connection['connected_at'] ) ? $connection['connected_at'] : '' ) ) ) . ( '' !== $tail ? '<br>' . esc_html__( 'Key ending in', 'aluleth-smtp' ) . ' <code>…' . esc_html( $tail ) . '</code>' : '' ) . '</p>';
			echo wp_kses( '<div class="al-connect-row">' . $open . '<input type="hidden" name="do" value="test"><button type="submit" class="al-button al-button--ghost">' . esc_html__( 'Test the connection', 'aluleth-smtp' ) . '</button></form>', Admin::allowed_html() );
			echo wp_kses( $open . '<input type="hidden" name="do" value="remove"><button type="submit" class="button-link button-link-delete">' . esc_html__( 'Disconnect', 'aluleth-smtp' ) . '</button></form></div>', Admin::allowed_html() );
		} else {
			echo wp_kses( $open . '<input type="hidden" name="do" value="save">', Admin::allowed_html() );
			foreach ( $service->credential_fields() as $field => $def ) {
				$fid  = 'al-cred-' . $id . '-' . $field;
				$type = isset( $def['type'] ) && in_array( $def['type'], array( 'url', 'text' ), true ) ? $def['type'] : 'password';
				echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( $def['label'] ) . '</label><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $fid ) . '" name="cred_' . esc_attr( $field ) . '" autocomplete="off" spellcheck="false" required>';
				if ( ! empty( $def['help'] ) ) {
					echo '<p class="al-help">' . esc_html( $def['help'] ) . '</p>';
				}
			}
			echo '<button type="submit" class="al-button">' . esc_html__( 'Connect', 'aluleth-smtp' ) . '</button></form>';
		}
		echo '</section>';
	}

	/**
	 * Status badge of a job.
	 *
	 * @param object $row Job.
	 * @return string HTML.
	 */
	public static function badge( $row ) {
		$class = array(
			'done'    => 'al-badge--subscribed',
			'failed'  => 'al-badge--bounced',
			'pending' => (int) $row->attempts > 0 ? 'al-badge--new' : '',
		);
		return '<span class="al-badge ' . esc_attr( isset( $class[ $row->status ] ) ? $class[ $row->status ] : '' ) . '">' . esc_html( Queue::status_label( $row ) ) . '</span>';
	}

	/**
	 * "Send again" form for a failed job.
	 *
	 * @param object $row  Job.
	 * @param string $back Page to come back to (default: the page that showed the button).
	 * @return string HTML.
	 */
	public static function retry_form( $row, $back = '' ) {
		if ( ! current_user_can( Admin::cap( 'settings' ) ) ) {
			return '';
		}
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-retry">'
			. wp_nonce_field( 'aluleth_queue_retry', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="aluleth_queue_retry"><input type="hidden" name="job" value="' . esc_attr( (string) $row->id ) . '">'
			. ( '' !== $back ? '<input type="hidden" name="back" value="' . esc_url( $back ) . '">' : '' )
			. '<button type="submit" class="button button-small">' . esc_html__( 'Send again', 'aluleth-smtp' ) . '</button></form>';
	}

	/**
	 * Link to what the send came from (an entry, a popup…).
	 *
	 * @param object $row Job.
	 * @return string HTML.
	 */
	private static function object_link( $row ) {
		if ( ! $row->object_type ) {
			return '<span class="al-muted">—</span>';
		}
		/**
		 * Filters the link and label of what a send came from.
		 *
		 * @param array  $link   'url' and 'label'.
		 * @param string $type   Object type, e.g. 'entry'.
		 * @param int    $id     Object id.
		 */
		$link  = (array) apply_filters(
			'aluleth/queue/object_link',
			array(
				'url'   => '',
				/* translators: 1: object type, 2: id. */
				'label' => sprintf( __( '%1$s #%2$d', 'aluleth-smtp' ), ucfirst( $row->object_type ), (int) $row->object_id ),
			),
			$row->object_type,
			(int) $row->object_id
		);
		$label = isset( $link['label'] ) ? (string) $link['label'] : '';
		return ! empty( $link['url'] ) ? '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}
}
