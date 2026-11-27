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

		/*
		 * $fields and $format run in step, by position. A column that is
		 * already in the list keeps its place and takes the new value; a
		 * new one goes on the end of both.
		 */
		$put = static function ( $column, $value, $as ) use ( &$fields, &$format ) {
			if ( ! array_key_exists( $column, $fields ) ) {
				$format[] = $as;
			}

			$fields[ $column ] = $value;
		};

		/*
		 * Several designs. card_id is always the first of them, so every
		 * screen that shows "the card" — a cover, a rail, a receipt — goes
		 * on working without knowing there can be more than one.
		 *
		 * Two ways in. The card step sends the whole list. Anything older
		 * — the admin's own screen, an import — sends a bare card_id, and
		 * on an occasion with several designs that changes the first of
		 * them rather than throwing the rest away.
		 */
		$redeal_designs = false;

		if ( array_key_exists( 'designs', $data ) ) {
			$designs = CardsCo_Mix::clean_designs( $data['designs'] );

			/* An empty list is somebody unticking every design: no card. */
			$put( 'card_id', $designs ? (int) $designs[0]['card'] : 0, '%d' );
			$put( 'designs', count( $designs ) > 1 ? wp_json_encode( $designs ) : null, '%s' );

			$redeal_designs = true;
		} elseif ( array_key_exists( 'card_id', $data ) ) {
			$stored = CardsCo_Mix::clean_designs( (string) ( $existing['designs'] ?? '' ) );

			if ( count( $stored ) > 1 && absint( $data['card_id'] ) ) {
				$stored[0]['card'] = absint( $data['card_id'] );
				$stored            = CardsCo_Mix::clean_designs( $stored );

				$put( 'designs', count( $stored ) > 1 ? wp_json_encode( $stored ) : null, '%s' );
			}

			$redeal_designs = absint( $data['card_id'] ) !== (int) $existing['card_id'];
		}

		/*
		 * Several messages, the same way: message_override is always the
		 * first, and a bare message_override from an older screen changes
		 * the first and leaves the others be.
		 */
		$redeal_messages = false;

		if ( array_key_exists( 'messages', $data ) ) {
			$messages = CardsCo_Mix::clean_messages( $data['messages'] );

			if ( $messages ) {
				$put( 'message_override', $messages[0], '%s' );
			}

			$put( 'messages', count( $messages ) > 1 ? wp_json_encode( $messages ) : null, '%s' );

			$redeal_messages = true;
		} elseif ( array_key_exists( 'message_override', $data ) ) {
			$stored = CardsCo_Mix::clean_messages( (string) ( $existing['messages'] ?? '' ) );

			if ( count( $stored ) > 1 ) {
				if ( '' === trim( (string) $fields['message_override'] ) ) {
					array_shift( $stored );
				} else {
					$stored[0] = $fields['message_override'];
				}

				$stored = CardsCo_Mix::clean_messages( $stored );

				if ( $stored ) {
					$put( 'message_override', $stored[0], '%s' );
				}

				$put( 'messages', count( $stored ) > 1 ? wp_json_encode( $stored ) : null, '%s' );

				$redeal_messages = true;
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

			/* Not saved for an occasion that cannot have one, whatever was posted. */
			if ( 'monthly_box' === $fields['timing_mode'] && $existing ) {
				$fields['timing_mode'] = self::timing_mode( array_merge( $existing, array( 'timing_mode' => 'monthly_box' ) ) );
			}
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

		if ( array_key_exists( 'paid_rate', $data ) ) {
			$fields['paid_rate'] = ( null === $data['paid_rate'] || (float) $data['paid_rate'] <= 0 )
				? null
				: number_format( (float) $data['paid_rate'], 2, '.', '' );
			$format[]            = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = cardsco_now();
		$format[]             = '%s';

		$wpdb->update( cardsco_table( 'programmes' ), $fields, array( 'id' => $programme_id ), $format, array( '%d' ) );

		/*
		 * Who gets which design, and which message, was worked out against
		 * the list as it stood. Change the list and those answers are
		 * about something that no longer exists, so they are cleared and
		 * dealt again the next time they are needed. A card already with
		 * the writers keeps what it was queued with.
		 */
		if ( $redeal_designs ) {
			$wpdb->update( cardsco_table( 'programme_recipients' ), array( 'card_id' => 0 ), array( 'programme_id' => $programme_id ), array( '%d' ), array( '%d' ) );
		}

		if ( $redeal_messages ) {
			$wpdb->update( cardsco_table( 'programme_recipients' ), array( 'message_no' => 0 ), array( 'programme_id' => $programme_id ), array( '%d' ), array( '%d' ) );
		}

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

		if ( 'individual' === ( $programme['shipping_mode'] ?? '' ) ) {
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
	 * Several designs, several messages, and a note for one person
	 * ------------------------------------------------------------------ */

	/**
	 * The designs an occasion sends, in the order they were chosen.
	 *
	 * One entry for the ordinary occasion with a single card, and none
	 * before a card has been picked.
	 *
	 * @param array $programme Programme row.
	 * @return array<int, array{card:int, qty:int}>
	 */
	public static function designs( array $programme ) {
		$designs = CardsCo_Mix::clean_designs( (string) ( $programme['designs'] ?? '' ) );

		if ( count( $designs ) > 1 ) {
			return $designs;
		}

		$card = (int) ( $programme['card_id'] ?? 0 );

		return $card
			? array(
				array(
					'card' => $card,
					'qty'  => 0,
				),
			)
			: array();
	}

	/**
	 * The messages an occasion sends, in the order they were written.
	 *
	 * @param array $programme Programme row.
	 * @return string[]
	 */
	public static function messages( array $programme ) {
		$messages = CardsCo_Mix::clean_messages( (string) ( $programme['messages'] ?? '' ) );

		if ( count( $messages ) > 1 ) {
			return $messages;
		}

		// Wording typed into the setup wins over a saved message, because
		// that is the box the company actually filled in.
		$text = trim( (string) ( $programme['message_override'] ?? '' ) );

		if ( '' === $text && ! empty( $programme['message_id'] ) ) {
			$saved = get_post( (int) $programme['message_id'] );

			if ( $saved && 'publish' === $saved->post_status ) {
				$text = trim( (string) CardsCo_Message::text( $saved->ID ) );
			}
		}

		return '' === $text ? array() : array( $text );
	}

	/**
	 * How many designs this occasion may carry at its present size.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return int
	 */
	public static function design_limit( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		return CardsCo_Mix::design_limit( (int) $quantity, self::one_address( $programme ), (int) $programme['company_id'] );
	}

	/**
	 * How many messages this occasion may carry at its present size.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return int
	 */
	public static function message_limit( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		return CardsCo_Mix::message_limit(
			(int) $quantity,
			self::one_address( $programme ),
			(string) $programme['category'],
			(int) $programme['company_id']
		);
	}

	/**
	 * How many cards of each design this occasion comes to.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return array<int, int> Card id => how many.
	 */
	public static function allocation( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		return CardsCo_Mix::split( self::designs( $programme ), (int) $quantity );
	}

	/**
	 * The link rows of everybody who gets a card.
	 *
	 * The same people people_summary() counts as ready: on the occasion,
	 * active, and holding the date it runs on.
	 *
	 * @param array $programme Programme row.
	 * @return array[] id, recipient_id, card_id, message_no, note.
	 */
	public static function ready_links( array $programme ) {
		global $wpdb;

		$type       = CardsCo_Category::primary_type( $programme['category'], (int) $programme['company_id'] );
		$link       = cardsco_table( 'programme_recipients' );
		$recipients = cardsco_table( 'recipients' );
		$occasions  = cardsco_table( 'occasions' );

		if ( $type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.recipient_id, l.card_id, l.message_no, l.note FROM {$link} l
					 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
					 INNER JOIN {$occasions} o ON o.recipient_id = l.recipient_id
					   AND o.occasion_type_id = %d AND o.is_active = 1
					 WHERE l.programme_id = %d
					 ORDER BY l.recipient_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $type['id'],
					(int) $programme['id']
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.recipient_id, l.card_id, l.message_no, l.note FROM {$link} l
					 INNER JOIN {$recipients} r ON r.id = l.recipient_id AND r.status = 'active'
					 WHERE l.programme_id = %d
					 ORDER BY l.recipient_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
					(int) $programme['id']
				),
				ARRAY_A
			);
		}

		return (array) $rows;
	}

	/**
	 * Decide who gets which design and which message, and write it down.
	 *
	 * Only people who have not been given one yet, so it is safe to run as
	 * often as anything asks: before a box is filled, when an occasion is
	 * turned on, after somebody joins. Whoever already has a design keeps
	 * it; a newcomer goes to whichever design is furthest short of its
	 * share, and gets whichever message has been used least.
	 *
	 * Written down rather than worked out afresh each time, because the
	 * cards for one occasion are queued a month at a time across a year.
	 * Somebody joining in March must not change which design the people
	 * with April birthdays were already down for.
	 *
	 * An occasion with one design and one message has nothing to decide,
	 * and this does nothing at all for it.
	 *
	 * @param array $programme Programme row.
	 * @return int How many people were given something.
	 */
	public static function assign( array $programme ) {
		global $wpdb;

		$designs  = self::designs( $programme );
		$messages = self::messages( $programme );

		if ( count( $designs ) < 2 && count( $messages ) < 2 ) {
			return 0;
		}

		$rows = self::ready_links( $programme );

		if ( ! $rows ) {
			return 0;
		}

		$table  = cardsco_table( 'programme_recipients' );
		$change = array();

		if ( count( $designs ) > 1 ) {
			$targets = CardsCo_Mix::split( $designs, count( $rows ) );
			$have    = array_fill_keys( array_keys( $targets ), 0 );
			$waiting = array();

			foreach ( $rows as $row ) {
				$card = (int) $row['card_id'];

				if ( $card && isset( $have[ $card ] ) ) {
					$have[ $card ]++;
				} else {
					$waiting[] = (int) $row['recipient_id'];
				}
			}

			$dealt = CardsCo_Mix::deal_designs(
				CardsCo_Mix::shuffled( $waiting, 'design:' . (int) $programme['id'] ),
				$targets,
				$have
			);

			foreach ( $dealt as $recipient_id => $card ) {
				$change[ $recipient_id ]['card_id'] = (int) $card;
			}
		}

		if ( count( $messages ) > 1 ) {
			$total   = count( $messages );
			$used    = array();
			$waiting = array();

			foreach ( $rows as $row ) {
				$no = (int) $row['message_no'];

				if ( $no >= 1 && $no <= $total ) {
					$used[ $no ] = ( $used[ $no ] ?? 0 ) + 1;
				} else {
					$waiting[] = (int) $row['recipient_id'];
				}
			}

			$dealt = CardsCo_Mix::deal_messages(
				CardsCo_Mix::shuffled( $waiting, 'message:' . (int) $programme['id'] ),
				$total,
				$used
			);

			foreach ( $dealt as $recipient_id => $no ) {
				$change[ $recipient_id ]['message_no'] = (int) $no;
			}
		}

		foreach ( $change as $recipient_id => $fields ) {
			$wpdb->update(
				$table,
				$fields,
				array(
					'programme_id' => (int) $programme['id'],
					'recipient_id' => (int) $recipient_id,
				),
				array_fill( 0, count( $fields ), '%d' ),
				array( '%d', '%d' )
			);
		}

		return count( $change );
	}

	/**
	 * The personal notes on an occasion.
	 *
	 * @param int $programme_id Programme ID.
	 * @return array<int, string> Recipient id => note, only where there is one.
	 */
	public static function notes( $programme_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT recipient_id, note FROM ' . cardsco_table( 'programme_recipients' ) . " WHERE programme_id = %d AND note <> ''", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $programme_id )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['recipient_id'] ] = (string) $row['note'];
		}

		return $out;
	}

	/**
	 * Save one person's note. An empty note takes it away.
	 *
	 * Only for somebody actually on the occasion: the update is keyed on
	 * both, so an id from a form cannot write a note onto anybody else.
	 *
	 * @param int    $programme_id Programme ID.
	 * @param int    $recipient_id Recipient ID.
	 * @param string $note         The note.
	 * @return void
	 */
	public static function set_note( $programme_id, $recipient_id, $note ) {
		global $wpdb;

		$wpdb->update(
			cardsco_table( 'programme_recipients' ),
			array( 'note' => CardsCo_Mix::clean_note( $note ) ),
			array(
				'programme_id' => absint( $programme_id ),
				'recipient_id' => absint( $recipient_id ),
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * What is wrong with the designs and messages chosen, if anything.
	 *
	 * The limits move with the order: add ten people and five designs
	 * become ten, take ten away and five become one. So this is asked
	 * again wherever the order could have changed since the choice was
	 * made, rather than only at the moment of choosing.
	 *
	 * @param array  $programme Programme row.
	 * @param string $what      'designs', 'messages', or 'all'.
	 * @return string[] One sentence per problem; empty when there is none.
	 */
	public static function mix_problems( array $programme, $what = 'all' ) {
		$problems = array();
		$quantity = (int) self::people_summary( $programme )['ready'];
		$designs  = 'messages' === $what ? array() : self::designs( $programme );
		$messages = 'designs' === $what ? array() : self::messages( $programme );

		$most = self::design_limit( $programme, $quantity );

		if ( count( $designs ) > $most ) {
			$problems[] = 1 === $most
				? sprintf(
					/* translators: %d: how many cards make a bulk order. */
					__( 'Several designs are for bulk orders — %d cards or more going to one address. This one is not, so choose a single design.', 'cardsco' ),
					(int) CardsCo_Settings::get( 'bulk_from', null, (int) $programme['company_id'] )
				)
				: sprintf(
					/* translators: 1: how many cards, 2: how many designs are allowed. */
					__( 'An order of %1$d cards can have up to %2$d designs. Take some off to carry on.', 'cardsco' ),
					$quantity,
					$most
				);
		}

		/*
		 * A number against some designs and not others. There is no good
		 * guess at what was meant, and guessing "split them evenly" would
		 * throw away the numbers that were typed.
		 */
		$numbered = 0;

		foreach ( $designs as $one ) {
			if ( (int) $one['qty'] > 0 ) {
				$numbered++;
			}
		}

		if ( count( $designs ) > 1 && $numbered > 0 && $numbered < count( $designs ) ) {
			$problems[] = __( 'Give every design a number of cards, or leave them all blank and we will split the cards evenly.', 'cardsco' );
		}

		if ( count( $designs ) > 1 && CardsCo_Mix::is_explicit( $designs ) ) {
			$asked = 0;

			foreach ( $designs as $one ) {
				$asked += (int) $one['qty'];
			}

			if ( $asked !== $quantity ) {
				$problems[] = sprintf(
					/* translators: 1: how many cards were shared out, 2: how many there are. */
					__( 'The designs add up to %1$d cards and there are %2$d to share out. Change the numbers, or clear them and we will split the cards evenly.', 'cardsco' ),
					$asked,
					$quantity
				);
			}
		}

		$most = self::message_limit( $programme, $quantity );

		if ( count( $messages ) > $most ) {
			$problems[] = 1 === $most
				? __( 'This occasion carries one message. Take the others off to carry on.', 'cardsco' )
				: sprintf(
					/* translators: %d: how many messages are allowed. */
					__( 'This occasion can have up to %d messages. Take some off to carry on.', 'cardsco' ),
					$most
				);
		}

		if ( 'designs' !== $what && $messages ) {
			$problems = array_merge( $problems, self::note_problems( $programme, $messages ) );
		}

		return $problems;
	}

	/**
	 * Whether anybody's note leaves their card unwritable.
	 *
	 * A note is written after whichever message the person gets, so it
	 * has to fit after the longest of them, with their name filled in and
	 * the sign-off underneath. It is checked against every message rather
	 * than the one they have been dealt, because which one they are dealt
	 * can change and the card has to fit whichever it turns out to be.
	 *
	 * Asked whenever the message step is, so it holds both ways round: a
	 * note that fitted stops fitting when a message is made longer, and
	 * that is caught on the message's save as well as on the note's.
	 *
	 * @param array    $programme Programme row.
	 * @param string[] $messages  The occasion's messages.
	 * @return string[]
	 */
	protected static function note_problems( array $programme, array $messages ) {
		$notes = self::notes( (int) $programme['id'] );

		if ( ! $notes ) {
			return array();
		}

		$max       = (int) CardsCo_Settings::get( 'card_max_chars', 400, (int) $programme['company_id'] );
		$signature = trim( (string) ( $programme['signature'] ?? '' ) );
		$problems  = array();

		foreach ( $notes as $recipient_id => $note ) {
			$person = CardsCo_Recipient::get( (int) $recipient_id );

			if ( ! $person ) {
				continue;
			}

			$name    = trim( $person['first_name'] . ' ' . $person['last_name'] );
			$context = CardsCo_Merge::context_for_recipient( $person );

			/* A tag in the note that comes out empty, or is not one of ours. */
			$alone = CardsCo_Merge::render( $note, $context );

			if ( ! empty( $alone['missing'] ) || ! empty( $alone['unknown'] ) ) {
				$problems[] = sprintf(
					/* translators: %s: a person's name. */
					__( 'The note for %s has a {tag} in it that we cannot fill in. Write the words out instead.', 'cardsco' ),
					$name
				);

				continue;
			}

			if ( $max < 1 ) {
				continue;
			}

			$longest = 0;

			foreach ( $messages as $text ) {
				$try  = CardsCo_Merge::render( CardsCo_Mix::with_note( $text, $note ), $context );
				$used = mb_strlen( (string) $try['text'] . ( $signature ? "\n" . $signature : '' ) );

				$longest = max( $longest, $used );
			}

			if ( $longest > $max ) {
				$problems[] = sprintf(
					/* translators: 1: a person's name, 2: characters used, 3: the limit. */
					__( 'With the note for %1$s their card comes to %2$s characters, and a card holds %3$s. Shorten the note, or the message.', 'cardsco' ),
					$name,
					number_format_i18n( $longest ),
					number_format_i18n( $max )
				);
			}
		}

		return $problems;
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
				/*
				 * Every design, not only the first, and no more of them
				 * than an order this size may carry. The allowance moves
				 * with the order — five designs chosen for thirty people
				 * are four too many once ten of those people are taken off
				 * — so this step can come unfinished after it was done.
				 */
				$designs = self::designs( $programme );

				if ( ! $designs ) {
					return false;
				}

				foreach ( $designs as $one ) {
					if ( ! self::post_is_usable( (int) $one['card'], CardsCo_Card::POST_TYPE ) ) {
						return false;
					}
				}

				return ! self::mix_problems( $programme, 'designs' );

			case 4:
				if ( ! self::messages( $programme ) ) {
					return false;
				}

				return ! self::mix_problems( $programme, 'messages' );

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
	 * Whether "one box a month" is a way this occasion can be delivered.
	 *
	 * A monthly box is for dates that come round every year and fall all
	 * through it: birthdays, work anniversaries. Each month's cards arrive
	 * together for somebody to hand out on the day.
	 *
	 * An occasion that happens once for each person is not that. A thank
	 * you, a welcome to a new customer, a card for somebody leaving: each
	 * is written for its own day and sent for it, and nobody arranges to
	 * have them batched up and delivered on the 25th. Christmas and the
	 * other fixed dates, and promotions, never had the option.
	 *
	 * @param array $programme Programme row.
	 * @return bool
	 */
	public static function offers_monthly_box( array $programme ) {
		$type = CardsCo_Category::primary_type( (string) ( $programme['category'] ?? '' ), (int) ( $programme['company_id'] ?? 0 ) );

		return $type && 'once' !== (string) $type['recurrence'];
	}

	/**
	 * How this occasion's cards are actually sent.
	 *
	 * The column defaults to the monthly box, so an occasion that cannot
	 * have one — and any set up before the option was taken away from it —
	 * may still say so. Everything that acts on the mode asks here.
	 *
	 * @param array $programme Programme row.
	 * @return string monthly_box, per_date, fixed_date or one_off.
	 */
	public static function timing_mode( array $programme ) {
		$mode = (string) ( $programme['timing_mode'] ?? 'monthly_box' );

		if ( 'monthly_box' === $mode && ! self::offers_monthly_box( $programme ) && 'per_person' === CardsCo_Dashboard::timing_shape( $programme ) ) {
			return 'per_date';
		}

		return $mode;
	}

	/**
	 * Whether this occasion's cards all go to one address.
	 *
	 * Where the cards go does NOT decide the price — postage is inside
	 * the price, so thirty-four cards cost the same whether they arrive
	 * in a box at the office or in thirty-four envelopes. This is here
	 * for the screens that need to know where things are being sent, not
	 * for working out what to charge.
	 *
	 * @param array $programme Programme row.
	 * @return bool
	 */
	public static function one_address( array $programme ) {
		return 'company' === ( $programme['shipping_mode'] ?? 'company' );
	}

	/**
	 * Whether this occasion is a combination we do not sell.
	 *
	 * There are two products. Bulk is twenty-five cards or more sent to
	 * ONE address, which the customer hands out, at the lower rate.
	 * Single is a card each, posted to each named person, at the higher
	 * one. Twenty-five or more posted to individual addresses is neither:
	 * it is the volume of a bulk order with the postage of a single one,
	 * and the price list has no line for it.
	 *
	 * So it is not priced and not sold here. The customer is pointed at
	 * CardsCo to agree terms, rather than being quietly charged one rate
	 * or the other and finding out afterwards.
	 *
	 * Note what this leaves: every combination that CAN be bought is
	 * priced on quantity alone, because the only case where the
	 * destination would have changed the rate is this one, and this one
	 * does not reach a till.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return bool
	 */
	public static function needs_referral( array $programme, $quantity = null ) {
		if ( self::one_address( $programme ) ) {
			return false;
		}

		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		return CardsCo_Card::is_bulk( (int) $quantity, (int) $programme['company_id'] );
	}

	/**
	 * What to say when an occasion has to be arranged with us directly.
	 *
	 * The wording and the address in one place, because it appears on the
	 * shipping step, on the review step and at the till, and three
	 * versions of it would drift.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return array{title:string,body:string,action:string,email:string,mailto:string}
	 */
	public static function referral( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		$email    = (string) CardsCo_Settings::get( 'support_email', null, (int) $programme['company_id'] );
		$company  = CardsCo_Company::get( (int) $programme['company_id'] );
		$category = CardsCo_Category::get( (string) $programme['category'] );

		$subject = sprintf(
			/* translators: 1: how many cards, 2: the company's name. */
			__( '%1$d cards to individual addresses — %2$s', 'cardsco' ),
			(int) $quantity,
			$company ? $company['name'] : __( 'our company', 'cardsco' )
		);

		$body = sprintf(
			/* translators: 1: how many cards, 2: the occasion. */
			__( "We would like to order %1\$d cards for %2\$s, posted to each person's own address.\n\nPlease could you let us know the price and how to go ahead.", 'cardsco' ),
			(int) $quantity,
			$category ? strtolower( $category['label'] ) : __( 'our occasion', 'cardsco' )
		);

		return array(
			'title'  => __( 'This one we arrange with you directly', 'cardsco' ),
			'body'   => sprintf(
				/* translators: %d: how many cards. */
				__( 'Orders of %d or more going to individual addresses are priced case by case, so there is nothing to pay here yet. Send us the details and we will come back to you with a price.', 'cardsco' ),
				(int) CardsCo_Settings::get( 'bulk_from', null, (int) $programme['company_id'] )
			),
			'action' => __( 'Email us about this order', 'cardsco' ),
			'email'  => $email,
			'mailto' => 'mailto:' . rawurlencode( $email ) . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body ),
		);
	}

	/**
	 * Whether this occasion counts as a bulk order.
	 *
	 * Twenty-five cards or more, whatever their destination. This is what
	 * the rate hangs on, and what decides how many designs the customer
	 * may pick.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return bool
	 */
	public static function is_bulk_order( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		return CardsCo_Card::is_bulk( (int) $quantity, (int) $programme['company_id'] );
	}

	/**
	 * What one card on this programme costs, at this quantity.
	 *
	 * The single place the rate comes from, because there were three and
	 * they disagreed. The review screen read programmes.unit_price — the
	 * list price copied onto the row the day the programme was created,
	 * which knows nothing about the card that was chosen afterwards and
	 * nothing about the bulk rate. The till has always read
	 * CardsCo_Card::price_total(), which knows about both. The renewal read
	 * a third thing that knew about the card but not about bulk.
	 *
	 * Thirty-four staff and a plain card: the review screen said £3.60 a
	 * card and £122.40 for the year, the customer was charged £1.99 and
	 * £67.66, and the renewal a year later billed £122.40. The till wins,
	 * because the till is the one that takes the money.
	 *
	 * The quantity matters because the bulk rate depends on it, and the
	 * quantity that counts is the whole order — the year — not whatever
	 * happens to fall in one box. Three people in next month's box are
	 * still three cards of a bulk order and are priced as such.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards the order is for.
	 * @return float The price of one card.
	 */
	public static function rate( array $programme, $quantity ) {
		$company_id = (int) $programme['company_id'];
		$quantity   = max( 1, (int) $quantity );
		$card_id    = (int) ( $programme['card_id'] ?? 0 );

		if ( ! $card_id && ! empty( $programme['category'] ) ) {
			foreach ( (array) CardsCo_Category::cards( $programme['category'], $company_id ) as $card ) {
				$card_id = (int) $card->ID;
				break;
			}
		}

		if ( $card_id ) {
			return (float) CardsCo_Card::price_for( $card_id, $quantity, $company_id );
		}

		/*
		 * No card to price yet. The review screen still has to put a figure
		 * up, so it falls back to the list — but to the list as it stands
		 * now, not as it stood when the row was made, because that is what
		 * they will be charged.
		 */
		$list = CardsCo_Settings::get( 'price_default', null, $company_id );

		if ( null === $list || '' === $list ) {
			$list = $programme['unit_price'] ?? 0;
		}

		return (float) $list;
	}

	/**
	 * What the order comes to, a line for each design.
	 *
	 * The one place a total is worked out, for the same reason rate() is
	 * the one place a rate is: the review screen, the till and the renewal
	 * each used to do their own sum, and they disagreed.
	 *
	 * Every design is priced at the rate the WHOLE order earns. Ten cards
	 * of one design and twenty-four of another are an order of
	 * thirty-four, and all thirty-four are at the bulk rate — choosing
	 * more designs does not cost more, and must not quietly cost more by
	 * pricing each design as a small order of its own.
	 *
	 * A design that carries its own price keeps it, which is the only way
	 * two lines on one order can be at different rates.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return array[] card, qty, each (float), total (string, 2dp).
	 */
	public static function order_lines( array $programme, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) self::people_summary( $programme )['ready'];
		}

		$quantity   = max( 0, (int) $quantity );
		$company_id = (int) $programme['company_id'];
		$lines      = array();

		foreach ( self::allocation( $programme, $quantity ) as $card => $many ) {
			if ( $many < 1 ) {
				continue;
			}

			$each    = (float) CardsCo_Card::price_for( (int) $card, max( 1, $quantity ), $company_id );
			$lines[] = array(
				'card'  => (int) $card,
				'qty'   => (int) $many,
				'each'  => $each,
				'total' => number_format( $each * $many, 2, '.', '' ),
			);
		}

		/*
		 * No card chosen yet. There is still a figure to show, so it is
		 * one line at whatever rate() falls back to.
		 */
		if ( ! $lines && $quantity > 0 ) {
			$each    = self::rate( $programme, $quantity );
			$lines[] = array(
				'card'  => 0,
				'qty'   => $quantity,
				'each'  => $each,
				'total' => number_format( $each * $quantity, 2, '.', '' ),
			);
		}

		return $lines;
	}

	/**
	 * What the order comes to altogether.
	 *
	 * @param array $programme Programme row.
	 * @param int   $quantity  How many cards, or null to count them.
	 * @return string Two decimal places.
	 */
	public static function order_total( array $programme, $quantity = null ) {
		$total = 0.0;

		foreach ( self::order_lines( $programme, $quantity ) as $line ) {
			$total += (float) $line['total'];
		}

		return number_format( $total, 2, '.', '' );
	}

	/**
	 * The figures the review step shows.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	public static function estimate( array $programme ) {
		$people = self::people_summary( $programme );
		$price  = self::rate( $programme, (int) $people['ready'] );
		$year   = self::order_total( $programme, (int) $people['ready'] );

		/* The box they will actually be charged for next, not the one that
		   shipped three weeks ago. */
		$next_period = self::next_box_period( $programme );
		$next_count  = self::count_in_period( $programme, $next_period );

		return array(
			'unit_price'   => number_format( $price, 2, '.', '' ),
			'currency'     => $programme['currency'] ? $programme['currency'] : CardsCo_Settings::get( 'currency' ),
			'people'       => $people['total'],
			'per_year'     => $people['ready'],
			'yearly_total' => $year,
			'next_period'  => $next_period,
			'next_count'   => $next_count,
			/*
			 * The box's share of the year. With one rate across the order
			 * — which is every order unless a design carries a price of
			 * its own — this is the count at that rate, to the penny.
			 */
			'next_total'   => $people['ready'] > 0
				? number_format( (float) $year * $next_count / $people['ready'], 2, '.', '' )
				: number_format( 0, 2, '.', '' ),
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
