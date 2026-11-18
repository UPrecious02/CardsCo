<?php
/**
 * Orders — three sources read as one list.
 *
 * There is no orders table. An order is a monthly box, a campaign, or a
 * shop order, and this screen reads all three and sorts them together. The
 * things worth pinning down are the seams:
 *
 *  - a reference has to survive the round trip, because a customer quotes
 *    it back at you and somebody has to find the order it names. A box,
 *    a campaign and a shop order can all be id 42, so the kind is part of
 *    the reference or three orders answer to one number;
 *
 *  - the tab counts are taken before the tab filter, so the numbers do not
 *    change as you click between tabs;
 *
 *  - the two figures under "In progress" answer different questions, and
 *    a box scheduled for December is not cards being written today.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/orders/';
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
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) { $uid = $u->ID; break; }
}
wp_set_current_user( $uid );
$cid = cardsco_get_user_company_id( $uid );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'boxes' ) . ' WHERE company_id = %d', $cid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'campaigns' ) . ' WHERE company_id = %d', $cid ) );

$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $pid, array( 'status' => 'active', 'setup_step' => 7 ) );

$ago = static function ( $months ) {
	return gmdate( 'Y-m-d', strtotime( '-' . (int) $months . ' months', (int) current_time( 'timestamp' ) ) );
};

/* Two boxes this year and one well outside the twelve-month window. */
$boxes = array(
	array( 'delivered', 14, 49.00, $ago( 1 ) ),
	array( 'dispatched', 9, 31.50, $ago( 2 ) ),
	array( 'delivered', 7, 24.50, $ago( 20 ) ),
	array( 'queued', 6, null, $ago( 0 ) ),
);

foreach ( $boxes as $i => $box ) {
	list( $status, $n, $amount, $made ) = $box;
	$wpdb->insert(
		cardsco_table( 'boxes' ),
		array(
			'company_id' => $cid, 'programme_id' => (int) $pid,
			'period' => substr( $made, 0, 7 ), 'arrive_by' => $made, 'write_by' => $made,
			'card_count' => $n, 'status' => $status, 'amount' => $amount, 'currency' => 'GBP',
			'payment_status' => null === $amount ? 'pending' : 'paid',
			'created_at' => $made . ' 09:00:00', 'updated_at' => $made . ' 09:00:00',
		)
	);
}

$wpdb->insert(
	cardsco_table( 'campaigns' ),
	array(
		'company_id' => $cid, 'name' => 'Christmas thank-you', 'recipient_count' => 400,
		'status' => 'scheduled', 'total_amount' => null, 'currency' => 'GBP',
		'billing_status' => 'quote', 'send_date' => '2026-12-01',
		'created_at' => $ago( 1 ) . ' 10:00:00', 'updated_at' => $ago( 1 ) . ' 10:00:00',
	)
);
$camp_quote = (int) $wpdb->insert_id;

$wpdb->insert(
	cardsco_table( 'campaigns' ),
	array(
		'company_id' => $cid, 'name' => 'Q4 loyalty offer', 'recipient_count' => 250,
		'status' => 'writing', 'total_amount' => 876.00, 'currency' => 'GBP',
		'billing_status' => 'invoiced',
		'created_at' => $ago( 2 ) . ' 10:00:00', 'updated_at' => $ago( 2 ) . ' 10:00:00',
	)
);

/* A draft is not an order and must not appear. */
$wpdb->insert(
	cardsco_table( 'campaigns' ),
	array(
		'company_id' => $cid, 'name' => 'Half-written idea', 'recipient_count' => 10,
		'status' => 'draft', 'currency' => 'GBP',
		'created_at' => $ago( 1 ) . ' 11:00:00', 'updated_at' => $ago( 1 ) . ' 11:00:00',
	)
);

sect( 'a reference survives the round trip' );
ck( 'a box reads as CC-1…', CardsCo_Orders::reference( 'recurring', 491 ), 'CC-10491' );
ck( 'a campaign as CC-2…', CardsCo_Orders::reference( 'campaign', 491 ), 'CC-20491' );
ck( 'a shop order as CC-3…', CardsCo_Orders::reference( 'bulk', 491 ), 'CC-30491' );

foreach ( CardsCo_Orders::KINDS as $kind ) {
	$back = CardsCo_Orders::from_reference( CardsCo_Orders::reference( $kind, 1234 ) );
	ck( sprintf( '%s comes back whole', $kind ), $back, array( 'kind' => $kind, 'id' => 1234 ) );
}

ck( 'the same id in three kinds is three references', 3, count( array_unique( array(
	CardsCo_Orders::reference( 'recurring', 42 ),
	CardsCo_Orders::reference( 'campaign', 42 ),
	CardsCo_Orders::reference( 'bulk', 42 ),
) ) ) );
ck( 'rubbish is refused', CardsCo_Orders::from_reference( 'CC-9999' ), null );
ck( 'and so is an empty string', CardsCo_Orders::from_reference( '' ), null );
ck( 'lower case still reads', CardsCo_Orders::from_reference( 'cc-20491' )['kind'], 'campaign' );

sect( 'the three sources arrive as one list' );
$found = CardsCo_Orders::all( $cid );
$kinds = array_count_values( array_column( $found['rows'], 'kind' ) );
ck( 'boxes are in it', (int) ( $kinds['recurring'] ?? 0 ), 3 );
ck( 'campaigns too', (int) ( $kinds['campaign'] ?? 0 ), 2 );
ck( 'the draft campaign is not', ! in_array( 'Half-written idea', array_column( $found['rows'], 'title' ), true ) );
ck( 'and the box from 20 months ago is outside the window', $found['total'], 5 );

sect( 'newest first, whichever source it came from' );
$dates = array_map(
	static function ( $r ) { return substr( (string) $r['placed'], 0, 10 ); },
	$found['rows']
);
$sorted = $dates;
rsort( $sorted );
ck( 'the list is in date order', $dates, $sorted );
ck( 'and it mixes the sources rather than grouping them', count( array_unique( array_slice( array_column( $found['rows'], 'kind' ), 0, 3 ) ) ) > 1 );

sect( 'tab counts do not move when you click a tab' );
$all  = CardsCo_Orders::all( $cid, array( 'kind' => 'all' ) );
$camp = CardsCo_Orders::all( $cid, array( 'kind' => 'campaign' ) );
ck( 'the counts match from either tab', $camp['counts'], $all['counts'] );
ck( 'but the rows are narrowed', $camp['total'], 2 );
ck( 'to campaigns only', array_unique( array_column( $camp['rows'], 'kind' ) ), array( 'campaign' ) );

sect( 'search looks where the placeholder promises' );
ck( 'by reference', CardsCo_Orders::all( $cid, array( 'search' => 'CC-2' ) )['total'], 2 );
ck( 'by description', CardsCo_Orders::all( $cid, array( 'search' => 'loyalty' ) )['total'], 1 );
ck( 'ignoring case', CardsCo_Orders::all( $cid, array( 'search' => 'LOYALTY' ) )['total'], 1 );
ck( 'by status in words', CardsCo_Orders::all( $cid, array( 'search' => 'Delivered' ) )['total'] > 0 );
ck( 'and finds nothing when there is nothing', CardsCo_Orders::all( $cid, array( 'search' => 'zzzz' ) )['total'], 0 );

sect( 'the window' );
ck( 'a month back is a shorter list', CardsCo_Orders::all( $cid, array( 'months' => 1 ) )['total'] < $found['total'] );
ck( 'all time reaches the old box', CardsCo_Orders::all( $cid, array( 'months' => 0 ) )['total'], 6 );

sect( 'pages' );
$p1 = CardsCo_Orders::all( $cid, array( 'per_page' => 2, 'page' => 1 ) );
$p2 = CardsCo_Orders::all( $cid, array( 'per_page' => 2, 'page' => 2 ) );
ck( 'two to a page', count( $p1['rows'] ), 2 );
ck( 'three pages of five', $p1['pages'], 3 );
ck( 'showing 1–2', array( $p1['from'], $p1['to'] ), array( 1, 2 ) );
ck( 'then 3–4', array( $p2['from'], $p2['to'] ), array( 3, 4 ) );
ck( 'and the pages hold different orders', array_intersect( array_column( $p1['rows'], 'reference' ), array_column( $p2['rows'], 'reference' ) ), array() );
$over = CardsCo_Orders::all( $cid, array( 'per_page' => 2, 'page' => 99 ) );
ck( 'asking past the end lands on the last page', $over['page'], 3 );

sect( 'the figures across the top' );
$stats = CardsCo_Orders::stats( $cid );
/*
 * Four are still in flight: the dispatched box, the queued box, the
 * scheduled campaign and the one being written. The two delivered boxes
 * and the draft are not.
 */
ck( 'in progress counts orders not yet landed', $stats['in_flight'], 4 );
/*
 * But only two of those four have cards in hand — the dispatched box (9)
 * and the campaign being written (250). The queued box and the scheduled
 * campaign are 406 cards that nobody has picked up a pen for, and
 * counting them would overstate the work in the building by half.
 */
ck( 'and only counts cards actually under way', $stats['writing'], 259 );
ck( 'which leaves the not-yet-started ones out', $stats['writing'] < 259 + 406 );
ck( 'cards this year adds up the year', $stats['cards'] > 0 );
ck( 'the month is named', (bool) $stats['month'] );

sect( 'a price that is not set is not a price of zero' );
$quote = null;
foreach ( CardsCo_Orders::all( $cid, array( 'months' => 0 ) )['rows'] as $row ) {
	if ( 'Christmas thank-you' === $row['title'] ) { $quote = $row; }
}
ck( 'the unquoted campaign has no amount', null === $quote['amount'] );
ck( 'rather than zero', 0.0 === $quote['amount'], false );

sect( 'statuses wear the pills the rest of the dashboard uses' );
ck( 'delivered is a success', CardsCo_Orders::tone( 'delivered' ), 'success' );
ck( 'a Woo completed order too', CardsCo_Orders::tone( 'completed' ), 'success' );
ck( 'writing is in flight', CardsCo_Orders::tone( 'writing' ), 'info' );
ck( 'scheduled is quiet', CardsCo_Orders::tone( 'scheduled' ), 'neutral' );
ck( 'and a failure is a failure', CardsCo_Orders::tone( 'failed' ), 'error' );
foreach ( array( 'success', 'info', 'neutral', 'error' ) as $tone ) {
	ck( sprintf( '.cc-pill.is-%s exists', $tone ), str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/cardsco/assets/dashboard.css' ), '.cc-pill.is-' . $tone ) );
}

sect( 'the screen renders' );
$_GET = array();
$html = do_shortcode( '[cardsco_orders]' );
ck( 'it has a table', str_contains( $html, 'cc-ord-row' ) );
ck( 'with the frame’s columns', str_contains( $html, 'cc-ord-c-status' ) );
ck( 'the heading', str_contains( $html, 'Orders' ) );
ck( 'and the frame’s footnote', str_contains( $html, 'Totals include VAT and UK postage' ) );
ck( 'the download carries a nonce', str_contains( $html, '_wpnonce' ) );
ck( 'an unpriced box is not called a quote', str_contains( $html, 'Not yet charged' ) );
ck( 'but an unpriced campaign is', str_contains( $html, 'Quote pending' ) );

$_GET = array( 'q' => 'zzzz' );
$empty = do_shortcode( '[cardsco_orders]' );
ck( 'a search with no hits says so', str_contains( $empty, 'Nothing matches that search' ) );
ck( 'and does not draw an empty table head', str_contains( $empty, 'cc-ord-head' ), false );
$_GET = array();

sect( 'the download takes the filters with it' );
$url = CardsCo_Orders_Export::url( 'campaign', 'loyalty', 3 );
ck( 'the tab rides along', str_contains( $url, 'tab=campaign' ) );
ck( 'the search too', str_contains( $url, 'q=loyalty' ) );
ck( 'and the window', str_contains( $url, 'months=3' ) );
ck( 'it is signed', str_contains( $url, '_wpnonce' ) );

$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'boxes' ) . ' WHERE company_id = %d', $cid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'campaigns' ) . ' WHERE company_id = %d', $cid ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $pid ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
