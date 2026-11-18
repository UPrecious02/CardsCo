<?php
/**
 * Step 2 — adding one person by hand.
 *
 * Until now the only way to get people into an occasion was a spreadsheet:
 * the "Add someone by hand" link pointed at whatever the people attribute
 * held, which by default was the same page, and nothing was behind it.
 *
 * This covers the save path and the two screen states, plus the category
 * lookup bug the screen uncovered — CardsCo_Category::for_type() answers with
 * a list, and two callers were comparing it to a slug with ===, so a person
 * with a birthday on file came back as having no date at all.
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

ck( 'a company to work in', $cid > 0 );

$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
ck( 'a draft to add people to', ! is_wp_error( $pid ) );
$programme = CardsCo_Programme::get( $pid );

sect( 'the category lookup the screen leans on' );
$type = CardsCo_Category::primary_type( 'employee-birthdays', $cid );
ck( 'the collection has a dated type', (bool) $type );
/* The shape of this is the whole point: a list, because one occasion type
   can sit in more than one collection. Anything comparing it with === to a
   slug is broken, which is what two callers were doing. */
$cats = CardsCo_Category::for_type( (int) $type['id'], $cid );
ck( 'for_type answers with a list', is_array( $cats ) );
ck( 'and the collection is in it', in_array( 'employee-birthdays', $cats, true ) );
ck( 'so it is never equal to the slug itself', $cats === 'employee-birthdays', false );

sect( 'saving a person' );
$rid = CardsCo_Recipient::create(
	array(
		'company_id' => $cid,
		'type'       => 'employee',
		'first_name' => 'Daniel',
		'last_name'  => 'Okafor',
		'source'     => 'manual',
	)
);
ck( 'the person is created', ! is_wp_error( $rid ) );

$occ = CardsCo_Occasion::save(
	array(
		'recipient_id'     => (int) $rid,
		'occasion_type_id' => (int) $type['id'],
		'event_day'        => 29,
		'event_month'      => 10,
	)
);
ck( 'with a date attached', ! is_wp_error( $occ ) );
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $rid ) );
ck( 'and joined to the programme', in_array( (int) $rid, CardsCo_Programme::recipient_ids( $pid ), true ) );

sect( 'what the screen shows back' );
$view = CardsCo_Dashboard::setup_person( $programme, (int) $rid );

ck( 'the saved person comes back', (bool) $view['saved'] );
ck( 'by name',                      $view['saved']['name'], 'Daniel Okafor' );
/* The one that was silently empty before the for_type fix: the banner read
   "...goes out for their birthday on ." with nothing after "on". */
ck( 'with the date filled in',      $view['saved']['birthday'], '29 October' );
ck( 'and initials for the avatar',  $view['saved']['initials'], 'DO' );
ck( 'the list holds them',          count( $view['rows'] ) >= 1 );
ck( 'twelve months to choose from', count( $view['months'] ), 12 );
ck( 'named, not numbered',          $view['months'][10], 'October' );
ck( 'the next person is numbered after the ones already on',
    $view['ordinal'], count( $view['rows'] ) + 1 );

/* Without an id it is the empty form, not the confirmation. */
$blank = CardsCo_Dashboard::setup_person( $programme, 0 );
ck( 'no id means the empty form', $blank['saved'], null );
ck( 'but the list is still counted', count( $blank['rows'] ) >= 1 );

sect( 'the step 2 table, which had the same bug' );
$people = CardsCo_Dashboard::setup_people( $programme );
$row    = null;
foreach ( $people['rows'] as $r ) {
	if ( 'Daniel Okafor' === $r['name'] ) { $row = $r; }
}
ck( 'the person is listed',            (bool) $row );
ck( 'and is no longer shown as undated', $row['has_date'] );
ck( 'with the real date',              $row['birthday'], '29 October' );
ck( 'counted as having a birthday',    $people['counts'][0]['n'] >= 1 );

sect( '29 February is a real birthday' );
$leap = CardsCo_Recipient::create(
	array( 'company_id' => $cid, 'type' => 'employee', 'first_name' => 'Ada', 'last_name' => 'Leapling', 'source' => 'manual' )
);
$leap_occ = CardsCo_Occasion::save(
	array( 'recipient_id' => (int) $leap, 'occasion_type_id' => (int) $type['id'], 'event_day' => 29, 'event_month' => 2 )
);
ck( '29 February saves', ! is_wp_error( $leap_occ ) );
CardsCo_Programme::add_recipients( (int) $pid, array( (int) $leap ) );
ck( 'and reads back', CardsCo_Dashboard::setup_person( $programme, (int) $leap )['saved']['birthday'], '29 February' );

sect( 'what the wizard renders' );
/* step_on_screen() will not hand out a step the draft has not reached, so
   the draft is walked forward first — the same thing clicking Continue on
   step 1 does. */
CardsCo_Programme::update( (int) $pid, array( 'setup_step' => 3 ) );
$programme = CardsCo_Programme::get( $pid );

$_GET[ CardsCo_Setup::ARG ] = $pid;
$_GET['step']               = 2;
$_GET['cc_add']             = 1;
$form = do_shortcode( '[cardsco_setup]' );

ck( 'the form panel is there',     str_contains( $form, 'cc-pn-panel' ) );
ck( 'with the frame heading',      str_contains( $form, 'Add someone by hand' ) );
ck( 'posting the add action',      str_contains( $form, 'value="add_person"' ) );
ck( 'a day and a month field',     str_contains( $form, 'name="event_day"' ) && str_contains( $form, 'name="event_month"' ) );
ck( 'the spreadsheet way out',     str_contains( $form, 'Upload a spreadsheet instead' ) );
/* The frame's footer on this state is Back and a count — no Continue
   beside the panel's own Save person. */
ck( 'no Continue while the form is open', str_contains( $form, 'form="ccWizForm"' ), false );
ck( 'the running count instead',          str_contains( $form, 'people added' ) );

unset( $_GET['cc_add'] );
$_GET['cc_added'] = (int) $rid;
$done = do_shortcode( '[cardsco_setup]' );

ck( 'the saved state confirms by name', str_contains( $done, 'Daniel Okafor added' ) );
ck( 'and says when the card goes out',  str_contains( $done, '29 October' ) );
ck( 'the list of who is on',            str_contains( $done, 'Added so far' ) );
ck( 'a way to add another',             str_contains( $done, 'Add another person' ) );
ck( 'and Continue is back',             str_contains( $done, 'form="ccWizForm"' ) );

sect( 'the link that used to go nowhere' );
unset( $_GET['cc_added'] );
$list = do_shortcode( '[cardsco_setup]' );
ck( 'step 2 offers the by-hand route', str_contains( $list, 'Add someone by hand' ) );
ck( 'and it points at the form',       str_contains( $list, 'cc_add=1' ) );

/*
 * Clean up by hand: there is no CardsCo_Programme::delete(), and a draft
 * left behind is found by draft_for() in other suites, which is exactly the
 * kind of cross-suite pollution that has caused false failures before.
 */
global $wpdb;
foreach ( array( $rid, $leap ) as $id ) {
	CardsCo_Recipient::delete( (int) $id );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $pid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
