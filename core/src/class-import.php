<?php
/**
 * Contact import: bring an existing list from another tool.
 *
 * Sources: a CSV / TXT file (any tool's export, Excel included), a ZIP export
 * with several CSV files (Mailchimp), pasted addresses, WordPress users.
 * Files are normalised to UTF-8 once, then imported in small batches so big
 * lists work on any hosting. People who unsubscribed or bounced in the old
 * tool keep that status and go to the suppression list; nobody becomes
 * "subscribed" unless the site owner confirms they gave their consent.
 *
 * @package Aluleth\Core
 */

namespace Aluleth\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Import jobs.
 */
final class Import {

	/**
	 * Rows per batch.
	 */
	const BATCH = 250;

	/**
	 * Option that lists the jobs (id => creation time).
	 */
	const INDEX = 'aluleth_core_import_index';

	/**
	 * Days a finished job (and its skipped-rows file) is kept.
	 */
	const KEEP_DAYS = 7;

	/**
	 * Where a column can go.
	 *
	 * @return array<string, string> Target => label.
	 */
	public static function targets() {
		return array(
			''           => __( 'Do not import', 'aluleth-smtp' ),
			'email'      => __( 'Email', 'aluleth-smtp' ),
			'first_name' => __( 'First name', 'aluleth-smtp' ),
			'last_name'  => __( 'Last name', 'aluleth-smtp' ),
			'full_name'  => __( 'Full name (split in two)', 'aluleth-smtp' ),
			'phone'      => __( 'Phone', 'aluleth-smtp' ),
			'tags'       => __( 'Tags', 'aluleth-smtp' ),
			'status'     => __( 'Status in the old tool', 'aluleth-smtp' ),
			'optout'     => __( 'Unsubscribed (yes / no)', 'aluleth-smtp' ),
			'country'    => __( 'Country', 'aluleth-smtp' ),
			'language'   => __( 'Language', 'aluleth-smtp' ),
			'meta'       => __( 'Custom field', 'aluleth-smtp' ),
		);
	}

	/**
	 * Folder that holds the files being imported (not listed, not guessable).
	 *
	 * @return string Path with a trailing slash, or '' when it cannot be created.
	 */
	public static function dir() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'aluleth-imports/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard files in our own folder.
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:enable
		}
		return $dir;
	}

	/**
	 * Creates an empty job.
	 *
	 * @param string $source 'file', 'paste' or 'users'.
	 * @param string $name   What the user imported (file name…).
	 * @return array
	 */
	private static function new_job( $source, $name ) {
		$id           = strtolower( wp_generate_password( 20, false, false ) );
		$job          = array(
			'id'         => $id,
			'source'     => $source,
			'name'       => substr( sanitize_text_field( $name ), 0, 120 ),
			'state'      => 'mapping',
			'file'       => '',
			'delimiter'  => ',',
			'header'     => array(),
			'has_header' => true,
			'total'      => 0,
			'offset'     => 0,
			'start'      => 0,
			'done'       => 0,
			'map'        => array(),
			'options'    => array(),
			'roles'      => array(),
			'counts'     => array(
				'created'   => 0,
				'updated'   => 0,
				'duplicate' => 0,
				'kept'      => 0,
				'existing'  => 0,
				'skipped'   => 0,
			),
			'skipped'    => '',
			'user'       => get_current_user_id(),
			'created_at' => time(),
			'started_at' => '',
			'ended_at'   => 0,
		);
		$index        = (array) get_option( self::INDEX, array() );
		$index[ $id ] = time();
		update_option( self::INDEX, $index, false );
		return $job;
	}

	/**
	 * Loads a job.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = strtolower( (string) $id );
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $id ) ) {
			return null;
		}
		$job = get_option( 'aluleth_core_import_' . $id );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Saves a job.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function save( array $job ) {
		update_option( 'aluleth_core_import_' . $job['id'], $job, false );
	}

	/**
	 * Deletes a job and its files.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function delete( $id ) {
		$job = self::get( $id );
		if ( $job ) {
			foreach ( array( $job['file'], $job['skipped'] ) as $file ) {
				if ( $file && file_exists( self::dir() . $file ) ) {
					wp_delete_file( self::dir() . $file );
				}
			}
		}
		delete_option( 'aluleth_core_import_' . $id );
		$index = (array) get_option( self::INDEX, array() );
		unset( $index[ $id ] );
		update_option( self::INDEX, $index, false );
	}

	/**
	 * Removes old jobs: unfinished ones after two days, finished ones after a week.
	 *
	 * @return void
	 */
	public static function cleanup() {
		foreach ( (array) get_option( self::INDEX, array() ) as $id => $created ) {
			$job = self::get( $id );
			$age = time() - (int) $created;
			if ( ! $job || ( 'done' !== $job['state'] && $age > 2 * DAY_IN_SECONDS ) || $age > self::KEEP_DAYS * DAY_IN_SECONDS ) {
				self::delete( $id );
			}
		}
	}

	/**
	 * Finished imports, newest first.
	 *
	 * @return array[]
	 */
	public static function history() {
		$out = array();
		foreach ( array_reverse( (array) get_option( self::INDEX, array() ), true ) as $id => $created ) {
			$job = self::get( $id );
			if ( $job && 'done' === $job['state'] ) {
				$out[] = $job;
			}
		}
		return $out;
	}

	/**
	 * Starts a job from an uploaded file (CSV, TXT, TSV or ZIP).
	 *
	 * @param array $file One entry of $_FILES.
	 * @return array|\WP_Error Job.
	 */
	public static function from_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			return new \WP_Error( 'upload', __( 'The file could not be uploaded. It may be bigger than your server allows.', 'aluleth-smtp' ) );
		}
		$name = isset( $file['name'] ) ? (string) $file['name'] : 'import.csv';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv', 'zip' ), true ) ) {
			return new \WP_Error( 'type', __( 'Please choose a CSV, TXT or ZIP file. In Excel or Google Sheets, use "Save as" or "Download" › CSV.', 'aluleth-smtp' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'upload', __( 'The file could not be uploaded.', 'aluleth-smtp' ) );
		}
		$dir = self::dir();
		if ( '' === $dir ) {
			return new \WP_Error( 'dir', __( 'The uploads folder is not writable.', 'aluleth-smtp' ) );
		}

		$job = self::new_job( 'file', $name );
		if ( 'zip' === $ext ) {
			$text = self::zip_to_csv( $file['tmp_name'] );
			if ( is_wp_error( $text ) ) {
				self::delete( $job['id'] );
				return $text;
			}
		} else {
			$text = self::to_utf8( (string) file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temporary file.
		}
		return self::store( $job, $text );
	}

	/**
	 * Starts a job from pasted addresses ("ana@example.com", "Ana Diaz <ana@example.com>"
	 * or "ana@example.com, Ana Diaz", one per line).
	 *
	 * @param string $text Pasted text.
	 * @return array|\WP_Error Job.
	 */
	public static function from_text( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$rows  = array( array( 'Email', 'Name' ) );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(.*)<([^>]+)>\s*$/', $line, $m ) ) {
				$rows[] = array( trim( $m[2] ), sanitize_text_field( trim( trim( $m[1] ), " \t\"'," ) ) );
				continue;
			}
			$parts = preg_split( '/\s*[,;\t]\s*/', $line, 2 );
			if ( isset( $parts[1] ) && ! is_email( trim( $parts[0] ) ) && is_email( trim( $parts[1] ) ) ) {
				$parts = array( $parts[1], $parts[0] );
			}
			$rows[] = array( trim( $parts[0] ), isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '' );
		}
		if ( count( $rows ) < 2 ) {
			return new \WP_Error( 'empty', __( 'Paste at least one email address.', 'aluleth-smtp' ) );
		}
		$job = self::new_job( 'paste', __( 'Pasted addresses', 'aluleth-smtp' ) );
		return self::store( $job, self::rows_to_csv( $rows ) );
	}

	/**
	 * Starts a job that imports WordPress users.
	 *
	 * @param string[] $roles Roles to import (empty = every role).
	 * @return array Job.
	 */
	public static function from_users( array $roles ) {
		$valid = array_keys( wp_roles()->get_names() );
		$roles = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
		$job   = self::new_job( 'users', __( 'WordPress users', 'aluleth-smtp' ) );

		$job['roles'] = $roles;
		$query        = new \WP_User_Query(
			array(
				'role__in'    => $roles,
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);
		$job['total'] = (int) $query->get_total();
		self::save( $job );
		return $job;
	}

	/**
	 * Saves the UTF-8 text of a list as the job's file and reads its layout.
	 *
	 * @param array  $job  Job.
	 * @param string $text CSV text in UTF-8.
	 * @return array|\WP_Error Job.
	 */
	private static function store( array $job, $text ) {
		$text = preg_replace( '/\r\n?/', "\n", (string) $text );
		if ( '' === trim( $text ) ) {
			self::delete( $job['id'] );
			return new \WP_Error( 'empty', __( 'This file is empty.', 'aluleth-smtp' ) );
		}
		$job['delimiter'] = self::sniff( $text );
		$job['file']      = $job['id'] . '.csv';
		file_put_contents( self::dir() . $job['file'], $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our protected import folder.

		$first = self::read( $job, 0, 2 );
		if ( ! $first['rows'] ) {
			self::delete( $job['id'] );
			return new \WP_Error( 'empty', __( 'This file is empty.', 'aluleth-smtp' ) );
		}
		$row0              = $first['rows'][0];
		$job['has_header'] = ! self::has_email( $row0 );
		if ( $job['has_header'] ) {
			$job['header'] = array_map( array( __CLASS__, 'clean_cell' ), $row0 );
			$job['offset'] = $first['offsets'][0];
		} else {
			$job['header'] = array();
			foreach ( $row0 as $i => $unused ) {
				/* translators: %d: column number. */
				$job['header'][] = sprintf( __( 'Column %d', 'aluleth-smtp' ), $i + 1 );
			}
			$job['offset'] = 0;
		}

		// Count the rows once, for the progress bar.
		$count  = 0;
		$offset = $job['offset'];
		do {
			$chunk  = self::read( $job, $offset, 2000 );
			$count += count( $chunk['rows'] );
			$offset = $chunk['next'];
		} while ( $chunk['rows'] && ! $chunk['eof'] );
		$job['total'] = $count;
		$job['start'] = $job['offset'];
		$job['map']   = self::guess( $job );
		self::save( $job );
		return $job;
	}

	/**
	 * Reads rows from the job's file.
	 *
	 * @param array $job    Job.
	 * @param int   $offset Byte offset.
	 * @param int   $limit  Maximum rows.
	 * @return array{rows: array[], offsets: int[], next: int, eof: bool}
	 */
	public static function read( array $job, $offset, $limit ) {
		$out  = array(
			'rows'    => array(),
			'offsets' => array(),
			'next'    => (int) $offset,
			'eof'     => true,
		);
		$path = self::dir() . $job['file'];
		if ( ! $job['file'] || ! file_exists( $path ) ) {
			return $out;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a big CSV needs a file handle.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return $out;
		}
		fseek( $handle, (int) $offset );
		$found = 0;
		while ( $found < $limit ) {
			$cells = fgetcsv( $handle, 0, $job['delimiter'], '"', '' );
			if ( false === $cells ) {
				break;
			}
			if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
				continue; // Empty line, or a row of empty cells.
			}
			$out['rows'][]    = $cells;
			$out['offsets'][] = ftell( $handle );
			++$found;
		}
		$out['next'] = ftell( $handle );
		$out['eof']  = feof( $handle );
		fclose( $handle );
		// phpcs:enable
		return $out;
	}

	/**
	 * First rows for the matching screen.
	 *
	 * @param array $job Job.
	 * @return array[]
	 */
	public static function preview( array $job ) {
		$chunk = self::read( $job, $job['start'], 4 );
		return $chunk['rows'];
	}

	/**
	 * Guesses where each column goes, from its title and its values.
	 *
	 * @param array $job Job.
	 * @return array<int, array{to: string, key: string}>
	 */
	public static function guess( array $job ) {
		$names  = array(
			'email'      => array( 'email', 'e mail', 'email address', 'e mail address', 'emailaddress', 'adresse email', 'adresse e mail', 'adresse mail', 'courriel', 'mail', 'correo', 'correo electronico', 'subscriber', 'subscriber email', 'contact email' ),
			'first_name' => array( 'first name', 'firstname', 'fname', 'prenom', 'given name', 'nombre', 'first' ),
			'last_name'  => array( 'last name', 'lastname', 'lname', 'nom', 'surname', 'family name', 'apellido', 'apellidos', 'nom de famille', 'last' ),
			'full_name'  => array( 'name', 'full name', 'fullname', 'nom complet', 'display name', 'nombre completo', 'contact name', 'contact' ),
			'phone'      => array( 'phone', 'phone number', 'telephone', 'tel', 'mobile', 'mobile phone', 'sms', 'cell', 'telefono', 'portable', 'whatsapp' ),
			'tags'       => array( 'tags', 'tag', 'etiquettes', 'etiquette', 'labels', 'groups', 'etiquetas' ),
			'status'     => array( 'status', 'member status', 'subscription status', 'statut', 'estado', 'email status', 'subscriber status', 'status from file' ),
			'optout'     => array( 'blacklisted', 'blocklisted', 'email blacklisted', 'email blocklisted', 'unsubscribed', 'opted out', 'opt out', 'email opt out', 'do not email', 'desabonne', 'desinscrit' ),
			'country'    => array( 'country', 'country code', 'pays', 'pais' ),
			'language'   => array( 'language', 'langue', 'idioma', 'locale', 'lang' ),
		);
		$custom = array(
			'company' => array( 'company', 'societe', 'entreprise', 'empresa', 'organization', 'organisation' ),
			'city'    => array( 'city', 'ville', 'ciudad' ),
		);
		$sample = self::preview( $job );
		$map    = array();
		$used   = array();
		foreach ( $job['header'] as $i => $title ) {
			$norm = self::norm( $title );
			$to   = '';
			$key  = '';
			foreach ( $names as $target => $list ) {
				if ( in_array( $norm, $list, true ) && ! isset( $used[ $target ] ) ) {
					$to = $target;
					break;
				}
			}
			if ( '' === $to ) {
				foreach ( $custom as $field => $list ) {
					if ( in_array( $norm, $list, true ) ) {
						$to  = 'meta';
						$key = $field;
						break;
					}
				}
			}
			if ( '' === $to && ! isset( $used['email'] ) ) {
				// No title: a column full of addresses is the email.
				$emails = 0;
				foreach ( $sample as $row ) {
					if ( isset( $row[ $i ] ) && is_email( trim( (string) $row[ $i ] ) ) ) {
						++$emails;
					}
				}
				if ( $sample && $emails * 2 >= count( $sample ) ) {
					$to = 'email';
				}
			}
			if ( '' !== $to && 'meta' !== $to ) {
				$used[ $to ] = true;
			}
			$map[ $i ] = array(
				'to'  => $to,
				'key' => $key ? $key : self::meta_key( $title ),
			);
		}
		return $map;
	}

	/**
	 * Saves the column matching and options, and readies the job.
	 *
	 * @param array $job     Job.
	 * @param array $map     Column index => target.
	 * @param array $keys    Column index => custom field key.
	 * @param array $options 'status' (subscribed|transactional|pending), 'consent' (bool),
	 *                       'existing' (update|fill|skip), 'tags' (string, comma separated).
	 * @return array|\WP_Error Job.
	 */
	public static function configure( array $job, array $map, array $keys, array $options ) {
		$targets = array_keys( self::targets() );
		if ( 'users' !== $job['source'] ) {
			$clean = array();
			$seen  = array();
			foreach ( $job['header'] as $i => $unused ) {
				$to = isset( $map[ $i ] ) && in_array( $map[ $i ], $targets, true ) ? $map[ $i ] : '';
				if ( '' !== $to && 'meta' !== $to && isset( $seen[ $to ] ) ) {
					$to = ''; // Each standard target once.
				}
				$seen[ $to ] = true;
				$key         = isset( $keys[ $i ] ) ? self::meta_key( $keys[ $i ] ) : '';
				if ( 'meta' === $to && '' === $key ) {
					$key = self::meta_key( $job['header'][ $i ] );
				}
				$clean[ $i ] = array(
					'to'  => $to,
					'key' => $key,
				);
			}
			$job['map'] = $clean;
			self::save( $job ); // Keep the choices if something below must be corrected.
			if ( ! isset( $seen['email'] ) ) {
				return new \WP_Error( 'email', __( 'Choose which column holds the email addresses.', 'aluleth-smtp' ) );
			}
		}

		$status  = isset( $options['status'] ) && in_array( $options['status'], array( 'subscribed', 'transactional', 'pending' ), true ) ? $options['status'] : 'transactional';
		$consent = ! empty( $options['consent'] );
		if ( 'subscribed' === $status && ! $consent ) {
			return new \WP_Error( 'consent', __( 'To import people as subscribed, confirm that they agreed to receive your emails.', 'aluleth-smtp' ) );
		}
		$tags = array();
		foreach ( preg_split( '/[,;]/', isset( $options['tags'] ) ? (string) $options['tags'] : '' ) as $tag ) {
			$tag = trim( sanitize_text_field( $tag ) );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}
		$job['options']    = array(
			'status'   => $status,
			'consent'  => $consent,
			'existing' => isset( $options['existing'] ) && in_array( $options['existing'], array( 'update', 'fill', 'skip' ), true ) ? $options['existing'] : 'fill',
			'tags'     => array_slice( array_unique( $tags ), 0, 20 ),
		);
		$job['state']      = 'ready';
		$job['offset']     = 'users' === $job['source'] ? 0 : $job['start'];
		$job['done']       = 0;
		$job['started_at'] = Util::now();
		global $wpdb;
		$job['max_id'] = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}aluleth_contacts" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::save( $job );
		return $job;
	}

	/**
	 * Imports the next batch.
	 *
	 * @param array $job Job.
	 * @return array Job.
	 */
	public static function run( array $job ) {
		if ( ! in_array( $job['state'], array( 'ready', 'running' ), true ) ) {
			return $job;
		}
		$job['state'] = 'running';
		$started      = microtime( true );

		if ( 'users' === $job['source'] ) {
			$users = get_users(
				array(
					'role__in' => $job['roles'],
					'number'   => self::BATCH,
					'offset'   => (int) $job['offset'],
					'orderby'  => 'ID',
					'order'    => 'ASC',
				)
			);
			foreach ( $users as $user ) {
				$first = (string) get_user_meta( $user->ID, 'first_name', true );
				$last  = (string) get_user_meta( $user->ID, 'last_name', true );
				self::import_one(
					$job,
					array(
						'email'      => $user->user_email,
						'first_name' => $first,
						'last_name'  => $last,
						'full_name'  => '' === $first . $last ? $user->display_name : '',
					),
					array()
				);
			}
			$job['offset'] += count( $users );
			$job['done']   += count( $users );
			$finished       = count( $users ) < self::BATCH;
		} else {
			$finished = false;
			$offset   = (int) $job['offset'];
			$count    = 0;
			// One batch per request, shorter when the server is slow.
			while ( $count < self::BATCH && microtime( true ) - $started < 15 ) {
				$chunk = self::read( $job, $offset, 50 );
				foreach ( $chunk['rows'] as $cells ) {
					self::import_one( $job, self::values( $job, $cells ), $cells );
					++$job['done'];
					++$count;
				}
				$offset = $chunk['next'];
				if ( ! $chunk['rows'] || $chunk['eof'] ) {
					$finished = true;
					break;
				}
			}
			$job['offset'] = $offset;
		}

		if ( $finished ) {
			$job['state']    = 'done';
			$job['ended_at'] = time();
			$job['done']     = max( $job['done'], $job['total'] );
			if ( $job['file'] && file_exists( self::dir() . $job['file'] ) ) {
				wp_delete_file( self::dir() . $job['file'] );
			}
			$job['file'] = '';

			/**
			 * Fires when an import is finished.
			 *
			 * @param array $job Job with its counts.
			 */
			do_action( 'aluleth/contacts/imported', $job );
		}
		self::save( $job );
		return $job;
	}

	/**
	 * Values of one row, by target.
	 *
	 * @param array $job   Job.
	 * @param array $cells Row.
	 * @return array
	 */
	private static function values( array $job, array $cells ) {
		$out = array(
			'tags' => array(),
			'meta' => array(),
		);
		foreach ( $job['map'] as $i => $target ) {
			$value = isset( $cells[ $i ] ) ? self::clean_cell( $cells[ $i ] ) : '';
			if ( '' === $value || '' === $target['to'] ) {
				continue;
			}
			if ( 'tags' === $target['to'] ) {
				foreach ( preg_split( '/\s*[,;|]\s*/', $value ) as $tag ) {
					$tag = trim( $tag, " \t\"'" );
					if ( '' !== $tag ) {
						$out['tags'][] = $tag;
					}
				}
			} elseif ( 'meta' === $target['to'] ) {
				if ( '' !== $target['key'] ) {
					$out['meta'][ $target['key'] ] = $value;
				}
			} elseif ( 'optout' === $target['to'] ) {
				if ( in_array( self::norm( $value ), array( 'yes', 'oui', 'si', 'true', '1', 'y', 'x' ), true ) ) {
					$out['status'] = 'unsubscribed';
				}
			} elseif ( ! isset( $out[ $target['to'] ] ) ) {
				$out[ $target['to'] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Imports one person.
	 *
	 * @param array $job    Job (counts are updated).
	 * @param array $values Values by target.
	 * @param array $cells  Original row, written to the skipped-rows file when needed.
	 * @return void
	 */
	private static function import_one( array &$job, array $values, array $cells ) {
		$core  = aluleth_core();
		$raw   = isset( $values['email'] ) ? (string) $values['email'] : '';
		$email = Util::email( $raw );
		if ( '' === $email ) {
			$empty = '' === trim( $raw );
			self::skip( $job, $cells, $empty ? __( 'No email address', 'aluleth-smtp' ) : __( 'Not a valid email address', 'aluleth-smtp' ) );
			return;
		}

		$opts   = $job['options'];
		$status = isset( $values['status'] ) ? self::status_from( $values['status'] ) : '';
		if ( '' === $status ) {
			$status = $opts['status'];
		}
		if ( 'subscribed' === $status && empty( $opts['consent'] ) ) {
			$status = 'transactional'; // No consent confirmed: never a marketing subscriber.
		}

		$existing  = $core->contacts()->get_by_email( $email );
		$duplicate = $existing && isset( $job['max_id'] ) && (int) $existing->id > (int) $job['max_id']; // Created earlier in this same import.
		if ( $existing && 'skip' === $opts['existing'] ) {
			++$job['counts'][ $duplicate ? 'duplicate' : 'existing' ];
			return;
		}

		$data = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'country', 'language' ) as $column ) {
			if ( ! empty( $values[ $column ] ) ) {
				$data[ $column ] = $values[ $column ];
			}
		}
		if ( ! empty( $values['full_name'] ) && empty( $data['first_name'] ) && empty( $data['last_name'] ) ) {
			$parts              = preg_split( '/\s+/', trim( $values['full_name'] ), 2 );
			$data['first_name'] = $parts[0];
			$data['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
		}
		if ( isset( $data['country'] ) ) {
			$data['country'] = substr( $data['country'], 0, 100 );
		}
		if ( ! empty( $values['meta'] ) ) {
			$data['meta'] = $values['meta'];
		}

		$context = array(
			'source'    => 'import',
			'fill_only' => 'fill' === $opts['existing'],
		);
		$kept    = false;
		$current = $existing ? $existing->status : '';
		if ( ! $existing ) {
			$context['status'] = $status;
		} elseif ( in_array( $status, array( 'unsubscribed', 'bounced', 'complained' ), true ) ) {
			$context['status'] = $status; // The old tool knows they left: respect it.
		} elseif ( 'subscribed' === $status ) {
			if ( in_array( $current, array( 'unsubscribed', 'bounced', 'complained' ), true ) ) {
				$kept = true; // Never re-subscribe someone who left, an import is not a new consent.
			} elseif ( 'subscribed' !== $current ) {
				$context['status'] = 'subscribed';
			}
		} elseif ( 'pending' === $status && 'transactional' === $current ) {
			$context['status'] = 'pending';
		}

		$id = $core->contacts()->upsert( $email, $data, $context );
		if ( ! $id ) {
			self::skip( $job, $cells, __( 'Could not be saved', 'aluleth-smtp' ) );
			return;
		}

		$tags = array_merge( $opts['tags'], isset( $values['tags'] ) ? $values['tags'] : array() );
		if ( $tags ) {
			$core->tags()->add( $id, array_slice( array_unique( $tags ), 0, 30 ) );
		}
		if ( isset( $context['status'] ) && 'subscribed' === $context['status'] && 'subscribed' !== $current ) {
			$core->consents()->record( $id, 'email_marketing', true, 'import', self::consent_text() );
		}
		if ( in_array( $status, array( 'unsubscribed', 'bounced', 'complained' ), true ) ) {
			$core->mail()->suppress( $email, $status, 'import' );
		}

		if ( $kept ) {
			++$job['counts']['kept'];
		}
		if ( $duplicate ) {
			++$job['counts']['duplicate'];
		} elseif ( $existing ) {
			++$job['counts']['updated'];
		} else {
			++$job['counts']['created'];
		}
	}

	/**
	 * The statement the site owner confirms before importing subscribers.
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'These people agreed to receive my marketing emails (for example they subscribed to my newsletter) and I can prove it.', 'aluleth-smtp' );
	}

	/**
	 * Writes a row to the skipped-rows file.
	 *
	 * @param array  $job    Job.
	 * @param array  $cells  Row.
	 * @param string $reason Why.
	 * @return void
	 */
	private static function skip( array &$job, array $cells, $reason ) {
		++$job['counts']['skipped'];
		if ( 'users' === $job['source'] ) {
			return;
		}
		$new = '' === $job['skipped'];
		if ( $new ) {
			$job['skipped'] = $job['id'] . '-skipped.csv';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- appending rows one by one.
		$handle = fopen( self::dir() . $job['skipped'], 'a' );
		if ( ! $handle ) {
			return;
		}
		if ( $new ) {
			fputcsv( $handle, array_merge( array( __( 'Reason', 'aluleth-smtp' ) ), $job['header'] ), ',', '"', '' );
		}
		fputcsv( $handle, array_merge( array( $reason ), array_map( array( __CLASS__, 'safe_cell' ), $cells ) ), ',', '"', '' );
		fclose( $handle );
		// phpcs:enable
	}

	/**
	 * Understands the status words of other tools.
	 *
	 * @param string $value Raw status.
	 * @return string One of our statuses, or '' when unknown.
	 */
	public static function status_from( $value ) {
		$value = self::norm( $value );
		$words = array(
			'subscribed'    => array( 'subscribed', 'subscriber', 'active', 'actif', 'active subscriber', 'abonne', 'inscrit', 'opt in', 'optin', 'opted in', 'confirmed', 'double opt in', 'yes', 'oui', 'si', 'true', '1', 'suscrito', 'activo' ),
			'unsubscribed'  => array( 'unsubscribed', 'unsubscribe', 'desabonne', 'desinscrit', 'opted out', 'opt out', 'blocklisted', 'blacklisted', 'blocked', 'inactive', 'no', 'non', 'false', '0', 'cancelled', 'canceled', 'dado de baja', 'baja' ),
			'bounced'       => array( 'bounced', 'bounce', 'hard bounce', 'hard bounced', 'cleaned', 'invalid', 'undeliverable', 'rebote' ),
			'complained'    => array( 'complained', 'complaint', 'spam', 'marked as spam', 'spam complaint', 'junk' ),
			'pending'       => array( 'pending', 'unconfirmed', 'not confirmed', 'non confirme', 'en attente', 'pendiente' ),
			'transactional' => array( 'transactional', 'non subscribed', 'nonsubscribed', 'not subscribed', 'non abonne', 'customer', 'lead' ),
		);
		foreach ( $words as $status => $list ) {
			if ( in_array( $value, $list, true ) ) {
				return $status;
			}
		}
		return '';
	}

	/**
	 * Turns a ZIP export (Mailchimp gives one CSV per status) into one CSV.
	 * A "Status from file" column is added from each file's name.
	 *
	 * @param string $path ZIP file.
	 * @return string|\WP_Error CSV text.
	 */
	private static function zip_to_csv( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'zip', __( 'Your server cannot open ZIP files. Unzip the file on your computer and import the CSV files one by one.', 'aluleth-smtp' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'zip', __( 'This ZIP file could not be opened.', 'aluleth-smtp' ) );
		}
		$tables = array();
		$header = array();
		$files  = min( 20, (int) $zip->numFiles ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
		for ( $i = 0; $i < $files; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '__MACOSX' ) || ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
				continue;
			}
			$stat = $zip->statIndex( $i );
			if ( ! $stat || (int) $stat['size'] > 30 * MB_IN_BYTES ) {
				// Do not unpack huge files in memory (a "ZIP bomb").
				continue;
			}
			$text = self::to_utf8( (string) $zip->getFromIndex( $i ) );
			$text = preg_replace( '/\r\n?/', "\n", $text );
			$rows = self::parse( $text, self::sniff( $text ) );
			if ( count( $rows ) < 2 ) {
				continue;
			}
			$cols = array_map( array( __CLASS__, 'clean_cell' ), array_shift( $rows ) );
			foreach ( $cols as $col ) {
				if ( ! in_array( $col, $header, true ) ) {
					$header[] = $col;
				}
			}
			$base     = strtolower( basename( $name ) );
			$status   = '';
			$patterns = array(
				'transactional' => '/non_?subscribed/',
				'unsubscribed'  => '/unsubscribed/',
				'bounced'       => '/cleaned|bounced/',
				'subscribed'    => '/subscribed|members/',
			);
			foreach ( $patterns as $candidate => $pattern ) {
				if ( preg_match( $pattern, $base ) ) {
					$status = $candidate;
					break;
				}
			}
			$tables[] = array( $cols, $rows, $status );
		}
		$zip->close();
		if ( ! $tables ) {
			return new \WP_Error( 'zip', __( 'No CSV file was found in this ZIP file.', 'aluleth-smtp' ) );
		}
		$out = array( array_merge( $header, array( 'Status from file' ) ) );
		foreach ( $tables as $table ) {
			list( $cols, $rows, $status ) = $table;
			$positions                    = array();
			foreach ( $header as $col ) {
				$positions[] = array_search( $col, $cols, true );
			}
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $positions as $pos ) {
					$line[] = false === $pos || ! isset( $row[ $pos ] ) ? '' : $row[ $pos ];
				}
				$line[] = $status;
				$out[]  = $line;
			}
		}
		return self::rows_to_csv( $out );
	}

	/**
	 * Parses CSV text in memory.
	 *
	 * @param string $text      Text.
	 * @param string $delimiter Delimiter.
	 * @return array[]
	 */
	private static function parse( $text, $delimiter ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $text );
		rewind( $handle );
		$rows = array();
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) !== $cells ) {
				$rows[] = $cells;
			}
		}
		fclose( $handle );
		// phpcs:enable
		return $rows;
	}

	/**
	 * Writes rows as CSV text.
	 *
	 * @param array[] $rows Rows.
	 * @return string
	 */
	private static function rows_to_csv( array $rows ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
		$handle = fopen( 'php://temp', 'r+' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '' );
		}
		rewind( $handle );
		$text = stream_get_contents( $handle );
		fclose( $handle );
		// phpcs:enable
		return (string) $text;
	}

	/**
	 * Converts text to UTF-8 (Excel saves UTF-16 or Windows-1252 files).
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	public static function to_utf8( $raw ) {
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			return substr( $raw, 3 );
		}
		if ( 0 === strpos( $raw, "\xFF\xFE" ) ) {
			return self::convert( substr( $raw, 2 ), 'UTF-16LE' );
		}
		if ( 0 === strpos( $raw, "\xFE\xFF" ) ) {
			return self::convert( substr( $raw, 2 ), 'UTF-16BE' );
		}
		$valid = function_exists( 'wp_is_valid_utf8' ) ? wp_is_valid_utf8( $raw ) : ( function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $raw, 'UTF-8' ) : true );
		if ( $valid ) {
			return $raw;
		}
		return self::convert( $raw, 'Windows-1252' );
	}

	/**
	 * Converts a string to UTF-8 with whatever the server offers.
	 *
	 * @param string $text Text.
	 * @param string $from Source encoding.
	 * @return string
	 */
	private static function convert( $text, $from ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', $from );
		}
		if ( function_exists( 'iconv' ) ) {
			$out = iconv( $from, 'UTF-8//IGNORE', $text );
			return false === $out ? $text : $out;
		}
		return $text;
	}

	/**
	 * Finds the delimiter (comma, semicolon as in French Excel, tab or pipe).
	 *
	 * @param string $text CSV text.
	 * @return string
	 */
	private static function sniff( $text ) {
		$lines = array_slice( array_filter( explode( "\n", substr( $text, 0, 20000 ) ), 'strlen' ), 0, 6 );
		$best  = ',';
		$score = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $delimiter ) {
			$counts = array();
			foreach ( $lines as $line ) {
				$counts[] = substr_count( preg_replace( '/"[^"]*"/', '', $line ), $delimiter );
			}
			$min = $counts ? min( $counts ) : 0;
			if ( $min > $score ) {
				$score = $min;
				$best  = $delimiter;
			}
		}
		return $best;
	}

	/**
	 * Whether a row contains an email address (then it is data, not titles).
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function has_email( array $row ) {
		foreach ( $row as $cell ) {
			if ( is_email( trim( (string) $cell ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Trims a cell and removes invisible characters.
	 *
	 * @param mixed $cell Cell.
	 * @return string
	 */
	public static function clean_cell( $cell ) {
		$cell = (string) $cell;
		$cell = str_replace( array( "\xEF\xBB\xBF", "\xC2\xA0" ), array( '', ' ' ), $cell );
		return trim( $cell );
	}

	/**
	 * A cell written back to a CSV that may be opened in a spreadsheet:
	 * values starting with = + - @ are prefixed so they are not run as formulas.
	 *
	 * @param mixed $cell Cell.
	 * @return string
	 */
	public static function safe_cell( $cell ) {
		$cell = (string) $cell;
		return preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
	}

	/**
	 * Lowercase words without accents, for matching titles and statuses.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function norm( $text ) {
		$text = strtolower( remove_accents( self::clean_cell( $text ) ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	/**
	 * Custom field key from a column title.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function meta_key( $title ) {
		return substr( str_replace( '-', '_', sanitize_title( self::norm( $title ) ) ), 0, 60 );
	}
}
