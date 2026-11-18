<?php
/**
 * Step 2 — the template download and the spreadsheet upload.
 *
 * Both controls on the upload panel were anchors pointing at the page they
 * were already on, so neither did anything. The work behind them had been
 * written months ago — CardsCo_Programme_Import::template() and ::run() —
 * and nothing was pointed at it.
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
	printf( "  %-5s %-54s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
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
$programme = CardsCo_Programme::get( $pid );

sect( 'the example file' );
$csv = CardsCo_Programme_Import::template( $programme );
ck( 'it has a body',            '' !== trim( $csv ) );
ck( 'with a header row',        str_contains( strtolower( $csv ), 'first name' ) );
ck( 'naming the occasion',      str_contains( strtolower( $csv ), 'birthday' ) );
ck( 'and example rows',         substr_count( trim( $csv ), "\n" ) >= 3 );

$url = CardsCo_Setup::template_url( $programme );
/*
 * This used to assert the link pointed at admin-post.php, which is where
 * it did point and exactly where it must not: portal customers are shut
 * out of /wp-admin/, so the download bounced them to the dashboard. The
 * assertion passed throughout, because it was written from the code
 * rather than from what a customer gets when they click it.
 */
ck( 'the link stays on the front end', str_contains( $url, 'admin-post.php' ), false );
ck( 'naming what it wants',            str_contains( $url, 'cardsco_dl=people' ) );
ck( 'carrying the programme',       str_contains( $url, 'programme=' . $pid ) );
/* Without this anybody could pull another company's list shape by guessing
   an id, and the handler's own check would never be reached. */
ck( 'and a nonce',                  str_contains( $url, '_wpnonce=' ) );

sect( 'the link on the step' );
$_GET[ CardsCo_Setup::ARG ] = $pid;
$_GET['step']               = 2;
$html = do_shortcode( '[cardsco_setup]' );

/* Pull the actual href out rather than matching any anchor: the old
 * assertion used [^"]* and so passed whatever the link pointed at, which is
 * exactly the bug it was supposed to catch. */
preg_match( '#href="([^"]+)"[^>]*>\s*Download template#', $html, $m );
$href = html_entity_decode( $m[1] ?? '' );

ck( 'the link has an href',                     '' !== $href );
ck( 'staying out of the admin',                 str_contains( $href, 'admin-post.php' ), false );
ck( 'with the download named on it',            str_contains( $href, 'cardsco_dl=people' ) );
ck( 'and the programme',                        str_contains( $href, 'programme=' . $pid ) );
/*
 * The download now sits on the wizard's own address, which is what the
 * original fault looked like: a link that defaults to the current page and
 * does nothing when clicked. The difference is the arguments, so that is
 * what to check — the bare page is still a dead link.
 */
$cc_stripped = remove_query_arg( array( 'cardsco_dl', 'programme', '_wpnonce' ), $href );
ck( 'and is more than the page it sits on',     $cc_stripped !== $href );

sect( 'the upload control is a form, not a link' );
ck( 'there is an upload form',      str_contains( $html, 'id="ccUpload"' ) );
ck( 'it can carry a file',          str_contains( $html, 'enctype="multipart/form-data"' ) );
ck( 'with a file input',            str_contains( $html, 'type="file"' ) );
ck( 'named for the handler',        str_contains( $html, 'name="people_file"' ) );
ck( 'posting the upload action',    str_contains( $html, 'value="upload_people"' ) );
/* Without JavaScript the label cannot submit, so a real button has to be
   there for the browser to fall back to. */
ck( 'and a submit button behind it', str_contains( $html, 'data-cc-up-go' ) );

sect( 'reading a list in' );
$file = tempnam( sys_get_temp_dir(), 'cc' ) . '.csv';
file_put_contents(
	$file,
	"First name,Last name,Birthday\n"
	. "Tunde,Bakare,14 November\n"
	. "Hannah,Lee,03/11\n"
	. "Marcus,Reid,1990-12-16\n"
	. "Nodate,Person,\n"
);

$before = count( CardsCo_Programme::recipient_ids( $pid ) );
$result = CardsCo_Programme_Import::read_file( $pid, $file, false );

ck( 'the file is read',          ! is_wp_error( $result ) );
ck( 'every row is counted',      (int) $result['total'], 4 );
ck( 'four people go on',         count( CardsCo_Programme::recipient_ids( $pid ) ) - $before, 4 );
ck( 'three of them with a date', (int) $result['with_date'], 3 );
ck( 'and one without',           (int) $result['missing_date'], 1 );

/* The dates are the point: three different formats, one occasion. */
$found = array();
foreach ( CardsCo_Programme::recipient_ids( $pid ) as $rid ) {
	$row = CardsCo_Dashboard::setup_person( $programme, (int) $rid );
	if ( $row['saved'] ) { $found[ $row['saved']['name'] ] = $row['saved']['birthday']; }
}
ck( '"14 November" is understood', $found['Tunde Bakare'] ?? '', '14 November' );
ck( '"03/11" is understood',       $found['Hannah Lee'] ?? '', '3 November' );
ck( '"1990-12-16" is understood',  $found['Marcus Reid'] ?? '', '16 December' );
ck( 'and the empty one has none',  $found['Nodate Person'] ?? 'x', '' );

sect( 'running it again matches rather than duplicates' );
$again = CardsCo_Programme_Import::read_file( $pid, $file, false );
ck( 'nobody new is created',  (int) $again['added'], 0 );
ck( 'they are matched',       (int) $again['matched'], 4 );
ck( 'and the list is the same length', count( CardsCo_Programme::recipient_ids( $pid ) ) - $before, 4 );

sect( 'what the step says afterwards' );
$_GET['cc_up_added']   = 4;
$_GET['cc_up_matched'] = 1;
$_GET['cc_up_nodate']  = 1;
$_GET['cc_up_bad']     = 0;
$after = do_shortcode( '[cardsco_setup]' );

ck( 'it reports what went in',  str_contains( $after, '4 people added' ) );
ck( 'what was already there',   str_contains( $after, 'already on your list' ) );
ck( 'and what had no date',     str_contains( $after, 'without a date' ) );
/* Nothing unreadable, so nothing about unreadable rows. */
ck( 'but not about rows it read fine', str_contains( $after, 'could not be read' ), false );

unset( $_GET['cc_up_added'], $_GET['cc_up_matched'], $_GET['cc_up_nodate'], $_GET['cc_up_bad'] );
ck( 'and says nothing when no upload just happened',
    str_contains( do_shortcode( '[cardsco_setup]' ), 'people added' ), false );

@unlink( $file );
global $wpdb;
foreach ( CardsCo_Programme::recipient_ids( $pid ) as $rid ) {
	CardsCo_Recipient::delete( (int) $rid );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programme_recipients' ) . ' WHERE programme_id = %d', $pid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
