<?php
/**
 * Where the wizard sends you after a save.
 *
 * Every wizard form posts to itself and the handler redirects, so a refresh
 * cannot save twice. The redirect has to land back on the wizard page.
 *
 * It did not. CardsCo_Setup::go() asked wp_get_referer() for the page to
 * return to, and WordPress deliberately answers false when the referer is
 * the same as the current request URI — wp_get_referer() means "the page
 * before this one", not "this page", and a form posting to itself is exactly
 * the case it refuses. So the fallback fired on every save and Continue
 * landed on the site's home page with the wizard's query string bolted on.
 *
 * Why no suite caught it: they asserted the query arguments and never the
 * path, and the arguments were right the whole time. These assertions check
 * the path.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/occasions/new/';
$_SERVER['REQUEST_METHOD'] = 'POST';
require 'core/wp-load.php';
require __DIR__ . '/stub-woo.php';

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-52s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : (string) $got );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

/* The form carries _wp_http_referer pointing at the page it is on, because
   wp_nonce_field() adds it. That is the condition that broke things. */
$_REQUEST['_wp_http_referer'] = '/portal/occasions/new/';
$_POST['_wp_http_referer']    = '/portal/occasions/new/';

add_filter( 'wp_redirect', function ( $to ) { throw new Exception( $to ); }, 1 );

function lands_on( $request_uri, array $args ) {
	$_SERVER['REQUEST_URI'] = $request_uri;
	try {
		CardsCo_Setup::go( $args );
	} catch ( Exception $e ) {
		return $e->getMessage();
	}
	return '';
}

sect( 'the condition that caused it' );
$_SERVER['REQUEST_URI'] = '/portal/occasions/new/';
ck( 'the referer is the page being posted to', wp_get_raw_referer(), '/portal/occasions/new/' );
ck( 'so WordPress refuses to call it a referer', wp_get_referer(), false );

sect( 'step 1, which has no query string at all' );
$to = lands_on( '/portal/occasions/new/', array( 'setup' => 123, 'step' => 2 ) );
ck( 'it stays on the wizard page',   str_starts_with( $to, '/portal/occasions/new/' ) );
ck( 'and not the home page',         str_contains( $to, home_url( '/' ) ), false );
ck( 'carrying the new programme',    str_contains( $to, 'setup=123' ) );
ck( 'and the step after it',         str_contains( $to, 'step=2' ) );

sect( 'later steps, which do have one' );
$to = lands_on( '/portal/occasions/new/?setup=123&step=2', array( 'setup' => 123, 'step' => 3 ) );
ck( 'still the wizard page',         str_starts_with( $to, '/portal/occasions/new/' ) );
ck( 'moved on a step',               str_contains( $to, 'step=3' ) );
ck( 'with no stale step left behind', substr_count( $to, 'step=' ), 1 );

sect( 'the states behind a step are dropped on the way out' );
$to = lands_on( '/portal/occasions/new/?setup=123&step=4&cc_write=1', array( 'setup' => 123, 'step' => 4 ) );
ck( 'back on the wizard page',       str_starts_with( $to, '/portal/occasions/new/' ) );
ck( 'and out of the writer',         str_contains( $to, 'cc_write' ), false );

$to = lands_on( '/portal/occasions/new/?setup=123&step=2&cc_added=9', array( 'setup' => 123, 'step' => 2, 'cc_add' => 1 ) );
ck( 'a stale cc_added does not survive', str_contains( $to, 'cc_added' ), false );
ck( 'while the one just asked for does', str_contains( $to, 'cc_add=1' ) );

sect( 'an error comes back to the same page too' );
$to = lands_on( '/portal/occasions/new/?cardsco_error=old', array( 'cardsco_error' => 'Choose an occasion to carry on.' ) );
ck( 'the wizard page, not home',     str_starts_with( $to, '/portal/occasions/new/' ) );
ck( 'with one error, not two',       substr_count( $to, 'cardsco_error' ), 1 );

sect( 'a wizard on a different page still works' );
$to = lands_on( '/set-up/', array( 'setup' => 7, 'step' => 2 ) );
ck( 'it follows the page it was on', str_starts_with( $to, '/set-up/' ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
