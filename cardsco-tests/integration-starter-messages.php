<?php
/**
 * The client's starter messages, and the guard that stops a placeholder
 * reaching a card.
 *
 * The seed used to be eight invented examples. It is now the client's own
 * thirty-five, from "Prompts for the occasion".
 *
 * Twenty-three of them carry [square brackets] for the sender to fill in —
 * an offer, a date, a discount code. Those are not merge tags and nothing
 * fills them in, so a message saved with one still in it would be written
 * onto a card, by a pen, in ink, and posted. That is what the guard is for.
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

sect( 'the file the client supplied' );
$rows = include CARDSCO_PATH . 'includes/data/starter-messages.php';

ck( 'it is a list',            is_array( $rows ) );
ck( 'thirty-five messages',    count( $rows ), 35 );

$groups = array();
foreach ( $rows as $r ) {
	$groups[ $r[0] ] = ( $groups[ $r[0] ] ?? 0 ) + 1;
}

/* The document's six groups, mapped onto occasion types. Special offers and
 * Promotions & Offers are one type between them. */
ck( 'birthdays',               $groups['birthday'] ?? 0, 6 );
ck( 'thank-yous',              $groups['thank-you'] ?? 0, 5 );
ck( 'customer milestones',     $groups['customer-anniversary'] ?? 0, 8 );
ck( 'offers and promotions',   $groups['promotion'] ?? 0, 9 );
ck( 'christmas and new year',  ( $groups['christmas'] ?? 0 ) + ( $groups['new-year'] ?? 0 ), 7 );

foreach ( $rows as $r ) {
	if ( $r[0] && ! CardsCo_Occasion_Type::get_by_slug( $r[0] ) ) {
		ck( 'unknown occasion type: ' . $r[0], false );
	}
}
ck( 'every one names a real occasion type', true );

sect( 'the two mechanical changes to the wording' );
$all = implode( "\n", array_column( $rows, 3 ) );

/* (Name) became {first name}, which the plugin fills in. */
ck( 'the name tag is the plugin’s',  str_contains( $all, '{first name}' ) );
ck( 'and (Name) is gone',            (bool) preg_match( '/\(Name\)/i', $all ), false );

/* One bracket convention, so an unfilled placeholder can be spotted. */
ck( 'no round-bracket placeholders left',
    (bool) preg_match( '/\([a-z][a-z ]+\)/i', $all ), false );
ck( 'square brackets are used instead',
    (bool) preg_match( '/\[[a-z][a-z ]+\]/i', $all ) );

/* Offer codes and expiry dates are campaign-only merge tags. An occasion
 * using them would write a blank onto a card, so the starter messages must
 * ask the sender for the words instead. */
ck( 'no campaign-only merge tags',   str_contains( $all, '{offer' ), false );

sect( 'the sender’s own name' );
/* "from all of us at ..." is the sending company, not the recipient's.
 * {company} is the recipient's — swapping the two would sign a card with
 * the name of the person receiving it. */
ck( 'the messages use the sender tag',  str_contains( $all, '{our company}' ) );
ck( 'and no longer ask the user to type it', str_contains( $all, '[your company]' ), false );

$fields = CardsCo_Merge::fields();
ck( 'the tag is a real field',          isset( $fields['our_company'] ) );
ck( 'and distinct from the recipient’s', isset( $fields['company'] ) );

$uid0 = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid0 = $u->ID; break; }
}
wp_set_current_user( $uid0 );
$cid0 = cardsco_get_user_company_id( $uid0 );

$was = (string) CardsCo_Settings::get( 'trading_name', '', $cid0 );

CardsCo_Settings::set( 'trading_name', 'Brightside', $cid0 );
ck( 'the trading name is used', CardsCo_Merge::our_company( $cid0 ), 'Brightside' );

/* A company registered as "Brightside Holdings Ltd" should not sign its
 * cards that way, but with nothing set the registered name is better than
 * a hole in the sentence. */
CardsCo_Settings::set( 'trading_name', '', $cid0 );
$registered = CardsCo_Company::get( $cid0 )['name'];
ck( 'without one it falls back', CardsCo_Merge::our_company( $cid0 ), $registered );
ck( 'and is never blank',        '' !== CardsCo_Merge::our_company( $cid0 ) );

CardsCo_Settings::set( 'trading_name', $was, $cid0 );

sect( 'seeding' );
/* Start from nothing: this suite may run after another that already seeded,
   and "it created none" would then look like a pass for the wrong reason. */
foreach ( $rows as $r ) {
	foreach ( get_posts( array(
		'post_type'        => CardsCo_Message::POST_TYPE,
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'title'            => $r[2],
		'suppress_filters' => true,
	) ) as $old ) {
		wp_delete_post( (int) $old, true );
	}
}

$created = CardsCo_Message::seed();
ck( 'it creates the whole set', $created, count( $rows ) );
ck( 'the first run creates them', $created > 0 );

/* The admin button can be pressed twice. A library of duplicates is worse
 * than a button that did nothing. */
$again = CardsCo_Message::seed();
ck( 'a second run creates nothing', $again, 0 );

$birthday = CardsCo_Occasion_Type::get_by_slug( 'birthday' );
$titles   = array_map( 'get_the_title', wp_list_pluck( CardsCo_Message::for_occasion( (int) $birthday['id'], 0 ), 'ID' ) );
ck( 'the writer can offer a birthday one', in_array( 'Birthday — the year ahead', $titles, true ) );

sect( 'the guard against an unfilled placeholder' );
$uid = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid = $u->ID; break; }
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );
$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $pid, array( 'setup_step' => 5 ) );

class CCWentTo extends Exception {}
add_filter( 'wp_redirect', function ( $to ) { throw new CCWentTo( $to ); }, 1 );

function save_message( $pid, $text ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array(
		'cardsco_setup_action' => 'write_message',
		'programme_id'         => $pid,
		'message'              => $text,
		'signature'            => 'The team',
		'_cardsco_nonce'       => wp_create_nonce( 'cardsco_setup' ),
	);
	$_REQUEST = $_POST;
	try { ( new CardsCo_Setup() )->handle(); }
	catch ( CCWentTo $e ) { return $e->getMessage(); }
	catch ( Throwable $e ) { return 'THREW: ' . $e->getMessage(); }
	finally { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); }
	return '';
}

$to = save_message( $pid, 'The festive season is here. Enjoy [offer] until [date].' );
ck( 'a message with a placeholder is refused', str_contains( $to, 'cardsco_error' ) );
ck( 'and it names the one to replace',         str_contains( rawurldecode( $to ), '[offer]' ) );
ck( 'nothing is stored',                       (string) CardsCo_Programme::get( $pid )['message_override'], '' );

/* Merge tags are a different thing: the plugin fills those in itself. */
$to = save_message( $pid, 'Happy birthday {first name}! Have a lovely day.' );
ck( 'a merge tag is not a placeholder', str_contains( $to, 'cardsco_error' ), false );
ck( 'and the message is stored',        CardsCo_Programme::get( $pid )['message_override'], 'Happy birthday {first name}! Have a lovely day.' );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
