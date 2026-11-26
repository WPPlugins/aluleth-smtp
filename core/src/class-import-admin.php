<?php
/**
 * Import screens: Contacts › Import (source, matching, progress, summary).
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Import admin.
 */
final class Import_Admin {

	/**
	 * Hooks the form handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_aluleth_import_start', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_aluleth_import_map', array( __CLASS__, 'map' ) );
		add_action( 'admin_post_aluleth_import_step', array( __CLASS__, 'step' ) );
		add_action( 'admin_post_aluleth_import_cancel', array( __CLASS__, 'cancel' ) );
		add_action( 'admin_post_aluleth_import_skipped', array( __CLASS__, 'download_skipped' ) );
		add_action( 'wp_ajax_aluleth_import_run', array( __CLASS__, 'ajax_run' ) );
	}

	/**
	 * URL of the import screen.
	 *
	 * @param string $job Job id.
	 * @return string
	 */
	public static function url( $job = '' ) {
		$args = array(
			'page' => 'aluleth-contacts',
			'view' => 'import',
		);
		if ( $job ) {
			$args['job'] = $job;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Stops when the user may not import.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! current_user_can( Admin::cap( 'contacts' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import contacts.', 'aluleth-smtp' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Goes back to the first step with an error message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function fail( $message ) {
		set_transient( 'aluleth_import_error_' . get_current_user_id(), $message, 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Step 1 handler: creates the job from the chosen source.
	 *
	 * @return void
	 */
	public static function start() {
		self::guard();
		check_admin_referer( 'aluleth_import_start' );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'file';

		if ( 'paste' === $source ) {
			// "Name <address>" lines are turned into "Name address" in the browser (import.js) before sending.
			$paste = isset( $_POST['paste'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['paste'] ) ), 0, 200000 ) : '';
			$job   = Import::from_text( $paste );
		} elseif ( 'users' === $source ) {
			$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array();
			$job   = Import::from_users( $roles );
		} else {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- file array, checked in Import::from_upload().
			$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();
			$job  = Import::from_upload( $file );
		}
		if ( is_wp_error( $job ) ) {
			self::fail( $job->get_error_message() );
		}
		wp_safe_redirect( self::url( $job['id'] ) );
		exit;
	}

	/**
	 * Step 2 handler: saves the matching and options.
	 *
	 * @return void
	 */
	public static function map() {
		self::guard();
		check_admin_referer( 'aluleth_import_map' );
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job = Import::get( $id );
		if ( ! $job ) {
			self::fail( __( 'This import has expired. Please start again.', 'aluleth-smtp' ) );
		}
		$map     = isset( $_POST['map'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['map'] ) ) : array();
		$keys    = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['keys'] ) ) : array();
		$options = array(
			'status'   => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'transactional',
			'consent'  => ! empty( $_POST['consent'] ),
			'existing' => isset( $_POST['existing'] ) ? sanitize_key( wp_unslash( $_POST['existing'] ) ) : 'fill',
			'tags'     => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
		);
		$result  = Import::configure( $job, $map, $keys, $options );
		if ( is_wp_error( $result ) ) {
			set_transient( 'aluleth_import_error_' . get_current_user_id(), $result->get_error_message(), 120 );
		}
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	/**
	 * Without JavaScript: imports one batch per page load.
	 *
	 * @return void
	 */
	public static function step() {
		self::guard();
		check_admin_referer( 'aluleth_import_run' );
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job = Import::get( $id );
		if ( $job ) {
			Import::run( $job );
		}
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	/**
	 * With JavaScript: imports one batch and returns the progress.
	 *
	 * @return void
	 */
	public static function ajax_run() {
		if ( ! current_user_can( Admin::cap( 'contacts' ) ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aluleth_import_run' );
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job = Import::get( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'This import has expired. Please start again.', 'aluleth-smtp' ) ), 404 );
		}
		$job = Import::run( $job );
		wp_send_json_success(
			array(
				'state'  => $job['state'],
				'done'   => (int) $job['done'],
				'total'  => (int) $job['total'],
				'counts' => $job['counts'],
			)
		);
	}

	/**
	 * Cancels an import that has not run yet (or deletes a finished one from the history).
	 *
	 * @return void
	 */
	public static function cancel() {
		self::guard();
		check_admin_referer( 'aluleth_import_cancel' );
		$id = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		Import::delete( $id );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Downloads the rows that were not imported.
	 *
	 * @return void
	 */
	public static function download_skipped() {
		self::guard();
		check_admin_referer( 'aluleth_import_skipped' );
		$id   = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$job  = Import::get( $id );
		$path = $job && $job['skipped'] ? Import::dir() . $job['skipped'] : '';
		if ( ! $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'This file is no longer available.', 'aluleth-smtp' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="not-imported-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 marker so Excel reads accents.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- CSV built by Import::skip() with formula-safe cells.
		exit;
	}

	/**
	 * The import screen.
	 *
	 * @return void
	 */
	public static function screen() {
		Import::cleanup();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$id  = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$job = $id ? Import::get( $id ) : null;

		wp_enqueue_script( 'aluleth-core-import', plugins_url( 'assets/import.js', ALULETH_CORE_PATH . '/bootstrap.php' ), array(), ALULETH_CORE_VERSION, true );
		wp_localize_script(
			'aluleth-core-import',
			'alulethImport',
			array(
				'ajax' => admin_url( 'admin-ajax.php' ),
				'i18n' => array(
					/* translators: 1: rows done, 2: total rows. */
					'progress' => __( '%1$s of %2$s', 'aluleth-smtp' ),
					'error'    => __( 'The import stopped because of a connection problem. Your contacts are safe: click Continue to go on where it stopped.', 'aluleth-smtp' ),
					'continue' => __( 'Continue', 'aluleth-smtp' ),
				),
			)
		);

		$step = ! $job ? 1 : ( 'mapping' === $job['state'] ? 2 : 3 );
		echo '<div class="wrap al-wrap al-import">';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aluleth-contacts' ) ) . '">&larr; ' . esc_html__( 'All contacts', 'aluleth-smtp' ) . '</a></p>';
		echo '<h1 class="al-title">' . esc_html__( 'Import contacts', 'aluleth-smtp' ) . '</h1>';
		echo '<ol class="al-steps">';
		foreach ( array(
			1 => __( 'Choose your list', 'aluleth-smtp' ),
			2 => __( 'Match the columns', 'aluleth-smtp' ),
			3 => __( 'Import', 'aluleth-smtp' ),
		) as $n => $label ) {
			echo '<li class="' . ( $n === $step ? 'is-current' : ( $n < $step ? 'is-done' : '' ) ) . '"' . ( $n === $step ? ' aria-current="step"' : '' ) . '><span>' . esc_html( (string) $n ) . '</span>' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';

		$error = get_transient( 'aluleth_import_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'aluleth_import_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( 1 === $step ) {
			self::step_source();
		} elseif ( 2 === $step ) {
			self::step_match( $job );
		} elseif ( 'done' === $job['state'] ) {
			self::summary( $job );
		} else {
			self::step_run( $job );
		}
		echo '</div>';
	}

	/**
	 * Step 1: where the list comes from.
	 *
	 * @return void
	 */
	private static function step_source() {
		$max   = size_format( wp_max_upload_size() );
		$roles = wp_roles()->get_names();
		$count = count_users();

		echo '<form class="al-card al-import-source" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aluleth_import_start' );
		echo '<input type="hidden" name="action" value="aluleth_import_start">';
		echo '<p class="al-lead">' . esc_html__( 'Bring the list you had in another tool. Your contacts stay on your own site.', 'aluleth-smtp' ) . '</p>';

		echo '<fieldset class="al-choices"><legend class="screen-reader-text">' . esc_html__( 'Source', 'aluleth-smtp' ) . '</legend>';
		$sources = array(
			'file'  => array( __( 'A file', 'aluleth-smtp' ), __( 'CSV, TXT or ZIP exported from Mailchimp, Brevo, MailerLite, Kit, ActiveCampaign, systeme.io, Excel or Google Sheets.', 'aluleth-smtp' ) ),
			'paste' => array( __( 'Paste addresses', 'aluleth-smtp' ), __( 'A few addresses copied from an email or a document.', 'aluleth-smtp' ) ),
			'users' => array( __( 'WordPress users', 'aluleth-smtp' ), __( 'The people who have an account on this site.', 'aluleth-smtp' ) ),
		);
		foreach ( $sources as $key => $source ) {
			echo '<label class="al-choice"><input type="radio" name="source" value="' . esc_attr( $key ) . '" id="al-src-' . esc_attr( $key ) . '"' . checked( 'file', $key, false ) . '><span><strong>' . esc_html( $source[0] ) . '</strong><small>' . esc_html( $source[1] ) . '</small></span></label>';
		}
		echo '</fieldset>';

		echo '<div class="al-panel al-panel--file"><label class="al-field-label" for="al-file">' . esc_html__( 'Your file', 'aluleth-smtp' ) . '</label>';
		echo '<input type="file" id="al-file" name="file" accept=".csv,.txt,.tsv,.zip,text/csv,text/plain,application/zip">';
		/* translators: %s: maximum upload size, e.g. 64 MB. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Up to %s. Columns can be separated by commas, semicolons or tabs, in any encoding.', 'aluleth-smtp' ), $max ) ) . '</p>';
		echo '<details class="al-help"><summary>' . esc_html__( 'How do I get this file?', 'aluleth-smtp' ) . '</summary><ul>';
		echo '<li>' . esc_html__( 'In your old tool, open the page that lists your contacts (often called Contacts, Subscribers or Audience) and look for Export. Choose CSV.', 'aluleth-smtp' ) . '</li>';
		echo '<li>' . esc_html__( 'Mailchimp gives a ZIP file with one file per status. Import the ZIP as it is: people who unsubscribed or bounced stay out of your emails.', 'aluleth-smtp' ) . '</li>';
		echo '<li>' . esc_html__( 'Excel: File › Save as › CSV. Google Sheets: File › Download › CSV.', 'aluleth-smtp' ) . '</li>';
		echo '</ul></details></div>';

		echo '<div class="al-panel al-panel--paste"><label class="al-field-label" for="al-paste">' . esc_html__( 'Addresses', 'aluleth-smtp' ) . '</label>';
		echo '<textarea id="al-paste" name="paste" rows="7" placeholder="' . esc_attr( "ana@example.com\nLeo Martin <leo@example.com>\nsara@example.com, Sara Diaz" ) . '"></textarea>';
		echo '<p class="description">' . esc_html__( 'One person per line. You can add the name before the address or after a comma.', 'aluleth-smtp' ) . '</p></div>';

		echo '<div class="al-panel al-panel--users"><fieldset><legend class="al-field-label">' . esc_html__( 'Roles to import', 'aluleth-smtp' ) . '</legend>';
		foreach ( $roles as $role => $name ) {
			$n = isset( $count['avail_roles'][ $role ] ) ? (int) $count['avail_roles'][ $role ] : 0;
			if ( ! $n ) {
				continue;
			}
			echo '<label class="al-check"><input type="checkbox" name="roles[]" value="' . esc_attr( $role ) . '"' . checked( 'administrator' !== $role, true, false ) . '> ' . esc_html( translate_user_role( $name ) ) . ' <span class="al-muted">(' . esc_html( number_format_i18n( $n ) ) . ')</span></label>';
		}
		echo '</fieldset></div>';

		echo '<p class="al-actions"><button type="submit" class="al-button al-button--accent">' . esc_html__( 'Continue', 'aluleth-smtp' ) . '</button></p>';
		echo '</form>';

		$history = Import::history();
		if ( $history ) {
			echo '<h2 class="al-subtitle">' . esc_html__( 'Recent imports', 'aluleth-smtp' ) . '</h2>';
			echo '<table class="widefat striped al-table"><thead><tr><th>' . esc_html__( 'Date', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'List', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'New', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Updated', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Not imported', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $job ) {
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $job['ended_at'] ) ) . '</td><td><a href="' . esc_url( self::url( $job['id'] ) ) . '">' . esc_html( $job['name'] ) . '</a></td><td>' . esc_html( number_format_i18n( $job['counts']['created'] ) ) . '</td><td>' . esc_html( number_format_i18n( $job['counts']['updated'] ) ) . '</td><td>' . esc_html( number_format_i18n( $job['counts']['skipped'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			/* translators: %d: number of days. */
			echo '<p class="description">' . esc_html( sprintf( _n( 'Imports are listed here for %d day.', 'Imports are listed here for %d days.', Import::KEEP_DAYS, 'aluleth-smtp' ), Import::KEEP_DAYS ) ) . '</p>';
		}
	}

	/**
	 * Step 2: matching and options.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function step_match( array $job ) {
		$targets = Import::targets();
		echo '<form class="al-card al-import-match" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aluleth_import_map' );
		echo '<input type="hidden" name="action" value="aluleth_import_map"><input type="hidden" name="job" value="' . esc_attr( $job['id'] ) . '">';

		if ( 'users' === $job['source'] ) {
			/* translators: %s: number of users. */
			echo '<p class="al-lead">' . esc_html( sprintf( _n( '%s user will be imported with their name and email address.', '%s users will be imported with their name and email address.', $job['total'], 'aluleth-smtp' ), number_format_i18n( $job['total'] ) ) ) . '</p>';
		} else {
			/* translators: 1: file name, 2: number of rows. */
			echo '<p class="al-lead">' . esc_html( sprintf( _n( '%1$s: %2$s row found. Check where each column goes.', '%1$s: %2$s rows found. Check where each column goes.', $job['total'], 'aluleth-smtp' ), $job['name'], number_format_i18n( $job['total'] ) ) ) . '</p>';
			$preview = Import::preview( $job );
			echo '<div class="al-table-scroll"><table class="widefat al-table al-map"><thead><tr><th>' . esc_html__( 'Column in your file', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Examples', 'aluleth-smtp' ) . '</th><th>' . esc_html__( 'Import as', 'aluleth-smtp' ) . '</th></tr></thead><tbody>';
			foreach ( $job['header'] as $i => $title ) {
				$examples = array();
				foreach ( $preview as $row ) {
					if ( isset( $row[ $i ] ) && '' !== Import::clean_cell( $row[ $i ] ) ) {
						$examples[] = Import::clean_cell( $row[ $i ] );
					}
				}
				$current = isset( $job['map'][ $i ] ) ? $job['map'][ $i ] : array(
					'to'  => '',
					'key' => '',
				);
				echo '<tr class="' . ( '' === $current['to'] ? 'is-off' : '' ) . '"><th scope="row">' . esc_html( '' !== $title ? $title : '—' ) . '</th><td class="al-examples">' . esc_html( implode( ' · ', array_slice( $examples, 0, 3 ) ) ) . '</td><td>';
				echo '<label class="screen-reader-text" for="al-map-' . esc_attr( (string) $i ) . '">' . esc_html( $title ) . '</label><select id="al-map-' . esc_attr( (string) $i ) . '" name="map[' . esc_attr( (string) $i ) . ']" class="al-map-select">';
				foreach ( $targets as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $current['to'], $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select> <input type="text" class="al-map-key" name="keys[' . esc_attr( (string) $i ) . ']" value="' . esc_attr( $current['key'] ) . '" aria-label="' . esc_attr__( 'Custom field name', 'aluleth-smtp' ) . '" placeholder="' . esc_attr__( 'Field name', 'aluleth-smtp' ) . '"></td></tr>';
			}
			echo '</tbody></table></div>';
		}

		$has_status = false;
		foreach ( $job['map'] as $target ) {
			$has_status = $has_status || in_array( $target['to'], array( 'status', 'optout' ), true );
		}

		echo '<fieldset class="al-option"><legend>' . esc_html__( 'Marketing emails', 'aluleth-smtp' ) . '</legend>';
		$statuses = array(
			'transactional' => array( __( 'Not subscribed', 'aluleth-smtp' ), __( 'They only receive emails linked to what they do on your site (orders, account, replies).', 'aluleth-smtp' ) ),
			'subscribed'    => array( __( 'Subscribed', 'aluleth-smtp' ), __( 'They agreed to receive your newsletters and offers.', 'aluleth-smtp' ) ),
			'pending'       => array( __( 'Waiting for confirmation', 'aluleth-smtp' ), __( 'You will be able to ask them to confirm by email.', 'aluleth-smtp' ) ),
		);
		foreach ( $statuses as $value => $status ) {
			echo '<label class="al-radio"><input type="radio" name="status" value="' . esc_attr( $value ) . '"' . checked( 'transactional', $value, false ) . '><span><strong>' . esc_html( $status[0] ) . '</strong><small>' . esc_html( $status[1] ) . '</small></span></label>';
		}
		echo '<label class="al-consent"><input type="checkbox" name="consent" value="1"> <span>' . esc_html( Import::consent_text() ) . '</span></label>';
		if ( $has_status ) {
			echo '<p class="al-note">' . esc_html__( 'Your file has a status column: it is used for each person. People who unsubscribed, bounced or marked emails as spam stay out of your marketing emails. Subscribed people are imported as subscribed only if you tick the box above.', 'aluleth-smtp' ) . '</p>';
		} else {
			echo '<p class="al-note">' . esc_html__( 'People who unsubscribed in the past are never subscribed again by an import.', 'aluleth-smtp' ) . '</p>';
		}
		echo '</fieldset>';

		echo '<fieldset class="al-option"><legend>' . esc_html__( 'People already in your contacts', 'aluleth-smtp' ) . '</legend>';
		$existing = array(
			'fill'   => __( 'Fill in only what is missing', 'aluleth-smtp' ),
			'update' => __( 'Replace with the values of the file', 'aluleth-smtp' ),
			'skip'   => __( 'Leave them as they are', 'aluleth-smtp' ),
		);
		foreach ( $existing as $value => $label ) {
			echo '<label class="al-radio al-radio--inline"><input type="radio" name="existing" value="' . esc_attr( $value ) . '"' . checked( 'fill', $value, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</fieldset>';

		echo '<div class="al-option"><label class="al-field-label" for="al-tags">' . esc_html__( 'Add tags to everyone', 'aluleth-smtp' ) . '</label>';
		echo '<input type="text" id="al-tags" name="tags" list="al-tags-list" placeholder="' . esc_attr__( 'For example: Mailchimp, Newsletter', 'aluleth-smtp' ) . '"><datalist id="al-tags-list">';
		foreach ( aluleth_core()->tags()->all( 200 ) as $tag ) {
			echo '<option value="' . esc_attr( $tag ) . '">';
		}
		echo '</datalist><p class="description">' . esc_html__( 'Separate tags with commas. A tag helps you find this group later.', 'aluleth-smtp' ) . '</p></div>';

		/* translators: %s: number of rows. */
		echo '<p class="al-actions"><button type="submit" class="al-button al-button--accent">' . esc_html( sprintf( _n( 'Import %s contact', 'Import %s contacts', $job['total'], 'aluleth-smtp' ), number_format_i18n( $job['total'] ) ) ) . '</button></p>';
		echo '</form>';
		self::cancel_form( $job, __( 'Cancel this import', 'aluleth-smtp' ) );
	}

	/**
	 * Step 3: progress.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function step_run( array $job ) {
		$total = max( 1, (int) $job['total'] );
		$pct   = min( 100, (int) floor( $job['done'] * 100 / $total ) );
		echo '<div class="al-card al-run" data-job="' . esc_attr( $job['id'] ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'aluleth_import_run' ) ) . '" data-done-url="' . esc_url( self::url( $job['id'] ) ) . '">';
		echo '<p class="al-lead">' . esc_html__( 'Importing your contacts… You can keep this page open; it only takes a moment for most lists.', 'aluleth-smtp' ) . '</p>';
		echo '<div class="al-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $pct ) . '"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
		/* translators: 1: rows done, 2: total rows. */
		echo '<p class="al-progress-text" aria-live="polite">' . esc_html( sprintf( __( '%1$s of %2$s', 'aluleth-smtp' ), number_format_i18n( $job['done'] ), number_format_i18n( $job['total'] ) ) ) . '</p>';
		echo '<p class="al-run-error" hidden></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-run-fallback">';
		wp_nonce_field( 'aluleth_import_run' );
		echo '<input type="hidden" name="action" value="aluleth_import_step"><input type="hidden" name="job" value="' . esc_attr( $job['id'] ) . '"><button type="submit" class="al-button">' . esc_html__( 'Continue', 'aluleth-smtp' ) . '</button></form>';
		echo '</div>';
	}

	/**
	 * Summary of a finished import.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function summary( array $job ) {
		$c = $job['counts'];
		echo '<div class="al-card al-summary">';
		echo '<h2>' . esc_html__( 'Import finished', 'aluleth-smtp' ) . '</h2>';
		echo '<dl class="al-stats al-stats--big">';
		$stats = array(
			__( 'New contacts', 'aluleth-smtp' ) => $c['created'],
			__( 'Updated', 'aluleth-smtp' )      => $c['updated'],
		);
		if ( $c['existing'] ) {
			$stats[ __( 'Already there, left as they were', 'aluleth-smtp' ) ] = $c['existing'];
		}
		$stats[ __( 'Not imported', 'aluleth-smtp' ) ] = $c['skipped'];
		foreach ( $stats as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( $value ) ) . '</dd></div>';
		}
		echo '</dl><ul class="al-notes">';
		if ( $c['duplicate'] ) {
			/* translators: %s: number of rows. */
			echo '<li>' . esc_html( sprintf( _n( '%s address appeared more than once in the file and was merged.', '%s addresses appeared more than once in the file and were merged.', $c['duplicate'], 'aluleth-smtp' ), number_format_i18n( $c['duplicate'] ) ) ) . '</li>';
		}
		if ( $c['kept'] ) {
			/* translators: %s: number of contacts. */
			echo '<li>' . esc_html( sprintf( _n( '%s person had unsubscribed before and stays unsubscribed.', '%s people had unsubscribed before and stay unsubscribed.', $c['kept'], 'aluleth-smtp' ), number_format_i18n( $c['kept'] ) ) ) . '</li>';
		}
		if ( ! empty( $job['options']['tags'] ) ) {
			/* translators: %s: list of tags. */
			echo '<li>' . esc_html( sprintf( __( 'Tags added: %s', 'aluleth-smtp' ), implode( ', ', $job['options']['tags'] ) ) ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="al-actions"><a class="al-button al-button--accent" href="' . esc_url( admin_url( 'admin.php?page=aluleth-contacts' ) ) . '">' . esc_html__( 'See your contacts', 'aluleth-smtp' ) . '</a> <a class="al-button al-button--ghost" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Import another list', 'aluleth-smtp' ) . '</a>';
		if ( $job['skipped'] && file_exists( Import::dir() . $job['skipped'] ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'aluleth_import_skipped',
						'job'    => $job['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'aluleth_import_skipped'
			);
			echo ' <a class="al-button al-button--ghost" href="' . esc_url( $url ) . '">' . esc_html__( 'Download the rows not imported', 'aluleth-smtp' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Small form that cancels a job.
	 *
	 * @param array  $job   Job.
	 * @param string $label Button label.
	 * @return void
	 */
	private static function cancel_form( array $job, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="al-cancel">';
		wp_nonce_field( 'aluleth_import_cancel' );
		echo '<input type="hidden" name="action" value="aluleth_import_cancel"><input type="hidden" name="job" value="' . esc_attr( $job['id'] ) . '"><button type="submit" class="button-link">' . esc_html( $label ) . '</button></form>';
	}
}
