<?php
/**
 * The card writer.
 *
 * Step 4's "Edit in card writer" button used to come back to the same page,
 * because the screen behind it did not exist. This covers the screen, the
 * save path, and the length rule.
 *
 * The length rule is the part worth guarding: it is measured on the rendered
 * card — the merged first name and the sign-off included — not on the raw
 * text, because that is what has to fit under a pen. A message that counts
 * as 390 characters in the box can be 410 on Oluwafunmilayo's card.
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

/* Somebody to merge against, so the preview is a real name. */
$rid = CardsCo_Recipient::create(
	array( 'company_id' => $cid, 'type' => 'employee', 'first_name' => 'Kemi', 'last_name' => 'Adebayo', 'source' => 'manual' )
);
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $rid ) );
$programme = CardsCo_Programme::get( $pid );

sect( 'what the writer is handed' );
$w = CardsCo_Dashboard::setup_writer( $programme );

ck( 'a limit to write within',      $w['max'] > 0 );
ck( 'and it is the setting',        $w['max'], (int) CardsCo_Settings::get( 'card_max_chars', 400, $cid ) );
ck( 'nothing written yet',          $w['ready'], false );
ck( 'previewed against a real name', $w['for'], 'Kemi' );
ck( 'the occasion is named',         (bool) $w['noun'] );
ck( 'and the people counted',        $w['people'], 1 );

sect( 'starting from one of ours' );
ck( 'there are templates to start from', count( $w['templates'] ) > 0 );
$texts = array_column( $w['templates'], 'text' );
ck( 'none of them empty',                count( array_filter( $texts, 'strlen' ) ), count( $texts ) );
ck( 'and none of them repeated',         count( array_unique( $texts ) ), count( $texts ) );
ck( 'each carries its wording',          (bool) $w['templates'][0]['text'] );
ck( 'and a label',                       (bool) $w['templates'][0]['label'] );

sect( 'saving' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'cardsco_setup_action' => 'write_message',
	'programme_id'         => $pid,
	'_cardsco_nonce'       => wp_create_nonce( 'cardsco_setup' ),
	'message'              => 'Happy birthday {first name}! Have a good one.',
	'signature'            => 'The team at Brightside',
);

/* go() redirects and exits, so the handler is driven through its own guard
   with redirects turned into exceptions. */
add_filter( 'wp_redirect', function ( $to ) { throw new Exception( 'redirect:' . $to ); }, 1 );

$landed = '';
try {
	( new CardsCo_Setup() )->handle();
} catch ( Exception $e ) {
	$landed = str_replace( 'redirect:', '', $e->getMessage() );
}

$saved = CardsCo_Programme::get( $pid );
ck( 'the message is stored',   $saved['message_override'], 'Happy birthday {first name}! Have a good one.' );
ck( 'and the sign-off',        $saved['signature'], 'The team at Brightside' );
ck( 'and it goes back to step 4', str_contains( $landed, 'step=4' ) );
ck( 'leaving the writer behind',  str_contains( $landed, 'cc_write' ), false );

$w2 = CardsCo_Dashboard::setup_writer( $saved );
ck( 'the writer reads it back',    $w2['ready'] );
ck( 'with the name merged in',     in_array( 'Happy birthday Kemi! Have a good one.', $w2['lines'], true ) );
ck( 'and the placeholder kept in the box for editing',
    str_contains( $w2['text'], '{first name}' ) );

sect( 'the length rule' );
/* The point of measuring the rendered card rather than the box: a name
   longer than the placeholder it replaces makes the message grow, and the
   sign-off is on the card too. "{first name}" is twelve characters;
   Oluwafunmilayo is fourteen, so this person's card is the longest one the
   batch will produce and the only one that matters for fit. */
$big = CardsCo_Recipient::create(
	array( 'company_id' => $cid, 'type' => 'employee', 'first_name' => 'Oluwafunmilayo', 'last_name' => 'Adeyemi', 'source' => 'manual' )
);
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $big ) );

$max  = (int) $w['max'];
$sign = 'The team at Brightside';
$long = str_repeat( 'a', 380 ) . ' {first name}';

$merged = CardsCo_Merge::render( $long, CardsCo_Merge::context_for_recipient( CardsCo_Recipient::get( $big ) ) );

ck( 'the raw text is within the limit',   mb_strlen( $long ) <= $max );
ck( 'the name makes it longer, not shorter', (int) $merged['length'] > mb_strlen( $long ) );
ck( 'but the card it renders to is over',
    mb_strlen( $merged['text'] . "\n" . $sign ) > $max );

$_POST['message']   = $long;
$_POST['signature'] = $sign;
$_POST['_cardsco_nonce'] = wp_create_nonce( 'cardsco_setup' );

$landed = '';
try {
	( new CardsCo_Setup() )->handle();
} catch ( Exception $e ) {
	$landed = str_replace( 'redirect:', '', $e->getMessage() );
}

ck( 'so saving is refused',        str_contains( $landed, 'cardsco_error' ) );
ck( 'back on the writer, not step 4 alone', str_contains( $landed, 'cc_write' ) );
ck( 'and the old message stands',  CardsCo_Programme::get( $pid )['message_override'], 'Happy birthday {first name}! Have a good one.' );

/* Empty is refused too, or Continue would pass with a blank card. */
$_POST['message']        = '   ';
$_POST['_cardsco_nonce'] = wp_create_nonce( 'cardsco_setup' );
$landed = '';
try {
	( new CardsCo_Setup() )->handle();
} catch ( Exception $e ) {
	$landed = str_replace( 'redirect:', '', $e->getMessage() );
}
ck( 'an empty message is refused', str_contains( $landed, 'cardsco_error' ) );

sect( 'what the screen renders' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$_GET[ CardsCo_Setup::ARG ] = $pid;
$_GET['step']               = 4;
$_GET['cc_write']           = 1;
$html = do_shortcode( '[cardsco_setup]' );

ck( 'the writer form',          str_contains( $html, 'id="ccWriter"' ) );
ck( 'posting the write action', str_contains( $html, 'value="write_message"' ) );
ck( 'the limit is on the form', str_contains( $html, 'data-cc-max="' . $w['max'] . '"' ) );
ck( 'a preview card',           str_contains( $html, 'cc-wr-card' ) );
ck( 'the sign-off field',       str_contains( $html, 'name="signature"' ) );
/* The rail is suppressed: the preview card is the screen and a 320px rail
   would halve it. */
ck( 'and no rail beside it',    str_contains( $html, '<aside class="cc-wz-rail"' ), false );
ck( 'the body goes full width', str_contains( $html, 'cc-wz-body is-wide' ) );

/* Step 4 itself keeps its rail, and its button now points at the writer. */
unset( $_GET['cc_write'] );
$step4 = do_shortcode( '[cardsco_setup]' );
ck( 'step 4 still has its rail', str_contains( $step4, '<aside class="cc-wz-rail"' ) );
ck( 'and its button reaches the writer', str_contains( $step4, 'cc_write=1' ) );

global $wpdb;
CardsCo_Recipient::delete( (int) $rid );
CardsCo_Recipient::delete( (int) $big );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $pid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
