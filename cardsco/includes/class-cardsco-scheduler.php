<?php
/**
 * The scheduling engine.
 *
 * Runs once a day, looks ahead at the occasions coming up, and puts a card in
 * the queue for each one in time to be written and posted.
 *
 * Two rules shape everything here. It never guesses which card or wording to
 * send: without a standing choice for that occasion nothing is queued, and the
 * screen says which companies need to make one. And it never sends the same
 * card twice, which the unique key on the queue enforces rather than trusting
 * the scan to be run exactly once.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Scheduler {

	/**
	 * Occasions handled in a single run before the work is continued later.
	 */
	const BATCH = 500;

	/**
	 * Settings key holding a company's standing card choice for an occasion.
	 *
	 * @param int $type_id Occasion type ID.
	 * @return string
	 */
	public static function card_key( $type_id ) {
		return 'default_card_' . absint( $type_id );
	}

	/**
	 * Settings key holding a company's standing wording choice.
	 *
	 * @param int $type_id Occasion type ID.
	 * @return string
	 */
	public static function message_key( $type_id ) {
		return 'default_message_' . absint( $type_id );
	}

	/**
	 * The card and wording a company sends for an occasion, if it has chosen.
	 *
	 * @param int $company_id Company ID.
	 * @param int $type_id Occasion type ID.
	 * @return array{card_id:int, message_id:int}
	 */
	public static function defaults_for( $company_id, $type_id ) {
		return array(
			'card_id'    => (int) CardsCo_Settings::get( self::card_key( $type_id ), 0, $company_id ),
			'message_id' => (int) CardsCo_Settings::get( self::message_key( $type_id ), 0, $company_id ),
		);
	}

	/**
	 * Record a company's standing choice for an occasion.
	 *
	 * @param int $company_id Company ID.
	 * @param int $type_id Occasion type ID.
	 * @param int $card_id Card post ID, 0 to clear.
	 * @param int $message_id Message post ID, 0 to clear.
	 * @return void
	 */
	public static function set_defaults( $company_id, $type_id, $card_id, $message_id ) {
		CardsCo_Settings::set( self::card_key( $type_id ), absint( $card_id ), absint( $company_id ) );
		CardsCo_Settings::set( self::message_key( $type_id ), absint( $message_id ), absint( $company_id ) );
	}

	/**
	 * Run the scan.
	 *
	 * @param array $args {
	 *     @type int  $company_id Limit to one company. 0 for all.
	 *     @type int  $horizon Days to look ahead. 0 uses the setting.
	 *     @type bool $dry_run Work everything out but write nothing.
	 *     @type int  $limit Occasions to handle this run.
	 * }
	 * @return array Summary of what happened.
	 */
	public static function run( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'company_id' => 0,
				'horizon'    => 0,
				'dry_run'    => false,
				'limit'      => self::BATCH,
			)
		);

		$horizon = absint( $args['horizon'] );
		$horizon = $horizon ? $horizon : (int) CardsCo_Settings::get( 'queue_horizon_days' );
		$horizon = $horizon ? $horizon : 30;

		$summary = array(
			'looked_at'    => 0,
			'queued'       => 0,
			'held'         => 0,
			'already_done' => 0,
			'unconfigured' => 0,
			'skipped'      => 0,
			'needs_setup'  => array(),
			'horizon'      => $horizon,
			'ran_at'       => cardsco_now(),
			'more_to_do'   => false,
		);

		$occasions = CardsCo_Occasion::upcoming(
			array(
				'company_id' => absint( $args['company_id'] ),
				'days'       => $horizon,
				'limit'      => absint( $args['limit'] ),
			)
		);

		foreach ( $occasions as $occasion ) {
			$summary['looked_at']++;

			$result = self::queue_occasion( $occasion, (bool) $args['dry_run'] );

			switch ( $result['outcome'] ) {
				case 'queued':
					$summary['queued']++;
					break;

				case 'held':
					$summary['held']++;
					break;

				case 'already_done':
					$summary['already_done']++;
					break;

				case 'unconfigured':
					$summary['unconfigured']++;
					$key = $occasion['company_id'] . ':' . $occasion['occasion_type_id'];

					if ( ! isset( $summary['needs_setup'][ $key ] ) ) {
						$summary['needs_setup'][ $key ] = array(
							'company_id' => (int) $occasion['company_id'],
							'type_id'    => (int) $occasion['occasion_type_id'],
							'label'      => $occasion['label'],
							'count'      => 0,
						);
					}

					$summary['needs_setup'][ $key ]['count']++;
					break;

				default:
					$summary['skipped']++;
			}
		}

		$summary['needs_setup'] = array_values( $summary['needs_setup'] );

		// A run that filled its batch probably has more waiting. Come back for
		// the rest shortly rather than leaving it until tomorrow.
		if ( ! $args['dry_run'] && $summary['looked_at'] >= absint( $args['limit'] ) ) {
			$summary['more_to_do'] = true;

			if ( ! wp_next_scheduled( 'cardsco_daily_scan' ) || ! $args['company_id'] ) {
				wp_schedule_single_event( time() + 120, 'cardsco_continue_scan' );
			}
		}

		if ( ! $args['dry_run'] ) {
			update_option( 'cardsco_last_scan', $summary, false );
		}

		return $summary;
	}

	/**
	 * Work out what should happen to one occasion, and do it.
	 *
	 * @param array $occasion Row from CardsCo_Occasion::upcoming().
	 * @param bool  $dry_run Decide but write nothing.
	 * @return array{outcome:string, reason:string, queue_id:int}
	 */
	public static function queue_occasion( array $occasion, $dry_run = false ) {
		$company_id = (int) $occasion['company_id'];
		$type_id    = (int) $occasion['occasion_type_id'];
		$event_date = $occasion['next_occurrence'];

		if ( ! $event_date ) {
			return self::outcome( 'skipped', 'no_date' );
		}

		if ( CardsCo_Queue::exists_for( (int) $occasion['id'], $event_date ) ) {
			return self::outcome( 'already_done' );
		}

		$recipient = CardsCo_Recipient::get( (int) $occasion['recipient_id'] );

		if ( ! $recipient || 'active' !== $recipient['status'] ) {
			return self::outcome( 'skipped', 'recipient_inactive' );
		}

		// A running programme already gathers this person's date into its own
		// box, so leave it alone. Without this the person gets two cards.
		if ( CardsCo_Programme::covers( (int) $occasion['recipient_id'], $type_id, $company_id ) ) {
			return self::outcome( 'skipped', 'in_programme' );
		}

		$defaults = self::defaults_for( $company_id, $type_id );
		$card     = $defaults['card_id'] ? get_post( $defaults['card_id'] ) : null;
		$message  = $defaults['message_id'] ? get_post( $defaults['message_id'] ) : null;

		// No standing choice means no card. Picking artwork on a company's
		// behalf is not a guess worth making.
		if ( ! $card || 'publish' !== $card->post_status || ! $message || 'publish' !== $message->post_status ) {
			return self::outcome( 'unconfigured' );
		}

		$lead     = self::lead_days( $occasion, $company_id );
		$postage  = (int) CardsCo_Settings::get( 'postage_days', null, $company_id );
		$write_by = cardsco_write_by_date( $event_date, $lead );
		$post_by  = cardsco_write_by_date( $event_date, max( 0, $postage ) );

		$template = CardsCo_Message::text( $message->ID );
		$rendered = CardsCo_Merge::render( $template, CardsCo_Merge::context_for_recipient( $recipient ) );

		$status = 'queued';
		$reason = '';

		if ( 'complete' !== cardsco_address_status( $recipient['address'], $recipient['postcode'] ) ) {
			$status = 'on_hold';
			$reason = 'no_address';
		} elseif ( ! empty( $rendered['missing'] ) || ! empty( $rendered['unknown'] ) ) {
			$status = 'on_hold';
			$reason = 'message_gaps';
		}

		if ( $dry_run ) {
			return self::outcome( 'on_hold' === $status ? 'held' : 'queued', $reason );
		}

		$queue_id = CardsCo_Queue::add(
			array(
				'company_id'        => $company_id,
				'source'            => 'occasion',
				'occasion_id'       => (int) $occasion['id'],
				'recipient_id'      => (int) $recipient['id'],
				'recipient_name'    => trim( $recipient['first_name'] . ' ' . $recipient['last_name'] ),
				'recipient_company' => $recipient['recipient_company'],
				'address_snapshot'  => $recipient['address'],
				'postcode'          => $recipient['postcode'],
				'country'           => $recipient['country'],
				'card_id'           => (int) $card->ID,
				'card_title'        => $card->post_title,
				'occasion_label'    => $occasion['label'],
				'message_final'     => $rendered['text'],
				'event_date'        => $event_date,
				'write_by'          => $write_by,
				'post_by'           => $post_by,
				'status'            => $status,
				'hold_reason'       => $reason,
				'unit_price'        => CardsCo_Card::price( $card->ID, $company_id ),
			)
		);

		if ( is_wp_error( $queue_id ) ) {
			return self::outcome( 'skipped', $queue_id->get_error_code() );
		}

		if ( ! $queue_id ) {
			return self::outcome( 'already_done' );
		}

		self::mark_queued( (int) $occasion['id'], $event_date );

		return self::outcome( 'on_hold' === $status ? 'held' : 'queued', $reason, $queue_id );
	}

	/**
	 * How many days before the event this card must be written.
	 *
	 * The occasion can override the type, which can override the company.
	 *
	 * @param array $occasion Occasion row with type_lead_days joined on.
	 * @param int   $company_id Company ID.
	 * @return int
	 */
	public static function lead_days( array $occasion, $company_id ) {
		if ( ! empty( $occasion['lead_days'] ) ) {
			return (int) $occasion['lead_days'];
		}

		if ( ! empty( $occasion['type_lead_days'] ) ) {
			return (int) $occasion['type_lead_days'];
		}

		return (int) CardsCo_Settings::get( 'default_lead_days', null, $company_id );
	}

	/**
	 * Note that an occasion has been dealt with for this date.
	 *
	 * @param int    $occasion_id Occasion ID.
	 * @param string $event_date Y-m-d.
	 * @return void
	 */
	protected static function mark_queued( $occasion_id, $event_date ) {
		global $wpdb;

		$wpdb->update(
			cardsco_table( 'occasions' ),
			array(
				'last_queued_for' => $event_date,
				'updated_at'      => cardsco_now(),
			),
			array( 'id' => absint( $occasion_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Shape a result.
	 *
	 * @param string $outcome Outcome key.
	 * @param string $reason Reason, if any.
	 * @param int    $queue_id Queue ID, if one was made.
	 * @return array
	 */
	protected static function outcome( $outcome, $reason = '', $queue_id = 0 ) {
		return array(
			'outcome'  => $outcome,
			'reason'   => $reason,
			'queue_id' => (int) $queue_id,
		);
	}

	/**
	 * Companies and occasions that have people with dates coming up but no
	 * standing choice of card. This is what the screens warn about.
	 *
	 * @param int $company_id Company ID, 0 for all.
	 * @param int $horizon Days to look ahead.
	 * @return array[]
	 */
	public static function needs_setup( $company_id = 0, $horizon = 0 ) {
		$summary = self::run(
			array(
				'company_id' => $company_id,
				'horizon'    => $horizon,
				'dry_run'    => true,
			)
		);

		return $summary['needs_setup'];
	}

	/**
	 * The last run, for showing on screen.
	 *
	 * @return array|null
	 */
	public static function last_run() {
		$last = get_option( 'cardsco_last_scan' );

		return is_array( $last ) ? $last : null;
	}
}
