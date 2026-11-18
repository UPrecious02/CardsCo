<?php
/**
 * £3.60 a card, and £1.99 for each card past the twenty-fifth.
 *
 * The break is on the cards past the limit, not on the whole order, so
 * thirty cards cost 25 × 3.60 + 5 × 1.99 and not 30 × 1.99. That is worth
 * pinning down in both directions: it is a £140 difference on an order of
 * thirty, and the whole-order reading had a cliff where twenty-five cards
 * cost less than twenty-four.
 *
 * A card carrying its own price keeps it whatever the quantity, because a
 * price set on the card is somebody overriding the list on purpose.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-56s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : (string) $got );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install( true );

$uid = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid = $u->ID; break; }
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );

/* A card with no price of its own, so it follows the list. */
$card = wp_insert_post( array(
	'post_type'   => CardsCo_Card::POST_TYPE,
	'post_title'  => 'Plain birthday',
	'post_status' => 'publish',
) );

sect( 'what the CEO set' );
ck( 'a card is £3.60', CardsCo_Settings::get( 'price_default', null, $cid ), '3.60' );
ck( 'bulk is £1.99', CardsCo_Settings::get( 'price_bulk', null, $cid ), '1.99' );
ck( 'and starts at 25', (int) CardsCo_Settings::get( 'bulk_from', null, $cid ), 25 );

sect( 'up to the limit, everything is full price' );
foreach ( array( 1, 2, 10, 24, 25 ) as $n ) {
	ck( sprintf( '%d cards cost %s', $n, number_format( $n * 3.6, 2 ) ), CardsCo_Card::price_total( $card, $n, $cid ), number_format( $n * 3.6, 2, '.', '' ) );
	ck( sprintf( '…and %d is not bulk', $n ), CardsCo_Card::is_bulk( $n, $cid ), false );
}

sect( 'past the limit, only the extra cards are cheaper' );
foreach ( array( 26 => '91.99', 30 => '99.95', 100 => '239.25', 1000 => '2030.25' ) as $n => $want ) {
	ck( sprintf( '%d cards cost £%s', $n, $want ), CardsCo_Card::price_total( $card, $n, $cid ), $want );
	ck( sprintf( '…and %d is bulk', $n ), CardsCo_Card::is_bulk( $n, $cid ) );
}

sect( 'the whole-order reading is not what happens' );
ck( '30 cards are not 30 x 1.99', CardsCo_Card::price_total( $card, 30, $cid ), '99.95' );
ck( 'which would have been £59.70', CardsCo_Card::price_total( $card, 30, $cid ) === '59.70', false );

sect( 'and ordering more never costs less' );
$cc_last = 0.0;
for ( $n = 1; $n <= 60; $n++ ) {
	$cc_now = (float) CardsCo_Card::price_total( $card, $n, $cid );

	if ( $cc_now < $cc_last ) {
		break;
	}

	$cc_last = $cc_now;
}
ck( 'no quantity up to 60 is cheaper than the one below it', $n, 61 );
ck( 'the old cliff is gone: 25 costs more than 24', (float) CardsCo_Card::price_total( $card, 25, $cid ) > (float) CardsCo_Card::price_total( $card, 24, $cid ) );

sect( 'the basket lands on the same figure to the penny' );
/*
 * A basket line carries one price per item, so past the limit it gets the
 * blended figure. Multiplying that back by the quantity has to reach the
 * real total exactly, or the customer is charged a penny off.
 */
foreach ( array( 1, 24, 25, 26, 30, 37, 99, 100, 101, 333, 1000 ) as $n ) {
	$cc_line = CardsCo_Card::price_for( $card, $n, $cid ) * $n;
	ck(
		sprintf( '%d cards', $n ),
		number_format( $cc_line, 2, '.', '' ),
		CardsCo_Card::price_total( $card, $n, $cid )
	);
}

sect( 'a card priced by hand keeps its price' );
$own = wp_insert_post( array(
	'post_type'   => CardsCo_Card::POST_TYPE,
	'post_title'  => 'Letterpress, priced by hand',
	'post_status' => 'publish',
) );
update_post_meta( $own, '_cardsco_price', '5.50' );
ck( 'one of them is £5.50', CardsCo_Card::price_total( $own, 1, $cid ), '5.50' );
ck( 'and a hundred are £550, with no break', CardsCo_Card::price_total( $own, 100, $cid ), '550.00' );

sect( 'the limit is a setting, not a number in the code' );
CardsCo_Settings::set( 'bulk_from', 50, 0 );
ck( '30 is no longer past it', CardsCo_Card::price_total( $card, 30, $cid ), '108.00' );
ck( 'but 51 is', CardsCo_Card::price_total( $card, 51, $cid ), '181.99' );
CardsCo_Settings::set( 'bulk_from', 25, 0 );
ck( 'and it goes back', CardsCo_Card::price_total( $card, 30, $cid ), '99.95' );

sect( 'a bulk rate that is not cheaper is ignored' );
/*
 * Somebody mistyping the bulk price should not make a bigger order cost
 * more than a smaller one.
 */
CardsCo_Settings::set( 'price_bulk', '4.00', 0 );
ck( '30 cards stay at the standard price', CardsCo_Card::price_total( $card, 30, $cid ), '108.00' );
CardsCo_Settings::set( 'price_bulk', '1.99', 0 );

sect( 'nothing silly at the edges' );
ck( 'nought cards cost nothing', CardsCo_Card::price_total( $card, 0, $cid ), '0.00' );
ck( 'and a negative count does too', CardsCo_Card::price_total( $card, -5, $cid ), '0.00' );
ck( 'one card still prices as one', CardsCo_Card::price_for( $card, 0, $cid ), 3.6 );

wp_delete_post( $card, true );
wp_delete_post( $own, true );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
