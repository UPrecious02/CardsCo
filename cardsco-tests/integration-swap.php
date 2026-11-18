<?php
/**
 * Moving between wizard steps without reloading the page.
 *
 * The behaviour itself is in the browser, so what can be checked here is
 * the wiring it depends on — and the wiring is where it broke once already:
 *
 *  - every script the wizard uses has to be on the page from the start.
 *    writer.js used to load only on the writer step, which was fine while
 *    each step was a fresh page and useless the moment a step could arrive
 *    without one: the writer opened with a dead character counter;
 *
 *  - each script has to register itself rather than run on load, or there
 *    is nothing to run again after a swap;
 *
 *  - every script must depend on boot.js, which holds the register.
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
	printf( "  %-5s %-58s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

$plugin = dirname( __DIR__ ) . '/cardsco';
$src    = (string) file_get_contents( $plugin . '/includes/class-cardsco-shortcodes.php' );

sect( 'the register exists and each part uses it' );
$boot = (string) file_get_contents( $plugin . '/assets/boot.js' );
ck( 'boot offers a way to register', str_contains( $boot, 'CardsCo.add' ) );
ck( 'and a way to run them again', str_contains( $boot, 'CardsCo.rerun' ) );
ck( 'one part failing does not stop the rest', str_contains( $boot, 'catch' ) );

foreach ( array( 'wizard', 'writer', 'preview' ) as $part ) {
	$js = (string) file_get_contents( $plugin . '/assets/' . $part . '.js' );
	ck( sprintf( '%s.js registers itself', $part ), str_contains( $js, 'window.CardsCo.add( function' ) );
	/*
	 * The point is that the script's own work is registered rather than
	 * run once on load. Each file also carries a standing-in registry for
	 * pages too old to ask for boot.js, and that fallback legitimately
	 * waits for DOMContentLoaded — so the check is on the body below it,
	 * not on the whole file. Checking the whole file turned this into a
	 * failure about the guard rather than about the thing it guards.
	 */
	$body = substr( $js, (int) strpos( $js, 'window.CardsCo.add( function' ) );
	ck( sprintf( '…rather than running on load', $part ), str_contains( $body, "addEventListener( 'DOMContentLoaded'" ), false );
	ck( sprintf( '%s.js survives boot.js being absent', $part ), str_contains( $js, 'window.CardsCo.add = window.CardsCo.add ||' ) );
}

sect( 'every part is loaded with boot behind it' );
preg_match_all( "/wp_enqueue_script\(\s*'cardsco-([a-z]+)'[^;]*?array\(([^)]*)\)/s", $src, $found, PREG_SET_ORDER );
$deps = array();

foreach ( $found as $one ) {
	$deps[ $one[1] ] = $one[2];
}

ck( 'boot is enqueued', isset( $deps['boot'] ) );

foreach ( array( 'wizard', 'writer', 'preview', 'swap' ) as $part ) {
	ck( sprintf( '%s is enqueued', $part ), isset( $deps[ $part ] ) );
	ck( sprintf( '…behind boot', $part ), str_contains( (string) ( $deps[ $part ] ?? '' ), 'cardsco-boot' ) );
}

sect( 'the wizard carries its step scripts from the first response' );
/*
 * This is the one that broke. A script enqueued only on the step that uses
 * it is never present when that step arrives by swap, because the swap
 * only replaces markup — it does not fetch scripts.
 */
$setup = substr( $src, strpos( $src, 'public function setup(' ) );
$setup = substr( $setup, 0, strpos( $setup, "\n\tpublic function " ) );

foreach ( array( 'swap', 'writer', 'preview' ) as $part ) {
	ck( sprintf( 'the wizard loads %s.js up front', $part ), str_contains( $setup, "'cardsco-" . $part . "'" ) );
}

sect( 'the swap gives up rather than guessing' );
$swap = (string) file_get_contents( $plugin . '/assets/swap.js' );
ck( 'an old browser is left alone', str_contains( $swap, '! window.fetch' ) );
ck( 'a failed post falls back to submitting', str_contains( $swap, 'form.submit()' ) );
ck( 'a failed fetch falls back to navigating', str_contains( $swap, 'window.location.href = url' ) );
ck( 'a reply without a wizard in it is followed properly', str_contains( $swap, '! next || ! panel' ) );
ck( 'the back button is handled', str_contains( $swap, "'popstate'" ) );
ck( 'and a link out of the wizard is not intercepted', str_contains( $swap, 'to.pathname !== window.location.pathname' ) );

sect( 'a delegated listener is attached once, not once per swap' );
$pv = (string) file_get_contents( $plugin . '/assets/preview.js' );
ck( 'the preview guards its document listener', str_contains( $pv, 'ccPvBound' ) );
ck( 'but still works against the dialog on screen now', str_contains( $pv, 'ccPvOpen = open' ) );
ck( 'and forgets it on a step without one', str_contains( $pv, 'ccPvOpen = null' ) );

sect( 'the loading state is quiet' );
$css = (string) file_get_contents( $plugin . '/assets/dashboard.css' );
ck( 'there is one', str_contains( $css, '.cc-wz.is-loading' ) );
ck( 'it waits before showing', str_contains( $css, 'transition: opacity 120ms ease 140ms' ) );
ck( 'and respects a reduced-motion setting', str_contains( $css, 'prefers-reduced-motion' ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
