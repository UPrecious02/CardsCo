<?php
/**
 * The card preview on step 3, and the headings the base rule was eating.
 *
 * Two faults found on the live site:
 *
 * 1. The eye on each card tile was a link to get_permalink() on the card
 *    post. That post type is not publicly viewable, so WordPress handed back
 *    the site's home page — clicking preview opened the marketing site in a
 *    new tab.
 *
 * 2. Every panel heading in the wizard is an h2, and the wizard's own base
 *    rule .cc h2 is (0,1,1), which beats a rule with one class in it. The
 *    writer's "What would you like to say?" collapsed from 34px to 18px.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/occasions/new/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';

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

$uid = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) {
		$uid = $u->ID;
		break;
	}
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );
$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $pid, array( 'setup_step' => 5 ) );

sect( 'the eye on a card tile' );
$_GET[ CardsCo_Setup::ARG ] = $pid;
$_GET['step']               = 3;
$html = do_shortcode( '[cardsco_setup]' );

ck( 'step 3 renders cards',          str_contains( $html, 'cc-card-pick-eye' ) );
ck( 'the eye is a button now',       str_contains( $html, '<button type="button" class="cc-card-pick-eye"' ) );
ck( 'and carries a card to preview', str_contains( $html, 'data-cc-pv="' ) );

/* The actual regression: an eye that is still an anchor is one pointing
 * somewhere, and the only somewhere it ever had was the home page. */
ck( 'no anchor eye survives',
    (bool) preg_match( '#<a[^>]*class="cc-card-pick-eye"#', $html ), false );
ck( 'and the home page is not linked from one',
    (bool) preg_match( '#class="cc-card-pick-eye"[^>]*href="' . preg_quote( home_url( '/' ), '#' ) . '"#', $html ), false );

sect( 'the panel it opens' );
ck( 'the dialog is on the step',  str_contains( $html, 'id="cc-pv"' ) );
ck( 'in pick mode',               str_contains( $html, 'data-cc-pv-mode="pick"' ) );
ck( 'its action chooses a card',  str_contains( $html, 'id="cc-pv-pick"' ) );
/* In the wizard the person is mid-set-up. Sending them to a shop page would
 * drop them out of a flow they are halfway through. */
ck( 'and does not offer the shop', str_contains( $html, 'id="cc-pv-use"' ), false );
ck( 'nor the product page',        str_contains( $html, 'id="cc-pv-more"' ), false );

$raw = preg_match( '#id="cc-pv-data">(.*?)</script>#s', $html, $m ) ? $m[1] : '{}';
$pv  = (array) json_decode( $raw, true );
ck( 'every tile is in the data',   count( $pv ) > 0 );
$first = reset( $pv );
ck( 'each has a title',            ! empty( $first['title'] ) );
ck( 'and an image to show',        array_key_exists( 'image', $first ) );
ck( 'and the script is enqueued',  wp_script_is( 'cardsco-preview', 'enqueued' ) );

sect( 'the catalogue still has its own version' );
$cat = do_shortcode( '[cardsco_catalogue use="/cart/"]' );
ck( 'the same dialog',             str_contains( $cat, 'id="cc-pv"' ) );
ck( 'but in shop mode',            str_contains( $cat, 'data-cc-pv-mode="shop"' ) );
ck( 'with the basket action',      str_contains( $cat, 'id="cc-pv-use"' ) );
ck( 'and no pick button',          str_contains( $cat, 'id="cc-pv-pick"' ), false );

sect( 'headings the base rule used to eat' );
/* .cc h2 is one class and one type. Any rule of ours with a single class
 * loses to it, and all of these headings are h2 elements. */
$css = file_get_contents( CARDSCO_PATH . 'assets/dashboard.css' );

foreach ( array( 'cc-wr-blank-h', 'cc-pn-title', 'cc-pn-listtitle' ) as $cls ) {
	ck(
		$cls . ' is scoped past .cc h2',
		(bool) preg_match( '#\.cc-wz\s+\.' . preg_quote( $cls, '#' ) . '\s*\{#', $css )
	);
	ck(
		'and not left on its own',
		(bool) preg_match( '#(^|\})\s*\.' . preg_quote( $cls, '#' ) . '\s*\{#m', $css ),
		false
	);
}

/* The one that was visibly wrong: 34px in the frame, 18 on screen. */
ck( 'the writer heading asks for 34px',
    (bool) preg_match( '#\.cc-wz\s+\.cc-wr-blank-h\s*\{[^}]*font-size:\s*34px#s', $css ) );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
