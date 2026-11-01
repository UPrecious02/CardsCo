<?php
/**
 * Recipients.
 *
 * Customers and employees live in one table, separated by `type`. Everything
 * that writes a recipient goes through create() or update() so the address
 * hash and address status are always recalculated and never drift.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Recipient {

	/**
	 * Columns that may be sorted on. Anything else falls back to the default,
	 * so an orderby coming from a query string can never reach SQL unchecked.
	 *
	 * @var string[]
	 */
	const SORTABLE = array(
		'id',
		'first_name',
		'last_name',
		'email',
		'recipient_company',
		'type',
		'status',
		'address_status',
		'last_order_date',
		'created_at',
		'updated_at',
	);

	/**
	 * Create a recipient.
	 *
	 * @param array $data Recipient fields. company_id is required.
	 * @return int|WP_Error Recipient ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$company_id = absint( $data['company_id'] ?? 0 );

		if ( ! $company_id ) {
			return new WP_Error( 'cardsco_recipient_company', __( 'A company is required.', 'cardsco' ) );
		}

		$valid = CardsCo_Validator::recipient( $data );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$row = self::prepare_row( $data );

		$row['company_id'] = $company_id;
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = cardsco_now();
		$row['updated_at'] = cardsco_now();

		$duplicate = self::find_duplicate( $company_id, $row['first_name'], $row['last_name'], $row['address_hash'] );

		if ( $duplicate && empty( $data['allow_duplicate'] ) ) {
			return new WP_Error(
				'cardsco_recipient_duplicate',
				__( 'Somebody with this name and address is already on the list.', 'cardsco' ),
				array( 'duplicate_id' => $duplicate )
			);
		}

		$inserted = $wpdb->insert( cardsco_table( 'recipients' ), $row, self::formats( $row ) );

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_recipient_insert', __( 'The recipient could not be saved.', 'cardsco' ) );
		}

		$recipient_id = (int) $wpdb->insert_id;

		if ( isset( $data['segments'] ) ) {
			CardsCo_Segment::set_for_recipient( $recipient_id, (array) $data['segments'], $company_id );
		}

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'recipient',
				'object_id'   => $recipient_id,
				'action'      => 'created',
				/* translators: %s: recipient name. */
				'summary'     => sprintf( __( '%s added', 'cardsco' ), trim( $row['first_name'] . ' ' . $row['last_name'] ) ),
			)
		);

		return $recipient_id;
	}

	/**
	 * Update a recipient.
	 *
	 * @param int   $recipient_id Recipient ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $recipient_id, array $data ) {
		global $wpdb;

		$recipient_id = absint( $recipient_id );
		$existing     = self::get( $recipient_id );

		if ( ! $existing ) {
			return new WP_Error( 'cardsco_recipient_missing', __( 'That recipient does not exist.', 'cardsco' ) );
		}

		// Merge over the existing row first, then validate the result rather
		// than the change on its own. Validating a partial update cannot see
		// the fields it is not touching, so clearing a first name would slip
		// past a check that had no idea a last name was already stored.
		$merged = array_merge( $existing, $data );

		$valid = CardsCo_Validator::recipient( $merged );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$row = self::prepare_row( $merged );

		unset( $row['company_id'] );

		$row['updated_at'] = cardsco_now();

		$wpdb->update(
			cardsco_table( 'recipients' ),
			$row,
			array( 'id' => $recipient_id ),
			self::formats( $row ),
			array( '%d' )
		);

		if ( isset( $data['segments'] ) ) {
			CardsCo_Segment::set_for_recipient( $recipient_id, (array) $data['segments'], (int) $existing['company_id'] );
		}

		wp_cache_delete( $recipient_id, 'cardsco_recipients' );

		cardsco_log(
			array(
				'company_id'  => (int) $existing['company_id'],
				'object_type' => 'recipient',
				'object_id'   => $recipient_id,
				'action'      => 'updated',
				/* translators: %s: recipient name. */
				'summary'     => sprintf( __( '%s updated', 'cardsco' ), trim( $row['first_name'] . ' ' . $row['last_name'] ) ),
			)
		);

		return true;
	}

	/**
	 * Fetch one recipient.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return array|null
	 */
	public static function get( $recipient_id ) {
		global $wpdb;

		$recipient_id = absint( $recipient_id );

		if ( ! $recipient_id ) {
			return null;
		}

		$cached = wp_cache_get( $recipient_id, 'cardsco_recipients' );

		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'recipients' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$recipient_id
			),
			ARRAY_A
		);

		wp_cache_set( $recipient_id, $row ? $row : 0, 'cardsco_recipients', HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * Query recipients.
	 *
	 * @param array $args {
	 *     @type int    $company_id Required.
	 *     @type string $type customer | employee | any
	 *     @type string $status active | archived | any
	 *     @type int    $segment_id Restrict to one segment.
	 *     @type string $search Matches name, email and company.
	 *     @type string $address_status complete | incomplete | missing
	 *     @type int[]  $include
	 *     @type int[]  $exclude
	 *     @type string $orderby
	 *     @type string $order ASC | DESC
	 *     @type int    $per_page Use -1 for everything.
	 *     @type int    $paged
	 * }
	 * @return array{items: array[], total: int, pages: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'company_id'     => 0,
				'type'           => 'any',
				'status'         => 'active',
				'segment_id'     => 0,
				'search'         => '',
				'address_status' => '',
				'include'        => array(),
				'exclude'        => array(),
				'orderby'        => 'first_name',
				'order'          => 'ASC',
				'per_page'       => 25,
				'paged'          => 1,
			)
		);

		$table    = cardsco_table( 'recipients' );
		$pivot    = cardsco_table( 'recipient_segments' );
		$where    = array( '1=1' );
		$params   = array();
		$join     = '';

		if ( $args['company_id'] ) {
			$where[]  = 'r.company_id = %d';
			$params[] = absint( $args['company_id'] );
		}

		if ( $args['type'] && 'any' !== $args['type'] ) {
			$where[]  = 'r.type = %s';
			$params[] = $args['type'];
		}

		if ( $args['status'] && 'any' !== $args['status'] ) {
			$where[]  = 'r.status = %s';
			$params[] = $args['status'];
		}

		if ( $args['address_status'] ) {
			$where[]  = 'r.address_status = %s';
			$params[] = $args['address_status'];
		}

		if ( $args['segment_id'] ) {
			$join     = " INNER JOIN {$pivot} ps ON ps.recipient_id = r.id AND ps.segment_id = %d";
			$params   = array_merge( array( absint( $args['segment_id'] ) ), $params );
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = "(r.first_name LIKE %s OR r.last_name LIKE %s OR r.email LIKE %s OR r.recipient_company LIKE %s OR CONCAT(r.first_name, ' ', r.last_name) LIKE %s)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$include = array_filter( array_map( 'absint', (array) $args['include'] ) );

		if ( $include ) {
			$where[] = 'r.id IN (' . implode( ',', $include ) . ')';
		}

		$exclude = array_filter( array_map( 'absint', (array) $args['exclude'] ) );

		if ( $exclude ) {
			$where[] = 'r.id NOT IN (' . implode( ',', $exclude ) . ')';
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(DISTINCT r.id) FROM {$table} r{$join} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		$orderby = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'first_name';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$sql = "SELECT DISTINCT r.* FROM {$table} r{$join} WHERE {$where_sql} ORDER BY r.{$orderby} {$order}, r.id {$order}";

		$per_page = (int) $args['per_page'];

		if ( $per_page > 0 ) {
			$paged    = max( 1, absint( $args['paged'] ) );
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $paged - 1 ) * $per_page;
		}

		$items = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'items' => (array) $items,
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		);
	}

	/**
	 * Headline counts for a company, for the dashboard tiles.
	 *
	 * @param int $company_id Company ID.
	 * @return array<string, int>
	 */
	public static function counts( $company_id ) {
		global $wpdb;

		$table      = cardsco_table( 'recipients' );
		$company_id = absint( $company_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN type = 'customer' THEN 1 ELSE 0 END) AS customers,
					SUM(CASE WHEN type = 'employee' THEN 1 ELSE 0 END) AS employees,
					SUM(CASE WHEN address_status <> 'complete' THEN 1 ELSE 0 END) AS needs_address
				 FROM {$table}
				 WHERE company_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id
			),
			ARRAY_A
		);

		return array(
			'total'         => (int) ( $row['total'] ?? 0 ),
			'customers'     => (int) ( $row['customers'] ?? 0 ),
			'employees'     => (int) ( $row['employees'] ?? 0 ),
			'needs_address' => (int) ( $row['needs_address'] ?? 0 ),
		);
	}

	/**
	 * Archive a recipient. Archiving is the normal way to remove somebody:
	 * it keeps the card history intact.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return bool
	 */
	public static function archive( $recipient_id ) {
		return self::set_status( $recipient_id, 'archived' );
	}

	/**
	 * Restore an archived recipient.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return bool
	 */
	public static function restore( $recipient_id ) {
		return self::set_status( $recipient_id, 'active' );
	}

	/**
	 * Permanently delete a recipient and everything attached to them.
	 *
	 * Cards already in the queue are kept, because they carry their own
	 * snapshot of the name and address and may already be written.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return bool
	 */
	public static function delete( $recipient_id ) {
		global $wpdb;

		$recipient_id = absint( $recipient_id );
		$existing     = self::get( $recipient_id );

		if ( ! $existing ) {
			return false;
		}

		$wpdb->delete( cardsco_table( 'occasions' ), array( 'recipient_id' => $recipient_id ), array( '%d' ) );

		$segments = CardsCo_Segment::for_recipient( $recipient_id );

		$wpdb->delete( cardsco_table( 'recipient_segments' ), array( 'recipient_id' => $recipient_id ), array( '%d' ) );
		$wpdb->delete( cardsco_table( 'recipients' ), array( 'id' => $recipient_id ), array( '%d' ) );

		foreach ( $segments as $segment_id ) {
			CardsCo_Segment::recount( $segment_id );
		}

		wp_cache_delete( $recipient_id, 'cardsco_recipients' );

		cardsco_log(
			array(
				'company_id'  => (int) $existing['company_id'],
				'object_type' => 'recipient',
				'object_id'   => $recipient_id,
				'action'      => 'deleted',
				/* translators: %s: recipient name. */
				'summary'     => sprintf( __( '%s deleted', 'cardsco' ), trim( $existing['first_name'] . ' ' . $existing['last_name'] ) ),
			)
		);

		return true;
	}

	/**
	 * Recalculate the postcode, hash and status for every stored address.
	 *
	 * Run once after an upgrade that changes how addresses are read, so rows
	 * saved under the old rules are corrected in place instead of needing
	 * somebody to open and re-save each one.
	 *
	 * @return int Number of rows changed.
	 */
	public static function backfill_addresses() {
		global $wpdb;

		$table   = cardsco_table( 'recipients' );
		$rows    = $wpdb->get_results( "SELECT id, address, postcode, country FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$changed = 0;

		foreach ( (array) $rows as $row ) {
			$country  = $row['country'] ? $row['country'] : 'GB';
			$postcode = trim( (string) $row['postcode'] );

			if ( '' === $postcode && 'GB' === $country ) {
				$postcode = cardsco_extract_postcode( $row['address'] );
			}

			$postcode = CardsCo_Validator::format_postcode( $postcode, $country );
			$status   = cardsco_address_status( $row['address'], $postcode );
			$hash     = cardsco_address_hash( $row['address'], $postcode );

			if ( $postcode === $row['postcode'] && $status === ( $row['address_status'] ?? null ) ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'postcode'       => $postcode,
					'address_status' => $status,
					'address_hash'   => $hash,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			wp_cache_delete( (int) $row['id'], 'cardsco_recipients' );

			$changed++;
		}

		return $changed;
	}

	/**
	 * Look for an existing recipient with the same name and address.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $first_name First name.
	 * @param string $last_name Last name.
	 * @param string $address_hash Address hash.
	 * @param int    $exclude_id Recipient to ignore, when updating.
	 * @return int Recipient ID, or 0.
	 */
	public static function find_duplicate( $company_id, $first_name, $last_name, $address_hash, $exclude_id = 0 ) {
		global $wpdb;

		if ( ! CardsCo_Settings::get( 'duplicate_detection', null, absint( $company_id ) ) ) {
			return 0;
		}

		if ( '' === (string) $address_hash ) {
			return 0;
		}

		$table = cardsco_table( 'recipients' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE company_id = %d
				   AND address_hash = %s
				   AND first_name = %s
				   AND last_name = %s
				   AND id <> %d
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				$address_hash,
				$first_name,
				$last_name,
				absint( $exclude_id )
			)
		);
	}

	/**
	 * Set a recipient's status.
	 *
	 * @param int    $recipient_id Recipient ID.
	 * @param string $status active | archived.
	 * @return bool
	 */
	protected static function set_status( $recipient_id, $status ) {
		global $wpdb;

		$recipient_id = absint( $recipient_id );

		$updated = $wpdb->update(
			cardsco_table( 'recipients' ),
			array(
				'status'     => $status,
				'updated_at' => cardsco_now(),
			),
			array( 'id' => $recipient_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( $recipient_id, 'cardsco_recipients' );

		return false !== $updated;
	}

	/**
	 * Sanitise and normalise a row for storage.
	 *
	 * @param array $data Raw input.
	 * @return array
	 */
	protected static function prepare_row( array $data ) {
		$country  = strtoupper( substr( (string) ( $data['country'] ?? 'GB' ), 0, 2 ) );
		$country  = '' === $country ? 'GB' : $country;
		$address  = sanitize_textarea_field( (string) ( $data['address'] ?? '' ) );
		$postcode = trim( (string) ( $data['postcode'] ?? '' ) );

		// The brief asks for one address box, so most people will type the
		// postcode inside it. Pull it out rather than making them repeat it.
		if ( '' === $postcode && 'GB' === $country ) {
			$postcode = cardsco_extract_postcode( $address );
		}

		$postcode = CardsCo_Validator::format_postcode( $postcode, $country );

		$row = array(
			'type'              => in_array( $data['type'] ?? '', array( 'customer', 'employee' ), true ) ? $data['type'] : 'customer',
			'first_name'        => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name'         => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
			'email'             => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'phone'             => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
			'recipient_company' => sanitize_text_field( (string) ( $data['recipient_company'] ?? '' ) ),
			'job_title'         => sanitize_text_field( (string) ( $data['job_title'] ?? '' ) ),
			'address'           => $address,
			'postcode'          => $postcode,
			'country'           => $country,
			'address_hash'      => cardsco_address_hash( $address, $postcode ),
			'address_status'    => cardsco_address_status( $address, $postcode ),
			'relationship_since' => self::nullable_date( $data['relationship_since'] ?? null ),
			'last_order_date'   => self::nullable_date( $data['last_order_date'] ?? null ),
			'source'            => in_array( $data['source'] ?? '', array( 'manual', 'import', 'order' ), true ) ? $data['source'] : 'manual',
			'import_batch_id'   => absint( $data['import_batch_id'] ?? 0 ),
			'status'            => in_array( $data['status'] ?? '', array( 'active', 'archived' ), true ) ? $data['status'] : 'active',
			'notes'             => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
		);

		if ( isset( $data['company_id'] ) ) {
			$row['company_id'] = absint( $data['company_id'] );
		}

		return $row;
	}

	/**
	 * Return a valid Y-m-d date or null.
	 *
	 * @param mixed $value Candidate.
	 * @return string|null
	 */
	protected static function nullable_date( $value ) {
		$value = trim( (string) $value );

		return ( '' !== $value && CardsCo_Validator::date( $value ) ) ? $value : null;
	}

	/**
	 * wpdb format array matching a prepared row.
	 *
	 * @param array $row Row.
	 * @return string[]
	 */
	protected static function formats( array $row ) {
		$integers = array( 'company_id', 'import_batch_id', 'created_by' );
		$formats  = array();

		foreach ( array_keys( $row ) as $key ) {
			$formats[] = in_array( $key, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}
}
