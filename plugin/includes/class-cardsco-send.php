<?php
/**
 * Sending one card, by somebody who may have no account at all.
 *
 * The occasion wizard keeps its drafts in a table scoped to a company. A
 * visitor who has never signed in has neither, so this keeps the card in
 * the WooCommerce session instead — which every visitor gets, signed in or
 * not — and moves it onto the cart line when they go to pay. Cart line
 * meta survives into the order, which is how the recipient and the message
 * reach whoever writes the card.
 *
 * That is the same path the occasion wizard already uses to carry its
 * programme id from basket to order; this carries more on it, nothing new.
 *
 * Steps:
 *   1  who it is for, and where it goes
 *   2  what it should say
 *   3  which card
 *   4  the order, and away to pay
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Send
 */
class CardsCo_Send {

	/** Where the half-finished card lives between requests. */
	const KEY = 'cardsco_send';

	/** What the cart line carries, and therefore what the order carries. */
	const LINE = '_cardsco_send';

	/** How many steps there are. */
	const STEPS = 4;

	/** Where the cached page lives. */
	const PAGE = 'cardsco_send_page';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'keep_on_line_item' ), 10, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'after_paying' ), 5 );
		add_filter( 'woocommerce_enable_guest_checkout', array( __CLASS__, 'allow_guest' ) );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_account' ) );
		add_action( 'save_post_page', array( __CLASS__, 'forget_page' ) );
		add_action( 'deleted_post', array( __CLASS__, 'forget_page' ) );
	}

	/**
	 * What somebody sees when they come back from paying.
	 *
	 * Two jobs: say what happens next in words about their card rather
	 * than about an order number, and offer an account — which is the only
	 * moment somebody who has just bought one card has a reason to want
	 * one.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function after_paying( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return;
		}

		$sent = array();

		foreach ( $order->get_items() as $item ) {
			$to = (string) $item->get_meta( __( 'To', 'cardsco' ) );

			if ( '' !== $to ) {
				$sent[] = array(
					'to'      => $to,
					'address' => (string) $item->get_meta( __( 'Address', 'cardsco' ) ),
				);
			}
		}

		if ( ! $sent ) {
			return;
		}

		/* The card is bought and on its way; the half-written one is done with. */
		self::forget();

		$offer_account = ! is_user_logged_in() && ! email_exists( $order->get_billing_email() );

		include CARDSCO_PATH . 'includes/front/partial-sent.php';
	}

	/**
	 * Is there a shop and a session to work with?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'WC' ) && ! is_null( WC()->session );
	}

	/**
	 * The page this flow lives on, or '' if it has not been published yet.
	 *
	 * Found by looking for the shortcode rather than by asking for a
	 * setting, so the catalogue links correctly the moment the page
	 * exists, with nothing to configure and nothing to keep in step.
	 * The answer is cached, and the cache is dropped whenever any page
	 * is saved, so moving the shortcode takes effect immediately.
	 *
	 * @return string
	 */
	public static function page_url() {
		$found = get_transient( self::PAGE );

		if ( false !== $found ) {
			return (string) $found;
		}

		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 20,
				's'                => '[cardsco_send',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$url = '';

		foreach ( $pages as $page_id ) {
			$content = get_post_field( 'post_content', $page_id );

			if ( has_shortcode( (string) $content, 'cardsco_send' ) ) {
				$url = (string) get_permalink( $page_id );
				break;
			}
		}

		set_transient( self::PAGE, $url, DAY_IN_SECONDS );

		return $url;
	}

	/**
	 * Where "Use this card" should go for one card.
	 *
	 * Never straight to the cart. A card bought on its own still needs a
	 * recipient, an address and a message before anybody can post it, so
	 * buying one without those is a sale that cannot be fulfilled. With
	 * the flow published the link starts it on that card; without it, the
	 * link stays on the card's own page rather than opening a checkout
	 * that leads nowhere.
	 *
	 * @param int    $card_id  The card being chosen.
	 * @param string $fallback Where to go if the flow is not published.
	 * @return string
	 */
	public static function use_url( $card_id, $fallback = '' ) {
		$page = self::page_url();

		if ( '' === $page ) {
			return (string) $fallback;
		}

		return add_query_arg( 'card', (int) $card_id, $page );
	}

	/**
	 * Forget the cached page whenever any page is saved.
	 *
	 * @param int $post_id The post being saved.
	 * @return void
	 */
	public static function forget_page( $post_id = 0 ) {
		unset( $post_id );
		delete_transient( self::PAGE );
	}

	/**
	 * The card being written, as far as it has got.
	 *
	 * @return array
	 */
	public static function draft() {
		$empty = array(
			'step'      => 1,
			'name'      => '',
			'company'   => '',
			'line1'     => '',
			'line2'     => '',
			'town'      => '',
			'postcode'  => '',
			'message'   => '',
			'signature' => '',
			'hand'      => '',
			'card_id'   => 0,
			'reached'   => 1,
		);

		if ( ! self::available() ) {
			return $empty;
		}

		$held = WC()->session->get( self::KEY );

		return is_array( $held ) ? array_merge( $empty, $held ) : $empty;
	}

	/**
	 * Keep what has been filled in so far.
	 *
	 * Setting the session cookie matters: without it a visitor who is not
	 * signed in gets a fresh session on the next request and their address
	 * is gone. WooCommerce only sets it once something is in the cart, and
	 * nothing is in the cart until the last step.
	 *
	 * @param array $data Fields to merge in.
	 * @return array The draft as it now stands.
	 */
	public static function save( array $data ) {
		if ( ! self::available() ) {
			return self::draft();
		}

		$draft = array_merge( self::draft(), $data );

		WC()->session->set( self::KEY, $draft );
		WC()->session->set_customer_session_cookie( true );

		return $draft;
	}

	/**
	 * Throw the card away.
	 *
	 * @return void
	 */
	public static function forget() {
		if ( self::available() ) {
			WC()->session->set( self::KEY, null );
		}
	}

	/**
	 * Which step can be shown, given what has been filled in.
	 *
	 * Asking for step 4 with no address gets step 1, so a bookmarked or
	 * guessed address cannot skip past the things the card needs.
	 *
	 * @param array $draft Draft.
	 * @param int   $want  The step asked for.
	 * @return int
	 */
	public static function step_on_screen( array $draft, $want ) {
		$want = max( 1, min( self::STEPS, (int) $want ) );

		for ( $step = 1; $step < $want; $step++ ) {
			if ( ! self::step_done( $draft, $step ) ) {
				return $step;
			}
		}

		return $want;
	}

	/**
	 * Is a step finished?
	 *
	 * @param array $draft Draft.
	 * @param int   $step  Step.
	 * @return bool
	 */
	public static function step_done( array $draft, $step ) {
		switch ( (int) $step ) {
			case 1:
				return '' !== trim( $draft['name'] )
					&& '' !== trim( $draft['line1'] )
					&& '' !== trim( $draft['town'] )
					&& '' !== trim( $draft['postcode'] );

			case 2:
				return '' !== trim( $draft['message'] );

			case 3:
				return (int) $draft['card_id'] > 0;

			case 4:
				return false;
		}

		return false;
	}

	/**
	 * What the card costs. Postage is inside that price, not beside it.
	 *
	 * @param array $draft Draft.
	 * @return float
	 */
	public static function card_price( array $draft ) {
		if ( ! $draft['card_id'] ) {
			return 0.0;
		}

		return (float) CardsCo_Card::price_for( (int) $draft['card_id'], 1 );
	}

	/**
	 * The whole address on one line, the way the card shows it.
	 *
	 * @param array $draft Draft.
	 * @return string
	 */
	public static function address_line( array $draft ) {
		$bits = array_filter(
			array(
				trim( $draft['line1'] ),
				trim( $draft['line2'] ),
				trim( $draft['town'] ),
				strtoupper( trim( $draft['postcode'] ) ),
			)
		);

		return implode( ', ', $bits );
	}

	/**
	 * Make an account for somebody who has just bought a card.
	 *
	 * Only possible for whoever holds the order's own link: the key below
	 * is the one WooCommerce put in the address of the thank-you page, and
	 * without it an order number alone would let anybody claim somebody
	 * else's order and the address on it.
	 *
	 * @return void
	 */
	protected static function claim() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked immediately below.
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$key      = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'cardsco_claim_' . $order_id, '_cardsco_nonce' );

		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			self::go( array( 'cardsco_error' => __( 'That order could not be found.', 'cardsco' ) ) );
		}

		if ( is_user_logged_in() ) {
			self::go( array() );
		}

		$email = sanitize_email( $order->get_billing_email() );

		if ( ! is_email( $email ) || email_exists( $email ) ) {
			self::go( array( 'cardsco_error' => __( 'There is already an account for that address. Sign in instead.', 'cardsco' ) ) );
		}

		if ( strlen( $password ) < 8 ) {
			self::go( array( 'cardsco_error' => __( 'Passwords need to be at least 8 characters.', 'cardsco' ) ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => $password,
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'role'       => 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::go( array( 'cardsco_error' => $user_id->get_error_message() ) );
		}

		/* The order they just placed belongs to the account they just made. */
		$order->set_customer_id( (int) $user_id );
		$order->save();

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, true );

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}

	/**
	 * A handful of written messages to start from.
	 *
	 * The frames offer chips that kick off an AI draft. There is no
	 * drafting here, so they do the one useful thing left: drop a written
	 * message into the box to edit. Same source as the wizard's, so the
	 * CEO's own messages are what a visitor sees.
	 *
	 * @param int $how_many At most this many.
	 * @return array
	 */
	public static function starters( $how_many = 6 ) {
		$out  = array();
		$seen = array();

		foreach ( CardsCo_Message::for_occasion( 0, 0 ) as $post ) {
			$body = trim( CardsCo_Message::text( $post->ID ) );

			/*
			 * A message written for a company's own occasion is full of
			 * merge tags — {first name}, {our company} — and a visitor with
			 * no company has nothing to fill them from. Those are left out
			 * rather than offered and then refused.
			 */
			if ( '' === $body || isset( $seen[ $body ] ) || preg_match( '/\{[^}]+\}/', $body ) ) {
				continue;
			}

			$seen[ $body ] = true;

			$out[] = array(
				'label' => get_the_title( $post ),
				'text'  => $body,
			);

			if ( count( $out ) >= (int) $how_many ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The name somebody would be greeted by.
	 *
	 * @param string $full A full name.
	 * @return string
	 */
	public static function first_name( $full ) {
		$bits = preg_split( '/\s+/', trim( (string) $full ) );

		return $bits && '' !== $bits[0] ? $bits[0] : __( 'them', 'cardsco' );
	}

	/**
	 * Deal with a posted step.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['cardsco_send_action'] ) ? sanitize_key( wp_unslash( $_POST['cardsco_send_action'] ) ) : '';

		if ( ! $action ) {
			return;
		}

		/*
		 * Claiming an order carries its own nonce, tied to that order, so
		 * it is checked inside claim() rather than against the sending
		 * form's.
		 */
		if ( 'claim' !== $action ) {
			check_admin_referer( 'cardsco_send', '_cardsco_nonce' );
		}

		if ( ! self::available() ) {
			self::go( array( 'cardsco_error' => __( 'The shop is not available right now. Try again shortly.', 'cardsco' ) ) );
		}

		if ( 'start_over' === $action ) {
			self::forget();
			self::go( array() );
		}

		if ( 'claim' === $action ) {
			self::claim();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$step  = max( 1, min( self::STEPS, absint( $_POST['step'] ?? 1 ) ) );
		$draft = self::save( self::fields( $step ) );

		/*
		 * The last step is not a step with fields to finish — it is the
		 * handover to payment, and to_payment() checks the steps before it
		 * for itself. Running the generic guard here sent it straight back
		 * with "something is missing", because step_done() answers false
		 * for a step that collects nothing.
		 */
		if ( self::STEPS === $step ) {
			self::to_payment( $draft );
		}

		if ( ! self::step_done( $draft, $step ) ) {
			self::go(
				array(
					'step'          => $step,
					'cardsco_error' => self::what_is_missing( $step ),
				)
			);
		}

		$next  = min( self::STEPS, $step + 1 );
		$draft = self::save( array( 'reached' => max( (int) $draft['reached'], $next ) ) );

		self::go( array( 'step' => $next ) );
	}

	/**
	 * Read one step's fields off the request.
	 *
	 * @param int $step Step.
	 * @return array
	 */
	protected static function fields( $step ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		switch ( (int) $step ) {
			case 1:
				return array(
					'name'     => $text( 'name' ),
					'company'  => $text( 'company' ),
					'line1'    => $text( 'line1' ),
					'line2'    => $text( 'line2' ),
					'town'     => $text( 'town' ),
					'postcode' => $text( 'postcode' ),
				);

			case 2:
				return array(
					'message'   => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
					'signature' => $text( 'signature' ),
					'hand'      => $text( 'hand' ),
				);

			case 3:
				return array( 'card_id' => absint( $_POST['card_id'] ?? 0 ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array();
	}

	/**
	 * Why a step would not save.
	 *
	 * @param int $step Step.
	 * @return string
	 */
	protected static function what_is_missing( $step ) {
		switch ( (int) $step ) {
			case 1:
				return __( 'We need a name, a street, a town and a postcode to post it.', 'cardsco' );

			case 2:
				return __( 'Write something before carrying on.', 'cardsco' );

			case 3:
				return __( 'Pick a card.', 'cardsco' );
		}

		return __( 'Something is missing.', 'cardsco' );
	}

	/**
	 * Put the card in the basket and hand over to the checkout.
	 *
	 * @param array $draft Draft.
	 * @return void
	 */
	protected static function to_payment( array $draft ) {
		for ( $step = 1; $step < self::STEPS; $step++ ) {
			if ( ! self::step_done( $draft, $step ) ) {
				self::go( array( 'step' => $step, 'cardsco_error' => self::what_is_missing( $step ) ) );
			}
		}

		$product_id = CardsCo_Catalogue::product_for_card( (int) $draft['card_id'] );

		if ( ! $product_id ) {
			$made = CardsCo_Catalogue::sync( (int) $draft['card_id'] );

			if ( ! is_wp_error( $made ) ) {
				$product_id = (int) $made;
			}
		}

		if ( ! $product_id || is_null( WC()->cart ) ) {
			self::go( array( 'step' => 3, 'cardsco_error' => __( 'That card cannot be bought at the moment. Pick another.', 'cardsco' ) ) );
		}

		/* Anything from an earlier attempt goes, so going back does not buy two. */
		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( isset( $line[ self::LINE ] ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		$added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( self::LINE => $draft ) );

		if ( ! $added ) {
			self::go( array( 'step' => 4, 'cardsco_error' => __( 'That card could not be added to the basket.', 'cardsco' ) ) );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Is there a single card in the basket right now?
	 *
	 * @return bool
	 */
	public static function in_basket() {
		if ( ! self::available() || is_null( WC()->cart ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			if ( ! empty( $line[ self::LINE ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Let a visitor buying one card pay without making an account.
	 *
	 * The whole point of this flow is that somebody can send a card
	 * without signing up, and they are offered an account afterwards, once
	 * they have a reason to want one. WooCommerce's "customers must
	 * register" setting is a single tick that silently turns that into a
	 * dead end, and it belongs to the shop rather than to us, so it is
	 * overridden here for this basket only. A basket with anything else in
	 * it is left alone and keeps the shop's own answer.
	 *
	 * @param mixed $allowed What WooCommerce was going to do.
	 * @return mixed
	 */
	public static function allow_guest( $allowed ) {
		return self::in_basket() ? true : $allowed;
	}

	/**
	 * The same answer, for the block checkout, which asks it the other way up.
	 *
	 * @param mixed $required What WooCommerce was going to do.
	 * @return mixed
	 */
	public static function require_account( $required ) {
		return self::in_basket() ? false : $required;
	}

	/**
	 * Carry the card onto the order, where it can be written from.
	 *
	 * @param object $item  WC_Order_Item_Product.
	 * @param string $key   Cart item key.
	 * @param array  $line  Cart line.
	 * @param object $order WC_Order.
	 * @return void
	 */
	public static function keep_on_line_item( $item, $key, $line, $order ) {
		if ( empty( $line[ self::LINE ] ) || ! is_array( $line[ self::LINE ] ) ) {
			return;
		}

		$card = $line[ self::LINE ];

		/*
		 * Written as readable lines rather than one blob, because these are
		 * what somebody reads off the order to write the card. A serialised
		 * array in the admin is no use to the person holding the pen.
		 */
		$item->add_meta_data( __( 'To', 'cardsco' ), $card['name'], true );

		if ( '' !== trim( (string) $card['company'] ) ) {
			$item->add_meta_data( __( 'Company', 'cardsco' ), $card['company'], true );
		}

		$item->add_meta_data( __( 'Address', 'cardsco' ), self::address_line( $card ), true );
		$item->add_meta_data( __( 'Message', 'cardsco' ), $card['message'], true );

		if ( '' !== trim( (string) $card['signature'] ) ) {
			$item->add_meta_data( __( 'Signed', 'cardsco' ), $card['signature'], true );
		}

		if ( '' !== trim( (string) $card['hand'] ) ) {
			$item->add_meta_data( __( 'Handwriting', 'cardsco' ), $card['hand'], true );
		}
	}

	/**
	 * Back to the sending page.
	 *
	 * @param array $args Query arguments.
	 * @return void
	 */
	public static function go( array $args ) {
		$base = remove_query_arg( array( 'step', 'cardsco_error', 'done' ) );

		wp_safe_redirect( $args ? add_query_arg( $args, $base ) : $base );
		exit;
	}
}
