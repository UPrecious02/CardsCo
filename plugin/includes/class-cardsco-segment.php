<?php
/**
 * Segments.
 *
 * Named audience groups a company creates for itself. Membership is a simple
 * many-to-many; `member_count` on the segment is a cached figure kept in step
 * by recount(), so listing segments never needs a COUNT per row.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Segment {

	/**
	 * Create a segment.
	 *
	 * @param array $data name, company_id, description, applies_to.
	 * @return int|WP_Error Segment ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$company_id = absint( $data['company_id'] ?? 0 );

		if ( ! $company_id ) {
			return new WP_Error( 'cardsco_segment_company', __( 'A company is required.', 'cardsco' ) );
		}

		$valid = CardsCo_Validator::segment( $data );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$name = sanitize_text_field( $data['name'] );

		$row = array(
			'company_id'  => $company_id,
			'name'        => $name,
			'slug'        => self::unique_slug( $company_id, $name ),
			'description' => sanitize_text_field( $data['description'] ?? '' ),
			'applies_to'  => in_array( $data['applies_to'] ?? '', array( 'customer', 'employee', 'both' ), true ) ? $data['applies_to'] : 'both',
			'is_auto'     => 0,
			'rules'       => null,
			'member_count' => 0,
			'created_by'  => get_current_user_id(),
			'created_at'  => cardsco_now(),
			'updated_at'  => cardsco_now(),
		);

		$inserted = $wpdb->insert(
			cardsco_table( 'segments' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_segment_insert', __( 'The segment could not be saved.', 'cardsco' ) );
		}

		$segment_id = (int) $wpdb->insert_id;

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'segment',
				'object_id'   => $segment_id,
				'action'      => 'created',
				/* translators: %s: segment name. */
				'summary'     => sprintf( __( 'Segment %s created', 'cardsco' ), $name ),
			)
		);

		return $segment_id;
	}

	/**
	 * Update a segment.
	 *
	 * @param int   $segment_id Segment ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $segment_id, array $data ) {
		global $wpdb;

		$segment_id = absint( $segment_id );
		$existing   = self::get( $segment_id );

		if ( ! $existing ) {
			return new WP_Error( 'cardsco_segment_missing', __( 'That segment does not exist.', 'cardsco' ) );
		}

		$valid = CardsCo_Validator::segment( $data, true );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$fields = array();
		$format = array();

		if ( array_key_exists( 'name', $data ) ) {
			$fields['name'] = sanitize_text_field( $data['name'] );
			$format[]       = '%s';

			if ( $fields['name'] !== $existing['name'] ) {
				$fields['slug'] = self::unique_slug( (int) $existing['company_id'], $fields['name'], $segment_id );
				$format[]       = '%s';
			}
		}

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_text_field( $data['description'] );
			$format[]              = '%s';
		}

		if ( array_key_exists( 'applies_to', $data ) ) {
			$fields['applies_to'] = in_array( $data['applies_to'], array( 'customer', 'employee', 'both' ), true ) ? $data['applies_to'] : 'both';
			$format[]             = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = cardsco_now();
		$format[]             = '%s';

		$wpdb->update( cardsco_table( 'segments' ), $fields, array( 'id' => $segment_id ), $format, array( '%d' ) );

		wp_cache_delete( $segment_id, 'cardsco_segments' );

		return true;
	}

	/**
	 * Fetch a segment.
	 *
	 * @param int $segment_id Segment ID.
	 * @return array|null
	 */
	public static function get( $segment_id ) {
		global $wpdb;

		$segment_id = absint( $segment_id );

		if ( ! $segment_id ) {
			return null;
		}

		$cached = wp_cache_get( $segment_id, 'cardsco_segments' );

		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'segments' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$segment_id
			),
			ARRAY_A
		);

		wp_cache_set( $segment_id, $row ? $row : 0, 'cardsco_segments', HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * All segments for a company.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $applies_to Filter by audience, or 'any'.
	 * @return array[]
	 */
	public static function all( $company_id, $applies_to = 'any' ) {
		global $wpdb;

		$table  = cardsco_table( 'segments' );
		$sql    = "SELECT * FROM {$table} WHERE company_id = %d";
		$params = array( absint( $company_id ) );

		if ( $applies_to && 'any' !== $applies_to ) {
			$sql     .= ' AND applies_to IN (%s, %s)';
			$params[] = $applies_to;
			$params[] = 'both';
		}

		$sql .= ' ORDER BY name ASC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Delete a segment. Recipients are untouched.
	 *
	 * @param int $segment_id Segment ID.
	 * @return bool
	 */
	public static function delete( $segment_id ) {
		global $wpdb;

		$segment_id = absint( $segment_id );
		$existing   = self::get( $segment_id );

		if ( ! $existing ) {
			return false;
		}

		$wpdb->delete( cardsco_table( 'recipient_segments' ), array( 'segment_id' => $segment_id ), array( '%d' ) );
		$wpdb->delete( cardsco_table( 'segments' ), array( 'id' => $segment_id ), array( '%d' ) );

		wp_cache_delete( $segment_id, 'cardsco_segments' );

		cardsco_log(
			array(
				'company_id'  => (int) $existing['company_id'],
				'object_type' => 'segment',
				'object_id'   => $segment_id,
				'action'      => 'deleted',
				/* translators: %s: segment name. */
				'summary'     => sprintf( __( 'Segment %s deleted', 'cardsco' ), $existing['name'] ),
			)
		);

		return true;
	}

	/**
	 * Add recipients to a segment.
	 *
	 * @param int   $segment_id Segment ID.
	 * @param int[] $recipient_ids Recipient IDs.
	 * @return int Number of rows added.
	 */
	public static function add_recipients( $segment_id, array $recipient_ids ) {
		global $wpdb;

		$segment = self::get( $segment_id );

		if ( ! $segment ) {
			return 0;
		}

		$recipient_ids = array_filter( array_unique( array_map( 'absint', $recipient_ids ) ) );

		if ( ! $recipient_ids ) {
			return 0;
		}

		$table  = cardsco_table( 'recipient_segments' );
		$now    = cardsco_now();
		$values = array();
		$params = array();

		foreach ( $recipient_ids as $recipient_id ) {
			$values[] = '(%d, %d, %d, %s)';
			$params[] = (int) $segment['company_id'];
			$params[] = $recipient_id;
			$params[] = (int) $segment['id'];
			$params[] = $now;
		}

		$sql = "INSERT IGNORE INTO {$table} (company_id, recipient_id, segment_id, created_at) VALUES " . implode( ', ', $values );

		$added = (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		self::recount( $segment_id );

		return $added;
	}

	/**
	 * Remove recipients from a segment.
	 *
	 * @param int   $segment_id Segment ID.
	 * @param int[] $recipient_ids Recipient IDs.
	 * @return int Number of rows removed.
	 */
	public static function remove_recipients( $segment_id, array $recipient_ids ) {
		global $wpdb;

		$segment_id    = absint( $segment_id );
		$recipient_ids = array_filter( array_unique( array_map( 'absint', $recipient_ids ) ) );

		if ( ! $segment_id || ! $recipient_ids ) {
			return 0;
		}

		$table        = cardsco_table( 'recipient_segments' );
		$placeholders = implode( ',', array_fill( 0, count( $recipient_ids ), '%d' ) );
		$params       = array_merge( array( $segment_id ), $recipient_ids );

		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE segment_id = %d AND recipient_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			)
		);

		self::recount( $segment_id );

		return $removed;
	}

	/**
	 * Replace a recipient's segments with exactly this list.
	 *
	 * @param int   $recipient_id Recipient ID.
	 * @param int[] $segment_ids Segment IDs.
	 * @param int   $company_id Company ID.
	 * @return void
	 */
	public static function set_for_recipient( $recipient_id, array $segment_ids, $company_id ) {
		$recipient_id = absint( $recipient_id );
		$company_id   = absint( $company_id );
		$wanted       = array_filter( array_unique( array_map( 'absint', $segment_ids ) ) );
		$current      = self::for_recipient( $recipient_id );

		foreach ( array_diff( $current, $wanted ) as $segment_id ) {
			self::remove_recipients( $segment_id, array( $recipient_id ) );
		}

		foreach ( array_diff( $wanted, $current ) as $segment_id ) {
			$segment = self::get( $segment_id );

			// Never let one company's recipient land in another company's segment.
			if ( $segment && (int) $segment['company_id'] === $company_id ) {
				self::add_recipients( $segment_id, array( $recipient_id ) );
			}
		}
	}

	/**
	 * Segment IDs a recipient belongs to.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return int[]
	 */
	public static function for_recipient( $recipient_id ) {
		global $wpdb;

		$table = cardsco_table( 'recipient_segments' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT segment_id FROM {$table} WHERE recipient_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $recipient_id )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Recipient IDs in a segment.
	 *
	 * @param int    $segment_id Segment ID.
	 * @param string $status Recipient status filter.
	 * @return int[]
	 */
	public static function members( $segment_id, $status = 'active' ) {
		global $wpdb;

		$pivot      = cardsco_table( 'recipient_segments' );
		$recipients = cardsco_table( 'recipients' );

		$sql    = "SELECT p.recipient_id FROM {$pivot} p INNER JOIN {$recipients} r ON r.id = p.recipient_id WHERE p.segment_id = %d";
		$params = array( absint( $segment_id ) );

		if ( $status && 'any' !== $status ) {
			$sql     .= ' AND r.status = %s';
			$params[] = $status;
		}

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Recalculate and store a segment's member count.
	 *
	 * Counts active recipients only, which is what the dashboard shows.
	 *
	 * @param int $segment_id Segment ID.
	 * @return int
	 */
	public static function recount( $segment_id ) {
		global $wpdb;

		$segment_id = absint( $segment_id );

		if ( ! $segment_id ) {
			return 0;
		}

		$pivot      = cardsco_table( 'recipient_segments' );
		$recipients = cardsco_table( 'recipients' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$pivot} p
				 INNER JOIN {$recipients} r ON r.id = p.recipient_id
				 WHERE p.segment_id = %d AND r.status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL
				$segment_id
			)
		);

		$wpdb->update(
			cardsco_table( 'segments' ),
			array( 'member_count' => $count ),
			array( 'id' => $segment_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( $segment_id, 'cardsco_segments' );

		return $count;
	}

	/**
	 * Recalculate every segment for a company. Used after a bulk import or
	 * an archive sweep, where recounting per row would be wasteful.
	 *
	 * @param int $company_id Company ID.
	 * @return void
	 */
	public static function recount_all( $company_id ) {
		foreach ( self::all( $company_id ) as $segment ) {
			self::recount( (int) $segment['id'] );
		}
	}

	/**
	 * Build a slug unique within the company.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $name Segment name.
	 * @param int    $exclude_id Segment to ignore, when renaming.
	 * @return string
	 */
	protected static function unique_slug( $company_id, $name, $exclude_id = 0 ) {
		global $wpdb;

		$base = sanitize_title( $name );
		$base = '' === $base ? 'segment' : $base;
		$slug = $base;
		$i    = 1;

		$table = cardsco_table( 'segments' );

		while (
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE company_id = %d AND slug = %s AND id <> %d", // phpcs:ignore WordPress.DB.PreparedSQL
					absint( $company_id ),
					$slug,
					absint( $exclude_id )
				)
			)
		) {
			$i++;
			$slug = $base . '-' . $i;
		}

		return $slug;
	}
}
