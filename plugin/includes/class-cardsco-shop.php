<?php
/**
 * WooCommerce front-end presentation.
 *
 * The shop pages — single product, cart, checkout — are not rendered by an
 * Oxygen template, and WooCommerce has detected a block theme and stood down
 * from applying its own classic styles. The result is a page with browser
 * defaults. This class puts the CardsCo stylesheet on those pages.
 *
 * It is presentation only. Nothing here touches products, prices, stock or
 * orders, and the whole class is inert when WooCommerce is not installed.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Shop {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * The hook goes on unconditionally, and whether there is a shop is
		 * decided later, inside assets().
		 *
		 * This used to check for WooCommerce here and return early without
		 * registering anything, which meant the stylesheet never loaded on
		 * the live site at all. init() runs when WordPress includes the
		 * plugin file, and plugins are included in alphabetical order:
		 * cardsco/ comes before woocommerce/, so at this moment the
		 * WooCommerce class does not exist yet however complete the install
		 * is. The check was asking a question that cannot be answered yet.
		 *
		 * wp_enqueue_scripts fires long after every plugin has loaded, so
		 * assets() can ask it and get the truth.
		 */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/**
	 * Is WooCommerce present?
	 *
	 * Checked by class rather than by plugin file, so it is true on a
	 * multisite network activation too. Only ever called from assets(),
	 * which runs late enough for the answer to mean something — see init().
	 *
	 * @return bool
	 */
	protected static function woo_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Does this request render a shop page?
	 *
	 * The conditional tags are only defined once WooCommerce has loaded, and
	 * only meaningful on the front end, so both are guarded.
	 *
	 * @return bool
	 */
	protected static function is_shop_page() {
		if ( is_admin() ) {
			return false;
		}

		foreach ( array( 'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page' ) as $tag ) {
			if ( function_exists( $tag ) && call_user_func( $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Put the shop styles on shop pages.
	 *
	 * Runs at priority 20 so it lands after WooCommerce's own styles and wins
	 * on equal specificity. The font request is the same pairing the
	 * catalogue uses; a separate handle keeps the two independent, and
	 * wp_enqueue_style() de-duplicates the actual request.
	 *
	 * @return void
	 */
	public static function assets() {
		if ( ! self::woo_active() || ! self::is_shop_page() ) {
			return;
		}

		wp_enqueue_style(
			'cardsco-fonts-shop',
			'https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500&family=Inter:wght@400;500;600&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'cardsco-shop',
			CARDSCO_URL . 'assets/shop.css',
			array( 'cardsco-fonts-shop' ),
			CARDSCO_VERSION
		);
	}
}
