<?php
/**
 * The card queue.
 *
 * One row per physical card. Everything that matters is snapshotted at the
 * moment the card is queued: the name, the address, the card title, the
 * finished wording. A card already with the writers must not change because
 * somebody edited a record an hour later.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Queue {

	/**
	 * Statuses a card moves through.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'queued'    => __( 'Queued', 'cardsco' ),
			'on_hold'   => __( 'On hold', 'cardsco' ),
			'writing'   => __( 'Being written', 'cardsco' ),
			'written'   => __( 'Written', 'cardsco' ),
			'posted'    => __( 'Posted', 'cardsco' ),
			'delivered' => __( 'Delivered', 'cardsco' ),
			'cancelled' => __( 'Cancelled', 'cardsco' ),
			'failed'    => __( 'Failed', 'cardsco' ),
		);
	}

	/**
	 * Why a card is held back.
	 *
	 * @return array<string, string>
	 */
	public static function hold_reasons() {
		return array(
			'no_address'      => __( 'Address incomplete', 'cardsco' ),
			'message_gaps'    => __( 'Message has gaps', 'cardsco' ),
			'no_card'         => __( 'No card chosen', 'cardsco' ),
			'no_message'      => __( 'No message chosen', 'cardsco' ),
			'needs_attention' => __( 'Needs attention', 'cardsco' ),
		);
	}

	/**
	 * Add a card to the queue.
	 *
	 * Refuses to create a second card for the same occasion on the same date,
	 * so running the scan twice in a morning cannot send somebody two
	 * birthday cards.
	 *
	 * @param array $data Queue row fields.
	 * @return int|WP_Error Queue ID, or 0 when one already exists.
	 */
	public static function add( array $data ) {
		global $wpdb;

		$company_id = absint( $data['company_id'] ?? 0 );

		if ( ! $company_id ) {
			return new WP_Error( 'cardsco_queue_company', __( 'A company is required.', 'cardsco' ) );
		}

		$occasion_id = isset( $data['occasion_id'] ) ? absint( $data['occasion_id'] ) : 0;
		$event_date  = $data['event_date'] ?? null;

		if ( $occasion_id && $event_date && self::exists_for( $occasion_id, $event_date ) ) {
			return 0;
		}

		$row = array(
			'company_id'        => $company_id,
			'source'            => in_array( $data['source'] ?? '', array( 'occasion', 'campaign', 'order', 'manual' ), true ) ? $data['source'] : 'occasion',
			'occasion_id'       => $occasion_id ? $occasion_id : null,
			'campaign_id'       => ! empty( $data['campaign_id'] ) ? absint( $data['campaign_id'] ) : null,
			'order_id'          => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
			'programme_id'      => ! empty( $data['programme_id'] ) ? absint( $data['programme_id'] ) : null,
			'box_id'            => ! empty( $data['box_id'] ) ? absint( $data['box_id'] ) : null,
			'recipient_id'      => ! empty( $data['recipient_id'] ) ? absint( $data['recipient_id'] ) : null,
			'recipient_name'    => sanitize_text_field( (string) ( $data['recipient_name'] ?? '' ) ),
			'recipient_company' => sanitize_text_field( (string) ( $data['recipient_company'] ?? '' ) ),
			'address_snapshot'  => sanitize_textarea_field( (string) ( $data['address_snapshot'] ?? '' ) ),
			'postcode'          => sanitize_text_field( (string) ( $data['postcode'] ?? '' ) ),
			'country'           => strtoupper( substr( (string) ( $data['country'] ?? 'GB' ), 0, 2 ) ),
			'card_id'           => absint( $data['card_id'] ?? 0 ),
			'card_title'        => sanitize_text_field( (string) ( $data['card_title'] ?? '' ) ),
			'occasion_label'    => sanitize_text_field( (string) ( $data['occasion_label'] ?? '' ) ),
			'message_final'     => (string) ( $data['message_final'] ?? '' ),
			'offer_code'        => sanitize_text_field( (string) ( $data['offer_code'] ?? '' ) ),
			'event_date'        => $event_date,
			'write_by'          => $data['write_by'] ?? null,
			'post_by'           => $data['post_by'] ?? null,
			'status'            => in_array( $data['status'] ?? '', array_keys( self::statuses() ), true ) ? $data['status'] : 'queued',
			'hold_reason'       => sanitize_key( $data['hold_reason'] ?? '' ),
			'assigned_to'       => 0,
			'tracking_ref'      => '',
			'unit_price'        => isset( $data['unit_price'] ) ? $data['unit_price'] : null,
			'currency'          => (string) ( $data['currency'] ?? CardsCo_Settings::get( 'currency', null, $company_id ) ),
			'created_at'        => cardsco_now(),
			'updated_at'        => cardsco_now(),
		);

		$inserted = $wpdb->insert( cardsco_table( 'card_queue' ), $row, self::formats( $row ) );

		if ( ! $inserted ) {
			// Almost certainly the unique key doing its job under a race, which
			// is the outcome we wanted anyway.
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a card already exists for this occasion on this date.
	 *
	 * @param int    $occasion_id Occasion ID.
	 * @param string $event_date Y-m-d.
	 * @return bool
	 */
	public static function exists_for( $occasion_id, $event_date ) {
		global $wpdb;

		$table = cardsco_table( 'card_queue' );

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE occasion_id = %d AND event_date = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $occasion_id ),
				$event_date
			)
		);
	}

	/**
	 * Fetch one card.
	 *
	 * @param int $queue_id Queue ID.
	 * @return array|null
	 */
	public static function get( $queue_id ) {
		global $wpdb;

		$queue_id = absint( $queue_id );

		if ( ! $queue_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'card_queue' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$queue_id
			),
			ARRAY_A
		);
	}

	/**
	 * Columns the queue may be sorted on.
	 *
	 * @var string[]
	 */
	const SORTABLE = array( 'id', 'write_by', 'post_by', 'event_date', 'status', 'recipient_name', 'created_at' );

	/**
	 * Search the queue.
	 *
	 * @param array $args {
	 *     @type int    $company_id 0 for every company.
	 *     @type string $status A status, 'open' for anything not finished, or 'any'.
	 *     @type string $due_before Y-m-d, matched against write_by.
	 *     @type string $search Name, company or postcode.
	 *     @type int    $campaign_id
	 *     @type string $orderby
	 *     @type string $order
	 *     @type int    $per_page -1 for everything.
	 *     @type int    $paged
	 * }
	 * @return array{items: array[], total: int, pages: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'company_id'  => 0,
				'status'      => 'open',
				'due_before'  => '',
				'search'      => '',
				'campaign_id' => 0,
				'orderby'     => 'write_by',
				'order'       => 'ASC',
				'per_page'    => 50,
				'paged'       => 1,
			)
		);

		$table  = cardsco_table( 'card_queue' );
		$where  = array( '1=1' );
		$params = array();

		if ( $args['company_id'] ) {
			$where[]  = 'company_id = %d';
			$params[] = absint( $args['company_id'] );
		}

		if ( 'open' === $args['status'] ) {
			$where[] = "status IN ('queued','on_hold','writing','written')";
		} elseif ( $args['status'] && 'any' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['due_before'] ) {
			$where[]  = 'write_by <= %s';
			$params[] = $args['due_before'];
		}

		if ( $args['campaign_id'] ) {
			$where[]  = 'campaign_id = %d';
			$params[] = absint( $args['campaign_id'] );
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(recipient_name LIKE %s OR recipient_company LIKE %s OR postcode LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL

		$orderby = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'write_by';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id ASC";

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
	 * Counts by status, plus how many are overdue.
	 *
	 * @param int $company_id Company ID, 0 for all.
	 * @return array<string, int>
	 */
	public static function counts( $company_id = 0 ) {
		global $wpdb;

		$table  = cardsco_table( 'card_queue' );
		$where  = '';
		$params = array();

		if ( $company_id ) {
			$where    = ' WHERE company_id = %d';
			$params[] = absint( $company_id );
		}

		$sql  = "SELECT status, COUNT(*) AS n FROM {$table}{$where} GROUP BY status";
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		$overdue_where = "status IN ('queued','on_hold','writing') AND write_by < %s";
		$overdue_args  = array( cardsco_now( 'date' ) );

		if ( $company_id ) {
			$overdue_where .= ' AND company_id = %d';
			$overdue_args[] = absint( $company_id );
		}

		$counts['overdue'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$overdue_where}", $overdue_args ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$counts['open'] = $counts['queued'] + $counts['on_hold'] + $counts['writing'] + $counts['written'];

		return $counts;
	}

	/**
	 * Move a card to a new status.
	 *
	 * @param int    $queue_id Queue ID.
	 * @param string $status New status.
	 * @param array  $extra Optional tracking_ref, assigned_to, hold_reason.
	 * @return bool|WP_Error
	 */
	public static function set_status( $queue_id, $status, array $extra = array() ) {
		global $wpdb;

		$queue_id = absint( $queue_id );
		$card     = self::get( $queue_id );

		if ( ! $card ) {
			return new WP_Error( 'cardsco_queue_missing', __( 'That card is not in the queue.', 'cardsco' ) );
		}

		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return new WP_Error( 'cardsco_queue_status', __( 'That is not a status.', 'cardsco' ) );
		}

		$fields = array(
			'status'     => $status,
			'updated_at' => cardsco_now(),
		);
		$format = array( '%s', '%s' );

		// Clear the reason whenever a card leaves hold, so a stale note cannot
		// follow it through the rest of its life.
		$fields['hold_reason'] = ( 'on_hold' === $status ) ? sanitize_key( $extra['hold_reason'] ?? 'needs_attention' ) : '';
		$format[]              = '%s';

		if ( isset( $extra['assigned_to'] ) ) {
			$fields['assigned_to'] = absint( $extra['assigned_to'] );
			$format[]              = '%d';
		}

		if ( isset( $extra['tracking_ref'] ) ) {
			$fields['tracking_ref'] = sanitize_text_field( $extra['tracking_ref'] );
			$format[]               = '%s';
		}

		if ( 'posted' === $status && empty( $card['posted_at'] ) ) {
			$fields['posted_at'] = cardsco_now();
			$format[]            = '%s';
		}

		if ( 'delivered' === $status && empty( $card['delivered_at'] ) ) {
			$fields['delivered_at'] = cardsco_now();
			$format[]               = '%s';
		}

		$wpdb->update( cardsco_table( 'card_queue' ), $fields, array( 'id' => $queue_id ), $format, array( '%d' ) );

		cardsco_log(
			array(
				'company_id'  => (int) $card['company_id'],
				'object_type' => 'card',
				'object_id'   => $queue_id,
				'action'      => 'status_' . $status,
				'summary'     => sprintf(
					/* translators: 1: recipient name, 2: new status. */
					__( '%1$s card marked %2$s', 'cardsco' ),
					$card['recipient_name'],
					self::statuses()[ $status ]
				),
			)
		);

		return true;
	}

	/**
	 * Re-check a held card and release it if the problem is gone.
	 *
	 * Run from maintenance, so filling in a missing address puts the card back
	 * in the queue without anybody having to find it.
	 *
	 * @param int $queue_id Queue ID.
	 * @return bool True when the card was released.
	 */
	public static function recheck( $queue_id ) {
		$card = self::get( $queue_id );

		if ( ! $card || 'on_hold' !== $card['status'] ) {
			return false;
		}

		if ( 'no_address' !== $card['hold_reason'] || empty( $card['recipient_id'] ) ) {
			return false;
		}

		$recipient = CardsCo_Recipient::get( (int) $card['recipient_id'] );

		if ( ! $recipient || 'complete' !== $recipient['address_status'] ) {
			return false;
		}

		global $wpdb;

		$wpdb->update(
			cardsco_table( 'card_queue' ),
			array(
				'address_snapshot' => $recipient['address'],
				'postcode'         => $recipient['postcode'],
				'country'          => $recipient['country'],
				'status'           => 'queued',
				'hold_reason'      => '',
				'updated_at'       => cardsco_now(),
			),
			array( 'id' => absint( $queue_id ) ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Release every held card whose address has since been filled in.
	 *
	 * @param int $limit Rows to look at.
	 * @return int Number released.
	 */
	public static function recheck_held( $limit = 200 ) {
		global $wpdb;

		$table = cardsco_table( 'card_queue' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'on_hold' AND hold_reason = 'no_address' LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $limit )
			)
		);

		$released = 0;

		foreach ( (array) $ids as $id ) {
			if ( self::recheck( (int) $id ) ) {
				$released++;
			}
		}

		return $released;
	}

	/**
	 * wpdb format array matching a prepared row.
	 *
	 * @param array $row Row.
	 * @return string[]
	 */
	protected static function formats( array $row ) {
		$integers = array( 'company_id', 'occasion_id', 'campaign_id', 'order_id', 'programme_id', 'box_id', 'recipient_id', 'card_id', 'assigned_to' );
		$formats  = array();

		foreach ( array_keys( $row ) as $key ) {
			$formats[] = in_array( $key, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}
}
