<?php
/**
 * Deactivation.
 *
 * Clears scheduled events only. No data is touched here, so deactivating and
 * reactivating is always safe.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cardsco_daily_scan' );
		wp_clear_scheduled_hook( 'cardsco_hourly_maintenance' );
		wp_clear_scheduled_hook( 'cardsco_continue_scan' );

		flush_rewrite_rules();
	}
}
