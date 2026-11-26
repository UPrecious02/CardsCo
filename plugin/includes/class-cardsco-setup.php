<?php
/**
 * The front-end occasion set-up wizard: everything except the markup.
 *
 * Step order, what each step accepts, how far somebody is allowed to jump,
 * and the saving — all of it mirrors the admin wizard rather than inventing
 * a second set of rules, because both write the same programme row and a
 * disagreement between them would be invisible until a card went out wrong.
 *
 * The per-step markup lives in the views and is built from the design; this
 * class deliberately knows nothing about it.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Setup {

	/**
	 * The query argument that carries the programme being set up.
	 */
	const ARG = 'setup';

	/**
	 * Hook the form handler.
	 *
	 * @return void
	 */
	/**
	 * Send the example CSV for a programme.
	 *
	 * A download is a GET, so it cannot ride on the wizard's POST handler.
	 * It used to go through admin-post.php, which is indeed WordPress's
	 * endpoint for a logged-in request that is not a page — but portal
	 * customers are blocked from /wp-admin/ entirely on this site, so the
	 * link bounced them to the dashboard rather than sending the file. It
	 * is served from the front end now; see CardsCo_Download.
	 *
	 * @return void
	 */
	public static function send_template() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- CardsCo_Download checks the nonce before calling this.
		$programme_id = absint( $_GET['programme'] ?? 0 );

		$company_id = CardsCo_Access::company_id();
		$programme  = CardsCo_Programme::get( $programme_id );

		// Somebody else's programme is simply not there.
		if ( ! $company_id || ! $programme || (int) $programme['company_id'] !== $company_id ) {
			wp_die( esc_html__( 'That occasion could not be found.', 'cardsco' ), '', array( 'response' => 404 ) );
		}

		$body = CardsCo_Programme_Import::template( $programme );
		$name = sanitize_file_name( strtolower( $programme['name'] ) . '-template.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		// A BOM, so Excel opens it as UTF-8 instead of mangling accents.
		echo "\xEF\xBB\xBF";
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, not markup.
		exit;
	}

	/**
	 * The link that downloads the example file.
	 *
	 * @param array $programme Programme row.
	 * @return string
	 */
	public static function template_url( array $programme ) {
		return CardsCo_Download::url( 'people', array( 'programme' => (int) $programme['id'] ) );
	}

	public function init() {

		add_action( 'init', array( $this, 'handle' ) );
	}

	/**
	 * The steps, straight from the model so there is one list.
	 *
	 * The design labels the last one "Review & pay"; the model calls it
	 * "Review" because payment is not built yet. The design's wording is used
	 * on screen and the model's key is used everywhere else.
	 *
	 * @return array[]
	 */
	public static function steps() {
		return CardsCo_Programme::steps();
	}

	/**
	 * Which step is on screen.
	 *
	 * Somebody may go back to any step they have already reached, but not
	 * skip forward past the furthest one they have finished — otherwise a
	 * programme could be activated with its middle left blank.
	 *
	 * @param array $programme Programme row.
	 * @param int   $asked     The step in the query string.
	 * @return int
	 */
	public static function step_on_screen( array $programme, $asked ) {
		$asked    = absint( $asked );
		$furthest = max( 1, (int) $programme['setup_step'] );
		$furthest = min( 7, $furthest );

		if ( $asked < 1 ) {
			return min( 7, $furthest );
		}

		return min( $asked, $furthest, 7 );
	}

	/**
	 * Is this programme's step finished?
	 *
	 * @param array $programme Programme row.
	 * @param int   $step      Step number.
	 * @return bool
	 */
	public static function done( array $programme, $step ) {
		/*
		 * Passed AND finished. This used to answer "has the person been
		 * past this step", which the stepper drew as a tick, so a step
		 * walked through with nothing in it wore the same tick as one that
		 * was actually done. Now that Continue refuses an unfinished step
		 * the two almost always agree — but a programme set up before that
		 * change, or one edited since, can still have a gap in the middle,
		 * and a tick over it would be a lie.
		 */
		if ( (int) $programme['setup_step'] <= (int) $step ) {
			return false;
		}

		return CardsCo_Programme::step_complete( $programme, (int) $step );
	}

	/**
	 * What a step accepts from a form.
	 *
	 * Kept beside the admin's own switch so the two stay in step. Anything
	 * not listed here is ignored rather than passed through.
	 *
	 * @param int   $step Step number.
	 * @param array $post The raw form values.
	 * @return array
	 */
	public static function fields( $step, array $post, array $programme = array() ) {
		$get = static function ( $key, $default = '' ) use ( $post ) {
			return isset( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : $default;
		};

		/*
		 * Keep only what the screen actually sent.
		 *
		 * A step's Continue button submits that screen's own form, and a
		 * screen carries the fields it shows and no others. Returning a
		 * value for a field that was not on screen means saving a blank
		 * over whatever was there: step 4 shows the message but has no
		 * input for it — the writing happens in the card writer, which
		 * saves on its own — so pressing Continue there posted no message
		 * and wiped the message, the sign-off and the handwriting. The
		 * step then read as unfinished, because an occasion with no
		 * message is unfinished, and going back to look re-ran the same
		 * form and wiped it again.
		 *
		 * $always lists the keys that are meant to be saved whether or not
		 * they were posted: a tickbox means false by its absence, and a
		 * value the step sets for itself has no input to arrive from.
		 */
		$only = static function ( array $values, array $always = array() ) use ( $post ) {
			foreach ( array_keys( $values ) as $key ) {
				if ( ! in_array( $key, $always, true ) && ! isset( $post[ $key ] ) ) {
					unset( $values[ $key ] );
				}
			}

			return $values;
		};

		switch ( (int) $step ) {
			case 3:
				return $only( array( 'card_id' => absint( $get( 'card_id', 0 ) ) ) );

			case 4:
				return $only(
					array(
						'message_id'       => absint( $get( 'message_id', 0 ) ),
						// Kept unsanitised on purpose: the model runs it through
						// the merge-field validator, which has to see the braces.
						'message_override' => (string) $get( 'message_override' ),
						'handwriting'      => sanitize_text_field( $get( 'handwriting' ) ),
						'ink'              => sanitize_text_field( $get( 'ink' ) ),
						'signature'        => sanitize_text_field( $get( 'signature' ) ),
					)
				);

			case 5:
				/*
				 * The screen posts five address lines; the model keeps one
				 * block. Always five, padded where empty, so reading them
				 * back out splits cleanly into the same five boxes.
				 */
				$lines = (array) $get( 'ship_line', array() );

				if ( $lines ) {
					$lines   = array_slice( array_pad( $lines, 5, '' ), 0, 5 );
					$lines   = array_map( 'sanitize_text_field', $lines );
					$address = implode( "\n", $lines );
				} else {
					// Anything posting the block directly — the admin screens,
					// an import — still works.
					$address = sanitize_textarea_field( $get( 'ship_address' ) );
				}

				/*
				 * ship_address has no input of its own — it is built from the
				 * five line boxes above — so it is kept whenever either the
				 * lines or the block itself arrived, and dropped when neither
				 * did rather than saved as nothing.
				 */
				$sent = isset( $post['ship_line'] ) || isset( $post['ship_address'] );

				return $only(
					array(
						'shipping_mode' => sanitize_key( $get( 'shipping_mode', 'company' ) ),
						'ship_address'  => $address,
						'ship_postcode' => sanitize_text_field( $get( 'ship_postcode' ) ),
						'ship_country'  => sanitize_text_field( $get( 'ship_country', 'GB' ) ),
					),
					$sent ? array( 'ship_address' ) : array()
				);

			case 6:
				/*
				 * Three shapes of occasion, three different screens, so only
				 * take what the screen in front of the person actually
				 * showed. Saving the lot would wipe the switches on the two
				 * screens that do not carry them, and a step that quietly
				 * turns a setting off is worse than one that does nothing.
				 */
				// Without a programme the shape cannot be worked out, so this
				// falls through to the per-person screen, which is what every
				// occasion used before the other two existed.
				$shape = $programme ? CardsCo_Dashboard::timing_shape( $programme ) : 'per_person';

				if ( 'fixed' === $shape ) {
					return $only(
						array(
							'occasion_type_id' => absint( $get( 'occasion_type_id' ) ),
							'timing_mode'      => 'fixed_date',
							'days_before'      => absint( $get( 'days_before', 7 ) ),
							'recurrence'       => sanitize_key( $get( 'recurrence', 'annual' ) ),
						),
						array( 'timing_mode' )
					);
				}

				if ( 'none' === $shape ) {
					return $only(
						array(
							'timing_mode' => 'one_off',
							'starts_on'   => sanitize_text_field( $get( 'starts_on' ) ),
							'days_before' => absint( $get( 'days_before', 3 ) ),
							// Nothing recurring: a promotion goes out once.
							'recurrence'  => 'once',
						),
						array( 'timing_mode', 'recurrence' )
					);
				}

				return $only(
					array(
						'timing_mode'       => sanitize_key( $get( 'timing_mode', 'monthly_box' ) ),
						'box_arrive_day'    => absint( $get( 'box_arrive_day', 25 ) ),
						'days_before'       => absint( $get( 'days_before', 3 ) ),
						'weekend_rule'      => ! empty( $post['weekend_rule'] ),
						'notify_before_box' => ! empty( $post['notify_before_box'] ),
						'starts_on'         => sanitize_text_field( $get( 'starts_on' ) ),
						'recurrence'        => sanitize_key( $get( 'recurrence', 'annual' ) ),
					),
					array( 'weekend_rule', 'notify_before_box' )
				);
		}

		return array();
	}

	/**
	 * Handle a submitted step.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! isset( $_POST['cardsco_setup_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['cardsco_setup_action'] ) );

		if ( ! isset( $_POST['_cardsco_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cardsco_nonce'] ) ), 'cardsco_setup' ) ) {
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

		$programme_id = absint( $_POST['programme_id'] ?? 0 );
		$programme    = CardsCo_Programme::get( $programme_id );

		// A programme belonging to somebody else is simply not found.
		if ( ! $programme || (int) $programme['company_id'] !== $company_id ) {
			return;
		}

		if ( 'activate' === $action ) {
			$this->activate( $programme );
			return;
		}

		if ( 'pay' === $action ) {
			$this->pay( $programme );
			return;
		}

		if ( 'add_person' === $action ) {
			$this->add_person( $programme );
			return;
		}

		if ( 'add_existing' === $action ) {
			$this->add_existing( $programme );
			return;
		}

		if ( 'remove_person' === $action ) {
			$this->remove_person( $programme );
			return;
		}

		if ( 'upload_choice' === $action ) {
			$this->upload_choice( $programme );
			return;
		}

		if ( 'upload_cancel' === $action ) {
			$this->upload_cancel( $programme );
			return;
		}

		if ( 'write_message' === $action ) {
			$this->write_message( $programme );
			return;
		}

		if ( 'upload_people' === $action ) {
			$this->upload_people( $programme );
			return;
		}

		if ( 'step' === $action ) {
			$this->save_step( $programme );
		}
	}

	/**
	 * Read an uploaded list of people into the programme.
	 *
	 * CardsCo_Programme_Import does the work and has done since the admin
	 * side was built; step 2 just had nothing pointed at it. The "Upload a
	 * file" control was a link to the page it was already on.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function upload_people( array $programme ) {
		$back = array(
			self::ARG => (int) $programme['id'],
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

		/*
		 * Keep the file before doing anything with it, so the question
		 * below can be asked without making somebody upload twice.
		 */
		$path = CardsCo_Programme_Import::stash( (array) $file );

		if ( is_wp_error( $path ) ) {
			$back['cardsco_error'] = $path->get_error_message();
			self::go( $back );
		}

		/*
		 * An upload onto an occasion that already has people could mean
		 * two different things — these are more people, or this is the
		 * list now — and it used to assume the first without asking. A
		 * company re-uploading a corrected spreadsheet quietly ended up
		 * with both versions of it.
		 */
		if ( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) ) {
			set_transient(
				self::upload_key( (int) $programme['id'] ),
				array(
					'path' => $path,
					'name' => sanitize_file_name( (string) $file['name'] ),
				),
				HOUR_IN_SECONDS
			);

			$back['cc_up_ask'] = 1;
			self::go( $back );
		}

		$result = CardsCo_Programme_Import::read_file( (int) $programme['id'], $path );

		if ( is_wp_error( $result ) ) {
			$back['cardsco_error'] = $result->get_error_message();
			self::go( $back );
		}

		/*
		 * Say what happened rather than silently returning to a longer list.
		 * The import reports what it added, what it matched to somebody
		 * already on the company's books, and what it could not read.
		 */
		$back['cc_up_added']   = (int) ( $result['added'] ?? 0 );
		$back['cc_up_matched'] = (int) ( $result['matched'] ?? 0 );
		$back['cc_up_on']      = (int) ( $result['on_occasion'] ?? 0 );
		$back['cc_up_nodate']  = (int) ( $result['missing_date'] ?? 0 );
		$back['cc_up_bad']     = count( (array) ( $result['problems'] ?? array() ) );

		self::go( $back );
	}

	/**
	 * Save what was written in the card writer.
	 *
	 * The length check is repeated here even though the writer counts as you
	 * type and disables its own save: the count in the browser is a courtesy,
	 * not a guarantee, and a message that does not fit the card cannot be
	 * written by a pen.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	/**
	 * Where a refused message waits while the browser follows the redirect.
	 *
	 * @param int $programme_id Programme ID.
	 * @return string
	 */
	protected static function draft_key( $programme_id ) {
		return 'cardsco_writer_' . get_current_user_id() . '_' . absint( $programme_id );
	}

	/**
	 * Put a refused message aside for the writer to pick up.
	 *
	 * The query string is the wrong place for it: a card message runs to
	 * four hundred characters and the redirect would carry every one of
	 * them through the URL bar. Fifteen minutes is longer than the round
	 * trip and short enough that a message never comes back days later.
	 *
	 * @param int    $programme_id Programme ID.
	 * @param string $text         What was written.
	 * @param string $signature    The sign-off.
	 * @return void
	 */
	public static function keep_draft( $programme_id, $text, $signature ) {
		set_transient(
			self::draft_key( $programme_id ),
			array(
				'text'      => (string) $text,
				'signature' => (string) $signature,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Take back a refused message, if one is waiting.
	 *
	 * Reading it clears it, so a message comes back once — on the screen
	 * the error is on — and does not reappear over a saved one later.
	 *
	 * @param int $programme_id Programme ID.
	 * @return array|null
	 */
	public static function take_draft( $programme_id ) {
		$kept = get_transient( self::draft_key( $programme_id ) );

		if ( ! is_array( $kept ) || ! isset( $kept['text'] ) ) {
			return null;
		}

		delete_transient( self::draft_key( $programme_id ) );

		return $kept;
	}

	/**
	 * Throw away any refused message being held for this programme.
	 *
	 * @param int $programme_id Programme ID.
	 * @return void
	 */
	public static function drop_draft( $programme_id ) {
		delete_transient( self::draft_key( $programme_id ) );
	}

	protected function write_message( array $programme ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$text      = CardsCo_Message::clean_text( wp_unslash( $_POST['message'] ?? '' ) );
		$signature = sanitize_text_field( wp_unslash( $_POST['signature'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = array(
			self::ARG  => (int) $programme['id'],
			'step'     => 4,
			'cc_write' => 1,
		);

		/*
		 * A refused save used to throw the message away. The redirect went
		 * back to the writer, the writer read the programme, and the
		 * programme still held whatever was saved last — so somebody who
		 * started from a starter message, changed the words and hit save saw
		 * the starter message come back, unedited, as though the edit had
		 * never happened. The words are put aside here and picked up by the
		 * writer on the way back in, so the error sits above the person's
		 * own sentence rather than a reverted one.
		 */
		$fail = function ( $message ) use ( $back, $text, $signature, $programme ) {
			self::keep_draft( (int) $programme['id'], $text, $signature );
			$back['cardsco_error'] = $message;
			self::go( $back );
		};

		if ( '' === trim( $text ) ) {
			$fail( __( 'Write something before saving.', 'cardsco' ) );
		}

		/*
		 * The starter messages carry [square brackets] for the sender to
		 * replace — an offer, a date, a discount code. A pen writes this in
		 * ink on a card that goes in the post, so "Enjoy [offer] until
		 * [date]" has to be stopped here rather than discovered by the
		 * person who receives it.
		 *
		 * Merge tags are a different thing and are left alone: {first name}
		 * is filled in from the recipient when the card is written.
		 */
		if ( preg_match( '/\[([^\]]{1,40})\]/', $text, $placeholder ) ) {
			$fail(
				sprintf(
					/* translators: %s: the placeholder still in the message, e.g. "offer". */
					__( 'Replace [%s] with your own words before saving — anything in square brackets gets written onto the card exactly as it reads.', 'cardsco' ),
					$placeholder[1]
				)
			);
		}

		/*
		 * Measured on the rendered card, the same way step 4 and the writer
		 * measure it: the merged first name and the sign-off are part of
		 * what has to fit, so counting the raw text would let a message
		 * through that is too long once a name is in it.
		 */
		$max = (int) CardsCo_Settings::get( 'card_max_chars', 400, (int) $programme['company_id'] );

		if ( $max > 0 ) {
			$sample   = $text;
			$ids      = CardsCo_Programme::recipient_ids( (int) $programme['id'] );
			$longest  = 0;

			foreach ( (array) $ids as $rid ) {
				$row = CardsCo_Recipient::get( (int) $rid );

				if ( ! $row ) {
					continue;
				}

				// render() returns a result array; its own length is already
				// measured, so there is no need to count it again here.
				$try = CardsCo_Merge::render( $text, CardsCo_Merge::context_for_recipient( $row ) );

				/*
				 * A tag that resolves to nothing leaves a hole in a sentence
				 * — "from all of us at ." — and a pen writes it exactly as
				 * it reads. {our company} is the one that bites, because it
				 * is blank until a trading name is saved in Settings.
				 */
				if ( ! empty( $try['missing'] ) ) {
					$gap   = (string) $try['missing'][0];
					$known = CardsCo_Merge::fields();
					$label = $known[ $gap ]['label'] ?? str_replace( '_', ' ', $gap );

					$fail(
						'our_company' === $gap
							? __( 'Your company name is blank, so the card would read “from all of us at .” — add a trading name in Settings, or take {our company} out of the message.', 'cardsco' )
							: sprintf(
								/* translators: %s: the name of a merge field, e.g. "Last name". */
								__( '%s is blank for somebody on this occasion, which would leave a gap in the sentence. Take that tag out, or fill the detail in.', 'cardsco' ),
								$label
							)
					);
				}

				if ( (int) $try['length'] > $longest ) {
					$longest = (int) $try['length'];
					$sample  = (string) $try['text'];
				}
			}

			$used = mb_strlen( $sample . ( $signature ? "\n" . $signature : '' ) );

			if ( $used > $max ) {
				$fail(
					sprintf(
						/* translators: 1: characters used, 2: the limit. */
						__( 'That is %1$s characters once a name and your sign-off are in it, and the card holds %2$s.', 'cardsco' ),
						number_format_i18n( $used ),
						number_format_i18n( $max )
					)
				);
			}
		}

		CardsCo_Programme::update(
			(int) $programme['id'],
			array(
				'message_override' => $text,
				'signature'        => $signature,
			)
		);

		// Saved, so nothing is waiting to be handed back.
		self::drop_draft( (int) $programme['id'] );

		self::go(
			array(
				self::ARG => (int) $programme['id'],
				'step'    => 4,
			)
		);
	}

	/**
	 * Add one person to a programme by hand.
	 *
	 * The spreadsheet upload is the fast way in for a whole team; this is
	 * the one for a single new starter, which is the common case after the
	 * first import.
	 *
	 * Three rows go in: the person, the date their card is for, and the link
	 * between them and this programme. The date is checked before any of it
	 * is written, because a recipient created and then left without an
	 * occasion is a person who silently never gets a card.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	/**
	 * Where a waiting upload is kept between the two requests.
	 *
	 * The path never travels through the form. A posted path is a path
	 * somebody could choose, and this one is opened and read.
	 *
	 * @param int $programme_id Programme ID.
	 * @return string
	 */
	protected static function upload_key( $programme_id ) {
		return 'cardsco_up_' . get_current_user_id() . '_' . absint( $programme_id );
	}

	/**
	 * Read the waiting file, adding to the list or becoming it.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function upload_choice( array $programme ) {
		$back = array(
			self::ARG => (int) $programme['id'],
			'step'    => 2,
		);

		$key     = self::upload_key( (int) $programme['id'] );
		$waiting = get_transient( $key );

		if ( ! is_array( $waiting ) || empty( $waiting['path'] ) || ! is_readable( $waiting['path'] ) ) {
			$back['cardsco_error'] = __( 'That upload is no longer waiting. Please upload the file again.', 'cardsco' );
			self::go( $back );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$how = 'replace' === ( $_POST['how'] ?? '' ) ? 'replace' : 'add';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/*
		 * Read the file before touching the list.
		 *
		 * Replacing used to take everyone off and read afterwards, so a
		 * file that turned out to hold nobody — an empty sheet, a header
		 * row on its own, rows with no name in them — emptied the
		 * occasion and put nothing back. The report said so plainly
		 * ("6 people taken off · 0 people from the file are on this
		 * occasion") and by then the work was gone.
		 *
		 * read_into_company() puts the people in the company's books
		 * without touching this occasion, which is what lets the check
		 * happen before anything is lost.
		 */
		$company_id = (int) $programme['company_id'];
		$read       = CardsCo_Programme_Import::read_into_company(
			$company_id,
			CardsCo_Category::primary_type( (string) $programme['category'], $company_id ),
			CardsCo_Category::get( (string) $programme['category'] )['audience'] ?? 'both',
			$waiting['path'],
			true
		);

		delete_transient( $key );

		if ( is_wp_error( $read ) ) {
			$back['cardsco_error'] = $read->get_error_message();
			self::go( $back );
		}

		$coming = array_values( array_unique( array_map( 'absint', (array) $read['recipient_ids'] ) ) );

		/* Nothing to replace the list with, so the list stays as it is. */
		if ( 'replace' === $how && ! $coming ) {
			$back['cardsco_error'] = __( 'There is nobody in that file, so the list has been left as it was.', 'cardsco' );
			self::go( $back );
		}

		$dropped = 0;

		/*
		 * Now it is safe. Taking people off stops their cards and keeps
		 * the dates they gave us; they stay in the company's people list,
		 * because they belong to the company and not to this occasion.
		 */
		if ( 'replace' === $how ) {
			$on      = CardsCo_Programme::recipient_ids( (int) $programme['id'] );
			$dropped = CardsCo_Programme::remove_recipients( (int) $programme['id'], $on );
		}

		CardsCo_Programme::add_recipients( (int) $programme['id'], $coming );

		$result                = (array) $read['summary'];
		$result['on_occasion'] = count( $coming );

		$back['cc_up_added']   = (int) ( $result['added'] ?? 0 );
		$back['cc_up_matched'] = (int) ( $result['matched'] ?? 0 );
		$back['cc_up_on']      = (int) ( $result['on_occasion'] ?? 0 );
		$back['cc_up_nodate']  = (int) ( $result['missing_date'] ?? 0 );
		$back['cc_up_bad']     = count( (array) ( $result['problems'] ?? array() ) );
		$back['cc_up_took']    = (int) $dropped;

		self::go( $back );
	}

	/**
	 * Forget a waiting upload without reading it.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function upload_cancel( array $programme ) {
		$waiting = get_transient( self::upload_key( (int) $programme['id'] ) );

		if ( is_array( $waiting ) && ! empty( $waiting['path'] ) && is_readable( $waiting['path'] ) ) {
			wp_delete_file( $waiting['path'] );
		}

		delete_transient( self::upload_key( (int) $programme['id'] ) );

		self::go(
			array(
				self::ARG => (int) $programme['id'],
				'step'    => 2,
			)
		);
	}

	/**
	 * Take one person off this occasion.
	 *
	 * Only somebody actually on it, which also settles that they belong
	 * to this company — the ids come from a form. Their card stops with
	 * them; the date they gave us is kept, so putting them back does not
	 * mean asking for it again.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function remove_person( array $programme ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$who = absint( $_POST['recipient_id'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = array(
			self::ARG => (int) $programme['id'],
			'step'    => 2,
		);

		if ( ! $who || ! in_array( $who, CardsCo_Programme::recipient_ids( (int) $programme['id'] ), true ) ) {
			$back['cardsco_error'] = __( 'That person is not on this occasion.', 'cardsco' );
			self::go( $back );
			return;
		}

		$person = CardsCo_Recipient::get( $who );
		$name   = $person ? trim( $person['first_name'] . ' ' . $person['last_name'] ) : '';

		CardsCo_Programme::remove_recipients( (int) $programme['id'], array( $who ) );

		$back['cc_dropped'] = $name ? $name : '1';
		self::go( $back );
	}

	/**
	 * Put people the company already has onto this programme.
	 *
	 * Only people the company owns and the occasion is for: the ids come
	 * from a form, so the list offered is worked out again here rather
	 * than trusted. Anyone already on the programme is ignored by the
	 * insert, so pressing the button twice adds nobody twice.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function add_existing( array $programme ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$wanted = array_map( 'absint', (array) ( $_POST['recipients'] ?? array() ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = array(
			self::ARG => (int) $programme['id'],
			'step'    => 2,
		);

		if ( ! $wanted ) {
			$back['cardsco_error'] = __( 'Tick at least one person to add.', 'cardsco' );
			self::go( $back );
			return;
		}

		$offered = wp_list_pluck( CardsCo_Programme::available_recipients( $programme ), 'id' );
		$allowed = array_intersect( $wanted, array_map( 'absint', $offered ) );

		if ( ! $allowed ) {
			/*
			 * Everyone ticked is already on it — a second submit from the
			 * back button, usually. That is not a failure, so it does not
			 * read as one.
			 */
			$on_already = array_intersect( $wanted, CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );

			if ( count( $on_already ) === count( $wanted ) ) {
				$back['cc_joined'] = 0;
			} else {
				$back['cardsco_error'] = __( 'Those people could not be added.', 'cardsco' );
			}

			self::go( $back );
			return;
		}

		/* The id, not the row: this takes an id and absint()s an array to 1. */
		$added = CardsCo_Programme::add_recipients( (int) $programme['id'], $allowed );

		/*
		 * cc_joined, not cc_added: cc_added is one recipient's id and sends
		 * the screen to the add-by-hand form to confirm that person. Adding
		 * two people from the list put a 2 in it, so the wizard read "2" as
		 * a recipient and opened an empty "Person 3" form instead of the
		 * list with the two new people on it.
		 */
		$back['cc_joined'] = (int) $added;
		self::go( $back );
	}

	protected function add_person( array $programme ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$day     = absint( $_POST['event_day'] ?? 0 );
		$month   = absint( $_POST['event_month'] ?? 0 );
		$year    = absint( $_POST['event_year'] ?? 0 );
		$editing = absint( $_POST['person_id'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$back = array(
			self::ARG => (int) $programme['id'],
			'step'    => 2,
			'cc_add'  => 1,
		);

		if ( $editing ) {
			$back['cc_person'] = $editing;
			unset( $back['cc_add'] );
		}

		$fail = function ( $message ) use ( $back ) {
			$back['cardsco_error'] = $message;
			self::go( $back );
		};

		if ( '' === trim( $first . $last ) ) {
			$fail( __( 'Give at least a first or last name.', 'cardsco' ) );
		}

		/*
		 * Only some occasions have a date that differs from person to person.
		 * Christmas is the 25th for everybody; a promotion goes out when the
		 * campaign runs. Where there is no such type, the form does not ask
		 * for a date and this must not insist on one — it used to refuse the
		 * save outright with "this occasion cannot take people one at a
		 * time", after letting the person fill the whole form in.
		 */
		$type       = CardsCo_Category::primary_type( (string) $programme['category'], (int) $programme['company_id'] );
		$per_person = (bool) $type;

		if ( $per_person ) {
			if ( ! $day || ! $month ) {
				$fail( __( 'Pick a day and a month.', 'cardsco' ) );
			}

			/*
			 * An occasion that happens once needs the year as well: there
			 * is no next time for it to come round to. Checked here rather
			 * than left to the save, because a failure down there deletes
			 * the person who was just created and sends back an error
			 * about a field the form had not asked for.
			 */
			$needs_year = 'once' === (string) ( $type['recurrence'] ?? 'annual' );

			if ( $needs_year && ! $year ) {
				$fail(
					sprintf(
						/* translators: %s: occasion label, e.g. "Welcome". */
						__( '%s happens once, so pick a year as well.', 'cardsco' ),
						$type['label']
					)
				);
			}

			// 2024 because it is a leap year: 29 February is a real birthday
			// and must be accepted even though no year is collected.
			if ( ! checkdate( $month, $day, $needs_year ? $year : 2024 ) ) {
				$fail( __( 'That is not a real date.', 'cardsco' ) );
			}
		}

		if ( $editing ) {
			$person = CardsCo_Recipient::get( $editing );

			// Somebody else's person is simply not there.
			if ( ! $person || (int) $person['company_id'] !== (int) $programme['company_id'] ) {
				$fail( __( 'That person could not be found.', 'cardsco' ) );
			}

			CardsCo_Recipient::update(
				$editing,
				array(
					'first_name' => $first,
					'last_name'  => $last,
				)
			);

			$recipient_id = $editing;
		} else {
			$recipient_id = CardsCo_Recipient::create(
				array(
					'company_id' => (int) $programme['company_id'],
					'type'       => 'employee',
					'first_name' => $first,
					'last_name'  => $last,
					'source'     => 'manual',
				)
			);

			if ( is_wp_error( $recipient_id ) ) {
				$fail( $recipient_id->get_error_message() );
			}
		}

		if ( $per_person ) {
			$occasion = CardsCo_Occasion::save(
				array(
					'recipient_id'     => (int) $recipient_id,
					'occasion_type_id' => (int) $type['id'],
					'event_day'        => $day,
					'event_month'      => $month,
					'event_year'       => $year,
				)
			);

			if ( is_wp_error( $occasion ) ) {
				/*
				 * The date was checked above, so this is something
				 * unexpected. A person created a moment ago is removed
				 * rather than left on the company's list with no occasion;
				 * one that was already there is left alone.
				 */
				if ( ! $editing ) {
					CardsCo_Recipient::delete( (int) $recipient_id );
				}

				$fail( $occasion->get_error_message() );
			}
		}

		CardsCo_Programme::add_recipients( (int) $programme['id'], array( (int) $recipient_id ) );

		self::go(
			array(
				self::ARG  => (int) $programme['id'],
				'step'     => 2,
				'cc_added' => (int) $recipient_id,
			)
		);
	}

	/**
	 * Begin a programme for a category.
	 *
	 * @param int $company_id Company ID.
	 * @return void
	 */
	protected function start( $company_id ) {
		$category = sanitize_key( wp_unslash( $_POST['category'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().

		if ( '' === $category ) {
			self::go( array( 'cardsco_error' => __( 'Choose an occasion to carry on.', 'cardsco' ) ) );
		}

		if ( ! CardsCo_Category::is_available( $category ) ) {
			self::go( array( 'cardsco_error' => __( 'That occasion is not available yet.', 'cardsco' ) ) );
		}

		/*
		 * Only occasions that run off a date each person holds are set up
		 * here. The rest — Christmas, seasonal and promotional cards — go
		 * out as a campaign on a day somebody chooses, and can_activate()
		 * has always refused them outright.
		 *
		 * It refused them on step 7, so people, a card, a message, an
		 * address and timing were all filled in first and then thrown away.
		 * The two halves of the plugin disagreed about whether they belonged
		 * here at all: there is a fixed-date timing screen built for exactly
		 * these, and nothing that would ever switch one on. The client
		 * settled it — recurring occasions are the dated ones — so this is
		 * the same refusal, moved to the moment it can still save the work.
		 *
		 * The occasion types are what say whether a category runs off dates,
		 * so this asks whether the install has any at all before trusting
		 * their answer. On one that has never been seeded every category
		 * would read as campaign-driven and the whole wizard would turn
		 * people away. Asking the category for its own types is not enough:
		 * a campaign-driven one can have none.
		 */
		if ( CardsCo_Occasion_Type::all( $company_id )
			&& ! CardsCo_Category::is_dated( $category, $company_id ) ) {
			self::go(
				array(
					'cardsco_error' => __( 'This one goes out as a campaign on a day you choose, not from dates people hold, so it is set up under Campaigns rather than here.', 'cardsco' ),
				)
			);
		}

		/*
		 * Pick up where they left off rather than starting again. Choosing
		 * the same occasion twice used to create a second draft and strand
		 * the first, which, with nothing on the site listing drafts, meant
		 * the earlier work was gone for good.
		 */
		$draft = CardsCo_Programme::draft_for( $company_id, $category );

		if ( $draft ) {
			/*
			 * Drafts created before the fix above are stranded on step 1 and
			 * resuming one would drop the person straight back where they
			 * started, for ever. Choosing the occasion is step 1, so any
			 * draft that exists has passed it.
			 */
			if ( (int) $draft['setup_step'] < 2 ) {
				CardsCo_Programme::update( (int) $draft['id'], array( 'setup_step' => 2 ) );
				$draft['setup_step'] = 2;
			}

			self::go(
				array(
					self::ARG => (int) $draft['id'],
					'step'    => CardsCo_Setup::step_on_screen( $draft, 0 ),
				)
			);
		}

		$id = CardsCo_Programme::create( $company_id, $category );

		if ( is_wp_error( $id ) ) {
			self::go( array( 'cardsco_error' => $id->get_error_message() ) );
		}

		self::go( array( self::ARG => $id, 'step' => 2 ) );
	}

	/**
	 * Save one step and move on.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	/**
	 * The addresses typed into step 5, one per person.
	 *
	 * Only people actually on this programme: the ids come from a form,
	 * so who is on it is worked out again here rather than believed.
	 *
	 * A box left empty clears that person's address, because on this
	 * screen an empty box is somebody deleting what was there — unlike an
	 * empty column in a spreadsheet, which is a column nobody filled in.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected static function save_each_address( array $programme ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		/*
		 * The parts, as the shared address fieldset posts them. It used to
		 * be one textarea per person under `each_address`; the older name
		 * is still read so a form left open across the upgrade, or a draft
		 * posted by a cached page, does not quietly lose what was typed.
		 */
		$lines     = (array) ( $_POST['each_line'] ?? array() );
		$addresses = (array) ( $_POST['each_address'] ?? array() );
		$postcodes = (array) ( $_POST['each_postcode'] ?? array() );
		$countries = (array) ( $_POST['each_country'] ?? array() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $lines && ! $addresses && ! $postcodes && ! $countries ) {
			return;
		}

		$mine = array_flip( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );
		$ids  = array_keys( $lines + $addresses + $postcodes + $countries );

		foreach ( $ids as $rid ) {
			$rid = absint( $rid );

			if ( ! $rid || ! isset( $mine[ $rid ] ) ) {
				continue;
			}

			/*
			 * The parts win where they were posted, because that is the
			 * form the person was actually looking at.
			 */
			if ( isset( $lines[ $rid ] ) ) {
				$parts = array_map(
					static function ( $one ) {
						return sanitize_text_field( wp_unslash( (string) $one ) );
					},
					(array) $lines[ $rid ]
				);

				$address = CardsCo_Dashboard::address_block( $parts );
			} else {
				$address = sanitize_textarea_field( wp_unslash( (string) ( $addresses[ $rid ] ?? '' ) ) );
			}

			$data = array(
				'address'  => $address,
				'postcode' => sanitize_text_field( wp_unslash( (string) ( $postcodes[ $rid ] ?? '' ) ) ),
			);

			/*
			 * Only when it was asked for. An absent country must not
			 * overwrite a stored one with an empty string, which is how a
			 * person imported as 'IE' would have become 'GB' the first
			 * time somebody pressed Continue on step 5.
			 */
			if ( isset( $countries[ $rid ] ) ) {
				$country = strtoupper( sanitize_text_field( wp_unslash( (string) $countries[ $rid ] ) ) );

				if ( '' !== $country ) {
					$data['country'] = substr( $country, 0, 2 );
				}
			}

			CardsCo_Recipient::update( $rid, $data );
		}
	}

	protected function save_step( array $programme ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
		$step = absint( $_POST['step'] ?? 0 );
		$step = max( 1, min( 7, $step ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in handle(); each field is sanitised in fields().
		/*
		 * And nothing may be saved for a step whose predecessors are
		 * unfinished.
		 *
		 * The check below governs the step being posted and nothing in
		 * front of it, so a programme sitting on step 3 with step 2 still
		 * empty — which is every draft made before Continue started
		 * refusing — could be carried on from there, stepping over the gap
		 * rather than through it.
		 */
		/*
		 * The per-person addresses go in before that check, not after it.
		 *
		 * They belong to the people rather than to the step, so they are
		 * worth keeping whatever state the wizard is in — and the check
		 * below redirects, which on a draft left mid-way by an older
		 * version threw away everything somebody had just typed into
		 * twenty address boxes and sent them to another step to find out
		 * why.
		 */
		if ( 5 === $step ) {
			self::save_each_address( $programme );
		}

		for ( $cc_before = 1; $cc_before < $step; $cc_before++ ) {
			if ( CardsCo_Programme::step_complete( $programme, $cc_before ) ) {
				continue;
			}

			$cc_steps = self::steps();

			self::go(
				array(
					self::ARG       => (int) $programme['id'],
					'step'          => $cc_before,
					'cardsco_error' => sprintf(
						/* translators: %s: the name of a step, such as "People". */
						__( 'Finish %s first.', 'cardsco' ),
						$cc_steps[ $cc_before ]['label'] ?? ''
					),
				)
			);
		}

		$data = self::fields( $step, (array) $_POST, $programme );
		$next = min( 7, max( 2, $step + 1 ) );

		/*
		 * What the step sent, saved on its own. The furthest step reached
		 * is not touched yet.
		 *
		 * It used to be set in this same write, before anything was
		 * checked, so Continue moved you on whatever you had filled in.
		 * Press it on step 2 with nobody ticked and you went to step 3,
		 * with no error, and the occasion carried on being set up for
		 * nobody — right up to the payment screen, which refused it with
		 * "Step 2, Who's celebrating, is not finished". Six steps after
		 * the one that could have said so.
		 *
		 * step_on_screen()'s own docblock said a programme must not skip
		 * past the furthest step it has finished. This is what makes that
		 * true: setup_step now means finished, not merely visited.
		 */
		$saved = CardsCo_Programme::update( (int) $programme['id'], $data );

		if ( is_wp_error( $saved ) ) {
			self::go(
				array(
					self::ARG        => (int) $programme['id'],
					'step'           => $step,
					'cardsco_error'  => $saved->get_error_message(),
				)
			);
		}

		$fresh = CardsCo_Programme::get( (int) $programme['id'] );

		if ( $fresh && ! CardsCo_Programme::step_complete( $fresh, $step ) ) {
			$steps = self::steps();

			self::go(
				array(
					self::ARG       => (int) $programme['id'],
					'step'          => $step,
					'cardsco_error' => sprintf(
						/* translators: %s: the name of a step, such as "Who's celebrating". */
						__( 'There is something still to fill in on %s.', 'cardsco' ),
						$steps[ $step ]['label'] ?? ''
					),
				)
			);
		}

		CardsCo_Programme::update(
			(int) $programme['id'],
			array( 'setup_step' => max( (int) $programme['setup_step'], $next ) )
		);

		self::go( array( self::ARG => (int) $programme['id'], 'step' => $next ) );
	}

	/**
	 * Hand over to the checkout.
	 *
	 * The occasion is not turned on here. It is turned on when the payment
	 * completes, which can be a minute later or never — a card gets
	 * declined, a 3-D Secure step is abandoned, somebody closes the tab on
	 * the Stripe page. Turning it on at the point of leaving for the
	 * checkout would mean cards going out to people nobody had paid for.
	 *
	 * The email check is here rather than only at the far end because this
	 * is the last moment there is somebody in front of a screen to tell.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function pay( array $programme ) {
		$stop = function ( $message ) use ( $programme ) {
			self::go(
				array(
					self::ARG       => (int) $programme['id'],
					'step'          => 7,
					'cardsco_error' => $message,
				)
			);
		};

		if ( ! CardsCo_Verify::may_commit() ) {
			$stop( __( 'Please confirm your email address first — we need to be able to reach you before cards start going out.', 'cardsco' ) );
		}

		if ( 'active' === $programme['status'] ) {
			self::go( array( self::ARG => (int) $programme['id'], 'done' => 1 ) );
		}

		/*
		 * can_activate() hands back true or a WP_Error listing what is
		 * missing — and a WP_Error is an object, so "! can_activate()" is
		 * false whatever it says and the guard never fired. That matters
		 * more here than almost anywhere: without it somebody pays, and
		 * then the occasion refuses to turn on with the money already
		 * taken. The check belongs before the checkout, not after it.
		 */
		$ready = CardsCo_Programme::can_activate( $programme );

		if ( is_wp_error( $ready ) ) {
			$stop( $ready->get_error_message() );
		}

		$to = CardsCo_Checkout::start( $programme );

		if ( is_wp_error( $to ) ) {
			$stop( $to->get_error_message() );
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Turn the programme on.
	 *
	 * @param array $programme Programme row.
	 * @return void
	 */
	protected function activate( array $programme ) {
		/*
		 * Turning a programme on is the point where real cards start going
		 * to real addresses and money follows. If we cannot reach the person
		 * who set it up, nobody hears about a failed card, a renewal, or an
		 * address we could not post to. Everything up to here stays open to
		 * an unconfirmed account; this does not.
		 */
		if ( ! CardsCo_Verify::may_commit() ) {
			self::go(
				array(
					self::ARG       => (int) $programme['id'],
					'step'          => 7,
					'cardsco_error' => __( 'Please confirm your email address first — we need to be able to reach you before cards start going out.', 'cardsco' ),
				)
			);
		}

		$done = CardsCo_Programme::activate( (int) $programme['id'] );

		if ( is_wp_error( $done ) ) {
			self::go(
				array(
					self::ARG       => (int) $programme['id'],
					'step'          => 7,
					'cardsco_error' => $done->get_error_message(),
				)
			);
		}

		self::go( array( self::ARG => (int) $programme['id'], 'done' => 1 ) );
	}

	/**
	 * Redirect back to the page the form was on.
	 *
	 * Always a redirect after a POST, so a refresh cannot save twice.
	 *
	 * @param array $args Query arguments to add.
	 * @return void
	 */
	public static function go( array $args ) {
		$strip = array(
			self::ARG,
			'step',
			'done',
			'cardsco_error',
			'cc_add',
			'cc_added',
			'cc_joined',
			'cc_dropped',
			'cc_up_ask',
			'cc_up_took',
			'cc_up_on',
			'cc_person',
			'cc_write',
			'cc_up_added',
			'cc_up_matched',
			'cc_up_nodate',
			'cc_up_bad',
		);

		/*
		 * The page to return to is the one being posted to, which is the one
		 * the form is on: every wizard form's action is $links['here'].
		 *
		 * This used to ask wp_get_referer(), and it sent people to the home
		 * page after every single save. WordPress deliberately returns false
		 * from wp_get_referer() when the referer matches the current request
		 * URI — it is meant for "where did you come from before this page",
		 * not "what page am I on" — and a form posting to itself is exactly
		 * that case, so the fallback fired every time. Continue on step 1
		 * landed on the home page with the right query string attached to
		 * the wrong page, and so did every other step.
		 *
		 * remove_query_arg() with no URL reads the current request, which is
		 * what is wanted here.
		 */
		$base = remove_query_arg( $strip );

		// Only if there is no request to read, which in practice means CLI.
		if ( ! $base ) {
			$referer = wp_get_referer();
			$base    = remove_query_arg( $strip, $referer ? $referer : home_url( '/' ) );
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}
}
