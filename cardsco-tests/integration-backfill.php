<?php
/**
 * The one-time category backfill.
 *
 * Cards used to carry only occasion ticks; the collection was worked out from
 * them on every read. 0.32.0 stores it on the card instead, so every card
 * saved before that needs the value writing once. This suite covers the two
 * things that matter: that it places the cards it can, and that it never runs
 * twice — a second run would quietly overwrite a correction made by hand.
 */

$_SERVER['HTTP_HOST']   = 'cardsco.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-54s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install( true );
wp_set_current_user( 1 );

$bday = (int) ( CardsCo_Occasion_Type::get_by_slug( 'birthday' )['id'] ?? 0 );
$xmas = (int) ( CardsCo_Occasion_Type::get_by_slug( 'christmas' )['id'] ?? 0 );

sect( 'a library as it looked before the field existed' );
ck( 'two occasion types to work with', $bday && $xmas );

$mk = function ( $title ) {
	$id = wp_insert_post(
		array(
			'post_type'   => CardsCo_Card::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $id ) ) { return 0; }
	update_post_meta( $id, '_cardsco_test_suite', 'backfill' );
	return (int) $id;
};

$legacy  = $mk( 'Legacy birthday' );
$legacy2 = $mk( 'Legacy festive' );
$orphan  = $mk( 'Legacy untagged' );

CardsCo_Card::set_occasions( $legacy, array( $bday ) );
CardsCo_Card::set_occasions( $legacy2, array( $xmas ) );

// set_occasions does not write the field; this is belt and braces so the
// fixture is genuinely a pre-0.32.0 card whatever else changes.
foreach ( array( $legacy, $legacy2, $orphan ) as $id ) {
	delete_post_meta( $id, CardsCo_Card::CATEGORY );
}
wp_cache_flush();

ck( 'nothing is stored on the legacy card', get_post_meta( $legacy, CardsCo_Card::CATEGORY, true ), '' );
ck( 'but it still resolves from its ticks', CardsCo_Card::category( $legacy ), 'employee-birthdays' );

sect( 'running it' );
delete_option( CardsCo_Card::BACKFILL_OPTION );
$n = CardsCo_Card::backfill_categories();
wp_cache_flush();

ck( 'it placed some cards', $n > 0 );
ck( 'the birthday one is stored now', get_post_meta( $legacy, CardsCo_Card::CATEGORY, true ), 'employee-birthdays' );
ck( 'and the festive one', get_post_meta( $legacy2, CardsCo_Card::CATEGORY, true ), 'christmas-seasonal' );

/* A card with no ticks cannot be placed. Guessing would be worse than
 * leaving it: the cards list flags it as "Not set" so it gets a decision. */
ck( 'a card with no ticks is left alone', get_post_meta( $orphan, CardsCo_Card::CATEGORY, true ), '' );
ck( 'and reports no collection', CardsCo_Card::category( $orphan ), '' );

sect( 'it only runs once' );
CardsCo_Card::set_category( $legacy, 'promotions-offers' );
$again = CardsCo_Card::backfill_categories();
wp_cache_flush();

ck( 'a second call does nothing', $again, 0 );
ck( 'a correction made by hand survives it', get_post_meta( $legacy, CardsCo_Card::CATEGORY, true ), 'promotions-offers' );

/* Forcing is the escape hatch, and it still must not touch a card that
 * already has a value — only ones with nothing stored. */
$forced = CardsCo_Card::backfill_categories( true );
wp_cache_flush();
ck( 'forcing it leaves placed cards alone', get_post_meta( $legacy, CardsCo_Card::CATEGORY, true ), 'promotions-offers' );
ck( 'and still skips the untagged one', CardsCo_Card::category( $orphan ), '' );

foreach ( array( $legacy, $legacy2, $orphan ) as $id ) {
	wp_delete_post( $id, true );
}

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
