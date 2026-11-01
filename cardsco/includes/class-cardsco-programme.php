<?php
/**
 * Programmes.
 *
 * A standing arrangement a company sets up once and leaves running: this
 * occasion, these people, this card, this wording, shipped this way, arriving
 * on this rhythm. Built through the seven step setup, saved as a draft at
 * every step, and turned on at the end.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Programme {

	/**
	 * The setup steps, in order.
	 *
	 * @return array<int, array{key:string, label:string, heading:string}>
	 */
	public static function steps() {
		return array(
			1 => array(
				'key'     => 'occasion',
				'label'   => __( 'Occasion', 'cardsco' ),
				'heading' => __( 'Which occasion?', 'cardsco' ),
			),
			2 => array(
				'key'     => 'people',
				'label'   => __( 'People', 'cardsco' ),
				'heading' => __( "Who's celebrating?", 'cardsco' ),
			),
			3 => array(
				'key'     => 'card',
				'label'   => __( 'Card', 'cardsco' ),
				'heading' => __( 'Pick a card', 'cardsco' ),
			),
			4 => array(
				'key'     => 'message',
				'label'   => __( 'Message', 'cardsco' ),
				'heading' => __( 'What should it say?', 'cardsco' ),
			),
			5 => array(
				'key'     => 'shipping',
				'label'   => __( 'Shipping', 'cardsco' ),
				'heading' => __( 'Where should we ship the cards?', 'cardsco' ),
			),
			6 => array(
				'key'     => 'timing',
				'label'   => __( 'Timing', 'cardsco' ),
				'heading' => __( 'When should the cards arrive?', 'cardsco' ),
			),
			7 => array(
				'key'     => 'review',
				'label'   => __( 'Review', 'cardsco' ),
				'heading' => __( 'Check and turn it on', 'cardsco' ),
			),
		);
	}

	/**
	 * Start a programme as a draft.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $category Category slug.
	 * @return int|WP_Error Programme ID.
	 */
	public static function create( $company_id, $category ) {
		global $wpdb;

		$company_id = absint( $company_id );
		$category   = sanitize_key( $category );

		if ( ! $company_id || ! CardsCo_Company::get( $company_id ) ) {
			return new WP_Error( 'cardsco_programme_company', __( 'Pick a company first.', 'cardsco' ) );
		}

		$definition = CardsCo_Category::get( $category );

		if ( ! $definition ) {
			return new WP_Error( 'cardsco_programme_category', __( 'That is not an occasion we offer.', 'cardsco' ) );
		}

		if ( ! empty( $definition['coming_soon'] ) ) {
			return new WP_Error(
				'cardsco_programme_soon',
				/* translators: %s: occasion name. */
				sprintf( __( '%s is not ready yet.', 'cardsco' ), $definition['label'] )
			);
		}

		$row = array(
			'company_id'  => $company_id,
			'category'    => $category,
			'name'        => $definition['label'],
			'status'      => 'draft',
			'setup_step'  => 1,
			'unit_price'  => CardsCo_Settings::get( 'price_default', null, $company_id ),
			'currency'    => CardsCo_Settings::get( 'currency', null, $company_id ),
			'created_by'  => get_current_user_id(),
			'created_at'  => cardsco_now(),
			'updated_at'  => cardsco_now(),
		);

		$inserted = $wpdb->insert(
			cardsco_table( 'programmes' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_programme_insert', __( 'That could not be started.', 'cardsco' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a programme.
	 *
	 * @param int $programme_id Programme ID.
	 * @return array|null
	 */
	public static function get( $programme_id ) {
		global $wpdb;

		$programme_id = absint( $programme_id );

		if ( ! $programme_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$programme_id
			),
			ARRAY_A
		);
	}

	/**
	 * A company's programmes.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $status draft | active | paused | any.
	 * @return array[]
	 */
	public static function for_company( $company_id, $status = 'any' ) {
		global $wpdb;

		$table  = cardsco_table( 'programmes' );
		$sql    = "SELECT * FROM {$table} WHERE company_id = %d";
		$params = array( absint( $company_id ) );

		if ( $status && 'any' !== $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		$sql .= ' ORDER BY id DESC';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Every programme the scheduler should be looking at.
	 *
	 * @param int $company_id Company ID, 0 for all.
	 * @return array[]
	 */
	/**
	 * Is this person's occasion already looked after by a running programme?
	 *
	 * Two things queue cards: the general occasion scan, which works through
	 * everybody's dates one at a time, and a programme, which gathers its own
	 * people into boxes. Without this check a person in a programme would get
	 * two cards for the same birthday, one from each.
	 *
	 * The programme wins, because it is the more specific instruction: somebody
	 * sat down and said these people, this card, this rhythm.
	 *
	 * @param int $recipient_id Recipient ID.
	 * @param int $type_id Occasion type ID.
	 * @param int $company_id Company ID.
	 * @return int The programme ID looking after it, or 0.
	 */
	public static function covers( $recipient_id, $type_id, $company_id ) {
		global $wpdb;

		$recipient_id = absint( $recipient_id );
		$type_id      = absint( $type_id );

		if ( ! $recipient_id || ! $type_id ) {
			return 0;
		}

		$categories = CardsCo_Category::for_type( $type_id, absint( $company_id ) );

		if ( ! $categories ) {
			return 0;
		}

		$link        = cardsco_table( 'programme_recipients' );
		$programmes  = cardsco_table( 'programmes' );
		$placeholder = implode( ', ', array_fill( 0, count( $categories ), '%s' ) );

		$params = array_merge( array( $recipient_id ), $categories );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.id FROM {$link} l
				 INNER JOIN {$programmes} p ON p.id = l.programme_id
				 WHERE l.recipient_id = %d AND p.status = 'active'
				   AND p.category IN ({$placeholder})
				 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			)
		);
	}

	public static function active( $company_id = 0 ) {
		global $wpdb;

		$table  = cardsco_table( 'programmes' );
		$sql    = "SELECT * FROM {$table} WHERE status = 'active'";
		$params = array();

		if ( $company_id ) {
			$sql     .= ' AND company_id = %d';
			$params[] = absint( $company_id );
		}

		$sql .= ' ORDER BY id ASC';

		return (array) ( $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Save part of a programme.
	 *
	 * @param int   $programme_id Programme ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $programme_id, array $data ) {
		global $wpdb;

		$programme_id = absint( $programme_id );
		$existing     = self::get( $programme_id );

		if ( ! $existing ) {
			return new WP_Error( 'cardsco_programme_missing', __( 'That occasion could not be found.', 'cardsco' ) );
		}

		$fields = array();
		$format = array();

		$text = array(
			'name'          => 191,
			'handwriting'   => 60,
			'ink'           => 40,
			'signature'     => 191,
			'ship_postcode' => 20,
		);

		foreach ( $text as $field => $max ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = mb_substr( sanitize_text_field( $data[ $field ] ), 0, $max );
				$format[]         = '%s';
			}
		}

		if ( array_key_exists( 'ship_address', $data ) ) {
			$fields['ship_address'] = sanitize_textarea_field( $data['ship_address'] );
			$format[]               = '%s';
		}

		if ( array_key_exists( 'message_override', $data ) ) {
			$fields['message_override'] = CardsCo_Message::clean_text( $data['message_override'] );
			$format[]                   = '%s';
		}

		foreach ( array( 'card_id', 'message_id', 'box_arrive_day', 'days_before', 'setup_step' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = absint( $data[ $field ] );
				$format[]         = '%d';
			}
		}

		foreach ( array( 'weekend_rule', 'notify_before_box' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = $data[ $field ] ? 1 : 0;
				$format[]         = '%d';
			}
		}

		if ( array_key_exists( 'shipping_mode', $data ) ) {
			$fields['shipping_mode'] = in_array( $data['shipping_mode'], array( 'company', 'individual' ), true )
				? $data['shipping_mode']
				: 'company';
			$format[]                = '%s';
		}

		if ( array_key_exists( 'timing_mode', $data ) ) {
			$fields['timing_mode'] = in_array( $data['timing_mode'], array( 'monthly_box', 'days_before' ), true )
				? $data['timing_mode']
				: 'monthly_box';
			$format[]              = '%s';
		}

		if ( array_key_exists( 'ship_country', $data ) ) {
			$country                 = strtoupper( substr( (string) $data['ship_country'], 0, 2 ) );
			$fields['ship_country']  = $country ? $country : 'GB';
			$format[]                = '%s';
		}

		if ( array_key_exists( 'recurrence', $data ) ) {
			$fields['recurrence'] = in_array( $data['recurrence'], array( 'annual', 'once' ), true ) ? $data['recurrence'] : 'annual';
			$format[]             = '%s';
		}

		if ( array_key_exists( 'starts_on', $data ) ) {
			$starts                = trim( (string) $data['starts_on'] );
			$fields['starts_on']   = ( $starts && CardsCo_Validator::date( $starts ) ) ? $starts : null;
			$format[]              = '%s';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$fields['status'] = in_array( $data['status'], array( 'draft', 'active', 'paused', 'finished' ), true ) ? $data['status'] : 'draft';
			$format[]         = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = cardsco_now();
		$format[]             = '%s';

		$wpdb->update( cardsco_table( 'programmes' ), $fields, array( 'id' => $programme_id ), $format, array( '%d' ) );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * People
	 * ------------------------------------------------------------------ */

	/**
	 * Put people into a programme.
	 *
	 * @param int   $programme_id Programme ID.
	 * @param int[] $recipient_ids Recipient IDs.
	 * @return int Number added.
	 */
	public static function add_recipients( $programme_id, array $recipient_ids ) {
		global $wpdb;

		$programme = self::get( $programme_id );

		if ( ! $programme ) {
			return 0;
		}

		$recipient_ids = array_filter( array_unique( array_map( 'absint', $recipient_ids ) ) );

		if ( ! $recipient_ids ) {
			return 0;
		}

		$table  = cardsco_table( 'programme_recipients' );
		$now    = cardsco_now();
		$values = array();
		$params = array();

		foreach ( $recipient_ids as $recipient_id ) {
			$values[] = '(%d, %d, %d, %s)';
			$params[] = (int) $programme['id'];
			$params[] = (int) $programme['company_id'];
			$params[] = $recipient_id;
			$params[] = $now;
		}

		$sql = "INSERT IGNORE INTO {$table} (programme_id, company_id, recipient_id, created_at) VALUES " . implode( ', ', $values );

		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Take someone out of a programme.
	 *
	 * @param int   $programme_id Programme ID.
	 * @param int[] $recipient_ids Recipient IDs.
	 * @return int Number removed.
	 */
	public static function remove_recipients( $programme_id, array $recipient_ids ) {
		global $wpdb;

		$programme_id  = absint( $programme_id );
		$recipient_ids = array_filter( array_unique( array_map( 'absint', $recipient_ids ) ) );

		if ( ! $programme_id || ! $recipient_ids ) {
			return 0;
		}

		$table        = cardsco_table( 'programme_recipients' );
		$placeholders = implode( ',', array_fill( 0, count( $recipient_ids ), '%d' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE programme_id = %d AND recipient_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $programme_id ), $recipient_ids )
			)
		);
	}

	/**
	 * Recipient IDs in a programme.
	 *
	 * @param int $programme_id Programme ID.
	 * @return int[]
	 */
	public static function recipient_ids( $programme_id ) {
		global $wpdb;

		$table = cardsco_table( 'programme_recipients' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT recipient_id FROM {$table} WHERE programme_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $programme_id )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How the people in a programme look: how many are ready, and how many
	 * are still missing the date the programme runs on.
	 *
	 * @param array $programme Programme row.
	 * @return array{total:int, ready:int, missing_date:int, missing_address:int}
	 */
	public static function people_summary( array $programme ) {
		global $wpdb;

		$type = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );

		$link       = cardsco_table( 'programme_recipients' );
		$recipients = cardsco_table( 'recipients' );
		$occasions  = cardsco_table( 'occasions' );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$link} l
				 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
				 WHERE l.programme_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $programme['id']
			)
		);

		$with_date = 0;

		if ( $type ) {
			$with_date = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$link} l
					 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
					 INNER JOIN {$occasions} o ON o.recipient_id = l.recipient_id
					   AND o.occasion_type_id = %d AND o.is_active = 1
					 WHERE l.programme_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $type['id'],
					(int) $programme['id']
				)
			);
		}

		// Addresses only matter when each card is posted to the person.
		$missing_address = 0;

		if ( 'individual' === $programme['shipping_mode'] ) {
			$missing_address = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$link} l
					 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
					 WHERE l.programme_id = %d AND r.address_status <> 'complete'", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $programme['id']
				)
			);
		}

		return array(
			'total'           => $total,
			'ready'           => $type ? $with_date : $total,
			'missing_date'    => $type ? max( 0, $total - $with_date ) : 0,
			'missing_address' => $missing_address,
		);
	}

	/* ---------------------------------------------------------------------
	 * Completeness
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a step has everything it needs.
	 *
	 * @param array $programme Programme row.
	 * @param int   $step Step number.
	 * @return bool
	 */
	public static function step_complete( array $programme, $step ) {
		switch ( (int) $step ) {
			case 1:
				return (bool) CardsCo_Category::get( $programme['category'] );

			case 2:
				$people = self::people_summary( $programme );
				return $people['ready'] > 0;

			case 3:
				return self::post_is_usable( (int) $programme['card_id'], CardsCo_Card::POST_TYPE );

			case 4:
				return self::post_is_usable( (int) $programme['message_id'], CardsCo_Message::POST_TYPE )
					|| '' !== trim( (string) $programme['message_override'] );

			case 5:
				if ( 'individual' === $programme['shipping_mode'] ) {
					return true;
				}

				return 'complete' === cardsco_address_status( $programme['ship_address'], $programme['ship_postcode'] );

			case 6:
				return (bool) $programme['starts_on'];

			case 7:
				return true === self::can_activate( $programme );
		}

		return false;
	}

	/**
	 * Whether a post exists and is published.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type Post type.
	 * @return bool
	 */
	protected static function post_is_usable( $post_id, $type ) {
		if ( ! $post_id ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post && $type === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Everything that is stopping a programme being turned on.
	 *
	 * @param array $programme Programme row.
	 * @return true|WP_Error
	 */
	public static function can_activate( array $programme ) {
		$errors = new WP_Error();

		// Some categories are sent as a campaign on a day somebody chooses,
		// not off a date each person holds. There is no date for the scheduler
		// to act on, so switching one on here would look like it worked and
		// then quietly never send a card. Send them to Campaigns instead.
		if ( ! CardsCo_Category::is_dated( $programme['category'], (int) $programme['company_id'] ) ) {
			$errors->add(
				'cardsco_needs_campaigns',
				__( 'This one is sent as a campaign on a day you choose, not from dates people hold, so it is set up under Campaigns rather than here.', 'cardsco' )
			);

			return $errors;
		}

		for ( $step = 1; $step <= 6; $step++ ) {
			if ( self::step_complete( $programme, $step ) ) {
				continue;
			}

			$steps = self::steps();

			$errors->add(
				'cardsco_step_' . $step,
				sprintf(
					/* translators: 1: step number, 2: step name. */
					__( 'Step %1$d, %2$s, is not finished.', 'cardsco' ),
					$step,
					$steps[ $step ]['label']
				)
			);
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Turn a programme on.
	 *
	 * @param int $programme_id Programme ID.
	 * @return true|WP_Error
	 */
	public static function activate( $programme_id ) {
		$programme = self::get( $programme_id );

		if ( ! $programme ) {
			return new WP_Error( 'cardsco_programme_missing', __( 'That occasion could not be found.', 'cardsco' ) );
		}

		$ready = self::can_activate( $programme );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		self::update( $programme_id, array( 'status' => 'active' ) );

		cardsco_log(
			array(
				'company_id'  => (int) $programme['company_id'],
				'object_type' => 'programme',
				'object_id'   => (int) $programme_id,
				'action'      => 'activated',
				/* translators: %s: programme name. */
				'summary'     => sprintf( __( '%s turned on', 'cardsco' ), $programme['name'] ),
			)
		);

		return true;
	}

	/* ---------------------------------------------------------------------
	 * What it will cost
	 * ------------------------------------------------------------------ */

	/**
	 * The figures the review step shows.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	public static function estimate( array $programme ) {
		$people = self::people_summary( $programme );
		$price  = (float) ( $programme['unit_price'] ?? CardsCo_Settings::get( 'price_default', null, (int) $programme['company_id'] ) );

		$next_period = self::next_period( $programme );
		$next_count  = self::count_in_period( $programme, $next_period );

		return array(
			'unit_price'   => number_format( $price, 2, '.', '' ),
			'currency'     => $programme['currency'] ? $programme['currency'] : CardsCo_Settings::get( 'currency' ),
			'people'       => $people['total'],
			'per_year'     => $people['ready'],
			'yearly_total' => number_format( $people['ready'] * $price, 2, '.', '' ),
			'next_period'  => $next_period,
			'next_count'   => $next_count,
			'next_total'   => number_format( $next_count * $price, 2, '.', '' ),
			'next_arrive'  => self::box_arrive_date( $programme, $next_period ),
		);
	}

	/**
	 * The month a programme's next box covers, as YYYY-MM.
	 *
	 * @param array  $programme Programme row.
	 * @param string $from Y-m-d, defaults to today.
	 * @return string
	 */
	public static function next_period( array $programme, $from = '' ) {
		$from  = $from ? $from : cardsco_now( 'date' );
		$start = $programme['starts_on'] ? $programme['starts_on'] : $from;
		$basis = max( $from, $start );

		return gmdate( 'Y-m', strtotime( $basis ) );
	}

	/**
	 * When a box for a period should arrive: the chosen day of the month
	 * before, so the cards are in hand before the month begins.
	 *
	 * @param array  $programme Programme row.
	 * @param string $period YYYY-MM.
	 * @return string|null Y-m-d.
	 */
	public static function box_arrive_date( array $programme, $period ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $period ) ) {
			return null;
		}

		$day   = (int) $programme['box_arrive_day'];
		$day   = ( $day >= 1 && $day <= 28 ) ? $day : 25;
		$first = strtotime( $period . '-01' );

		if ( ! $first ) {
			return null;
		}

		$previous = strtotime( '-1 month', $first );

		return gmdate( 'Y-m-', $previous ) . sprintf( '%02d', $day );
	}

	/**
	 * How many cards fall in a given month for a programme.
	 *
	 * @param array  $programme Programme row.
	 * @param string $period YYYY-MM.
	 * @return int
	 */
	public static function count_in_period( array $programme, $period ) {
		global $wpdb;

		$type = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );

		if ( ! $type || ! preg_match( '/^\d{4}-(\d{2})$/', (string) $period, $m ) ) {
			return 0;
		}

		$link       = cardsco_table( 'programme_recipients' );
		$recipients = cardsco_table( 'recipients' );
		$occasions  = cardsco_table( 'occasions' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$link} l
				 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
				 INNER JOIN {$occasions} o ON o.recipient_id = l.recipient_id
				   AND o.occasion_type_id = %d AND o.is_active = 1
				 WHERE l.programme_id = %d AND o.event_month = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $type['id'],
				(int) $programme['id'],
				(int) $m[1]
			)
		);
	}
}
