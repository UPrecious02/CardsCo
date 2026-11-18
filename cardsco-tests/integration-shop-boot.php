<?php
/**
 * The shop stylesheet registers its hook even with no WooCommerce in sight.
 *
 * WHY THIS SUITE EXISTS, AND WHY IT DOES NOT LOAD THE STUB
 *
 * 0.31.0 shipped a stylesheet for the WooCommerce pages and it never loaded
 * on the live site. CardsCo_Shop::init() checked class_exists('WooCommerce')
 * and returned early without registering its enqueue hook. init() runs when
 * WordPress includes the plugin file, and plugins are included in
 * alphabetical order — cardsco/ before woocommerce/ — so that class never
 * exists at that moment, however complete the install is.
 *
 * Every other suite requires stub-woo.php, which defines the WooCommerce
 * class before anything else runs. Under that stub the bug is invisible: the
 * check passes and the hook goes on. So this one deliberately does not load
 * it. A WordPress with no WooCommerce at all is exactly the state the live
 * site is in at plugin-load time, and the only state where the mistake
 * shows.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require 'core/wp-load.php';
// No stub-woo.php. That is the point of this suite.

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-56s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : (string) $got );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

sect( 'the state the live site boots in' );
ck( 'WooCommerce is genuinely absent', class_exists( 'WooCommerce' ), false );
ck( 'and so are its conditional tags', function_exists( 'is_woocommerce' ), false );
ck( 'but the shop class is loaded', class_exists( 'CardsCo_Shop' ) );

sect( 'init still registers the hook' );
/* Called again rather than relying on the one at plugin load: this asserts
   the method's own behaviour, not a side effect of boot order. */
remove_action( 'wp_enqueue_scripts', array( 'CardsCo_Shop', 'assets' ), 20 );
ck( 'cleared to start from nothing', has_action( 'wp_enqueue_scripts', array( 'CardsCo_Shop', 'assets' ) ), false );

CardsCo_Shop::init();
ck( 'the enqueue hook is on', has_action( 'wp_enqueue_scripts', array( 'CardsCo_Shop', 'assets' ) ), 20 );

sect( 'but nothing is enqueued without a shop' );
/* The hook being registered must not mean the stylesheet loads everywhere.
   With no WooCommerce the conditional tags do not exist, so assets() has to
   bail without a fatal rather than calling a function that is not there. */
CardsCo_Shop::assets();
ck( 'no stylesheet on a site with no shop', wp_style_is( 'cardsco-shop', 'enqueued' ), false );
ck( 'and no font request either',           wp_style_is( 'cardsco-fonts-shop', 'enqueued' ), false );
ck( 'calling it did not fatal',             true );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
