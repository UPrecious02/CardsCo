<?php
/**
 * What happens to a message the server will not save.
 *
 * The fault, found by starting from a starter message and changing the
 * words: the save was refused, the redirect went back to the writer, the
 * writer read the programme, and the programme still held whatever was
 * saved last. The starter came back unedited and the person's own sentence
 * was gone, with nothing to say it had ever been typed.
 *
 * Two halves to the fix:
 *
 * 1. A refused message is held and handed back, so the error sits above the
 *    person's own words.
 *
 * 2. The writer is told which merge tags would come out blank, so it stops
 *    the save the way it already stops [square brackets] — before the round
 *    trip rather than after it.
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

CardsCo_Schema::maybe_install( true );

$uid = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid = $u->ID; break; }
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );

class CCRedirected extends Exception {}
add_filter( 'wp_redirect', function ( $to ) { throw new CCRedirected( $to ); }, 1 );

function post( array $data ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = $data;
	$_POST['_cardsco_nonce'] = wp_create_nonce( 'cardsco_setup' );
	$_REQUEST = $_POST;
	try { ( new CardsCo_Setup() )->handle(); }
	catch ( CCRedirected $r ) { return $r->getMessage(); }
	catch ( Throwable $e ) { return 'THREW: ' . $e->getMessage(); }
	finally { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); }
	return 'no redirect';
}

$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $pid, array( 'setup_step' => 4 ) );
$rid = CardsCo_Recipient::create( array(
	'company_id' => $cid, 'type' => 'employee',
	'first_name' => 'Tunde', 'last_name' => 'Bakare', 'source' => 'manual',
	'event_day'  => 14, 'event_month' => 11,
) );
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $rid ) );

/*
 * The refusal the browser cannot see coming. The writer counts the raw
 * text; the server counts it merged against every person on the occasion,
 * because a long first name is what pushes a message over the card. So a
 * message the box calls a fit comes back refused — which is exactly the
 * case the writer's own comments warn about.
 */
$max = (int) CardsCo_Settings::get( 'card_max_chars', 400, $cid );
$long = CardsCo_Recipient::create( array(
	'company_id' => $cid, 'type' => 'employee',
	'first_name' => str_repeat( 'Oluwaseunfunmilayo', 3 ),
	'last_name'  => 'Adebayo', 'source' => 'manual',
	'event_day'  => 2, 'event_month' => 4,
) );
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $long ) );

sect( 'a starter is saved, the way somebody would' );
$starter = 'Happy birthday! We hope the year ahead treats you well.';
$url     = post( array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'message'              => $starter,
	'signature'            => 'The team',
) );
ck( 'it saves', str_contains( $url, 'cardsco_error' ), false );
ck( 'and is what step 4 shows', trim( (string) CardsCo_Programme::get( $pid )['message_override'] ), $starter );

sect( 'then the words are changed and the save is refused' );
$edited = $starter . ' ' . str_repeat( 'We mean every word of it. ', 11 ) . '{first name}.';
ck( 'the browser would call it a fit', mb_strlen( $edited . "\n" . 'Ada and the team' ) <= $max );
ck(
	'but merged, with the sign-off, it is over',
	mb_strlen(
		(string) CardsCo_Merge::render( $edited, CardsCo_Merge::context_for_recipient( CardsCo_Recipient::get( (int) $long ) ) )['text']
		. "\n" . 'Ada and the team'
	) > $max
);
$url    = post( array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'message'              => $edited,
	'signature'            => 'Ada and the team',
) );
ck( 'it is refused', str_contains( $url, 'cardsco_error' ) );
ck( 'and comes back to the writer', str_contains( $url, 'cc_write=1' ) );
ck( 'the saved message is untouched', trim( (string) CardsCo_Programme::get( $pid )['message_override'] ), $starter );

sect( 'and the writer gives the person their own words back' );
$w = CardsCo_Dashboard::setup_writer( CardsCo_Programme::get( $pid ) );
ck( 'the box holds what was typed', trim( $w['text'] ), $edited );
ck( 'not the message that was saved', trim( $w['text'] ) === $starter, false );
ck( 'and the sign-off too', $w['signature'], 'Ada and the team' );

sect( 'handed back once, not over a later save' );
$w2 = CardsCo_Dashboard::setup_writer( CardsCo_Programme::get( $pid ) );
ck( 'the second look shows the saved one', trim( $w2['text'] ), $starter );
ck( 'and the saved sign-off', $w2['signature'], 'The team' );

sect( 'a save that works leaves nothing waiting' );
post( array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'message'              => 'Short and blank-free. {first name}, have a good one.',
	'signature'            => 'The team',
) );
$ok = post( array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'message'              => 'Happy birthday {first name}, from everyone here.',
	'signature'            => 'The team',
) );
ck( 'the good one saves', str_contains( $ok, 'cardsco_error' ), false );
$w3 = CardsCo_Dashboard::setup_writer( CardsCo_Programme::get( $pid ) );
ck( 'and no refused draft resurfaces', trim( $w3['text'] ), 'Happy birthday {first name}, from everyone here.' );

sect( 'the writer is warned before the round trip' );
/* {our company} falls back from the trading name to the registered one, so
   it is blank only when a company has neither. */
$cc_was_name    = (string) CardsCo_Company::get( $cid )['name'];
$cc_was_trading = (string) CardsCo_Settings::get( 'trading_name', '', $cid );
CardsCo_Settings::set( 'trading_name', '', $cid );
CardsCo_Company::update( $cid, array( 'name' => '' ) );
$w4 = CardsCo_Dashboard::setup_writer( CardsCo_Programme::get( $pid ) );
ck( 'it knows a tag would be blank', in_array( 'our_company', (array) $w4['blanks'], true ) );
ck( 'but not one that fills in fine', in_array( 'first_name', (array) $w4['blanks'], true ), false );

$_GET = array( CardsCo_Setup::ARG => $pid, 'step' => 4, 'cc_write' => 1 );
$html = do_shortcode( '[cardsco_setup]' );
ck( 'and tells the browser', str_contains( $html, 'data-cc-blanks' ) );
ck( 'naming the tag as it is written', str_contains( $html, 'our company' ) );
ck( 'not with an underscore', str_contains( $html, 'data-cc-blanks="[&quot;our_company' ), false );

sect( 'and once there is a trading name, it stops warning' );
CardsCo_Settings::set( 'trading_name', 'Brightside', $cid );
$w5 = CardsCo_Dashboard::setup_writer( CardsCo_Programme::get( $pid ) );
ck( 'the tag is no longer blank', in_array( 'our_company', (array) $w5['blanks'], true ), false );
$url = post( array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'message'              => 'Happy birthday {first name}, from all of us at {our company}.',
	'signature'            => 'The team',
) );
ck( 'and the message saves', str_contains( $url, 'cardsco_error' ), false );

/* The fixtures are shared, so put the company back as it was found —
   a suite that leaves the next one a blank company name is a suite that
   fails somebody else's assertions. */
CardsCo_Company::update( $cid, array( 'name' => $cc_was_name ) );
CardsCo_Settings::set( 'trading_name', $cc_was_trading, $cid );

global $wpdb;
CardsCo_Recipient::delete( (int) $rid );
CardsCo_Recipient::delete( (int) $long );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $pid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
