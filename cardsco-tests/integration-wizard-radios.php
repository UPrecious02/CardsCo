<?php
/**
 * Every radio group in the wizard has to look picked when it is picked.
 *
 * The radios on these screens are invisible: the panel's green border and
 * its filled dot come from a class, and the class is written twice — once
 * by PHP when the page is built, and once by wizard.js when somebody
 * clicks. A group that PHP renders but wizard.js does not know about looks
 * dead: the radio checks, the panel does not change, and the option reads
 * as one that cannot be selected.
 *
 * That is what happened to step 6's "A few days before each birthday".
 * timing_mode was never added to the table in wizard.js, so this checks the
 * two lists against each other rather than trusting either one.
 *
 * The CSS carries the same state a second way, through :has(input:checked),
 * so the screen is right even before the script runs — and each of those
 * lives in its own rule, because a browser that cannot parse :has() throws
 * away the whole rule and would take the server's state down with it.
 */

$fail = 0;
function ck( $l, $got, $want = true ) {
	global $fail;
	$ok = ( true === $want ) ? (bool) $got : ( $got === $want );
	if ( ! $ok ) { $fail++; }
	printf( "  %-5s %-58s %s\n", $ok ? 'ok' : 'FAIL', $l, is_bool( $got ) ? var_export( $got, true ) : ( is_array( $got ) ? json_encode( $got ) : (string) $got ) );
	if ( ! $ok && true !== $want ) { printf( "        want %s\n", var_export( $want, true ) ); }
}
function sect( $t ) { echo "\n=== $t ===\n"; }

$plugin = dirname( __DIR__ ) . '/cardsco';
$js     = (string) file_get_contents( $plugin . '/assets/wizard.js' );
$css    = (string) file_get_contents( $plugin . '/assets/dashboard.css' );

/* The radio groups the wizard's own screens render. */
$rendered = array();

foreach ( glob( $plugin . '/includes/front/view-setup*.php' ) as $view ) {
	$src = (string) file_get_contents( $view );

	/*
	 * PHP blocks come out first. "?>" closing a tag inside an attribute
	 * ends the match early otherwise, which quietly dropped every group
	 * whose name="" sits after one — category among them — and left this
	 * suite passing while covering less than it claimed.
	 */
	$markup = preg_replace( '/<\?php.*?\?>/s', '', $src );

	// An <input> can run over several lines, so match the tag, not the line.
	if ( ! preg_match_all( '/<input\b[^>]*>/s', (string) $markup, $tags ) ) {
		continue;
	}

	foreach ( $tags[0] as $tag ) {
		if ( ! str_contains( $tag, 'type="radio"' ) ) {
			continue;
		}

		if ( preg_match( '/name="([a-z_]+)"/', $tag, $m ) ) {
			$rendered[ $m[1] ] = basename( $view );
		}
	}
}

sect( 'what the wizard puts on screen' );
ck( 'it renders radio groups', count( $rendered ) > 0 );
ck( 'step 6 among them', isset( $rendered['timing_mode'] ) );
ck( 'and its occasion chips', isset( $rendered['occasion_type_id'] ) );
echo '  (found: ' . implode( ', ', array_keys( $rendered ) ) . ")\n";

sect( 'every one of them is wired for the click' );
/* The table in wizard.js: { name: 'x', item: '.y', on: 'is-z' } */
preg_match_all( "/name:\s*'([a-z_]+)'\s*,\s*item:\s*'([^']+)'\s*,\s*on:\s*'([^']+)'/", $js, $rows, PREG_SET_ORDER );

$wired = array();

foreach ( $rows as $row ) {
	$wired[ $row[1] ] = array(
		'item' => $row[2],
		'on'   => $row[3],
	);
}

ck( 'the table is readable', count( $wired ) > 0 );

foreach ( $rendered as $name => $view ) {
	ck( sprintf( '%s (%s) is in the table', $name, $view ), isset( $wired[ $name ] ) );
}

sect( 'and each one points at markup that exists' );
foreach ( $wired as $name => $row ) {
	if ( ! isset( $rendered[ $name ] ) ) {
		// Wired but not rendered by the wizard — a screen elsewhere.
		continue;
	}

	$class = ltrim( $row['item'], '.' );
	$src   = (string) file_get_contents( $plugin . '/includes/front/' . $rendered[ $name ] );

	ck( sprintf( '%s wraps its radios in %s', $name, $row['item'] ), str_contains( $src, $class ) );
	ck( sprintf( '…and the CSS styles %s.%s', $row['item'], $row['on'] ), str_contains( $css, $row['item'] . '.' . $row['on'] ) );
}

sect( 'the stylesheet shows the state on its own too' );
foreach ( array( '.cc-opt', '.cc-ship-mode', '.cc-tim-chip' ) as $item ) {
	ck( sprintf( '%s follows the checked radio', $item ), str_contains( $css, $item . ':has(input:checked)' ) );
}

/*
 * The important part: :has() must never share a rule with the class the
 * server writes. A browser that cannot parse the selector drops the whole
 * rule, so folding them together would lose the server-rendered state on
 * exactly the browsers the fallback is for.
 */
sect( 'and never at the cost of the server-rendered state' );
preg_match_all( '/^([^{}\n][^{}]*)\{/m', $css, $sel );

$folded = array();

foreach ( $sel[1] as $selector ) {
	$selector = trim( $selector );

	if ( str_contains( $selector, ':has(' ) && preg_match( '/\.is-on\b/', $selector ) ) {
		$folded[] = $selector;
	}
}

ck( 'no rule mixes :has() with .is-on', count( $folded ), 0 );

if ( $folded ) {
	foreach ( $folded as $selector ) { echo '        ' . $selector . "\n"; }
}

sect( 'the label the footer reads is one of these' );
preg_match( '/var named = item\.querySelector\(\s*\'([^\']+)\'/', $js, $named );
$heads = isset( $named[1] ) ? array_map( 'trim', explode( ',', $named[1] ) ) : array();
ck( 'step 6 modes have a readable head', in_array( '.cc-opt-head', $heads, true ) );
ck( 'and so do its occasion chips', in_array( '.cc-tim-chip-name', $heads, true ) );

printf( "\n%s (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail );
exit( $fail ? 1 : 0 );
