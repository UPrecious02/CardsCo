<?php
/**
 * Campaigns: one send, on a day somebody chooses.
 *
 * An occasion programme runs itself off dates people hold — birthdays come
 * round whether anybody is watching or not. A campaign is the opposite: a
 * company decides to send a card to a group of people on a particular day, and
 * once it has gone, it has gone.
 *
 * This is what sits behind the Promotions & Offers category and behind a
 * seasonal send such as Christmas, where everybody gets a card on the same day
 * rather than on their own date.
 *
 * The important rule is the lock. A campaign can be edited right up until a
 * few days before it sends, and then it freezes, because by that point the
 * cards are being written by hand and changing the wording is no longer a
 * thing a database can do.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Campaign {

	/**
	 * The builder's steps, in the order they are asked.
	 *
	 * @return array<int, array{key:string, label:string, heading:string}>
	 */
	public static function steps() {
		return array(
			1 => array(
				'key'     => 'occasion',
				'label'   => __( 'Occasion', 'cardsco' ),
				'heading' => __( 'What is this card for?', 'cardsco' ),
			),
			2 => array(
				'key'     => 'audience',
				'label'   => __( 'Who gets it', 'cardsco' ),
				'heading' => __( 'Who should receive this?', 'cardsco' ),
			),
			3 => array(
				'key'     => 'card',
				'label'   => __( 'Card', 'cardsco' ),
				'heading' => __( 'Choose the card', 'cardsco' ),
			),
			4 => array(
				'key'     => 'message',
				'label'   => __( 'Message', 'cardsco' ),
				'heading' => __( 'What should it say?', 'cardsco' ),
			),
			5 => array(
				'key'     => 'offer',
				'label'   => __( 'Offer', 'cardsco' ),
				'heading' => __( 'Add an offer code?', 'cardsco' ),
			),
			6 => array(
				'key'     => 'send',
				'label'   => __( 'When', 'cardsco' ),
				'heading' => __( 'When should it arrive?', 'cardsco' ),
			),
			7 => array(
				'key'     => 'review',
				'label'   => __( 'Review', 'cardsco' ),
				'heading' => __( 'Check it over before it goes', 'cardsco' ),
			),
		);
	}

	/**
	 * Statuses a campaign moves through.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'draft'     => __( 'Draft', 'cardsco' ),
			'scheduled' => __( 'Scheduled', 'cardsco' ),
			'locked'    => __( 'Locked for writing', 'cardsco' ),
			'sent'      => __( 'Sent', 'cardsco' ),
			'cancelled' => __( 'Cancelled', 'cardsco' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Making and reading
	 * ------------------------------------------------------------------ */

	/**
	 * Start a draft.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $name What to call it.
	 * @return int|WP_Error New campaign ID.
	 */
	public static function create( $company_id, $name = '' ) {
		global $wpdb;

		$company_id = absint( $company_id );

		if ( ! $company_id || ! CardsCo_Company::get( $company_id ) ) {
			return new WP_Error( 'cardsco_campaign_company', __( 'That company could not be found.', 'cardsco' ) );
		}

		$name = sanitize_text_field( $name );
		$name = '' !== $name ? $name : __( 'Untitled campaign', 'cardsco' );

		$inserted = $wpdb->insert(
			cardsco_table( 'campaigns' ),
			array(
				'company_id'   => $company_id,
				'name'         => $name,
				'status'       => 'draft',
				'builder_step' => 1,
				'currency'     => (string) CardsCo_Settings::get( 'currency', null, $company_id ),
				'created_by'   => get_current_user_id(),
				'created_at'   => cardsco_now(),
				'updated_at'   => cardsco_now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_campaign_insert', __( 'That campaign could not be created.', 'cardsco' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null
	 */
	public static function get( $campaign_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . cardsco_table( 'campaigns' ) . ' WHERE id = %d', absint( $campaign_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * A company's campaigns, newest first.
	 *
	 * @param int   $company_id Company ID.
	 * @param array $args status, limit.
	 * @return array[]
	 */
	public static function for_company( $company_id, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'limit'  => 50,
			)
		);

		/* 0 means every company, for the admin's listing screens. */
		$company_id = absint( $company_id );
		$where      = $company_id ? array( 'company_id = %d' ) : array( '1 = %d' );
		$params     = array( $company_id ? $company_id : 1 );

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$params[] = absint( $args['limit'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'campaigns' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Campaigns waiting to send.
	 *
	 * @param array $args company_id, limit.
	 * @return array[]
	 */
	public static function pending( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array( 'company_id' => 0, 'limit' => 100 ) );

		$where  = array( "status IN ('scheduled','locked')" );
		$params = array();

		if ( $args['company_id'] ) {
			$where[]  = 'company_id = %d';
			$params[] = absint( $args['company_id'] );
		}

		$params[] = absint( $args['limit'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'campaigns' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY send_date ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/* ---------------------------------------------------------------------
	 * Editing
	 * ------------------------------------------------------------------ */

	/**
	 * Save part of a campaign.
	 *
	 * Refuses once the campaign has locked, because at that point somebody is
	 * already writing the cards.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $campaign_id, array $data ) {
		global $wpdb;

		$campaign = self::get( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error( 'cardsco_campaign_missing', __( 'That campaign could not be found.', 'cardsco' ) );
		}

		if ( in_array( $campaign['status'], array( 'locked', 'sent' ), true ) ) {
			return new WP_Error(
				'cardsco_campaign_locked',
				__( 'This campaign is already with the writers, so it cannot be changed. Cancel it and start another if you need something different.', 'cardsco' )
			);
		}

		$fields = array();
		$format = array();

		$text = array( 'name', 'offer_code', 'offer_description' );

		foreach ( $text as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = sanitize_text_field( (string) $data[ $key ] );
				$format[]       = '%s';
			}
		}

		if ( array_key_exists( 'message_body', $data ) ) {
			$fields['message_body'] = sanitize_textarea_field( (string) $data['message_body'] );
			$format[]               = '%s';
		}

		foreach ( array( 'occasion_type_id', 'card_id', 'message_template_id', 'builder_step' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = absint( $data[ $key ] );
				$format[]       = '%d';
			}
		}

		/*
		 * Where the cards go: to each person, or all to one address.
		 */
		if ( array_key_exists( 'shipping_mode', $data ) ) {
			$fields['shipping_mode'] = 'company' === $data['shipping_mode'] ? 'company' : 'individual';
			$format[]                = '%s';
		}

		if ( array_key_exists( 'ship_address', $data ) ) {
			$fields['ship_address'] = sanitize_textarea_field( (string) $data['ship_address'] );
			$format[]               = '%s';
		}

		if ( array_key_exists( 'ship_postcode', $data ) ) {
			$fields['ship_postcode'] = strtoupper( sanitize_text_field( (string) $data['ship_postcode'] ) );
			$format[]                = '%s';
		}

		if ( array_key_exists( 'ship_country', $data ) ) {
			$country                = strtoupper( substr( sanitize_text_field( (string) $data['ship_country'] ), 0, 2 ) );
			$fields['ship_country'] = $country ? $country : 'GB';
			$format[]               = '%s';
		}

		/*
		 * Several designs. card_id is always the first of them, so every
		 * place that reads one card still reads the right one; the list is
		 * only kept when there is more than one, or a number was given.
		 * A bare card_id — the one-design picker — replaces the lot.
		 */
		if ( array_key_exists( 'designs', $data ) ) {
			$designs = CardsCo_Mix::clean_designs( $data['designs'] );

			$fields['card_id'] = $designs ? (int) $designs[0]['card'] : 0;
			$fields['designs'] = count( $designs ) > 1 ? wp_json_encode( $designs ) : null;

		} elseif ( array_key_exists( 'card_id', $data ) ) {
			/*
			 * A different card replaces the mix. The same card saved again
			 * — the admin screen re-saving a step on a customer's bulk
			 * order — is not somebody throwing the other designs away.
			 */
			$kept = self::designs( $campaign );

			if ( ! $kept || (int) $kept[0]['card'] !== absint( $data['card_id'] ) ) {
				$fields['designs'] = null;
			}
		}

		/*
		 * Several messages. message_body is always the first. A bare
		 * message_body — the offer step adding its code, an admin screen —
		 * replaces the first and leaves the rest alone.
		 */
		if ( array_key_exists( 'messages', $data ) ) {
			$wordings = array();

			foreach ( (array) $data['messages'] as $one ) {
				$one = trim( sanitize_textarea_field( (string) $one ) );

				if ( '' !== $one && ! in_array( $one, $wordings, true ) ) {
					$wordings[] = $one;
				}
			}

			$fields['message_body'] = $wordings ? $wordings[0] : '';
			$fields['messages']     = count( $wordings ) > 1 ? wp_json_encode( $wordings ) : null;

		} elseif ( array_key_exists( 'message_body', $data ) ) {
			$kept = self::messages( $campaign );

			if ( count( $kept ) > 1 ) {
				$kept[0] = $fields['message_body'];
				$kept    = array_values( array_unique( array_filter( array_map( 'trim', $kept ), 'strlen' ) ) );

				$fields['messages'] = count( $kept ) > 1 ? wp_json_encode( $kept ) : null;
			}
		}

		if ( array_key_exists( 'offer_enabled', $data ) ) {
			$fields['offer_enabled'] = empty( $data['offer_enabled'] ) ? 0 : 1;
			$format[]                = '%d';
		}

		foreach ( array( 'offer_expires', 'send_date' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = trim( (string) $data[ $key ] );

			$fields[ $key ] = ( '' !== $value && CardsCo_Validator::date( $value ) ) ? $value : null;
			$format[]       = '%s';
		}

		// The audience is stored as a list of segment IDs plus any people
		// picked by hand, so re-running the count later gives the same answer.
		if ( array_key_exists( 'audience', $data ) ) {
			$fields['audience'] = wp_json_encode( self::clean_audience( $data['audience'] ) );
			$format[]           = '%s';
		}

		if ( ! $fields ) {
			return true;
		}

		// The send date decides the lock date, so they move together.
		if ( array_key_exists( 'send_date', $fields ) ) {
			$fields['lock_date'] = self::lock_date( $fields['send_date'], (int) $campaign['company_id'] );
			$format[]            = '%s';
		}

		$fields['updated_at'] = cardsco_now();

		/*
		 * The formats are worked out here, from the fields as they finally
		 * stand, not collected alongside them. A list of formats built up
		 * in step with the fields is right only while every field is added
		 * exactly once and in order; a field set twice — card_id, by the
		 * card step and again by the designs — puts every format after it
		 * against the wrong column.
		 */
		$whole  = array( 'occasion_type_id', 'card_id', 'message_template_id', 'builder_step', 'offer_enabled' );
		$format = array();

		foreach ( array_keys( $fields ) as $key ) {
			$format[] = in_array( $key, $whole, true ) ? '%d' : '%s';
		}

		$wpdb->update( cardsco_table( 'campaigns' ), $fields, array( 'id' => absint( $campaign_id ) ), $format, array( '%d' ) );

		// Keep the counts and the price honest after any change that could
		// move them, rather than only at the review step.
		self::recount( $campaign_id );

		return true;
	}

	/**
	 * Tidy an audience into segments and hand-picked people.
	 *
	 * @param mixed $audience Raw input.
	 * @return array{segments:int[], recipients:int[], exclude:int[]}
	 */
	public static function clean_audience( $audience ) {
		if ( is_string( $audience ) ) {
			$decoded  = json_decode( $audience, true );
			$audience = is_array( $decoded ) ? $decoded : array();
		}

		$audience = is_array( $audience ) ? $audience : array();

		$out = array(
			'segments'   => array(),
			'recipients' => array(),
			'exclude'    => array(),
		);

		foreach ( array_keys( $out ) as $key ) {
			$values = isset( $audience[ $key ] ) ? (array) $audience[ $key ] : array();
			$values = array_filter( array_map( 'absint', $values ) );

			$out[ $key ] = array_values( array_unique( $values ) );
		}

		return $out;
	}

	/**
	 * The audience of a campaign, as it stands now.
	 *
	 * @param array $campaign Campaign row.
	 * @return array{ids:int[], excluded:int[]}
	 */
	public static function audience( array $campaign ) {
		$picked  = self::clean_audience( $campaign['audience'] );
		$ids     = $picked['recipients'];
		$exclude = $picked['exclude'];

		foreach ( $picked['segments'] as $segment_id ) {
			$segment = CardsCo_Segment::get( $segment_id );

			// Somebody else's segment must never widen this company's audience.
			if ( ! $segment || (int) $segment['company_id'] !== (int) $campaign['company_id'] ) {
				continue;
			}

			$ids = array_merge( $ids, CardsCo_Segment::members( $segment_id ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		// Anybody deliberately taken out stays out, however they got in.
		$ids = array_values( array_diff( $ids, $exclude ) );

		/*
		 * And only people who are still there to be sent a card.
		 *
		 * Somebody ticked by hand stayed on the list after they were
		 * archived or deleted. No card was ever written for them — the send
		 * skips anyone who is not active — but they were counted, and the
		 * count is what is charged for. The company check is here for the
		 * same reason it is on segments: a campaign reaches its own
		 * company's people and nobody else's.
		 */
		if ( $ids ) {
			global $wpdb;

			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$live  = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						'SELECT id FROM ' . cardsco_table( 'recipients' ) . " WHERE company_id = %d AND status = 'active' AND id IN ($marks)", // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
						array_merge( array( (int) $campaign['company_id'] ), $ids )
					)
				)
			);

			/* In the order they were chosen, which is the order they are written in. */
			$ids = array_values( array_intersect( $ids, $live ) );
		}

		return array(
			'ids'      => $ids,
			'excluded' => array_values( $exclude ),
		);
	}

	/**
	 * Count the audience and work out the bill.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null The refreshed campaign.
	 */
	public static function recount( $campaign_id ) {
		global $wpdb;

		$campaign = self::get( $campaign_id );

		if ( ! $campaign ) {
			return null;
		}

		$audience = self::audience( $campaign );
		$count    = count( $audience['ids'] );

		/* Priced at the size it has just been counted at, not the size on the row. */
		$price = 'draft' === (string) $campaign['status']
			? self::rate( $campaign, $count )
			: self::unit_price( $campaign );

		$wpdb->update(
			cardsco_table( 'campaigns' ),
			array(
				'recipient_count' => $count,
				'excluded_count'  => count( $audience['excluded'] ),
				'unit_price'      => number_format( $price, 2, '.', '' ),
				'total_amount'    => number_format(
					'draft' === (string) $campaign['status'] ? self::order_total( $campaign, $count ) : $count * $price,
					2,
					'.',
					''
				),
				'updated_at'      => cardsco_now(),
			),
			array( 'id' => absint( $campaign_id ) ),
			array( '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return self::get( $campaign_id );
	}

	/**
	 * What one card costs on this campaign.
	 *
	 * @param array $campaign Campaign row.
	 * @return float
	 */
	public static function unit_price( array $campaign ) {
		/*
		 * Once a campaign has left draft it has been paid for, and what
		 * was stored then is what was paid. While it is a draft the stored
		 * figure is only the last answer, and it used to be handed back as
		 * if it were the current one: change the card, or add the people
		 * who take the order past the bulk mark, and the review went on
		 * showing the first price while the till charged the real one.
		 */
		if ( 'draft' !== (string) ( $campaign['status'] ?? 'draft' )
			&& null !== $campaign['unit_price'] && '' !== $campaign['unit_price'] && (float) $campaign['unit_price'] > 0 ) {
			return (float) $campaign['unit_price'];
		}

		return self::rate( $campaign );
	}

	/**
	 * What one card costs on this campaign, at the size it is now.
	 *
	 * The one place that answers it: the review, the stored total and the
	 * till all come here, so they cannot disagree. Quantity decides the
	 * rate, exactly as it does for an occasion.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return float
	 */
	public static function rate( array $campaign, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) $campaign['recipient_count'];
		}

		if ( $campaign['card_id'] ) {
			return (float) CardsCo_Card::price_for( (int) $campaign['card_id'], max( 1, (int) $quantity ), (int) $campaign['company_id'] );
		}

		return (float) CardsCo_Settings::get( 'price_default', null, (int) $campaign['company_id'] );
	}

	/**
	 * Whether this campaign has to be arranged with us directly.
	 *
	 * A campaign's cards are posted to each person. Twenty-five or more of
	 * those is the order that is not sold here: the volume of a bulk order
	 * with the postage of single cards.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return bool
	 */
	public static function needs_referral( array $campaign, $quantity = null ) {
		if ( null === $quantity ) {
			$quantity = (int) $campaign['recipient_count'];
		}

		return ! self::one_address( $campaign ) && CardsCo_Card::is_bulk( (int) $quantity, (int) $campaign['company_id'] );
	}

	/**
	 * Whether every card on this campaign goes to one address.
	 *
	 * @param array $campaign Campaign row.
	 * @return bool
	 */
	public static function one_address( array $campaign ) {
		return 'company' === (string) ( $campaign['shipping_mode'] ?? 'individual' );
	}

	/**
	 * Whether that one address is somewhere a parcel can be sent.
	 *
	 * @param array $campaign Campaign row.
	 * @return bool
	 */
	public static function address_ready( array $campaign ) {
		return 'complete' === cardsco_address_status( (string) ( $campaign['ship_address'] ?? '' ), (string) ( $campaign['ship_postcode'] ?? '' ) );
	}

	/**
	 * The designs on this campaign, in the order chosen.
	 *
	 * @param array $campaign Campaign row.
	 * @return array<int, array{card:int, qty:int}>
	 */
	public static function designs( array $campaign ) {
		$designs = CardsCo_Mix::clean_designs( $campaign['designs'] ?? '' );

		if ( $designs ) {
			return $designs;
		}

		return (int) $campaign['card_id'] > 0
			? array( array( 'card' => (int) $campaign['card_id'], 'qty' => 0 ) )
			: array();
	}

	/**
	 * The messages on this campaign, in the order written.
	 *
	 * @param array $campaign Campaign row.
	 * @return string[]
	 */
	public static function messages( array $campaign ) {
		$raw  = $campaign['messages'] ?? '';
		$list = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$out  = array();

		foreach ( is_array( $list ) ? $list : array() as $one ) {
			$one = trim( (string) $one );

			if ( '' !== $one && ! in_array( $one, $out, true ) ) {
				$out[] = $one;
			}
		}

		if ( $out ) {
			return $out;
		}

		$first = trim( (string) $campaign['message_body'] );

		if ( '' === $first && (int) $campaign['message_template_id'] > 0 ) {
			$post = get_post( (int) $campaign['message_template_id'] );

			if ( $post && 'publish' === $post->post_status ) {
				$first = trim( (string) CardsCo_Message::text( $post->ID ) );
			}
		}

		return '' === $first ? array() : array( $first );
	}

	/**
	 * How many designs this campaign may carry.
	 *
	 * The same rule as an occasion, from the same place: one for single
	 * cards, five for a bulk order, ten for a large one.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return int
	 */
	public static function design_limit( array $campaign, $quantity = null ) {
		$quantity = null === $quantity ? (int) $campaign['recipient_count'] : (int) $quantity;

		return CardsCo_Mix::design_limit( $quantity, self::one_address( $campaign ), (int) $campaign['company_id'] );
	}

	/**
	 * How many messages this campaign may carry.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return int
	 */
	public static function message_limit( array $campaign, $quantity = null ) {
		$quantity = null === $quantity ? (int) $campaign['recipient_count'] : (int) $quantity;

		return CardsCo_Mix::message_limit( $quantity, self::one_address( $campaign ), '', (int) $campaign['company_id'] );
	}

	/**
	 * What is wrong with the designs or the messages, in words.
	 *
	 * @param array  $campaign Campaign row.
	 * @param string $what     'all', 'designs' or 'messages'.
	 * @return string[] Nothing when all is well.
	 */
	public static function mix_problems( array $campaign, $what = 'all' ) {
		$problems = array();
		$quantity = (int) $campaign['recipient_count'];

		if ( 'messages' !== $what ) {
			$designs = self::designs( $campaign );
			$limit   = self::design_limit( $campaign );

			if ( count( $designs ) > $limit ) {
				$problems[] = 1 === $limit
					? __( 'This order takes one design. Several designs are for bulk orders: 25 cards or more, sent to one address.', 'cardsco' )
					: sprintf(
						/* translators: 1: how many designs are allowed, 2: how many were chosen. */
						__( 'An order this size takes up to %1$d designs, and %2$d are chosen. Take some off.', 'cardsco' ),
						$limit,
						count( $designs )
					);
			} elseif ( count( $designs ) > 1 ) {
				$said = array_filter( wp_list_pluck( $designs, 'qty' ) );

				if ( $said && count( $said ) !== count( $designs ) ) {
					$problems[] = __( 'Give a number for every design, or leave them all empty and we split the cards evenly.', 'cardsco' );
				} elseif ( $said && array_sum( $said ) !== $quantity ) {
					$problems[] = sprintf(
						/* translators: 1: the numbers typed, added up, 2: how many cards the order is for. */
						__( 'The numbers add up to %1$d and the order is for %2$d cards. Make them match, or clear them and we split the cards evenly.', 'cardsco' ),
						array_sum( $said ),
						$quantity
					);
				}
			}
		}

		if ( 'designs' !== $what ) {
			$messages = self::messages( $campaign );
			$limit    = self::message_limit( $campaign );

			if ( count( $messages ) > $limit ) {
				$problems[] = 1 === $limit
					? __( 'This order takes one message. Several messages are for bulk orders: 25 cards or more, sent to one address.', 'cardsco' )
					: sprintf(
						/* translators: 1: how many messages are allowed, 2: how many were written. */
						__( 'An order this size takes up to %1$d messages, and there are %2$d. Take some off.', 'cardsco' ),
						$limit,
						count( $messages )
					);
			}
		}

		return $problems;
	}

	/**
	 * The order, a line for each design.
	 *
	 * Every line is priced at the size of the whole order: thirty cards
	 * across three designs are thirty cards.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return array<int, array{card:int, qty:int, each:float, total:float}>
	 */
	public static function order_lines( array $campaign, $quantity = null ) {
		$quantity = null === $quantity ? (int) $campaign['recipient_count'] : (int) $quantity;
		$lines    = array();

		if ( $quantity < 1 ) {
			return $lines;
		}

		foreach ( CardsCo_Mix::split( self::designs( $campaign ), $quantity ) as $card_id => $count ) {
			if ( $count < 1 ) {
				continue;
			}

			$each    = (float) CardsCo_Card::price_for( (int) $card_id, $quantity, (int) $campaign['company_id'] );
			$lines[] = array(
				'card'  => (int) $card_id,
				'qty'   => (int) $count,
				'each'  => $each,
				'total' => round( $each * $count, 2 ),
			);
		}

		return $lines;
	}

	/**
	 * What the whole campaign costs.
	 *
	 * @param array    $campaign Campaign row.
	 * @param int|null $quantity How many cards, or null for the campaign's own count.
	 * @return float
	 */
	public static function order_total( array $campaign, $quantity = null ) {
		$quantity = null === $quantity ? (int) $campaign['recipient_count'] : (int) $quantity;
		$lines    = self::order_lines( $campaign, $quantity );

		if ( ! $lines ) {
			return round( self::rate( $campaign, $quantity ) * max( 0, $quantity ), 2 );
		}

		return round( array_sum( wp_list_pluck( $lines, 'total' ) ), 2 );
	}

	/**
	 * What to say when it does. The same panel an occasion shows.
	 *
	 * @param array $campaign Campaign row.
	 * @return array{title:string,body:string,action:string,email:string,mailto:string}
	 */
	public static function referral( array $campaign ) {
		$quantity = (int) $campaign['recipient_count'];
		$email    = (string) CardsCo_Settings::get( 'support_email', null, (int) $campaign['company_id'] );
		$company  = CardsCo_Company::get( (int) $campaign['company_id'] );

		$subject = sprintf(
			/* translators: 1: how many cards, 2: the company's name. */
			__( '%1$d cards to individual addresses — %2$s', 'cardsco' ),
			$quantity,
			$company ? $company['name'] : __( 'our company', 'cardsco' )
		);

		$body = sprintf(
			/* translators: 1: how many cards, 2: the campaign's name. */
			__( "We would like to send %1\$d cards for our campaign \"%2\$s\", posted to each person's own address.\n\nPlease could you let us know the price and how to go ahead.", 'cardsco' ),
			$quantity,
			(string) $campaign['name']
		);

		return array(
			'title'  => __( 'This one we arrange with you directly', 'cardsco' ),
			'body'   => sprintf(
				/* translators: %d: how many cards. */
				__( 'Orders of %d or more going to individual addresses are priced case by case, so there is nothing to pay here yet. Send us the details and we will come back to you with a price.', 'cardsco' ),
				(int) CardsCo_Settings::get( 'bulk_from', null, (int) $campaign['company_id'] )
			),
			'action' => __( 'Email us about this order', 'cardsco' ),
			'email'  => $email,
			'mailto' => 'mailto:' . rawurlencode( $email ) . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body ),
		);
	}

	/**
	 * The day a campaign stops being editable.
	 *
	 * @param string|null $send_date Y-m-d.
	 * @param int         $company_id Company ID.
	 * @return string|null Y-m-d.
	 */
	public static function lock_date( $send_date, $company_id = 0 ) {
		if ( ! $send_date ) {
			return null;
		}

		$days = (int) CardsCo_Settings::get( 'campaign_lock_days', null, $company_id );
		$days = $days > 0 ? $days : 3;

		return cardsco_write_by_date( $send_date, $days );
	}

	/**
	 * The soonest a campaign started today could land.
	 *
	 * The mirror of lock_date(): that one goes backwards from a landing
	 * date to the day the list must be settled, this one goes forwards from
	 * today to the first landing date that leaves enough time to write.
	 *
	 * They are easy to mix up, and mixing them up is not loud — it returns
	 * a real date, just one in the past. Step 6 did exactly that and offered
	 * it as the earliest date a customer could choose.
	 *
	 * @param int $company_id Company, for its own lock days.
	 * @return string Y-m-d.
	 */
	public static function earliest_send_date( $company_id = 0 ) {
		$days = (int) CardsCo_Settings::get( 'campaign_lock_days', null, $company_id );
		$days = $days > 0 ? $days : 3;

		return gmdate( 'Y-m-d', strtotime( '+' . $days . ' days', strtotime( cardsco_now( 'date' ) ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Steps and scheduling
	 * ------------------------------------------------------------------ */

	/**
	 * Is a step finished?
	 *
	 * @param array $campaign Campaign row.
	 * @param int   $step Step number.
	 * @return bool
	 */
	public static function step_complete( array $campaign, $step ) {
		switch ( (int) $step ) {
			case 1:
				/* A bulk order has to have somewhere to be delivered to. */
				return (int) $campaign['occasion_type_id'] > 0
					&& ( ! self::one_address( $campaign ) || self::address_ready( $campaign ) );

			case 2:
				return (int) $campaign['recipient_count'] > 0;

			case 3:
				$designs = self::designs( $campaign );

				if ( ! $designs ) {
					return false;
				}

				foreach ( $designs as $design ) {
					$card = get_post( (int) $design['card'] );

					if ( ! $card || CardsCo_Card::POST_TYPE !== $card->post_type || 'publish' !== $card->post_status ) {
						return false;
					}
				}

				return ! self::mix_problems( $campaign, 'designs' );

			case 4:
				return (bool) self::messages( $campaign ) && ! self::mix_problems( $campaign, 'messages' );

			case 5:
				// The offer step is a choice, not a requirement. Switching the
				// offer on makes a code compulsory; leaving it off is finished.
				return empty( $campaign['offer_enabled'] ) || '' !== trim( (string) $campaign['offer_code'] );

			case 6:
				return ! empty( $campaign['send_date'] );

			default:
				return false;
		}
	}

	/**
	 * Can this campaign be scheduled?
	 *
	 * @param array $campaign Campaign row.
	 * @return true|WP_Error
	 */
	public static function can_schedule( array $campaign ) {
		$errors = new WP_Error();
		$steps  = self::steps();

		for ( $step = 1; $step <= 6; $step++ ) {
			if ( self::step_complete( $campaign, $step ) ) {
				continue;
			}

			$errors->add(
				'cardsco_campaign_step_' . $step,
				sprintf(
					/* translators: 1: step number, 2: step name. */
					__( 'Step %1$d, %2$s, is not finished.', 'cardsco' ),
					$step,
					$steps[ $step ]['label']
				)
			);
		}

		// A send date in the past cannot be met, and one inside the lock window
		// leaves no time to write the cards.
		if ( ! empty( $campaign['send_date'] ) ) {
			$today = cardsco_now( 'date' );

			if ( $campaign['send_date'] < $today ) {
				$errors->add( 'cardsco_campaign_past', __( 'That send date has already gone by.', 'cardsco' ) );
			} elseif ( self::lock_date( $campaign['send_date'], (int) $campaign['company_id'] ) < $today ) {
				$errors->add(
					'cardsco_campaign_tight',
					__( 'That is too soon. There has to be time to write the cards before they go out.', 'cardsco' )
				);
			}
		}

		// Wording that still has gaps would reach a card with a blank in it.
		// Every message is checked, not only the first.
		$wordings = self::messages( $campaign );

		foreach ( $wordings ? array_keys( $wordings ) : array( 0 ) as $index ) {
			$check = self::preview( $campaign, null, $index + 1 );

			if ( is_array( $check ) && ! empty( $check['problem'] ) ) {
				$errors->add(
					'cardsco_campaign_message',
					count( $wordings ) > 1
						/* translators: 1: which message, 2: what is wrong with it. */
						? sprintf( __( 'Message %1$d: %2$s', 'cardsco' ), $index + 1, $check['problem'] )
						: $check['problem']
				);
			}
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Put a campaign in the diary.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return true|WP_Error
	 */
	public static function schedule( $campaign_id ) {
		global $wpdb;

		$campaign = self::recount( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error( 'cardsco_campaign_missing', __( 'That campaign could not be found.', 'cardsco' ) );
		}

		if ( 'draft' !== $campaign['status'] ) {
			return new WP_Error( 'cardsco_campaign_status', __( 'Only a draft can be scheduled.', 'cardsco' ) );
		}

		$ready = self::can_schedule( $campaign );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$wpdb->update(
			cardsco_table( 'campaigns' ),
			array(
				'status'      => 'scheduled',
				'lock_date'   => self::lock_date( $campaign['send_date'], (int) $campaign['company_id'] ),
				'approved_by' => get_current_user_id(),
				'approved_at' => cardsco_now(),
				'updated_at'  => cardsco_now(),
			),
			array( 'id' => absint( $campaign_id ) ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		cardsco_log(
			array(
				'company_id'  => (int) $campaign['company_id'],
				'object_type' => 'campaign',
				'object_id'   => (int) $campaign_id,
				'action'      => 'scheduled',
				/* translators: 1: campaign name, 2: send date. */
				'summary'     => sprintf( __( '%1$s scheduled for %2$s', 'cardsco' ), $campaign['name'], $campaign['send_date'] ),
			)
		);

		return true;
	}

	/**
	 * Stop a campaign that has not gone yet.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return true|WP_Error
	 */
	public static function cancel( $campaign_id ) {
		global $wpdb;

		$campaign = self::get( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error( 'cardsco_campaign_missing', __( 'That campaign could not be found.', 'cardsco' ) );
		}

		if ( 'sent' === $campaign['status'] ) {
			return new WP_Error( 'cardsco_campaign_sent', __( 'That campaign has already gone out.', 'cardsco' ) );
		}

		$wpdb->update(
			cardsco_table( 'campaigns' ),
			array(
				'status'     => 'cancelled',
				'updated_at' => cardsco_now(),
			),
			array( 'id' => absint( $campaign_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Anything already queued and not yet written comes back out.
		foreach ( self::cards( $campaign_id ) as $card ) {
			if ( in_array( $card['status'], array( 'queued', 'on_hold' ), true ) ) {
				CardsCo_Queue::set_status( (int) $card['id'], 'cancelled' );
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * The wording this campaign sends, and whether it holds together.
	 *
	 * @param array      $campaign Campaign row.
	 * @param array|null $recipient Somebody to render it against, or null for a sample.
	 * @return array{text:string, problem:string}
	 */
	public static function preview( array $campaign, $recipient = null, $message_no = 1 ) {
		$wordings = self::messages( $campaign );
		$template = (string) ( $wordings[ max( 1, (int) $message_no ) - 1 ] ?? ( $wordings[0] ?? '' ) );

		if ( '' === trim( $template ) ) {
			return array(
				'text'    => '',
				'problem' => __( 'This campaign has no wording yet.', 'cardsco' ),
			);
		}

		$context = $recipient
			? CardsCo_Merge::context_for_recipient( $recipient, self::offer_context( $campaign ) )
			: array_merge( self::sample_context(), self::offer_context( $campaign ) );

		$rendered = CardsCo_Merge::render( $template, $context );
		$problem  = '';

		// Merge keys come back underscored, but somebody typed "{offer code}"
		// with a space. Telling them "{offer_code}" is wrong is a small thing
		// that makes a person doubt they typed it correctly, so the message
		// shows the field the way they wrote it.
		$spaced = static function ( array $keys ) {
			return implode( '}, {', array_map( static function ( $key ) {
				return str_replace( '_', ' ', $key );
			}, $keys ) );
		};

		if ( ! empty( $rendered['unknown'] ) ) {
			$problem = sprintf(
				/* translators: %s: the merge fields that were not recognised. */
				__( 'The wording uses something the system does not recognise: {%s}.', 'cardsco' ),
				$spaced( $rendered['unknown'] )
			);
		} elseif ( ! empty( $rendered['missing'] ) ) {
			$problem = sprintf(
				/* translators: %s: the merge fields with nothing behind them. */
				__( 'There is nothing to put in {%s}, so a card would go out with a gap in it.', 'cardsco' ),
				$spaced( $rendered['missing'] )
			);
		}

		return array(
			'text'    => $rendered['text'],
			'problem' => $problem,
		);
	}

	/**
	 * Is the offer going to reach anybody?
	 *
	 * An offer can be switched on, given a code, and then never mentioned in
	 * the wording — in which case the card arrives and the person never learns
	 * the code. Nothing errors, nothing is held back, the promotion simply
	 * does nothing. Worth saying out loud on the review screen.
	 *
	 * @param array $campaign Campaign row.
	 * @return string Empty when there is nothing to say.
	 */
	public static function offer_warning( array $campaign ) {
		if ( empty( $campaign['offer_enabled'] ) || '' === trim( (string) $campaign['offer_code'] ) ) {
			return '';
		}

		$template = (string) $campaign['message_body'];

		if ( '' === trim( $template ) && $campaign['message_template_id'] ) {
			$message = get_post( (int) $campaign['message_template_id'] );

			if ( $message && 'publish' === $message->post_status ) {
				$template = CardsCo_Message::text( $message->ID );
			}
		}

		// Look for the field however it was typed: {offer code}, {offer_code},
		// {Offer Code} all count.
		if ( preg_match( '/\{\s*offer[ _-]?code\s*\}/i', $template ) ) {
			return '';
		}

		return __( 'The offer is switched on, but the wording never mentions {offer code}, so nobody receiving the card will see it. The code is still recorded against each card for your own records.', 'cardsco' );
	}

	/**
	 * The offer values a campaign's wording can use.
	 *
	 * @param array $campaign Campaign row.
	 * @return array<string, string>
	 */
	public static function offer_context( array $campaign ) {
		if ( empty( $campaign['offer_enabled'] ) ) {
			return array();
		}

		$expires = '';

		if ( ! empty( $campaign['offer_expires'] ) ) {
			$parts = explode( '-', $campaign['offer_expires'] );

			if ( 3 === count( $parts ) ) {
				$expires = cardsco_format_occasion_date( (int) $parts[1], (int) $parts[2] );
			}
		}

		return array(
			'offer_code'    => (string) $campaign['offer_code'],
			'offer_expires' => $expires,
		);
	}

	/**
	 * Stand-in values for previewing wording with nobody in particular.
	 *
	 * @return array<string, string>
	 */
	public static function sample_context() {
		$values = array();

		foreach ( CardsCo_Merge::fields() as $key => $field ) {
			$values[ $key ] = (string) $field['sample'];
		}

		return $values;
	}

	/**
	 * Cards this campaign has put in the queue.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array[]
	 */
	public static function cards( $campaign_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'card_queue' ) . ' WHERE campaign_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $campaign_id )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Queue a campaign's cards.
	 *
	 * Running this twice does not send twice: a card already queued for this
	 * campaign and this person is left alone, so somebody added to a segment
	 * late still gets one without everybody else getting a second.
	 *
	 * @param int  $campaign_id Campaign ID.
	 * @param bool $dry_run Decide but write nothing.
	 * @return array{queued:int, held:int, already:int, skipped:int, reasons:array}|WP_Error
	 */
	public static function send( $campaign_id, $dry_run = false ) {
		$campaign = self::get( $campaign_id );

		if ( ! $campaign ) {
			return new WP_Error( 'cardsco_campaign_missing', __( 'That campaign could not be found.', 'cardsco' ) );
		}

		if ( ! in_array( $campaign['status'], array( 'scheduled', 'locked' ), true ) ) {
			return new WP_Error( 'cardsco_campaign_not_ready', __( 'Only a scheduled campaign can be sent.', 'cardsco' ) );
		}

		$card = $campaign['card_id'] ? get_post( (int) $campaign['card_id'] ) : null;

		if ( ! $card || 'publish' !== $card->post_status ) {
			return new WP_Error( 'cardsco_campaign_card', __( 'This campaign has no card chosen.', 'cardsco' ) );
		}

		$result = array(
			'queued'  => 0,
			'held'    => 0,
			'already' => 0,
			'skipped' => 0,
			'reasons' => array(),
		);

		$audience = self::audience( $campaign );
		$price    = self::unit_price( $campaign );
		$type     = $campaign['occasion_type_id'] ? CardsCo_Occasion_Type::get( (int) $campaign['occasion_type_id'] ) : null;
		$label    = $type ? $type['label'] : $campaign['name'];
		$already  = self::queued_recipient_ids( $campaign_id );

		/*
		 * Who gets which design, and which message.
		 *
		 * Dealt over the whole list, in an order that looks shuffled and is
		 * the same every time for the same campaign — so sending it twice,
		 * or sending the rest after a card was held, gives nobody a
		 * different card from the one they were dealt. The designs and the
		 * messages are shuffled separately, which is what makes the pairing
		 * of one with the other arbitrary. With one of each this hands
		 * everybody the same, as it always did.
		 */
		$bulk     = self::one_address( $campaign );
		$total    = count( $audience['ids'] );
		$designs  = self::designs( $campaign );
		$wordings = self::messages( $campaign );
		$card_for = array();
		$wording_for = array();

		if ( count( $designs ) > 1 ) {
			$card_for = CardsCo_Mix::deal_designs(
				CardsCo_Mix::shuffled( $audience['ids'], 'campaign-design:' . (int) $campaign_id ),
				CardsCo_Mix::split( $designs, $total )
			);
		}

		if ( count( $wordings ) > 1 ) {
			$wording_for = CardsCo_Mix::deal_messages(
				CardsCo_Mix::shuffled( $audience['ids'], 'campaign-message:' . (int) $campaign_id ),
				count( $wordings )
			);
		}

		$cards = array( (int) $card->ID => $card );

		foreach ( $audience['ids'] as $recipient_id ) {
			if ( isset( $already[ $recipient_id ] ) ) {
				$result['already']++;
				continue;
			}

			$recipient = CardsCo_Recipient::get( $recipient_id );

			if ( ! $recipient || 'active' !== $recipient['status'] ) {
				$result['skipped']++;
				$result['reasons']['inactive'] = ( $result['reasons']['inactive'] ?? 0 ) + 1;
				continue;
			}

			$rendered = self::preview( $campaign, $recipient, (int) ( $wording_for[ $recipient_id ] ?? 1 ) );

			/* Their design, or the campaign's first if theirs has since been withdrawn. */
			$theirs = (int) ( $card_for[ $recipient_id ] ?? $card->ID );

			if ( ! isset( $cards[ $theirs ] ) ) {
				$found            = get_post( $theirs );
				$cards[ $theirs ] = ( $found && 'publish' === $found->post_status ) ? $found : $card;
			}

			$their_card = $cards[ $theirs ];

			/*
			 * A bulk order goes to the one address on the campaign. The
			 * person's own address is not asked for and not used.
			 */
			$to = $bulk
				? array( (string) $campaign['ship_address'], (string) $campaign['ship_postcode'], (string) $campaign['ship_country'] )
				: array( (string) $recipient['address'], (string) $recipient['postcode'], (string) $recipient['country'] );

			$status = 'queued';
			$reason = '';

			if ( 'complete' !== cardsco_address_status( $to[0], $to[1] ) ) {
				$status = 'on_hold';
				$reason = 'no_address';
			} elseif ( $rendered['problem'] ) {
				$status = 'on_hold';
				$reason = 'message_gaps';
			}

			if ( $dry_run ) {
				$result[ 'on_hold' === $status ? 'held' : 'queued' ]++;

				if ( $reason ) {
					$result['reasons'][ $reason ] = ( $result['reasons'][ $reason ] ?? 0 ) + 1;
				}

				continue;
			}

			$queue_id = CardsCo_Queue::add(
				array(
					'company_id'        => (int) $campaign['company_id'],
					'source'            => 'campaign',
					'campaign_id'       => (int) $campaign_id,
					'recipient_id'      => $recipient_id,
					'recipient_name'    => trim( $recipient['first_name'] . ' ' . $recipient['last_name'] ),
					'recipient_company' => (string) $recipient['recipient_company'],
					'address_snapshot'  => $to[0],
					'postcode'          => $to[1],
					'country'           => $to[2] ? $to[2] : 'GB',
					'card_id'           => (int) $their_card->ID,
					'card_title'        => $their_card->post_title,
					'occasion_label'    => $label,
					'message_final'     => $rendered['text'],
					'offer_code'        => empty( $campaign['offer_enabled'] ) ? '' : (string) $campaign['offer_code'],
					'event_date'        => $campaign['send_date'],
					'write_by'          => self::lock_date( $campaign['send_date'], (int) $campaign['company_id'] ),
					'post_by'           => $campaign['send_date'],
					'status'            => $status,
					'hold_reason'       => $reason,
					'unit_price'        => number_format( $price, 2, '.', '' ),
				)
			);

			if ( is_wp_error( $queue_id ) || ! $queue_id ) {
				$result['skipped']++;
				continue;
			}

			$result[ 'on_hold' === $status ? 'held' : 'queued' ]++;

			if ( $reason ) {
				$result['reasons'][ $reason ] = ( $result['reasons'][ $reason ] ?? 0 ) + 1;
			}
		}

		return $result;
	}

	/**
	 * Who this campaign has already queued a card for.
	 *
	 * The queue's own unique key only covers occasions, which a campaign does
	 * not use, so the guard against sending twice lives here.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<int, true>
	 */
	public static function queued_recipient_ids( $campaign_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT recipient_id FROM ' . cardsco_table( 'card_queue' ) . ' WHERE campaign_id = %d AND status != %s', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $campaign_id ),
				'cancelled'
			)
		);

		$out = array();

		foreach ( (array) $ids as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}

	/**
	 * Lock what is due and send what has come round.
	 *
	 * Called from the daily scan.
	 *
	 * @param array $args company_id, today, dry_run.
	 * @return array Summary.
	 */
	public static function run( array $args = array() ) {
		global $wpdb;

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
			'looked_at' => 0,
			'locked'    => 0,
			'sent'      => 0,
			'queued'    => 0,
			'held'      => 0,
			'already'   => 0,
			'skipped'   => 0,
			'reasons'   => array(),
			'ran_at'    => cardsco_now(),
		);

		foreach ( self::pending( array( 'company_id' => $args['company_id'] ) ) as $campaign ) {
			$summary['looked_at']++;

			$lock = $campaign['lock_date'] ? $campaign['lock_date'] : self::lock_date( $campaign['send_date'], (int) $campaign['company_id'] );

			// Not yet its turn.
			if ( ! $lock || $today < $lock ) {
				continue;
			}

			$result = self::send( (int) $campaign['id'], (bool) $args['dry_run'] );

			if ( is_wp_error( $result ) ) {
				$summary['skipped']++;
				$code                        = $result->get_error_code();
				$summary['reasons'][ $code ] = ( $summary['reasons'][ $code ] ?? 0 ) + 1;
				continue;
			}

			foreach ( array( 'queued', 'held', 'already', 'skipped' ) as $key ) {
				$summary[ $key ] += (int) $result[ $key ];
			}

			foreach ( $result['reasons'] as $reason => $count ) {
				$summary['reasons'][ $reason ] = ( $summary['reasons'][ $reason ] ?? 0 ) + (int) $count;
			}

			if ( $args['dry_run'] ) {
				continue;
			}

			// Once the cards are in the queue the campaign is with the
			// writers: it locks, and stops being editable.
			if ( 'scheduled' === $campaign['status'] ) {
				$wpdb->update(
					cardsco_table( 'campaigns' ),
					array(
						'status'     => 'locked',
						'updated_at' => cardsco_now(),
					),
					array( 'id' => (int) $campaign['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				$summary['locked']++;
			}

			// Its day has come and gone, so it is done.
			if ( $campaign['send_date'] && $today >= $campaign['send_date'] ) {
				$wpdb->update(
					cardsco_table( 'campaigns' ),
					array(
						'status'     => 'sent',
						'updated_at' => cardsco_now(),
					),
					array( 'id' => (int) $campaign['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				$summary['sent']++;
			}
		}

		if ( ! $args['dry_run'] ) {
			update_option( 'cardsco_last_campaign_run', $summary, false );
		}

		return $summary;
	}
}
