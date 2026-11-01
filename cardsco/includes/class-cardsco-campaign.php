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

		$where  = array( 'company_id = %d' );
		$params = array( absint( $company_id ) );

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
		$format[]             = '%s';

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
		$price    = self::unit_price( $campaign );
		$count    = count( $audience['ids'] );

		$wpdb->update(
			cardsco_table( 'campaigns' ),
			array(
				'recipient_count' => $count,
				'excluded_count'  => count( $audience['excluded'] ),
				'unit_price'      => number_format( $price, 2, '.', '' ),
				'total_amount'    => number_format( $count * $price, 2, '.', '' ),
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
		if ( null !== $campaign['unit_price'] && '' !== $campaign['unit_price'] && (float) $campaign['unit_price'] > 0 ) {
			return (float) $campaign['unit_price'];
		}

		if ( $campaign['card_id'] ) {
			return (float) CardsCo_Card::price( (int) $campaign['card_id'], (int) $campaign['company_id'] );
		}

		return (float) CardsCo_Settings::get( 'price_default', null, (int) $campaign['company_id'] );
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
				return (int) $campaign['occasion_type_id'] > 0;

			case 2:
				return (int) $campaign['recipient_count'] > 0;

			case 3:
				$card = $campaign['card_id'] ? get_post( (int) $campaign['card_id'] ) : null;

				return $card && 'publish' === $card->post_status;

			case 4:
				return '' !== trim( (string) $campaign['message_body'] )
					|| (int) $campaign['message_template_id'] > 0;

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
		$check = self::preview( $campaign );

		if ( is_array( $check ) && ! empty( $check['problem'] ) ) {
			$errors->add( 'cardsco_campaign_message', $check['problem'] );
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
	public static function preview( array $campaign, $recipient = null ) {
		$template = trim( (string) $campaign['message_body'] );

		if ( '' === $template && $campaign['message_template_id'] ) {
			$message = get_post( (int) $campaign['message_template_id'] );

			if ( $message && 'publish' === $message->post_status ) {
				$template = CardsCo_Message::text( $message->ID );
			}
		}

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

			$rendered = self::preview( $campaign, $recipient );

			$status = 'queued';
			$reason = '';

			if ( 'complete' !== cardsco_address_status( $recipient['address'], $recipient['postcode'] ) ) {
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
					'address_snapshot'  => (string) $recipient['address'],
					'postcode'          => (string) $recipient['postcode'],
					'country'           => $recipient['country'] ? $recipient['country'] : 'GB',
					'card_id'           => (int) $card->ID,
					'card_title'        => $card->post_title,
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
