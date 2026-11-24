<?php
/**
 * Occasion categories.
 *
 * The six things a company actually chooses from when setting up an occasion.
 * Each one gathers occasion types underneath it: picking "Employee Birthdays"
 * is one decision for the person setting it up, while the engine underneath
 * still schedules against each individual's own birthday.
 *
 * Keeping the two apart means the wording on screen can change without
 * touching the scheduling, and a category can gain a second occasion type
 * later without a migration.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Category {

	/**
	 * Every category, in the order the setup screen shows them.
	 *
	 * @return array<string, array>
	 */
	public static function all() {
		return array(
			'christmas-seasonal'  => array(
				'label'       => __( 'Christmas & Seasonal', 'cardsco' ),
				'blurb'       => __( 'Stand out from the inbox when it matters most.', 'cardsco' ),
				'format'      => __( 'Postcard 6 × 4 in', 'cardsco' ),
				/*
				 * The four the design's catalogue filter names. Easter,
				 * Halloween, Thanksgiving, Valentine's Day, Mothering
				 * Sunday, Father's Day and International Women's Day were
				 * here too and are in no design file; a company that wants
				 * one adds it as its own occasion.
				 */
				'types'       => array(
					'christmas',
					'new-year',
					'eid-al-fitr',
					'diwali',
				),
				'audience'    => 'both',
				'coming_soon' => false,
			),
			'employee-birthdays'  => array(
				'label'       => __( 'Employee Birthdays', 'cardsco' ),
				'blurb'       => __( 'Upload your team once. Cards go out on the day.', 'cardsco' ),
				'format'      => __( 'Folded card', 'cardsco' ),
				'types'       => array( 'birthday' ),
				'audience'    => 'employee',
				'coming_soon' => false,
			),
			'thank-you'           => array(
				'label'       => __( 'Thank-You', 'cardsco' ),
				'blurb'       => __( 'Say thanks after a purchase or a referral, and invite them back.', 'cardsco' ),
				'format'      => __( 'Postcard 6 × 4 in', 'cardsco' ),
				'types'       => array( 'thank-you' ),
				'audience'    => 'customer',
				'coming_soon' => false,
			),
			'customer-milestones' => array(
				'label'       => __( 'Customer Milestones', 'cardsco' ),
				/*
				 * What one of these is called in a sentence. A category
				 * holding several occasions cannot borrow the first one's
				 * word: this one is a welcome and a client anniversary,
				 * and naming it "welcome" everywhere made the whole
				 * occasion read as though anniversaries were not part of it.
				 */
				'noun'        => __( 'customer milestone', 'cardsco' ),
				'blurb'       => __( 'New customer welcomes and client anniversaries.', 'cardsco' ),
				'format'      => __( 'Postcard 6 × 4 in', 'cardsco' ),
				'types'       => array( 'welcome', 'customer-anniversary' ),
				'audience'    => 'customer',
				'coming_soon' => false,
			),
			'work-anniversaries'  => array(
				'label'       => __( 'Work Anniversaries', 'cardsco' ),
				'blurb'       => __( 'Mark every year someone has been with you.', 'cardsco' ),
				'format'      => __( 'Folded card', 'cardsco' ),
				'types'       => array( 'work-anniversary' ),
				'audience'    => 'employee',
				// Marked "coming soon" in the approved designs, so it is shown
				// but cannot be set up yet.
				'coming_soon' => true,
			),
			'leaving-retirement'  => array(
				'label'       => __( 'Leaving & Retirement', 'cardsco' ),
				/*
				 * Two occasions in one category — somebody moving on and
				 * somebody finishing for good — so it is named by its own
				 * word rather than by whichever happens to come first.
				 */
				'noun'        => __( 'leaving or retirement', 'cardsco' ),
				/*
				 * The date, not the event. "0 missing a leaving or
				 * retirement" is not English; "0 missing a leaving date"
				 * is, and it covers a retirement too — somebody retiring
				 * still has a last day. See CardsCo_Dashboard::date_noun().
				 */
				'date_noun'   => __( 'leaving date', 'cardsco' ),
				'blurb'       => __( 'Send somebody off properly, whether they are moving on or finishing for good.', 'cardsco' ),
				'format'      => __( 'Folded card', 'cardsco' ),
				'types'       => array( 'leaving', 'retirement' ),
				'audience'    => 'employee',
				'coming_soon' => false,
			),
			'promotions-offers'   => array(
				'label'       => __( 'Promotions & Offers', 'cardsco' ),
				'blurb'       => __( 'Prospect follow-ups, product launches and offer codes.', 'cardsco' ),
				'format'      => __( 'Postcard 6 × 4 in', 'cardsco' ),
				// Driven by campaigns rather than by a date each person holds.
				'types'       => array(),
				'audience'    => 'customer',
				'coming_soon' => false,
			),
		);
	}

	/**
	 * One category.
	 *
	 * @param string $slug Category slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all  = self::all();
		$slug = sanitize_key( $slug );

		return $all[ $slug ] ?? null;
	}

	/**
	 * Whether a category can be set up yet.
	 *
	 * @param string $slug Category slug.
	 * @return bool
	 */
	public static function is_available( $slug ) {
		$category = self::get( $slug );

		return $category && empty( $category['coming_soon'] );
	}

	/**
	 * The occasion types a category covers, as rows.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return array[]
	 */
	public static function types( $slug, $company_id = 0 ) {
		$category = self::get( $slug );

		if ( ! $category ) {
			return array();
		}

		$rows = array();

		foreach ( $category['types'] as $type_slug ) {
			$type = CardsCo_Occasion_Type::get_by_slug( $type_slug, $company_id );

			if ( $type ) {
				$rows[] = $type;
			}
		}

		return $rows;
	}

	/**
	 * Which categories cover a given occasion type.
	 *
	 * The reverse of types(): used to work out whether a person's birthday is
	 * already being handled by a programme before the general scan touches it.
	 *
	 * @param int $type_id Occasion type ID.
	 * @param int $company_id Company ID.
	 * @return string[] Category slugs.
	 */
	public static function for_type( $type_id, $company_id = 0 ) {
		$type = CardsCo_Occasion_Type::get( absint( $type_id ) );

		if ( ! $type ) {
			return array();
		}

		$slugs = array();

		foreach ( self::all() as $slug => $category ) {
			if ( in_array( $type['slug'], $category['types'], true ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * The occasion type a programme in this category schedules against.
	 *
	 * Only meaningful for categories built on a date each person holds.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return array|null
	 */
	public static function primary_type( $slug, $company_id = 0 ) {
		foreach ( self::types( $slug, $company_id ) as $type ) {
			if ( 'dated' === $type['mode'] ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Whether a category runs off dates people hold, rather than campaigns.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return bool
	 */
	public static function is_dated( $slug, $company_id = 0 ) {
		return (bool) self::primary_type( $slug, $company_id );
	}

	/**
	 * How many published designs a category has.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return int
	 */
	public static function design_count( $slug, $company_id = 0 ) {
		return count( self::cards( $slug, $company_id ) );
	}

	/**
	 * The cards offered for a category.
	 *
	 * A card ticked for any occasion the category covers belongs to it, and a
	 * card with no occasions ticked belongs to all of them.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return WP_Post[]
	 */
	public static function cards( $slug, $company_id = 0 ) {
		$slug = sanitize_key( $slug );

		if ( ! self::get( $slug ) ) {
			return array();
		}

		return array_values(
			array_filter(
				CardsCo_Card::for_occasion( 0, $company_id ),
				static function ( $post ) use ( $slug ) {
					/*
					 * The card's own collection decides, whether it was chosen
					 * on the card or worked out from its occasions.
					 *
					 * This used to match on occasion ticks and treat a card
					 * with none as suiting everything, which meant an untagged
					 * card appeared in all six collections at once. A card
					 * that has not been placed now appears in none of them,
					 * and is flagged on the cards list instead.
					 */
					return CardsCo_Card::category( $post->ID ) === $slug;
				}
			)
		);
	}

	/**
	 * The messages offered for a category, on the same rule as cards.
	 *
	 * @param string $slug Category slug.
	 * @param int    $company_id Company ID.
	 * @return WP_Post[]
	 */
	public static function messages( $slug, $company_id = 0 ) {
		$type_ids = wp_list_pluck( self::types( $slug, $company_id ), 'id' );
		$type_ids = array_map( 'intval', (array) $type_ids );

		return array_values(
			array_filter(
				CardsCo_Message::for_occasion( 0, $company_id ),
				static function ( $post ) use ( $type_ids ) {
					$set = array_map( 'intval', (array) get_post_meta( $post->ID, CardsCo_Message::OCCASION, false ) );

					return ! $set || array_intersect( $set, $type_ids );
				}
			)
		);
	}

	/**
	 * Write the category onto each occasion type row.
	 *
	 * Run on activation and on upgrade, so the grouping is queryable rather
	 * than only living in this file.
	 *
	 * @return int Rows touched.
	 */
	public static function backfill() {
		global $wpdb;

		$table   = cardsco_table( 'occasion_types' );
		$touched = 0;

		foreach ( self::all() as $slug => $category ) {
			foreach ( $category['types'] as $type_slug ) {
				$touched += (int) $wpdb->update(
					$table,
					array( 'category' => $slug ),
					array( 'slug' => $type_slug ),
					array( '%s' ),
					array( '%s' )
				);
			}
		}

		return $touched;
	}
}
