<?php
/**
 * What the front-end dashboard shows.
 *
 * Read-only, and every query here takes its company from CardsCo_Access
 * rather than from an argument. That is deliberate: a method that accepted a
 * company ID could be called with somebody else's, and sooner or later would
 * be. There is nothing to get wrong at the call site because there is nothing
 * to pass.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Dashboard {

	/**
	 * The headline numbers.
	 *
	 * @return array
	 */
	public static function summary() {
		$company_id = CardsCo_Access::company_id();

		$empty = array(
			'people'        => 0,
			'occasions'     => 0,
			'upcoming'      => 0,
			'campaigns'     => 0,
			'next_box'      => null,
			'cards_on_hold' => 0,
			'spend_year'    => '0.00',
			'currency'      => 'GBP',
		);

		if ( ! $company_id ) {
			return $empty;
		}

		$out = $empty;

		$out['currency']  = (string) CardsCo_Settings::get( 'currency', null, $company_id );
		$out['people']    = (int) CardsCo_Recipient::counts( $company_id )['total'];
		$out['occasions'] = count( CardsCo_Programme::active( $company_id ) );
		$out['upcoming']  = count( self::upcoming( 30 ) );

		$out['campaigns'] = count(
			array_filter(
				CardsCo_Campaign::for_company( $company_id ),
				static function ( $campaign ) {
					return in_array( $campaign['status'], array( 'draft', 'scheduled', 'locked' ), true );
				}
			)
		);

		$boxes = CardsCo_Box::outstanding( array( 'company_id' => $company_id, 'limit' => 1 ) );

		if ( $boxes ) {
			$out['next_box'] = $boxes[0];
		}

		$out['cards_on_hold'] = self::count_queue( array( 'on_hold' ) );
		$out['spend_year']    = self::spend_this_year();

		return $out;
	}

	/**
	 * Occasions coming up, soonest first.
	 *
	 * Built from the people in this company's running occasions, so it shows
	 * what will actually produce a card rather than every date on file.
	 *
	 * @param int $days How far ahead to look.
	 * @param int $limit Most to return.
	 * @return array[] Each with name, label, date and days_away.
	 */
	public static function upcoming( $days = 30, $limit = 50 ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.id, o.event_day, o.event_month, o.next_occurrence, o.occasion_type_id,
				        r.first_name, r.last_name, t.label
				 FROM ' . cardsco_table( 'occasions' ) . ' o
				 INNER JOIN ' . cardsco_table( 'recipients' ) . " r
				   ON r.id = o.recipient_id AND r.status = 'active'
				 INNER JOIN " . cardsco_table( 'occasion_types' ) . ' t
				   ON t.id = o.occasion_type_id
				 INNER JOIN ' . cardsco_table( 'programme_recipients' ) . ' pr
				   ON pr.recipient_id = r.id
				 INNER JOIN ' . cardsco_table( 'programmes' ) . " p
				   ON p.id = pr.programme_id AND p.status = 'active'
				 WHERE r.company_id = %d AND o.is_active = 1
				   AND o.next_occurrence IS NOT NULL
				   AND o.next_occurrence >= %s
				   AND o.next_occurrence <= %s
				 GROUP BY o.id
				 ORDER BY o.next_occurrence ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				cardsco_now( 'date' ),
				gmdate( 'Y-m-d', strtotime( cardsco_now( 'date' ) . ' +' . absint( $days ) . ' days' ) ),
				absint( $limit )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['name']      = trim( $row['first_name'] . ' ' . $row['last_name'] );
			$rows[ $i ]['days_away'] = cardsco_days_away( $row['next_occurrence'] );
		}

		return $rows;
	}

	/**
	 * This company's running occasions.
	 *
	 * @return array[]
	 */
	public static function programmes() {
		$company_id = CardsCo_Access::company_id();

		return $company_id ? CardsCo_Programme::for_company( $company_id ) : array();
	}

	/**
	 * This company's campaigns.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public static function campaigns( $limit = 50 ) {
		$company_id = CardsCo_Access::company_id();

		return $company_id
			? CardsCo_Campaign::for_company( $company_id, array( 'limit' => $limit ) )
			: array();
	}

	/**
	 * This company's boxes, newest period first.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public static function boxes( $limit = 24 ) {
		$company_id = CardsCo_Access::company_id();

		return $company_id
			? CardsCo_Box::for_company( $company_id, array( 'limit' => $limit ) )
			: array();
	}

	/**
	 * The renewal panel on the Occasions screen.
	 *
	 * Shown when a reminder has been followed, and also whenever something
	 * is paused or close to falling due — a person who deleted the email
	 * should not be the only one who cannot find out why their cards have
	 * stopped.
	 *
	 * @return array|null Null when there is nothing to say.
	 */
	public static function renewal_panel() {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks a panel, takes no action.
		$asked = isset( $_GET['renew'] ) ? absint( $_GET['renew'] ) : 0;
		$found = null;

		foreach ( CardsCo_Programme::for_company( $company_id, 'any' ) as $programme ) {
			if ( ! in_array( (string) $programme['status'], array( 'active', 'paused' ), true ) ) {
				continue;
			}

			if ( ! CardsCo_Renewal::recurring( $programme ) || empty( $programme['renews_on'] ) ) {
				continue;
			}

			if ( $asked && (int) $programme['id'] !== $asked ) {
				continue;
			}

			$due   = strtotime( (string) $programme['renews_on'] );
			$weeks = CardsCo_Renewal::offsets();
			$from  = strtotime( '-' . ( $weeks ? max( $weeks ) : 4 ) . ' weeks', $due );

			/* Nothing to say about a year that has months left to run. */
			if ( ! $asked && $from > (int) current_time( 'timestamp' ) && 'paused' !== (string) $programme['status'] ) {
				continue;
			}

			$found = $programme;
			break;
		}

		if ( ! $found ) {
			return null;
		}

		$quote    = CardsCo_Renewal::quote( $found );
		$category = CardsCo_Category::get( (string) $found['category'] );
		$paused   = 'paused' === (string) $found['status'];
		$due      = strtotime( (string) $found['renews_on'] );

		return array(
			'id'      => (int) $found['id'],
			'paused'  => $paused,
			'label'   => $category ? $category['label'] : __( 'Your occasion', 'cardsco' ),
			'heading' => $paused
				? __( 'This occasion has paused', 'cardsco' )
				: __( 'Time to renew', 'cardsco' ),
			'blurb'   => $paused
				? __( 'The year it was paid for has ended, so no more cards are being written. Nothing is being charged. Everything you set up is kept exactly as it is.', 'cardsco' )
				: sprintf(
					/* translators: %s: the date it falls due. */
					__( 'The year it is paid for ends on %s. Renew before then and nothing stops.', 'cardsco' ),
					date_i18n( 'j F Y', $due )
				),
			'people'  => $quote['people'],
			'total'   => CardsCo_Shortcodes::money(
				number_format( $quote['total'], 2, '.', '' ),
				$quote['currency']
			),
			'each'    => CardsCo_Shortcodes::money(
				number_format( $quote['each'], 2, '.', '' ),
				$quote['currency']
			),
			'note'    => sprintf(
				/* translators: %s: how many people. */
				__( 'That is one card each for the %s on the list today, for the coming year.', 'cardsco' ),
				sprintf(
					/* translators: %s: how many people. */
					_n( '%s person', '%s people', $quote['people'], 'cardsco' ),
					number_format_i18n( $quote['people'] )
				)
			),
			'button'  => $paused
				? __( 'Pay and start it again', 'cardsco' )
				: __( 'Renew for a year', 'cardsco' ),
		);
	}

	/**
	 * The Occasions screen's list: one row per category.
	 *
	 * The frame lists the six categories whatever this company has set up, so
	 * the catalogue drives the list and the company's own programmes only
	 * decide each row's state. Rows carry the art, the status pill, the blurb,
	 * a stat line that differs per state, and the button — or no button at
	 * all, for one marked coming soon.
	 *
	 * @return array[]
	 */
	public static function occasion_cards() {
		$company_id = CardsCo_Access::company_id();
		$out        = array();

		foreach ( CardsCo_Category::all() as $slug => $cat ) {
			$running = $company_id ? self::running_programme( $slug, $company_id ) : null;

			$row = array(
				'slug'        => $slug,
				'label'       => $cat['label'],
				'blurb'       => $cat['blurb'],
				'coming_soon' => ! empty( $cat['coming_soon'] ),
				'active'      => (bool) $running,
				'programme'   => $running,
				'art'         => self::category_art( $slug, $company_id ),
				'stat'        => '',
				'stat_strong' => false,
			);

			/*
			 * A half-finished set-up is its own state, between "not set up"
			 * and "running". Without it the row says "Set up" and the draft
			 * behind it is invisible — which is how somebody loses an
			 * afternoon's work and never knows why.
			 */
			$draft = ( ! $running && $company_id && empty( $cat['coming_soon'] ) )
				? CardsCo_Programme::draft_for( $company_id, $slug )
				: null;

			$row['draft']      = (bool) $draft;
			/*
			 * The id, not just the fact of one. Without it the row's button
			 * says "Continue" and links to ?occasion=<slug>, which is where
			 * a NEW occasion starts — so somebody who saved and came back
			 * landed on step 1 with their draft untouched behind them.
			 */
			$row['draft_id']   = $draft ? (int) $draft['id'] : 0;
			$row['draft_step'] = $draft ? (int) CardsCo_Setup::step_on_screen( $draft, 0 ) : 0;

			if ( $row['coming_soon'] ) {
				$row['stat'] = __( 'Use a milestone card for now', 'cardsco' );
			} elseif ( $draft ) {
				$row['stat']        = sprintf(
					/* translators: 1: the step reached, 2: how many steps there are. */
					__( 'Half set up · step %1$s of %2$s', 'cardsco' ),
					number_format_i18n( $row['draft_step'] ),
					number_format_i18n( count( CardsCo_Setup::steps() ) )
				);
				$row['stat_strong'] = true;
			} elseif ( $running ) {
				$row['stat']        = self::programme_stat( $running, $slug, $company_id );
				$row['stat_strong'] = true;
			} else {
				$designs = $company_id ? CardsCo_Category::design_count( $slug, $company_id ) : 0;

				$row['stat'] = $designs
					? sprintf(
						/* translators: 1: the card format, 2: how many designs. */
						_n( '%1$s · %2$s design', '%1$s · %2$s designs', $designs, 'cardsco' ),
						$cat['format'],
						number_format_i18n( $designs )
					)
					: $cat['format'];
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The active programme behind a category, if there is one.
	 *
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company ID.
	 * @return array|null
	 */
	public static function running_programme( $slug, $company_id ) {
		foreach ( CardsCo_Programme::active( $company_id ) as $programme ) {
			if ( isset( $programme['category'] ) && $programme['category'] === $slug ) {
				return $programme;
			}
		}

		return null;
	}

	/**
	 * The stat line on a running occasion.
	 *
	 * The frame shows two shapes: a people count with who is next, and — for
	 * the category driven by campaigns rather than by each person's own date —
	 * a card count with when it posts.
	 *
	 * @param array  $programme  Programme row.
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company ID.
	 * @return string
	 */
	public static function programme_stat( array $programme, $slug, $company_id ) {
		if ( ! CardsCo_Category::is_dated( $slug, $company_id ) ) {
			$cards = 0;
			$when  = '';

			foreach ( CardsCo_Campaign::for_company( $company_id, array( 'limit' => 200 ) ) as $campaign ) {
				if ( in_array( $campaign['status'], array( 'scheduled', 'locked' ), true ) ) {
					$cards += (int) $campaign['recipient_count'];

					if ( $campaign['send_date'] && ( ! $when || $campaign['send_date'] < $when ) ) {
						$when = $campaign['send_date'];
					}
				}
			}

			if ( ! $cards ) {
				return __( 'Nothing scheduled', 'cardsco' );
			}

			return $when
				? sprintf(
					/* translators: 1: how many cards, 2: the date they post. */
					__( '%1$s cards · posts %2$s', 'cardsco' ),
					number_format_i18n( $cards ),
					gmdate( 'D j M', strtotime( $when ) )
				)
				: sprintf(
					/* translators: %s: how many cards. */
					__( '%s cards', 'cardsco' ),
					number_format_i18n( $cards )
				);
		}

		$people = count( CardsCo_Programme::recipient_ids( (int) $programme["id"] ) );
		$next   = null;

		foreach ( self::upcoming( 365, 50 ) as $occasion ) {
			$type_id = isset( $occasion['occasion_type_id'] ) ? (int) $occasion['occasion_type_id'] : 0;
			// for_type() answers with a list, because one occasion type can
			// sit under more than one collection. Compared as a string it
			// never matched, and the next date here never resolved.
			$cats = $type_id ? CardsCo_Category::for_type( $type_id, $company_id ) : array();

			if ( in_array( $slug, (array) $cats, true ) ) {
				$next = $occasion;
				break;
			}
		}

		if ( ! $next ) {
			return sprintf(
				/* translators: %s: how many people. */
				_n( '%s person', '%s people', $people, 'cardsco' ),
				number_format_i18n( $people )
			);
		}

		return sprintf(
			/* translators: 1: how many people, 2: who is next, 3: when. */
			__( '%1$s people · next: %2$s, %3$s', 'cardsco' ),
			number_format_i18n( $people ),
			$next['name'],
			gmdate( 'D j M', strtotime( $next['next_occurrence'] ) )
		);
	}

	/**
	 * The artwork shown in a category row's 96x72 well.
	 *
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company ID.
	 * @return array{title:string, image:string}
	 */
	public static function category_art( $slug, $company_id ) {
		$cards = $company_id ? CardsCo_Category::cards( $slug, $company_id ) : array();

		foreach ( (array) $cards as $card ) {
			$id = $card instanceof WP_Post ? (int) $card->ID : (int) $card;

			if ( ! $id ) {
				continue;
			}

			$cover = self::cover( $id );

			if ( $cover['image'] ) {
				return $cover;
			}
		}

		return array( 'title' => '', 'image' => '' );
	}

	/**
	 * The three tabs on the Campaigns screen.
	 *
	 * Read off the frame: In progress / Drafts / Past, each with a count.
	 *
	 * @return array<string, array{label:string, statuses:string[]}>
	 */
	public static function campaign_tabs() {
		return array(
			'progress' => array(
				'label'    => __( 'In progress', 'cardsco' ),
				'statuses' => array( 'scheduled', 'locked' ),
			),
			'drafts'   => array(
				'label'    => __( 'Drafts', 'cardsco' ),
				'statuses' => array( 'draft' ),
			),
			'past'     => array(
				'label'    => __( 'Past', 'cardsco' ),
				'statuses' => array( 'sent', 'cancelled' ),
			),
		);
	}

	/**
	 * Campaigns for one tab, with everything a row in the design needs.
	 *
	 * The row carries cover art, the audience in words, the offer and a
	 * progress bar, so all of that is resolved here rather than leaving the
	 * view to fetch it per campaign.
	 *
	 * @param string $tab   Key from campaign_tabs().
	 * @param int    $limit Most to return.
	 * @return array[]
	 */
	public static function campaign_rows( $tab = 'progress', $limit = 25 ) {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$tabs = self::campaign_tabs();
		$spec = isset( $tabs[ $tab ] ) ? $tabs[ $tab ] : $tabs['progress'];
		$out  = array();

		foreach ( CardsCo_Campaign::for_company( $company_id, array( 'limit' => 200 ) ) as $campaign ) {
			if ( ! in_array( $campaign['status'], $spec['statuses'], true ) ) {
				continue;
			}

			$out[] = array(
				'id'        => (int) $campaign['id'],
				'name'      => $campaign['name'],
				'status'    => $campaign['status'],
				'total'     => (int) $campaign['recipient_count'],
				'written'   => 'draft' === $campaign['status']
					? 0
					: CardsCo_Shortcodes::written_count( (int) $campaign['id'] ),
				'send_date' => $campaign['send_date'],
				'lock_date' => isset( $campaign['lock_date'] ) ? $campaign['lock_date'] : '',
				'occasion'  => self::occasion_label( (int) $campaign['occasion_type_id'] ),
				'audience'  => self::audience_words( $campaign ),
				'offer'     => empty( $campaign['offer_enabled'] )
					? null
					: array(
						'code'        => isset( $campaign['offer_code'] ) ? $campaign['offer_code'] : '',
						'description' => isset( $campaign['offer_description'] ) ? $campaign['offer_description'] : '',
					),
				'cover'     => self::cover( (int) $campaign['card_id'] ),
				'holds'     => self::no_address_count( $campaign ),
			);

			if ( count( $out ) >= absint( $limit ) ) {
				break;
			}
		}

		/*
		 * The frame lists the campaign being written first, then the one in
		 * the diary, then the one waiting on a person — most-live first, which
		 * is also the order in which they need attention. Within a rank, the
		 * nearest send date leads; a campaign with no date yet goes last.
		 */
		$rank = array( 'locked' => 0, 'scheduled' => 1, 'draft' => 2, 'sent' => 3, 'cancelled' => 4 );

		usort(
			$out,
			static function ( $a, $b ) use ( $rank ) {
				$ra = isset( $rank[ $a['status'] ] ) ? $rank[ $a['status'] ] : 9;
				$rb = isset( $rank[ $b['status'] ] ) ? $rank[ $b['status'] ] : 9;

				if ( $ra !== $rb ) {
					return $ra - $rb;
				}

				$da = $a['send_date'] ? $a['send_date'] : '9999-12-31';
				$db = $b['send_date'] ? $b['send_date'] : '9999-12-31';

				// Finished campaigns read newest first; everything else soonest first.
				if ( 'sent' === $a['status'] ) {
					return strcmp( $db, $da );
				}

				return strcmp( $da, $db );
			}
		);

		return $out;
	}

	/**
	 * How many campaigns sit behind each tab.
	 *
	 * @return array<string, int>
	 */
	public static function campaign_tab_counts() {
		$out = array_fill_keys( array_keys( self::campaign_tabs() ), 0 );

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return $out;
		}

		foreach ( CardsCo_Campaign::for_company( $company_id, array( 'limit' => 500 ) ) as $campaign ) {
			foreach ( self::campaign_tabs() as $key => $spec ) {
				if ( in_array( $campaign['status'], $spec['statuses'], true ) ) {
					$out[ $key ]++;
				}
			}
		}

		return $out;
	}

	/**
	 * How many people in a draft's audience have nowhere to post to.
	 *
	 * The frame's review row reads "3 recipients have no address", which is
	 * the thing stopping that campaign being scheduled.
	 *
	 * @param array $campaign Campaign row.
	 * @return int
	 */
	public static function no_address_count( array $campaign ) {
		if ( 'draft' !== $campaign['status'] ) {
			return 0;
		}

		$audience = CardsCo_Campaign::audience( $campaign );
		$missing  = 0;

		foreach ( $audience['ids'] as $recipient_id ) {
			$recipient = CardsCo_Recipient::get( $recipient_id );

			if ( ! $recipient || '' === trim( (string) $recipient['address'] ) ) {
				$missing++;
			}
		}

		return $missing;
	}

	/**
	 * The occasion type's label.
	 *
	 * @param int $type_id Occasion type ID.
	 * @return string
	 */
	public static function occasion_label( $type_id ) {
		if ( ! $type_id ) {
			return '';
		}

		$type = CardsCo_Occasion_Type::get( $type_id );

		return $type ? $type['label'] : '';
	}

	/**
	 * The audience, described the way the row describes it.
	 *
	 * The frame reads "Retail and Trade segments" and "All customers", so a
	 * couple of segments are named and more than that are counted. Only this
	 * company's own segments are ever named.
	 *
	 * @param array $campaign Campaign row.
	 * @return string
	 */
	public static function audience_words( array $campaign ) {
		$picked = CardsCo_Campaign::clean_audience( $campaign['audience'] );
		$names  = array();

		foreach ( $picked['segments'] as $segment_id ) {
			$segment = CardsCo_Segment::get( $segment_id );

			if ( ! $segment || (int) $segment['company_id'] !== (int) $campaign['company_id'] ) {
				continue;
			}

			$names[] = $segment['name'];
		}

		if ( ! $names ) {
			return $picked['recipients']
				? __( 'Chosen people', 'cardsco' )
				: __( 'All customers', 'cardsco' );
		}

		if ( count( $names ) > 2 ) {
			return sprintf(
				/* translators: %s: number of segments. */
				_n( '%s segment', '%s segments', count( $names ), 'cardsco' ),
				number_format_i18n( count( $names ) )
			);
		}

		return sprintf(
			/* translators: %s: segment names, e.g. "Retail and Trade". */
			_n( '%s segment', '%s segments', count( $names ), 'cardsco' ),
			implode( __( ' and ', 'cardsco' ), $names )
		);
	}

	/**
	 * The card's cover art for the 88x96 well on a campaign row.
	 *
	 * @param int $card_id Card post ID.
	 * @return array{title:string, image:string}
	 */
	public static function cover( $card_id ) {
		$empty = array( 'title' => '', 'image' => '' );

		if ( ! $card_id ) {
			return $empty;
		}

		$summary = CardsCo_Card::summary( $card_id );

		if ( ! $summary ) {
			return $empty;
		}

		return array(
			'title' => isset( $summary['title'] ) ? (string) $summary['title'] : '',
			'image' => ! empty( $summary['image'] ) ? (string) $summary['image'] : '',
		);
	}

	/**
	 * Finished campaigns, for the "Recently finished" table.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public static function finished_campaigns( $limit = 4 ) {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$out = array();

		foreach ( CardsCo_Campaign::for_company( $company_id, array( 'limit' => 200 ) ) as $campaign ) {
			if ( 'sent' !== $campaign['status'] ) {
				continue;
			}

			$out[] = array(
				'id'       => (int) $campaign['id'],
				'name'     => $campaign['name'],
				'audience' => self::audience_words( $campaign ),
				'cards'    => (int) $campaign['recipient_count'],
				'finished' => $campaign['send_date'],
			);

			if ( count( $out ) >= absint( $limit ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * How many finished campaigns there are altogether, for "See all 6".
	 *
	 * @return int
	 */
	public static function finished_count() {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return 0;
		}

		return count(
			CardsCo_Campaign::for_company( $company_id, array( 'status' => 'sent', 'limit' => 500 ) )
		);
	}

	/**
	 * Campaigns waiting on somebody.
	 *
	 * The sidebar's count is described in the design as "campaigns needing
	 * review", not every campaign, so this counts the drafts: the ones that
	 * have been started and not yet put in the diary.
	 *
	 * @return int
	 */
	public static function campaigns_needing_review() {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return 0;
		}

		return count( CardsCo_Campaign::for_company( $company_id, array( 'status' => 'draft' ) ) );
	}

	/**
	 * The handful of campaigns the sidebar lists, newest first.
	 *
	 * @param int $limit Most to show.
	 * @return array[]
	 */
	public static function recent_campaigns( $limit = 4 ) {
		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		return CardsCo_Campaign::for_company( $company_id, array( 'limit' => absint( $limit ) ) );
	}

	/**
	 * The views the dashboard's segmented control offers.
	 *
	 * Taken from the frame: All (with a count), Drafts, Scheduled, Sent. The
	 * statuses behind each are listed here rather than in the query so that
	 * "what counts as sent" is stated once.
	 *
	 * @return array<string, array{label:string, campaign:string[], card:string[]}>
	 */
	public static function views() {
		return array(
			'all'       => array(
				'label'    => __( 'All', 'cardsco' ),
				'campaign' => array( 'draft', 'scheduled', 'locked', 'sent' ),
				'card'     => array( 'queued', 'on_hold', 'writing', 'written', 'posted', 'delivered', 'failed' ),
			),
			'drafts'    => array(
				'label'    => __( 'Drafts', 'cardsco' ),
				'campaign' => array( 'draft' ),
				'card'     => array(),
			),
			'scheduled' => array(
				'label'    => __( 'Scheduled', 'cardsco' ),
				'campaign' => array( 'scheduled', 'locked' ),
				'card'     => array( 'queued', 'on_hold', 'writing' ),
			),
			'sent'      => array(
				'label'    => __( 'Sent', 'cardsco' ),
				'campaign' => array( 'sent' ),
				'card'     => array( 'written', 'posted', 'delivered' ),
			),
		);
	}

	/**
	 * What the dashboard grid shows: one tile per send.
	 *
	 * A send is either a campaign — one tile, however many cards it carries —
	 * or a single card that was not part of one. The frame's segmented control
	 * reads All / Drafts / Scheduled / Sent and its empty state says "No
	 * campaigns yet", while a tile reads "Tunde Bakare · 1 card", so the grid
	 * holds both and the count tells them apart.
	 *
	 * Two queries rather than one grouped query: a campaign and a loose card
	 * keep their own statuses, and flattening them in SQL would mean deciding
	 * in SQL which status wins.
	 *
	 * @param array $args view: a key from views(). occasion: label to filter
	 *                    by. sort: newest|soonest. limit.
	 * @return array[] Each with kind, id, title, message, occasion, date,
	 *                 status, count, hold_reason.
	 */
	public static function sends( array $args = array() ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'view'     => 'all',
				'occasion' => '',
				'sort'     => 'newest',
				'limit'    => 24,
			)
		);

		$views = self::views();
		$view  = isset( $views[ $args['view'] ] ) ? $views[ $args['view'] ] : $views['all'];
		$limit = absint( $args['limit'] );
		$out   = array();

		/* ----- campaigns ------------------------------------------------ */

		if ( $view['campaign'] ) {
			$in     = implode( ', ', array_fill( 0, count( $view['campaign'] ), '%s' ) );
			$params = array_merge( array( $company_id ), $view['campaign'] );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, name, message_body, status, send_date, recipient_count, created_at
					 FROM ' . cardsco_table( 'campaigns' ) . "
					 WHERE company_id = %d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL
					$params
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'kind'        => 'campaign',
					'id'          => (int) $row['id'],
					'title'       => $row['name'],
					'message'     => (string) $row['message_body'],
					'occasion'    => '',
					'date'        => $row['send_date'],
					'status'      => $row['status'],
					'count'       => (int) $row['recipient_count'],
					'hold_reason' => '',
					'created_at'  => $row['created_at'],
				);
			}
		}

		/* ----- single cards ---------------------------------------------- */

		if ( $view['card'] ) {
			$in     = implode( ', ', array_fill( 0, count( $view['card'] ), '%s' ) );
			$where  = array( 'company_id = %d', '( campaign_id IS NULL OR campaign_id = 0 )', "status IN ({$in})" );
			$params = array_merge( array( $company_id ), $view['card'] );

			if ( $args['occasion'] ) {
				$where[]  = 'occasion_label = %s';
				$params[] = $args['occasion'];
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, recipient_name, occasion_label, message_final, status,
					        event_date, hold_reason, created_at
					 FROM ' . cardsco_table( 'card_queue' ) . '
					 WHERE ' . implode( ' AND ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL
					$params
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'kind'        => 'card',
					'id'          => (int) $row['id'],
					'title'       => $row['recipient_name'],
					'message'     => (string) $row['message_final'],
					'occasion'    => $row['occasion_label'],
					'date'        => $row['event_date'],
					'status'      => $row['status'],
					'count'       => 1,
					'hold_reason' => $row['hold_reason'],
					'created_at'  => $row['created_at'],
				);
			}
		}

		/*
		 * Filtering campaigns by occasion label is left out on purpose: a
		 * campaign carries an occasion type, not the label the queue stores,
		 * and matching the two by string would quietly drop campaigns whose
		 * label differs by a word. When an occasion filter is on, only cards
		 * are shown, which is what the filter is for.
		 */
		if ( $args['occasion'] ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $row ) {
						return 'card' === $row['kind'];
					}
				)
			);
		}

		usort(
			$out,
			static function ( $a, $b ) use ( $args ) {
				if ( 'soonest' === $args['sort'] ) {
					// A send with no date yet sits at the end rather than the front.
					$x = $a['date'] ? $a['date'] : '9999-12-31';
					$y = $b['date'] ? $b['date'] : '9999-12-31';

					return $x === $y ? 0 : ( $x < $y ? -1 : 1 );
				}

				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);

		return $limit ? array_slice( $out, 0, $limit ) : $out;
	}

	/**
	 * How many sends each segmented view would show.
	 *
	 * The frame puts a count beside "All" only, but counting them all costs
	 * nothing here and lets the view decide.
	 *
	 * @return array<string, int>
	 */
	public static function view_counts() {
		$out = array();

		foreach ( array_keys( self::views() ) as $key ) {
			$out[ $key ] = count( self::sends( array( 'view' => $key, 'limit' => 0 ) ) );
		}

		return $out;
	}

	/**
	 * The occasion labels this company actually has cards for.
	 *
	 * Drives the "All occasions" filter, so it lists what is there rather than
	 * every occasion type in the system.
	 *
	 * @return string[]
	 */
	public static function card_occasions() {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT occasion_label FROM ' . cardsco_table( 'card_queue' ) . "
				 WHERE company_id = %d AND occasion_label <> ''
				 ORDER BY occasion_label ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * How many cards sent so far this year, for the page subtitle.
	 *
	 * @return int
	 */
	public static function sent_this_year() {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . cardsco_table( 'card_queue' ) . "
				 WHERE company_id = %d
				   AND status IN ('posted','delivered')
				   AND event_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				gmdate( 'Y' ) . '-01-01'
			)
		);
	}

	/**
	 * Cards in a given state.
	 *
	 * @param string[] $statuses Queue statuses.
	 * @return int
	 */
	public static function count_queue( array $statuses ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();
		$statuses   = array_values( array_intersect( $statuses, array_keys( CardsCo_Queue::statuses() ) ) );

		if ( ! $company_id || ! $statuses ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $company_id ), $statuses );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . cardsco_table( 'card_queue' ) . " WHERE company_id = %d AND status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			)
		);
	}

	/**
	 * Cards held, with the reason, so the dashboard can say what to fix.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public static function held( $limit = 50 ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, recipient_id, recipient_name, occasion_label, event_date, hold_reason
				 FROM ' . cardsco_table( 'card_queue' ) . "
				 WHERE company_id = %d AND status = 'on_hold'
				 ORDER BY event_date ASC, id ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				absint( $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * What this company has spent on cards so far this year.
	 *
	 * Counts cards that actually went out. A card still queued has not cost
	 * anybody anything yet, and a cancelled one never will.
	 *
	 * @return string Formatted to two decimal places.
	 */
	public static function spend_this_year() {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return '0.00';
		}

		$total = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(unit_price) FROM ' . cardsco_table( 'card_queue' ) . "
				 WHERE company_id = %d
				   AND status IN ('written','posted','delivered')
				   AND event_date >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				gmdate( 'Y' ) . '-01-01'
			)
		);

		return number_format( $total, 2, '.', '' );
	}

	/**
	 * Recent goings-on, for the activity strip.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public static function activity( $limit = 10 ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT action, summary, created_at FROM ' . cardsco_table( 'activity_log' ) . '
				 WHERE company_id = %d
				 ORDER BY id DESC
				 LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				absint( $limit )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * The wizard's right-hand summary rail.
	 *
	 * Frame 253:56833 lists all seven steps with a value each — the finished
	 * ones showing what was chosen, the one you are on showing "In progress"
	 * and the rest an em dash — then the price and a note.
	 *
	 * @param array $programme Programme row, or a stand-in before one exists.
	 * @param int   $step      The step on screen.
	 * @return array
	 */
	public static function setup_rail( array $programme, $step ) {
		$company_id = CardsCo_Access::company_id();
		$category   = $programme['category'] ? CardsCo_Category::get( $programme['category'] ) : null;
		$values     = array();

		$live = 'active' === (string) ( $programme['status'] ?? '' );
		$last = count( CardsCo_Setup::steps() );

		foreach ( array_keys( CardsCo_Setup::steps() ) as $n ) {
			/*
			 * Once it is live the last row is not "in progress" — it is the
			 * thing that happened. Checked before the current-step branch,
			 * which would otherwise claim it first.
			 */
			if ( $live && $n === $last ) {
				$values[ $n ] = __( 'Paid · occasion on', 'cardsco' );
				continue;
			}

			if ( $n === (int) $step ) {
				$values[ $n ] = __( 'In progress', 'cardsco' );
				continue;
			}

			if ( ! CardsCo_Setup::done( $programme, $n ) ) {
				$values[ $n ] = '—';
				continue;
			}

			$values[ $n ] = self::setup_value( $programme, $n, $category );
		}

		$price = self::card_price( $programme, $company_id );

		$money = CardsCo_Shortcodes::money( number_format( $price, 2, '.', '' ), (string) CardsCo_Settings::get( 'currency', null, $company_id ) );

		return array(
			'title'        => $category ? $category['label'] : __( 'New occasion', 'cardsco' ),
			'saved'        => $programme['id']
				? __( 'New occasion · draft saved', 'cardsco' )
				: __( 'New occasion · nothing saved yet', 'cardsco' ),
			'blurb'        => self::setup_blurb( $step, $programme, $category ),
			'heading'      => self::setup_heading( $step, $programme, $category ),
			'values'       => $values,
			/* The frame hangs a 28x39 thumbnail of the chosen card off the
			   rail's Card row from step 3 onwards. */
			'thumb'        => ! empty( $programme['card_id'] )
				? (string) get_the_post_thumbnail_url( (int) $programme['card_id'], 'thumbnail' )
				: '',
			'price'        => $price ? $money : '—',
			'price_note'   => $price && $category
				? sprintf(
					/* translators: 1: the occasion, 2: the price of one card. */
					__( '%1$s cards are %2$s each, charged as they’re written. Postage is extra.', 'cardsco' ),
					/* Starts the sentence; the nouns are written for the
					   middle of one. */
					cardsco_ucfirst( self::card_noun( $programme['category'], $company_id ) ),
					$money
				)
				: __( 'Pick an occasion to see what each card costs.', 'cardsco' ),
			'footer'       => self::setup_footer( $programme, $step, $category ),
			/* The last step does not continue anywhere — it hands over to
			   payment, and the button should say so. */
			'continue'     => count( CardsCo_Setup::steps() ) === (int) $step
				? __( 'Go to payment', 'cardsco' )
				: __( 'Continue', 'cardsco' ),
			'can_continue' => self::setup_ready( $programme, $step ),
		);
	}

	/**
	 * What a finished step shows in the rail.
	 *
	 * @param array      $programme Programme row.
	 * @param int        $n         Step number.
	 * @param array|null $category  The chosen category.
	 * @return string
	 */
	protected static function setup_value( array $programme, $n, $category ) {
		switch ( (int) $n ) {
			case 1:
				return $category ? $category['label'] : '—';

			case 2:
				$people = count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );

				return sprintf(
					/* translators: %s: how many people. */
					_n( '%s person', '%s people', $people, 'cardsco' ),
					number_format_i18n( $people )
				);

			case 3:
				$title = $programme['card_id'] ? get_the_title( (int) $programme['card_id'] ) : '';

				return $title ? $title : '—';

			/*
			 * Steps 4, 5 and 6 fell off the end of this switch, so the rail
			 * showed a dash against Message, Shipping and Timing however
			 * complete they were — three finished steps reported as empty
			 * on every screen from 4 onwards.
			 */
			case 4:
				$said = $programme['message_id']
					? (string) get_the_title( (int) $programme['message_id'] )
					: (string) $programme['message_override'];
				$said = trim( wp_strip_all_tags( $said ) );

				return '' !== $said ? wp_trim_words( $said, 6, '…' ) : '—';

			case 5:
				if ( 'individual' === (string) $programme['shipping_mode'] ) {
					return __( 'Each person’s own address', 'cardsco' );
				}

				$where = trim( (string) $programme['ship_address'] );
				$where = trim( (string) strtok( $where, "\n" ) );

				return '' !== $where ? $where : __( 'Your company address', 'cardsco' );

			case 6:
				return 'per_date' === (string) $programme['timing_mode']
					? __( 'Before each date', 'cardsco' )
					: __( 'One box a month', 'cardsco' );
		}

		return '—';
	}

	/**
	 * A start date written out, for a sentence rather than a picker.
	 *
	 * @param string $date A Y-m-d date.
	 * @return string
	 */
	protected static function setup_start_date( $date ) {
		$when = strtotime( (string) $date );

		return $when ? date_i18n( 'D j M Y', $when ) : '';
	}

	/**
	 * The line under each step's heading.
	 *
	 * @param int $step Step number.
	 * @return string
	 */
	protected static function setup_blurb( $step, $programme = array(), $category = null ) {
		$step = (int) $step;

		/*
		 * Steps 2 and 6 used to say "birthday" outright, so a company
		 * setting up Customer Milestones was told we work back from each
		 * birthday and asked for the day and month of one. The occasion
		 * names itself.
		 */
		/*
		 * Callers hand this the category row, not its slug — and the
		 * programme carries the slug. Take whichever is actually there.
		 */
		$cc_slug = is_array( $category ) && isset( $category['slug'] )
			? (string) $category['slug']
			: (string) ( $programme['category'] ?? ( is_string( $category ) ? $category : '' ) );
		$noun    = cardsco_lcfirst( self::card_noun( $cc_slug, (int) ( $programme['company_id'] ?? 0 ) ) );
		$type    = CardsCo_Category::primary_type( $cc_slug, (int) ( $programme['company_id'] ?? 0 ) );

		/* A yearly date needs no year; one that happens once does. */
		$dated = $type && 'once' === (string) $type['recurrence']
			? sprintf(
				/* translators: %s: the occasion, e.g. "welcome". */
				__( 'Upload your team once. We only need a name and the date of each %s.', 'cardsco' ),
				$noun
			)
			: sprintf(
				/* translators: %s: the occasion, e.g. "birthday". */
				__( 'Upload your team once. We only need a name and the day and month of each %s.', 'cardsco' ),
				$noun
			);

		$blurbs = array(
			1 => __( 'Pick one. You can set up the others any time.', 'cardsco' ),
			2 => $dated,
			4 => __( 'Write it once. We add each person’s name, and every card is written by hand.', 'cardsco' ),
			5 => __( 'Send them to one business address, or to each person’s own address.', 'cardsco' ),
			6 => sprintf(
				/* translators: %s: the occasion, e.g. "birthday". */
				__( 'We work back from each %s so nobody’s card is late.', 'cardsco' ),
				$noun
			),
			7 => __( 'You only pay for cards as they’re written. Nothing is charged today.', 'cardsco' ),
		);

		/*
		 * Step 3 names the occasion in the frame — "Birthday cards are folded
		 * cards" rather than "Cards are folded cards" — so it is built here
		 * rather than kept as a fixed string. Step 4's line is fixed: the
		 * frame does not name the occasion in it.
		 */
		if ( 3 === $step ) {
			$noun = $category ? self::card_noun( $programme['category'], CardsCo_Access::company_id() ) : '';

			if ( ! $noun ) {
				return __( 'Every card is written inside in real ink.', 'cardsco' );
			}

			return sprintf(
				/* translators: %s: the occasion, e.g. Birthday. */
				__( '%s cards are folded cards, written inside in real ink.', 'cardsco' ),
				/* It starts the sentence, and the category nouns are written
				 * for the middle of one. */
				cardsco_ucfirst( $noun )
			);
		}

		return isset( $blurbs[ $step ] ) ? $blurbs[ $step ] : '';
	}

	/**
	 * Step 3 — the cards on offer for this occasion.
	 *
	 * Two sources, as the frame's tabs have them: the occasion's own cards
	 * from the shared catalogue, and anything the company has had made for
	 * itself. A company with no designs of its own never sees the second tab,
	 * because an empty tab is a dead end rather than an invitation.
	 *
	 * @param array  $programme Programme row.
	 * @param string $tab       Which tab is open: 'occasion' or 'mine'.
	 * @return array
	 */
	public static function setup_cards( array $programme, $tab = 'occasion' ) {
		$company_id = CardsCo_Access::company_id();
		$category   = $programme['category'] ? CardsCo_Category::get( $programme['category'] ) : null;
		$chosen     = (int) ( $programme['card_id'] ?? 0 );

		$for_occasion = $category
			? (array) CardsCo_Category::cards( $programme['category'], $company_id )
			: (array) CardsCo_Card::for_occasion( 0, $company_id );

		// A card carrying this company's own id is one of theirs, not the
		// shared catalogue's.
		$mine = array_values(
			array_filter(
				$for_occasion,
				static function ( $post ) use ( $company_id ) {
					return $company_id && (int) get_post_meta( $post->ID, '_cardsco_company_id', true ) === $company_id;
				}
			)
		);

		$tab  = in_array( $tab, array( 'occasion', 'mine' ), true ) ? $tab : 'occasion';
		$show = 'mine' === $tab ? $mine : $for_occasion;

		$tiles = array();

		foreach ( $show as $post ) {
			$card = CardsCo_Card::summary( $post->ID );

			if ( ! $card ) {
				continue;
			}

			$tiles[] = array(
				'id'       => (int) $card['id'],
				'title'    => $card['title'],
				'sub'      => trim( implode( ' · ', array_filter( array( $card['pattern'], $card['format'] ) ) ) ),
				'image'    => $card['image'],
				'selected' => (int) $card['id'] === $chosen,
			);
		}

		$tabs = array(
			array(
				'key'   => 'occasion',
				'label' => $category ? $category['label'] : __( 'All cards', 'cardsco' ),
				'n'     => count( $for_occasion ),
				'on'    => 'occasion' === $tab,
			),
		);

		/*
		 * The frame shows "Your designs" whether or not there are any, so it
		 * stays — a company with none gets an empty state behind it rather
		 * than a tab that appears the day their first design lands.
		 */
		$tabs[] = array(
			'key'   => 'mine',
			'label' => __( 'Your designs', 'cardsco' ),
			'n'     => count( $mine ),
			'on'    => 'mine' === $tab,
		);

		return array(
			'tabs'   => $tabs,
			'tiles'  => $tiles,
			'chosen' => $chosen,
			'tab'    => $tab,
		);
	}

	/**
	 * What a card costs on this programme.
	 *
	 * Before a card is chosen there is still a price to show — the first one
	 * in the occasion's own range — because "from £3.60" is more use than a
	 * blank. The rail and the review screen both ask here, so they cannot
	 * drift apart and quote two different numbers on the same page.
	 *
	 * @param array $programme  Programme row.
	 * @param int   $company_id Company.
	 * @return float
	 */
	public static function card_price( array $programme, $company_id ) {
		if ( ! empty( $programme['card_id'] ) ) {
			return (float) CardsCo_Card::price( (int) $programme['card_id'], $company_id );
		}

		if ( empty( $programme['category'] ) ) {
			return 0.0;
		}

		foreach ( (array) CardsCo_Category::cards( $programme['category'], $company_id ) as $card ) {
			return (float) CardsCo_Card::price( (int) $card->ID, $company_id );
		}

		return 0.0;
	}

	/**
	 * The Done screen — the occasion is on.
	 *
	 * Deliberately concrete rather than congratulatory. The useful thing to
	 * show somebody who has just paid is the first few names and what will
	 * actually happen to each: which box they are in, when it arrives, and
	 * which of them falls outside the start date and so is not included.
	 *
	 * @param array $programme Programme row.
	 * @param int   $limit     How many upcoming dates to list.
	 * @return array
	 */
	public static function setup_done( array $programme, $limit = 3 ) {
		global $wpdb;

		$company_id = CardsCo_Access::company_id();
		$timing     = self::setup_timing( $programme );
		$price      = self::card_price( $programme, $company_id );
		$box        = (int) $timing['in_box'];

		$money = CardsCo_Shortcodes::money(
			number_format( $price * $box, 2, '.', '' ),
			(string) CardsCo_Settings::get( 'currency', null, $company_id )
		);

		$occasions = cardsco_table( 'occasions' );
		$members   = cardsco_table( 'programme_recipients' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT o.event_day, o.event_month, o.next_occurrence, o.recipient_id
				 FROM {$occasions} o
				 INNER JOIN {$members} m ON m.recipient_id = o.recipient_id
				 WHERE m.programme_id = %d AND o.is_active = 1 AND o.next_occurrence IS NOT NULL
				 ORDER BY o.next_occurrence ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $programme['id'],
				absint( $limit )
			),
			ARRAY_A
		);

		$starts = strtotime( (string) ( $programme['starts_on'] ?? '' ) );
		$coming = array();

		foreach ( $rows as $row ) {
			$when = strtotime( (string) $row['next_occurrence'] );
			$who  = CardsCo_Recipient::get( (int) $row['recipient_id'] );
			$name = $who ? trim( $who['first_name'] . ' ' . $who['last_name'] ) : __( 'Someone', 'cardsco' );

			$before = $starts && $when && $when < $starts;

			$coming[] = array(
				'day'    => $when ? date_i18n( 'j', $when ) : '',
				'month'  => $when ? strtoupper( date_i18n( 'M', $when ) ) : '',
				'name'   => $name,
				'detail' => $before
					? __( 'Before your start date · send as a single card', 'cardsco' )
					: ( 'monthly_box' === $timing['mode']
						? sprintf(
							/* translators: 1: a month, 2: the date the box arrives. */
							__( '%1$s box · arrives by %2$s', 'cardsco' ),
							date_i18n( 'F', $when ),
							date_i18n( 'D j M', strtotime( '-1 month', $when ) )
						)
						: __( 'Arrives a few days before', 'cardsco' ) ),
				'status' => $before ? __( 'Not included', 'cardsco' ) : __( 'Queued', 'cardsco' ),
				'state'  => $before ? 'out' : 'queued',
			);
		}

		$missing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$members} m
				 WHERE m.programme_id = %d
				 AND NOT EXISTS (
					SELECT 1 FROM {$occasions} o
					WHERE o.recipient_id = m.recipient_id AND o.is_active = 1
				 )", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $programme['id']
			)
		);

		$user = wp_get_current_user();

		return array(
			'paid'    => sprintf(
				/* translators: 1: an amount, 2: a month. */
				__( 'Paid %1$s for the %2$s box', 'cardsco' ),
				$money,
				$timing['month']
			),
			'receipt' => $user && $user->user_email
				? sprintf(
					/* translators: %s: an email address. */
					__( 'Receipt sent to %s', 'cardsco' ),
					$user->user_email
				)
				: '',
			'coming'  => $coming,
			'warning' => $missing
				? sprintf(
					/* translators: %s: how many people. */
					_n(
						'%s person has no date yet. Add them any time from Occasions.',
						'%s people have no date yet. Add them any time from Occasions.',
						$missing,
						'cardsco'
					),
					number_format_i18n( $missing )
				)
				: '',
		);
	}

	/**
	 * Step 7 — check it over, then pay.
	 *
	 * Two halves: everything chosen so far with a way back to each step, and
	 * what it costs. The money is deliberately specific about what is being
	 * charged *now* versus over a year, because "you only pay as cards are
	 * written" is easy to say and easy to misread.
	 *
	 * @param array $programme Programme row.
	 * @param array $links     Where each Edit link goes.
	 * @return array
	 */
	public static function setup_review( array $programme, array $links ) {
		$company_id = CardsCo_Access::company_id();
		$timing     = self::setup_timing( $programme );

		/*
		 * Step 6 is three different screens and setup_timing() answers with
		 * the keys the screen in front of the person needs, while the rows
		 * below are written for the monthly-box shape. A programme in either
		 * of the other two shapes belongs under Campaigns and start() turns
		 * it away now, but a draft made before it did can still reach this
		 * screen, and reading past the end of the array filled the log with
		 * ten warnings a view. Fill in what those shapes do not carry.
		 */
		$timing = array_merge(
			array(
				'in_box' => 0,
				'due_count' => 0,
				'due_month' => '',
				'run'    => 'annual',
				'start'  => '',
				'starts' => array(),
				'mode'   => 'monthly_box',
				'modes'  => array( array( 'head' => '' ), array( 'head' => '' ) ),
				'month'  => '',
			),
			$timing
		);

		$shipping   = self::setup_shipping( $programme );
		$message    = self::setup_message( $programme );
		$people     = self::setup_people( $programme );
		$card       = ! empty( $programme['card_id'] ) ? CardsCo_Card::summary( (int) $programme['card_id'] ) : null;
		$cat        = $programme['category'] ? CardsCo_Category::get( $programme['category'] ) : null;

		$price = self::card_price( $programme, $company_id );
		$money = static function ( $amount ) use ( $company_id ) {
			return CardsCo_Shortcodes::money(
				number_format( (float) $amount, 2, '.', '' ),
				(string) CardsCo_Settings::get( 'currency', null, $company_id )
			);
		};

		/*
		 * By name, not by position. Reading the second tile as the total
		 * made step 7 say "0 people" above "3 with a date", because the
		 * second tile counts the people MISSING a date.
		 */
		$tile  = static function ( $key, $fallback = 0 ) use ( $people ) {
			foreach ( (array) $people['counts'] as $one ) {
				if ( ( $one['key'] ?? '' ) === $key ) {
					return (int) $one['n'];
				}
			}

			return $fallback;
		};

		$total = $tile( 'total', (int) $people['total'] );
		$have  = $tile( 'dated', $total );
		$box   = (int) $timing['due_count'];
		$year  = $have;

		$rows = array(
			array(
				'key'    => 'occasion',
				'label'  => __( 'Occasion', 'cardsco' ),
				'value'  => $cat ? $cat['label'] : __( 'Not chosen', 'cardsco' ),
				/*
				 * The date is formatted here rather than borrowed from the
				 * step 6 picker. Those are option labels, not dates, so
				 * pasting one into "from %s" produced "Once, from Started
				 * Tue 22 Dec 2026" and, on the default choice, "from Today,
				 * Sun 22 Nov 2026".
				 */
				'detail' => 'annual' === $timing['run']
					? sprintf(
						/* translators: %s: the start date, e.g. "Tue 22 Dec 2026". */
						__( 'Every year, from %s', 'cardsco' ),
						self::setup_start_date( $timing['start'] )
					)
					: sprintf(
						/* translators: %s: the start date, e.g. "Tue 22 Dec 2026". */
						__( 'Once, from %s', 'cardsco' ),
						self::setup_start_date( $timing['start'] )
					),
				'step'   => 1,
			),
			array(
				'key'    => 'people',
				'label'  => __( 'People', 'cardsco' ),
				'value'  => sprintf(
					/* translators: %s: how many people. */
					_n( '%s person', '%s people', $total, 'cardsco' ),
					number_format_i18n( $total )
				),
				'detail' => $total > $have
					? sprintf(
						/* translators: 1: how many have a date, 2: how many do not. */
						__( '%1$s with a date · %2$s to add later', 'cardsco' ),
						number_format_i18n( $have ),
						number_format_i18n( $total - $have )
					)
					: sprintf(
						/* translators: %s: how many have a date. */
						__( '%s with a date', 'cardsco' ),
						number_format_i18n( $have )
					),
				'step'   => 2,
			),
			array(
				'key'    => 'card',
				'label'  => __( 'Card', 'cardsco' ),
				'value'  => $card ? $card['title'] : __( 'Not chosen', 'cardsco' ),
				'detail' => $card ? $card['format'] : '',
				'step'   => 3,
			),
			array(
				'key'    => 'message',
				'label'  => __( 'Message', 'cardsco' ),
				'value'  => $message['opening'] ? $message['opening'] : __( 'Nothing written yet', 'cardsco' ),
				'detail' => trim(
					implode(
						' · ',
						array_filter(
							array(
								$message['hand'] && '—' !== $message['hand'] ? $message['hand'] : '',
								$message['signature']
									? sprintf(
										/* translators: %s: who the card is signed from. */
										__( 'signed %s', 'cardsco' ),
										$message['signature']
									)
									: '',
							)
						)
					)
				),
				'step'   => 4,
			),
			array(
				'key'    => 'shipping',
				'label'  => __( 'Shipping', 'cardsco' ),
				'value'  => 'individual' === $shipping['mode']
					? __( 'Each person’s own address', 'cardsco' )
					: ( $shipping['where'] ? $shipping['where'] : __( 'Your company address', 'cardsco' ) ),
				'detail' => 'individual' === $shipping['mode']
					? __( 'One card per address', 'cardsco' )
					: trim( implode( ', ', array_filter( $shipping['lines'] ) ) ),
				'step'   => 5,
			),
			array(
				'key'    => 'timing',
				'label'  => __( 'Timing', 'cardsco' ),
				'value'  => $timing['modes'][ 'monthly_box' === $timing['mode'] ? 0 : 1 ]['head'],
				'detail' => 'monthly_box' === $timing['mode']
					? sprintf(
						/* translators: %s: a day of the month, e.g. 25th. */
						__( 'Arrives by the %s of the month before', 'cardsco' ),
						cardsco_ordinal( (int) ( $programme['box_arrive_day'] ?? 25 ) )
					)
					: __( 'A few days before each date', 'cardsco' ),
				'step'   => 6,
			),
		);

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['edit'] = add_query_arg( 'step', $row['step'], $links['here'] );
		}

		/*
		 * The money panel was written for one shape of occasion and said so
		 * in every line: "First box", "Postage for the box", "charged before
		 * its box is written" — on an occasion whose timing is a card a few
		 * days before each person's date, where there is no box at all. And
		 * it promised "about N cards a year" for a welcome, which each
		 * person is sent once and never again.
		 */
		/*
		 * What the till actually takes. The checkout builds one line of
		 * card x everybody on the list and charges it once — so this panel,
		 * which quoted a single month of a monthly-box plan, was naming a
		 * figure the payment page was never going to ask for. Three people
		 * were quoted one card at GBP 3.60 and would have been charged
		 * GBP 10.80 on the next screen.
		 */
		$paying = count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );
		$undated = max( 0, $paying - $have );

		return array(
			'rows'  => $rows,
			'lines' => array(
				array( 'label' => __( 'Price per card', 'cardsco' ), 'value' => $money( $price ) ),
				array(
					'label' => __( 'People on this occasion', 'cardsco' ),
					'value' => sprintf(
						/* translators: %s: how many people. */
						_n( '%s person', '%s people', $paying, 'cardsco' ),
						number_format_i18n( $paying )
					),
				),
				array(
					'label' => __( 'Postage', 'cardsco' ),
					'value' => __( 'Included in the card price', 'cardsco' ),
				),
				array(
					'label' => __( 'First cards', 'cardsco' ),
					'value' => $timing['due_month'] ? $timing['due_month'] : __( 'Once dates are added', 'cardsco' ),
				),
			),
			'due'   => array(
				'label' => __( 'Due today', 'cardsco' ),
				'value' => $money( $price * $paying ),
			),
			'note'  => $undated
				? sprintf(
					/* translators: 1: one card each, 2: how many have no date yet. */
					__( 'One card for each person on your list, charged once — not month by month. %s has no date yet; their card is written as soon as you add one.', 'cardsco' ),
					sprintf(
						/* translators: %s: how many people. */
						_n( '%s person', '%s people', $undated, 'cardsco' ),
						number_format_i18n( $undated )
					)
				)
				: __( 'One card for each person on your list, charged once — not month by month.', 'cardsco' ),
		);
	}

	/**
	 * Step 6 — when the cards arrive.
	 *
	 * The chip on the monthly option is worked out rather than written: which
	 * month the next box covers, how many cards are in it, and the day it has
	 * to leave by. A box for November's birthdays arrives in October, because
	 * it has to be in somebody's hands before the first of them.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	/**
	 * What kind of date an occasion runs on.
	 *
	 * Three shapes, and step 6 is a different question for each:
	 *
	 *   per_person  a birthday or a welcome. The date differs for everybody,
	 *               so the question is how to group the sending.
	 *   fixed       Christmas, Diwali, Easter. The date is the same for
	 *               everybody but the category covers eleven of them, and
	 *               nothing has asked which one this occasion is for.
	 *   none        a promotion. There is no date until somebody picks one.
	 *
	 * @param array $programme Programme row.
	 * @return string
	 */
	public static function timing_shape( array $programme ) {
		$company_id = (int) $programme['company_id'];
		$category   = (string) $programme['category'];

		if ( CardsCo_Category::primary_type( $category, $company_id ) ) {
			return 'per_person';
		}

		foreach ( CardsCo_Category::types( $category, $company_id ) as $type ) {
			if ( ! empty( $type['fixed_month'] ) && ! empty( $type['fixed_day'] ) ) {
				return 'fixed';
			}
		}

		return 'none';
	}

	/**
	 * Step 6 for an occasion whose date is fixed — Christmas and the rest.
	 *
	 * The category covers eleven of these and nothing earlier in the wizard
	 * asks which one. It is asked here because it is what settles the date:
	 * pick the occasion, and the date comes with it.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	protected static function timing_fixed( array $programme ) {
		$company_id = (int) $programme['company_id'];
		$types      = array();

		foreach ( CardsCo_Category::types( (string) $programme['category'], $company_id ) as $type ) {
			if ( empty( $type['fixed_month'] ) || empty( $type['fixed_day'] ) ) {
				continue;
			}

			$types[ (int) $type['id'] ] = array(
				'id'    => (int) $type['id'],
				'label' => $type['label'],
				'when'  => cardsco_format_occasion_date( (int) $type['fixed_month'], (int) $type['fixed_day'] ),
				'month' => (int) $type['fixed_month'],
				'day'   => (int) $type['fixed_day'],
				'lead'  => (int) ( $type['lead_days'] ?? 7 ),
			);
		}

		$chosen = (int) ( $programme['occasion_type_id'] ?? 0 );

		if ( ! isset( $types[ $chosen ] ) ) {
			$chosen = 0;
		}

		$lands = '';
		$post  = '';

		if ( $chosen ) {
			$days = (int) ( $programme['days_before'] ?? 0 );
			$days = $days > 0 ? $days : $types[ $chosen ]['lead'];
			$when = self::next_fixed_date( $types[ $chosen ]['month'], $types[ $chosen ]['day'] );

			$lands = date_i18n( 'D j M Y', $when );
			$post  = date_i18n( 'D j M', strtotime( '-' . $days . ' days', $when ) );
		}

		return array(
			'shape'   => 'fixed',
			'types'   => array_values( $types ),
			'chosen'  => $chosen,
			'days'    => (int) ( $programme['days_before'] ?? 0 ) ?: ( $chosen ? $types[ $chosen ]['lead'] : 7 ),
			'lands'   => $lands,
			'posts'   => $post,
			'runs'    => array(
				'annual' => __( 'Every year, until you turn it off', 'cardsco' ),
				'once'   => __( 'Once, then stop', 'cardsco' ),
			),
			'run'     => 'once' === (string) ( $programme['recurrence'] ?? 'annual' ) ? 'once' : 'annual',
			'weekend' => ! empty( $programme['weekend_rule'] ),
			'people'  => count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) ),
		);
	}

	/**
	 * Step 6 for an occasion with no date at all — a promotion.
	 *
	 * Nothing decides when these go out except the person sending them, so
	 * the question is simply which day they should land on.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	protected static function timing_one_off( array $programme ) {
		$lands = (string) ( $programme['starts_on'] ?? '' );
		$days  = (int) ( $programme['days_before'] ?? 0 );
		$days  = $days > 0 ? $days : 3;

		$post = '';

		if ( $lands && CardsCo_Validator::date( $lands ) ) {
			$post = date_i18n( 'D j M', strtotime( '-' . $days . ' days', strtotime( $lands ) ) );
		}

		/*
		 * Cards are written by hand, so the earliest sensible landing date
		 * is not tomorrow. A week gives the writers room.
		 */
		return array(
			'shape'    => 'none',
			'lands'    => $lands,
			'earliest' => gmdate( 'Y-m-d', strtotime( '+7 days', (int) current_time( 'timestamp' ) ) ),
			'days'     => $days,
			'posts'    => $post,
			'weekend'  => ! empty( $programme['weekend_rule'] ),
			'people'   => count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) ),
		);
	}

	/**
	 * The next time a fixed date comes round.
	 *
	 * @param int $month Month.
	 * @param int $day   Day.
	 * @return int Timestamp.
	 */
	protected static function next_fixed_date( $month, $day ) {
		$now  = (int) current_time( 'timestamp' );
		$year = (int) gmdate( 'Y', $now );
		$when = (int) mktime( 0, 0, 0, (int) $month, (int) $day, $year );

		return $when < $now ? (int) mktime( 0, 0, 0, (int) $month, (int) $day, $year + 1 ) : $when;
	}

	public static function setup_timing( array $programme ) {
		$shape = self::timing_shape( $programme );

		if ( 'fixed' === $shape ) {
			return self::timing_fixed( $programme );
		}

		/*
		 * What this occasion is called, so the choices can say "before
		 * each welcome" rather than naming a birthday on an occasion
		 * that has nothing to do with one.
		 */
		$noun = cardsco_lcfirst( self::card_noun( (string) $programme['category'], (int) $programme['company_id'] ) );

		/*
		 * Whether this occasion happens once per person — a welcome, a
		 * thank-you — rather than coming round every year.
		 */
		$cc_type   = CardsCo_Category::primary_type( (string) $programme['category'], (int) $programme['company_id'] );
		$once_each = $cc_type && 'once' === (string) $cc_type['recurrence'];

		if ( 'none' === $shape ) {
			return self::timing_one_off( $programme );
		}

		$mode = (string) ( $programme['timing_mode'] ?? 'monthly_box' );
		$mode = in_array( $mode, array( 'monthly_box', 'per_date' ), true ) ? $mode : 'monthly_box';
		$day  = (int) ( $programme['box_arrive_day'] ?? 25 );
		$day  = $day >= 1 && $day <= 28 ? $day : 25;

		$now  = (int) current_time( 'timestamp' );
		$next = strtotime( '+1 month', strtotime( gmdate( 'Y-m-01', $now ) ) );

		// The box covering next month has to land before next month starts.
		$arrives = strtotime( gmdate( 'Y-m-', strtotime( '-1 month', $next ) ) . $day );

		if ( $arrives < $now ) {
			$next    = strtotime( '+1 month', $next );
			$arrives = strtotime( gmdate( 'Y-m-', strtotime( '-1 month', $next ) ) . $day );
		}

		$in_box = self::dates_in_month( (int) $programme['id'], (int) gmdate( 'n', $next ) );

		/*
		 * A box is packed ahead, so its first cards are next month's at the
		 * earliest. Delivered per date there is nothing to pack: the first
		 * card is the next one due, this month included.
		 */
		$due = array( 'ts' => $next, 'count' => $in_box );

		if ( 'monthly_box' !== $mode ) {
			$soon = self::next_due_month( (int) $programme['id'], $now );
			$due  = $soon ? $soon : array( 'ts' => $next, 'count' => 0 );
		}

		/*
		 * When the occasion starts running.
		 *
		 * The list used to begin at the first of the current month, so on
		 * the 22nd it offered the 1st — three weeks gone — and that was
		 * the default. Nothing can be scheduled into a date that has
		 * passed, so the first choice is today and the rest are the first
		 * of the months after this one.
		 */
		$starts = array(
			gmdate( 'Y-m-d', $now ) => sprintf(
				/* translators: %s: today's date, e.g. "Sun 22 Nov 2026". */
				__( 'Today, %s', 'cardsco' ),
				date_i18n( 'D j M Y', $now )
			),
		);

		for ( $i = 1; $i <= 5; $i++ ) {
			$when = strtotime( '+' . $i . ' month', strtotime( gmdate( 'Y-m-01', $now ) ) );

			/* Skip the 1st when today is the 1st, rather than list it twice. */
			if ( ! isset( $starts[ gmdate( 'Y-m-d', $when ) ] ) ) {
				$starts[ gmdate( 'Y-m-d', $when ) ] = date_i18n( 'D j M Y', $when );
			}
		}

		$chosen = (string) ( $programme['starts_on'] ?? '' );

		/*
		 * An occasion that has been running since before today keeps the
		 * date it actually started on, named as what it is. Dropping it
		 * would silently move the start of somebody's live occasion.
		 */
		if ( $chosen && ! isset( $starts[ $chosen ] ) ) {
			$when = strtotime( $chosen );

			/*
			 * A date off the list is not necessarily a date gone by — an
			 * admin can set any date — and "Started" over one still to come
			 * reads as though the occasion is already running.
			 */
			$starts = array(
				$chosen => $when < strtotime( gmdate( 'Y-m-d', $now ) )
					? sprintf(
						/* translators: %s: the date it started, e.g. "Sun 1 Nov 2026". */
						__( 'Started %s', 'cardsco' ),
						date_i18n( 'D j M Y', $when )
					)
					: date_i18n( 'D j M Y', $when ),
			) + $starts;
		}

		if ( ! $chosen ) {
			$chosen = (string) array_key_first( $starts );
		}

		return array(
			'mode'    => $mode,
			/* The view says it too, on the weekend switch. */
			'noun'    => $noun,
			'modes'   => array(
				array(
					'key'  => 'monthly_box',
					'head' => __( 'One box a month', 'cardsco' ),
					'sub'  => sprintf(
						/* translators: %s: the day of the month, e.g. 25th. */
						__( 'Every card for the coming month arrives together by the %s, so you can hand them out on the day.', 'cardsco' ),
						cardsco_ordinal( $day )
					),
					'chip' => sprintf(
						/* translators: 1: a month, 2: how many cards, 3: the date it arrives by. */
						__( 'Next box: %1$s · %2$s · arrives by %3$s', 'cardsco' ),
						date_i18n( 'F', $next ),
						sprintf( _n( '%s card', '%s cards', $in_box, 'cardsco' ), number_format_i18n( $in_box ) ),
						date_i18n( 'D j M', $arrives )
					),
					'on'   => 'monthly_box' === $mode,
				),
				array(
					'key'  => 'per_date',
					'head' => sprintf(
						/* translators: %s: the occasion, e.g. "birthday". */
						__( 'A few days before each %s', 'cardsco' ),
						$noun
					),
					'sub'  => sprintf(
						/* translators: 1: how many working days, 2: the occasion, e.g. "birthday". */
						__( 'One small delivery per card, arriving %1$s working days before the %2$s.', 'cardsco' ),
						'2–3',
						$noun
					),
					'chip' => '',
					'on'   => 'per_date' === $mode,
				),
			),
			'starts'  => $starts,
			'start'   => $chosen,
			/*
			 * An occasion that happens once per person has nothing to
			 * choose here: a welcome is not sent again next year. The
			 * dropdown offered "Every year, until you turn it off" on
			 * exactly those, which is the opposite of what happens —
			 * each person's card is scheduled once, from the occasion's
			 * own recurrence, and this field never fed the scheduler at
			 * all. So it is a fixed line there and a choice only where
			 * there is genuinely a choice.
			 */
			'runs'    => $once_each
				? array()
				: array(
					'annual' => __( 'Every year, until you turn it off', 'cardsco' ),
					'once'   => __( 'Once, then stop', 'cardsco' ),
				),
			'run'     => $once_each ? 'once' : ( 'once' === (string) ( $programme['recurrence'] ?? 'annual' ) ? 'once' : 'annual' ),
			'run_fixed' => $once_each
				? sprintf(
					/* translators: %s: the occasion, e.g. "welcome". */
					__( 'Once for each person. A %s is not sent again the next year.', 'cardsco' ),
					$noun
				)
				: '',
			'notify'  => ! empty( $programme['notify_before_box'] ),
			'weekend' => ! empty( $programme['weekend_rule'] ),
			'in_box'  => $in_box,
			'month'   => date_i18n( 'F', $next ),
			/*
			 * What the money panel charges for first. The box figures above
			 * stay as they are because the box option's own chip describes
			 * the box whichever delivery is currently chosen.
			 */
			'due_count' => $due['count'],
			'due_month' => date_i18n( 'F', $due['ts'] ),
		);
	}

	/**
	 * The next month that has cards due, and how many.
	 *
	 * The money panel asked "how many cards fall in next calendar month",
	 * which is a question about boxes: a box has to be packed and posted
	 * before the month it covers, so the soonest one is always next month.
	 * An occasion delivered a few days before each person's own date has no
	 * box and no cut-off — its first card is simply the next one due, which
	 * is usually still this month. So an occasion with three welcomes, one
	 * of them six days away, reported "First cards · December · 2 cards"
	 * and left the card going out first out of the count altogether.
	 *
	 * @param int $programme_id Programme.
	 * @param int $from_ts      Count from this moment.
	 * @return array{ts:int,count:int}|null Null when nothing is due at all.
	 */
	protected static function next_due_month( $programme_id, $from_ts ) {
		global $wpdb;

		$occasions = cardsco_table( 'occasions' );
		$members   = cardsco_table( 'programme_recipients' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT o.event_month AS m, o.event_day AS d, o.event_year AS y
				 FROM {$occasions} o
				 INNER JOIN {$members} pm ON pm.recipient_id = o.recipient_id
				 WHERE pm.programme_id = %d AND o.is_active = 1
				   AND o.event_month IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $programme_id )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return null;
		}

		$from   = (int) gmdate( 'Ymd', $from_ts );
		$year   = (int) gmdate( 'Y', $from_ts );
		$months = array();

		foreach ( $rows as $row ) {
			$month = (int) $row['m'];
			$day   = (int) $row['d'] > 0 ? (int) $row['d'] : 1;
			$has   = (int) $row['y'] > 0;

			if ( $has ) {
				/* A dated occasion happens once. Once it is past, it is over. */
				$due = ( (int) $row['y'] * 10000 ) + ( $month * 100 ) + $day;

				if ( $due < $from ) {
					continue;
				}
			} else {
				/* No year: it comes round again, so roll it to next year. */
				$due = ( $year * 10000 ) + ( $month * 100 ) + $day;

				if ( $due < $from ) {
					$due = ( ( $year + 1 ) * 10000 ) + ( $month * 100 ) + $day;
				}
			}

			$key = (int) floor( $due / 100 );

			$months[ $key ] = ( $months[ $key ] ?? 0 ) + 1;
		}

		if ( ! $months ) {
			return null;
		}

		ksort( $months );
		$first = (int) array_key_first( $months );

		return array(
			'ts'    => (int) mktime( 0, 0, 0, $first % 100, 1, (int) floor( $first / 100 ) ),
			'count' => (int) $months[ $first ],
		);
	}

	/**
	 * How many of a programme's people have a date in one month.
	 *
	 * @param int $programme_id Programme.
	 * @param int $month        1–12.
	 * @return int
	 */
	protected static function dates_in_month( $programme_id, $month ) {
		global $wpdb;

		$occasions = cardsco_table( 'occasions' );
		$members   = cardsco_table( 'programme_recipients' );

		/*
		 * A date that carries a year happens once and then is over. The
		 * count matched on the month alone, so a welcome dated December
		 * 2025 was still counted into the coming December's box, and the
		 * first charge covered cards nobody was going to be sent. A date
		 * with no year is a birthday and comes round every December, so
		 * those are counted whatever year it is.
		 */
		$today = (int) gmdate( 'Ymd' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$occasions} o
				 INNER JOIN {$members} m ON m.recipient_id = o.recipient_id
				 WHERE m.programme_id = %d AND o.event_month = %d AND o.is_active = 1
				   AND (
				     o.event_year IS NULL OR o.event_year = 0
				     OR ( o.event_year * 10000 ) + ( o.event_month * 100 ) + COALESCE( o.event_day, 1 ) >= %d
				   )", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $programme_id ),
				absint( $month ),
				$today
			)
		);
	}

	/**
	 * Step 5 — where the cards get shipped.
	 *
	 * The frame gives five address lines and a country, and the model keeps
	 * the address as one block. Five lines are written and read back every
	 * time, padded where empty, so the split is lossless in both directions.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	public static function setup_shipping( array $programme ) {
		$company_id = CardsCo_Access::company_id();
		$company    = $company_id ? CardsCo_Company::get( $company_id ) : null;

		$mode = (string) ( $programme['shipping_mode'] ?? 'company' );
		$mode = in_array( $mode, array( 'company', 'individual' ), true ) ? $mode : 'company';

		$block = (string) ( $programme['ship_address'] ?? '' );

		// Fall back to the company's billing address, which is what "saved
		// from your last campaign" means on a first run.
		$from_company = false;

		if ( '' === trim( $block ) && $company && ! empty( $company['billing_address'] ) ) {
			$block        = (string) $company['billing_address'];
			$from_company = true;
		}

		$lines = preg_split( '/\R/', $block );
		$lines = array_slice( array_pad( (array) $lines, 5, '' ), 0, 5 );

		return array(
			'mode'    => $mode,
			'modes'   => array(
				array(
					'key'  => 'company',
					'icon' => 'building',
					'head' => __( 'Ship to our company address', 'cardsco' ),
					'sub'  => __( 'Cards arrive at the office, ready to hand out.', 'cardsco' ),
					'on'   => 'company' === $mode,
				),
				array(
					'key'  => 'individual',
					'icon' => 'users',
					'head' => __( 'Ship to individual addresses', 'cardsco' ),
					'sub'  => __( 'Each card goes to the person’s home address.', 'cardsco' ),
					'on'   => 'individual' === $mode,
				),
			),
			'lines'   => array_map( 'strval', $lines ),
			'country'  => (string) ( $programme['ship_country'] ?? '' ) ? (string) $programme['ship_country'] : 'GB',
			'postcode' => (string) ( $programme['ship_postcode'] ?? '' ),
			'source'  => $from_company
				? __( 'Saved from your company profile', 'cardsco' )
				: __( 'Saved from your last campaign', 'cardsco' ),
			'where'   => trim( (string) ( $lines[1] ?: $lines[0] ) ) . ( trim( (string) $lines[3] ) ? ', ' . trim( (string) $lines[3] ) : '' ),

			/*
			 * Everybody on the occasion, with whatever address is on file.
			 *
			 * Choosing "ship to individual addresses" used to turn the
			 * company panel off and offer nothing in its place, so the
			 * step asked a question and then gave no way to answer it.
			 */
			'people'  => self::shipping_people( $programme ),
		);
	}

	/**
	 * Each person on the occasion and the address their card would go to.
	 *
	 * Prefilled from what the list already holds — an upload that carried
	 * addresses fills these in without anyone retyping them — and empty
	 * for anyone whose address nobody has supplied yet.
	 *
	 * @param array $programme Programme row.
	 * @return array[]
	 */
	public static function shipping_people( array $programme ) {
		$out = array();

		foreach ( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) as $rid ) {
			$row = CardsCo_Recipient::get( (int) $rid );

			if ( ! $row ) {
				continue;
			}

			$address = trim( (string) $row['address'] );

			$out[] = array(
				'id'       => (int) $rid,
				'name'     => trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'initials' => self::initials( $row['first_name'], $row['last_name'] ),
				'address'  => $address,
				'postcode' => trim( (string) $row['postcode'] ),
				/*
				 * Complete is the model's own word for "enough to post".
				 * Anything else is a card that would be held back, so the
				 * step says so here rather than letting it surface three
				 * screens later as a held card nobody expected.
				 */
				'ready'    => 'complete' === (string) $row['address_status'],
			);
		}

		return $out;
	}

	/**
	 * The Settings screen.
	 *
	 * Measured from the Settings frame: a page head, three tabs, then
	 * sections made of a 328-wide label column at x=280 and a 792-wide panel
	 * at x=608, with a save bar at the foot.
	 *
	 * Only the Company profile tab is drawn in the frames. Billing and Team
	 * access exist as tabs there with nothing behind them, so they are
	 * carried here as tabs and answered honestly rather than invented.
	 *
	 * @param string $tab Which tab is open.
	 * @return array
	 */
	/**
	 * Who is on the company's account.
	 *
	 * Each person carries what the viewer may do to them, rather than the
	 * view working it out: the rules live in one place and the markup only
	 * draws what it is handed.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	public static function settings_team( $company_id ) {
		if ( ! $company_id ) {
			return array( 'people' => array(), 'may_manage' => false, 'roles' => array() );
		}

		$people = array();

		foreach ( CardsCo_Invite::people( $company_id ) as $person ) {
			/*
			 * Somebody who has been invited but has not set a password yet
			 * has no name, so their email stands in for one. Printing it
			 * again underneath just repeats it.
			 */
			$person['named']      = $person['name'] !== $person['email'];
			$person['is_you']     = (int) $person['user_id'] === get_current_user_id();
			$person['role_label'] = self::team_role_label( $person['role'] );
			$person['may_manage'] = CardsCo_Access::may_manage_person( (int) $person['user_id'] );
			$people[]             = $person;
		}

		/* The owner first, then admins, then everybody else. */
		$order = array( 'owner' => 0, 'admin' => 1, 'member' => 2 );

		usort(
			$people,
			static function ( $a, $b ) use ( $order ) {
				$one = ( $order[ $a['role'] ] ?? 3 ) <=> ( $order[ $b['role'] ] ?? 3 );

				return $one ?: strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'people'     => $people,
			'may_manage' => CardsCo_Access::may_manage_company(),
			/* The owner is not offered: handing the company over is its own
			   thing, not something to do from a dropdown beside a name. */
			'roles'      => array(
				'admin'  => self::team_role_label( 'admin' ),
				'member' => self::team_role_label( 'member' ),
			),
		);
	}

	/**
	 * What a role is called, and what it means.
	 *
	 * @param string $role owner, admin or member.
	 * @return string
	 */
	public static function team_role_label( $role ) {
		$words = array(
			'owner'  => __( 'Owner', 'cardsco' ),
			'admin'  => __( 'Admin', 'cardsco' ),
			'member' => __( 'Member', 'cardsco' ),
		);

		return $words[ $role ] ?? __( 'Member', 'cardsco' );
	}

	/**
	 * One line saying what a role may do.
	 *
	 * @param string $role owner, admin or member.
	 * @return string
	 */
	public static function team_role_blurb( $role ) {
		$words = array(
			'owner'  => __( 'Everything, including billing. Can’t be removed.', 'cardsco' ),
			'admin'  => __( 'Can change company details and manage people.', 'cardsco' ),
			'member' => __( 'Can set up occasions and write cards.', 'cardsco' ),
		);

		return $words[ $role ] ?? '';
	}

	public static function settings_screen( $tab = 'profile' ) {
		$company_id = CardsCo_Access::company_id();
		$company    = $company_id ? CardsCo_Company::get( $company_id ) : null;
		$known      = array( 'profile', 'billing', 'team' );
		$tab        = in_array( $tab, $known, true ) ? $tab : 'profile';

		$get = static function ( $key, $default = '' ) use ( $company_id ) {
			return (string) CardsCo_Settings::get( $key, $default, $company_id );
		};

		$logo_id  = $company ? (int) $company['logo_attachment_id'] : 0;
		$logo_url = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$logo     = null;

		if ( $logo_id ) {
			$file = get_attached_file( $logo_id );

			$logo = array(
				'id'    => $logo_id,
				'url'   => $logo_url,
				'name'  => $file ? basename( $file ) : '',
				'bytes' => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
			);
		}

		return array(
			'tab'      => $tab,
			'tabs'     => array(
				array(
					'key'   => 'profile',
					'label' => __( 'Company profile', 'cardsco' ),
					'on'    => 'profile' === $tab,
				),
				array(
					'key'   => 'billing',
					'label' => __( 'Billing', 'cardsco' ),
					'on'    => 'billing' === $tab,
				),
				array(
					'key'   => 'team',
					'label' => __( 'Team access', 'cardsco' ),
					'on'    => 'team' === $tab,
				),
			),
			'company'  => $company,
			'name'     => $company ? (string) $company['name'] : '',
			'address'  => $company ? (string) $company['billing_address'] : '',

			/*
			 * Both of these are read at the checkout already — the basket
			 * is filled in from billing_name and billing_email before
			 * anything else is tried — and until now there was nowhere in
			 * the product to set either. The prefill fell back to the
			 * contact, or to the WordPress account, every single time.
			 */
			'bill_to'  => $company ? (string) $company['billing_name'] : '',
			'bill_at'  => $company ? (string) $company['billing_email'] : '',
			'trading'  => $get( 'trading_name' ),
			'number'   => $get( 'company_number' ),
			'vat'      => $get( 'vat_number' ),
			'returns'  => $get( 'return_mode', 'billing' ),
			'return'   => $get( 'return_address' ),
			'signoff'  => $get( 'card_signoff' ),
			'logo'     => $logo,
			'team'     => self::settings_team( $company_id ),
			'role'     => CardsCo_Access::company_role(),
			'choices'  => CardsCo_Settings::definition( 'return_mode' )['choices'],
			'saved'    => self::settings_saved_line( $company ),
			'may_edit' => current_user_can( 'cardsco_manage_company' ),
		);
	}

	/**
	 * "Last saved 2 Oct 2026 by Sam Carter", as the frame's foot has it.
	 *
	 * @param array|null $company Company row.
	 * @return string
	 */
	protected static function settings_saved_line( $company ) {
		if ( ! $company || empty( $company['updated_at'] ) ) {
			return '';
		}

		$when = strtotime( (string) $company['updated_at'] );

		if ( ! $when ) {
			return '';
		}

		$who = (string) CardsCo_Settings::get( 'profile_saved_by', '', (int) $company['id'] );

		if ( $who ) {
			return sprintf(
				/* translators: 1: a date, 2: who saved it. */
				__( 'Last saved %1$s by %2$s', 'cardsco' ),
				date_i18n( 'j M Y', $when ),
				$who
			);
		}

		return sprintf(
			/* translators: %s: a date. */
			__( 'Last saved %s', 'cardsco' ),
			date_i18n( 'j M Y', $when )
		);
	}

	/**
	 * Step 4 — what the message looks like, and what it is made of.
	 *
	 * The frame is a preview beside a summary rather than an editor: the
	 * writing itself happens in the card writer, which this screen links to.
	 * So everything here is read-only, including the rendered preview, which
	 * is shown against a real person from the programme so the merge fields
	 * are filled in with a name that actually exists.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	public static function setup_message( array $programme ) {
		$company_id = CardsCo_Access::company_id();
		$max        = (int) CardsCo_Settings::get( 'card_max_chars', 400, $company_id );

		$text = trim( (string) ( $programme['message_override'] ?? '' ) );

		if ( '' === $text && ! empty( $programme['message_id'] ) ) {
			$saved = get_post( (int) $programme['message_id'] );
			$text  = $saved ? (string) get_post_meta( $saved->ID, CardsCo_Message::TEXT, true ) : '';
		}

		// Preview against somebody real, so the name in it is a name the
		// customer will recognise rather than a placeholder.
		$who  = null;
		$ids  = CardsCo_Programme::recipient_ids( (int) $programme['id'] );
		$name = '';

		foreach ( (array) $ids as $rid ) {
			$row = CardsCo_Recipient::get( (int) $rid );

			if ( $row && ! empty( $row['first_name'] ) ) {
				$who  = $row;
				$name = $row['first_name'];
				break;
			}
		}

		$rendered = $text;

		/*
		 * Whoever the preview is for, whether or not anything is written
		 * yet. Built only alongside the first render, it was empty on an
		 * occasion with no saved message — which is every occasion at the
		 * moment somebody sits down to write one, so the card showed raw
		 * tags exactly when it mattered.
		 */
		$sample = $who ? CardsCo_Merge::context_for_recipient( $who ) : array();

		if ( $text && $who ) {
			/*
			 * render() answers with a result, not a string: the filled text
			 * plus what was missing, what was not recognised, and how long
			 * it came out. Taking the whole thing as the text turned the
			 * card preview into the single word "Array" and made the
			 * character count 14 for every message ever written. Every other
			 * caller in the plugin reads the keys; this one did not.
			 */
			$merged   = CardsCo_Merge::render( $text, CardsCo_Merge::context_for_recipient( $who ) );
			$rendered = (string) $merged['text'];
		}

		$signature = trim( (string) ( $programme['signature'] ?? '' ) );
		$ink       = trim( (string) ( $programme['ink'] ?? '' ) );
		$hand      = trim( (string) ( $programme['handwriting'] ?? '' ) );

		// Length is measured on the rendered card, because that is what has
		// to fit — a long first name is what pushes a message over.
		$used = $rendered ? mb_strlen( $rendered . ( $signature ? "\n" . $signature : '' ) ) : 0;

		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $rendered ) ), 'strlen' ) );
		$raw   = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $text ) ), 'strlen' ) );

		return array(
			'card'      => ! empty( $programme['card_id'] ) ? CardsCo_Card::summary( (int) $programme['card_id'] ) : null,
			'lines'     => $lines,
			'signature' => $signature,
			'opening'   => $raw ? $raw[0] : '',
			'hand'      => $hand && $ink
				? sprintf(
					/* translators: 1: the handwriting style, 2: the ink colour. */
					_x( '%1$s · %2$s', 'handwriting and ink', 'cardsco' ),
					$hand,
					$ink
				)
				: ( $hand ? $hand : '—' ),
			'used'      => $used,
			'max'       => $max,
			'over'      => $max > 0 && $used > $max,
			'for'       => $name,
			/*
			 * The values behind the preview, so it keeps showing the card
			 * as it will read while somebody types. The first render is
			 * merged server-side, but the live repaint had nothing to
			 * merge with and put the raw {first name} back on the card
			 * under the words "Previewed for Zara".
			 */
			'sample'    => array_map( 'strval', (array) $sample ),
			'ready'     => '' !== $text,
		);
	}

	/**
	 * What the card writer needs.
	 *
	 * Built on top of setup_message() rather than beside it, so the length
	 * the writer counts and the length step 4 reports can never disagree —
	 * both are measured on the rendered card, signature included, because a
	 * long first name is what pushes a message over the limit.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	/**
	 * Which merge tags would come out blank on somebody's card.
	 *
	 * The writer already stops a message that still has [square brackets]
	 * in it, because a pen writes those exactly as they read. A merge tag
	 * that resolves to nothing is the same fault wearing different
	 * punctuation — "from all of us at ." — but it looks fine in the box,
	 * so the browser let it through and the server refused it on save.
	 * The tags that would fall over are worked out here and handed to the
	 * writer, which warns about them the same way it warns about brackets.
	 *
	 * {our company} is the one that usually bites: it is blank until a
	 * trading name is saved in Settings.
	 *
	 * @param array $programme Programme row.
	 * @return array Tag names, e.g. array( 'our_company' ).
	 */
	/**
	 * The merge tags the writer offers, as chips.
	 *
	 * Written out as they go in the message — `{first name}`, with the
	 * space — because that is what the engine reads, and a chip that
	 * inserts something the engine does not recognise is worse than no
	 * chip at all.
	 *
	 * @param array $programme Programme row.
	 * @return array[] tag, label, note and what an empty one means.
	 */
	public static function writer_tags( array $programme ) {
		$campaign = 'campaign' === (string) ( $programme['source'] ?? '' );
		$out      = array();

		foreach ( CardsCo_Merge::fields_for( $campaign ) as $key => $field ) {
			$out[] = array(
				/* The engine reports gaps by key, so the chip carries both. */
				'key'   => $key,
				'tag'   => '{' . str_replace( '_', ' ', $key ) . '}',
				'label' => $field['label'],
				'note'  => $field['note'],
				'empty' => sprintf(
					/* translators: %s: the tag's label, e.g. "Last name". */
					__( '%s is missing for somebody on this occasion, so their card would have a gap there.', 'cardsco' ),
					$field['label']
				),
			);
		}

		return $out;
	}

	public static function blank_tags( array $programme ) {
		$blank = array();
		$ids   = CardsCo_Programme::recipient_ids( (int) $programme['id'] );

		// Every tag there is, so one render says which of them are empty.
		$probe = '';

		foreach ( array_keys( CardsCo_Merge::fields() ) as $key ) {
			$probe .= '{' . str_replace( '_', ' ', $key ) . '}';
		}

		foreach ( (array) $ids as $rid ) {
			$row = CardsCo_Recipient::get( (int) $rid );

			if ( ! $row ) {
				continue;
			}

			$try = CardsCo_Merge::render( $probe, CardsCo_Merge::context_for_recipient( $row ) );

			foreach ( (array) ( $try['missing'] ?? array() ) as $gap ) {
				$blank[ (string) $gap ] = true;
			}
		}

		return array_keys( $blank );
	}

	public static function setup_writer( array $programme ) {
		$company_id = CardsCo_Access::company_id();
		$message    = self::setup_message( $programme );

		$text = trim( (string) ( $programme['message_override'] ?? '' ) );

		if ( '' === $text && ! empty( $programme['message_id'] ) ) {
			$text = CardsCo_Message::text( (int) $programme['message_id'] );
		}

		/*
		 * A message the server has just refused comes back ahead of the
		 * saved one, so the person carries on from their own words instead
		 * of starting again from whatever was saved last.
		 */
		$kept      = CardsCo_Setup::take_draft( (int) $programme['id'] );
		$signature = trim( (string) ( $programme['signature'] ?? '' ) );

		if ( $kept ) {
			$text      = (string) $kept['text'];
			$signature = (string) $kept['signature'];
		}

		/*
		 * "Start from one of ours": the saved messages for this occasion.
		 *
		 * The frame offers five chips that kick off an AI draft. There is no
		 * drafting here, so they do the one thing left that is useful —
		 * drop a written message into the box for the person to edit.
		 */
		/*
		 * Every occasion in the category, not just its first. Customer
		 * Milestones is a welcome and a customer anniversary, and all
		 * eight of its messages are written for the anniversary — so
		 * asking for the first one found nothing and the screen offered
		 * the whole library instead, birthdays included.
		 */
		$type  = CardsCo_Category::primary_type( (string) $programme['category'], $company_id );
		$types = wp_list_pluck( CardsCo_Category::types( (string) $programme['category'], $company_id ), 'id' );
		$types = array_values( array_filter( array_map( 'absint', (array) $types ) ) );

		/*
		 * Six chips fit, and a category can hold more than six messages
		 * across more than one occasion. Taken in order, the first occasion
		 * fills the row and the second barely appears: Leaving & Retirement
		 * has four of each, and somebody setting up a retirement was offered
		 * four leaving messages and two retirements.
		 *
		 * So they are grouped by occasion first and then taken one from each
		 * in turn. With a single occasion that is the plain order it always
		 * was.
		 */
		$by_type = array();

		foreach ( CardsCo_Message::for_occasion( $types, $company_id ) as $post ) {
			$body = trim( CardsCo_Message::text( $post->ID ) );

			if ( '' === $body ) {
				continue;
			}

			$own = CardsCo_Message::occasions( $post->ID );
			$key = $own ? (int) $own[0] : 0;

			$by_type[ $key ][] = array(
				'id'    => (int) $post->ID,
				'label' => get_the_title( $post ),
				'text'  => $body,
			);
		}

		$templates = array();
		$seen      = array();

		while ( $by_type && count( $templates ) < 6 ) {
			foreach ( array_keys( $by_type ) as $key ) {
				$next = array_shift( $by_type[ $key ] );

				if ( ! $by_type[ $key ] ) {
					unset( $by_type[ $key ] );
				}

				// Two chips with the same wording behind them are one chip
				// as far as the person is concerned.
				if ( null === $next || isset( $seen[ $next['text'] ] ) ) {
					continue;
				}

				$seen[ $next['text'] ] = true;
				$templates[]           = $next;

				if ( count( $templates ) >= 6 ) {
					break;
				}
			}
		}

		/*
		 * The frame puts "Posts Mon 26 Oct · lands Wed 28 Oct" in the corner.
		 * Both come from the programme's own timing, so an empty string here
		 * means the dates are not settled yet and the view leaves the line
		 * out rather than printing half of it.
		 */
		$period = CardsCo_Programme::next_period( $programme );
		$posts  = $period ? CardsCo_Programme::box_arrive_date( $programme, $period ) : '';

		return array(
			'blanks'    => self::blank_tags( $programme ),
			'tags'      => self::writer_tags( $programme ),
			'text'      => $text,
			'lines'     => $message['lines'],
			'signature' => $signature,
			'hand'      => $message['hand'],
			'used'      => $message['used'],
			'max'       => $message['max'],
			'over'      => $message['over'],
			'for'       => $message['for'],
			'sample'    => $message['sample'],
			'ready'     => $message['ready'],
			'card'      => $message['card'],
			'templates' => $templates,
			'posts'     => $posts ? mysql2date( 'D j M', $posts ) : '',
			'noun'      => self::card_noun( (string) $programme['category'], $company_id ),
			'people'    => count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) ),
		);
	}

	/**
	 * The step's heading.
	 *
	 * Mostly the fixed string on the step itself, but steps 3 and 4 name the
	 * occasion — "Choose a birthday card", "Your birthday message" — which
	 * only this layer knows.
	 *
	 * @param int        $step      Step on screen.
	 * @param array      $programme Programme row.
	 * @param array|null $category  The chosen category.
	 * @return string
	 */
	public static function setup_heading( $step, array $programme, $category ) {
		$step  = (int) $step;
		$steps = CardsCo_Setup::steps();
		$fixed = isset( $steps[ $step ]['heading'] ) ? $steps[ $step ]['heading'] : '';

		if ( ! $category || ( 3 !== $step && 4 !== $step ) ) {
			return $fixed;
		}

		$noun = cardsco_lcfirst( self::card_noun( $programme['category'], CardsCo_Access::company_id() ) );

		if ( ! $noun ) {
			return $fixed;
		}

		if ( 3 === $step ) {
			return sprintf(
				/* translators: %s: the occasion in lower case, e.g. birthday. */
				__( 'Choose a %s card', 'cardsco' ),
				$noun
			);
		}

		return sprintf(
			/* translators: %s: the occasion in lower case, e.g. birthday. */
			__( 'Your %s message', 'cardsco' ),
			$noun
		);
	}

	/**
	 * What the footer bar says beside the Continue button.
	 *
	 * @param array      $programme Programme row.
	 * @param int        $step      Step on screen.
	 * @param array|null $category  The chosen category.
	 * @return string
	 */
	protected static function setup_footer( array $programme, $step, $category ) {
		if ( 1 === (int) $step ) {
			return $category
				? sprintf(
					/* translators: %s: the occasion chosen. */
					__( '%s selected', 'cardsco' ),
					$category['label']
				)
				: __( 'Nothing selected yet', 'cardsco' );
		}

		if ( 2 === (int) $step ) {
			$ready = self::setup_people( $programme )['counts'][0]['n'];

			return sprintf(
				/* translators: %s: how many people have a date. */
				_n( '%s birthday ready', '%s birthdays ready', $ready, 'cardsco' ),
				number_format_i18n( $ready )
			);
		}

		if ( 7 === (int) $step ) {
			return __( 'Payment happens on our secure payment page', 'cardsco' );
		}

		if ( 6 === (int) $step ) {
			$timing = self::setup_timing( $programme );

			if ( 'monthly_box' !== $timing['mode'] ) {
				return __( 'One delivery per card', 'cardsco' );
			}

			return sprintf(
				/* translators: 1: a month, 2: how many cards are in that box. */
				__( '%1$s box: %2$s', 'cardsco' ),
				$timing['month'],
				sprintf(
					/* translators: %s: how many cards. */
					_n( '%s card', '%s cards', $timing['in_box'], 'cardsco' ),
					number_format_i18n( $timing['in_box'] )
				)
			);
		}

		if ( 3 === (int) $step ) {
			$card = ! empty( $programme['card_id'] )
				? CardsCo_Card::summary( (int) $programme['card_id'] )
				: null;

			return $card
				? sprintf(
					/* translators: %s: the card chosen. */
					__( '%s selected', 'cardsco' ),
					$card['title']
				)
				: __( 'No card chosen yet', 'cardsco' );
		}

		return '';
	}

	/**
	 * Can this step be left yet?
	 *
	 * @param array $programme Programme row.
	 * @param int   $step      Step on screen.
	 * @return bool
	 */
	protected static function setup_ready( array $programme, $step ) {
		/*
		 * Step 1 used to be gated on the programme already having a category
		 * — but a category is only stored once the step is submitted, and the
		 * only way to submit it is the button this gate was disabling. Nobody
		 * could get past the first screen from a cold start.
		 *
		 * Choosing is a client-side act, so the server cannot know about it
		 * in time. The button stays live and the submit is validated instead,
		 * which is the right place for it.
		 */
		if ( 1 === (int) $step ) {
			return true;
		}

		if ( 2 === (int) $step ) {
			return self::setup_people( $programme )['counts'][0]['n'] > 0;
		}

		return true;
	}

	/**
	 * Step 1's choices: the catalogue, with the chosen one marked.
	 *
	 * @param array $programme Programme row.
	 * @return array[]
	 */
	/**
	 * Where a campaign-driven occasion is actually set up.
	 *
	 * Carries the occasion with it, so the builder opens with it already
	 * chosen rather than asking again for what has just been picked.
	 *
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company.
	 * @return string
	 */
	protected static function campaign_link( $slug, $company_id ) {
		$page = CardsCo_Shortcodes::page_for( 'cardsco_campaigns' );

		if ( ! $page ) {
			return '';
		}

		$link  = add_query_arg( 'new', '1', $page );
		$types = CardsCo_Category::types( $slug, $company_id );
		$first = $types ? reset( $types ) : null;

		if ( $first && ! empty( $first['id'] ) ) {
			$link = add_query_arg( 'occasion_type_id', (int) $first['id'], $link );
		}

		return $link;
	}

	public static function setup_choices( array $programme ) {
		$company_id = CardsCo_Access::company_id();
		$out        = array();

		/*
		 * An occasion named in the address is already an answer to this
		 * screen's question, so it arrives ticked. The Occasions page posts
		 * its choice now rather than linking it, but links of that shape
		 * were handed out before and sit in bookmarks and browser history,
		 * and the screen used to ignore the slug entirely: it asked "Which
		 * occasion?" with the answer in the address bar and nothing marked.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- marks a radio, takes no action.
		$asked = isset( $_GET['occasion'] ) ? sanitize_title( wp_unslash( $_GET['occasion'] ) ) : '';

		if ( '' !== $asked && '' === (string) $programme['category'] && CardsCo_Category::get( $asked ) ) {
			$programme['category'] = $asked;
		}

		foreach ( CardsCo_Category::all() as $slug => $cat ) {
			$designs = $company_id ? CardsCo_Category::design_count( $slug, $company_id ) : 0;

			/*
			 * Some of these are not set up here at all: they go out on a
			 * day somebody chooses rather than from dates people hold, and
			 * start() has always refused them. The refusal was the first
			 * anyone heard of it — the tile looked exactly as pickable as
			 * the rest — so it is said on the tile, and the tile takes
			 * them where it is actually done.
			 *
			 * Only once the install has occasion types at all; on one that
			 * has never been seeded every category reads as campaign-driven
			 * and the whole screen would send people away.
			 */
			$campaign_only = ! empty( CardsCo_Occasion_Type::all( $company_id ) )
				&& empty( $cat['coming_soon'] )
				&& ! CardsCo_Category::is_dated( $slug, $company_id );

			$out[] = array(
				'slug'          => $slug,
				'label'         => $cat['label'],
				'blurb'         => $cat['blurb'],
				'coming_soon'   => ! empty( $cat['coming_soon'] ),
				'campaign_only' => $campaign_only,
				'campaign_link' => $campaign_only ? self::campaign_link( $slug, $company_id ) : '',
				'chosen'        => $programme['category'] === $slug,
				'art'           => self::category_art( $slug, $company_id ),
				'stat'          => ! empty( $cat['coming_soon'] )
					? __( 'Coming soon · use a milestone card for now', 'cardsco' )
					: sprintf(
						/* translators: 1: how many designs, 2: the card format. */
						_n( '%1$s design · %2$s', '%1$s designs · %2$s', $designs, 'cardsco' ),
						number_format_i18n( $designs ),
						$cat['format']
					),
			);
		}

		return $out;
	}

	/**
	 * The note under step 1's grid, once something is chosen.
	 *
	 * @param array $programme Programme row.
	 * @return string
	 */
	public static function setup_note( array $programme ) {
		if ( ! $programme['category'] ) {
			return '';
		}

		$cat = CardsCo_Category::get( $programme['category'] );

		if ( ! $cat ) {
			return '';
		}

		return CardsCo_Category::is_dated( $programme['category'], (int) $programme['company_id'] )
			? sprintf(
				/* translators: %s: the occasion. */
				__( '%s cards go out on each person’s own date. You’ll add the dates in the next step.', 'cardsco' ),
				self::card_noun( $programme['category'], (int) $programme['company_id'] )
			)
			: sprintf(
				/* translators: %s: the occasion. */
				__( '%s cards go out when you send a campaign rather than on a date of their own.', 'cardsco' ),
				self::card_noun( $programme['category'], (int) $programme['company_id'] )
			);
	}

	/**
	 * Step 2's people, counts and upload state.
	 *
	 * @param array $programme Programme row.
	 * @return array
	 */
	public static function setup_people( array $programme ) {
		$ids     = $programme['id'] ? CardsCo_Programme::recipient_ids( (int) $programme['id'] ) : array();
		$rows    = array();
		$dated   = 0;
		$undated = 0;

		/*
		 * Does this occasion have a date that differs from person to person?
		 *
		 * Only some do. A birthday does. Christmas does not — it is the 25th
		 * for everybody — and Promotions & Offers has no date at all, because
		 * it goes out when a campaign runs. For those, there is nothing to
		 * collect per person, so the column of missing dates and the "Add a
		 * birthday" link beside each name are not just unhelpful, they offer
		 * something that cannot be done.
		 */
		$per_person = (bool) CardsCo_Category::primary_type(
			(string) $programme['category'],
			(int) $programme['company_id']
		);

		foreach ( $ids as $rid ) {
			$person = CardsCo_Recipient::get( $rid );

			if ( ! $person ) {
				continue;
			}

			$occasion = self::person_occasion( (int) $rid, $programme );
			$has      = (bool) $occasion;

			if ( $has ) {
				$dated++;
			} else {
				$undated++;
			}

			/*
			 * Everyone, not the first four.
			 *
			 * The frame showed four and a "Showing 4 of 42" count, and
			 * there was nothing to press to see the rest — so a person
			 * further down the list could not be looked at, and once
			 * each row grew a Remove button, could not be taken off
			 * either. The table scrolls instead, which keeps the step
			 * short without putting anybody out of reach. The cap is
			 * only so a company with thousands does not render them all
			 * into one page.
			 */
			if ( count( $rows ) < 200 ) {
				$rows[] = array(
					'id'       => (int) $rid,
					'name'     => trim( $person['first_name'] . ' ' . $person['last_name'] ),
					// Nothing is "missing" when nothing was ever asked for.
					'has_date' => $per_person ? $has : true,
					'birthday' => $has
						? gmdate( 'j F', strtotime( '2001-' . str_pad( (string) $occasion['event_month'], 2, '0', STR_PAD_LEFT ) . '-' . str_pad( (string) $occasion['event_day'], 2, '0', STR_PAD_LEFT ) ) )
						: ( $per_person ? __( 'Missing', 'cardsco' ) : __( 'Not needed', 'cardsco' ) ),
					'next'     => $has && $occasion['next_occurrence']
						? gmdate( 'D j M', strtotime( $occasion['next_occurrence'] ) )
						: '',
				);
			}
		}

		$noun = cardsco_lcfirst( self::date_noun( (string) $programme['category'], (int) $programme['company_id'] ) );

		/*
		 * The counts only make sense where a date is collected. Telling
		 * somebody running a promotion that forty people are "missing a
		 * birthday" is noise about a field that was never asked for.
		 */
		/*
		 * Each tile says what it is.
		 *
		 * They used to be told apart by position, and the review on step 7
		 * read the second one as the total — but the second one is how
		 * many are MISSING a date, so an occasion where everybody had one
		 * was summed up as "0 people" directly above "3 with a date".
		 * The shape changes with the occasion too: one tile when the date
		 * is not the person's, three when it is.
		 */
		$counts = $per_person
			? array(
				array(
					'key'   => 'dated',
					'n'     => $dated,
					/* translators: %s: the occasion, e.g. "birthday". */
					'label' => sprintf( __( 'with a %s', 'cardsco' ), $noun ),
				),
				array(
					'key'   => 'undated',
					'n'     => $undated,
					/* translators: %s: the occasion, e.g. "birthday". */
					'label' => sprintf( __( 'missing a %s', 'cardsco' ), $noun ),
				),
				array( 'key' => 'total', 'n' => count( $ids ), 'label' => __( 'people in total', 'cardsco' ) ),
			)
			: array(
				array( 'key' => 'total', 'n' => count( $ids ), 'label' => __( 'people in total', 'cardsco' ) ),
			);

		/*
		 * People the company already has. Offered here so a second
		 * occasion does not mean uploading the same list again.
		 */
		$available = CardsCo_Programme::available_recipients( $programme );

		return array(
			'rows'       => $rows,
			'total'      => count( $ids ),
			'per_person' => $per_person,
			'noun'       => self::date_noun( (string) $programme['category'], (int) $programme['company_id'] ),
			'counts'     => $counts,
			'available'  => array_map(
				static function ( $person ) {
					return array(
						'id'   => (int) $person['id'],
						'name' => trim( $person['first_name'] . ' ' . $person['last_name'] ),
						'what' => 'employee' === $person['type']
							? __( 'Staff', 'cardsco' )
							: __( 'Customer', 'cardsco' ),
					);
				},
				$available
			),
			'upload' => self::upload_row( $ids ),
		);
	}

	/**
	 * What an upload just did, in a sentence.
	 *
	 * Read off the redirect the import leaves behind. Silence after a file
	 * goes in is the worst outcome: the list is longer and nothing says
	 * whether the rows that mattered were understood.
	 *
	 * @return string Empty when no upload has just happened.
	 */
	protected static function upload_report() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of a completed action.
		if ( ! isset( $_GET['cc_up_added'] ) ) {
			return '';
		}

		$added   = absint( $_GET['cc_up_added'] );
		$matched = absint( $_GET['cc_up_matched'] ?? 0 );
		$nodate  = absint( $_GET['cc_up_nodate'] ?? 0 );
		$bad     = absint( $_GET['cc_up_bad'] ?? 0 );
		$took    = absint( $_GET['cc_up_took'] ?? 0 );
		$on      = absint( $_GET['cc_up_on'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$bits = array();

		/* When the file replaced the list, say so first: it is the big thing that happened. */
		if ( $took ) {
			$bits[] = sprintf(
				/* translators: %s: how many people came off the occasion. */
				_n( '%s person taken off', '%s people taken off', $took, 'cardsco' ),
				number_format_i18n( $took )
			);
		}

		/*
		 * What the file did to this occasion, which is the thing being
		 * looked at. "Added" and "already on your list" are about the
		 * company's records — a file of six people who all already
		 * existed reported "0 people added", which reads as nothing
		 * having happened while six of them went onto the occasion.
		 */
		$bits[] = sprintf(
			/* translators: %s: how many people. */
			_n( '%s person from the file is on this occasion', '%s people from the file are on this occasion', $on, 'cardsco' ),
			number_format_i18n( $on )
		);

		/* New to the company's people list, which is worth knowing but not the headline. */
		if ( $added ) {
			$bits[] = sprintf(
				/* translators: %s: how many people. */
				_n( '%s is new to your people', '%s are new to your people', $added, 'cardsco' ),
				number_format_i18n( $added )
			);
		}

		if ( $nodate ) {
			$bits[] = sprintf(
				/* translators: %s: how many people. */
				_n( '%s without a date', '%s without a date', $nodate, 'cardsco' ),
				number_format_i18n( $nodate )
			);
		}

		if ( $bad ) {
			$bits[] = sprintf(
				/* translators: %s: how many rows. */
				_n( '%s row could not be read', '%s rows could not be read', $bad, 'cardsco' ),
				number_format_i18n( $bad )
			);
		}

		return implode( ' · ', $bits );
	}

	/**
	 * What the add-by-hand screen needs.
	 *
	 * Two states in one frame pair: the empty form, and the confirmation
	 * after a save with the list of who is on the programme so far. Which
	 * one shows is decided by whether a person was just added.
	 *
	 * @param array $programme Programme row.
	 * @param int   $added     Recipient just saved, 0 for the empty form.
	 * @return array
	 */
	public static function setup_person( array $programme, $added = 0, $editing = 0 ) {
		$ids   = $programme['id'] ? CardsCo_Programme::recipient_ids( (int) $programme['id'] ) : array();
		$rows  = array();
		$saved = null;

		/*
		 * Whether this occasion has a date that differs per person. Christmas
		 * is the 25th for everybody and a promotion goes out when it goes
		 * out, so for those the form asks for a name and nothing else — and
		 * must not refuse the save for want of a date it never asked for.
		 */
		$type       = CardsCo_Category::primary_type( (string) $programme['category'], (int) $programme['company_id'] );
		$per_person = (bool) $type;

		/*
		 * Some of those happen once rather than every year — a welcome, a
		 * thank-you, an offer. A date that never comes round again is a
		 * date in a particular year, and the save refuses it without one.
		 * The form asked for a day and a month and said in as many words
		 * that the year was not needed, so every save failed and the
		 * person just created was deleted again on the way out.
		 */
		$needs_year = $per_person && 'once' === (string) ( $type['recurrence'] ?? 'annual' );

		foreach ( $ids as $rid ) {
			$person = CardsCo_Recipient::get( $rid );

			if ( ! $person ) {
				continue;
			}

			$occasion = self::person_occasion( (int) $rid, $programme );
			$name     = trim( $person['first_name'] . ' ' . $person['last_name'] );

			/*
			 * The frame shows "Birthday 29 October · Design" — the team
			 * comes after the date. Nothing on this form collects a team, so
			 * for anyone added by hand there is simply no second part, and
			 * the view leaves the separator out rather than printing a
			 * trailing dot.
			 */
			$row = array(
				'id'       => (int) $rid,
				'name'     => $name,
				'initials' => self::initials( $person['first_name'], $person['last_name'] ),
				'birthday' => self::person_date( $occasion, $needs_year ),
				'team'     => (string) $person['job_title'],
			);

			$rows[] = $row;

			if ( $added && (int) $rid === (int) $added ) {
				$saved = $row;
			}
		}

		$months = array();

		for ( $m = 1; $m <= 12; $m++ ) {
			$months[ $m ] = gmdate( 'F', mktime( 0, 0, 0, $m, 1, 2024 ) );
		}

		/*
		 * Editing somebody already on the list rather than adding a new one.
		 * This is where "Add a birthday" beside a name goes: it used to open
		 * a blank form headed "Person 4", which created a second, nameless
		 * copy of the person instead of giving the one on screen a date.
		 */
		$edit = null;

		if ( $editing ) {
			foreach ( $rows as $row ) {
				if ( (int) $row['id'] === (int) $editing ) {
					$edit = $row;
					break;
				}
			}

			if ( ! $edit ) {
				$person = CardsCo_Recipient::get( (int) $editing );

				if ( $person && (int) $person['company_id'] === (int) $programme['company_id'] ) {
					$occasion = self::person_occasion( (int) $editing, $programme );

					$edit = array(
						'id'    => (int) $editing,
						'name'  => trim( $person['first_name'] . ' ' . $person['last_name'] ),
						'first' => (string) $person['first_name'],
						'last'  => (string) $person['last_name'],
						'day'   => $occasion ? (int) $occasion['event_day'] : 0,
						'month' => $occasion ? (int) $occasion['event_month'] : 0,
					);
				}
			}
		}

		if ( $edit && ! isset( $edit['first'] ) ) {
			$person   = CardsCo_Recipient::get( (int) $edit['id'] );
			$occasion = self::person_occasion( (int) $edit['id'], $programme );

			$edit['first'] = $person ? (string) $person['first_name'] : '';
			$edit['last']  = $person ? (string) $person['last_name'] : '';
			$edit['day']   = $occasion ? (int) $occasion['event_day'] : 0;
			$edit['month'] = $occasion ? (int) $occasion['event_month'] : 0;
			$edit['year']  = $occasion ? (int) $occasion['event_year'] : 0;
		}

		return array(
			'saved'      => $saved,
			'edit'       => $edit,
			'rows'       => $rows,
			'total'      => count( $rows ),
			'months'     => $months,
			'per_person' => $per_person,
			'needs_year' => $needs_year,
			'years'      => self::year_choices(),
			/*
			 * How many of the company's own people could still be added.
			 * The confirmation state offered only "Add another person", so
			 * somebody who had added one by hand and then wanted the rest
			 * had no way to a spreadsheet or to their own list from here.
			 */
			'available'  => count( CardsCo_Programme::available_recipients( $programme ) ),
			/*
			 * The same file row the list screen shows, so the upload can
			 * live on this screen too rather than being a link to the one
			 * that has it.
			 */
			'upload'     => self::upload_row( $ids ),
			// "Person 1" on an empty list, "Person 2" once one is on.
			'ordinal'    => count( $rows ) + 1,
			'noun'       => self::date_noun( (string) $programme['category'], (int) $programme['company_id'] ),
		);
	}

	/**
	 * The file row above the upload button.
	 *
	 * Shared, because the upload itself is now on more than one screen
	 * and the two must not drift into saying different things about the
	 * same list.
	 *
	 * @param int[] $ids Recipients already on the programme.
	 * @return array{name:string, meta:string, action:string, report:string}
	 */
	public static function upload_row( array $ids ) {
		return array(
			/*
			 * What is on the occasion, not what was uploaded. Nothing
			 * records a file ever having been sent, so this used to read
			 * "Your list" and offer to "Replace file" the moment anyone
			 * was added — including somebody typed in by hand, with no
			 * file anywhere in it. And an upload does not replace: it
			 * adds the new people and matches the ones already there.
			 */
			'name'   => $ids
				? sprintf(
					/* translators: %s: how many people. */
					_n( '%s person on this occasion', '%s people on this occasion', count( $ids ), 'cardsco' ),
					number_format_i18n( count( $ids ) )
				)
				: __( 'Nobody yet', 'cardsco' ),
			'meta'   => $ids
				? __( 'Upload a CSV to add more', 'cardsco' )
				: __( 'Upload a CSV to get started', 'cardsco' ),
			/* One button, and it says what pressing it does. */
			'action' => __( 'Upload a spreadsheet', 'cardsco' ),
			'report' => self::upload_report(),
		);
	}

	/**
	 * One person's date, as the step 2 list prints it.
	 *
	 * An occasion that happens once does not come round again, so a date
	 * already past schedules nothing at all. That is a reasonable thing
	 * to have typed — a welcome for somebody who joined in March — but
	 * left unsaid it reads as done when no card will ever be written.
	 * The year is shown for those, and a passed one says so.
	 *
	 * @param array|null $occasion   Occasion row, or null when there is none.
	 * @param bool       $needs_year Whether this occasion happens once.
	 * @return string
	 */
	public static function person_date( $occasion, $needs_year ) {
		if ( ! $occasion ) {
			return '';
		}

		$day   = (int) $occasion['event_day'];
		$month = (int) $occasion['event_month'];
		$date  = self::day_month( $day, $month );

		if ( ! $needs_year ) {
			return $date;
		}

		/* Not every caller selects the year; a missing one simply is not one. */
		$year = (int) ( $occasion['event_year'] ?? 0 );

		if ( ! $year ) {
			return $date;
		}

		$date .= ' ' . $year;

		if ( empty( $occasion['next_occurrence'] ) ) {
			$date .= ' — ' . __( 'already passed, no card due', 'cardsco' );
		}

		return $date;
	}

	/**
	 * The years a one-off occasion can be put in.
	 *
	 * Last year as well as this one, because somebody setting up a welcome
	 * programme in January is often entering the people who joined in
	 * December, and a few ahead for anything already arranged.
	 *
	 * @return int[]
	 */
	public static function year_choices() {
		$now = (int) current_time( 'Y' );

		return range( $now - 1, $now + 3 );
	}

	/**
	 * A day and month as the frames write it: "29 October".
	 *
	 * @param int $day   Day.
	 * @param int $month Month.
	 * @return string
	 */
	protected static function day_month( $day, $month ) {
		if ( ! $day || ! $month ) {
			return '';
		}

		// A leap year, so 29 February formats rather than rolling over.
		return gmdate( 'j F', (int) mktime( 0, 0, 0, $month, $day, 2024 ) );
	}

	/**
	 * Initials for the avatar on a person row.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return string
	 */
	protected static function initials( $first, $last ) {
		$first = trim( (string) $first );
		$last  = trim( (string) $last );

		$out = '';

		if ( '' !== $first ) {
			$out .= mb_strtoupper( mb_substr( $first, 0, 1 ) );
		}

		if ( '' !== $last ) {
			$out .= mb_strtoupper( mb_substr( $last, 0, 1 ) );
		}

		return $out;
	}

	/**
	 * A person's occasion within this programme's category.
	 *
	 * @param int   $recipient_id Recipient ID.
	 * @param array $programme    Programme row.
	 * @return array|null
	 */
	protected static function person_occasion( $recipient_id, array $programme ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT occasion_type_id, event_day, event_month, event_year, next_occurrence
				 FROM ' . cardsco_table( 'occasions' ) . '
				 WHERE recipient_id = %d AND is_active = 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$recipient_id
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			/*
			 * A list, not a slug: one occasion type can belong to several
			 * collections. This used to be compared with === against the
			 * programme's category, which an array never equals, so every
			 * person came back without a date — step 2's table showed
			 * "Missing" against people who had a birthday on file, and the
			 * with/without counts beneath it were wrong.
			 */
			$cats = CardsCo_Category::for_type( (int) $row['occasion_type_id'], (int) $programme['company_id'] );

			if ( in_array( (string) $programme['category'], (array) $cats, true ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * What the frame calls the cards themselves.
	 *
	 * The notes read "Birthday cards are £3.60 each", not "Employee
	 * Birthdays cards" — the noun is the occasion type, not the category it
	 * is filed under.
	 *
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company ID.
	 * @return string
	 */
	public static function card_noun( $slug, $company_id ) {
		/*
		 * The first occasion's word only when it is the only one. Customer
		 * Milestones is a welcome AND a customer anniversary, and naming
		 * the first made every screen read as though the occasion were
		 * welcomes alone — the card picker offered "welcome cards" to
		 * somebody setting up client anniversaries.
		 */
		$types = CardsCo_Category::types( $slug, $company_id );

		if ( 1 === count( $types ) && ! empty( $types[0]['label'] ) ) {
			return $types[0]['label'];
		}

		if ( count( $types ) > 1 ) {
			$cat = CardsCo_Category::get( $slug );

			if ( $cat && ! empty( $cat['noun'] ) ) {
				return $cat['noun'];
			}

			/* Two occasions, named as a pair: "welcome and anniversary". */
			$labels = array_values( array_filter( wp_list_pluck( $types, 'label' ) ) );

			if ( 2 === count( $labels ) ) {
				return sprintf(
					/* translators: 1 and 2: two occasions, e.g. "welcome" and "customer anniversary". */
					__( '%1$s and %2$s', 'cardsco' ),
					$labels[0],
					$labels[1]
				);
			}

			return $cat ? $cat['label'] : '';
		}

		$type = CardsCo_Category::primary_type( $slug, $company_id );

		if ( $type && ! empty( $type['label'] ) ) {
			return $type['label'];
		}

		$cat = CardsCo_Category::get( $slug );

		return $cat ? $cat['label'] : '';
	}

	/**
	 * The same word, where a date is meant rather than the occasion.
	 *
	 * "0 missing a birthday" reads perfectly well, because a birthday is a
	 * date. "0 missing a leaving or retirement" does not: the occasion noun
	 * names an event there, and the sentence wants the date of it. Categories
	 * that need a different word for those slots give one; the rest fall back
	 * to the occasion noun, so nothing else on the site changes.
	 *
	 * @param string $slug       Category slug.
	 * @param int    $company_id Company ID.
	 * @return string
	 */
	public static function date_noun( $slug, $company_id ) {
		$cat = CardsCo_Category::get( $slug );

		if ( $cat && ! empty( $cat['date_noun'] ) ) {
			return $cat['date_noun'];
		}

		return self::card_noun( $slug, $company_id );
	}
}
