<?php
/**
 * Team access — who may do what to whom.
 *
 * Every company account holds the same WordPress capabilities, so a
 * capability check alone lets a junior colleague remove the person who
 * signed the company up. What separates them is the role on the row
 * joining that person to that company: owner, then admin, then member.
 *
 * The panel only draws the buttons a viewer may press, which is the right
 * thing to show and no protection at all — a form can be posted without
 * the panel ever being drawn. So what is checked here is the handler,
 * against requests the screen would never produce.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/settings/?tab=team';
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

/* Find the owner of a company, and a colleague in it. */
$owner = 0;
foreach ( get_users( array( 'number' => 50 ) ) as $u ) {
	if ( user_can( $u, 'cardsco_access_dashboard' ) && cardsco_get_user_company_id( $u->ID ) ) {
		wp_set_current_user( $u->ID );
		if ( 'owner' === CardsCo_Access::company_role() ) { $owner = $u->ID; break; }
	}
}

wp_set_current_user( $owner );
$cid = cardsco_get_user_company_id( $owner );

$member = 0;
foreach ( CardsCo_Invite::people( $cid ) as $person ) {
	if ( 'owner' !== $person['role'] && ! $person['pending'] ) { $member = (int) $person['user_id']; break; }
}

if ( ! $owner || ! $member ) {
	echo "fixtures missing an owner or a colleague\n";
	exit( 1 );
}

/*
 * Start from a known shape: the colleague back to a plain member, and no
 * invitations left over from an earlier run or from somebody clicking
 * around the site. A suite that counts things has to own what it counts.
 */
CardsCo_Company::add_user( $cid, $member, 'member', 'active' );

foreach ( CardsCo_Invite::pending( $cid ) as $cc_stale ) {
	CardsCo_Invite::revoke( $cid, (int) $cc_stale['user_id'] );
}

class CCTeamRedirect extends Exception {}
add_filter( 'wp_redirect', function ( $to ) { throw new CCTeamRedirect( $to ); }, 1 );

function team_post( array $data ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(
		array(
			'cardsco_settings_action' => 'team',
			'_cardsco_nonce'          => wp_create_nonce( 'cardsco_settings' ),
		),
		$data
	);
	$_REQUEST = $_POST;

	try { ( new CardsCo_Shortcodes() )->handle_settings(); }
	catch ( CCTeamRedirect $r ) { return $r->getMessage(); }
	catch ( Throwable $e ) { return 'THREW: ' . $e->getMessage(); }
	finally { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); }

	return 'no redirect';
}

function said( $url, $key ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return isset( $q[ $key ] ) ? rawurldecode( (string) $q[ $key ] ) : '';
}

sect( 'the roles mean something' );
ck( 'the owner is the owner', CardsCo_Access::company_role( $owner, $cid ), 'owner' );
ck( 'and may manage the company', CardsCo_Access::may_manage_company( $owner ) );
ck( 'a member is a member', CardsCo_Access::company_role( $member, $cid ), 'member' );
ck( 'and may not', CardsCo_Access::may_manage_company( $member ), false );
ck( 'somebody outside it has no role', CardsCo_Access::company_role( 999999, $cid ), '' );

sect( 'nobody may act on the owner — including the owner' );
ck( 'the owner cannot remove themselves', CardsCo_Access::may_manage_person( $owner ), false );
$url = team_post( array( 'team_remove' => $owner ) );
ck( 'and a posted attempt is refused', (bool) said( $url, 'cardsco_error' ) );
ck( 'the owner is still there', CardsCo_Access::company_role( $owner, $cid ), 'owner' );

sect( 'a member cannot manage anybody' );
wp_set_current_user( $member );
ck( 'not even in theory', CardsCo_Access::may_manage_person( $owner ), false );

$url = team_post( array( 'team_remove' => $owner ) );
ck( 'removing the owner is refused', (bool) said( $url, 'cardsco_error' ) );
ck( 'and says who may', said( $url, 'cardsco_error' ), 'Only an owner or admin can change this.' );

$url = team_post( array( 'team_invite' => 1, 'team_email' => 'sneaky@example.com', 'team_role' => 'admin' ) );
ck( 'inviting is refused too', (bool) said( $url, 'cardsco_error' ) );
ck( 'and nobody was invited', count( CardsCo_Invite::pending( $cid ) ), 0 );

$url = team_post( array( 'team_set_role' => $member, 'role_for' => array( $member => 'admin' ) ) );
ck( 'and they cannot promote themselves', CardsCo_Access::company_role( $member, $cid ), 'member' );

wp_set_current_user( $owner );

sect( 'the owner can promote and demote' );
$url = team_post( array( 'team_set_role' => $member, 'role_for' => array( $member => 'admin' ) ) );
ck( 'it saves', (bool) said( $url, 'cardsco_saved' ) );
ck( 'they are an admin now', CardsCo_Access::company_role( $member, $cid ), 'admin' );

sect( 'an admin may manage, but not the owner' );
wp_set_current_user( $member );
ck( 'they may manage the company', CardsCo_Access::may_manage_company() );
ck( 'but not the owner', CardsCo_Access::may_manage_person( $owner ), false );
$url = team_post( array( 'team_remove' => $owner ) );
ck( 'removing the owner is still refused', (bool) said( $url, 'cardsco_error' ) );
ck( 'and the owner remains', CardsCo_Access::company_role( $owner, $cid ), 'owner' );

sect( 'the owner role cannot be handed out from the dropdown' );
wp_set_current_user( $owner );
team_post( array( 'team_set_role' => $member, 'role_for' => array( $member => 'owner' ) ) );
ck( 'asking for owner gives member', CardsCo_Access::company_role( $member, $cid ), 'member' );
ck( 'and there is still one owner', count( array_filter( CardsCo_Invite::people( $cid ), static function ( $p ) { return 'owner' === $p['role']; } ) ), 1 );

sect( 'inviting' );
$url = team_post( array( 'team_invite' => 1, 'team_email' => 'not-an-email', 'team_role' => 'member' ) );
ck( 'a bad address is refused', said( $url, 'cardsco_error' ), 'That does not look like an email address.' );

$url = team_post( array( 'team_invite' => 1, 'team_email' => 'newcomer@example.com', 'team_role' => 'member' ) );
ck( 'a good one is sent', (bool) said( $url, 'cardsco_saved' ) );
$pending = CardsCo_Invite::pending( $cid );
ck( 'and shows as waiting', count( $pending ), 1 );
ck( 'with no way into the company yet', cardsco_get_user_company_id( (int) $pending[0]['user_id'] ), 0 );

$url = team_post( array( 'team_revoke' => (int) $pending[0]['user_id'] ) );
ck( 'withdrawing it works', (bool) said( $url, 'cardsco_saved' ) );
ck( 'and it is gone', count( CardsCo_Invite::pending( $cid ) ), 0 );

sect( 'the panel shows only what the viewer may press' );
$_GET = array( 'tab' => 'team' );
$html = do_shortcode( '[cardsco_settings]' );
ck( 'the owner sees the invite form', str_contains( $html, 'name="team_email"' ) );
ck( 'and a remove button for the colleague', str_contains( $html, 'name="team_remove"' ) );
ck( 'the owner has no buttons of their own', substr_count( $html, 'name="team_remove"' ), 1 );

wp_set_current_user( $member );
$html = do_shortcode( '[cardsco_settings]' );
ck( 'a member sees no invite form', str_contains( $html, 'name="team_email"' ), false );
ck( 'no remove buttons', str_contains( $html, 'name="team_remove"' ), false );
ck( 'no role dropdowns', str_contains( $html, 'name="role_for' ), false );
ck( 'and is told why', str_contains( $html, 'Only an owner or admin can invite people' ) );
wp_set_current_user( $owner );

sect( 'removing somebody leaves their work behind' );
$prog = CardsCo_Programme::create( $cid, 'employee-birthdays' );
CardsCo_Programme::update( (int) $prog, array( 'status' => 'active' ) );
team_post( array( 'team_remove' => $member ) );
ck( 'they are out', CardsCo_Access::company_role( $member, $cid ), '' );
$still = CardsCo_Programme::get( (int) $prog );
ck( 'the occasion is still there', (bool) $still );
ck( 'and still running', $still['status'], 'active' );
ck( 'and still the company’s', (int) $still['company_id'], $cid );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cardsco_table( 'programmes' ) . ' WHERE id = %d', $prog ) );
CardsCo_Company::add_user( $cid, $member, 'member', 'active' );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
