<?php
/**
 * Nothing the portal shows a customer may point into /wp-admin/.
 *
 * Portal customers are blocked from the WordPress admin on this site, so
 * any link that lands there bounces them to the dashboard. Two downloads
 * did exactly that — the orders CSV and the wizard's example file — and
 * both went unnoticed because whoever tries them while logged in as an
 * administrator sees them work perfectly.
 *
 * The reasoning behind the mistake was that admin-post.php is simply
 * WordPress's endpoint for a logged-in request that is not a page, which
 * is true of WordPress and false of this site. So this does not test the
 * reasoning; it tests the addresses the portal actually renders.
 *
 * The admin screens are a different matter and are left alone: they are
 * for staff, who can reach /wp-admin/ by definition.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/orders/';
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

/* Something to download, so the button is rendered at all. */
global $wpdb;
$pid = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $pid, array( 'status' => 'active', 'setup_step' => 7 ) );
$wpdb->insert(
	cardsco_table( 'boxes' ),
	array(
		'company_id' => $cid, 'programme_id' => (int) $pid, 'period' => gmdate( 'Y-m' ),
		'arrive_by' => gmdate( 'Y-m-d' ), 'write_by' => gmdate( 'Y-m-d' ), 'card_count' => 9,
		'status' => 'delivered', 'amount' => 31.50, 'currency' => 'GBP', 'payment_status' => 'paid',
		'created_at' => cardsco_now(), 'updated_at' => cardsco_now(),
	)
);
$box = (int) $wpdb->insert_id;

/* Every screen a customer sees. */
$screens = array(
	'orders'      => '[cardsco_orders]',
	'occasions'   => '[cardsco_occasions]',
	'campaigns'   => '[cardsco_campaigns]',
	'dashboard'   => '[cardsco_dashboard]',
	'catalogue'   => '[cardsco_catalogue]',
	'collections' => '[cardsco_collections]',
);

sect( 'no portal screen links into the admin' );

foreach ( $screens as $name => $shortcode ) {
	$_GET = array();
	$html = do_shortcode( $shortcode );

	preg_match_all( '/href="([^"]*wp-admin[^"]*)"/', $html, $found );

	ck( sprintf( '%s has no wp-admin links', $name ), count( $found[1] ), 0 );

	foreach ( array_slice( $found[1], 0, 3 ) as $bad ) {
		echo '        ' . html_entity_decode( $bad ) . "\n";
	}
}

sect( 'and neither does the wizard, on any step' );
$draft = CardsCo_Programme::create( $cid, 'employee-birthdays' );

for ( $step = 1; $step <= 7; $step++ ) {
	$_GET = array( CardsCo_Setup::ARG => $draft, 'step' => $step );
	$html = do_shortcode( '[cardsco_setup]' );

	preg_match_all( '/href="([^"]*wp-admin[^"]*)"/', $html, $found );

	ck( sprintf( 'step %d is clean', $step ), count( $found[1] ), 0 );

	foreach ( array_slice( $found[1], 0, 2 ) as $bad ) {
		echo '        ' . html_entity_decode( $bad ) . "\n";
	}
}

sect( 'the downloads are served from the front end' );
$orders = CardsCo_Orders_Export::url( 'all', '', 12 );
ck( 'the orders CSV is not an admin address', str_contains( $orders, 'wp-admin' ), false );
ck( 'it names what it wants', str_contains( $orders, 'cardsco_dl=orders' ) );
ck( 'and is signed', str_contains( $orders, '_wpnonce' ) );

$template = CardsCo_Setup::template_url( CardsCo_Programme::get( $draft ) );
ck( 'the example file is not either', str_contains( $template, 'wp-admin' ), false );
ck( 'it names what it wants', str_contains( $template, 'cardsco_dl=people' ) );
ck( 'and is signed', str_contains( $template, '_wpnonce' ) );

sect( 'only the files meant to be downloadable can be asked for' );
$handlers = new ReflectionMethod( 'CardsCo_Download', 'handlers' );
$handlers->setAccessible( true );
$allowed = array_keys( (array) $handlers->invoke( null ) );
ck( 'the list is closed', $allowed, array( 'people', 'orders' ) );

sect( 'nothing to download means no button' );
$_GET = array();
$html = do_shortcode( '[cardsco_orders]' );
ck( 'with orders, the button is there', str_contains( $html, 'cc-ord-dl' ) );

$_GET = array( 'q' => 'zzzznothing' );
$html = do_shortcode( '[cardsco_orders]' );
ck( 'with none, it is not', str_contains( $html, 'cc-ord-dl' ), false );

sect( 'and a month with no orders does not read as a fault' );
$_GET = array();
$html = do_shortcode( '[cardsco_orders]' );
ck( 'no "+0 in" anywhere', str_contains( $html, '+0 in' ), false );

$wpdb->delete( cardsco_table( 'boxes' ), array( 'id' => $box ), array( '%d' ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id IN (%d, %d)', $pid, $draft ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
