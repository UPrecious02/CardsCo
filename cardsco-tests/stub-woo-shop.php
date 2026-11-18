<?php
/**
 * A stand-in for WooCommerce's basket and orders.
 *
 * Same caveat as stub-woo.php, and it bears repeating because this file
 * covers the part where money changes hands: WooCommerce cannot be
 * installed in this container — downloads.wordpress.org and wpackagist are
 * both unreachable — so this is written from WooCommerce's documented API
 * and is my model of it, not the thing itself.
 *
 * What that means for the suite built on it: it can show that OUR logic is
 * right — the quantity, the price break, that the occasion is carried from
 * basket to order, that paying twice turns the occasion on once — and it
 * cannot show that real WooCommerce calls these methods in this order or
 * returns what is assumed here. A stub written by the same hand as the
 * code agrees with the code by construction. The integration still has to
 * be exercised once on a site that has a real shop and a real card.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	function wc_get_checkout_url() {
		return home_url( '/checkout/' );
	}
}

/*
 * The base stub's product has no price methods — the catalogue sync only
 * ever set a regular price and read it back as meta. The basket needs to
 * be told a price per line, which is how the bulk break is applied, so
 * they are added here rather than in the file that predates any of this.
 */
if ( ! method_exists( 'WC_Product_Simple', 'set_price' ) ) {
	class CC_Stub_Product extends WC_Product_Simple {

		protected $price = null;

		public function set_price( $price ) {
			$this->price = (float) $price;
		}

		public function get_price( $context = 'view' ) {
			if ( null !== $this->price ) {
				return $this->price;
			}

			return (float) get_post_meta( $this->get_id(), '_regular_price', true );
		}
	}
}

if ( ! class_exists( 'CC_Stub_Cart' ) ) {
	/** The basket. */
	class CC_Stub_Cart {

		/** @var array */
		public $lines = array();

		public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $meta = array() ) {
			$product = class_exists( 'CC_Stub_Product' )
				? new CC_Stub_Product( (int) $product_id )
				: new WC_Product_Simple( (int) $product_id );

			if ( ! $product->get_id() ) {
				return false;
			}

			$key = md5( $product_id . wp_json_encode( $meta ) );

			/*
			 * WooCommerce does not replace a line that is already there for
			 * the same product and the same meta — it adds to its quantity.
			 * This stub used to overwrite, which quietly hid a real fault:
			 * going to pay, coming back, and going to pay again charged for
			 * two years of cards, and the suite saw one tidy line either
			 * way.
			 */
			if ( isset( $this->lines[ $key ] ) ) {
				$this->lines[ $key ]['quantity'] += (int) $quantity;
				return $key;
			}

			$this->lines[ $key ] = array_merge(
				(array) $meta,
				array(
					'key'          => $key,
					'product_id'   => (int) $product_id,
					'quantity'     => (int) $quantity,
					'data'         => $product,
				)
			);

			return $key;
		}

		public function get_cart() {
			return $this->lines;
		}

		public function remove_cart_item( $key ) {
			unset( $this->lines[ $key ] );
			return true;
		}

		public function empty_cart() {
			$this->lines = array();
		}

		/** What the basket comes to, after our own price filter has run. */
		public function total() {
			do_action( 'woocommerce_before_calculate_totals', $this );

			$total = 0.0;

			foreach ( $this->lines as $line ) {
				$total += (float) $line['data']->get_price() * (int) $line['quantity'];
			}

			return round( $total, 2 );
		}
	}
}

if ( ! class_exists( 'CC_Stub_Order' ) ) {
	/** An order, kept in a post so its meta is real. */
	class CC_Stub_Order {

		public $id = 0;

		protected $status = 'pending';

		public function __construct( $id = 0 ) {
			$this->id = (int) $id;

			if ( ! $this->id ) {
				$this->id = wp_insert_post(
					array(
						'post_type'   => 'cc_stub_order',
						'post_title'  => 'order',
						'post_status' => 'publish',
					)
				);
			}

			$was = get_post_meta( $this->id, '_status', true );

			if ( $was ) {
				$this->status = $was;
			}
		}

		public function get_id() { return $this->id; }
		public function get_order_number() { return (string) $this->id; }
		public function get_status() { return $this->status; }
		public function get_currency() { return 'GBP'; }
		public function get_total() { return (float) get_post_meta( $this->id, '_total', true ); }
		public function get_shipping_city() { return ''; }
		public function get_formatted_billing_full_name() { return ''; }
		public function get_date_created() { return null; }
		public function get_items() { return (array) get_post_meta( $this->id, '_items', true ); }

		/* The key WooCommerce puts in the thank-you address; it is what
		   proves somebody placed the order they are claiming. */
		public function get_order_key() {
			$key = get_post_meta( $this->id, '_order_key', true );

			if ( ! $key ) {
				$key = 'wc_order_' . wp_generate_password( 13, false );
				update_post_meta( $this->id, '_order_key', $key );
			}

			return $key;
		}

		public function get_billing_email() { return (string) get_post_meta( $this->id, '_billing_email', true ); }
		public function get_billing_first_name() { return (string) get_post_meta( $this->id, '_billing_first', true ); }
		public function get_billing_last_name() { return (string) get_post_meta( $this->id, '_billing_last', true ); }
		public function set_customer_id( $id ) { update_post_meta( $this->id, '_customer_id', (int) $id ); }
		public function get_customer_id() { return (int) get_post_meta( $this->id, '_customer_id', true ); }
		public function get_checkout_order_received_url() { return home_url( '/checkout/order-received/' . $this->id . '/' ); }

		public function get_meta( $key ) { return get_post_meta( $this->id, $key, true ); }
		public function update_meta_data( $key, $value ) { update_post_meta( $this->id, $key, $value ); }
		/* Kept in the database, as WooCommerce keeps them: a note added by
		   one handler has to be readable from another object for the same
		   order, which is exactly what happens on payment. */
		public function add_order_note( $note ) {
			$notes   = (array) get_post_meta( $this->id, '_notes', true );
			$notes[] = (string) $note;
			update_post_meta( $this->id, '_notes', $notes );
		}

		public function get_order_notes() {
			return (array) get_post_meta( $this->id, '_notes', true );
		}
		public function save() { update_post_meta( $this->id, '_status', $this->status ); return $this->id; }

		/** Move the order along, firing what WooCommerce would fire. */
		public function set_status( $status ) {
			$this->status = (string) $status;
			$this->save();
			do_action( 'woocommerce_order_status_' . $status, $this->id );
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		if ( is_object( $id ) ) {
			return $id;
		}

		return get_post( (int) $id ) ? new CC_Stub_Order( (int) $id ) : false;
	}
}

if ( ! class_exists( 'CC_Stub_Session' ) ) {
	/**
	 * A session, kept in a transient against a cookie.
	 *
	 * Backed by storage rather than a static array so it survives between
	 * requests — the sending flow spreads one card over four of them, and
	 * a session that forgets on every request would make the whole thing
	 * look broken for the wrong reason.
	 */
	class CC_Stub_Session {

		protected $id = '';

		public function __construct() {
			$cookie = 'cc_stub_session';

			if ( ! empty( $_COOKIE[ $cookie ] ) ) {
				$this->id = sanitize_key( wp_unslash( $_COOKIE[ $cookie ] ) );
			}

			if ( ! $this->id ) {
				$this->id = wp_generate_password( 16, false );

				if ( ! headers_sent() ) {
					setcookie( $cookie, $this->id, time() + DAY_IN_SECONDS, '/' );
				}

				$_COOKIE[ $cookie ] = $this->id;
			}
		}

		protected function key() {
			return 'cc_stub_sess_' . $this->id;
		}

		public function get( $what, $default = null ) {
			$all = (array) get_transient( $this->key() );

			return array_key_exists( $what, $all ) ? $all[ $what ] : $default;
		}

		public function set( $what, $value ) {
			$all = (array) get_transient( $this->key() );

			if ( null === $value ) {
				unset( $all[ $what ] );
			} else {
				$all[ $what ] = $value;
			}

			set_transient( $this->key(), $all, DAY_IN_SECONDS );
		}

		public function set_customer_session_cookie( $set ) {
			/* The real one writes a cookie here; this already has. */
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors WooCommerce's own name.
		static $wc = null;

		if ( null === $wc ) {
			$wc           = new stdClass();
			$wc->cart     = new CC_Stub_Cart();
			$wc->session  = new CC_Stub_Session();
			$wc->customer = new WC_Customer( get_current_user_id() );
		}

		return $wc;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args = array() ) {
		$found = get_posts(
			array(
				'post_type'   => 'cc_stub_order',
				'numberposts' => (int) ( $args['limit'] ?? 50 ),
				'meta_key'    => $args['meta_key'] ?? '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $args['meta_value'] ?? '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ids',
			)
		);

		return array_map( static function ( $id ) { return new CC_Stub_Order( $id ); }, $found );
	}
}

/**
 * Take a basket through to a paid order, the way a checkout would.
 *
 * The order of these calls is taken from WooCommerce's checkout: the order
 * is created, each basket line becomes an order line, then the payment
 * moves the status on. Getting that order wrong here would hide a bug
 * rather than find one, which is the risk this whole file carries.
 *
 * @param string $status What the payment leaves the order as.
 * @return CC_Stub_Order
 */
function cc_stub_checkout( $status = 'processing' ) {
	$cart  = WC()->cart;
	$order = new CC_Stub_Order();

	$cart->total();

	do_action( 'woocommerce_checkout_create_order', $order, array() );

	$items = array();

	foreach ( $cart->get_cart() as $key => $line ) {
		$item = new CC_Stub_Order_Item( $line );
		do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $line, $order );
		$items[] = $item;
	}

	update_post_meta( $order->get_id(), '_items', $items );
	update_post_meta( $order->get_id(), '_total', $cart->total() );

	$cart->empty_cart();
	$order->set_status( $status );

	return $order;
}

if ( ! class_exists( 'CC_Stub_Order_Item' ) ) {
	/** One line on an order. */
	/**
	 * The customer, as far as the billing block is concerned.
	 *
	 * Modelled on WooCommerce's own: a signed-in customer's billing fields
	 * live in user meta under the same keys WooCommerce uses, they are read
	 * on construction, and nothing is written until save() is called. A
	 * logged-out visitor keeps theirs on the object and nowhere else.
	 *
	 * This is a model of WooCommerce, not WooCommerce. If a test here
	 * passes and the real shop disagrees, the stub is what is wrong.
	 */
	class WC_Customer {
		/** @var int */
		protected $id = 0;

		/** @var array */
		protected $billing = array();

		/** The fields WooCommerce keeps in the billing block. */
		const FIELDS = array(
			'first_name',
			'last_name',
			'company',
			'email',
			'phone',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'country',
		);

		public function __construct( $user_id = 0 ) {
			$this->id = absint( $user_id );

			foreach ( self::FIELDS as $field ) {
				$this->billing[ $field ] = $this->id
					? (string) get_user_meta( $this->id, 'billing_' . $field, true )
					: '';
			}
		}

		public function get_id() {
			return $this->id;
		}

		public function __call( $name, $args ) {
			if ( 0 === strpos( $name, 'get_billing_' ) ) {
				$field = substr( $name, 12 );

				return in_array( $field, self::FIELDS, true ) ? $this->billing[ $field ] : '';
			}

			if ( 0 === strpos( $name, 'set_billing_' ) ) {
				$field = substr( $name, 12 );

				if ( in_array( $field, self::FIELDS, true ) ) {
					$this->billing[ $field ] = (string) ( $args[0] ?? '' );
				}

				return null;
			}

			return null;
		}

		public function save() {
			if ( ! $this->id ) {
				return;
			}

			foreach ( self::FIELDS as $field ) {
				update_user_meta( $this->id, 'billing_' . $field, $this->billing[ $field ] );
			}
		}
	}

	class CC_Stub_Order_Item {

		public $meta = array();
		protected $line;

		public function __construct( $line ) { $this->line = $line; }

		public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function get_quantity() { return (int) $this->line['quantity']; }
		public function get_name() { return get_the_title( (int) $this->line['product_id'] ); }
	}
}
