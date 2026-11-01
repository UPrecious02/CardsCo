<?php
/**
 * Plugin loader.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Plugin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Post types register on every request: the front-end dashboard and
		// the campaign builder both read them.
		CardsCo_Card::init();
		CardsCo_Message::init();

		if ( is_admin() ) {
			require_once CARDSCO_PATH . 'includes/admin/class-cardsco-admin.php';
			require_once CARDSCO_PATH . 'includes/admin/class-cardsco-wizard.php';
			require_once CARDSCO_PATH . 'includes/admin/class-cardsco-fulfilment.php';

			$admin = new CardsCo_Admin();
			$admin->init();

			$wizard = new CardsCo_Wizard();
			$wizard->init();

			$fulfilment = new CardsCo_Fulfilment();
			$fulfilment->init();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'check_schema' ) );
		add_action( 'admin_init', array( 'CardsCo_Roles', 'block_admin_access' ) );
		add_filter( 'show_admin_bar', array( 'CardsCo_Roles', 'filter_admin_bar' ) );
		add_action( 'admin_notices', array( $this, 'cron_notice' ) );

		// cardsco_continue_scan picks up where a run stopped when there was
		// more to do than one pass should attempt.
		add_action( 'cardsco_daily_scan', array( $this, 'daily_scan' ) );
		add_action( 'cardsco_continue_scan', array( $this, 'daily_scan' ) );
		add_action( 'cardsco_hourly_maintenance', array( $this, 'hourly_maintenance' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'cardsco', false, dirname( CARDSCO_BASENAME ) . '/languages' );
	}

	/**
	 * Run pending migrations after a plugin update.
	 *
	 * @return void
	 */
	public function check_schema() {
		CardsCo_Schema::maybe_install();

		$stored = get_option( 'cardsco_version' );

		if ( $stored === CARDSCO_VERSION ) {
			return;
		}

		CardsCo_Activator::schedule_events();

		// Capabilities introduced by a later version need granting on update,
		// not only on a fresh activation.
		CardsCo_Roles::install();

		// The six categories group the occasion types the engine schedules
		// against; write the grouping onto existing rows on upgrade.
		CardsCo_Category::backfill();

		// 0.2.1 changed how a postcode is read out of the address box, so
		// anything saved before it needs recalculating once.
		if ( $stored && version_compare( $stored, '0.2.1', '<' ) ) {
			CardsCo_Recipient::backfill_addresses();
		}

		update_option( 'cardsco_version', CARDSCO_VERSION, false );
	}

	/**
	 * Warn once, on the plugins screen, if WP-Cron has not been handed over to
	 * a real server cron. Without that the daily scan runs only when somebody
	 * visits the site, which on a quiet morning means cards are queued late.
	 *
	 * @return void
	 */
	public function cron_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'CardsCo:', 'cardsco' ),
			esc_html__( 'card scheduling relies on a real server cron. Set DISABLE_WP_CRON to true in wp-config.php and add a cron job that calls wp-cron.php every 15 minutes.', 'cardsco' )
		);
	}

	/**
	 * Daily occasion scan: queues cards for the occasions coming up.
	 *
	 * @return void
	 */
	public function daily_scan() {
		do_action( 'cardsco_before_daily_scan' );

		// Programmes first. They claim their own people into boxes, and the
		// general scan below then skips anybody a programme is looking after,
		// so the order here is what stops a person getting two cards.
		$boxes = CardsCo_Box::run();

		$summary = CardsCo_Scheduler::run();

		$summary['boxes'] = $boxes;

		do_action( 'cardsco_after_daily_scan', $summary );
	}

	/**
	 * Hourly housekeeping.
	 *
	 * @return void
	 */
	public function hourly_maintenance() {
		// Roll annual occasions forward once their date has gone by, and
		// retire one-off occasions that have happened. Without this an annual
		// occasion would sit on last year's date and never be seen again.
		CardsCo_Occasion::refresh_stale();

		// A card held for a missing address goes back in the queue by itself
		// once somebody fills the address in.
		CardsCo_Queue::recheck_held();

		do_action( 'cardsco_run_maintenance' );
	}
}
