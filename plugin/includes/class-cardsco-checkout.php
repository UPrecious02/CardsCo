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
		return count( CardsCo_Programme::recipient_ids( (int) $programme['id'] ) );
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

		$card_id = (int) $programme['card_id'];

		if ( ! $card_id ) {
			return new WP_Error( 'cardsco_no_card', __( 'Pick a card before paying.', 'cardsco' ) );
		}

		$quantity = self::quantity( $programme );

		if ( $quantity < 1 ) {
			return new WP_Error( 'cardsco_no_people', __( 'There is nobody on this occasion yet.', 'cardsco' ) );
		}

		$product_id = CardsCo_Catalogue::product_for_card( $card_id );

		/*
		 * product_for_card() only reads the link; sync() is what makes one.
		 * A card normally gets its product when it is saved, so this is the
		 * card that was already in the catalogue before the shop existed,
		 * or one whose product somebody deleted. Making it here beats
		 * telling a customer with their card chosen that it cannot be
		 * bought.
		 */
		if ( ! $product_id ) {
			$made = CardsCo_Catalogue::sync( $card_id );

			if ( ! is_wp_error( $made ) ) {
				$product_id = (int) $made;
			}
		}

		if ( ! $product_id ) {
			return new WP_Error( 'cardsco_no_product', __( 'That card cannot be bought at the moment. Pick another, or tell us.', 'cardsco' ) );
		}

		if ( is_null( WC()->cart ) ) {
			return new WP_Error( 'cardsco_no_cart', __( 'The basket is not ready. Try again shortly.', 'cardsco' ) );
		}

		/*
		 * Anything already in the basket for this same occasion is taken
		 * out first. Otherwise somebody who goes to pay, comes back, and
		 * goes to pay again is charged for two years of cards.
		 */
		self::forget( (int) $programme['id'] );

		$added = WC()->cart->add_to_cart(
			$product_id,
			$quantity,
			0,
			array(),
			array( self::PROGRAMME => (int) $programme['id'] )
		);

		if ( ! $added ) {
			return new WP_Error( 'cardsco_not_added', __( 'Those cards could not be added to the basket.', 'cardsco' ) );
		}

		self::prefill_billing( $programme );

		return wc_get_checkout_url();
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
		if ( ! is_user_logged_in() || is_null( WC()->customer ) ) {
			return;
		}

		$company_id = (int) $programme['company_id'];

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

		/*
		 * The company's own billing address if somebody has set one. If not,
		 * the address the cards are posted to — but only when they go to the
		 * company itself. On an occasion that posts to each person's home
		 * there is no single address, and none of those are the buyer's.
		 */
		$lines    = array_pad( array(), 5, '' );
		$postcode = '';
		$country  = '';

		if ( '' !== trim( (string) $company['billing_address'] ) ) {
			$lines = array_slice( array_pad( explode( "\n", (string) $company['billing_address'] ), 5, '' ), 0, 5 );
		} elseif ( 'company' === $programme['shipping_mode'] ) {
			$lines    = array_slice( array_pad( explode( "\n", (string) $programme['ship_address'] ), 5, '' ), 0, 5 );
			$postcode = (string) $programme['ship_postcode'];
			$country  = (string) $programme['ship_country'];
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
	 * @param int $programme_id Programme.
	 * @return void
	 */
	public static function forget( $programme_id ) {
		if ( ! self::available() || is_null( WC()->cart ) ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( isset( $line[ self::PROGRAMME ] ) && (int) $line[ self::PROGRAMME ] === (int) $programme_id ) {
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

		foreach ( $cart->get_cart() as $line ) {
			if ( empty( $line['data'] ) || ! is_object( $line['data'] ) ) {
				continue;
			}

			$card_id = (int) get_post_meta( $line['data']->get_id(), CardsCo_Catalogue::CARD, true );

			if ( ! $card_id ) {
				continue;
			}

			$line['data']->set_price( CardsCo_Card::price_for( $card_id, (int) $line['quantity'] ) );
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
				break;
			}
		}
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

		$programme_id = (int) $order->get_meta( self::PROGRAMME );

		if ( ! $programme_id ) {
			return;
		}

		$programme = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			return;
		}

		if ( 'active' === $programme['status'] ) {
			$order->update_meta_data( self::ACTIVATED, 1 );
			$order->save();
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
