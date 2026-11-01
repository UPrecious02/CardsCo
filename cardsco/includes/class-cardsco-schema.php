<?php
/**
 * Database schema.
 *
 * Owns every custom table, the CREATE statements and the migration runner.
 * Bump CardsCo_Schema::DB_VERSION whenever a statement below changes and the
 * installer will run dbDelta again on the next admin request.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Schema {

	/**
	 * Schema version. Bump on any change to the statements in this class.
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option that stores the installed schema version.
	 */
	const VERSION_OPTION = 'cardsco_db_version';

	/**
	 * Logical table names, without the WordPress prefix.
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'companies',
		'company_users',
		'recipients',
		'occasion_types',
		'occasions',
		'segments',
		'recipient_segments',
		'campaigns',
		'campaign_recipients',
		'card_queue',
		'programmes',
		'programme_recipients',
		'boxes',
		'settings',
		'import_batches',
		'activity_log',
	);

	/**
	 * Fully qualified table name.
	 *
	 * @param string $name Logical name, e.g. 'recipients'.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'cardsco_' . $name;
	}

	/**
	 * Run the schema if it is missing or out of date.
	 *
	 * Safe to call on every request; it returns immediately when current.
	 *
	 * @param bool $force Run regardless of the stored version.
	 * @return void
	 */
	public static function maybe_install( $force = false ) {
		if ( ! $force && get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Create or update every table.
	 *
	 * @return void
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Drop every table. Only ever called from uninstall with an explicit opt-in.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array_reverse( self::TABLES ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Every CREATE TABLE statement.
	 *
	 * dbDelta is strict: one column per line, two spaces before the opening
	 * bracket of PRIMARY KEY, KEY rather than INDEX, and every key named.
	 *
	 * @return string[]
	 */
	public static function statements() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$sql     = array();

		/*
		 * Companies.
		 *
		 * A company is the account that buys cards. The owner is a WP user;
		 * company_users allows more seats later without a migration.
		 */
		$table = self::table( 'companies' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			contact_name varchar(191) NOT NULL DEFAULT '',
			contact_email varchar(191) NOT NULL DEFAULT '',
			contact_phone varchar(60) NOT NULL DEFAULT '',
			billing_name varchar(191) NOT NULL DEFAULT '',
			billing_email varchar(191) NOT NULL DEFAULT '',
			billing_address text NULL,
			billing_reference varchar(191) NOT NULL DEFAULT '',
			logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY owner_user_id (owner_user_id),
			KEY status (status)
		) {$charset};";

		/*
		 * Company users. Links WP users to a company with a role within it.
		 */
		$table = self::table( 'company_users' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_role varchar(40) NOT NULL DEFAULT 'member',
			status varchar(20) NOT NULL DEFAULT 'active',
			invited_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY company_user (company_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		/*
		 * Recipients.
		 *
		 * One table for customers and employees, separated by `type`.
		 * `email` is for order updates to the buyer, never used to email the
		 * recipient. `address_hash` is a normalised hash used for duplicate
		 * detection on same person plus same address.
		 * `last_order_date` is a hedge: it costs nothing now and makes the
		 * lapsed-customer filter work without a migration if the client
		 * confirms companies will supply order dates.
		 */
		$table = self::table( 'recipients' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT 'customer',
			first_name varchar(191) NOT NULL DEFAULT '',
			last_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			recipient_company varchar(191) NOT NULL DEFAULT '',
			job_title varchar(191) NOT NULL DEFAULT '',
			address text NULL,
			postcode varchar(20) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT 'GB',
			address_hash char(32) NOT NULL DEFAULT '',
			address_status varchar(20) NOT NULL DEFAULT 'missing',
			relationship_since date NULL DEFAULT NULL,
			last_order_date date NULL DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'manual',
			import_batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			notes text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY company_type_status (company_id,type,status),
			KEY company_address_hash (company_id,address_hash),
			KEY company_email (company_id,email),
			KEY address_status (address_status),
			KEY last_order_date (last_order_date),
			KEY import_batch_id (import_batch_id)
		) {$charset};";

		/*
		 * Occasion types.
		 *
		 * company_id 0 means a global type available to every company.
		 *
		 * mode:
		 *   dated    - the date belongs to the recipient (birthday, anniversary)
		 *   seasonal - one date for everyone (Christmas, Valentine's)
		 *
		 * Moveable feasts (Easter, Eid, Diwali) are seasonal with no fixed
		 * month and day and requires_annual_date set, so admin confirms the
		 * date each year rather than the plugin guessing it.
		 */
		$table = self::table( 'occasion_types' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			slug varchar(100) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			category varchar(60) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'dated',
			recurrence varchar(20) NOT NULL DEFAULT 'annual',
			fixed_month tinyint(2) unsigned NULL DEFAULT NULL,
			fixed_day tinyint(2) unsigned NULL DEFAULT NULL,
			requires_annual_date tinyint(1) NOT NULL DEFAULT 0,
			lead_days smallint(4) unsigned NOT NULL DEFAULT 7,
			applies_to varchar(20) NOT NULL DEFAULT 'both',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY company_slug (company_id,slug),
			KEY mode_active (mode,is_active),
			KEY category (category)
		) {$charset};";

		/*
		 * Occasions. One row per recipient per occasion.
		 *
		 * The month and day are the source of truth; the year is optional
		 * because companies often know a birthday without the year.
		 * `next_occurrence` is a cached date the scheduler maintains, so the
		 * daily scan is a single indexed range query instead of date
		 * arithmetic across every row.
		 */
		$table = self::table( 'occasions' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occasion_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_month tinyint(2) unsigned NULL DEFAULT NULL,
			event_day tinyint(2) unsigned NULL DEFAULT NULL,
			event_year smallint(4) unsigned NULL DEFAULT NULL,
			recurrence varchar(20) NOT NULL DEFAULT 'annual',
			next_occurrence date NULL DEFAULT NULL,
			last_queued_for date NULL DEFAULT NULL,
			lead_days smallint(4) unsigned NULL DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			notes varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY recipient_type (recipient_id,occasion_type_id),
			KEY scan (is_active,next_occurrence),
			KEY company_type (company_id,occasion_type_id),
			KEY recipient_id (recipient_id)
		) {$charset};";

		/*
		 * Segments. Company-created audience groups.
		 * `rules` is reserved for rule-based segments; null means manual.
		 */
		$table = self::table( 'segments' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			description varchar(255) NOT NULL DEFAULT '',
			applies_to varchar(20) NOT NULL DEFAULT 'both',
			is_auto tinyint(1) NOT NULL DEFAULT 0,
			rules longtext NULL,
			member_count int(11) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY company_slug (company_id,slug),
			KEY company_id (company_id)
		) {$charset};";

		/*
		 * Segment membership.
		 */
		$table = self::table( 'recipient_segments' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
			segment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY recipient_segment (recipient_id,segment_id),
			KEY segment_id (segment_id),
			KEY company_id (company_id)
		) {$charset};";

		/*
		 * Campaigns. A one-off send to a segment, built in the seven-step
		 * builder: occasion, audience, card, message, offer, review, schedule.
		 *
		 * `audience` holds the saved builder selection (included segments,
		 * exclusions, filters) as JSON. The resolved people live in
		 * campaign_recipients so the audience can be re-resolved without
		 * losing the record of who was actually sent to.
		 *
		 * Pricing and billing columns are present but unused until the client
		 * confirms invoice versus card billing.
		 */
		$table = self::table( 'campaigns' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			occasion_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			card_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_body text NULL,
			offer_enabled tinyint(1) NOT NULL DEFAULT 0,
			offer_code varchar(100) NOT NULL DEFAULT '',
			offer_description varchar(255) NOT NULL DEFAULT '',
			offer_expires date NULL DEFAULT NULL,
			audience longtext NULL,
			recipient_count int(11) unsigned NOT NULL DEFAULT 0,
			excluded_count int(11) unsigned NOT NULL DEFAULT 0,
			send_date date NULL DEFAULT NULL,
			lock_date date NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			builder_step tinyint(2) unsigned NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NULL DEFAULT NULL,
			total_amount decimal(12,2) NULL DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			billing_status varchar(20) NOT NULL DEFAULT 'none',
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY company_status (company_id,status),
			KEY send_date (send_date),
			KEY status_lock (status,lock_date)
		) {$charset};";

		/*
		 * Campaign recipients. The resolved audience, with the reason anyone
		 * was held back so the review step can explain itself.
		 */
		$table = self::table( 'campaign_recipients' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occasion_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resolved_message text NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			exclusion_reason varchar(100) NOT NULL DEFAULT '',
			queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_recipient (campaign_id,recipient_id),
			KEY campaign_status (campaign_id,status),
			KEY recipient_id (recipient_id)
		) {$charset};";

		/*
		 * Card queue. One row per physical card, whatever created it.
		 *
		 * Address, name, card title and message are snapshotted at queue time
		 * so a later edit to a recipient cannot change a card that is already
		 * with the writers.
		 *
		 * The unique key on (occasion_id, event_date) is what stops the daily
		 * cron queueing the same birthday twice.
		 */
		$table = self::table( 'card_queue' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(20) NOT NULL DEFAULT 'occasion',
			occasion_id bigint(20) unsigned NULL DEFAULT NULL,
			campaign_id bigint(20) unsigned NULL DEFAULT NULL,
			programme_id bigint(20) unsigned NULL DEFAULT NULL,
			box_id bigint(20) unsigned NULL DEFAULT NULL,
			order_id bigint(20) unsigned NULL DEFAULT NULL,
			recipient_id bigint(20) unsigned NULL DEFAULT NULL,
			recipient_name varchar(191) NOT NULL DEFAULT '',
			recipient_company varchar(191) NOT NULL DEFAULT '',
			address_snapshot text NULL,
			postcode varchar(20) NOT NULL DEFAULT '',
			country char(2) NOT NULL DEFAULT 'GB',
			card_id bigint(20) unsigned NOT NULL DEFAULT 0,
			card_title varchar(191) NOT NULL DEFAULT '',
			occasion_label varchar(191) NOT NULL DEFAULT '',
			message_final text NULL,
			offer_code varchar(100) NOT NULL DEFAULT '',
			event_date date NULL DEFAULT NULL,
			write_by date NULL DEFAULT NULL,
			post_by date NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			hold_reason varchar(100) NOT NULL DEFAULT '',
			assigned_to bigint(20) unsigned NOT NULL DEFAULT 0,
			tracking_ref varchar(100) NOT NULL DEFAULT '',
			posted_at datetime NULL DEFAULT NULL,
			delivered_at datetime NULL DEFAULT NULL,
			unit_price decimal(10,2) NULL DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY occasion_event (occasion_id,event_date),
			KEY status_write_by (status,write_by),
			KEY company_status (company_id,status),
			KEY campaign_id (campaign_id),
			KEY programme_id (programme_id),
			KEY box_id (box_id),
			KEY recipient_id (recipient_id),
			KEY order_id (order_id)
		) {$charset};";

		/*
		 * Programmes.
		 *
		 * A standing arrangement a company sets up once: this occasion, these
		 * people, this card, this wording, shipped this way, arriving on this
		 * rhythm. Built through the seven step setup and then left running.
		 *
		 * Distinct from a campaign, which is a single send decided on the day.
		 */
		$table = self::table( 'programmes' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			category varchar(60) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			card_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_override text NULL,
			handwriting varchar(60) NOT NULL DEFAULT '',
			ink varchar(40) NOT NULL DEFAULT '',
			signature varchar(191) NOT NULL DEFAULT '',
			shipping_mode varchar(20) NOT NULL DEFAULT 'company',
			ship_address text NULL,
			ship_postcode varchar(20) NOT NULL DEFAULT '',
			ship_country char(2) NOT NULL DEFAULT 'GB',
			timing_mode varchar(20) NOT NULL DEFAULT 'monthly_box',
			box_arrive_day tinyint(2) unsigned NOT NULL DEFAULT 25,
			days_before smallint(4) unsigned NOT NULL DEFAULT 3,
			weekend_rule tinyint(1) NOT NULL DEFAULT 1,
			notify_before_box tinyint(1) NOT NULL DEFAULT 1,
			starts_on date NULL DEFAULT NULL,
			recurrence varchar(20) NOT NULL DEFAULT 'annual',
			status varchar(20) NOT NULL DEFAULT 'draft',
			setup_step tinyint(2) unsigned NOT NULL DEFAULT 1,
			unit_price decimal(10,2) NULL DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY company_status (company_id,status),
			KEY category (category)
		) {$charset};";

		/*
		 * Who is in a programme. A person can be in several.
		 */
		$table = self::table( 'programme_recipients' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			programme_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY programme_recipient (programme_id,recipient_id),
			KEY recipient_id (recipient_id),
			KEY company_id (company_id)
		) {$charset};";

		/*
		 * Boxes.
		 *
		 * A month's cards travelling together to one address, which is the
		 * default way a programme ships. Cards for the coming month are
		 * written, packed and sent so the box arrives before the month starts.
		 */
		$table = self::table( 'boxes' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			programme_id bigint(20) unsigned NOT NULL DEFAULT 0,
			period char(7) NOT NULL DEFAULT '',
			arrive_by date NULL DEFAULT NULL,
			write_by date NULL DEFAULT NULL,
			card_count int(11) unsigned NOT NULL DEFAULT 0,
			ship_address text NULL,
			ship_postcode varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'planned',
			amount decimal(12,2) NULL DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT 'GBP',
			payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
			tracking_ref varchar(100) NOT NULL DEFAULT '',
			notified_at datetime NULL DEFAULT NULL,
			posted_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY programme_period (programme_id,period),
			KEY company_status (company_id,status),
			KEY arrive_by (arrive_by)
		) {$charset};";

		/*
		 * Settings. Per company, with company_id 0 for platform defaults.
		 */
		$table = self::table( 'settings' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			setting_key varchar(100) NOT NULL DEFAULT '',
			setting_value longtext NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY company_key (company_id,setting_key)
		) {$charset};";

		/*
		 * Import batches. Backs the four-step import: upload, match columns,
		 * check what we found, set their occasions.
		 */
		$table = self::table( 'import_batches' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			filename varchar(255) NOT NULL DEFAULT '',
			file_path varchar(255) NOT NULL DEFAULT '',
			recipient_type varchar(20) NOT NULL DEFAULT 'customer',
			total_rows int(11) unsigned NOT NULL DEFAULT 0,
			created_count int(11) unsigned NOT NULL DEFAULT 0,
			updated_count int(11) unsigned NOT NULL DEFAULT 0,
			skipped_count int(11) unsigned NOT NULL DEFAULT 0,
			error_count int(11) unsigned NOT NULL DEFAULT 0,
			column_map longtext NULL,
			report longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'uploaded',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY company_status (company_id,status)
		) {$charset};";

		/*
		 * Activity log. Who changed what, for support and for the dashboard
		 * activity feed.
		 */
		$table = self::table( 'activity_log' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(60) NOT NULL DEFAULT '',
			summary varchar(255) NOT NULL DEFAULT '',
			detail longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY company_created (company_id,created_at),
			KEY object (object_type,object_id)
		) {$charset};";

		return $sql;
	}
}
