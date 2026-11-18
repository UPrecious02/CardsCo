<?php
/**
 * Sending one card, with no account.
 *
 * The occasion wizard keeps drafts in a table scoped to a company. This
 * flow is for somebody who has neither, so the half-written card lives in
 * the WooCommerce session and moves onto the cart line at payment, where
 * it survives into the order. If that carrying fails, somebody is charged
 * for a card nobody can write: the recipient, the address and the words
 * are the whole product.
 *
 * Same caveat as the checkout suite — WooCommerce cannot be installed
 * here, so the basket, the session and the order below are a model of it.
 * These checks show our logic holds. The flow still has to be walked once
 * on a real shop.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/send/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';
require __DIR__ . '/stub-woo-shop.php';

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-56s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install( true );
CardsCo_Checkout::init();

/* Nobody is signed in. That is the point of this flow. */
wp_set_current_user( 0 );

sect( 'this works for somebody with no account at all' );
ck( 'not signed in', is_user_logged_in(), false );
ck( 'and has no company', CardsCo_Access::company_id(), 0 );
ck( 'the flow is still available', CardsCo_Send::available() );

$card = wp_insert_post(
	array(
		'post_type'   => CardsCo_Card::POST_TYPE,
		'post_title'  => 'Season’s greetings',
		'post_status' => 'publish',
	)
);
update_post_meta( $card, CardsCo_Catalogue::SELLABLE, 1 );
CardsCo_Catalogue::sync( $card );

class CCSendWent extends Exception {}
add_filter( 'wp_redirect', function ( $to ) { throw new CCSendWent( $to ); }, 1 );

function send_post( array $data ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(
		array( 'cardsco_send_action' => 'step', '_cardsco_nonce' => wp_create_nonce( 'cardsco_send' ) ),
		$data
	);
	$_REQUEST = $_POST;

	try { CardsCo_Send::handle(); }
	catch ( CCSendWent $w ) { return $w->getMessage(); }
	catch ( Throwable $e ) { return 'THREW: ' . $e->getMessage(); }
	finally { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); }

	return 'no redirect';
}

function said( $url, $key ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return isset( $q[ $key ] ) ? rawurldecode( (string) $q[ $key ] ) : '';
}

CardsCo_Send::forget();

sect( 'step 1 will not pass without somewhere to post it' );
$url = send_post( array( 'step' => 1, 'name' => 'Priya Shah' ) );
ck( 'a name alone is refused', (bool) said( $url, 'cardsco_error' ) );
ck( 'and says what is needed', str_contains( said( $url, 'cardsco_error' ), 'postcode' ) );

$url = send_post(
	array(
		'step' => 1, 'name' => 'Priya Shah', 'company' => 'Brightside Studio',
		'line1' => '22 Clarendon Road', 'town' => 'Leeds', 'postcode' => 'LS2 9NZ',
	)
);
ck( 'a full address moves on', said( $url, 'step' ), '2' );

$draft = CardsCo_Send::draft();
ck( 'and is kept', $draft['name'], 'Priya Shah' );
ck( 'with the address', CardsCo_Send::address_line( $draft ), '22 Clarendon Road, Leeds, LS2 9NZ' );

sect( 'you cannot skip ahead to paying' );
ck( 'asking for step 4 gives step 2', CardsCo_Send::step_on_screen( $draft, 4 ), 2 );

sect( 'step 2 is the message' );
$url = send_post( array( 'step' => 2, 'message' => '   ' ) );
ck( 'an empty message is refused', (bool) said( $url, 'cardsco_error' ) );

$url = send_post(
	array(
		'step' => 2,
		'message' => 'Priya, thank you for being part of our year.',
		'signature' => 'The Acme team',
	)
);
ck( 'a real one moves on', said( $url, 'step' ), '3' );

sect( 'step 3 is the card' );
$url = send_post( array( 'step' => 3, 'card_id' => 0 ) );
ck( 'no card is refused', (bool) said( $url, 'cardsco_error' ) );

$url = send_post( array( 'step' => 3, 'card_id' => $card ) );
ck( 'picking one moves on', said( $url, 'step' ), '4' );

sect( 'the order adds up' );
$draft = CardsCo_Send::draft();
ck( 'the total is the card price', number_format( CardsCo_Send::card_price( $draft ), 2 ), '3.60' );

/*
 * Postage is inside the card price, so nothing may add a second charge
 * on top of it. There is no setting to turn one back on and no code
 * left that could; these assert the absence stays absent.
 */
ck( 'there is no postage charge to set', ! array_key_exists( 'postage_single', CardsCo_Settings::defaults() ) );
ck( 'and nothing adds one', ! method_exists( 'CardsCo_Send', 'postage' ) && ! method_exists( 'CardsCo_Send', 'add_postage' ) );

sect( 'paying puts it in the basket, with the card on it' );
WC()->cart->empty_cart();
$url = send_post( array( 'step' => 4 ) );
ck( 'it goes to the checkout', str_contains( $url, 'checkout' ) );
ck( 'with one line', count( WC()->cart->get_cart() ), 1 );

$line = current( WC()->cart->get_cart() );
ck( 'carrying the card', ! empty( $line[ CardsCo_Send::LINE ] ) );
ck( 'with the recipient on it', $line[ CardsCo_Send::LINE ]['name'], 'Priya Shah' );
ck( 'and the message', str_contains( $line[ CardsCo_Send::LINE ]['message'], 'part of our year' ) );

sect( 'and nothing is added on top' );
ck( 'the basket is the card price', number_format( WC()->cart->total(), 2 ), '3.60' );

sect( 'going back and paying again does not buy two' );
send_post( array( 'step' => 4 ) );
ck( 'still one line', count( WC()->cart->get_cart() ), 1 );
ck( 'still one card', (int) current( WC()->cart->get_cart() )['quantity'], 1 );

sect( 'a visitor is never forced to make an account to pay' );
/*
 * The shop's own setting says they must register. For a basket holding
 * one card that has to be overridden, or this entire flow dead-ends at
 * the checkout for the people it was built for.
 */
update_option( 'woocommerce_enable_guest_checkout', 'no' );
ck( 'the card is in the basket', CardsCo_Send::in_basket() );
ck( 'guest checkout is allowed anyway', CardsCo_Send::allow_guest( false ) );
ck( 'and registration is not required', CardsCo_Send::require_account( true ), false );

sect( 'but an ordinary basket keeps the shop’s own answer' );
$cc_held = WC()->cart->get_cart();
WC()->cart->empty_cart();
ck( 'nothing of ours in it', CardsCo_Send::in_basket(), false );
ck( 'so the shop decides guest checkout', CardsCo_Send::allow_guest( false ), false );
ck( 'and the shop decides registration', CardsCo_Send::require_account( true ) );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );

/* Put the card back for the steps that follow. */
send_post( array( 'step' => 4 ) );
ck( 'the card is back in the basket', count( WC()->cart->get_cart() ), 1 );

sect( 'the order carries what the writer needs' );
$order = cc_stub_checkout( 'processing' );
$item  = $order->get_items()[0];
ck( 'who it is for', $item->get_meta( 'To' ), 'Priya Shah' );
ck( 'the company', $item->get_meta( 'Company' ), 'Brightside Studio' );
ck( 'where it goes', $item->get_meta( 'Address' ), '22 Clarendon Road, Leeds, LS2 9NZ' );
ck( 'what it says', str_contains( $item->get_meta( 'Message' ), 'part of our year' ) );
ck( 'and how it is signed', $item->get_meta( 'Signed' ), 'The Acme team' );

sect( 'starters offered to a visitor have nothing to fill in' );
/*
 * A company's saved messages are full of {first name} and {our company}.
 * A visitor has no company to fill them from, so offering one would hand
 * them a message with holes in it.
 */
foreach ( CardsCo_Send::starters( 6 ) as $one ) {
	ck( sprintf( '"%s" has no merge tags', substr( $one['label'], 0, 28 ) ), (bool) preg_match( '/\{[^}]+\}/', $one['text'] ), false );
}

sect( 'small things' );
ck( 'a first name comes out of a full one', CardsCo_Send::first_name( 'Priya Shah' ), 'Priya' );
ck( 'and an empty name does not break it', (bool) CardsCo_Send::first_name( '' ) );

sect( 'claiming the order as an account' );
/*
 * The offer after paying. The order key is what proves this is the person
 * who placed the order — without it an order number alone would let
 * anybody claim somebody else's order, and the address on it.
 */
$claimed = cc_stub_checkout( 'processing' );
$claimed->update_meta_data( '_billing_email', 'newcomer-' . wp_rand( 1000, 9999 ) . '@example.test' );

function claim_post( array $data ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST    = array_merge( array( 'cardsco_send_action' => 'claim' ), $data );
	$_REQUEST = $_POST;

	try { CardsCo_Send::handle(); }
	catch ( CCSendWent $w ) { return $w->getMessage(); }
	catch ( Throwable $e ) { return 'THREW: ' . $e->getMessage(); }
	finally { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); }

	return 'no redirect';
}

$cc_id  = $claimed->get_id();
$cc_key = $claimed->get_order_key();

$url = claim_post(
	array(
		'order_id'       => $cc_id,
		'order_key'      => 'not-the-key',
		'password'       => 'a-good-password',
		'_cardsco_nonce' => wp_create_nonce( 'cardsco_claim_' . $cc_id ),
	)
);
ck( 'a wrong order key is refused', (bool) said( $url, 'cardsco_error' ) );

$url = claim_post(
	array(
		'order_id'       => $cc_id,
		'order_key'      => $cc_key,
		'password'       => 'short',
		'_cardsco_nonce' => wp_create_nonce( 'cardsco_claim_' . $cc_id ),
	)
);
/* said() decodes; the raw query string has it as "8+characters". */
ck( 'a short password is refused', str_contains( said( $url, 'cardsco_error' ), '8 characters' ) );

$cc_before = count_users()['total_users'];
$url = claim_post(
	array(
		'order_id'       => $cc_id,
		'order_key'      => $cc_key,
		'password'       => 'a-good-long-password',
		'_cardsco_nonce' => wp_create_nonce( 'cardsco_claim_' . $cc_id ),
	)
);
ck( 'a good one makes the account', count_users()['total_users'], $cc_before + 1 );
ck( 'and the order belongs to it', CardsCo_Send::available() && wc_get_order( $cc_id )->get_customer_id() > 0 );
ck( 'and they are signed in', is_user_logged_in() );

/* Leave the fixtures as they were found. */
$cc_made = get_user_by( 'email', wc_get_order( $cc_id )->get_billing_email() );
wp_set_current_user( 0 );
if ( $cc_made ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $cc_made->ID );
}

sect( 'the catalogue sends people into the flow, not the cart' );

/*
 * Clear any page left behind by a run that stopped early. Without this
 * a single failure poisons every run after it, and the suite starts
 * reporting a stale fixture rather than the code.
 */
foreach ( get_posts(
	array(
		'post_type'        => 'page',
		'post_status'      => 'any',
		'numberposts'      => 50,
		's'                => '[cardsco_send',
		'fields'           => 'ids',
		'suppress_filters' => false,
	)
) as $cc_old ) {
	wp_delete_post( $cc_old, true );
}
CardsCo_Send::forget_page();

$cc_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Send a card',
		'post_content' => '[cardsco_send]',
	)
);
CardsCo_Send::forget_page();

$cc_use = CardsCo_Send::use_url( $card, 'https://example.test/fallback/' );
ck( 'it points at the flow', str_contains( $cc_use, (string) get_permalink( $cc_page ) ) );
ck( 'carrying the card', (int) said( $cc_use, 'card' ), $card );
ck( 'and never at add-to-cart', ! str_contains( $cc_use, 'add-to-cart' ) );

/*
 * The catalogue markup is what the customer actually clicks, so check
 * the rendered anchor and not only the helper behind it.
 */
$cc_html = do_shortcode( '[cardsco_catalogue]' );
ck( 'the rendered tiles too', ! str_contains( $cc_html, 'add-to-cart' ) );

/* With no page published there is nowhere to send them, so no sale either. */
wp_delete_post( $cc_page, true );
CardsCo_Send::forget_page();
$cc_use = CardsCo_Send::use_url( $card, 'https://example.test/fallback/' );
ck( 'without the page it falls back', $cc_use, 'https://example.test/fallback/' );
ck( 'and still never to the cart', ! str_contains( $cc_use, 'add-to-cart' ) );

sect( 'starting over clears it' );
CardsCo_Send::forget();
$draft = CardsCo_Send::draft();
ck( 'the name is gone', $draft['name'], '' );
ck( 'and it begins at step 1', CardsCo_Send::step_on_screen( $draft, 3 ), 1 );

CardsCo_Settings::set( 'postage_single', '0.00', 0 );
foreach ( get_posts( array( 'post_type' => 'cc_stub_order', 'numberposts' => 50, 'fields' => 'ids' ) ) as $o ) {
	wp_delete_post( $o, true );
}
$product = CardsCo_Catalogue::product_for_card( $card );
wp_delete_post( $card, true );
if ( $product ) { wp_delete_post( $product, true ); }

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
