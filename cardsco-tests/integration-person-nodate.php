<?php
/**
 * Adding people to occasions that have no per-person date, and fixing a
 * date on somebody already on the list.
 *
 * Two faults, both found by uploading a list with names and no dates:
 *
 * 1. Promotions & Offers has no occasion type at all, and Christmas &
 *    Seasonal has eleven but none of them dated — Christmas is the 25th for
 *    everybody. The add-by-hand form showed day and month regardless, then
 *    refused the save with "this occasion cannot take people one at a time"
 *    after the whole form had been filled in.
 *
 * 2. "Add a birthday" beside somebody with no date pointed at the same URL
 *    as "Add someone by hand", so it opened a blank panel headed "Person 4"
 *    and created a second, nameless person instead of giving that one a
 *    date.
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
function qs( $url, $key ) {
	$q = array();
	parse_str( (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' ), $q );
	return $q[ $key ] ?? null;
}

sect( 'which occasions have a date of their own' );
ck( 'a birthday differs per person',  (bool) CardsCo_Category::primary_type( 'employee-birthdays', $cid ) );
ck( 'Christmas does not',             (bool) CardsCo_Category::primary_type( 'christmas-seasonal', $cid ), false );
ck( 'even though it has occasions',   count( CardsCo_Category::types( 'christmas-seasonal', $cid ) ) > 1 );
ck( 'and promotions have none at all', (bool) CardsCo_Category::primary_type( 'promotions-offers', $cid ), false );

sect( 'adding somebody to a promotion' );
$promo = CardsCo_Programme::create( $cid, 'promotions-offers' );
CardsCo_Programme::update( (int) $promo, array( 'setup_step' => 3 ) );
$prow  = CardsCo_Programme::get( $promo );

$view = CardsCo_Dashboard::setup_person( $prow, 0 );
ck( 'the form knows not to ask for a date', $view['per_person'], false );

$_GET[ CardsCo_Setup::ARG ] = $promo;
$_GET['step']               = 2;
$_GET['cc_add']             = 1;
$html = do_shortcode( '[cardsco_setup]' );
ck( 'and does not render a day field',   str_contains( $html, 'name="event_day"' ), false );
ck( 'nor a month field',                 str_contains( $html, 'name="event_month"' ), false );
ck( 'but still takes a name',            str_contains( $html, 'name="first_name"' ) );
ck( 'and explains why there is no date', str_contains( $html, 'no date of its own' ) );

/* The save that used to be refused. */
$url = post( array(
	'cardsco_setup_action' => 'add_person',
	'programme_id'         => $promo,
	'first_name'           => 'Ada',
	'last_name'            => 'Nwosu',
) );

ck( 'saving works without a date',   str_contains( $url, 'cc_added=' ) );
ck( 'and is not refused',            str_contains( $url, 'cardsco_error' ), false );
$ada = (int) qs( $url, 'cc_added' );
ck( 'the person is on the occasion', in_array( $ada, CardsCo_Programme::recipient_ids( $promo ), true ) );

sect( 'and the list does not nag about a date it never wanted' );
$people = CardsCo_Dashboard::setup_people( $prow );
ck( 'no with/missing counts',    count( $people['counts'] ), 1 );
ck( 'just the total',            $people['counts'][0]['label'], 'people in total' );
ck( 'the row is not flagged',    $people['rows'][0]['has_date'] );
ck( 'and reads "Not needed"',    $people['rows'][0]['birthday'], 'Not needed' );

sect( 'fixing a date on somebody already on the list' );
$bday = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $bday, array( 'setup_step' => 3 ) );
$brow = CardsCo_Programme::get( $bday );

/* Exactly what the CSV with no dates produces: a person, no occasion. */
$rid = CardsCo_Recipient::create( array(
	'company_id' => $cid, 'type' => 'employee',
	'first_name' => 'Tunde', 'last_name' => 'Bakare', 'source' => 'manual',
) );
CardsCo_Programme::add_recipients( (int) $bday, array( (int) $rid ) );

$people = CardsCo_Dashboard::setup_people( $brow );
ck( 'they show as missing a date', $people['rows'][0]['has_date'], false );
ck( 'and the row carries their id', (int) $people['rows'][0]['id'], (int) $rid );

$_GET[ CardsCo_Setup::ARG ] = $bday;
$_GET['step']               = 2;
unset( $_GET['cc_add'] );
$list = do_shortcode( '[cardsco_setup]' );
ck( 'the link names this person',  str_contains( $list, 'cc_person=' . $rid ) );

$_GET['cc_person'] = $rid;
$form = do_shortcode( '[cardsco_setup]' );
ck( 'the form opens on them',      str_contains( $form, 'name="person_id"' ) );
ck( 'headed with their name',      str_contains( $form, 'Tunde Bakare' ) );
ck( 'with the name filled in',     str_contains( $form, 'value="Tunde"' ) );
ck( 'and it saves changes, not a new person', str_contains( $form, 'Save changes' ) );
ck( 'not headed "Person 2"',       str_contains( $form, 'Person 2' ), false );

$before = count( CardsCo_Programme::recipient_ids( $bday ) );
$url    = post( array(
	'cardsco_setup_action' => 'add_person',
	'programme_id'         => $bday,
	'person_id'            => $rid,
	'first_name'           => 'Tunde',
	'last_name'            => 'Bakare',
	'event_day'            => 14,
	'event_month'          => 11,
) );

ck( 'it saves',                       str_contains( $url, 'cc_added=' ) );
ck( 'nobody new is created',          count( CardsCo_Programme::recipient_ids( $bday ) ), $before );
ck( 'it is the same person',          (int) qs( $url, 'cc_added' ), (int) $rid );

$after = CardsCo_Dashboard::setup_people( CardsCo_Programme::get( $bday ) );
ck( 'and they now have their date',   $after['rows'][0]['has_date'] );
ck( 'the right one',                  $after['rows'][0]['birthday'], '14 November' );
ck( 'counted as having one',          $after['counts'][0]['n'], 1 );
ck( 'and the label names the occasion', $after['counts'][0]['label'], 'with a birthday' );

global $wpdb;
foreach ( array( $promo, $bday ) as $p ) {
	foreach ( CardsCo_Programme::recipient_ids( $p ) as $r ) { CardsCo_Recipient::delete( (int) $r ); }
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $p ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $p ) );
}

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
