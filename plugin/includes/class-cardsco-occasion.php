<?php
/**
 * Occasions.
 *
 * One row per recipient per dated occasion: this person's birthday, that
 * person's work anniversary. Seasonal occasions are not stored here, because
 * a date that is the same for everybody belongs on the type, not on five
 * thousand identical rows.
 *
 * `next_occurrence` is a cached date that the scheduler reads. Every write
 * recalculates it, so the daily scan is one indexed range query rather than
 * date arithmetic across the whole table.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Occasion {

	/**
	 * Create or update the occasion of a given type for a recipient.
	 *
	 * There is one occasion per type per recipient, enforced by a unique key,
	 * so saving the same type twice edits rather than duplicates.
	 *
	 * @param array $data {
	 *     @type int $recipient_id Required.
	 *     @type int $occasion_type_id Required.
	 *     @type int $event_day Required.
	 *     @type int $event_month Required.
	 *     @type int $event_year Optional for annual, required for one-off.
	 *     @type int $lead_days Optional override.
	 * }
	 * @return int|WP_Error Occasion ID.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$recipient_id = absint( $data['recipient_id'] ?? 0 );
		$type_id      = absint( $data['occasion_type_id'] ?? 0 );

		$recipient = CardsCo_Recipient::get( $recipient_id );
		$type      = CardsCo_Occasion_Type::get( $type_id );

		if ( ! $recipient ) {
			return new WP_Error( 'cardsco_occasion_recipient', __( 'That recipient does not exist.', 'cardsco' ) );
		}

		if ( ! $type ) {
			return new WP_Error( 'cardsco_occasion_type', __( 'That occasion does not exist.', 'cardsco' ) );
		}

		if ( 'dated' !== $type['mode'] ) {
			return new WP_Error(
				'cardsco_occasion_seasonal',
				__( 'Seasonal occasions are sent as a campaign, so they are not set per person.', 'cardsco' )
			);
		}

		$month = absint( $data['event_month'] ?? 0 );
		$day   = absint( $data['event_day'] ?? 0 );
		$year  = absint( $data['event_year'] ?? 0 );

		if ( ! $month || ! $day ) {
			return new WP_Error( 'cardsco_occasion_date', __( 'Pick a day and a month.', 'cardsco' ) );
		}

		// 29 February is allowed, so validate against a leap year when no
		// year has been given.
		if ( ! checkdate( $month, $day, $year ? $year : 2024 ) ) {
			return new WP_Error( 'cardsco_occasion_real_date', __( 'That is not a real date.', 'cardsco' ) );
		}

		$recurrence = 'once' === $type['recurrence'] ? 'once' : 'annual';

		if ( 'once' === $recurrence && ! $year ) {
			return new WP_Error(
				'cardsco_occasion_year',
				/* translators: %s: occasion label. */
				sprintf( __( '%s happens once, so it needs a year.', 'cardsco' ), $type['label'] )
			);
		}

		$row = array(
			'company_id'       => (int) $recipient['company_id'],
			'recipient_id'     => $recipient_id,
			'occasion_type_id' => $type_id,
			'event_month'      => $month,
			'event_day'        => $day,
			'event_year'       => $year ? $year : null,
			'recurrence'       => $recurrence,
			'lead_days'        => isset( $data['lead_days'] ) && '' !== $data['lead_days'] ? absint( $data['lead_days'] ) : null,
			'is_active'        => isset( $data['is_active'] ) ? ( $data['is_active'] ? 1 : 0 ) : 1,
			'notes'            => sanitize_text_field( (string) ( $data['notes'] ?? '' ) ),
			'updated_at'       => cardsco_now(),
		);

		$row['next_occurrence'] = cardsco_next_occurrence(
			array(
				'event_month' => $month,
				'event_day'   => $day,
				'event_year'  => $year,
				'recurrence'  => $recurrence,
			)
		);

		$existing = self::get_by_type( $recipient_id, $type_id );

		if ( $existing ) {
			$wpdb->update(
				cardsco_table( 'occasions' ),
				$row,
				array( 'id' => (int) $existing['id'] ),
				self::formats( $row ),
				array( '%d' )
			);

			return (int) $existing['id'];
		}

		$row['created_at'] = cardsco_now();

		$inserted = $wpdb->insert( cardsco_table( 'occasions' ), $row, self::formats( $row ) );

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_occasion_insert', __( 'The occasion could not be saved.', 'cardsco' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one occasion.
	 *
	 * @param int $occasion_id Occasion ID.
	 * @return array|null
	 */
	public static function get( $occasion_id ) {
		global $wpdb;

		$occasion_id = absint( $occasion_id );

		if ( ! $occasion_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'occasions' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$occasion_id
			),
			ARRAY_A
		);
	}

	/**
	 * Fetch a recipient's occasion of a given type.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @param int $type_id Type ID.
	 * @return array|null
	 */
	public static function get_by_type( $recipient_id, $type_id ) {
		global $wpdb;

		$table = cardsco_table( 'occasions' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recipient_id = %d AND occasion_type_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $recipient_id ),
				absint( $type_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Every occasion a recipient has, with the type label joined on.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @return array[]
	 */
	public static function for_recipient( $recipient_id ) {
		global $wpdb;

		$occasions = cardsco_table( 'occasions' );
		$types     = cardsco_table( 'occasion_types' );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, t.label, t.slug, t.recurrence AS type_recurrence, t.lead_days AS type_lead_days
				 FROM {$occasions} o
				 INNER JOIN {$types} t ON t.id = o.occasion_type_id
				 WHERE o.recipient_id = %d
				 ORDER BY t.sort_order ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $recipient_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Remove a recipient's occasion of a given type.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @param int $type_id Type ID.
	 * @return bool
	 */
	public static function delete_by_type( $recipient_id, $type_id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			cardsco_table( 'occasions' ),
			array(
				'recipient_id'     => absint( $recipient_id ),
				'occasion_type_id' => absint( $type_id ),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Occasions coming up within a window.
	 *
	 * This is the query the scheduler will use in segment 6, and the same one
	 * the upcoming screen uses now, so what staff see is exactly what the
	 * cron will act on.
	 *
	 * @param array $args {
	 *     @type int    $company_id 0 for every company.
	 *     @type string $from Y-m-d, defaults to today.
	 *     @type int    $days Window length.
	 *     @type int    $recipient_id Restrict to one person.
	 *     @type int    $limit
	 * }
	 * @return array[]
	 */
	public static function upcoming( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'company_id'   => 0,
				'from'         => '',
				'days'         => 60,
				'recipient_id' => 0,
				'limit'        => 200,
			)
		);

		$from = $args['from'] ? $args['from'] : cardsco_now( 'date' );
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' +' . absint( $args['days'] ) . ' days' ) );

		$occasions  = cardsco_table( 'occasions' );
		$types      = cardsco_table( 'occasion_types' );
		$recipients = cardsco_table( 'recipients' );

		$where  = array(
			'o.is_active = 1',
			"r.status = 'active'",
			'o.next_occurrence IS NOT NULL',
			'o.next_occurrence BETWEEN %s AND %s',
		);
		$params = array( $from, $to );

		if ( $args['company_id'] ) {
			$where[]  = 'o.company_id = %d';
			$params[] = absint( $args['company_id'] );
		}

		if ( $args['recipient_id'] ) {
			$where[]  = 'o.recipient_id = %d';
			$params[] = absint( $args['recipient_id'] );
		}

		$params[] = absint( $args['limit'] );

		$sql = "SELECT o.*, t.label, t.slug, t.lead_days AS type_lead_days,
					r.first_name, r.last_name, r.type AS recipient_type,
					r.address_status, r.recipient_company
				FROM {$occasions} o
				INNER JOIN {$types} t ON t.id = o.occasion_type_id
				INNER JOIN {$recipients} r ON r.id = o.recipient_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY o.next_occurrence ASC, r.last_name ASC
				LIMIT %d';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Roll `next_occurrence` forward for anything whose date has passed.
	 *
	 * Runs on the hourly maintenance hook. Without it an annual occasion would
	 * sit on last year's date once it had gone by and never be seen again.
	 *
	 * @param int $limit Rows to touch in one pass.
	 * @return int Number updated.
	 */
	public static function refresh_stale( $limit = 500 ) {
		global $wpdb;

		$table = cardsco_table( 'occasions' );
		$today = cardsco_now( 'date' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_month, event_day, event_year, recurrence
				 FROM {$table}
				 WHERE is_active = 1 AND (next_occurrence IS NULL OR next_occurrence < %s)
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$today,
				absint( $limit )
			),
			ARRAY_A
		);

		$updated = 0;

		foreach ( $rows as $row ) {
			$next = cardsco_next_occurrence( $row, $today );

			// A one-off that has been and gone has no next date. Deactivate it
			// rather than leaving it to be rescanned forever.
			$fields = array( 'next_occurrence' => $next );
			$format = array( '%s' );

			if ( null === $next && 'once' === $row['recurrence'] ) {
				$fields['is_active'] = 0;
				$format[]            = '%d';
			}

			$wpdb->update( $table, $fields, array( 'id' => (int) $row['id'] ), $format, array( '%d' ) );

			$updated++;
		}

		return $updated;
	}

	/**
	 * Recalculate every occasion for a company.
	 *
	 * @param int $company_id Company ID.
	 * @return int Number updated.
	 */
	public static function recalculate_for_company( $company_id ) {
		global $wpdb;

		$table = cardsco_table( 'occasions' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_month, event_day, event_year, recurrence FROM {$table} WHERE company_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id )
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'next_occurrence' => cardsco_next_occurrence( $row ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return count( $rows );
	}

	/**
	 * Count of occasions due within a window, for the dashboard tiles.
	 *
	 * @param int $company_id Company ID.
	 * @param int $days Window length.
	 * @return int
	 */
	public static function count_upcoming( $company_id, $days = 30 ) {
		global $wpdb;

		$occasions  = cardsco_table( 'occasions' );
		$recipients = cardsco_table( 'recipients' );

		$from = cardsco_now( 'date' );
		$to   = gmdate( 'Y-m-d', strtotime( $from . ' +' . absint( $days ) . ' days' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$occasions} o
				 INNER JOIN {$recipients} r ON r.id = o.recipient_id
				 WHERE o.company_id = %d
				   AND o.is_active = 1
				   AND r.status = 'active'
				   AND o.next_occurrence BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				$from,
				$to
			)
		);
	}

	/**
	 * wpdb format array matching a prepared row.
	 *
	 * @param array $row Row.
	 * @return string[]
	 */
	protected static function formats( array $row ) {
		$integers = array( 'company_id', 'recipient_id', 'occasion_type_id', 'event_month', 'event_day', 'event_year', 'lead_days', 'is_active' );
		$formats  = array();

		foreach ( array_keys( $row ) as $key ) {
			$formats[] = in_array( $key, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}
}
