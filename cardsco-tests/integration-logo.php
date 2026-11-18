<?php
/**
 * The company logo, uploaded by somebody who cannot reach wp-admin.
 *
 * The Settings screen used to send people to /wp-admin/upload.php for
 * this, which portal customers are shut out of, so no customer could ever
 * put a logo on their own cards. It was invisible because an
 * administrator testing it sees the media library open perfectly.
 *
 * The interesting part is what a logo field must refuse. A file is named
 * by whoever uploads it and its type is declared by their browser, so
 * neither can be allowed to decide anything: a PHP script called
 * logo.php.png must not become a PHP script on the server.
 *
 * What is checked here is the rules. Moving the file is not: WordPress
 * will only move something that arrived as a genuine HTTP upload, which a
 * fixture on disk is not. That half is covered by driving a browser
 * through the real Settings form — see the notes with this build.
 */

$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/settings/';
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

sect( 'the person doing this is a customer, not staff' );
ck( 'they can change their company', current_user_can( 'cardsco_manage_company' ) );
ck( 'but they are not an administrator', current_user_can( 'manage_options' ), false );
/*
 * This is why media_handle_upload() cannot be used: it demands this, and
 * granting it would be the right to put any file in the media library.
 */
ck( 'and cannot upload files generally', current_user_can( 'upload_files' ), false );

/* A real, small PNG, built here so the test carries its own fixture. */
$cc_chunk = static function ( $type, $data ) {
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
};

$cc_w   = 8;
$cc_raw = '';

for ( $cc_y = 0; $cc_y < $cc_w; $cc_y++ ) {
	$cc_raw .= chr( 0 ) . str_repeat( chr( 10 ) . chr( 90 ) . chr( 180 ), $cc_w );
}

$png = "\x89PNG\r\n\x1a\n"
	. $cc_chunk( 'IHDR', pack( 'NNCCCCC', $cc_w, $cc_w, 8, 2, 0, 0, 0 ) )
	. $cc_chunk( 'IDAT', gzcompress( $cc_raw ) )
	. $cc_chunk( 'IEND', '' );

$dir = get_temp_dir() . 'cardsco-logo-test/';
wp_mkdir_p( $dir );

$make = static function ( $name, $bytes ) use ( $dir ) {
	$path = $dir . $name;
	file_put_contents( $path, $bytes );
	return array(
		'name'     => $name,
		'type'     => 'image/png',
		'tmp_name' => $path,
		'error'    => 0,
		'size'     => strlen( $bytes ),
	);
};

sect( 'a real PNG is allowed' );
$good = $make( 'logo.png', $png );
ck( 'it passes the rules', true === CardsCo_Logo::check( $good ) );

sect( 'a script wearing a .png name is refused' );
$evil = $make( 'logo.php.png', '<?php echo "owned"; ?>' );
$out  = CardsCo_Logo::check( $evil );
ck( 'it is refused', is_wp_error( $out ) );
ck( 'for being the wrong type', is_wp_error( $out ) ? $out->get_error_code() : '', 'cardsco_logo_type' );

sect( 'and so is anything that is not an image' );
$fake = $make( 'notreally.png', 'this is just text' );
$out  = CardsCo_Logo::check( $fake );
ck( 'refused', is_wp_error( $out ) );

/* A PNG header with nothing behind it: passes a careless check, is not an image. */
$stub = $make( 'stub.png', "\x89PNG\r\n\x1a\n" . str_repeat( '0', 200 ) );
$out  = CardsCo_Logo::check( $stub );
ck( 'a PNG header alone is not enough', is_wp_error( $out ) );

sect( 'the browser’s word for the type decides nothing' );
$lying         = $make( 'logo2.png', '<?php echo "owned"; ?>' );
$lying['type'] = 'image/png';
ck( 'still refused', is_wp_error( CardsCo_Logo::check( $lying ) ) );

sect( 'size has a limit' );
$big = $make( 'big.png', $png );
$big['size'] = CardsCo_Logo::MAX_BYTES + 1;
$out = CardsCo_Logo::check( $big );
ck( 'too big is refused', is_wp_error( $out ) );
ck( 'and says so', is_wp_error( $out ) ? $out->get_error_code() : '', 'cardsco_logo_big' );

sect( 'an upload the server already rejected is reported as such' );
/*
 * A file over the server's own limit arrives with no temporary name, so
 * checking for that first used to report "no file arrived" when in fact
 * too much had.
 */
$over = array( 'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 );
$out  = CardsCo_Logo::check( $over );
ck( 'refused', is_wp_error( $out ) );
ck( 'as too big, not as missing', is_wp_error( $out ) ? $out->get_error_message() : '', 'That file is too big for the server to accept.' );

sect( 'somebody without the right cannot set a logo' );
$was = wp_get_current_user()->ID;
wp_set_current_user( 0 );
ck( 'a signed-out request is refused', is_wp_error( CardsCo_Logo::check( $make( 'x.png', $png ) ) ) );
wp_set_current_user( $was );

sect( 'the screen offers a real file picker, not a trip to the admin' );
$_GET = array();
$html = do_shortcode( '[cardsco_settings]' );
ck( 'no link into the admin', str_contains( $html, 'wp-admin' ), false );
ck( 'there is a file input', str_contains( $html, 'data-cc-logo' ) );
ck( 'it takes images only', str_contains( $html, 'accept="image/png,image/jpeg"' ) );
ck( 'and the form can carry a file', str_contains( $html, 'enctype="multipart/form-data"' ) );

/* Tidy up. */
foreach ( glob( $dir . '*' ) as $leftover ) {
	unlink( $leftover );
}
rmdir( $dir );
CardsCo_Company::update( $cid, array( 'logo_attachment_id' => 0 ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
