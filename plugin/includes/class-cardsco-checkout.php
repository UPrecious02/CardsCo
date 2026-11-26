<?php
/**
 * From a finished occasion to a paid one.
 *
 * Step 7 ends at "Go to payment". What happens there is that the cards the
 * occasion needs are put in the WooCommerce cart and the customer is sent
 * to the ordinary checkout — the same one a single card goes through. Not
 * a second checkout of our own: WooCommerce already knows about tax, about
 * addresses, about Stripe, and about what to do when a card is declined,
 * and none of that is worth writing twice.
 *
 * The occasion is not turned on here. It is turned on when the payment
 * completes, which is a different moment and often a later one — a card
 * can be declined, a 3-D Secure step abandoned, a browser closed on the
 * Stripe page. Until then the occasion stays exactly what it was, a draft,
 * and nothing has been promised to anybody.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Checkout
 */
class CardsCo_Checkout {

	/** Which occasion a cart line, and later an order, is paying for. */
	const PROGRAMME = '_cardsco_programme_id';

	/** Set on an order once its occasion has been turned on, so it is not done twice. */
	const ACTIVATED = '_cardsco_activated';

	/** The campaign a basket line is paying for. */
	const CAMPAIGN = '_cardsco_campaign_id';

	/** Set on an order once its campaign has been scheduled. */
	const SCHEDULED = '_cardsco_scheduled';

	/**
	 * Hook into WooCommerce.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_the_cart' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'keep_on_line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'keep_on_order' ), 10, 2 );

		/*
		 * Both, because which one a shop lands on depends on whether the
		 * goods need posting. The guard inside means the second is a no-op.
		 */
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'campaign_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'campaign_paid' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'after_paying' ), 5 );
	}

	/**
	 * Is there a shop to check out through?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'WC' ) && function_exists( 'wc_get_checkout_url' );
	}

	/**
	 * How many cards this occasion is paying for.
	 *
	 * A birthday occasion runs for a year, and is paid for a year at a
	 * time: one card per person on it. That is the reading of "pricing per
	 * year is calculated on how many employees", and it is what makes a
	 * company of thirty reach the twenty-five card break.
	 *
	 * An occasion that lands on one day for everybody — Christmas, a
	 * promotion — is one card each, once.
	 *
	 * @param array $programme Programme row.
	 * @return int
	 */
	public static function quantity( array $programme ) {
		/*
		 * The cards that will actually be written, not the people on the
		 * list.
		 *
		 * This counted every recipient. The review step the customer reads
		 * before paying counts only those holding a date for the occasion,
		 * through Programme::estimate() — because somebody with no birthday
		 * on file is never written a birthday card.
		 *
		 * So the screen said three cards at £10.80 and the till took five
		 * at £18.00. The customer is shown one number and charged another,
		 * and the two cards they paid for do not exist and never go out.
		 *
		 * people_summary() answers 'ready' as the whole list for a category
		 * with no occasion type of its own, so a campaign-driven order is
		 * counted exactly as it was.
		 */
		$summary = CardsCo_Programme::people_summary( $programme );

		return (int) $summary['ready'];
	}

	/**
	 * Put the cards in the cart and say where to go next.
	 *
	 * @param array $programme Programme row.
	 * @return string|WP_Error The checkout address.
	 */
	public static function start( array $programme ) {
		if ( ! self::available() ) {
			return new WP_Error( 'cardsco_no_shop', __( 'The shop is not available right now. Try again shortly.', 'cardsco' ) );
		}

		if ( ! CardsCo_Programme::designs( $programme ) ) {
			return new WP_Error( 'cardsco_no_card', __( 'Pick a card before paying.', 'cardsco' ) );
		}

		$quantity = self::quantity( $programme );

		if ( $quantity < 1 ) {
			return new WP_Error( 'cardsco_no_people', __( 'There is nobody on this occasion yet.', 'cardsco' ) );
		}

		/*
		 * Twenty-five or more posted to individual addresses is priced case
		 * by case and is not for sale here — see needs_referral(). The
		 * screens say so already, so reaching this is a stale tab, a
		 * bookmarked URL, or somebody who changed the shipping mode in
		 * another window. It stops at the door either way: a basket that
		 * can be paid for is a price we are committed to.
		 */
		if ( CardsCo_Programme::needs_referral( $programme, $quantity ) ) {
			$referral = CardsCo_Programme::referral( $programme, $quantity );

			return new WP_Error(
				'cardsco_referral',
				$referral['body'],
				array( 'referral' => $referral )
			);
		}

		/*
		 * More designs than an order this size may carry, or a split that
		 * no longer adds up. The card step refuses both, so this is the
		 * same stale tab — but it is checked here too, because the basket
		 * is the thing that takes money.
		 *
		 * Only before the occasion is running. A renewal is the same
		 * occasion a year on, and people will have joined and left since
		 * the numbers were typed; the cards are shared out in the same
		 * proportions rather than the renewal being turned away over a
		 * sum that was right when it was written.
		 */
		$wrong = 'draft' === (string) $programme['status']
			? CardsCo_Programme::mix_problems( $programme )
			: array();

		if ( $wrong ) {
			return new WP_Error( 'cardsco_mix', $wrong[0] );
		}

		/*
		 * One basket line for each design, each for as many cards as that
		 * design was given. An occasion with a single design is one line,
		 * exactly as it always was.
		 *
		 * Every product is found before anything is added, so a design
		 * that cannot be bought stops the order rather than leaving half
		 * of it in the basket.
		 */
		$lines = array();

		foreach ( CardsCo_Programme::order_lines( $programme, $quantity ) as $line ) {
			$card_id    = (int) $line['card'];
			/*
			 * Made on the spot if it is missing, and put back on sale if it
			 * had been withdrawn: a design offered on the card step is one
			 * the customer can pay for. See product_for_order().
			 */
			$product_id = CardsCo_Catalogue::product_for_order( $card_id );

			if ( ! $product_id ) {
				/*
				 * Which one. With one card on the order "that card" was
				 * enough; with five designs it leaves somebody guessing
				 * which of them to take off.
				 */
				return new WP_Error(
					'cardsco_no_product',
					sprintf(
						/* translators: %s: the name of a card design. */
						__( '“%s” cannot be bought at the moment. Pick another design in its place, or tell us.', 'cardsco' ),
						get_the_title( $card_id )
					)
				);
			}

			$lines[] = array(
				'product' => (int) $product_id,
				'qty'     => (int) $line['qty'],
			);
		}

		if ( ! $lines ) {
			return new WP_Error( 'cardsco_no_card', __( 'Pick a card before paying.', 'cardsco' ) );
		}

		if ( is_null( WC()->cart ) ) {
			return new WP_Error( 'cardsco_no_cart', __( 'The basket is not ready. Try again shortly.', 'cardsco' ) );
		}

		/*
		 * Anything already in the basket for an occasion is taken out
		 * first — this one's, or it would be charged for twice by somebody
		 * who goes to pay, comes back and goes again; and any other
		 * occasion's too.
		 *
		 * A signed-in customer's basket is kept between visits. Somebody
		 * who went to pay for one occasion, left the checkout, and came
		 * back another day to pay for a different one arrived with both in
		 * the basket and was charged for both — and an order turns on the
		 * one occasion it is marked with, so the other was paid for and
		 * never started. One occasion to a checkout; the other is still
		 * there to be paid for from its own review step.
		 */
		self::forget();

		/*
		 * And a campaign left there from an earlier visit. Somebody who
		 * went to pay for a campaign, came away, and is now paying for an
		 * occasion would otherwise find both on the bill.
		 */
		self::forget_campaign();

		/*
		 * Ours, so the shop's guard against buying a bare card lets it by.
		 * Cleared straight after, whatever happens, so nothing later in the
		 * request inherits the exemption.
		 */
		CardsCo_Shop::adding_ours( true );

		try {
			foreach ( $lines as $line ) {
				/* The cap is cleared here, for this product, at the moment it counts. */
				CardsCo_Catalogue::ensure_uncapped( $line['product'] );

				$added = WC()->cart->add_to_cart(
					$line['product'],
					$line['qty'],
					0,
					array(),
					array( self::PROGRAMME => (int) $programme['id'] )
				);

				if ( ! $added ) {
					break;
				}
			}
		} finally {
			CardsCo_Shop::adding_ours( false );
		}

		if ( ! $added ) {
			/* Not half an order: what did go in comes back out. */
			self::forget( (int) $programme['id'] );

			return new WP_Error( 'cardsco_not_added', __( 'Those cards could not be added to the basket.', 'cardsco' ) );
		}

		self::prefill_billing( $programme );

		return wc_get_checkout_url();
	}

	/**
	 * Say what the payment did, and give them a way back.
	 *
	 * WooCommerce's order-received page is a receipt: it says an order was
	 * received and stops there. Somebody who has just turned on an
	 * occasion or scheduled a campaign is left on a page about a purchase,
	 * in the same tab their dashboard was in, with no sign of what
	 * happened to the thing they were setting up and no link back to it.
	 *
	 * Opening the checkout in a new tab would be the other way to solve
	 * it, and a worse one: popup blockers eat it, card payments redirect
	 * away and back, and on a phone a second tab is somewhere people lose
	 * things rather than somewhere they return from. The page they land on
	 * should simply know where they came from.
	 *
	 * @param int $order_id The order just paid for.
	 * @return void
	 */
	public static function after_paying( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return;
		}

		$programme_id = self::linked_id( $order, self::PROGRAMME );
		$campaign_id  = self::linked_id( $order, self::CAMPAIGN );

		if ( ! $programme_id && ! $campaign_id ) {
			return;
		}

		/*
		 * There is always somewhere to go. If no portal page has reported
		 * its address yet — nobody has opened one since this version went
		 * on — the site's front page still beats a dead end.
		 */
		$dash = CardsCo_Shortcodes::page_for( 'cardsco_dashboard' );
		$dash = $dash ? $dash : home_url( '/' );

		if ( $programme_id ) {
			$programme = CardsCo_Programme::get( $programme_id );
			$on        = $programme && 'active' === $programme['status'];
			$title     = $on
				? __( 'Your occasion is on', 'cardsco' )
				: __( 'Thank you — we are setting your occasion up', 'cardsco' );
			$blurb = $on
				? __( 'We will write and post every card from now on. You can change anything at any time.', 'cardsco' )
				: __( 'Payment went through. If anything is still needed we will be in touch.', 'cardsco' );
			$back  = CardsCo_Shortcodes::page_for( 'cardsco_occasions' );
			$label = $back ? __( 'Back to your occasions', 'cardsco' ) : '';
		} else {
			$campaign = CardsCo_Campaign::get( $campaign_id );
			$on       = $campaign && 'draft' !== $campaign['status'];
			$title    = $on
				? __( 'Your campaign is scheduled', 'cardsco' )
				: __( 'Thank you — we are scheduling your campaign', 'cardsco' );
			$blurb = $on && $campaign && ! empty( $campaign['send_date'] )
				? sprintf(
					/* translators: %s: the date the cards arrive. */
					__( 'The cards are written by hand and arrive on %s.', 'cardsco' ),
					date_i18n( get_option( 'date_format' ), strtotime( $campaign['send_date'] ) )
				)
				: __( 'Payment went through. If anything is still needed we will be in touch.', 'cardsco' );
			$back  = CardsCo_Shortcodes::page_for( 'cardsco_campaigns' );
			$label = $back ? __( 'Back to your campaigns', 'cardsco' ) : '';
		}

		include CARDSCO_PATH . 'includes/front/partial-paid.php';
	}

	/**
	 * Put a campaign in the basket and hand back the checkout.
	 *
	 * The same shape as an occasion: the campaign's id rides on the basket
	 * line, survives into the order, and the scheduling happens on the back
	 * of the payment rather than here.
	 *
	 * @param array $campaign The campaign being paid for.
	 * @return string|WP_Error The checkout URL.
	 */
	public static function start_campaign( array $campaign ) {
		if ( ! self::available() ) {
			return new WP_Error( 'cardsco_no_shop', __( 'The shop is not available right now. Try again shortly.', 'cardsco' ) );
		}

		$card_id = (int) $campaign['card_id'];

		if ( ! $card_id ) {
			return new WP_Error( 'cardsco_no_card', __( 'Pick a card before paying.', 'cardsco' ) );
		}

		$quantity = (int) $campaign['recipient_count'];

		if ( $quantity < 1 ) {
			return new WP_Error( 'cardsco_no_people', __( 'There is nobody on this campaign yet.', 'cardsco' ) );
		}

		/*
		 * Twenty-five or more, posted to each person, is arranged with us
		 * and not sold here. The review page does not offer the button;
		 * this is for whoever arrives without it.
		 */
		if ( CardsCo_Campaign::needs_referral( $campaign, $quantity ) ) {
			$cc_refer = CardsCo_Campaign::referral( $campaign );

			return new WP_Error( 'cardsco_referral', $cc_refer['title'] . '. ' . $cc_refer['body'] );
		}

		/*
		 * A design or a message more than this order may carry — a bulk
		 * order's mix left on a campaign that has since become single
		 * cards — is stopped here as well as on the steps themselves.
		 */
		$cc_wrong = CardsCo_Campaign::mix_problems( $campaign );

		if ( $cc_wrong ) {
			return new WP_Error( 'cardsco_mix', (string) reset( $cc_wrong ) );
		}

		if ( CardsCo_Campaign::one_address( $campaign ) && ! CardsCo_Campaign::address_ready( $campaign ) ) {
			return new WP_Error( 'cardsco_no_address', __( 'Add the address the cards should be delivered to, on the first step.', 'cardsco' ) );
		}

		/*
		 * A line for each design. Every product is found before anything
		 * is added, so a design that cannot be bought stops the order
		 * rather than leaving half of it in the basket.
		 */
		$lines = array();

		foreach ( CardsCo_Campaign::order_lines( $campaign, $quantity ) as $line ) {
			$product_id = CardsCo_Catalogue::product_for_order( (int) $line['card'] );

			if ( ! $product_id ) {
				return new WP_Error(
					'cardsco_no_product',
					sprintf(
						/* translators: %s: the name of a card design. */
						__( '“%s” cannot be bought at the moment. Pick another design in its place, or tell us.', 'cardsco' ),
						get_the_title( (int) $line['card'] )
					)
				);
			}

			$lines[] = array( $product_id, (int) $line['qty'] );
		}

		if ( ! $lines ) {
			return new WP_Error( 'cardsco_no_product', __( 'That card cannot be bought at the moment. Pick another, or tell us.', 'cardsco' ) );
		}

		if ( is_null( WC()->cart ) ) {
			return new WP_Error( 'cardsco_no_cart', __( 'The basket is not ready. Try again shortly.', 'cardsco' ) );
		}

		/*
		 * Anything already in the basket for a campaign goes first — this
		 * one's, or somebody who goes to pay, comes back and goes again is
		 * charged for the send twice; and any other's, because an order
		 * carries one campaign: two in one basket were both charged and
		 * only one was scheduled.
		 */
		self::forget_campaign();

		/* The same the other way round: an occasion left in the basket is not part of this. */
		self::forget();

		CardsCo_Shop::adding_ours( true );

		try {
			foreach ( $lines as $line ) {
				/* The cap is cleared here, for this product, at the moment it counts. */
				CardsCo_Catalogue::ensure_uncapped( $line[0] );

				$added = WC()->cart->add_to_cart(
					$line[0],
					$line[1],
					0,
					array(),
					array( self::CAMPAIGN => (int) $campaign['id'] )
				);

				if ( ! $added ) {
					self::forget_campaign();

					return new WP_Error( 'cardsco_not_added', __( 'Those cards could not be added to the basket.', 'cardsco' ) );
				}
			}
		} finally {
			CardsCo_Shop::adding_ours( false );
		}

		self::prefill_billing_for_company( (int) $campaign['company_id'] );

		return wc_get_checkout_url();
	}

	/**
	 * Take a campaign's cards back out of the basket.
	 *
	 * @param int $campaign_id The campaign, or 0 for every campaign.
	 * @return void
	 */
	public static function forget_campaign( $campaign_id = 0 ) {
		if ( ! self::available() || is_null( WC()->cart ) ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( isset( $line[ self::CAMPAIGN ] ) && ( ! $campaign_id || (int) $line[ self::CAMPAIGN ] === (int) $campaign_id ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Schedule a campaign once its order is paid.
	 *
	 * @param int $order_id The order.
	 * @return void
	 */
	public static function campaign_paid( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order || $order->get_meta( self::SCHEDULED ) ) {
			return;
		}

		$campaign_id = self::linked_id( $order, self::CAMPAIGN );

		if ( ! $campaign_id ) {
			return;
		}

		$campaign = CardsCo_Campaign::get( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		if ( 'draft' !== $campaign['status'] ) {
			$order->update_meta_data( self::SCHEDULED, 1 );
			$order->save();
			return;
		}

		$done = CardsCo_Campaign::schedule( $campaign_id );

		/*
		 * The money is already taken, so a refusal cannot go back to
		 * anybody standing in front of a form. It goes on the order, where
		 * whoever fulfils it will see it.
		 */
		if ( is_wp_error( $done ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: why it could not be scheduled. */
					__( 'CardsCo: paid, but the campaign could not be scheduled — %s', 'cardsco' ),
					$done->get_error_message()
				)
			);
			$order->save();
			return;
		}

		$order->update_meta_data( self::SCHEDULED, 1 );
		$order->save();
	}

	/**
	 * Put the company's own details into the checkout, so they are not
	 * typed a second time.
	 *
	 * Everything here comes from the company the signed-in person belongs
	 * to, read server-side. Nothing is taken from the request, so there is
	 * no way to ask the page for somebody else's address, and nothing new
	 * is written anywhere: these are the details they have already given
	 * us, handed back to them on their own screen.
	 *
	 * A field already filled in is left alone. This is a starting point for
	 * somebody who has typed nothing yet, not a correction of what they
	 * chose last time — and a card's billing address is theirs to set.
	 *
	 * @param array $programme The occasion being paid for.
	 * @return void
	 */
	public static function prefill_billing( array $programme ) {
		/*
		 * The address the cards are posted to, but only when they go to the
		 * company itself. On an occasion that posts to each person's home
		 * there is no single address, and none of those are the buyer's.
		 */
		$fallback = array();

		if ( 'company' === $programme['shipping_mode'] ) {
			$fallback = array(
				'lines'    => (string) $programme['ship_address'],
				'postcode' => (string) $programme['ship_postcode'],
				'country'  => (string) $programme['ship_country'],
			);
		}

		self::prefill_billing_for_company( (int) $programme['company_id'], $fallback );
	}

	/**
	 * The same, for a company on its own.
	 *
	 * A campaign has no shipping address of its own — its cards go to the
	 * people on it — so it passes no fallback and fills in from the
	 * company's billing address or not at all.
	 *
	 * @param int   $company_id The company paying.
	 * @param array $fallback   An address to use when the company has saved none.
	 * @return void
	 */
	public static function prefill_billing_for_company( $company_id, array $fallback = array() ) {
		if ( ! is_user_logged_in() || is_null( WC()->customer ) ) {
			return;
		}

		$company_id = (int) $company_id;

		/* Belt and braces: only ever this person's own company. */
		if ( ! $company_id || $company_id !== (int) cardsco_get_user_company_id( get_current_user_id() ) ) {
			return;
		}

		$company = CardsCo_Company::get( $company_id );

		if ( ! $company ) {
			return;
		}

		$customer = WC()->customer;
		$user     = wp_get_current_user();

		$pick = static function ( ...$options ) {
			foreach ( $options as $option ) {
				if ( '' !== trim( (string) $option ) ) {
					return trim( (string) $option );
				}
			}

			return '';
		};

		/* The company's own billing address if somebody has set one. */
		$lines    = array_pad( array(), 5, '' );
		$postcode = '';
		$country  = '';

		if ( '' !== trim( (string) $company['billing_address'] ) ) {
			$lines = array_slice( array_pad( explode( "\n", (string) $company['billing_address'] ), 5, '' ), 0, 5 );
		} elseif ( ! empty( $fallback['lines'] ) ) {
			$lines    = array_slice( array_pad( explode( "\n", (string) $fallback['lines'] ), 5, '' ), 0, 5 );
			$postcode = (string) ( $fallback['postcode'] ?? '' );
			$country  = (string) ( $fallback['country'] ?? '' );
		}

		$name  = $pick( $company['billing_name'], $company['contact_name'], $user->display_name );
		$split = preg_split( '/\s+/', $name, 2 );

		$values = array(
			'first_name' => $pick( $split[0] ?? '' ),
			'last_name'  => $pick( $split[1] ?? '' ),
			'company'    => $pick( $company['name'] ),
			'email'      => $pick( $company['billing_email'], $company['contact_email'], $user->user_email ),
			'phone'      => $pick( $company['contact_phone'] ),
			'address_1'  => $pick( $lines[0] ),
			'address_2'  => $pick( trim( $lines[1] . ' ' . $lines[2] ) ),
			'city'       => $pick( $lines[3] ),
			'state'      => $pick( $lines[4] ),
			'postcode'   => $pick( $postcode ),
			'country'    => $pick( $country ),
		);

		$wrote = false;

		foreach ( $values as $field => $value ) {
			$read = array( $customer, 'get_billing_' . $field );
			$save = array( $customer, 'set_billing_' . $field );

			if ( '' === $value || ! is_callable( $read ) || ! is_callable( $save ) ) {
				continue;
			}

			if ( '' !== trim( (string) call_user_func( $read ) ) ) {
				continue;
			}

			call_user_func( $save, $value );
			$wrote = true;
		}

		if ( $wrote ) {
			$customer->save();
		}
	}

	/**
	 * Take an occasion's cards back out of the basket.
	 *
	 * @param int $programme_id Programme, or nought for every occasion's.
	 * @return void
	 */
	public static function forget( $programme_id = 0 ) {
		if ( ! self::available() || is_null( WC()->cart ) ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( ! isset( $line[ self::PROGRAMME ] ) ) {
				continue;
			}

			if ( ! $programme_id || (int) $line[ self::PROGRAMME ] === (int) $programme_id ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Charge the right price for the quantity.
	 *
	 * The product carries the single-card price. Twenty-five or more is
	 * £1.99 each, and the break applies to the whole line rather than to
	 * the cards past the twenty-fifth.
	 *
	 * @param object $cart WC_Cart.
	 * @return void
	 */
	public static function price_the_cart( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		/*
		 * How many cards each occasion has in the basket altogether.
		 *
		 * An occasion with several designs is several lines, and the rate
		 * belongs to the order, not to the line: ten of one design and
		 * twenty-four of another are thirty-four cards at the bulk rate.
		 * Priced line by line they would be two small orders at the
		 * single rate — more designs quietly costing more, which is the
		 * one thing they must not do.
		 */
		$together = array();

		$as_one = array();

		foreach ( $cart->get_cart() as $line ) {
			if ( isset( $line[ self::PROGRAMME ] ) ) {
				$of              = (int) $line[ self::PROGRAMME ];
				$together[ $of ] = ( $together[ $of ] ?? 0 ) + (int) $line['quantity'];
			}

			/* A campaign's designs are one order too. */
			if ( isset( $line[ self::CAMPAIGN ] ) ) {
				$of            = (int) $line[ self::CAMPAIGN ];
				$as_one[ $of ] = ( $as_one[ $of ] ?? 0 ) + (int) $line['quantity'];
			}
		}

		foreach ( $cart->get_cart() as $line ) {
			if ( empty( $line['data'] ) || ! is_object( $line['data'] ) ) {
				continue;
			}

			$card_id = (int) get_post_meta( $line['data']->get_id(), CardsCo_Catalogue::CARD, true );

			if ( ! $card_id ) {
				continue;
			}

			/*
			 * Quantity decides the rate — postage is inside the price, so
			 * where the cards go does not change it. The company id comes
			 * from the occasion so a company on its own price list gets
			 * its own rate.
			 */
			$programme = isset( $line[ self::PROGRAMME ] )
				? CardsCo_Programme::get( (int) $line[ self::PROGRAMME ] )
				: null;

			$order_size = isset( $line[ self::PROGRAMME ] )
				? (int) $together[ (int) $line[ self::PROGRAMME ] ]
				: ( isset( $line[ self::CAMPAIGN ] ) ? (int) $as_one[ (int) $line[ self::CAMPAIGN ] ] : (int) $line['quantity'] );

			/*
			 * A campaign's line is priced with the campaign's company, the
			 * same sum its review page shows. The company used to be left
			 * out here, so a company on its own price list saw its own rate
			 * on the review and paid the standard one at the till.
			 */
			$campaign = isset( $line[ self::CAMPAIGN ] )
				? CardsCo_Campaign::get( (int) $line[ self::CAMPAIGN ] )
				: null;

			$line['data']->set_price(
				CardsCo_Card::price_for(
					$card_id,
					$order_size,
					$programme ? (int) $programme['company_id'] : ( $campaign ? (int) $campaign['company_id'] : 0 )
				)
			);
		}
	}

	/**
	 * Carry the occasion from the basket onto the order's line.
	 *
	 * @param object $item          WC_Order_Item_Product.
	 * @param string $key           Cart item key.
	 * @param array  $line          Cart line.
	 * @param object $order         WC_Order.
	 * @return void
	 */
	public static function keep_on_line_item( $item, $key, $line, $order ) {
		if ( isset( $line[ self::PROGRAMME ] ) ) {
			$item->add_meta_data( self::PROGRAMME, (int) $line[ self::PROGRAMME ], true );
		}

		if ( isset( $line[ self::CAMPAIGN ] ) ) {
			$item->add_meta_data( self::CAMPAIGN, (int) $line[ self::CAMPAIGN ], true );
		}

		/*
		 * Whose order this is, written here because this hook fires on both
		 * checkouts.
		 *
		 * It was only ever set in keep_on_order(), on
		 * woocommerce_checkout_create_order, which the block checkout — the
		 * one this shop uses — never fires. The Orders screen finds a
		 * company's orders by this one piece of meta, so every bulk order
		 * placed through the shop was invisible to the company that placed
		 * it: paid for, fulfilled, and absent from their own list.
		 *
		 * Same shape as the campaign meta beside it, and the same reason:
		 * the only hooks worth relying on here are the ones the Store API
		 * calls too.
		 */
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		if ( (int) $order->get_meta( '_cardsco_company_id' ) ) {
			return;
		}

		$company_id = (int) CardsCo_Access::company_id();

		if ( ! $company_id && method_exists( $order, 'get_customer_id' ) ) {
			$company_id = (int) cardsco_get_user_company_id( (int) $order->get_customer_id() );
		}

		if ( $company_id ) {
			$order->update_meta_data( '_cardsco_company_id', $company_id );
		}
	}

	/**
	 * Mark the order with the occasion and the company.
	 *
	 * The company is what the Orders screen reads to show somebody their
	 * own orders, so it is set for every order a signed-in company places,
	 * not only those that came from the wizard.
	 *
	 * @param object $order WC_Order.
	 * @param array  $data  Posted checkout data.
	 * @return void
	 */
	public static function keep_on_order( $order, $data ) {
		$company_id = CardsCo_Access::company_id();

		if ( $company_id ) {
			$order->update_meta_data( '_cardsco_company_id', (int) $company_id );
		}

		if ( is_null( WC()->cart ) ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			if ( ! empty( $line[ self::PROGRAMME ] ) ) {
				$order->update_meta_data( self::PROGRAMME, (int) $line[ self::PROGRAMME ] );
			}

			if ( ! empty( $line[ self::CAMPAIGN ] ) ) {
				$order->update_meta_data( self::CAMPAIGN, (int) $line[ self::CAMPAIGN ] );
			}
		}
	}

	/**
	 * The occasion or campaign an order is paying for.
	 *
	 * Looks at the order first, then at its lines. The order-level note is
	 * written by woocommerce_checkout_create_order, which the block
	 * checkout — the one this shop actually uses — never fires: its Store
	 * API builds the order itself and calls WooCommerce's line-item code
	 * directly, so the line meta arrives and the order meta does not.
	 *
	 * Reading both means it does not matter which checkout took the money.
	 * Before this, an order placed through the block checkout carried the
	 * campaign on its line and nothing on the order, so the campaign was
	 * paid for and never scheduled, and the occasion paid for and never
	 * turned on, with nothing to say why.
	 *
	 * @param object $order WC_Order.
	 * @param string $key   Which meta to look for.
	 * @return int
	 */
	protected static function linked_id( $order, $key ) {
		$id = (int) $order->get_meta( $key );

		if ( $id ) {
			return $id;
		}

		if ( ! method_exists( $order, 'get_items' ) ) {
			return 0;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$id = (int) $item->get_meta( $key );

			if ( $id ) {
				/* Put it where it should have been, so later reads are cheap. */
				$order->update_meta_data( $key, $id );
				$order->save();

				return $id;
			}
		}

		return 0;
	}

	/**
	 * The money arrived: turn the occasion on.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function paid( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return;
		}

		/* Processing and completed both fire; the second must do nothing. */
		if ( $order->get_meta( self::ACTIVATED ) ) {
			return;
		}

		$programme_id = self::linked_id( $order, self::PROGRAMME );

		if ( ! $programme_id ) {
			return;
		}

		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			return;
		}

		/*
		 * A recurring occasion is bought a year at a time, so a payment
		 * against one already running is the next year — not a duplicate
		 * to be ignored. This returned early on any active programme,
		 * which would have taken a renewal payment and moved the renewal
		 * date not one day.
		 */
		/*
		 * What one card cost on this payment, kept so the next renewal can
		 * say truthfully whether the price has moved. Worked out the way
		 * the till worked it out a moment ago, before anything below
		 * changes the occasion.
		 */
		$paid_rate = CardsCo_Programme::rate( $programme, self::quantity( $programme ) );

		if ( in_array( (string) $programme['status'], array( 'active', 'paused' ), true ) ) {
			CardsCo_Renewal::paid( $programme_id );
			CardsCo_Programme::update( $programme_id, array( 'paid_rate' => $paid_rate ) );

			$order->update_meta_data( self::ACTIVATED, 1 );
			$order->add_order_note( __( 'CardsCo: renewed for another year.', 'cardsco' ) );
			$order->save();

			cardsco_log(
				array(
					'company_id'  => (int) $programme['company_id'],
					'object_type' => 'programme',
					'object_id'   => $programme_id,
					'action'      => 'programme_renewed',
					'summary'     => sprintf(
						/* translators: %s: an order number. */
						__( 'Renewed on order %s', 'cardsco' ),
						$order->get_order_number()
					),
				)
			);

			return;
		}

		$done = CardsCo_Programme::activate( $programme_id );

		/*
		 * The money is already taken, so a refusal here cannot be handed
		 * back to anybody standing in front of a form. It is written down
		 * instead, on the order, where whoever fulfils it will see it.
		 */
		if ( is_wp_error( $done ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: why it could not be turned on. */
					__( 'CardsCo: paid, but the occasion could not be turned on — %s', 'cardsco' ),
					$done->get_error_message()
				)
			);
			$order->save();
			return;
		}

		/* The first payment is what starts the year running. */
		CardsCo_Renewal::paid( $programme_id );
		CardsCo_Programme::update( $programme_id, array( 'paid_rate' => $paid_rate ) );

		/*
		 * Who gets which design and which message, settled now that it is
		 * paid for. It would be settled anyway the first time a box is
		 * filled; doing it here means it is already true by the time
		 * anybody looks.
		 */
		$running = CardsCo_Programme::get( $programme_id );

		if ( $running ) {
			CardsCo_Programme::assign( $running );
		}

		$order->update_meta_data( self::ACTIVATED, 1 );
		$order->add_order_note( __( 'CardsCo: the occasion is now running.', 'cardsco' ) );
		$order->save();

		cardsco_log(
			array(
				'company_id'  => (int) $programme['company_id'],
				'object_type' => 'programme',
				'object_id'   => $programme_id,
				'action'      => 'programme_paid',
				'summary'     => sprintf(
					/* translators: %s: an order number. */
					__( 'Paid on order %s', 'cardsco' ),
					$order->get_order_number()
				),
			)
		);
	}
}
