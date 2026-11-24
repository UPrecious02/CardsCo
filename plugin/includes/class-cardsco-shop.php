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

		/*
		 * Before WooCommerce's own handler, which goes on wp_loaded at 20.
		 * Getting in first is the whole point: once it has run, the card is
		 * in the basket and the only thing left to do is take it out again.
		 */
		add_action( 'wp_loaded', array( __CLASS__, 'catch_add_to_cart' ), 15 );
		add_action( 'template_redirect', array( __CLASS__, 'catch_product_page' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'only_ours' ), 10, 2 );
	}

	/**
	 * Is our own code the thing putting this in the basket?
	 *
	 * @var bool
	 */
	protected static $ours = false;

	/**
	 * Mark the basket changes our own flows make as ours.
	 *
	 * Set around our own add_to_cart() calls and cleared straight after.
	 * It lives for one request and is never written anywhere.
	 *
	 * @param bool $yes Starting or finishing.
	 * @return void
	 */
	public static function adding_ours( $yes = true ) {
		self::$ours = (bool) $yes;
	}

	/**
	 * Send anyone trying to buy a card straight into the Sending Card flow.
	 *
	 * A card on its own is not a thing that can be posted. It needs
	 * somebody to send it to, an address to send it to, and something
	 * written inside, and none of that is collected by a basket. An order
	 * for a bare card is money taken for something that cannot be
	 * fulfilled — which is exactly what the first real test of the shop
	 * produced.
	 *
	 * A redirect rather than a refusal: the person wanted this card, and
	 * the flow that takes their card details also takes the three things
	 * the card needs. Telling them no and leaving them on the page would
	 * be a dead end.
	 *
	 * Our own flows are untouched. They call WC()->cart->add_to_cart() in
	 * PHP and never come through the query string this reads.
	 *
	 * @return void
	 */
	public static function catch_add_to_cart() {
		if ( is_admin() || ! self::woo_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading WooCommerce's own public parameter, and only to redirect.
		$product_id = isset( $_REQUEST['add-to-cart'] ) ? absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) : 0;

		if ( ! $product_id ) {
			return;
		}

		self::send_them_to_the_flow( $product_id );
	}

	/**
	 * The same, for landing on a card's product page.
	 *
	 * The page is a WooCommerce product page with an Add to cart button on
	 * it, and nothing on the site links to it — but it is a public URL, the
	 * order confirmation links to it, and so do WooCommerce's order emails.
	 *
	 * @return void
	 */
	public static function catch_product_page() {
		if ( is_admin() || ! self::woo_active() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		self::send_them_to_the_flow( get_queried_object_id() );
	}

	/**
	 * Redirect to the flow for whichever card this product mirrors.
	 *
	 * Does nothing for a product that is not one of our cards, so a shop
	 * selling anything else alongside them carries on as it was.
	 *
	 * @param int $product_id The product being bought or viewed.
	 * @return void
	 */
	protected static function send_them_to_the_flow( $product_id ) {
		$card_id = CardsCo_Catalogue::card_for_product( $product_id );

		if ( ! $card_id ) {
			return;
		}

		$to = CardsCo_Send::use_url( $card_id );

		/*
		 * With the flow not published there is nowhere to send them, so
		 * they are left where they are. only_ours() still refuses the
		 * basket, so nothing unfulfillable can be bought either way.
		 */
		if ( '' === $to ) {
			return;
		}

		wp_safe_redirect( $to, 302 );
		exit;
	}

	/**
	 * Refuse a card going into the basket from anywhere but our own flows.
	 *
	 * The redirects above cover the ways a person actually gets here. This
	 * is behind them, for the ways that skip WooCommerce's form handler —
	 * the Store API the block cart and the product blocks use, chiefly,
	 * which runs this filter but never sees a query string.
	 *
	 * @param mixed $passed     What WooCommerce was going to do.
	 * @param int   $product_id The product being added.
	 * @return mixed
	 */
	public static function only_ours( $passed, $product_id = 0 ) {
		if ( self::$ours || ! CardsCo_Catalogue::card_for_product( $product_id ) ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'A card needs somebody to send it to before it can be bought. Choose the card again to start.', 'cardsco' ),
				'error'
			);
		}

		return false;
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
