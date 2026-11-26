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

	/** The card as data on the order line, for putting it on the writing list. */
	const LINE_DATA = '_cardsco_card';

	/** Set on an order line once its card is on the writing list. */
	const LINE_QUEUED = '_cardsco_queued';

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

		/*
		 * The confirmation's styles, loaded before the page is drawn.
		 *
		 * after_paying() runs while the receipt is being rendered, which is
		 * past the point where a stylesheet can reach the head — so the
		 * block came out as plain unstyled text under the receipt, with the
		 * rules for it sitting in a file nothing on that page loads.
		 */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_received' ) );

		/*
		 * Putting the card on the writing list hangs off payment, not off
		 * the thank-you page. after_paying() runs on woocommerce_thankyou,
		 * which only fires if the buyer comes back to the site: close the
		 * tab at the payment provider and it never runs at all. That was
		 * fine for showing a message and no good for the one step that has
		 * to happen, so this listens for the money instead.
		 *
		 * Three hooks because gateways differ in which they fire, and the
		 * block checkout fires a different set again. queue_order() only
		 * ever acts once per card, so hearing the same order three times
		 * costs a lookup and changes nothing.
		 */
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'queue_order' ), 10 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue_order' ), 10 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue_order' ), 10 );
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
	/**
	 * Put every card on a paid order onto the writing list.
	 *
	 * Until this existed, a card bought on the site was paid for, promised
	 * on screen — "we'll write it and post it" — and then known to nobody.
	 * The writing list is built from the queue, and nothing outside the
	 * occasion and campaign paths ever wrote to it, so the order sat in
	 * WooCommerce with the address on the line and no work item anywhere.
	 *
	 * Safe to call as often as the gateways care to call it: each line is
	 * marked once its card is on the list, and a marked line is skipped.
	 *
	 * @param int $order_id Order ID.
	 * @return int How many cards were added, for the tests and the log.
	 */
	public static function queue_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return 0;
		}

		$added   = 0;
		$ordered = self::ordered_on( $order );

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$card = $item->get_meta( self::LINE_DATA );

			/*
			 * Judged by the cards it comes to, not by the draft's own name
			 * field. That field is the form somebody types a person into,
			 * and it is empty once they have been added to the list — so
			 * this skipped every order addressed to more than one person
			 * and wrote nothing at all for it.
			 */
			if ( ! is_array( $card ) ) {
				continue;
			}

			/*
			 * One line can now be several cards: a card each for up to ten
			 * named people, or a batch of the same card to one address.
			 * This made exactly one card per line, so an order for six
			 * would have been paid for in full and one card written.
			 */
			$wanted = self::cards_in( $card );

			if ( ! $wanted ) {
				continue;
			}

			$already = (array) $item->get_meta( self::LINE_QUEUED );
			$already = array_values( array_filter( array_map( 'absint', $already ) ) );

			if ( count( $already ) >= count( $wanted ) ) {
				continue;
			}

			/*
			 * Only the ones not yet written, so a second run after a
			 * failure finishes the order rather than starting it again.
			 */
			foreach ( array_slice( $wanted, count( $already ) ) as $one ) {
				$queue_id = self::queue_one( $order, $item, array_merge( $card, $one ), $ordered );

				if ( is_wp_error( $queue_id ) || ! $queue_id ) {
					break;
				}

				/*
				 * Marked on the line rather than counted in the queue, so
				 * that two cards to the same person on one order both get
				 * written while the same card is never written twice.
				 */
				$already[] = (int) $queue_id;

				$item->update_meta_data( self::LINE_QUEUED, $already );
				$item->save();

				$added++;
			}
		}

		return $added;
	}

	/**
	 * One line of a paid order, as a card to write.
	 *
	 * @param object $order   The order.
	 * @param object $item    The line.
	 * @param array  $card    What the sender filled in.
	 * @param string $ordered Y-m-d the order was placed.
	 * @return int|WP_Error Queue ID.
	 */
	protected static function queue_one( $order, $item, array $card, $ordered ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		$card_id    = (int) ( $card['card_id'] ?? 0 );

		if ( ! $card_id && $product_id ) {
			$card_id = (int) CardsCo_Catalogue::card_for_product( $product_id );
		}

		$write_by = cardsco_add_working_days( $ordered, (int) CardsCo_Settings::get( 'single_card_days' ) );
		$post_by  = cardsco_add_working_days( $write_by, (int) CardsCo_Settings::get( 'postage_days' ) );

		/*
		 * Nobody has to be signed in to buy one of these, so the company is
		 * whatever the order carries and nothing when it carries none.
		 */
		$company_id = (int) $order->get_meta( '_cardsco_company_id' );

		$message = trim( (string) ( $card['message'] ?? '' ) );
		$signed  = trim( (string) ( $card['signature'] ?? '' ) );

		if ( '' !== $signed ) {
			$message .= "\n\n" . $signed;
		}

		return CardsCo_Queue::add(
			array(
				'company_id'        => $company_id,
				'source'            => 'order',
				'order_id'          => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
				'recipient_name'    => (string) $card['name'],
				'recipient_company' => (string) ( $card['company'] ?? '' ),
				'address_snapshot'  => self::address_line( $card ),
				'postcode'          => (string) ( $card['postcode'] ?? '' ),
				'card_id'           => $card_id,
				'card_title'        => $card_id ? (string) get_the_title( $card_id ) : '',
				'occasion_label'    => __( 'Single card', 'cardsco' ),
				'message_final'     => $message,
				'write_by'          => $write_by,
				'post_by'           => $post_by,
				'unit_price'        => method_exists( $item, 'get_total' ) ? $item->get_total() : null,
				'status'            => self::hold_or_queue( $card, $card_id ),
				'hold_reason'       => self::why_held( $card, $card_id ),
			)
		);
	}

	/**
	 * The day an order was placed, for counting the deadline from.
	 *
	 * @param object $order The order.
	 * @return string Y-m-d.
	 */
	protected static function ordered_on( $order ) {
		$placed = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

		if ( is_object( $placed ) && method_exists( $placed, 'date' ) ) {
			return (string) $placed->date( 'Y-m-d' );
		}

		return gmdate( 'Y-m-d' );
	}

	/**
	 * Whether this card can be written as it stands.
	 *
	 * The steps will not let anybody pay with a piece missing, so a held
	 * card here means something went wrong rather than somebody skipping a
	 * field. It goes on the list either way: held and visible beats absent.
	 *
	 * @param array $card    What the sender filled in.
	 * @param int   $card_id The card.
	 * @return string
	 */
	protected static function hold_or_queue( array $card, $card_id ) {
		/* 'on_hold', not 'hold': add() quietly treats a status it does not
		   know as queued, so the wrong word here would put a card with no
		   address on the list as ready to write. */
		return '' === self::why_held( $card, $card_id ) ? 'queued' : 'on_hold';
	}

	/**
	 * Why a bought card cannot be written yet, if it cannot.
	 *
	 * @param array $card    What the sender filled in.
	 * @param int   $card_id The card.
	 * @return string A hold reason, or an empty string.
	 */
	protected static function why_held( array $card, $card_id ) {
		if ( '' === trim( (string) ( $card['line1'] ?? '' ) ) || '' === trim( (string) ( $card['postcode'] ?? '' ) ) ) {
			return 'no_address';
		}

		if ( '' === trim( (string) ( $card['message'] ?? '' ) ) ) {
			return 'no_message';
		}

		if ( ! $card_id ) {
			return 'no_card';
		}

		return '';
	}

	public static function enqueue_received() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		CardsCo_Catalogue::enqueue_catalogue();
	}

	public static function after_paying( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return;
		}

		/*
		 * Our lines are found by the data we attach to them, not by a
		 * readable "To" having something in it.
		 *
		 * This used to collect only lines with a name on them and give up
		 * when there were none — so an order whose recipient did not make
		 * it onto the line showed the bare WooCommerce receipt and nothing
		 * else: no confirmation that a card was on its way, and no offer of
		 * an account at the one moment somebody has a reason to want one.
		 * The buyer could not tell whether anything had worked.
		 *
		 * An order placed before the flow could hold several people is
		 * exactly that order, and there are some. LINE_DATA is written to
		 * every line this flow creates, so it answers "is this ours" on its
		 * own, and a line with no name is shown without the sentence that
		 * needs one rather than dropped.
		 */
		$sent = array();
		$ours = false;

		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( self::LINE_DATA );

			if ( ! is_array( $data ) && '' === (string) $item->get_meta( __( 'To', 'cardsco' ) ) ) {
				continue;
			}

			$ours = true;

			$to = (string) $item->get_meta( __( 'To', 'cardsco' ) );

			if ( '' === $to && is_array( $data ) ) {
				$to = (string) ( $data['name'] ?? '' );
			}

			$address = (string) $item->get_meta( __( 'Address', 'cardsco' ) );

			if ( '' === $address && is_array( $data ) ) {
				$address = self::address_line( $data );
			}

			$sent[] = array(
				'to'      => $to,
				'address' => $address,
			);
		}

		if ( ! $ours ) {
			return;
		}

		/* The card is bought and on its way; the half-written one is done with. */
		self::forget();

		/*
		 * Three different people land here, and only one of them was being
		 * offered anything.
		 *
		 * Somebody with no account gets the offer of one. Somebody whose
		 * address already has an account — which is most people who have
		 * bought before — got nothing at all, when what they want is the
		 * way in. And somebody already signed in needs neither.
		 */
		$signed_in     = is_user_logged_in();
		$has_account   = ! $signed_in && email_exists( $order->get_billing_email() );
		$offer_account = ! $signed_in && ! $has_account;
		$offer_sign_in = $has_account;
		$sign_in_url   = $offer_sign_in ? self::sign_in_url( $order ) : '';

		include CARDSCO_PATH . 'includes/front/partial-sent.php';
	}

	/**
	 * Where "Sign in" on the confirmation goes.
	 *
	 * Back to this page afterwards, so signing in does not cost them the
	 * confirmation they were reading.
	 *
	 * @param object $order The order.
	 * @return string
	 */
	protected static function sign_in_url( $order ) {
		$login = CardsCo_Shortcodes::page_for( 'cardsco_login' );

		if ( ! $login ) {
			return '';
		}

		$back = method_exists( $order, 'get_checkout_order_received_url' )
			? (string) $order->get_checkout_order_received_url()
			: '';

		return $back ? add_query_arg( 'redirect_to', rawurlencode( $back ), $login ) : (string) $login;
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
	/**
	 * The copy of this flow that lives inside the portal, if there is one.
	 *
	 * A page carrying [cardsco_send portal="1"] writes its own address down
	 * when it renders, the same way every other portal screen does — which
	 * is the only way to find a page on a site built with a page builder,
	 * where the shortcode is in the builder's storage and not in the post.
	 *
	 * @return string A URL, or '' when no such page has been made.
	 */
	public static function portal_page_url() {
		return (string) CardsCo_Shortcodes::page_for( 'cardsco_send_portal' );
	}

	/**
	 * Where to send this person to write one card.
	 *
	 * A customer goes to the portal copy when there is one, so sending a
	 * card is something they do inside their account rather than out on the
	 * marketing site. Everybody else goes to the public one.
	 *
	 * @return string
	 */
	public static function flow_url() {
		if ( is_user_logged_in() && CardsCo_Access::company_id() ) {
			$portal = self::portal_page_url();

			if ( '' !== $portal ) {
				return $portal;
			}
		}

		return self::page_url();
	}

	public static function page_url() {
		/*
		 * One implementation, not two.
		 *
		 * This had its own copy of the search for a page carrying the
		 * shortcode, and when the flow gained a second page — the copy
		 * inside the portal — only the other copy learned to tell them
		 * apart. This one went on handing the portal page to guests, who
		 * cannot open it.
		 */
		return (string) CardsCo_Shortcodes::page_for( 'cardsco_send' );
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
		$page = self::flow_url();

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

		/*
		 * The page lookup lives in CardsCo_Shortcodes now, so this clears
		 * what that one caches. The old transient is dropped as well, for
		 * installs upgrading with one still in the table.
		 */
		delete_transient( self::PAGE );
		CardsCo_Shortcodes::forget_pages();
	}

	/**
	 * The card being written, as far as it has got.
	 *
	 * @return array
	 */
	public static function draft() {
		$empty = array(
			'step'      => 1,
			/*
			 * How this order is addressed. 'people' posts a card to each
			 * person named; 'batch' posts a stack of the same card to one
			 * address for the buyer to hand out, which is the bulk order
			 * the proposal describes for companies without an account.
			 */
			'mode'      => 'people',
			'people'    => array(),
			'copies'    => 0,
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
	 * Where somebody goes to open an account.
	 *
	 * @return string
	 */
	public static function signup_link() {
		$page = CardsCo_Shortcodes::page_for( 'cardsco_signup' );

		return $page ? $page : wp_registration_url();
	}

	/**
	 * The most people a guest can address one order to.
	 *
	 * Past this it stops being a card to send and becomes a list to keep,
	 * which is what an account is for. A batch to a single address has no
	 * such limit — nothing is being managed, it is one parcel.
	 *
	 * @return int
	 */
	/**
	 * The longest message a pen will write on one of these cards.
	 *
	 * The same setting the portal's writer uses. A guest message had no
	 * limit at all: the box took as much as somebody cared to paste, the
	 * order went through, and the length was not anybody's problem until a
	 * card was in front of a writer with no room left on it.
	 *
	 * @return int Characters, or 0 if the setting is off.
	 */
	public static function max_chars() {
		return (int) CardsCo_Settings::get( 'message_max_chars' );
	}

	public static function cap() {
		$cap = (int) CardsCo_Settings::get( 'guest_max_people' );

		return $cap > 0 ? $cap : 10;
	}

	/**
	 * One entry per card to be written.
	 *
	 * Every screen after the first, the basket and the handwriting queue
	 * all work from this, so there is one answer to "what was ordered"
	 * rather than three that can disagree.
	 *
	 * @param array $draft Draft.
	 * @return array[] Each with name, company and the address lines.
	 */
	public static function cards_in( array $draft ) {
		$one = static function ( array $from ) {
			return array(
				'name'     => (string) ( $from['name'] ?? '' ),
				'company'  => (string) ( $from['company'] ?? '' ),
				'line1'    => (string) ( $from['line1'] ?? '' ),
				'line2'    => (string) ( $from['line2'] ?? '' ),
				'town'     => (string) ( $from['town'] ?? '' ),
				'postcode' => (string) ( $from['postcode'] ?? '' ),
			);
		};

		if ( 'batch' === (string) ( $draft['mode'] ?? 'people' ) ) {
			$copies = max( 0, (int) ( $draft['copies'] ?? 0 ) );

			return $copies ? array_fill( 0, $copies, $one( $draft ) ) : array();
		}

		$people = array();

		foreach ( (array) ( $draft['people'] ?? array() ) as $person ) {
			if ( is_array( $person ) ) {
				$people[] = $one( $person );
			}
		}

		/*
		 * A draft begun before this order could hold several people has
		 * its one address in the draft's own fields, so it is read as the
		 * single card it is rather than as an empty order.
		 */
		if ( ! $people && '' !== trim( (string) $draft['name'] ) && '' !== trim( (string) $draft['line1'] ) ) {
			$people[] = $one( $draft );
		}

		return $people;
	}

	/**
	 * Whether an address is complete enough to post to.
	 *
	 * @param array $person One person.
	 * @return bool
	 */
	public static function postable( array $person ) {
		foreach ( array( 'name', 'line1', 'town', 'postcode' ) as $need ) {
			if ( '' === trim( (string) ( $person[ $need ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
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
	/**
	 * Is the card already settled?
	 *
	 * Everybody who reaches this flow from the site comes through the
	 * collections and the catalogue and presses "Use this card", so the card
	 * is decided before the first screen. Asking again is a screen they open
	 * with their own card already selected and press Continue on.
	 *
	 * The card has to still be on sale. One withdrawn between the click and
	 * the order would otherwise be skipped past silently and taken to
	 * payment.
	 *
	 * @param array $draft Draft.
	 * @return bool
	 */
	public static function card_chosen( array $draft ) {
		$card_id = absint( $draft['card_id'] ?? 0 );

		return $card_id > 0 && CardsCo_Catalogue::is_sellable( $card_id );
	}

	/**
	 * The steps this visitor is actually shown, in order.
	 *
	 * The numbers themselves do not move: 3 is the card wherever it appears,
	 * so what a posted form means, what step_done() answers and what is
	 * saved are all unchanged. Only which of them are drawn, and what comes
	 * after what, depend on the draft.
	 *
	 * Step 3 is still reachable on purpose — "Change card" on the last step
	 * goes straight to it — so this says which steps are walked through, not
	 * which may be opened.
	 *
	 * @param array $draft Draft.
	 * @return int[]
	 */
	public static function visible_steps( array $draft ) {
		if ( self::card_chosen( $draft ) ) {
			return array( 1, 2, 4 );
		}

		return range( 1, self::STEPS );
	}

	/**
	 * The step Continue goes to, or the one Back comes from.
	 *
	 * @param array $draft Draft.
	 * @param int   $step  The step now on screen.
	 * @param int   $by    1 for the next one, -1 for the one before.
	 * @return int The step, or the one given when there is none that way.
	 */
	public static function step_along( array $draft, $step, $by ) {
		$steps = self::visible_steps( $draft );
		$step  = (int) $step;
		$at    = array_search( $step, $steps, true );

		/*
		 * A step that is not in the walk — the card step opened from the
		 * last one — carries on from where it sits in the full order, so
		 * Continue from it lands on the next step there is rather than
		 * nowhere.
		 */
		if ( false === $at ) {
			foreach ( ( $by < 0 ? array_reverse( $steps ) : $steps ) as $one ) {
				if ( $by < 0 ? $one < $step : $one > $step ) {
					return $one;
				}
			}

			return $step;
		}

		$want = (int) $at + ( $by < 0 ? -1 : 1 );

		return isset( $steps[ $want ] ) ? (int) $steps[ $want ] : $step;
	}

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
				$cards = self::cards_in( $draft );

				if ( ! $cards ) {
					return false;
				}

				/* Every card has to have somewhere to go. */
				foreach ( $cards as $card ) {
					if ( ! self::postable( $card ) ) {
						return false;
					}
				}

				return 'batch' === (string) $draft['mode'] || count( $cards ) <= self::cap();

			case 2:
				$cc_said = trim( (string) $draft['message'] );
				$cc_max  = self::max_chars();

				/* The signature is written under the message, on the same
				   card, so it counts towards the room there is. */
				$cc_sign = trim( (string) ( $draft['signature'] ?? '' ) );
				$cc_len  = mb_strlen( $cc_sign ? $cc_said . "\n" . $cc_sign : $cc_said );

				if ( $cc_max > 0 && $cc_len > $cc_max ) {
					return false;
				}

				return '' !== $cc_said;

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
	 * How many cards go in the basket.
	 *
	 * Named rather than worked out where it is used, because it decides
	 * what somebody is charged and a test can only hold a line it can
	 * call. It was a literal 1, which was right while an order was one
	 * card and would otherwise take payment for one of however many were
	 * addressed.
	 *
	 * @param array $draft Draft.
	 * @return int
	 */
	public static function ordering( array $draft ) {
		return max( 1, count( self::cards_in( $draft ) ) );
	}

	/**
	 * What this order comes to.
	 *
	 * The total is asked for as one order of however many cards, not as
	 * one card's price repeated, because the bulk rate applies to the
	 * whole order once it reaches the limit. Priced a card at a time, an
	 * order of forty would have been charged at the standard rate and the
	 * bulk price would never have been seen by anybody.
	 *
	 * @param array $draft Draft.
	 * @return array{cards:int,each:float,total:float,bulk:bool}
	 */
	public static function order_sum( array $draft ) {
		$cards = max( 1, count( self::cards_in( $draft ) ) );
		$id    = (int) $draft['card_id'];

		if ( ! $id ) {
			return array( 'cards' => $cards, 'each' => 0.0, 'total' => 0.0, 'bulk' => false );
		}

		/*
		 * price_for() is what one card costs at that quantity; price_total()
		 * is what the order costs. Using the first for both priced thirty
		 * cards at one card's money — a 30-card order came to GBP 1.99.
		 */
		/*
		 * price_for() is what one card costs at that quantity; price_total()
		 * is what the order costs. Using the first for both priced thirty
		 * cards at one card's money — a 30-card order came to GBP 1.99.
		 */
		return array(
			'cards' => $cards,
			'each'  => (float) CardsCo_Card::price_for( $id, $cards ),
			'total' => (float) CardsCo_Card::price_total( $id, $cards ),
			'bulk'  => (bool) CardsCo_Card::is_bulk( $cards ),
		);

	}

	/**
	 * The whole address on one line, the way the card shows it.
	 *
	 * @param array $draft Draft.
	 * @return string
	 */
	public static function address_line( array $draft ) {
		/*
		 * Each part asked for rather than assumed. This is handed a draft
		 * from the session, which always has every field, and also the data
		 * kept on an order line, which was written by whichever version of
		 * this flow took that order — an old one has fewer fields, and
		 * reading a key that is not there turns a thank-you page into a
		 * row of PHP warnings.
		 */
		$part = static function ( $key ) use ( $draft ) {
			return trim( (string) ( $draft[ $key ] ?? '' ) );
		};

		$bits = array_filter(
			array(
				$part( 'line1' ),
				$part( 'line2' ),
				$part( 'town' ),
				strtoupper( $part( 'postcode' ) ),
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

		$first = (string) $order->get_billing_first_name();
		$last  = (string) $order->get_billing_last_name();

		/*
		 * The offer above this promises three things: see when the card is
		 * written and posted, send another in a couple of clicks, and set up
		 * cards that go out on their own. All three are behind the dashboard,
		 * and the dashboard needs the role and a company of their own.
		 *
		 * This used to make a bare WordPress customer, so somebody who took
		 * the offer could sign in and reach nothing: no orders, no occasions,
		 * no sign of the card they had just paid for. Built the same way a
		 * sign-up is now, so the two cannot drift apart.
		 *
		 * The company takes the name they gave at checkout if they gave one,
		 * and otherwise their own — somebody sending one card to a friend has
		 * no company name to offer, and asking for one here, after they have
		 * paid, is a form nobody would fill in.
		 */
		$company = trim( (string) $order->get_billing_company() );

		if ( '' === $company ) {
			$company = trim( $first . ' ' . $last );
		}

		if ( '' === $company ) {
			$company = $email;
		}

		$made = CardsCo_Auth::make_account( $email, $password, $first, $last, $company );

		if ( is_wp_error( $made ) ) {
			self::go( array( 'cardsco_error' => $made->get_error_message() ) );
		}

		$user_id    = (int) $made['user_id'];
		$company_id = (int) $made['company_id'];

		/* The order they just placed belongs to the account they just made. */
		$order->set_customer_id( $user_id );

		/*
		 * And to the company, so it shows in their orders on its own footing
		 * rather than only through who placed it. Orders found by the people
		 * on a company stop being found when somebody leaves it.
		 */
		if ( ! (int) $order->get_meta( '_cardsco_company_id' ) ) {
			$order->update_meta_data( '_cardsco_company_id', $company_id );
		}

		$order->save();

		/*
		 * The card they bought was queued before any of this existed, with
		 * nobody behind it. It is theirs now.
		 */
		self::hand_queue_over( $order, $company_id );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		/*
		 * Confirmation is chased afterwards rather than in the way, the same
		 * as a sign-up: the banner and the gates do the work.
		 */
		CardsCo_Verify::send( $user_id );

		wp_safe_redirect( add_query_arg( 'cc_welcome', '1', CardsCo_Auth::landing_for( $user_id ) ) );
		exit;
	}

	/**
	 * Give a company the cards bought on one of its orders.
	 *
	 * A card bought without an account is queued with no company on it,
	 * because there was none. When somebody takes the offer of an account
	 * afterwards, the card is theirs, and without this it would stay on the
	 * writing list belonging to nobody while they were promised they could
	 * watch it.
	 *
	 * @param object $order      The order.
	 * @param int    $company_id The company it now belongs to.
	 * @return int How many cards changed hands.
	 */
	protected static function hand_queue_over( $order, $company_id ) {
		global $wpdb;

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;

		if ( ! $order_id || ! $company_id ) {
			return 0;
		}

		$changed = $wpdb->update(
			cardsco_table( 'card_queue' ),
			array(
				'company_id' => (int) $company_id,
				'updated_at' => cardsco_now(),
			),
			array(
				'order_id'   => $order_id,
				'company_id' => 0,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);

		return (int) $changed;
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
	public static function starters( $how_many = 6, $card_id = 0 ) {
		$out  = array();
		$seen = array();

		/*
		 * Narrowed to the card, where there is one.
		 *
		 * The card is chosen at step 3 and the message is written at step 2,
		 * so most of the time there is nothing to narrow by and the row is a
		 * general mix. But somebody who came from the catalogue arrives with
		 * a card already chosen, and a Christmas design above six chips that
		 * start "Happy birthday" is the row working against them.
		 *
		 * By the card's collection rather than its own ticks, so a design in
		 * Leaving & Retirement offers both occasions of the pair.
		 *
		 * All or nothing: a narrowed row topped up from the general pool is
		 * the same mismatch with the relevant ones at the front. Where the
		 * card's occasions have nothing a visitor can use, the general mix
		 * is the better row.
		 */
		$only = self::starter_occasions( (int) $card_id );
		$pool = $only
			? CardsCo_Message::for_occasions( $only, 0 )
			: CardsCo_Message::for_occasion( 0, 0 );

		foreach ( $pool as $post ) {
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

		/* The card's own occasions had nothing usable; offer the rest. */
		if ( ! $out && $only ) {
			return self::starters( $how_many, 0 );
		}

		return $out;
	}

	/**
	 * The occasions a card's starter messages should come from, or an empty
	 * list for a visitor who has not chosen a card yet.
	 *
	 * @param int $card_id Card post ID.
	 * @return int[] Occasion type IDs.
	 */
	protected static function starter_occasions( $card_id ) {
		$card_id = absint( $card_id );

		if ( ! $card_id ) {
			return array();
		}

		$category = CardsCo_Card::category( $card_id );

		if ( $category ) {
			$ids = wp_list_pluck( CardsCo_Category::types( $category ), 'id' );
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

			if ( $ids ) {
				return $ids;
			}
		}

		/* No collection on it: whatever is ticked on the card itself. */
		return array_values( array_filter( CardsCo_Card::occasions( $card_id ) ) );
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

		if ( 'add_person' === $action ) {
			self::add_person();
		}

		if ( 'remove_person' === $action ) {
			self::remove_person();
		}

		if ( 'upload_people' === $action ) {
			self::upload_people();
		}

		if ( 'mode' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the POST's nonce is checked above.
			$want = 'batch' === sanitize_key( wp_unslash( $_GET['cc_mode'] ?? '' ) ) ? 'batch' : 'people';

			self::save( array( 'mode' => $want ) );
			self::go( array( 'step' => 1 ) );
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

		$next  = self::step_along( $draft, $step, 1 );
		$draft = self::save( array( 'reached' => max( (int) $draft['reached'], $next ) ) );

		self::go( array( 'step' => $next ) );
	}

	/**
	 * Put one person on the order.
	 *
	 * @return void
	 */
	protected static function add_person() {
		$draft  = self::draft();
		$person = self::fields( 1 );

		/* The batch count rides on the same form; it is not part of a person. */
		unset( $person['copies'] );

		if ( ! self::postable( $person ) ) {
			self::go(
				array(
					'step'          => 1,
					'cardsco_error' => __( 'A name, a street, a town and a postcode, please.', 'cardsco' ),
				)
			);
		}

		$people = (array) $draft['people'];

		if ( count( $people ) >= self::cap() ) {
			self::go( array( 'step' => 1, 'cardsco_full' => 1 ) );
		}

		/*
		 * The same person twice is two cards to one letterbox, paid for.
		 *
		 * Worth a guard on its own, and worth more than that here: the
		 * fields carried browser autocomplete tokens meant for the visitor's
		 * own address, so a form emptied after each person was filled back
		 * in by the browser, and somebody adding a third person could post
		 * the second one again without noticing. The tokens are gone, and
		 * this is the net under them.
		 *
		 * Refused, not merged: two people really can share an address, so
		 * the name has to match too, and then it is said rather than
		 * decided for them.
		 */
		foreach ( $people as $cc_already ) {
			if ( self::same_person( $cc_already, $person ) ) {
				self::go(
					array(
						'step'          => 1,
						'cardsco_error' => sprintf(
							/* translators: %s: the person's name. */
							__( '%s is already on the list at that address. Change the name or the address, or carry on.', 'cardsco' ),
							trim( (string) $person['name'] )
						),
					)
				);
			}
		}

		$people[] = $person;

		/*
		 * The form empties as the person joins the list, so the next name
		 * is typed into a clean form rather than over the last one.
		 */
		self::save(
			array(
				'mode'     => 'people',
				'people'   => array_values( $people ),
				'name'     => '',
				'company'  => '',
				'line1'    => '',
				'line2'    => '',
				'town'     => '',
				'postcode' => '',
			)
		);

		/*
		 * Back to step 1 at a slightly different address.
		 *
		 * A browser restores what was typed into a form when it loads the
		 * same URL again, which is what a redirect back to the same page is.
		 * That is separate from autofill and no autocomplete attribute
		 * governs it — the URL does. So the count rides along, every add
		 * lands somewhere the browser has not seen, and the form comes up
		 * as empty as the server sent it.
		 *
		 * It reads as "3 people so far" in the address bar, which is true
		 * and harmless, and $here strips it back out of the form's action.
		 */
		self::go(
			array(
				'step'      => 1,
				'cc_people' => count( $people ),
			)
		);
	}

	/**
	 * Whether two entries are the same person at the same address.
	 *
	 * Case and spacing are ignored, because "ADA NWOSU" and "Ada  Nwosu"
	 * are one person as far as a postman is concerned.
	 *
	 * @param array $a One entry.
	 * @param array $b Another.
	 * @return bool
	 */
	protected static function same_person( array $a, array $b ) {
		$tidy = static function ( $value ) {
			return strtolower( preg_replace( '/\s+/', ' ', trim( (string) $value ) ) );
		};

		foreach ( array( 'name', 'line1', 'town', 'postcode' ) as $field ) {
			if ( $tidy( $a[ $field ] ?? '' ) !== $tidy( $b[ $field ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Take one person off again.
	 *
	 * @return void
	 */
	protected static function remove_person() {
		$draft  = self::draft();
		$people = array_values( (array) $draft['people'] );

		/*
		 * In the address: a form cannot hold another form, and every row
		 * needs its own remove, so the button carries the index in its
		 * formaction rather than in a field of its own.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the POST's nonce is checked in handle().
		$which = isset( $_GET['cc_person'] ) ? (int) wp_unslash( $_GET['cc_person'] ) : -1;

		if ( isset( $people[ $which ] ) ) {
			unset( $people[ $which ] );
			self::save( array( 'people' => array_values( $people ) ) );
		}

		self::go( array( 'step' => 1 ) );
	}

	/**
	 * Read a list of people out of an uploaded file.
	 *
	 * The column names are the ones the rest of the plugin already knows,
	 * so a file that works for a company list works here too.
	 *
	 * @return void
	 */
	protected static function upload_people() {
		$draft = self::draft();

		if ( empty( $_FILES['people_file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::go( array( 'step' => 1, 'cardsco_error' => __( 'Choose a file to upload.', 'cardsco' ) ) );
		}

		$tmp = isset( $_FILES['people_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['people_file']['tmp_name'] ) ) : '';

		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			self::go( array( 'step' => 1, 'cardsco_error' => __( 'That file did not arrive. Try again.', 'cardsco' ) ) );
		}

		$read = self::read_people( $tmp );

		if ( is_wp_error( $read ) ) {
			self::go( array( 'step' => 1, 'cardsco_error' => $read->get_error_message() ) );
		}

		$people = array_values( (array) $draft['people'] );
		$room   = max( 0, self::cap() - count( $people ) );
		$taken  = array_slice( $read, 0, $room );
		$over   = count( $read ) - count( $taken );

		if ( ! $taken ) {
			self::go(
				array(
					'step'          => 1,
					'cardsco_error' => $over
						? __( 'The list is already full.', 'cardsco' )
						: __( 'Nobody in that file had a name and a full address.', 'cardsco' ),
					'cardsco_full'  => $over ? 1 : 0,
				)
			);
		}

		self::save( array( 'mode' => 'people', 'people' => array_merge( $people, $taken ) ) );

		self::go(
			array(
				'step'         => 1,
				'cardsco_took' => count( $taken ),
				'cardsco_full' => $over ? 1 : 0,
			)
		);
	}

	/**
	 * The people in an uploaded file, as far as they can be posted to.
	 *
	 * @param string $path A file on disk.
	 * @return array[]|WP_Error
	 */
	protected static function read_people( $path ) {
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return new WP_Error( 'cardsco_unreadable', __( 'That file could not be read.', 'cardsco' ) );
		}

		$headers = fgetcsv( $handle );

		if ( ! $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'cardsco_empty', __( 'That file is empty.', 'cardsco' ) );
		}

		/*
		 * The same column names the company importer accepts, plus a
		 * company column of our own: a company list does not carry one
		 * because the company is the account, but a guest posting to four
		 * different firms needs it on the envelope.
		 */
		$known            = CardsCo_Programme_Import::columns();
		$known['company'] = array( 'company', 'organisation', 'organization', 'business', 'company name' );
		$where = array();

		foreach ( $headers as $at => $head ) {
			$head = strtolower( trim( (string) $head ) );

			foreach ( $known as $field => $aliases ) {
				if ( in_array( $head, (array) $aliases, true ) ) {
					$where[ $field ] = $at;
					break;
				}
			}
		}

		$people = array();
		$cell   = static function ( $row, $at ) {
			return isset( $at, $row[ $at ] ) ? sanitize_text_field( (string) $row[ $at ] ) : '';
		};

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( $people ) >= 200 ) {
				break;
			}

			$name = trim( $cell( $row, $where['full_name'] ?? null ) );

			if ( '' === $name ) {
				$name = trim(
					$cell( $row, $where['first_name'] ?? null ) . ' ' . $cell( $row, $where['last_name'] ?? null )
				);
			}

			$person = array(
				'name'     => $name,
				'company'  => $cell( $row, $where['company'] ?? null ),
				'line1'    => $cell( $row, $where['address_1'] ?? null ),
				'line2'    => $cell( $row, $where['address_2'] ?? null ),
				'town'     => $cell( $row, $where['address_3'] ?? null ),
				'postcode' => $cell( $row, $where['postcode'] ?? null ),
			);

			/* Anyone we could not post to is left out rather than guessed at. */
			if ( self::postable( $person ) ) {
				$people[] = $person;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $people;
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
					'copies'   => isset( $_POST['copies'] ) ? max( 0, (int) wp_unslash( $_POST['copies'] ) ) : 0,
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
				return __( 'Add at least one person, with a name, a street, a town and a postcode.', 'cardsco' );

			case 2:
				$cc_max = self::max_chars();
				$cc_now = self::draft();
				$cc_msg = trim( (string) ( $cc_now['message'] ?? '' ) );
				$cc_sig = trim( (string) ( $cc_now['signature'] ?? '' ) );
				$cc_n   = mb_strlen( $cc_sig ? $cc_msg . "\n" . $cc_sig : $cc_msg );

				if ( '' !== $cc_msg && $cc_max > 0 && $cc_n > $cc_max ) {
					return sprintf(
						/* translators: 1: how many characters were written, 2: how many fit. */
						__( 'That is %1$d characters and %2$d fit on the card. Shorten it a little.', 'cardsco' ),
						$cc_n,
						$cc_max
					);
				}

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

		/* Ours, so the shop's guard against buying a bare card lets it by. */
		CardsCo_Shop::adding_ours( true );

		/*
		 * One line, with the quantity actually ordered on it. It was fixed
		 * at one, which was right while an order was one card to one
		 * person and would now charge for a single card however many were
		 * addressed — and would lose the bulk rate at twenty-five.
		 */
		$ordering = self::ordering( $draft );

		try {
			$added = WC()->cart->add_to_cart( $product_id, $ordering, 0, array(), array( self::LINE => $draft ) );
		} finally {
			CardsCo_Shop::adding_ours( false );
		}

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
		/*
		 * The same thing twice, for two different readers. The lines below
		 * are for the person holding the pen, so they are readable and
		 * translated. This one is for the code that puts the card on the
		 * writing list, which needs the card it was bought from and a
		 * postcode of its own rather than an address baked into a sentence.
		 * Underscored, so WooCommerce keeps it out of the order display.
		 */
		$item->add_meta_data( self::LINE_DATA, $card, true );

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
		$base = remove_query_arg( array( 'step', 'cardsco_error', 'done', 'cc_people', 'cardsco_took', 'cardsco_full' ) );

		wp_safe_redirect( $args ? add_query_arg( $args, $base ) : $base );
		exit;
	}
}
