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

/* A draft parked on step 6, with one person on it. */
function draft( $cid, $slug ) {
	$id = CardsCo_Programme::create( $cid, $slug );
	CardsCo_Programme::update( (int) $id, array( 'setup_step' => 6 ) );
	$rid = CardsCo_Recipient::create( array(
		'company_id' => $cid, 'type' => 'employee',
		'first_name' => 'Ada', 'last_name' => 'Nwosu', 'source' => 'manual',
		'event_day'  => 14, 'event_month' => 11,
	) );
	CardsCo_Programme::add_recipients( (int) $id, array( (int) $rid ) );
	return array( (int) $id, (int) $rid );
}

function screen( $id, $step = 6 ) {
	$_GET = array( CardsCo_Setup::ARG => $id, 'step' => $step );
	return do_shortcode( '[cardsco_setup]' );
}

list( $bday, $bday_r )   = draft( $cid, 'employee-birthdays' );
list( $xmas, $xmas_r )   = draft( $cid, 'christmas-seasonal' );
list( $promo, $promo_r ) = draft( $cid, 'promotions-offers' );

sect( 'each occasion knows which shape of screen it needs' );
ck( 'birthdays go per person', CardsCo_Dashboard::timing_shape( CardsCo_Programme::get( $bday ) ), 'per_person' );
ck( 'Christmas is a fixed date', CardsCo_Dashboard::timing_shape( CardsCo_Programme::get( $xmas ) ), 'fixed' );
ck( 'a promotion has none', CardsCo_Dashboard::timing_shape( CardsCo_Programme::get( $promo ) ), 'none' );

sect( 'the per-person screen is unchanged' );
$t = CardsCo_Dashboard::setup_timing( CardsCo_Programme::get( $bday ) );
ck( 'it still offers the two modes', count( $t['modes'] ), 2 );
ck( 'and names no shape', isset( $t['shape'] ), false );
$html = screen( $bday );
ck( 'the screen offers the monthly box', str_contains( $html, 'value="monthly_box"' ) );
ck( 'and a date per person', str_contains( $html, 'value="per_date"' ) );
ck( 'it asks for no occasion', str_contains( $html, 'name="occasion_type_id"' ), false );
ck( 'nor a landing date', str_contains( $html, 'name="starts_on"' ) );
ck( 'the lede still talks about birthdays', str_contains( $html, 'work back from each birthday' ) );

sect( 'the fixed screen asks which occasion' );
$t = CardsCo_Dashboard::setup_timing( CardsCo_Programme::get( $xmas ) );
ck( 'it offers only the dated occasions', count( $t['types'] ) > 0 );
$undated = 0;
foreach ( $t['types'] as $type ) { if ( ! $type['when'] ) { $undated++; } }
ck( 'every one of them has a date', $undated, 0 );
ck( 'and fewer than the eleven on offer', count( $t['types'] ) < count( CardsCo_Category::types( 'christmas-seasonal', $cid ) ) );
ck( 'nothing is picked yet', $t['chosen'], 0 );
ck( 'so no landing date is shown', $t['lands'], '' );

$html = screen( $xmas );
ck( 'the screen asks which occasion', str_contains( $html, 'name="occasion_type_id"' ) );
ck( 'and says so', str_contains( $html, 'Which occasion is this for?' ) );
ck( 'the lede does not mention birthdays', str_contains( $html, 'work back from each birthday' ), false );
ck( 'it explains the shared date instead', str_contains( $html, 'fall on the same day for everyone' ) );
ck( 'and will not continue with nothing picked', str_contains( $html, 'No occasion picked yet' ) );
ck( 'it offers no monthly box', str_contains( $html, 'value="monthly_box"' ), false );

/* Pick Christmas itself and save. */
$chr = 0;
foreach ( $t['types'] as $type ) {
	if ( false !== stripos( $type['label'], 'christmas' ) ) { $chr = (int) $type['id']; break; }
}
ck( 'Christmas is one of them', $chr > 0 );

$url = post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $xmas,
	'step'                 => 6,
	'occasion_type_id'     => $chr,
	'days_before'          => 10,
	'recurrence'           => 'annual',
) );
ck( 'it saves', str_contains( $url, 'step=7' ) );
ck( 'with no error', str_contains( $url, 'cardsco_error' ), false );

$row = CardsCo_Programme::get( $xmas );
ck( 'the occasion is stored', (int) $row['occasion_type_id'], $chr );
ck( 'the mode says fixed', $row['timing_mode'], 'fixed_date' );
ck( 'and the lead days are kept', (int) $row['days_before'], 10 );

$t = CardsCo_Dashboard::setup_timing( $row );
ck( 'now it knows the landing date', (bool) $t['lands'] );
ck( 'and the posting date', (bool) $t['posts'] );
ck( 'the date is in the future', strtotime( $t['lands'] ) > (int) current_time( 'timestamp' ) );
ck( 'ten days before it', (int) round( ( strtotime( $t['lands'] ) - strtotime( $t['posts'] . ' ' . gmdate( 'Y', strtotime( $t['lands'] ) ) ) ) / 86400 ), 10 );

$html = screen( $xmas );
ck( 'the footer names the landing date', str_contains( $html, 'Lands ' ) );
ck( 'and the chosen occasion is ticked', str_contains( $html, 'value="' . $chr . '" checked' ) || str_contains( $html, 'checked' ) );

sect( 'the dateless screen asks for a date' );
$t = CardsCo_Dashboard::setup_timing( CardsCo_Programme::get( $promo ) );
ck( 'nothing is chosen yet', $t['lands'], '' );
/*
 * Counted in calendar days, not in elapsed seconds. The earliest date is a
 * midnight; "now" is whatever time it happens to be, so the subtraction is
 * seven days minus the time of day — which rounds to 7 in the morning and
 * to 6 in the afternoon, and had this suite passing or failing by the
 * clock.
 */
ck(
	'the earliest offered is a week out',
	(string) $t['earliest'],
	gmdate( 'Y-m-d', strtotime( '+7 days', (int) current_time( 'timestamp' ) ) )
);

$html = screen( $promo );
ck( 'the screen takes a date', str_contains( $html, 'name="starts_on"' ) );
ck( 'and will not accept an earlier one', str_contains( $html, 'min="' . $t['earliest'] . '"' ) );
ck( 'the lede does not mention birthdays', str_contains( $html, 'work back from each birthday' ), false );
ck( 'it says the date is theirs to choose', str_contains( $html, 'Nothing sets the date for you' ) );
ck( 'and will not continue without one', str_contains( $html, 'No date chosen yet' ) );
ck( 'it asks for no occasion', str_contains( $html, 'name="occasion_type_id"' ), false );

$lands = gmdate( 'Y-m-d', strtotime( '+30 days', (int) current_time( 'timestamp' ) ) );
$url   = post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $promo,
	'step'                 => 6,
	'starts_on'            => $lands,
	'days_before'          => 5,
) );
ck( 'it saves', str_contains( $url, 'step=7' ) );
$row = CardsCo_Programme::get( $promo );
ck( 'the date is stored', $row['starts_on'], $lands );
ck( 'the mode says one-off', $row['timing_mode'], 'one_off' );
ck( 'and it does not repeat', $row['recurrence'], 'once' );

$t = CardsCo_Dashboard::setup_timing( $row );
ck( 'the posting date follows from it', (bool) $t['posts'] );

sect( 'a mode the screen really sends is not thrown away' );
/*
 * The whitelist used to accept "days_before", which no screen produces, so
 * "a few days before each birthday" was saved as the monthly box.
 */
CardsCo_Programme::update( $bday, array( 'timing_mode' => 'per_date' ) );
ck( 'per_date survives', CardsCo_Programme::get( $bday )['timing_mode'], 'per_date' );
CardsCo_Programme::update( $bday, array( 'timing_mode' => 'days_before' ) );
ck( 'the old spelling reads as the same thing', CardsCo_Programme::get( $bday )['timing_mode'], 'per_date' );
CardsCo_Programme::update( $bday, array( 'timing_mode' => 'nonsense' ) );
ck( 'and nonsense still falls back', CardsCo_Programme::get( $bday )['timing_mode'], 'monthly_box' );

$url = post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $bday,
	'step'                 => 6,
	'timing_mode'          => 'per_date',
	'days_before'          => 4,
) );
ck( 'the screen saves it as sent', CardsCo_Programme::get( $bday )['timing_mode'], 'per_date' );
ck( 'and keeps the lead days', (int) CardsCo_Programme::get( $bday )['days_before'], 4 );

sect( 'one shape does not wipe what another screen set' );
/* The two new screens carry no weekend switch, so saving must leave it be. */
CardsCo_Programme::update( $xmas, array( 'weekend_rule' => 1, 'box_arrive_day' => 20 ) );
post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $xmas,
	'step'                 => 6,
	'occasion_type_id'     => $chr,
	'days_before'          => 10,
) );
$row = CardsCo_Programme::get( $xmas );
ck( 'the weekend rule is untouched', (int) $row['weekend_rule'], 1 );
ck( 'and the box day too', (int) $row['box_arrive_day'], 20 );

CardsCo_Programme::update( $promo, array( 'weekend_rule' => 1 ) );
post( array(
	'cardsco_setup_action' => 'step',
	'programme_id'         => $promo,
	'step'                 => 6,
	'starts_on'            => $lands,
	'days_before'          => 5,
) );
ck( 'the promotion keeps its weekend rule', (int) CardsCo_Programme::get( $promo )['weekend_rule'], 1 );

sect( 'and the shape is worked out from the programme, not guessed' );
$f = CardsCo_Setup::fields( 6, array( 'occasion_type_id' => 3, 'starts_on' => $lands ), CardsCo_Programme::get( $xmas ) );
ck( 'a fixed occasion takes the occasion', isset( $f['occasion_type_id'] ) );
ck( 'and not a landing date', isset( $f['starts_on'] ), false );
$f = CardsCo_Setup::fields( 6, array( 'occasion_type_id' => 3, 'starts_on' => $lands ), CardsCo_Programme::get( $promo ) );
ck( 'a dateless one takes the date', $f['starts_on'], $lands );
ck( 'and not an occasion', isset( $f['occasion_type_id'] ), false );
$f = CardsCo_Setup::fields( 6, array( 'timing_mode' => 'per_date' ) );
ck( 'with no programme it falls back to per person', $f['timing_mode'], 'per_date' );

global $wpdb;
foreach ( array( $bday, $xmas, $promo ) as $p ) {
	foreach ( CardsCo_Programme::recipient_ids( $p ) as $r ) { CardsCo_Recipient::delete( (int) $r ); }
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $p ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $p ) );
}

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
