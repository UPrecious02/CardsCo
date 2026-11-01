<?php
/**
 * The small import inside the occasion setup.
 *
 * Step 2 asks for very little: a name and the day and month. That is a much
 * narrower job than the full four step import, so it gets its own path rather
 * than dragging someone through column mapping for three columns. The reading
 * and date handling underneath are the same tested pieces.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Programme_Import {

	/**
	 * Header names accepted for each field.
	 *
	 * @return array<string, string[]>
	 */
	public static function columns() {
		return array(
			'first_name' => array( 'first name', 'firstname', 'first', 'forename', 'given name' ),
			'last_name'  => array( 'last name', 'lastname', 'last', 'surname', 'family name' ),
			'full_name'  => array( 'name', 'full name', 'employee name', 'person' ),
			'date'       => array( 'birthday', 'date of birth', 'dob', 'birth date', 'date', 'anniversary', 'start date' ),
			'email'      => array( 'email', 'e-mail', 'email address' ),
		);
	}

	/**
	 * The example file offered on the step.
	 *
	 * @param array $programme Programme row.
	 * @return string CSV body.
	 */
	public static function template( array $programme ) {
		$type  = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );
		$label = $type ? strtolower( $type['label'] ) : __( 'date', 'cardsco' );

		$rows = array(
			array( 'First name', 'Last name', ucfirst( $label ) ),
			array( 'Tunde', 'Bakare', '14 November' ),
			array( 'Hannah', 'Lee', '03/11' ),
			array( 'Marcus', 'Reid', '1990-12-16' ),
		);

		$out = '';

		foreach ( $rows as $row ) {
			$out .= implode( ',', $row ) . "\n";
		}

		return $out;
	}

	/**
	 * Read an uploaded list into a programme.
	 *
	 * Creates anybody new, attaches the date, and puts everyone in the
	 * programme. Somebody already on the company's list is matched rather
	 * than duplicated.
	 *
	 * @param int   $programme_id Programme ID.
	 * @param array $file One entry from $_FILES.
	 * @return array|WP_Error Summary.
	 */
	public static function run( $programme_id, array $file ) {
		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			return new WP_Error( 'cardsco_import_programme', __( 'That occasion could not be found.', 'cardsco' ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'cardsco_import_upload', __( 'No file was uploaded.', 'cardsco' ) );
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'csv', 'txt', 'tsv' ), true ) ) {
			return new WP_Error(
				'cardsco_import_type',
				__( 'Only CSV files can be read. In Excel or Google Sheets choose File, then Download or Save As, and pick CSV.', 'cardsco' )
			);
		}

		$dir = CardsCo_Import::storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = trailingslashit( $dir ) . wp_generate_password( 20, false, false ) . '.csv';

		if ( ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new WP_Error( 'cardsco_import_move', __( 'The file could not be saved.', 'cardsco' ) );
		}

		return self::read_file( $programme_id, $path );
	}

	/**
	 * Read a list that is already on disk into a programme.
	 *
	 * Split out from run() so the reading can be exercised without an actual
	 * HTTP upload: everything interesting happens here, and run() only does
	 * the upload checks around it.
	 *
	 * @param int    $programme_id Programme ID.
	 * @param string $path Absolute path to a readable CSV.
	 * @param bool   $delete Remove the file afterwards.
	 * @return array|WP_Error Summary.
	 */
	public static function read_file( $programme_id, $path, $delete = true ) {
		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			return new WP_Error( 'cardsco_import_programme', __( 'That occasion could not be found.', 'cardsco' ) );
		}

		$csv    = new CardsCo_CSV( $path );
		$opened = $csv->open();

		if ( is_wp_error( $opened ) ) {
			if ( $delete ) {
				wp_delete_file( $path );
			}

			return $opened;
		}

		$map = self::map_headers( $csv->headers() );

		if ( empty( $map['first_name'] ) && empty( $map['full_name'] ) ) {
			if ( $delete ) {
				wp_delete_file( $path );
			}

			return new WP_Error(
				'cardsco_import_noname',
				__( 'We could not find a name column. The first row should name the columns, for example: First name, Last name, Birthday.', 'cardsco' )
			);
		}

		$company_id = (int) $programme['company_id'];
		$type       = CardsCo_Category::primary_type( $programme['category'], $company_id );
		$audience   = CardsCo_Category::get( $programme['category'] )['audience'] ?? 'both';
		$recipient_type = in_array( $audience, array( 'customer', 'employee' ), true ) ? $audience : 'customer';

		// Read the dates across the whole file before reading any single one,
		// so 03/04 is settled by the column rather than by the row.
		$order = self::detect_order( $csv, $map );

		$summary = array(
			'total'        => 0,
			'added'        => 0,
			'matched'      => 0,
			'with_date'    => 0,
			'missing_date' => 0,
			'problems'     => array(),
			'order'        => $order,
		);

		$recipient_ids = array();

		foreach ( $csv->records() as $record ) {
			$summary['total']++;

			$data  = $record['data'];
			$first = isset( $map['first_name'] ) ? trim( (string) ( $data[ $map['first_name'] ] ?? '' ) ) : '';
			$last  = isset( $map['last_name'] ) ? trim( (string) ( $data[ $map['last_name'] ] ?? '' ) ) : '';

			if ( ( '' === $first && '' === $last ) && isset( $map['full_name'] ) ) {
				list( $first, $last ) = CardsCo_Import::split_name( (string) ( $data[ $map['full_name'] ] ?? '' ) );
			}

			if ( '' === $first && '' === $last ) {
				$summary['problems'][] = sprintf(
					/* translators: %d: line number. */
					__( 'Line %d has no name', 'cardsco' ),
					(int) $record['line']
				);
				continue;
			}

			$email = isset( $map['email'] ) ? sanitize_email( (string) ( $data[ $map['email'] ] ?? '' ) ) : '';
			$email = is_email( $email ) ? $email : '';

			$existing = self::find_person( $company_id, $email, $first, $last );

			if ( $existing ) {
				$recipient_id = (int) $existing;
				$summary['matched']++;
			} else {
				$created = CardsCo_Recipient::create(
					array(
						'company_id'      => $company_id,
						'type'            => $recipient_type,
						'first_name'      => $first,
						'last_name'       => $last,
						'email'           => $email,
						'source'          => 'import',
						'allow_duplicate' => true,
					)
				);

				if ( is_wp_error( $created ) ) {
					$summary['problems'][] = sprintf(
						/* translators: 1: line number, 2: reason. */
						__( 'Line %1$d: %2$s', 'cardsco' ),
						(int) $record['line'],
						implode( ' ', $created->get_error_messages() )
					);
					continue;
				}

				$recipient_id = (int) $created;
				$summary['added']++;
			}

			$recipient_ids[] = $recipient_id;

			$raw = isset( $map['date'] ) ? trim( (string) ( $data[ $map['date'] ] ?? '' ) ) : '';

			if ( '' === $raw || ! $type ) {
				$summary['missing_date']++;
				continue;
			}

			$parsed = CardsCo_Date_Parser::parse( $raw, $order );

			if ( ! $parsed ) {
				$summary['missing_date']++;
				$summary['problems'][] = sprintf(
					/* translators: 1: line number, 2: the value. */
					__( 'Line %1$d: could not read the date "%2$s"', 'cardsco' ),
					(int) $record['line'],
					mb_substr( $raw, 0, 20 )
				);
				continue;
			}

			CardsCo_Occasion::save(
				array(
					'recipient_id'     => $recipient_id,
					'occasion_type_id' => (int) $type['id'],
					'event_day'        => $parsed['day'],
					'event_month'      => $parsed['month'],
					'event_year'       => $parsed['year'],
				)
			);

			$summary['with_date']++;
		}

		CardsCo_Programme::add_recipients( $programme_id, $recipient_ids );

		// The file holds names, so it does not stay on the server.
		if ( $delete ) {
			wp_delete_file( $path );
		}

		// Keep the list short enough to read on screen.
		$summary['problem_count'] = count( $summary['problems'] );
		$summary['problems']      = array_slice( $summary['problems'], 0, 20 );

		return $summary;
	}

	/**
	 * Find somebody already on the company's list.
	 *
	 * This step collects no address, so the usual name-plus-address match has
	 * nothing to work with and would create everybody again on a second
	 * upload. Here the email decides it when there is one, and the name alone
	 * when there is not — which is what "Replace file" has to mean for a team
	 * list that gets re-uploaded.
	 *
	 * The known limit: two people in the same company with exactly the same
	 * name and no email collapse into one. Adding the second by hand keeps
	 * them apart.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $email Email, may be empty.
	 * @param string $first First name.
	 * @param string $last Last name.
	 * @return int Recipient ID, or 0.
	 */
	protected static function find_person( $company_id, $email, $first, $last ) {
		global $wpdb;

		$table      = cardsco_table( 'recipients' );
		$company_id = absint( $company_id );

		if ( '' !== $email ) {
			$found = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE company_id = %d AND email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
					$company_id,
					$email
				)
			);

			if ( $found ) {
				return $found;
			}
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE company_id = %d AND first_name = %s AND last_name = %s AND status = 'active'
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				$first,
				$last
			)
		);
	}

	/**
	 * Match the file's headers to the few fields this step wants.
	 *
	 * @param string[] $headers Column names.
	 * @return array<string, string> Field mapped to header.
	 */
	public static function map_headers( array $headers ) {
		$map   = array();
		$taken = array();

		foreach ( self::columns() as $field => $aliases ) {
			$aliases = array_map( array( 'CardsCo_Import', 'normalise' ), $aliases );

			foreach ( $headers as $header ) {
				if ( isset( $taken[ $header ] ) ) {
					continue;
				}

				if ( in_array( CardsCo_Import::normalise( $header ), $aliases, true ) ) {
					$map[ $field ]    = $header;
					$taken[ $header ] = true;
					break;
				}
			}
		}

		// Separate first and last columns beat a single full name column.
		if ( isset( $map['first_name'], $map['last_name'] ) ) {
			unset( $map['full_name'] );
		}

		return $map;
	}

	/**
	 * Work out whether the date column is day first or month first.
	 *
	 * @param CardsCo_CSV $csv Reader.
	 * @param array       $map Header map.
	 * @return string 'day' or 'month'.
	 */
	public static function detect_order( CardsCo_CSV $csv, array $map ) {
		if ( empty( $map['date'] ) ) {
			return 'day';
		}

		$values = array();

		foreach ( $csv->records( 200 ) as $record ) {
			$value = trim( (string) ( $record['data'][ $map['date'] ] ?? '' ) );

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		$verdict = CardsCo_Date_Parser::detect_order( $values );

		return $verdict['order'];
	}

	/**
	 * Add one person by hand.
	 *
	 * @param int   $programme_id Programme ID.
	 * @param array $data first_name, last_name, day, month, year, email.
	 * @return int|WP_Error Recipient ID.
	 */
	public static function add_one( $programme_id, array $data ) {
		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			return new WP_Error( 'cardsco_import_programme', __( 'That occasion could not be found.', 'cardsco' ) );
		}

		$company_id = (int) $programme['company_id'];
		$audience   = CardsCo_Category::get( $programme['category'] )['audience'] ?? 'both';

		$recipient = CardsCo_Recipient::create(
			array(
				'company_id'      => $company_id,
				'type'            => in_array( $audience, array( 'customer', 'employee' ), true ) ? $audience : 'customer',
				'first_name'      => $data['first_name'] ?? '',
				'last_name'       => $data['last_name'] ?? '',
				'email'           => $data['email'] ?? '',
				'allow_duplicate' => true,
			)
		);

		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}

		$recipient_id = (int) $recipient;
		$type         = CardsCo_Category::primary_type( $programme['category'], $company_id );
		$day          = absint( $data['day'] ?? 0 );
		$month        = absint( $data['month'] ?? 0 );

		if ( $type && $day && $month ) {
			CardsCo_Occasion::save(
				array(
					'recipient_id'     => $recipient_id,
					'occasion_type_id' => (int) $type['id'],
					'event_day'        => $day,
					'event_month'      => $month,
					'event_year'       => absint( $data['year'] ?? 0 ),
				)
			);
		}

		CardsCo_Programme::add_recipients( $programme_id, array( $recipient_id ) );

		return $recipient_id;
	}

	/**
	 * The people in a programme, with the date the programme runs on.
	 *
	 * @param array $programme Programme row.
	 * @param int   $limit Rows to return.
	 * @return array[]
	 */
	public static function people( array $programme, $limit = 100 ) {
		global $wpdb;

		$type = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );

		$link       = cardsco_table( 'programme_recipients' );
		$recipients = cardsco_table( 'recipients' );
		$occasions  = cardsco_table( 'occasions' );

		$type_id = $type ? (int) $type['id'] : 0;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.first_name, r.last_name, r.email, r.address_status,
						o.event_day, o.event_month, o.event_year, o.next_occurrence
				 FROM {$link} l
				 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
				 LEFT JOIN {$occasions} o ON o.recipient_id = l.recipient_id AND o.occasion_type_id = %d
				 WHERE l.programme_id = %d
				 ORDER BY (o.event_month IS NULL), o.event_month ASC, o.event_day ASC, r.last_name ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$type_id,
				(int) $programme['id'],
				absint( $limit )
			),
			ARRAY_A
		);
	}
}
