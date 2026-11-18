<?php
/**
 * From a finished occasion to a paid one.
 *
 * The thing this is really about is when the occasion turns on. It must
 * not be when somebody presses "Go to payment" — a card gets declined, a
 * 3-D Secure step is abandoned, a tab is closed on the Stripe page — and
 * if it were, cards would go out to people nobody had paid for. It must be
 * when the money actually arrives, and exactly once however many times
 * WooCommerce says so.
 *
 * Read the caveat at the top of stub-woo-shop.php before trusting any of
 * this: WooCommerce cannot be installed here, so the shop below is my
 * model of it. These checks show our logic is right. They cannot show that
 * real WooCommerce behaves as modelled, and that still has to be exercised
 * once against a real shop and a real card.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/occasions/new/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';
require __DIR__ . '/stub-woo-shop.php';

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-58s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install( true );
CardsCo_Checkout::init();

$uid = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid = $u->ID; break; }
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );

/* A card that can be bought. */
$card = wp_insert_post(
	array(
		'post_type'   => CardsCo_Card::POST_TYPE,
		'post_title'  => 'Linocut birthday',
		'post_status' => 'publish',
	)
);
/* A card is only sold once somebody has ticked that it may be. */
update_post_meta( $card, CardsCo_Catalogue::SELLABLE, 1 );

sect( 'the card has something to sell' );
ck( 'nothing is linked to it yet', CardsCo_Catalogue::product_for_card( $card ), 0 );

/* An occasion, ready to pay for, with a given number of people. */
function ready_occasion( $cid, $card, $people ) {
	$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );

	/*
	 * Everything the earlier steps would have filled in. Without it the
	 * occasion cannot be turned on, and the suite would be exercising the
	 * refusal rather than the payment.
	 */
	CardsCo_Programme::update(
		(int) $pid,
		array(
			'setup_step'       => 7,
			'card_id'          => (int) $card,
			'message_override' => 'Happy birthday {first name}, from all of us.',
			'signature'        => 'The team',
			'shipping_mode'    => 'individual',
			'timing_mode'      => 'monthly_box',
			'starts_on'        => gmdate( 'Y-m-d', strtotime( '+2 weeks', (int) current_time( 'timestamp' ) ) ),
		)
	);

	$ids = array();

	global $wpdb;

	$types = CardsCo_Category::types( 'employee-birthdays', $cid );
	$type  = (int) ( is_array( $types ) ? reset( $types ) : $types );

	for ( $i = 0; $i < $people; $i++ ) {
		$rid = (int) CardsCo_Recipient::create(
			array(
				'company_id' => $cid, 'type' => 'employee',
				'first_name' => 'Person' . $i, 'last_name' => 'Test', 'source' => 'manual',
			)
		);

		/*
		 * A birthday occasion will not turn on until everybody on it has a
		 * date, which is what step 2 is for. Without this the fixture is a
		 * programme that could never be activated, and the suite would be
		 * testing the refusal rather than the payment.
		 */
		$wpdb->insert(
			cardsco_table( 'occasions' ),
			array(
				'company_id'       => $cid,
				'recipient_id'     => $rid,
				'occasion_type_id' => $type,
				'event_month'      => ( $i % 12 ) + 1,
				'event_day'        => ( $i % 27 ) + 1,
				'recurrence'       => 'annual',
				'is_active'        => 1,
				'created_at'       => cardsco_now(),
			)
		);

		$ids[] = $rid;
	}

	CardsCo_Programme::add_recipients( (int) $pid, $ids );

	return array( (int) $pid, $ids );
}

sect( 'the quantity is a card per person, for the year' );
list( $small, $small_ids ) = ready_occasion( $cid, $card, 4 );
ck( 'four people, four cards', CardsCo_Checkout::quantity( CardsCo_Programme::get( $small ) ), 4 );

list( $big, $big_ids ) = ready_occasion( $cid, $card, 30 );
ck( 'thirty people, thirty cards', CardsCo_Checkout::quantity( CardsCo_Programme::get( $big ) ), 30 );

sect( 'going to pay fills the basket' );
WC()->cart->empty_cart();
$to = CardsCo_Checkout::start( CardsCo_Programme::get( $small ) );
ck( 'it hands back the checkout', is_string( $to ) && str_contains( $to, 'checkout' ) );
/*
 * And it made the product on the way, rather than refusing. That is the
 * card that was already in the catalogue before the shop existed.
 */
$product = CardsCo_Catalogue::product_for_card( $card );
ck( 'the card now has a product', $product > 0 );
ck( 'with one line in the basket', count( WC()->cart->get_cart() ), 1 );
$line = current( WC()->cart->get_cart() );
ck( 'of four cards', (int) $line['quantity'], 4 );
ck( 'carrying the occasion', (int) $line[ CardsCo_Checkout::PROGRAMME ], $small );

sect( 'and the occasion is NOT on yet' );
/*
 * The whole point. Somebody has been sent to a payment page; nothing has
 * been paid.
 */
ck( 'still a draft', CardsCo_Programme::get( $small )['status'], 'draft' );

sect( 'going to pay twice does not buy two years' );
CardsCo_Checkout::start( CardsCo_Programme::get( $small ) );
CardsCo_Checkout::start( CardsCo_Programme::get( $small ) );
ck( 'still one line', count( WC()->cart->get_cart() ), 1 );
ck( 'still four cards', (int) current( WC()->cart->get_cart() )['quantity'], 4 );

sect( 'the price follows the quantity' );
WC()->cart->empty_cart();
CardsCo_Checkout::start( CardsCo_Programme::get( $small ) );
$four = WC()->cart->total();
ck( 'four cards at £3.60', number_format( $four, 2 ), '14.40' );

WC()->cart->empty_cart();
CardsCo_Checkout::start( CardsCo_Programme::get( $big ) );
$thirty = WC()->cart->total();
/* 25 at £3.60 plus 5 at £1.99 — the break is on the cards past the limit. */
ck( 'thirty cards, with five of them at the bulk rate', number_format( $thirty, 2 ), '99.95' );
ck( 'which is cheaper per card', ( $thirty / 30 ) < ( $four / 4 ) );
ck( 'and the basket matches the price table exactly', number_format( $thirty, 2, '.', '' ), CardsCo_Card::price_total( (int) $card, 30 ) );

sect( 'the checkout is filled in from the company' );
/*
 * The details are already ours; making somebody type their address a
 * second time to pay for cards they just set up is the kind of friction
 * that loses the sale.
 */
CardsCo_Company::update(
	$cid,
	array(
		'name'            => 'Acme Widgets Ltd',
		'billing_name'    => 'Precious Triumph',
		'billing_email'   => 'billing@acme.test',
		'contact_phone'   => '020 7946 0000',
		'billing_address' => "Unit 4, Mill Yard\nBack Lane\n\nLeeds\nWest Yorkshire",
	)
);
CardsCo_Programme::update(
	$small,
	array(
		'shipping_mode' => 'company',
		'ship_address'  => "9 Somewhere Depot\n\n\nHull\nEast Riding",
		'ship_postcode' => 'HU1 3DZ',
		'ship_country'  => 'GB',
	)
);

$fresh = static function () use ( $uid ) {
	foreach ( WC_Customer::FIELDS as $f ) { delete_user_meta( $uid, 'billing_' . $f ); }
	WC()->customer = new WC_Customer( $uid );
	return WC()->customer;
};

$cc_b = $fresh();
CardsCo_Checkout::prefill_billing( CardsCo_Programme::get( $small ) );

ck( 'the first name is there', $cc_b->get_billing_first_name(), 'Precious' );
ck( 'and the last',            $cc_b->get_billing_last_name(), 'Triumph' );
ck( 'the company',             $cc_b->get_billing_company(), 'Acme Widgets Ltd' );
ck( 'the email',               $cc_b->get_billing_email(), 'billing@acme.test' );
ck( 'the phone',               $cc_b->get_billing_phone(), '020 7946 0000' );
ck( 'the first address line',  $cc_b->get_billing_address_1(), 'Unit 4, Mill Yard' );
ck( 'the second, joined up',   $cc_b->get_billing_address_2(), 'Back Lane' );
ck( 'the town',                $cc_b->get_billing_city(), 'Leeds' );
ck( 'and the county',          $cc_b->get_billing_state(), 'West Yorkshire' );

sect( 'the company’s own billing address wins over where cards are posted' );
ck( 'not the depot the cards go to', $cc_b->get_billing_address_1() !== '9 Somewhere Depot' );
ck( 'nor its town',                  $cc_b->get_billing_city(), 'Leeds' );

sect( 'with no billing address saved, the company address is used' );
CardsCo_Company::update( $cid, array( 'billing_address' => '' ) );
$cc_b = $fresh();
CardsCo_Checkout::prefill_billing( CardsCo_Programme::get( $small ) );
ck( 'the address the cards go to', $cc_b->get_billing_address_1(), '9 Somewhere Depot' );
ck( 'its town',                    $cc_b->get_billing_city(), 'Hull' );
ck( 'its postcode',                $cc_b->get_billing_postcode(), 'HU1 3DZ' );
ck( 'and its country',             $cc_b->get_billing_country(), 'GB' );

sect( 'but it never overwrites what they typed themselves' );
$cc_b = $fresh();
$cc_b->set_billing_address_1( '12 Somewhere Else' );
$cc_b->set_billing_city( 'Manchester' );
CardsCo_Checkout::prefill_billing( CardsCo_Programme::get( $small ) );
ck( 'their own address stands',  $cc_b->get_billing_address_1(), '12 Somewhere Else' );
ck( 'and their own town',        $cc_b->get_billing_city(), 'Manchester' );
ck( 'while a blank field is still filled', $cc_b->get_billing_postcode(), 'HU1 3DZ' );

sect( 'cards posted to people’s homes fill in no address' );
/*
 * On an occasion that posts to each person individually there is no one
 * address, and none of those addresses belong to the buyer. Putting a
 * recipient's address in the billing block would be wrong, and the card
 * would likely be declined for it. With no company billing address saved
 * either, the address block is left for them to fill in.
 */
list( $cc_home ) = ready_occasion( $cid, $card, 2 );
CardsCo_Programme::update(
	$cc_home,
	array(
		'shipping_mode' => 'individual',
		'ship_address'  => "7 Recipient Road\n\n\nSheffield\nSouth Yorkshire",
		'ship_postcode' => 'S1 2HH',
	)
);
$cc_b = $fresh();
CardsCo_Checkout::prefill_billing( CardsCo_Programme::get( $cc_home ) );
ck( 'no address line',  $cc_b->get_billing_address_1(), '' );
ck( 'no town',          $cc_b->get_billing_city(), '' );
ck( 'no postcode',      $cc_b->get_billing_postcode(), '' );
ck( 'but the name is still theirs', $cc_b->get_billing_first_name(), 'Precious' );

sect( 'and a guest is given nothing at all' );
$cc_was = get_current_user_id();
wp_set_current_user( 0 );
WC()->customer = new WC_Customer( 0 );
CardsCo_Checkout::prefill_billing( CardsCo_Programme::get( $small ) );
ck( 'nothing is filled for a logged-out visitor', WC()->customer->get_billing_address_1(), '' );
ck( 'and no email either', WC()->customer->get_billing_email(), '' );
wp_set_current_user( $cc_was );
$fresh();

sect( 'paying turns it on' );
WC()->cart->empty_cart();
CardsCo_Checkout::start( CardsCo_Programme::get( $small ) );
$order = cc_stub_checkout( 'processing' );

ck( 'the order knows the occasion', (int) $order->get_meta( CardsCo_Checkout::PROGRAMME ), $small );
ck( 'and the company', (int) $order->get_meta( '_cardsco_company_id' ), $cid );
ck( 'the line knows it too', (int) $order->get_items()[0]->get_meta( CardsCo_Checkout::PROGRAMME ), $small );
ck( 'the occasion is running', CardsCo_Programme::get( $small )['status'], 'active' );
ck( 'and it is noted on the order', (bool) array_filter( $order->get_order_notes(), static function ( $n ) { return str_contains( $n, 'now running' ); } ) );

sect( 'and completing the same order does not do it twice' );
$before = count( $order->get_order_notes() );
ck( 'there is a note to begin with', $before > 0 );
$order->set_status( 'completed' );
ck( 'and completing adds no second one', count( $order->get_order_notes() ), $before );
ck( 'still running', CardsCo_Programme::get( $small )['status'], 'active' );

sect( 'an abandoned checkout leaves the draft alone' );
WC()->cart->empty_cart();
CardsCo_Checkout::start( CardsCo_Programme::get( $big ) );
WC()->cart->empty_cart(); // they closed the tab
ck( 'the occasion is untouched', CardsCo_Programme::get( $big )['status'], 'draft' );

sect( 'if the money lands and the occasion still will not start' );
/*
 * The guard before the checkout should stop this ever happening. If it
 * does happen the money is already taken, so there is nobody in front of a
 * form to tell: it has to be written where whoever fulfils the order will
 * see it, and the occasion must not quietly claim to be running.
 */
list( $broken, $broken_ids ) = ready_occasion( $cid, $card, 2 );
WC()->cart->empty_cart();
CardsCo_Checkout::start( CardsCo_Programme::get( $broken ) );

/* Something the earlier steps needed, taken away after they left to pay. */
CardsCo_Programme::update( (int) $broken, array( 'message_override' => '', 'message_id' => 0 ) );

$bad = cc_stub_checkout( 'processing' );
ck( 'the occasion did not start', CardsCo_Programme::get( $broken )['status'], 'draft' );
ck( 'and the order says why', (bool) array_filter( $bad->get_order_notes(), static function ( $n ) { return str_contains( $n, 'could not be turned on' ); } ) );
ck( 'rather than claiming it worked', (bool) array_filter( $bad->get_order_notes(), static function ( $n ) { return str_contains( $n, 'now running' ); } ), false );

sect( 'an order that pays for nothing is ignored' );
$stray = new CC_Stub_Order();
$stray->set_status( 'processing' );
ck( 'nothing blew up', true );

sect( 'what cannot be paid for' );
$empty = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $empty, array( 'setup_step' => 7, 'card_id' => (int) $card ) );
$out = CardsCo_Checkout::start( CardsCo_Programme::get( $empty ) );
ck( 'an occasion with nobody on it', is_wp_error( $out ) );
ck( 'and says so', is_wp_error( $out ) ? $out->get_error_code() : '', 'cardsco_no_people' );

list( $nocard ) = ready_occasion( $cid, $card, 3 );
CardsCo_Programme::update( (int) $nocard, array( 'card_id' => 0 ) );
$out = CardsCo_Checkout::start( CardsCo_Programme::get( $nocard ) );
ck( 'an occasion with no card picked', is_wp_error( $out ) );
ck( 'and says so', is_wp_error( $out ) ? $out->get_error_code() : '', 'cardsco_no_card' );

sect( 'the paid occasion shows up on the Orders screen' );
$found = CardsCo_Orders::all( $cid, array( 'months' => 0, 'per_page' => 50 ) );
ck( 'there is a bulk order on it', in_array( 'bulk', array_column( $found['rows'], 'kind' ), true ) );

/* Tidy up. */
global $wpdb;
foreach ( array( $small, $big, $empty, $nocard, $broken ) as $p ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $p ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $p ) );
}
foreach ( array_merge( $small_ids, $big_ids, $broken_ids ) as $r ) {
	CardsCo_Recipient::delete( (int) $r );
}
foreach ( get_posts( array( 'post_type' => 'cc_stub_order', 'numberposts' => 50, 'fields' => 'ids' ) ) as $o ) {
	wp_delete_post( $o, true );
}
wp_delete_post( $card, true );
if ( $product ) { wp_delete_post( $product, true ); }

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
