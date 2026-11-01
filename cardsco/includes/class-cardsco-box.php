<?php
/**
 * Boxes: a month of cards travelling together.
 *
 * A programme set to the monthly box rhythm does not post cards one at a
 * time. Every card for, say, November is written in late October, packed into
 * one box and sent to the office so it arrives before November starts. Whoever
 * runs the office then hands each card over on the day.
 *
 * A programme set to post each card instead skips boxes entirely and queues
 * cards individually, a set number of days before each date.
 *
 * Either way the cards end up in the same queue, tagged with the programme
 * they came from, so fulfilment is one list of work.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Box {

	/**
	 * How many periods ahead to keep planned, so the dashboard can show the
	 * next box before anybody needs to act on it.
	 */
	const LOOKAHEAD = 2;

	/* ---------------------------------------------------------------------
	 * Dates
	 * ------------------------------------------------------------------ */

	/**
	 * Pull a date back off the weekend.
	 *
	 * A box due to arrive on a Saturday is no use: nobody is there to take it
	 * in. The same goes for a card meant to be on a desk on a Sunday. When the
	 * weekend rule is on, such a date moves back to the Friday before, never
	 * forward, because early is harmless and late is the whole problem.
	 *
	 * @param string $date Y-m-d.
	 * @param bool   $apply Whether the rule is switched on.
	 * @return string Y-m-d.
	 */
	public static function working_day( $date, $apply = true ) {
		if ( ! $apply || ! $date ) {
			return $date;
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return $date;
		}

		// 6 Saturday, 7 Sunday.
		$weekday = (int) gmdate( 'N', $timestamp );

		if ( $weekday < 6 ) {
			return $date;
		}

		return gmdate( 'Y-m-d', strtotime( '-' . ( $weekday - 5 ) . ' days', $timestamp ) );
	}

	/**
	 * The date a box must arrive, weekend rule applied.
	 *
	 * @param array  $programme Programme row.
	 * @param string $period YYYY-MM.
	 * @return string|null Y-m-d.
	 */
	public static function arrive_by( array $programme, $period ) {
		$date = CardsCo_Programme::box_arrive_date( $programme, $period );

		if ( ! $date ) {
			return null;
		}

		return self::working_day( $date, ! empty( $programme['weekend_rule'] ) );
	}

	/**
	 * The date a box must be written and packed by.
	 *
	 * @param array  $programme Programme row.
	 * @param string $arrive_by Y-m-d.
	 * @return string|null Y-m-d.
	 */
	public static function write_by( array $programme, $arrive_by ) {
		if ( ! $arrive_by ) {
			return null;
		}

		$days = (int) CardsCo_Settings::get( 'box_production_days', null, (int) $programme['company_id'] );
		$days = $days > 0 ? $days : 7;

		return self::working_day(
			cardsco_write_by_date( $arrive_by, $days ),
			! empty( $programme['weekend_rule'] )
		);
	}

	/**
	 * Turn a period and somebody's day and month into a real date.
	 *
	 * 29 February in a year that has no 29 February falls back to the 28th
	 * rather than silently rolling into March.
	 *
	 * @param string $period YYYY-MM.
	 * @param int    $day Day of month.
	 * @return string|null Y-m-d.
	 */
	public static function event_date( $period, $day ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $period, $m ) ) {
			return null;
		}

		$year  = (int) $m[1];
		$month = (int) $m[2];
		$day   = (int) $day;

		if ( $day < 1 ) {
			return null;
		}

		$last = (int) gmdate( 't', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) );

		return sprintf( '%04d-%02d-%02d', $year, $month, min( $day, $last ) );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * One box by ID.
	 *
	 * @param int $box_id Box ID.
	 * @return array|null
	 */
	public static function get( $box_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . cardsco_table( 'boxes' ) . ' WHERE id = %d', absint( $box_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The box for a programme and period, if one exists.
	 *
	 * @param int    $programme_id Programme ID.
	 * @param string $period YYYY-MM.
	 * @return array|null
	 */
	public static function find( $programme_id, $period ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'boxes' ) . ' WHERE programme_id = %d AND period = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $programme_id ),
				(string) $period
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Boxes for a company, newest period first.
	 *
	 * @param int   $company_id Company ID.
	 * @param array $args Optional status and limit.
	 * @return array[]
	 */
	public static function for_company( $company_id, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'       => '',
				'programme_id' => 0,
				'limit'        => 24,
			)
		);

		$where  = array( 'company_id = %d' );
		$params = array( absint( $company_id ) );

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['programme_id'] ) {
			$where[]  = 'programme_id = %d';
			$params[] = absint( $args['programme_id'] );
		}

		$params[] = absint( $args['limit'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'boxes' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY period DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * The cards in a box.
	 *
	 * @param int $box_id Box ID.
	 * @return array[]
	 */
	public static function cards( $box_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'card_queue' ) . ' WHERE box_id = %d ORDER BY event_date ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $box_id )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Everybody in a programme with a date falling in this period.
	 *
	 * @param array  $programme Programme row.
	 * @param string $period YYYY-MM.
	 * @return array[] Each with the recipient row plus event_date.
	 */
	public static function members_in_period( array $programme, $period ) {
		global $wpdb;

		$type = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );

		if ( ! $type || ! preg_match( '/^\d{4}-(\d{2})$/', (string) $period, $m ) ) {
			return array();
		}

		$link       = cardsco_table( 'programme_recipients' );
		$recipients = cardsco_table( 'recipients' );
		$occasions  = cardsco_table( 'occasions' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, o.id AS occasion_id, o.event_day, o.event_month
				 FROM {$link} l
				 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
				 INNER JOIN {$occasions} o ON o.recipient_id = l.recipient_id
				   AND o.occasion_type_id = %d AND o.is_active = 1
				 WHERE l.programme_id = %d AND o.event_month = %d
				 ORDER BY o.event_day ASC, r.last_name ASC, r.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $type['id'],
				(int) $programme['id'],
				(int) $m[1]
			),
			ARRAY_A
		);

		// A query that fails comes back null, which looks exactly like nobody
		// having a birthday that month. Say so loudly instead: a silent empty
		// list here once cost a whole suite's worth of wrong green ticks.
		if ( null === $rows && $wpdb->last_error ) {
			cardsco_log(
				array(
					'company_id'  => (int) $programme['company_id'],
					'object_type' => 'programme',
					'object_id'   => (int) $programme['id'],
					'action'      => 'error',
					'summary'     => 'Could not read programme members: ' . $wpdb->last_error,
				)
			);
		}

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['event_date']     = self::event_date( $period, $row['event_day'] );
			$rows[ $i ]['occasion_label'] = (string) $type['label'];
		}

		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Building
	 * ------------------------------------------------------------------ */

	/**
	 * Create the box row for a programme and period if it is not there yet.
	 *
	 * @param array  $programme Programme row.
	 * @param string $period YYYY-MM.
	 * @return array|WP_Error The box row.
	 */
	public static function ensure( array $programme, $period ) {
		global $wpdb;

		$existing = self::find( (int) $programme['id'], $period );

		if ( $existing ) {
			return $existing;
		}

		$arrive_by = self::arrive_by( $programme, $period );

		if ( ! $arrive_by ) {
			return new WP_Error( 'cardsco_box_period', __( 'That period could not be read.', 'cardsco' ) );
		}

		$inserted = $wpdb->insert(
			cardsco_table( 'boxes' ),
			array(
				'company_id'    => (int) $programme['company_id'],
				'programme_id'  => (int) $programme['id'],
				'period'        => (string) $period,
				'arrive_by'     => $arrive_by,
				'write_by'      => self::write_by( $programme, $arrive_by ),
				'card_count'    => 0,
				'ship_address'  => (string) $programme['ship_address'],
				'ship_postcode' => (string) $programme['ship_postcode'],
				'status'        => 'planned',
				'currency'      => $programme['currency'] ? $programme['currency'] : CardsCo_Settings::get( 'currency' ),
				'created_at'    => cardsco_now(),
				'updated_at'    => cardsco_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			// The unique key on programme and period means a second attempt in
			// the same moment loses, which is the outcome we wanted.
			$raced = self::find( (int) $programme['id'], $period );

			if ( $raced ) {
				return $raced;
			}

			return new WP_Error( 'cardsco_box_insert', __( 'That box could not be created.', 'cardsco' ) );
		}

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Move a whole box on, taking its cards with it.
	 *
	 * This is what the fulfilment team actually presses. Marking a box packed
	 * marks every card in it written; marking it posted marks them posted.
	 *
	 * Cards on hold are deliberately left where they are. A card held because
	 * nobody filled in an address has not been written and must not be swept
	 * along with the rest, so the count of those left behind comes back with
	 * the result and the screen says so plainly.
	 *
	 * @param int    $box_id Box ID.
	 * @param string $status planned, writing, packed, posted or cancelled.
	 * @return array|WP_Error {moved:int, left:int, status:string}
	 */
	public static function advance( $box_id, $status ) {
		$box = self::get( $box_id );

		if ( ! $box ) {
			return new WP_Error( 'cardsco_box_missing', __( 'That box could not be found.', 'cardsco' ) );
		}

		// What each box status means for the cards travelling in it. A box
		// going back to planned or being cancelled does not rewrite history on
		// cards that are already done, so those map to nothing.
		$cascade = array(
			'writing' => 'writing',
			'packed'  => 'written',
			'posted'  => 'posted',
		);

		if ( ! self::set_status( $box_id, $status ) ) {
			return new WP_Error( 'cardsco_box_status', __( 'That is not a status a box can be in.', 'cardsco' ) );
		}

		$result = array(
			'moved'  => 0,
			'left'   => 0,
			'status' => $status,
		);

		if ( ! isset( $cascade[ $status ] ) ) {
			return $result;
		}

		foreach ( self::cards( $box_id ) as $card ) {
			if ( in_array( $card['status'], array( 'on_hold', 'cancelled', 'failed' ), true ) ) {
				$result['left']++;
				continue;
			}

			if ( $card['status'] === $cascade[ $status ] ) {
				continue;
			}

			$moved = CardsCo_Queue::set_status( (int) $card['id'], $cascade[ $status ] );

			if ( ! is_wp_error( $moved ) ) {
				$result['moved']++;
			}
		}

		cardsco_log(
			array(
				'company_id'  => (int) $box['company_id'],
				'object_type' => 'box',
				'object_id'   => (int) $box_id,
				'action'      => 'box_' . $status,
				'summary'     => sprintf(
					/* translators: 1: period, 2: status, 3: number of cards. */
					__( '%1$s box marked %2$s (%3$d cards)', 'cardsco' ),
					$box['period'],
					$status,
					$result['moved']
				),
			)
		);

		return $result;
	}

	/**
	 * Boxes with work outstanding, soonest deadline first.
	 *
	 * This is the fulfilment team's to-do list: everything that still has to be
	 * written, packed or posted, across every company.
	 *
	 * @param array $args company_id, limit.
	 * @return array[]
	 */
	public static function outstanding( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'company_id' => 0,
				'limit'      => 100,
			)
		);

		$where  = array( "status IN ('planned','writing','packed')" );
		$params = array();

		if ( $args['company_id'] ) {
			$where[]  = 'company_id = %d';
			$params[] = absint( $args['company_id'] );
		}

		$sql = 'SELECT * FROM ' . cardsco_table( 'boxes' ) . ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY write_by ASC, arrive_by ASC LIMIT %d';

		$params[] = absint( $args['limit'] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		return $rows ? $rows : array();
	}

	/**
	 * Add up what is actually in a box and write the totals back.
	 *
	 * @param int $box_id Box ID.
	 * @return array|null The refreshed box.
	 */
	public static function recalc( $box_id ) {
		global $wpdb;

		$box = self::get( $box_id );

		if ( ! $box ) {
			return null;
		}

		$cards = self::cards( $box_id );
		$total = 0.0;

		foreach ( $cards as $card ) {
			$total += (float) $card['unit_price'];
		}

		$wpdb->update(
			cardsco_table( 'boxes' ),
			array(
				'card_count' => count( $cards ),
				'amount'     => number_format( $total, 2, '.', '' ),
				'updated_at' => cardsco_now(),
			),
			array( 'id' => (int) $box_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		return self::get( $box_id );
	}

	/**
	 * Change a box's status.
	 *
	 * @param int    $box_id Box ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $box_id, $status ) {
		global $wpdb;

		$allowed = array( 'planned', 'writing', 'packed', 'posted', 'cancelled' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$row = array(
			'status'     => $status,
			'updated_at' => cardsco_now(),
		);

		$format = array( '%s', '%s' );

		if ( 'posted' === $status ) {
			$row['posted_at'] = cardsco_now();
			$format[]         = '%s';
		}

		return (bool) $wpdb->update( cardsco_table( 'boxes' ), $row, array( 'id' => absint( $box_id ) ), $format, array( '%d' ) );
	}

	/**
	 * Fill a box: queue a card for everybody in the programme whose date falls
	 * in that month.
	 *
	 * Running this again is safe. The queue's unique key on occasion and date
	 * means anybody already in the box is left alone, so a box can be topped up
	 * when somebody is added to the programme late.
	 *
	 * @param array $programme Programme row.
	 * @param array $box Box row.
	 * @param bool  $dry_run Decide but write nothing.
	 * @return array{queued:int, held:int, already:int, skipped:int, reasons:array}
	 */
	public static function fill( array $programme, array $box, $dry_run = false ) {
		$result = array(
			'queued'  => 0,
			'held'    => 0,
			'already' => 0,
			'skipped' => 0,
			'reasons' => array(),
		);

		$members = self::members_in_period( $programme, $box['period'] );

		if ( ! $members ) {
			return $result;
		}

		$prepared = self::prepare( $programme );

		if ( is_wp_error( $prepared ) ) {
			$result['skipped']                              = count( $members );
			$result['reasons'][ $prepared->get_error_code() ] = count( $members );

			return $result;
		}

		foreach ( $members as $member ) {
			$outcome = self::queue_one( $programme, $member, $prepared, $box, $dry_run );

			$result[ $outcome['bucket'] ]++;

			if ( $outcome['reason'] ) {
				$result['reasons'][ $outcome['reason'] ] = ( $result['reasons'][ $outcome['reason'] ] ?? 0 ) + 1;
			}
		}

		if ( ! $dry_run ) {
			self::recalc( (int) $box['id'] );
		}

		return $result;
	}

	/**
	 * The card and wording a programme sends, read once per run rather than
	 * once per person.
	 *
	 * @param array $programme Programme row.
	 * @return array|WP_Error
	 */
	public static function prepare( array $programme ) {
		$card = $programme['card_id'] ? get_post( (int) $programme['card_id'] ) : null;

		if ( ! $card || 'publish' !== $card->post_status ) {
			return new WP_Error( 'no_card', __( 'This occasion has no card chosen.', 'cardsco' ) );
		}

		// Wording typed into the setup wins over a saved message, because that
		// is the box the company actually filled in.
		$template = trim( (string) $programme['message_override'] );

		if ( '' === $template && $programme['message_id'] ) {
			$message = get_post( (int) $programme['message_id'] );

			if ( $message && 'publish' === $message->post_status ) {
				$template = CardsCo_Message::text( $message->ID );
			}
		}

		if ( '' === trim( $template ) ) {
			return new WP_Error( 'no_message', __( 'This occasion has no wording.', 'cardsco' ) );
		}

		return array(
			'card'     => $card,
			'template' => $template,
			'price'    => null !== $programme['unit_price']
				? (float) $programme['unit_price']
				: (float) CardsCo_Card::price( $card->ID, (int) $programme['company_id'] ),
		);
	}

	/**
	 * Queue one person's card.
	 *
	 * @param array      $programme Programme row.
	 * @param array      $member Recipient row with event_date and occasion_id.
	 * @param array      $prepared Output of prepare().
	 * @param array|null $box Box row, or null when cards post individually.
	 * @param bool       $dry_run Decide but write nothing.
	 * @return array{bucket:string, reason:string}
	 */
	public static function queue_one( array $programme, array $member, array $prepared, $box, $dry_run = false ) {
		$event_date = $member['event_date'];

		if ( ! $event_date ) {
			return array(
				'bucket' => 'skipped',
				'reason' => 'no_date',
			);
		}

		if ( CardsCo_Queue::exists_for( (int) $member['occasion_id'], $event_date ) ) {
			return array(
				'bucket' => 'already',
				'reason' => '',
			);
		}

		$rendered = CardsCo_Merge::render(
			$prepared['template'],
			CardsCo_Merge::context_for_recipient( $member )
		);

		// Where it goes. A box travels to one address, so the person's own
		// address does not matter and a missing one must not hold the card up.
		$to_office = $box || 'company' === $programme['shipping_mode'];

		if ( $to_office ) {
			$address  = (string) $programme['ship_address'];
			$postcode = (string) $programme['ship_postcode'];
			$country  = $programme['ship_country'] ? $programme['ship_country'] : 'GB';
		} else {
			$address  = (string) $member['address'];
			$postcode = (string) $member['postcode'];
			$country  = $member['country'] ? $member['country'] : 'GB';
		}

		$status = 'queued';
		$reason = '';

		if ( 'complete' !== cardsco_address_status( $address, $postcode ) ) {
			$status = 'on_hold';
			$reason = $to_office ? 'no_office_address' : 'no_address';
		} elseif ( ! empty( $rendered['missing'] ) || ! empty( $rendered['unknown'] ) ) {
			$status = 'on_hold';
			$reason = 'message_gaps';
		}

		if ( $dry_run ) {
			return array(
				'bucket' => 'on_hold' === $status ? 'held' : 'queued',
				'reason' => $reason,
			);
		}

		// When the cards travel together the whole box shares one deadline.
		// When they go one at a time each card has its own.
		if ( $box ) {
			$write_by = $box['write_by'];
			$post_by  = $box['arrive_by'];
		} else {
			$lead     = (int) $programme['days_before'];
			$lead     = $lead > 0 ? $lead : (int) CardsCo_Settings::get( 'default_lead_days', null, (int) $programme['company_id'] );
			$postage  = (int) CardsCo_Settings::get( 'postage_days', null, (int) $programme['company_id'] );
			$weekend  = ! empty( $programme['weekend_rule'] );
			$write_by = self::working_day( cardsco_write_by_date( $event_date, $lead ), $weekend );
			$post_by  = self::working_day( cardsco_write_by_date( $event_date, max( 0, $postage ) ), $weekend );
		}

		$queue_id = CardsCo_Queue::add(
			array(
				'company_id'        => (int) $programme['company_id'],
				'source'            => 'occasion',
				'occasion_id'       => (int) $member['occasion_id'],
				'programme_id'      => (int) $programme['id'],
				'box_id'            => $box ? (int) $box['id'] : 0,
				'recipient_id'      => (int) $member['id'],
				'recipient_name'    => trim( $member['first_name'] . ' ' . $member['last_name'] ),
				'recipient_company' => (string) $member['recipient_company'],
				'address_snapshot'  => $address,
				'postcode'          => $postcode,
				'country'           => $country,
				'card_id'           => (int) $prepared['card']->ID,
				'card_title'        => $prepared['card']->post_title,
				'occasion_label'    => (string) $member['occasion_label'],
				'message_final'     => $rendered['text'],
				'event_date'        => $event_date,
				'write_by'          => $write_by,
				'post_by'           => $post_by,
				'status'            => $status,
				'hold_reason'       => $reason,
				'unit_price'        => number_format( $prepared['price'], 2, '.', '' ),
			)
		);

		if ( is_wp_error( $queue_id ) ) {
			return array(
				'bucket' => 'skipped',
				'reason' => $queue_id->get_error_code(),
			);
		}

		if ( ! $queue_id ) {
			return array(
				'bucket' => 'already',
				'reason' => '',
			);
		}

		return array(
			'bucket' => 'on_hold' === $status ? 'held' : 'queued',
			'reason' => $reason,
		);
	}

	/* ---------------------------------------------------------------------
	 * The run
	 * ------------------------------------------------------------------ */

	/**
	 * Which periods a programme should have boxes for right now.
	 *
	 * @param array  $programme Programme row.
	 * @param string $today Y-m-d, for testing.
	 * @return string[] YYYY-MM.
	 */
	public static function periods_to_build( array $programme, $today = '' ) {
		$today = $today ? $today : cardsco_now( 'date' );
		$start = CardsCo_Programme::next_period( $programme, $today );
		$out   = array();

		for ( $i = 0; $i <= self::LOOKAHEAD; $i++ ) {
			$stamp = strtotime( $start . '-01 +' . $i . ' month' );

			if ( ! $stamp ) {
				continue;
			}

			$out[] = gmdate( 'Y-m', $stamp );
		}

		return $out;
	}

	/**
	 * Build and fill boxes for every active programme.
	 *
	 * A box is only filled once its write-by date is in sight; before that it
	 * sits planned so the dashboard can show what is coming without committing
	 * anybody to a card.
	 *
	 * @param array $args company_id, today, dry_run.
	 * @return array Summary.
	 */
	public static function run( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'company_id' => 0,
				'today'      => '',
				'dry_run'    => false,
			)
		);

		$today = $args['today'] ? $args['today'] : cardsco_now( 'date' );

		$summary = array(
			'programmes' => 0,
			'boxes_made' => 0,
			'boxes_fill' => 0,
			'queued'     => 0,
			'held'       => 0,
			'already'    => 0,
			'skipped'    => 0,
			'reasons'    => array(),
			'ran_at'     => cardsco_now(),
		);

		$programmes = CardsCo_Programme::active( absint( $args['company_id'] ) );

		foreach ( $programmes as $programme ) {
			$summary['programmes']++;

			if ( 'monthly_box' !== $programme['timing_mode'] ) {
				self::tally( $summary, self::post_individually( $programme, $today, (bool) $args['dry_run'] ) );
				continue;
			}

			foreach ( self::periods_to_build( $programme, $today ) as $period ) {
				$box = self::ensure( $programme, $period );

				if ( is_wp_error( $box ) ) {
					$summary['skipped']++;
					$summary['reasons'][ $box->get_error_code() ] = ( $summary['reasons'][ $box->get_error_code() ] ?? 0 ) + 1;
					continue;
				}

				if ( (int) $box['card_count'] === 0 && 'planned' === $box['status'] ) {
					$summary['boxes_made']++;
				}

				// Nothing to do yet for a box whose writing week has not come,
				// and nothing to do ever for one already out of the door.
				if ( in_array( $box['status'], array( 'posted', 'cancelled' ), true ) ) {
					continue;
				}

				if ( $box['write_by'] && $today < $box['write_by'] ) {
					continue;
				}

				$summary['boxes_fill']++;
				self::tally( $summary, self::fill( $programme, $box, (bool) $args['dry_run'] ) );

				if ( ! $args['dry_run'] && 'planned' === $box['status'] ) {
					self::set_status( (int) $box['id'], 'writing' );
				}
			}
		}

		if ( ! $args['dry_run'] ) {
			update_option( 'cardsco_last_box_run', $summary, false );
		}

		return $summary;
	}

	/**
	 * Queue cards one at a time for a programme that does not use boxes.
	 *
	 * @param array  $programme Programme row.
	 * @param string $today Y-m-d.
	 * @param bool   $dry_run Decide but write nothing.
	 * @return array Same shape as fill().
	 */
	public static function post_individually( array $programme, $today, $dry_run = false ) {
		$result = array(
			'queued'  => 0,
			'held'    => 0,
			'already' => 0,
			'skipped' => 0,
			'reasons' => array(),
		);

		$lead = (int) $programme['days_before'];
		$lead = $lead > 0 ? $lead : (int) CardsCo_Settings::get( 'default_lead_days', null, (int) $programme['company_id'] );

		$prepared = self::prepare( $programme );

		// Look far enough ahead to catch everybody whose writing day has come.
		foreach ( self::periods_to_build( $programme, $today ) as $period ) {
			$members = self::members_in_period( $programme, $period );

			foreach ( $members as $member ) {
				if ( ! $member['event_date'] ) {
					$result['skipped']++;
					continue;
				}

				// Not yet: this card is still too far off to write.
				//
				// The weekend rule has to be applied here too, not only when
				// the date is stored. A card whose write-by lands on a Saturday
				// is actually due on the Friday, and comparing against the
				// unadjusted date would let that Friday go by untouched.
				$due = self::working_day(
					cardsco_write_by_date( $member['event_date'], $lead ),
					! empty( $programme['weekend_rule'] )
				);

				if ( $today < $due ) {
					continue;
				}

				if ( is_wp_error( $prepared ) ) {
					$result['skipped']++;
					$code                       = $prepared->get_error_code();
					$result['reasons'][ $code ] = ( $result['reasons'][ $code ] ?? 0 ) + 1;
					continue;
				}

				$outcome = self::queue_one( $programme, $member, $prepared, null, $dry_run );
				$result[ $outcome['bucket'] ]++;

				if ( $outcome['reason'] ) {
					$result['reasons'][ $outcome['reason'] ] = ( $result['reasons'][ $outcome['reason'] ] ?? 0 ) + 1;
				}
			}
		}

		return $result;
	}

	/**
	 * Fold one result into the run summary.
	 *
	 * @param array $summary Summary, by reference.
	 * @param array $result One fill() or post_individually() result.
	 * @return void
	 */
	protected static function tally( array &$summary, array $result ) {
		foreach ( array( 'queued', 'held', 'already', 'skipped' ) as $key ) {
			$summary[ $key ] += (int) ( $result[ $key ] ?? 0 );
		}

		foreach ( ( $result['reasons'] ?? array() ) as $reason => $count ) {
			$summary['reasons'][ $reason ] = ( $summary['reasons'][ $reason ] ?? 0 ) + (int) $count;
		}
	}
}
