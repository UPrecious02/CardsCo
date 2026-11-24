<?php
/**
 * The campaign builder, in the portal.
 *
 * A campaign is the one-off send: pick an occasion, pick who gets it,
 * pick a card, write the message, optionally add an offer code, choose
 * the date, pay. Seven steps, the same shape as the occasion wizard, and
 * deliberately so — somebody who has set up one occasion should not have
 * to learn a second set of habits to send a campaign.
 *
 * The model underneath already existed and is left alone: this is the
 * screen in front of it, and the handler that takes what the screen
 * sends. CardsCo_Campaign decides what a campaign means; this decides
 * what happens when somebody presses Continue.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the campaign builder's form posts.
 */
class CardsCo_Builder {

	/** The query argument naming the campaign being built. */
	const ARG = 'campaign';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/**
	 * The steps, from the model.
	 *
	 * @return array
	 */
	public static function steps() {
		return CardsCo_Campaign::steps();
	}

	/**
	 * Which step to actually show.
	 *
	 * Somebody linking to step 6 of a campaign that has only reached step 2
	 * is shown step 2. A step they have already passed is theirs to go back
	 * to, because going back to change the message is a normal thing to
	 * want, but skipping ahead past the fields that feed the later steps
	 * only produces a screen that cannot be filled in.
	 *
	 * @param array $campaign The campaign.
	 * @param int   $asked    The step asked for.
	 * @return int
	 */
	public static function step_on_screen( array $campaign, $asked ) {
		$steps  = self::steps();
		$asked  = absint( $asked );
		$reached = max( 1, (int) $campaign['builder_step'] );

		/*
		 * Once it is paid for, there is nothing left to fill in.
		 *
		 * The shell already knew this — it writes "Campaign · scheduled"
		 * in the header and stops linking the step list — but not one of
		 * the seven step views did, so underneath that header every field
		 * was still live and Continue still saved. A scheduled campaign
		 * could be walked back to step 6 and given a new arrival date,
		 * which changed the campaign and not the cards already queued
		 * against the old one.
		 *
		 * Sending every request to the last step means the only screen a
		 * paid campaign has is the summary, which is read-only, and the
		 * way to change one is to cancel it.
		 */
		if ( 'draft' !== $campaign['status'] ) {
			return count( $steps );
		}

		if ( $asked < 1 || ! isset( $steps[ $asked ] ) ) {
			$asked = $reached;
		}

		return min( $asked, min( $reached, count( $steps ) ) );
	}

	/**
	 * What a step saves, taking only what its own screen sent.
	 *
	 * The same rule as the occasion wizard, and for the same reason: a
	 * screen carries the fields it shows and no others, so returning a
	 * value for a field that was not on screen saves a blank over whatever
	 * was there. On the occasion wizard that silently wiped people's
	 * messages, and the message step here works the same way — the writing
	 * happens in the card writer, which saves on its own.
	 *
	 * $always lists keys meant to be saved whether or not they arrived: a
	 * tickbox means false by its absence.
	 *
	 * @param int   $step     Which step.
	 * @param array $post     The submitted values.
	 * @return array
	 */
	public static function fields( $step, array $post ) {
		$get = static function ( $key, $default = '' ) use ( $post ) {
			return isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : $default;
		};

		$only = static function ( array $values, array $always = array() ) use ( $post ) {
			foreach ( array_keys( $values ) as $key ) {
				if ( ! in_array( $key, $always, true ) && ! isset( $post[ $key ] ) ) {
					unset( $values[ $key ] );
				}
			}

			return $values;
		};

		switch ( (int) $step ) {
			case 1:
				return $only(
					array(
						'name'             => sanitize_text_field( $get( 'name' ) ),
						'occasion_type_id' => absint( $get( 'occasion_type_id' ) ),
					)
				);

			case 2:
				/*
				 * The three lists arrive as arrays of ids and are stored as
				 * one JSON column. They are only touched when the screen
				 * sent at least one of them, so a Continue with nothing
				 * ticked does not empty an audience chosen earlier.
				 */
				$sent = isset( $post['segments'] ) || isset( $post['recipients'] ) || isset( $post['exclude'] );

				if ( ! $sent ) {
					return array();
				}

				return array(
					'audience' => CardsCo_Campaign::clean_audience(
						array(
							'segments'   => (array) $get( 'segments', array() ),
							'recipients' => (array) $get( 'recipients', array() ),
							'exclude'    => (array) $get( 'exclude', array() ),
						)
					),
				);

			case 3:
				return $only( array( 'card_id' => absint( $get( 'card_id' ) ) ) );

			case 4:
				return $only(
					array(
						'message_template_id' => absint( $get( 'message_template_id' ) ),
						// Unsanitised on purpose: the model's merge-field
						// validator has to see the braces.
						'message_body'        => (string) $get( 'message_body' ),
					)
				);

			case 5:
				/*
				 * The offer is a tickbox and three fields behind it. The
				 * tickbox is always saved, because unticking it is how an
				 * offer is removed; the fields behind it are only saved
				 * when they arrive.
				 */
				return $only(
					array(
						'offer_enabled'     => ! empty( $post['offer_enabled'] ),
						'offer_code'        => sanitize_text_field( $get( 'offer_code' ) ),
						'offer_description' => sanitize_text_field( $get( 'offer_description' ) ),
						'offer_expires'     => sanitize_text_field( $get( 'offer_expires' ) ),
					),
					array( 'offer_enabled' )
				);

			case 6:
				return $only( array( 'send_date' => sanitize_text_field( $get( 'send_date' ) ) ) );
		}

		return array();
	}

	/**
	 * Deal with a posted step.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! isset( $_POST['cardsco_builder_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['cardsco_builder_action'] ) );

		if ( ! isset( $_POST['_cardsco_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cardsco_nonce'] ) ), 'cardsco_builder' ) ) {
			return;
		}

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return;
		}

		if ( 'start' === $action ) {
			$this->start( $company_id );
			return;
		}

		$campaign_id = absint( $_POST[ self::ARG ] ?? 0 );
		$campaign    = CardsCo_Campaign::get( $campaign_id );

		// A campaign belonging to somebody else is simply not found.
		if ( ! $campaign || (int) $campaign['company_id'] !== $company_id ) {
			return;
		}

		if ( 'step' === $action ) {
			$this->step( $campaign );
			return;
		}

		if ( 'pay' === $action ) {
			$this->pay( $campaign );
			return;
		}

		if ( 'cancel' === $action ) {
			$this->cancel( $campaign );
			return;
		}

		if ( 'upload_people' === $action ) {
			$this->upload_people( $campaign );
			return;
		}

		if ( 'add_person' === $action ) {
			$this->add_person( $campaign );
			return;
		}
	}

	/**
	 * Read a list of people in from the step that asks who gets the card.
	 *
	 * A company that has just signed up has nobody on its books, and that
	 * step used to say so and stop: "Nobody has been added to this company
	 * yet", with nothing to click. The only way to add anybody was a screen
	 * in the WordPress admin that a company account cannot reach at all, so
	 * the first campaign somebody tried to build could not be finished.
	 *
	 * Whoever is read in is ticked straight away. Somebody who uploads a
	 * list on this step means that list to get the card.
	 *
	 * @param array $campaign Campaign row.
	 * @return void
	 */
	protected function upload_people( array $campaign ) {
		$back = array(
			self::ARG => (int) $campaign['id'],
			'step'    => 2,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$file = isset( $_FILES['people_file'] ) ? $_FILES['people_file'] : null;

		if ( ! $file || empty( $file['name'] ) ) {
			$back['cardsco_error'] = __( 'Choose a file to upload.', 'cardsco' );
			self::go( $back );
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$back['cardsco_error'] = ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] )
				? __( 'That file is too large to upload.', 'cardsco' )
				: __( 'The file could not be uploaded. Please try again.', 'cardsco' );
			self::go( $back );
		}

		$moved = CardsCo_Programme_Import::stash( (array) $file );

		if ( is_wp_error( $moved ) ) {
			$back['cardsco_error'] = $moved->get_error_message();
			self::go( $back );
		}

		$read = CardsCo_Programme_Import::read_into_company(
			(int) $campaign['company_id'],
			self::occasion_type( $campaign ),
			self::audience_for( $campaign ),
			$moved
		);

		if ( is_wp_error( $read ) ) {
			$back['cardsco_error'] = $read->get_error_message();
			self::go( $back );
		}

		$this->tick( $campaign, $read['recipient_ids'] );

		$summary               = (array) $read['summary'];
		$back['cc_up_added']   = (int) ( $summary['added'] ?? 0 );
		$back['cc_up_matched'] = (int) ( $summary['matched'] ?? 0 );
		$back['cc_up_nodate']  = (int) ( $summary['missing_date'] ?? 0 );
		$back['cc_up_bad']     = (int) ( $summary['problem_count'] ?? 0 );

		self::go( $back );
	}

	/**
	 * Add one person from the step that asks who gets the card.
	 *
	 * Somebody trying the site with three colleagues should not have to make
	 * a spreadsheet first.
	 *
	 * @param array $campaign Campaign row.
	 * @return void
	 */
	protected function add_person( array $campaign ) {
		$back = array(
			self::ARG => (int) $campaign['id'],
			'step'    => 2,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$post  = wp_unslash( $_POST );
		$first = sanitize_text_field( $post['first_name'] ?? '' );
		$last  = sanitize_text_field( $post['last_name'] ?? '' );
		$email = sanitize_email( $post['email'] ?? '' );

		if ( '' === trim( $first ) && '' === trim( $last ) ) {
			$back['cardsco_error'] = __( 'A name is needed, so we know who the card is for.', 'cardsco' );
			self::go( $back );
		}

		if ( '' !== (string) ( $post['email'] ?? '' ) && ! is_email( $email ) ) {
			$back['cardsco_error'] = __( 'That email address does not look right.', 'cardsco' );
			self::go( $back );
		}

		$audience  = self::audience_for( $campaign );
		$recipient = CardsCo_Recipient::create(
			array(
				'company_id' => (int) $campaign['company_id'],
				'type'       => in_array( $audience, array( 'customer', 'employee' ), true ) ? $audience : 'customer',
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'source'     => 'manual',
			)
		);

		if ( is_wp_error( $recipient ) ) {
			$back['cardsco_error'] = $recipient->get_error_message();
			self::go( $back );
		}

		$this->tick( $campaign, array( (int) $recipient ) );

		$back['cc_added'] = (int) $recipient;

		self::go( $back );
	}

	/**
	 * Put people on a campaign's audience without disturbing who is there.
	 *
	 * @param array $campaign Campaign row.
	 * @param int[] $ids      People to add.
	 * @return void
	 */
	protected function tick( array $campaign, array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return;
		}

		/* clean_audience() decodes the stored JSON and fills in the three
		   keys, so it reads what is there as well as tidying what goes back. */
		$audience = CardsCo_Campaign::clean_audience( $campaign['audience'] );

		/*
		 * Added to whoever was picked already, and taken off the exclusions:
		 * somebody who uploads a name means it to get a card, even if that
		 * name was crossed off a while ago.
		 */
		$audience['recipients'] = array_values( array_unique( array_merge( (array) $audience['recipients'], $ids ) ) );
		$audience['exclude']    = array_values( array_diff( (array) $audience['exclude'], $ids ) );

		CardsCo_Campaign::update(
			(int) $campaign['id'],
			array( 'audience' => CardsCo_Campaign::clean_audience( $audience ) )
		);
	}

	/**
	 * The occasion type a campaign's dates should be read against, if any.
	 *
	 * @param array $campaign Campaign row.
	 * @return array|null
	 */
	protected static function occasion_type( array $campaign ) {
		$type_id = (int) ( $campaign['occasion_type_id'] ?? 0 );

		return $type_id ? CardsCo_Occasion_Type::get( $type_id ) : null;
	}

	/**
	 * Who a campaign is aimed at.
	 *
	 * @param array $campaign Campaign row.
	 * @return string
	 */
	protected static function audience_for( array $campaign ) {
		$type = self::occasion_type( $campaign );

		return (string) ( $type['applies_to'] ?? 'both' );
	}

	/**
	 * Start a new campaign and open it at step 1.
	 *
	 * @param int $company_id The company.
	 * @return void
	 */
	protected function start( $company_id ) {
		$made = CardsCo_Campaign::create( $company_id );

		if ( is_wp_error( $made ) ) {
			self::go( array( 'cardsco_error' => $made->get_error_message() ) );
		}

		/*
		 * Arriving from an occasion tile that belongs here rather than in
		 * the wizard — Christmas, a promotion. The occasion comes with the
		 * link, so step 1 opens with it already chosen instead of asking
		 * again for something the person has just picked.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$type = isset( $_POST['occasion_type_id'] ) ? absint( $_POST['occasion_type_id'] ) : 0;

		if ( $type && CardsCo_Occasion_Type::get( $type ) ) {
			CardsCo_Campaign::update( (int) $made, array( 'occasion_type_id' => $type ) );
		}

		self::go(
			array(
				self::ARG => (int) $made,
				'step'    => 1,
			)
		);
	}

	/**
	 * Save a step and move on.
	 *
	 * @param array $campaign The campaign.
	 * @return void
	 */
	protected function step( array $campaign ) {
		$steps = self::steps();
		$step  = absint( $_POST['step'] ?? 0 );

		if ( ! isset( $steps[ $step ] ) ) {
			return;
		}

		$back = array(
			self::ARG => (int) $campaign['id'],
			'step'    => $step,
		);

		/*
		 * And nothing may be saved for a campaign that has been paid for.
		 *
		 * The screens are read-only above, but a form left open in another
		 * tab before payment will still post, and that post would land on
		 * a campaign whose cards are already queued.
		 */
		if ( 'draft' !== $campaign['status'] ) {
			self::go(
				array(
					self::ARG       => (int) $campaign['id'],
					'cardsco_error' => __( 'This campaign has been paid for and is in the diary, so it cannot be changed. Cancel it if you need something different.', 'cardsco' ),
				)
			);
		}

		/*
		 * Nothing may be saved for a step whose predecessors are unfinished.
		 *
		 * step_on_screen() keeps the screens in order, but it only governs
		 * what is drawn. The handler took whatever step number arrived and
		 * checked that one alone, so a post for step 3 while step 2 was
		 * still empty saved the card and moved the marker to 4 — stepping
		 * over the unfinished step rather than through it. A stale tab left
		 * open on an earlier visit is enough to do that by accident.
		 *
		 * Going back is untouched: a step behind the one on screen has its
		 * predecessors finished by definition.
		 */
		for ( $cc_before = 1; $cc_before < $step; $cc_before++ ) {
			if ( CardsCo_Campaign::step_complete( $campaign, $cc_before ) ) {
				continue;
			}

			self::go(
				array(
					self::ARG        => (int) $campaign['id'],
					'step'           => $cc_before,
					'cardsco_error'  => sprintf(
						/* translators: %s: the name of a step, such as "Who gets it". */
						__( 'Finish %s first.', 'cardsco' ),
						$steps[ $cc_before ]['label']
					),
				)
			);
		}

		$data = self::fields( $step, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- fields() sanitises per field.

		/*
		 * What the step sent is saved first, on its own. The furthest step
		 * reached is NOT touched yet.
		 *
		 * It used to be set here, in the same write, and that quietly
		 * defeated the gate: a step that failed its check still moved the
		 * furthest-reached marker forward before the check ran, so the
		 * error message came back and the next step unlocked anyway. Press
		 * Continue on an empty audience, read "there is something still to
		 * fill in", click step 3 in the stepper, and carry on — all the way
		 * to a review screen reading "0 people".
		 */
		/*
		 * Switching the offer on puts it into the wording.
		 *
		 * The code only ever reaches a card through {offer code} in the
		 * message. Turn the offer on, type a code, and unless you also
		 * went back and wrote that field in yourself the card went out
		 * without it — the review screen warned, but the warning read as a
		 * note rather than "this will not work". Putting the field in the
		 * wording at the moment it is switched on means it is there to see
		 * and to move, rather than something to know about.
		 */
		if ( 5 === $step && ! empty( $data['offer_enabled'] ) ) {
			$wording = (string) $campaign['message_body'];

			if ( '' !== trim( $wording ) && ! preg_match( '/\{\s*offer[ _-]?code\s*\}/i', $wording ) ) {
				$data['message_body'] = rtrim( $wording ) . "\n\n" . __( 'Use code {offer code}.', 'cardsco' );
			}
		}

		$saved = CardsCo_Campaign::update( (int) $campaign['id'], $data );

		if ( is_wp_error( $saved ) ) {
			$back['cardsco_error'] = $saved->get_error_message();
			self::go( $back );
		}

		/*
		 * Save and exit comes before the check, because a draft is allowed
		 * to be half-finished — that is what a draft is. It used to sit
		 * after, so on an incomplete step the button saved the work and
		 * then refused to leave, which left the footer's own exit unusable
		 * on exactly the screens somebody would want to walk away from.
		 * Nothing escapes this way: can_schedule() checks every step again
		 * before the campaign can be paid for.
		 */
		if ( isset( $_POST['save_exit'] ) ) {
			self::go( array( 'saved' => 1 ) );
		}

		$fresh = CardsCo_Campaign::get( (int) $campaign['id'] );

		/*
		 * A step gets to object to what it was given, in its own words,
		 * here — not six steps later at the payment screen.
		 *
		 * The date was the case that proved it: step 6 asked only whether
		 * a date had been entered, so tomorrow passed, and the first time
		 * anybody said it was too soon was the review, with the Pay button
		 * refusing. The place to say it is the screen that asked.
		 */
		$cc_says = $fresh ? self::objection( $step, $fresh ) : '';

		if ( '' !== $cc_says ) {
			$back['cardsco_error'] = $cc_says;
			self::go( $back );
		}

		if ( $fresh && ! CardsCo_Campaign::step_complete( $fresh, $step ) ) {
			$back['cardsco_error'] = sprintf(
				/* translators: %s: the name of a step, such as "Message". */
				__( 'There is something still to fill in on %s.', 'cardsco' ),
				$steps[ $step ]['label']
			);
			self::go( $back );
		}

		/*
		 * Only now, with the step actually finished, does the furthest step
		 * reached move. It only ever goes forward: going back to change the
		 * message must not make the later steps unreachable.
		 */
		CardsCo_Campaign::update(
			(int) $campaign['id'],
			array(
				'builder_step' => max( (int) $campaign['builder_step'], min( $step + 1, count( $steps ) ) ),
			)
		);

		self::go(
			array(
				self::ARG => (int) $campaign['id'],
				'step'    => min( $step + 1, count( $steps ) ),
			)
		);
	}

	/**
	 * What a step has to say about what it was just given.
	 *
	 * Separate from step_complete(), which answers only whether a step has
	 * been filled in at all. This answers whether what was filled in will
	 * actually work, in words the person can act on, and it runs on the
	 * step that asked rather than at the review.
	 *
	 * can_schedule() still checks all of this again before any money
	 * changes hands. That is the backstop; this is so nobody reaches it.
	 *
	 * @param int   $step     Which step was posted.
	 * @param array $campaign The campaign as it now stands.
	 * @return string An empty string when there is nothing to say.
	 */
	protected static function objection( $step, array $campaign ) {
		if ( 6 !== (int) $step ) {
			return '';
		}

		$date = (string) $campaign['send_date'];

		if ( '' === $date || '0000-00-00' === $date ) {
			return '';
		}

		$today = cardsco_now( 'date' );

		if ( $date < $today ) {
			return __( 'That date has already gone by. Pick a day in the future.', 'cardsco' );
		}

		$earliest = CardsCo_Campaign::earliest_send_date( (int) $campaign['company_id'] );

		if ( $date < $earliest ) {
			return sprintf(
				/* translators: %s: the earliest date that can be chosen, already formatted. */
				__( 'That is too soon — every card is written by hand, so we need a few days. The soonest we can manage is %s.', 'cardsco' ),
				date_i18n( get_option( 'date_format' ), strtotime( $earliest ) )
			);
		}

		return '';
	}

	/**
	 * Take payment for a campaign, and schedule it once the money lands.
	 *
	 * Nothing is scheduled here. The campaign is checked over first, so
	 * nobody is sent to a payment page for something that will refuse to
	 * go out afterwards, and the scheduling itself happens on the back of
	 * the paid order.
	 *
	 * @param array $campaign The campaign.
	 * @return void
	 */
	protected function pay( array $campaign ) {
		$back = array(
			self::ARG => (int) $campaign['id'],
			'step'    => count( self::steps() ),
		);

		$stop = static function ( $message ) use ( $back ) {
			$back['cardsco_error'] = $message;
			self::go( $back );
		};

		$ready = CardsCo_Campaign::can_schedule( $campaign );

		if ( is_wp_error( $ready ) ) {
			$stop( $ready->get_error_message() );
		}

		$to = CardsCo_Checkout::start_campaign( $campaign );

		if ( is_wp_error( $to ) ) {
			$stop( $to->get_error_message() );
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Cancel a campaign that has not gone out.
	 *
	 * @param array $campaign The campaign.
	 * @return void
	 */
	protected function cancel( array $campaign ) {
		$done = CardsCo_Campaign::cancel( (int) $campaign['id'] );

		if ( is_wp_error( $done ) ) {
			self::go(
				array(
					self::ARG        => (int) $campaign['id'],
					'cardsco_error'  => $done->get_error_message(),
				)
			);
		}

		self::go( array( 'cancelled' => 1 ) );
	}

	/**
	 * Back to the page being posted to, with these arguments on it.
	 *
	 * The same reasoning as the occasion wizard's: the page to return to
	 * is the one the form is on, which is the one being posted to, and
	 * wp_get_referer() deliberately answers false for a form posting to
	 * itself.
	 *
	 * @param array $args What to put in the query string.
	 * @return void
	 */
	public static function go( array $args ) {
		$strip = array( self::ARG, 'step', 'new', 'saved', 'cancelled', 'cardsco_error' );
		$base  = remove_query_arg( $strip );

		if ( ! $base ) {
			$referer = wp_get_referer();
			$base    = remove_query_arg( $strip, $referer ? $referer : home_url( '/' ) );
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}
}
