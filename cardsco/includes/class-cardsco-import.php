<?php
/**
 * Import pipeline.
 *
 * Four steps, matching the designs: upload, match columns, check what we
 * found, set their occasions. Nothing is written to the recipients table
 * until the last step, so the review is a genuine dry run of the same code
 * that does the work.
 *
 * Committing happens in chunks with a redirect between them, so a file of ten
 * thousand people cannot hit the PHP time limit halfway through and leave the
 * list half imported.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Import {

	/**
	 * Rows written per request before redirecting to continue.
	 */
	const CHUNK = 200;

	/**
	 * Errors kept in the report. Beyond this only the count is kept.
	 */
	const MAX_REPORTED_ERRORS = 100;

	/**
	 * Fields a column can be mapped to, with the header names that are
	 * recognised automatically.
	 *
	 * @return array<string, array{label:string, aliases:string[]}>
	 */
	public static function fields() {
		return array(
			'first_name'         => array(
				'label'   => __( 'First name', 'cardsco' ),
				'aliases' => array( 'first name', 'firstname', 'first', 'forename', 'given name', 'first_name' ),
			),
			'last_name'          => array(
				'label'   => __( 'Last name', 'cardsco' ),
				'aliases' => array( 'last name', 'lastname', 'last', 'surname', 'family name', 'last_name' ),
			),
			'full_name'          => array(
				'label'   => __( 'Full name (split automatically)', 'cardsco' ),
				'aliases' => array( 'name', 'full name', 'fullname', 'contact name', 'customer name', 'employee name', 'recipient' ),
			),
			'email'              => array(
				'label'   => __( 'Email', 'cardsco' ),
				'aliases' => array( 'email', 'e-mail', 'email address', 'e-mail address', 'mail' ),
			),
			'phone'              => array(
				'label'   => __( 'Phone', 'cardsco' ),
				'aliases' => array( 'phone', 'telephone', 'mobile', 'phone number', 'contact number', 'tel' ),
			),
			'recipient_company'  => array(
				'label'   => __( 'Their company', 'cardsco' ),
				'aliases' => array( 'company', 'company name', 'organisation', 'organization', 'business', 'account', 'account name', 'employer' ),
			),
			'job_title'          => array(
				'label'   => __( 'Job title', 'cardsco' ),
				'aliases' => array( 'job title', 'jobtitle', 'role', 'position', 'job role' ),
			),
			'address_1'          => array(
				'label'   => __( 'Address line 1', 'cardsco' ),
				'aliases' => array( 'address', 'address 1', 'address line 1', 'address1', 'street', 'street address', 'postal address', 'shipping address', 'line 1' ),
			),
			'address_2'          => array(
				'label'   => __( 'Address line 2', 'cardsco' ),
				'aliases' => array( 'address 2', 'address line 2', 'address2', 'line 2' ),
			),
			'city'               => array(
				'label'   => __( 'Town or city', 'cardsco' ),
				'aliases' => array( 'city', 'town', 'town/city', 'post town' ),
			),
			'county'             => array(
				'label'   => __( 'County', 'cardsco' ),
				'aliases' => array( 'county', 'state', 'region', 'province' ),
			),
			'postcode'           => array(
				'label'   => __( 'Postcode', 'cardsco' ),
				'aliases' => array( 'postcode', 'post code', 'zip', 'zip code', 'postal code', 'postalcode' ),
			),
			'country'            => array(
				'label'   => __( 'Country', 'cardsco' ),
				'aliases' => array( 'country', 'country code' ),
			),
			'relationship_since' => array(
				'label'   => __( 'Customer since', 'cardsco' ),
				'aliases' => array( 'customer since', 'client since', 'member since', 'since', 'joined', 'date joined' ),
			),
			'last_order_date'    => array(
				'label'   => __( 'Last order date', 'cardsco' ),
				'aliases' => array( 'last order', 'last order date', 'last purchase', 'last purchase date', 'last ordered' ),
			),
			'segments'           => array(
				'label'   => __( 'Segments (separated by commas)', 'cardsco' ),
				'aliases' => array( 'segment', 'segments', 'group', 'groups', 'tag', 'tags', 'list' ),
			),
			'notes'              => array(
				'label'   => __( 'Notes', 'cardsco' ),
				'aliases' => array( 'notes', 'note', 'comments', 'comment' ),
			),
		);
	}

	/**
	 * Header names that suggest a particular occasion.
	 *
	 * @return array<string, string[]> Occasion slug mapped to header aliases.
	 */
	public static function occasion_aliases() {
		return array(
			'birthday'             => array( 'birthday', 'birth date', 'birthdate', 'dob', 'date of birth', 'b-day', 'bday' ),
			'work-anniversary'     => array( 'work anniversary', 'start date', 'hire date', 'date hired', 'started', 'employment start', 'anniversary' ),
			'customer-anniversary' => array( 'customer anniversary', 'client anniversary', 'customer since', 'client since' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Batch lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * Accept an uploaded file and open a batch.
	 *
	 * @param int    $company_id Company ID.
	 * @param array  $file A single entry from $_FILES.
	 * @param string $recipient_type customer | employee.
	 * @return int|WP_Error Batch ID.
	 */
	public static function create_batch( $company_id, array $file, $recipient_type = 'customer' ) {
		global $wpdb;

		$company_id = absint( $company_id );

		if ( ! $company_id || ! CardsCo_Company::get( $company_id ) ) {
			return new WP_Error( 'cardsco_import_company', __( 'Pick a company first.', 'cardsco' ) );
		}

		// Normalise before anything reads it, so the occasions offered during
		// auto-mapping are the ones the batch is actually stored with.
		$recipient_type = in_array( $recipient_type, array( 'customer', 'employee' ), true ) ? $recipient_type : 'customer';

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'cardsco_import_upload', __( 'No file was uploaded.', 'cardsco' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'cardsco_import_error', self::upload_error_message( (int) $file['error'] ) );
		}

		$name      = sanitize_file_name( $file['name'] );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'csv', 'txt', 'tsv' ), true ) ) {
			return new WP_Error(
				'cardsco_import_type',
				__( 'Only CSV files can be imported. In Excel or Google Sheets choose File, then Download or Save As, and pick CSV.', 'cardsco' )
			);
		}

		$destination = self::storage_dir();

		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		$filename = wp_generate_password( 20, false, false ) . '.csv';
		$path     = trailingslashit( $destination ) . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new WP_Error( 'cardsco_import_move', __( 'The file could not be saved to the server.', 'cardsco' ) );
		}

		// 0600 so the file is readable by the web user and nobody else.
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$csv    = new CardsCo_CSV( $path );
		$opened = $csv->open();

		if ( is_wp_error( $opened ) ) {
			wp_delete_file( $path );
			return $opened;
		}

		$total = $csv->count_records();
		$max   = (int) CardsCo_Settings::get( 'import_max_rows', null, $company_id );

		if ( $max && $total > $max ) {
			wp_delete_file( $path );

			return new WP_Error(
				'cardsco_import_size',
				sprintf(
					/* translators: 1: rows in the file, 2: maximum allowed. */
					__( 'That file has %1$s rows and the limit is %2$s. Split it and import in parts.', 'cardsco' ),
					number_format_i18n( $total ),
					number_format_i18n( $max )
				)
			);
		}

		if ( ! $total ) {
			wp_delete_file( $path );
			return new WP_Error( 'cardsco_import_norows', __( 'That file has column names but no people in it.', 'cardsco' ) );
		}

		$inserted = $wpdb->insert(
			cardsco_table( 'import_batches' ),
			array(
				'company_id'     => $company_id,
				'user_id'        => get_current_user_id(),
				'filename'       => $name,
				'file_path'      => $filename,
				'recipient_type' => $recipient_type,
				'total_rows'     => $total,
				'column_map'     => wp_json_encode( self::auto_map( $csv->headers(), $company_id, $recipient_type, $csv ) ),
				'status'         => 'uploaded',
				'created_at'     => cardsco_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_delete_file( $path );
			return new WP_Error( 'cardsco_import_insert', __( 'The import could not be started.', 'cardsco' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a batch.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array|null
	 */
	public static function get( $batch_id ) {
		global $wpdb;

		$batch_id = absint( $batch_id );

		if ( ! $batch_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'import_batches' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$batch_id
			),
			ARRAY_A
		);

		if ( $row ) {
			$row['column_map'] = json_decode( (string) $row['column_map'], true ) ?: array();
			$row['report']     = json_decode( (string) $row['report'], true ) ?: array();
		}

		return $row;
	}

	/**
	 * Recent batches for a company.
	 *
	 * @param int $company_id Company ID.
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public static function recent( $company_id, $limit = 10 ) {
		global $wpdb;

		$table = cardsco_table( 'import_batches' );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE company_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				absint( $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * Update batch columns.
	 *
	 * @param int   $batch_id Batch ID.
	 * @param array $fields Columns to set.
	 * @return void
	 */
	public static function update_batch( $batch_id, array $fields ) {
		global $wpdb;

		$formats = array();

		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, array( 'column_map', 'report' ), true ) && is_array( $value ) ) {
				$fields[ $key ] = wp_json_encode( $value );
			}

			$formats[] = in_array(
				$key,
				array( 'total_rows', 'created_count', 'updated_count', 'skipped_count', 'error_count', 'company_id', 'user_id' ),
				true
			) ? '%d' : '%s';
		}

		$wpdb->update( cardsco_table( 'import_batches' ), $fields, array( 'id' => absint( $batch_id ) ), $formats, array( '%d' ) );
	}

	/**
	 * A reader for a batch's file.
	 *
	 * @param array $batch Batch row.
	 * @return CardsCo_CSV|WP_Error
	 */
	public static function csv( array $batch ) {
		$dir = self::storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$csv    = new CardsCo_CSV( trailingslashit( $dir ) . $batch['file_path'] );
		$opened = $csv->open();

		return is_wp_error( $opened ) ? $opened : $csv;
	}

	/**
	 * Remove a batch's uploaded file.
	 *
	 * The file holds names and addresses, so it does not stay on the server
	 * once the rows are in the database.
	 *
	 * @param array $batch Batch row.
	 * @return void
	 */
	public static function delete_file( array $batch ) {
		$dir = self::storage_dir();

		if ( is_wp_error( $dir ) || empty( $batch['file_path'] ) ) {
			return;
		}

		$path = trailingslashit( $dir ) . $batch['file_path'];

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/* ---------------------------------------------------------------------
	 * Mapping
	 * ------------------------------------------------------------------ */

	/**
	 * Guess which column is which.
	 *
	 * @param string[]        $headers Column names.
	 * @param int             $company_id Company ID.
	 * @param string          $recipient_type customer | employee.
	 * @param CardsCo_CSV|null $csv Reader, used to sample dates for order detection.
	 * @return array
	 */
	public static function auto_map( array $headers, $company_id, $recipient_type = 'customer', $csv = null ) {
		$fields    = self::fields();
		$map       = array();
		$occasions = array();
		$taken     = array();

		$types    = CardsCo_Occasion_Type::all( $company_id, array( 'mode' => 'dated', 'applies_to' => $recipient_type ) );
		$by_slug  = array();

		foreach ( $types as $type ) {
			$by_slug[ $type['slug'] ] = (int) $type['id'];
		}

		foreach ( $headers as $header ) {
			$normal = self::normalise( $header );

			if ( '' === $normal ) {
				continue;
			}

			// Occasion columns are checked first: a header called "Customer
			// since" is more useful as an anniversary than as a plain field.
			$matched = false;

			foreach ( self::occasion_aliases() as $slug => $aliases ) {
				if ( ! isset( $by_slug[ $slug ] ) || in_array( $by_slug[ $slug ], $occasions, true ) ) {
					continue;
				}

				if ( in_array( $normal, array_map( array( __CLASS__, 'normalise' ), $aliases ), true ) ) {
					$occasions[ $header ] = $by_slug[ $slug ];
					$matched              = true;
					break;
				}
			}

			if ( $matched ) {
				continue;
			}

			foreach ( $fields as $key => $field ) {
				if ( isset( $taken[ $key ] ) ) {
					continue;
				}

				if ( in_array( $normal, array_map( array( __CLASS__, 'normalise' ), $field['aliases'] ), true ) ) {
					$map[ $header ] = $key;
					$taken[ $key ]  = true;
					break;
				}
			}
		}

		// A file with both a full name and separate first/last columns should
		// use the separate ones.
		if ( isset( $taken['first_name'], $taken['last_name'] ) ) {
			$map = array_filter(
				$map,
				static function ( $field ) {
					return 'full_name' !== $field;
				}
			);
		}

		$order = 'day';

		if ( $csv instanceof CardsCo_CSV && $occasions ) {
			$order = self::detect_date_order( $csv, array_keys( $occasions ) );
		}

		return array(
			'fields'     => $map,
			'occasions'  => $occasions,
			'date_order' => $order,
			'segments'   => array(),
		);
	}

	/**
	 * Work out the date order across every date column in the file.
	 *
	 * @param CardsCo_CSV $csv Reader.
	 * @param string[]    $headers Date column names.
	 * @param int         $sample Rows to look at.
	 * @return string 'day' or 'month'.
	 */
	public static function detect_date_order( CardsCo_CSV $csv, array $headers, $sample = 200 ) {
		return self::inspect_date_order( $csv, $headers, $sample )['order'];
	}

	/**
	 * Date order plus the evidence behind it, for showing on screen.
	 *
	 * @param CardsCo_CSV $csv Reader.
	 * @param string[]    $headers Date column names.
	 * @param int         $sample Rows to look at.
	 * @return array
	 */
	public static function inspect_date_order( CardsCo_CSV $csv, array $headers, $sample = 200 ) {
		$values = array();

		foreach ( $csv->records( $sample ) as $record ) {
			foreach ( $headers as $header ) {
				if ( isset( $record['data'][ $header ] ) && '' !== $record['data'][ $header ] ) {
					$values[] = $record['data'][ $header ];
				}
			}
		}

		return CardsCo_Date_Parser::detect_order( $values );
	}

	/**
	 * Normalise a header for comparison.
	 *
	 * @param string $value Header.
	 * @return string
	 */
	public static function normalise( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( '_', '-', '.' ), ' ', $value );
		$value = preg_replace( '/[^a-z0-9 ]/', '', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return trim( (string) $value );
	}

	/* ---------------------------------------------------------------------
	 * Reading rows
	 * ------------------------------------------------------------------ */

	/**
	 * Turn one CSV record into the shape the recipient functions expect.
	 *
	 * Pure: it reads nothing and writes nothing, so the review step and the
	 * commit step see exactly the same result.
	 *
	 * @param array $data Row keyed by column name.
	 * @param array $map Column map.
	 * @param array $batch Batch row.
	 * @return array{recipient:array, occasions:array, segments:string[], problems:string[]}
	 */
	public static function shape_row( array $data, array $map, array $batch ) {
		$fields    = $map['fields'] ?? array();
		$order     = ( isset( $map['date_order'] ) && 'month' === $map['date_order'] ) ? 'month' : 'day';
		$values    = array();
		$problems  = array();
		$segments  = array();

		foreach ( $fields as $header => $field ) {
			$values[ $field ] = isset( $data[ $header ] ) ? trim( (string) $data[ $header ] ) : '';
		}

		$first = $values['first_name'] ?? '';
		$last  = $values['last_name'] ?? '';

		if ( ( '' === $first || '' === $last ) && ! empty( $values['full_name'] ) ) {
			list( $split_first, $split_last ) = self::split_name( $values['full_name'] );

			$first = '' !== $first ? $first : $split_first;
			$last  = '' !== $last ? $last : $split_last;
		}

		if ( '' === $first && '' === $last ) {
			$problems[] = __( 'No name', 'cardsco' );
		}

		// A single-word name is kept rather than rejected; plenty of people
		// have one, and the card still gets written.
		if ( '' === $last && '' !== $first ) {
			$last = '';
		}

		$country = strtoupper( substr( trim( (string) ( $values['country'] ?? '' ) ), 0, 2 ) );
		$country = $country ? $country : 'GB';

		$postcode = trim( (string) ( $values['postcode'] ?? '' ) );

		$address_lines = array_filter(
			array(
				trim( (string) ( $values['address_1'] ?? '' ) ),
				trim( (string) ( $values['address_2'] ?? '' ) ),
				trim( (string) ( $values['city'] ?? '' ) ),
				trim( (string) ( $values['county'] ?? '' ) ),
			),
			'strlen'
		);

		// The postcode goes on the envelope too, so it belongs in the address
		// block as well as its own column.
		if ( '' === $postcode ) {
			$postcode = cardsco_extract_postcode( implode( "\n", $address_lines ) );
		} elseif ( $address_lines ) {
			$address_lines[] = $postcode;
		}

		$email = trim( (string) ( $values['email'] ?? '' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$problems[] = __( 'Email does not look valid', 'cardsco' );
			$email      = '';
		}

		$recipient = array(
			'company_id'         => (int) $batch['company_id'],
			'type'               => $batch['recipient_type'],
			'first_name'         => $first,
			'last_name'          => $last,
			'email'              => $email,
			'phone'              => $values['phone'] ?? '',
			'recipient_company'  => $values['recipient_company'] ?? '',
			'job_title'          => $values['job_title'] ?? '',
			'address'            => implode( "\n", $address_lines ),
			'postcode'           => $postcode,
			'country'            => $country,
			'notes'              => $values['notes'] ?? '',
			'source'             => 'import',
			'import_batch_id'    => (int) $batch['id'],
			'relationship_since' => null,
			'last_order_date'    => null,
		);

		foreach ( array( 'relationship_since', 'last_order_date' ) as $date_field ) {
			$raw = trim( (string) ( $values[ $date_field ] ?? '' ) );

			if ( '' === $raw ) {
				continue;
			}

			$parsed = CardsCo_Date_Parser::parse( $raw, $order );

			if ( $parsed && $parsed['year'] ) {
				$recipient[ $date_field ] = sprintf( '%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day'] );
			}
		}

		if ( ! empty( $values['segments'] ) ) {
			$segments = array_filter( array_map( 'trim', preg_split( '/[,;|]/', $values['segments'] ) ), 'strlen' );
		}

		$occasions = array();

		foreach ( (array) ( $map['occasions'] ?? array() ) as $header => $type_id ) {
			$raw = isset( $data[ $header ] ) ? trim( (string) $data[ $header ] ) : '';

			if ( '' === $raw ) {
				continue;
			}

			$parsed = CardsCo_Date_Parser::parse( $raw, $order );

			if ( ! $parsed ) {
				$problems[] = sprintf(
					/* translators: 1: column name, 2: the value that could not be read. */
					__( 'Could not read %1$s "%2$s"', 'cardsco' ),
					$header,
					mb_substr( $raw, 0, 20 )
				);
				continue;
			}

			$occasions[ (int) $type_id ] = $parsed;
		}

		return array(
			'recipient' => $recipient,
			'occasions' => $occasions,
			'segments'  => $segments,
			'problems'  => $problems,
		);
	}

	/**
	 * Split a full name into first and last.
	 *
	 * Splits on the last space, so "Mary Jane Watson" becomes Mary Jane and
	 * Watson. Handles "Watson, Mary Jane" too, which is how exports from
	 * older systems write names.
	 *
	 * @param string $name Full name.
	 * @return array{0:string,1:string}
	 */
	public static function split_name( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );

		if ( '' === $name ) {
			return array( '', '' );
		}

		if ( false !== strpos( $name, ',' ) ) {
			$parts = array_map( 'trim', explode( ',', $name, 2 ) );

			if ( '' !== $parts[0] && ! empty( $parts[1] ) ) {
				return array( $parts[1], $parts[0] );
			}
		}

		$position = strrpos( $name, ' ' );

		if ( false === $position ) {
			return array( $name, '' );
		}

		return array( trim( substr( $name, 0, $position ) ), trim( substr( $name, $position + 1 ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Review
	 * ------------------------------------------------------------------ */

	/**
	 * Walk the whole file without writing anything, and report what would
	 * happen.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array|WP_Error Report.
	 */
	public static function analyse( $batch_id ) {
		$batch = self::get( $batch_id );

		if ( ! $batch ) {
			return new WP_Error( 'cardsco_import_missing', __( 'That import could not be found.', 'cardsco' ) );
		}

		$csv = self::csv( $batch );

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$map        = $batch['column_map'];
		$company_id = (int) $batch['company_id'];

		$report = array(
			'total'        => 0,
			'ready'        => 0,
			'new'          => 0,
			'updates'      => 0,
			'in_file_dupes' => 0,
			'no_address'   => 0,
			'occasions'    => 0,
			'errors'       => array(),
			'error_count'  => 0,
			'samples'      => array(),
			'segments'     => array(),
		);

		$seen_hashes = array();
		$seen_emails = array();

		foreach ( $csv->records() as $record ) {
			$report['total']++;

			$shaped    = self::shape_row( $record['data'], $map, $batch );
			$recipient = $shaped['recipient'];
			$name      = trim( $recipient['first_name'] . ' ' . $recipient['last_name'] );

			if ( in_array( __( 'No name', 'cardsco' ), $shaped['problems'], true ) ) {
				self::add_error( $report, $record['line'], __( 'No name in this row', 'cardsco' ), '' );
				continue;
			}

			// Anything else wrong is worth reporting but does not stop the
			// person being imported.
			foreach ( $shaped['problems'] as $problem ) {
				self::add_error( $report, $record['line'], $problem, $name );
			}

			$hash = cardsco_address_hash( $recipient['address'], $recipient['postcode'] );
			$key  = strtolower( $name ) . '|' . $hash;

			if ( isset( $seen_hashes[ $key ] ) && '' !== $hash ) {
				$report['in_file_dupes']++;
				self::add_error(
					$report,
					$record['line'],
					sprintf(
						/* translators: %d: the earlier line number. */
						__( 'Same person and address as line %d', 'cardsco' ),
						$seen_hashes[ $key ]
					),
					$name
				);
				continue;
			}

			if ( '' !== $hash ) {
				$seen_hashes[ $key ] = $record['line'];
			}

			if ( '' !== $recipient['email'] ) {
				$lower = strtolower( $recipient['email'] );

				if ( isset( $seen_emails[ $lower ] ) ) {
					$report['in_file_dupes']++;
					self::add_error(
						$report,
						$record['line'],
						sprintf(
							/* translators: %d: the earlier line number. */
							__( 'Same email as line %d', 'cardsco' ),
							$seen_emails[ $lower ]
						),
						$name
					);
					continue;
				}

				$seen_emails[ $lower ] = $record['line'];
			}

			$existing = self::find_existing( $company_id, $recipient, $hash );

			if ( $existing ) {
				$report['updates']++;
			} else {
				$report['new']++;
			}

			if ( 'complete' !== cardsco_address_status( $recipient['address'], $recipient['postcode'] ) ) {
				$report['no_address']++;
			}

			$report['occasions'] += count( $shaped['occasions'] );
			$report['ready']++;

			foreach ( $shaped['segments'] as $segment_name ) {
				$report['segments'][ $segment_name ] = ( $report['segments'][ $segment_name ] ?? 0 ) + 1;
			}

			if ( count( $report['samples'] ) < 5 ) {
				$report['samples'][] = array(
					'name'      => $name,
					'email'     => $recipient['email'],
					'address'   => $recipient['address'],
					'postcode'  => $recipient['postcode'],
					'occasions' => $shaped['occasions'],
					'existing'  => (bool) $existing,
				);
			}
		}

		self::update_batch(
			$batch_id,
			array(
				'status' => 'analysed',
				'report' => $report,
			)
		);

		return $report;
	}

	/**
	 * Record an error in the report, capped so a badly broken file cannot
	 * produce a megabyte of JSON.
	 *
	 * @param array  $report Report, by reference.
	 * @param int    $line Line number.
	 * @param string $reason Reason.
	 * @param string $name Person's name.
	 * @return void
	 */
	protected static function add_error( array &$report, $line, $reason, $name ) {
		$report['error_count']++;

		if ( count( $report['errors'] ) < self::MAX_REPORTED_ERRORS ) {
			$report['errors'][] = array(
				'line'   => (int) $line,
				'reason' => $reason,
				'name'   => $name,
			);
		}
	}

	/**
	 * Find an existing recipient this row refers to.
	 *
	 * Email first because it is the stronger signal, then name plus address.
	 *
	 * @param int    $company_id Company ID.
	 * @param array  $recipient Shaped recipient.
	 * @param string $hash Address hash.
	 * @return int Recipient ID, or 0.
	 */
	public static function find_existing( $company_id, array $recipient, $hash ) {
		global $wpdb;

		$table = cardsco_table( 'recipients' );

		if ( '' !== $recipient['email'] ) {
			$found = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE company_id = %d AND email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					absint( $company_id ),
					$recipient['email']
				)
			);

			if ( $found ) {
				return $found;
			}
		}

		if ( '' === $hash ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE company_id = %d AND address_hash = %s AND first_name = %s AND last_name = %s
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				$hash,
				$recipient['first_name'],
				$recipient['last_name']
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Commit
	 * ------------------------------------------------------------------ */

	/**
	 * Write one chunk of rows.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param int    $offset Rows already processed.
	 * @param string $strategy skip | update | create for rows that already exist.
	 * @return array{done:bool, offset:int, created:int, updated:int, skipped:int, errors:int}|WP_Error
	 */
	public static function commit_chunk( $batch_id, $offset = 0, $strategy = 'skip' ) {
		$batch = self::get( $batch_id );

		if ( ! $batch ) {
			return new WP_Error( 'cardsco_import_missing', __( 'That import could not be found.', 'cardsco' ) );
		}

		$csv = self::csv( $batch );

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		$strategy   = in_array( $strategy, array( 'skip', 'update', 'create' ), true ) ? $strategy : 'skip';
		$map        = $batch['column_map'];
		$company_id = (int) $batch['company_id'];
		$offset     = max( 0, absint( $offset ) );

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = 0;
		$handled = 0;

		$apply_segments = array_filter( array_map( 'absint', (array) ( $map['segments'] ?? array() ) ) );
		$segment_cache  = array();

		foreach ( $csv->records( self::CHUNK, $offset ) as $record ) {
			$handled++;

			$shaped    = self::shape_row( $record['data'], $map, $batch );
			$recipient = $shaped['recipient'];

			if ( '' === trim( $recipient['first_name'] . $recipient['last_name'] ) ) {
				$errors++;
				continue;
			}

			$hash     = cardsco_address_hash( $recipient['address'], $recipient['postcode'] );
			$existing = self::find_existing( $company_id, $recipient, $hash );

			if ( $existing && 'skip' === $strategy ) {
				$skipped++;
				continue;
			}

			if ( $existing && 'update' === $strategy ) {
				// Never blank a stored value with an empty cell: a column the
				// spreadsheet does not have should not wipe what is already
				// known about somebody.
				$changes = array_filter(
					$recipient,
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				);

				unset( $changes['company_id'], $changes['type'], $changes['source'] );

				$result       = CardsCo_Recipient::update( $existing, $changes );
				$recipient_id = is_wp_error( $result ) ? 0 : $existing;

				if ( $recipient_id ) {
					$updated++;
				} else {
					$errors++;
					continue;
				}
			} else {
				$recipient['allow_duplicate'] = true;

				$result = CardsCo_Recipient::create( $recipient );

				if ( is_wp_error( $result ) ) {
					$errors++;
					continue;
				}

				$recipient_id = (int) $result;
				$created++;
			}

			foreach ( $shaped['occasions'] as $type_id => $parsed ) {
				CardsCo_Occasion::save(
					array(
						'recipient_id'     => $recipient_id,
						'occasion_type_id' => $type_id,
						'event_day'        => $parsed['day'],
						'event_month'      => $parsed['month'],
						'event_year'       => $parsed['year'],
					)
				);
			}

			$segment_ids = $apply_segments;

			foreach ( $shaped['segments'] as $segment_name ) {
				$key = strtolower( $segment_name );

				if ( ! isset( $segment_cache[ $key ] ) ) {
					$segment_cache[ $key ] = self::find_or_create_segment( $company_id, $segment_name );
				}

				if ( $segment_cache[ $key ] ) {
					$segment_ids[] = $segment_cache[ $key ];
				}
			}

			if ( $segment_ids ) {
				CardsCo_Segment::set_for_recipient( $recipient_id, array_unique( $segment_ids ), $company_id );
			}
		}

		$done      = $handled < self::CHUNK;
		$new_total = $offset + $handled;

		self::update_batch(
			$batch_id,
			array(
				'created_count' => (int) $batch['created_count'] + $created,
				'updated_count' => (int) $batch['updated_count'] + $updated,
				'skipped_count' => (int) $batch['skipped_count'] + $skipped,
				'error_count'   => (int) $batch['error_count'] + $errors,
				'status'        => $done ? 'committed' : 'importing',
				'completed_at'  => $done ? cardsco_now() : null,
			)
		);

		if ( $done ) {
			CardsCo_Segment::recount_all( $company_id );
			self::delete_file( $batch );

			cardsco_log(
				array(
					'company_id'  => $company_id,
					'object_type' => 'import',
					'object_id'   => (int) $batch_id,
					'action'      => 'committed',
					'summary'     => sprintf(
						/* translators: 1: file name, 2: rows added, 3: rows updated. */
						__( 'Imported %1$s: %2$d added, %3$d updated', 'cardsco' ),
						$batch['filename'],
						(int) $batch['created_count'] + $created,
						(int) $batch['updated_count'] + $updated
					),
				)
			);
		}

		return array(
			'done'    => $done,
			'offset'  => $new_total,
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * Find a segment by name, creating it if it does not exist.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $name Segment name.
	 * @return int Segment ID, or 0.
	 */
	protected static function find_or_create_segment( $company_id, $name ) {
		global $wpdb;

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return 0;
		}

		$table = cardsco_table( 'segments' );

		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE company_id = %d AND (name = %s OR slug = %s) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				$name,
				sanitize_title( $name )
			)
		);

		if ( $found ) {
			return $found;
		}

		$created = CardsCo_Segment::create(
			array(
				'company_id' => $company_id,
				'name'       => $name,
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Directory holding uploaded import files, created and protected on
	 * first use.
	 *
	 * @return string|WP_Error
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'cardsco_import_uploads', $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'cardsco-imports';

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'cardsco_import_mkdir', __( 'The import folder could not be created.', 'cardsco' ) );
		}

		// These files hold names and addresses, so the folder is closed off
		// rather than left servable.
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Readable message for a PHP upload error code.
	 *
	 * @param int $code Error code.
	 * @return string
	 */
	protected static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'That file is larger than the server allows. Split it and import in parts.', 'cardsco' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload did not finish. Try again.', 'cardsco' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was chosen.', 'cardsco' );

			default:
				return __( 'The file could not be uploaded.', 'cardsco' );
		}
	}
}
