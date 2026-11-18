<?php
/**
 * Put the test library back where it started, before the suites run.
 *
 * Suites create cards, messages and products and mostly do not remove them.
 * Left alone the published card library creeps upward, and once it passes
 * the 200-row window CardsCo_Card::for_occasion() reads, unrelated suites
 * start failing on cards that fell off the end. That happened, and it read
 * exactly like a code bug for a while.
 *
 * The first run records the highest post ID as the mark; every run after
 * removes anything above it. No suite has to remember to tidy up.
 */
$_SERVER['HTTP_HOST']   = 'cardsco.test';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/core/wp-load.php';
global $wpdb;

$types = array( 'cardsco_card', 'cardsco_message', 'product' );
$in    = "'" . implode( "','", $types ) . "'";
$mark  = (int) get_option( 'cardsco_fixture_mark', 0 );

if ( ! $mark ) {
	$mark = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	update_option( 'cardsco_fixture_mark', $mark );
	echo "fixture mark set at post $mark\n";
	return;
}

$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND ID > $mark" );

foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cardsco_card' AND post_status = 'publish'" );
printf( "cleared %d posts left by earlier runs; %d published cards in the library\n", count( $ids ), $left );
