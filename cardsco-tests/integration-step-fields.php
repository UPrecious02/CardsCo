<?php
/**
 * Step 6 for the three shapes of occasion.
 *
 * Birthdays carry a date per person, so the screen asks how to group them.
 * Christmas & Seasonal carries eleven occasions with dates of their own, so
 * the screen asks which occasion and works back from its date. Promotions
 * carry no date anywhere, so the screen asks for one.
 *
 * Three faults this covers:
 *
 * 1. update() accepted "days_before" as a timing mode, which no screen has
 *    ever sent. Choosing "a few days before each birthday" failed the
 *    whitelist and was silently saved as the monthly box.
 *
 * 2. Saving one shape's screen used to post the whole field list, so the
 *    switches the other two screens never show would be wiped on save.
 *
 * 3. The lede and footer on the rail still talked about working back from
 *    each birthday on screens where there are no birthdays.
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
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) {
		$uid = $u->ID;
		break;
	}
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

/*
 * A step only saves what its own screen sent.
 *
 * Step 4 shows the message but has no box for it — the writing happens in
 * the card writer, which saves on its own. Pressing Continue there posted
 * nothing about the message, and the step still wrote every message field,
 * so the message, the sign-off and the handwriting were all blanked. The
 * step then reported itself unfinished, which it was, and going back to
 * see what had happened re-ran the same form and blanked it again.
 */

$prog = CardsCo_Programme::create( $cid, 'employee-birthdays' );
if ( is_wp_error( $prog ) ) { echo 'could not make a draft: ' . $prog->get_error_message() . "\n"; exit( 1 ); }
$prog = (int) $prog;

CardsCo_Programme::update(
	$prog,
	array(
		'setup_step'       => 4,
		'message_override' => 'Happy birthday {first name}, from everyone here.',
		'signature'        => 'The Acme team',
		'handwriting'      => 'Marcella',
		'ink'              => 'navy',
	)
);

sect( 'what the card writer saved' );
$row = CardsCo_Programme::get( $prog );
ck( 'the message is there', $row['message_override'], 'Happy birthday {first name}, from everyone here.' );
ck( 'and step 4 counts as done', CardsCo_Programme::step_complete( $row, 4 ) );

sect( 'pressing Continue on step 4 keeps it' );
/* Exactly what view-setup-4.php posts: the step and nothing else. */
$url = post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $prog,
	'step'                 => 4,
) );
ck( 'it moves on', str_contains( $url, 'step=5' ) );

$row = CardsCo_Programme::get( $prog );
ck( 'the message survives', $row['message_override'], 'Happy birthday {first name}, from everyone here.' );
ck( 'the sign-off survives', $row['signature'], 'The Acme team' );
ck( 'the handwriting survives', $row['handwriting'], 'Marcella' );
ck( 'the ink survives', $row['ink'], 'navy' );
ck( 'and step 4 is still done', CardsCo_Programme::step_complete( $row, 4 ) );

sect( 'and going back to look at it keeps it too' );
post( array( 'cardsco_setup_action' => 'step', 'programme_id' => $prog, 'step' => 4 ) );
post( array( 'cardsco_setup_action' => 'step', 'programme_id' => $prog, 'step' => 4 ) );
$row = CardsCo_Programme::get( $prog );
ck( 'still there after going round twice', $row['message_override'], 'Happy birthday {first name}, from everyone here.' );
ck( 'and the step has not gone backwards', (int) $row['setup_step'] >= 5 );

sect( 'a screen that does send a field still saves it' );
$url = post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $prog,
	'step'                 => 4,
	'message_override'     => 'A different message entirely.',
	'signature'            => 'Priya',
) );
$row = CardsCo_Programme::get( $prog );
ck( 'the new message is saved', $row['message_override'], 'A different message entirely.' );
ck( 'and the new sign-off', $row['signature'], 'Priya' );
ck( 'while the untouched handwriting is left alone', $row['handwriting'], 'Marcella' );

sect( 'the same holds for the card on step 3' );
CardsCo_Programme::update( $prog, array( 'card_id' => 4242 ) );
post( array( 'cardsco_setup_action' => 'step', 'programme_id' => $prog, 'step' => 3 ) );
$row = CardsCo_Programme::get( $prog );
ck( 'a step 3 with no card posted keeps the card', (int) $row['card_id'], 4242 );

sect( 'but a tickbox still means false by its absence' );
CardsCo_Programme::update( $prog, array( 'setup_step' => 6, 'weekend_rule' => 1, 'timing_mode' => 'monthly_box' ) );
ck( 'it is on to begin with', (bool) CardsCo_Programme::get( $prog )['weekend_rule'] );
post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $prog,
	'step'                 => 6,
	'timing_mode'          => 'monthly_box',
	'box_arrive_day'       => 25,
	'starts_on'            => '2027-01-01',
) );
ck( 'and unticking it turns it off', (bool) CardsCo_Programme::get( $prog )['weekend_rule'], false );


printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
