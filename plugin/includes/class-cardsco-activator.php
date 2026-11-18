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
	 * Seed the shared occasion library.
	 *
	 * These are global types (company 0) available to every company. Companies
	 * can add their own, which is what the client's "other seasonal or
	 * corporate occasions" wording requires.
	 *
	 * Moveable dates (Easter, Eid, Diwali, Mothering Sunday) are marked
	 * requires_annual_date rather than given a fixed month and day, so staff
	 * confirm the date each year instead of the plugin calculating it wrongly.
	 *
	 * @return void
	 */
	public static function seed_occasion_types() {
		global $wpdb;

		$table = cardsco_table( 'occasion_types' );
		$lead  = (int) CardsCo_Settings::get( 'default_lead_days' );

		$types = array(
			// Dated: the date belongs to the recipient.
			array( 'birthday', 'Birthday', 'dated', 'annual', null, null, 0, 'both' ),
			array( 'work-anniversary', 'Work Anniversary', 'dated', 'annual', null, null, 0, 'employee' ),
			array( 'customer-anniversary', 'Customer Anniversary', 'dated', 'annual', null, null, 0, 'customer' ),
			array( 'welcome', 'Welcome', 'dated', 'once', null, null, 0, 'customer' ),
			array( 'thank-you', 'Thank You', 'dated', 'once', null, null, 0, 'both' ),
			array( 'congratulations', 'Congratulations', 'dated', 'once', null, null, 0, 'both' ),
			array( 'promotion', 'Promotion', 'dated', 'once', null, null, 0, 'employee' ),
			array( 'new-baby', 'New Baby', 'dated', 'once', null, null, 0, 'both' ),
			array( 'wedding', 'Wedding', 'dated', 'once', null, null, 0, 'both' ),
			array( 'new-home', 'New Home', 'dated', 'once', null, null, 0, 'both' ),
			array( 'retirement', 'Retirement', 'dated', 'once', null, null, 0, 'employee' ),
			array( 'get-well', 'Get Well Soon', 'dated', 'once', null, null, 0, 'both' ),
			array( 'condolence', 'With Sympathy', 'dated', 'once', null, null, 0, 'both' ),

			// Seasonal: one date for everyone.
			array( 'new-year', 'New Year', 'seasonal', 'annual', 1, 1, 0, 'both' ),
			array( 'valentines-day', "Valentine's Day", 'seasonal', 'annual', 2, 14, 0, 'both' ),
			array( 'international-womens-day', "International Women's Day", 'seasonal', 'annual', 3, 8, 0, 'both' ),
			array( 'mothering-sunday', 'Mothering Sunday', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'easter', 'Easter', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'eid-al-fitr', 'Eid al-Fitr', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'fathers-day', "Father's Day", 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'diwali', 'Diwali', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'halloween', 'Halloween', 'seasonal', 'annual', 10, 31, 0, 'both' ),
			array( 'thanksgiving', 'Thanksgiving', 'seasonal', 'annual', null, null, 1, 'both' ),
			array( 'christmas', 'Christmas', 'seasonal', 'annual', 12, 25, 0, 'both' ),
		);

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
