<?php
/**
 * Activation.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		CardsCo_Schema::maybe_install( true );
		CardsCo_Roles::install();
		CardsCo_Settings::seed();

		self::seed_occasion_types();
		CardsCo_Category::backfill();

		/*
		 * The client's ready-written messages, which are what the card
		 * writer offers as starting points.
		 *
		 * These used to arrive only if somebody found a notice on the
		 * WordPress Messages screen and pressed the button on it. Nothing
		 * anywhere told them to, so on a fresh install the writer opened
		 * on "start from one of ours" above an empty space where the
		 * starting points should have been. seed() skips anything already
		 * there, so running it here costs nothing on an install that has
		 * them; the notice stays for a library somebody has emptied.
		 */
		if ( class_exists( 'CardsCo_Message' ) ) {
			CardsCo_Message::seed();
		}

		self::schedule_events();

		if ( ! get_option( 'cardsco_installed_at' ) ) {
			add_option( 'cardsco_installed_at', cardsco_now(), '', false );
		}

		update_option( 'cardsco_version', CARDSCO_VERSION, false );

		flush_rewrite_rules();
	}

	/**
	 * Register the plugin's scheduled events.
	 *
	 * The daily scan is what queues cards for upcoming occasions. On the live
	 * site WP-Cron must be disabled in wp-config.php and a real server cron set
	 * to hit wp-cron.php, otherwise the scan only runs when someone happens to
	 * visit the site.
	 *
	 * @return void
	 */
	public static function schedule_events() {
		if ( ! wp_next_scheduled( 'cardsco_daily_scan' ) ) {
			// 02:00 site time, so the queue is ready before the writing day starts.
			$timestamp = strtotime( 'tomorrow 02:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			wp_schedule_event( $timestamp, 'daily', 'cardsco_daily_scan' );
		}

		if ( ! wp_next_scheduled( 'cardsco_hourly_maintenance' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'cardsco_hourly_maintenance' );
		}
	}

	/**
	 * The occasions the approved designs call for, and only those.
	 *
	 * The design names its occasions in the card catalogue's filter row,
	 * and the counts there settle that the list is the whole list:
	 * Birthday 8, Work anniversary 5, Thank you 9, Christmas 6, Eid 3,
	 * Diwali 2, New starter 3, Congratulations 4, New Year 2 — which is
	 * the "All 42" the same row shows. Customer Anniversary is the tenth,
	 * named in the Customer Milestones blurb on the setup screen ("New
	 * customer welcomes and client anniversaries") rather than as a
	 * filter of its own, and Promotions & Offers the eleventh, which has
	 * a card on that screen and nine messages in the client's own
	 * "Prompts for the occasion".
	 *
	 * An earlier seed carried thirteen more that read like reasonable
	 * additions — Easter, Halloween, Valentine's Day, Get Well Soon and
	 * so on. No design file or client document mentions any of them.
	 * They are retired by
	 * CardsCo_Occasion_Type::retire_unspecified(), which is careful about
	 * the ones a company may already be using.
	 *
	 * Moveable dates (Eid, Diwali) are marked requires_annual_date rather
	 * than given a fixed month and day, so staff confirm the date each
	 * year instead of the plugin calculating it wrongly.
	 *
	 * Columns: slug, label, mode, recurrence, month, day, needs a date
	 * each year, who it applies to.
	 *
	 * @return array[]
	 */
	public static function designed_types() {
		return array(
			// Dated: the date belongs to the recipient.
			array( 'birthday', 'Birthday', 'dated', 'annual', null, null, 0, 'both' ),
			array( 'work-anniversary', 'Work Anniversary', 'dated', 'annual', null, null, 0, 'employee' ),
			array( 'customer-anniversary', 'Customer Anniversary', 'dated', 'annual', null, null, 0, 'customer' ),
			array( 'welcome', 'Welcome', 'dated', 'once', null, null, 0, 'customer' ),
			array( 'thank-you', 'Thank You', 'dated', 'once', null, null, 0, 'both' ),
			array( 'congratulations', 'Congratulations', 'dated', 'once', null, null, 0, 'both' ),

			/*
			 * Offers and follow-ups. Not in the catalogue filter, but the
			 * setup screen has a Promotions & Offers card and the client's
			 * own "Prompts for the occasion" supplies nine messages for it,
			 * every one of them an offer to a customer ("Offer — refer a
			 * friend"). It was seeded as an employee occasion, which read
			 * as a staff promotion and left those nine messages with no
			 * audience that could ever use them.
			 */
			array( 'promotion', 'Promotions & Offers', 'dated', 'once', null, null, 0, 'customer' ),

			// Seasonal: one date for everyone.
			array( 'new-year', 'New Year', 'seasonal', 'annual', 1, 1, 0, 'both' ),
			array( 'eid-al-fitr', 'Eid al-Fitr', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'diwali', 'Diwali', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'christmas', 'Christmas', 'seasonal', 'annual', 12, 25, 0, 'both' ),
		);
	}

	/**
	 * Seed the shared occasion library.
	 *
	 * These are global types (company 0) available to every company. Companies
	 * can still add their own, which is what the client's "other seasonal or
	 * corporate occasions" wording requires — that is the route for anything
	 * outside the designed list, rather than shipping it to everybody.
	 *
	 * @return void
	 */
	public static function seed_occasion_types() {
		global $wpdb;

		$table = cardsco_table( 'occasion_types' );
		$lead  = (int) CardsCo_Settings::get( 'default_lead_days' );

		$types = self::designed_types();

		$sort = 0;

		foreach ( $types as $type ) {
			list( $slug, $label, $mode, $recurrence, $month, $day, $needs_date, $applies_to ) = $type;

			$sort += 10;

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE company_id = 0 AND slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL
					$slug
				)
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'company_id'           => 0,
					'slug'                 => $slug,
					'label'                => $label,
					'mode'                 => $mode,
					'recurrence'           => $recurrence,
					'fixed_month'          => $month,
					'fixed_day'            => $day,
					'requires_annual_date' => $needs_date,
					'lead_days'            => $lead,
					'applies_to'           => $applies_to,
					'is_active'            => 1,
					'sort_order'           => $sort,
					'created_at'           => cardsco_now(),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s' )
			);
		}
	}
}
