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

		/*
		 * Christmas and a promotion go out on a day somebody chooses, not
		 * from dates people hold, so they are built in Campaigns and not
		 * here. The wizard's tile says so and sends people there, and the
		 * radio behind it is disabled — but that was the whole of it. An
		 * address typed by hand, or a stale form, made a programme that can
		 * never send a card: nothing it can read a date from.
		 *
		 * The occasion-types check mirrors the tile's. On an install that
		 * has never been seeded every category reads as campaign-driven,
		 * and refusing them all would leave nothing that can be set up at
		 * all — a worse failure than the one being fixed.
		 */
		if (
			CardsCo_Occasion_Type::all( $company_id )
			&& ! CardsCo_Category::is_dated( $category, $company_id )
		) {
			return new WP_Error(
				'cardsco_programme_campaign_only',
				/* translators: %s: occasion name. */
				sprintf( __( '%s is set up as a campaign, not as an occasion.', 'cardsco' ), $definition['label'] )
			);
		}

		$row = array(
			'company_id'  => $company_id,
			'category'    => $category,
			'name'        => $definition['label'],
			'status'      => 'draft',
			/*
			 * 2, not 1. setup_step is the furthest step reached, and a
			 * programme only exists once an occasion has been chosen — which
			 * is step 1, done.
			 *
			 * Left at 1, step_on_screen() clamped every request to the
			 * furthest reached and pulled the wizard straight back to step 1,
			 * so choosing an occasion appeared to do nothing at all. Every
			 * other step gets this right because save_step() raises the
			 * number as it goes; creation was the one that did not.
			 */
			'setup_step'  => 2,
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
	/**
	 * A company's unfinished draft for one occasion, if there is one.
	 *
	 * Without this, choosing the same occasion twice made a second draft and
	 * left the first one stranded: nothing on the site lists drafts, so the
	 * work simply disappeared. Resuming the existing row is what "save and
	 * exit" has always implied.
	 *
	 * @param int    $company_id Company.
	 * @param string $category   Occasion category slug.
	 * @return array|null
	 */
	public static function draft_for( $company_id, $category ) {
		global $wpdb;

		$table = cardsco_table( 'programmes' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE company_id = %d AND category = %s AND status = 'draft' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				(string) $category
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

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
		/* 0 means every company, for the admin's listing screens. */
		$company_id = absint( $company_id );
		$sql        = $company_id ? "SELECT * FROM {$table} WHERE company_id = %d" : "SELECT * FROM {$table} WHERE 1 = %d";
		$params     = array( $company_id ? $company_id : 1 );

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

		foreach ( array( 'card_id', 'message_id', 'occasion_type_id', 'box_arrive_day', 'days_before', 'setup_step' ) as $field ) {
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
			$mode = (string) $data['timing_mode'];

			/*
			 * "days_before" was never a mode the screen could produce — step
			 * 6 has always sent "per_date" — so choosing "a few days before
			 * each birthday" failed this check and was silently saved as the
			 * monthly box. The name it actually sends is accepted now, and
			 * the old spelling is read as the same thing rather than thrown
			 * away, in case a row was written with it.
			 */
			if ( 'days_before' === $mode ) {
				$mode = 'per_date';
			}

			$fields['timing_mode'] = in_array( $mode, array( 'monthly_box', 'per_date', 'fixed_date', 'one_off' ), true )
				? $mode
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

		if ( array_key_exists( 'renews_on', $data ) ) {
			$renews              = trim( (string) $data['renews_on'] );
			$fields['renews_on'] = ( $renews && CardsCo_Validator::date( $renews ) ) ? $renews : null;
			$format[]            = '%s';
		}

		if ( array_key_exists( 'renewal_notices', $data ) ) {
			$fields['renewal_notices'] = min( 9, absint( $data['renewal_notices'] ) );
			$format[]                  = '%d';
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

		$added = (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		/*
		 * Somebody taken off the list had their occasion switched off.
		 * Putting them back puts the card back with them, using the date
		 * they already gave us.
		 */
		self::set_occasions_active( (int) $programme['id'], $recipient_ids, 1 );

		return $added;
	}

	/**
	 * People the company already has who are not on this programme yet.
	 *
	 * A company that has uploaded its list once should not have to upload
	 * it again for the next occasion. Step 2 offered a spreadsheet or
	 * typing names in by hand, and nothing else, so the people already
	 * sitting in the account were invisible to it.
	 *
	 * Only those the occasion is actually for: a customer welcome does
	 * not offer the warehouse staff.
	 *
	 * @param array $programme  Programme row.
	 * @param int   $limit      Most to return.
	 * @return array[] Recipient rows, name order.
	 */
	public static function available_recipients( array $programme, $limit = 500 ) {
		global $wpdb;

		$company_id = (int) $programme['company_id'];

		if ( ! $company_id ) {
			return array();
		}

		$table = cardsco_table( 'recipients' );
		$link  = cardsco_table( 'programme_recipients' );
		$where = array( 'r.company_id = %d', "r.status = 'active'" );
		$args  = array( $company_id );

		/* Who this occasion is for, when it is for one kind of person. */
		$type = CardsCo_Category::primary_type( (string) $programme['category'], $company_id );

		if ( $type && in_array( (string) $type['applies_to'], array( 'employee', 'customer' ), true ) ) {
			$where[] = 'r.type = %s';
			$args[]  = (string) $type['applies_to'];
		}

		if ( $programme['id'] ) {
			$where[] = "r.id NOT IN ( SELECT recipient_id FROM {$link} WHERE programme_id = %d )";
			$args[]  = (int) $programme['id'];
		}

		$args[] = absint( $limit );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM {$table} r WHERE " . implode( ' AND ', $where ) // phpcs:ignore WordPress.DB
				. ' ORDER BY r.first_name ASC, r.last_name ASC LIMIT %d',
				$args
			),
			ARRAY_A
		);
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

		$gone = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE programme_id = %d AND recipient_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $programme_id ), $recipient_ids )
			)
		);

		/*
		 * And stop the card.
		 *
		 * The scheduler reads the occasions table and never looks at this
		 * link, so taking somebody off the list on its own left their
		 * occasion live and their card still coming. Switched off rather
		 * than deleted, because the row holds the date they gave us and
		 * putting them back should not mean asking for it again.
		 */
		self::set_occasions_active( $programme_id, $recipient_ids, 0 );

		return $gone;
	}

	/**
	 * Switch these people's occasions on or off for one programme.
	 *
	 * @param int   $programme_id  Programme ID.
	 * @param int[] $recipient_ids Recipient IDs.
	 * @param int   $active        1 to switch on, 0 to switch off.
	 * @return int Rows changed.
	 */
	public static function set_occasions_active( $programme_id, array $recipient_ids, $active ) {
		global $wpdb;

		$programme = self::get( absint( $programme_id ) );

		if ( ! $programme ) {
			return 0;
		}

		$type_id = (int) $programme['occasion_type_id'];

		if ( ! $type_id ) {
			$type    = CardsCo_Category::primary_type( (string) $programme['category'], (int) $programme['company_id'] );
			$type_id = $type ? (int) $type['id'] : 0;
		}

		$recipient_ids = array_filter( array_unique( array_map( 'absint', $recipient_ids ) ) );

		if ( ! $type_id || ! $recipient_ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $recipient_ids ), '%d' ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . cardsco_table( 'occasions' ) . " SET is_active = %d, updated_at = %s
				 WHERE occasion_type_id = %d AND recipient_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $active ? 1 : 0, cardsco_now(), $type_id ), $recipient_ids )
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
				/*
				 * Step 6 is three different screens, so ask for whatever the
				 * screen in front of the person actually offered. The
				 * fixed-date screen carries no start date — the date is the
				 * occasion's own, held on the occasion type — so asking for
				 * one here left the step permanently unfinished and the
				 * wizard with no way forward. What marks that screen as
				 * answered is the mode it writes, since the column defaults
				 * to the per-person mode and never to this one.
				 */
				if ( class_exists( 'CardsCo_Dashboard' )
					&& 'fixed' === CardsCo_Dashboard::timing_shape( $programme ) ) {
					return 'fixed_date' === (string) $programme['timing_mode'];
				}

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

		/* The box they will actually be charged for next, not the one that
		   shipped three weeks ago. */
		$next_period = self::next_box_period( $programme );
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
	 * The next period whose box has not already gone out.
	 *
	 * next_period() answers a different question — which month the scheduler
	 * should start building from — and that has to stay the current month,
	 * because this month's cards are still being written even once the box
	 * has shipped. Narrowing it would skip a month of boxes.
	 *
	 * The screens want the other answer. A box for November arrives on the
	 * 25th of October, so from the 26th of October onwards "Next box posts
	 * Sun 25 Oct" is a date in the past, on every occasion, every month.
	 * This walks forward to the first box still to come.
	 *
	 * Display only. Nothing queues or charges from it.
	 *
	 * @param array  $programme Programme row.
	 * @param string $today Y-m-d, for testing.
	 * @return string YYYY-MM.
	 */
	public static function next_box_period( array $programme, $today = '' ) {
		$today  = $today ? $today : cardsco_now( 'date' );
		$period = self::next_period( $programme, $today );

		/*
		 * Twelve at most. A box date that can never be in the future would
		 * otherwise spin here, and a year is further ahead than any screen
		 * showing this has a reason to look.
		 */
		for ( $i = 0; $i < 12; $i++ ) {
			$arrives = self::box_arrive_date( $programme, $period );

			if ( ! $arrives || $arrives >= $today ) {
				return $period;
			}

			$stamp = strtotime( $period . '-01 +1 month' );

			if ( ! $stamp ) {
				return $period;
			}

			$period = gmdate( 'Y-m', $stamp );
		}

		return $period;
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
