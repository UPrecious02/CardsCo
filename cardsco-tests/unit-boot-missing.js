/**
 * Every registry script has to survive boot.js being absent.
 *
 * A page served from a cache can be older than the plugin: its HTML asks
 * for writer.js but not for boot.js, the browser fetches the current
 * writer.js, and window.CardsCo is undefined. Without a guard the very
 * first line throws, the whole script dies, and the card writer opens
 * with a dead character counter and a Save button stuck disabled — with
 * nothing on screen saying why. That is what a real customer hit.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const dir = process.argv[2] || '/home/claude/cardsco/assets';
const parts = [ 'settings.js', 'wizard.js', 'preview.js', 'writer.js' ];

let fail = 0;

function ck( label, got, want = true ) {
	const ok = want === true ? !! got : got === want;
	if ( ! ok ) { fail++; }
	console.log( `  ${ ok ? 'ok   ' : 'FAIL ' } ${ label.padEnd( 54 ) } ${ got }` );
	if ( ! ok && want !== true ) { console.log( `        want ${ want }` ); }
}

console.log( '\n=== each script runs with no boot.js on the page ===' );

for ( const name of parts ) {
	const src = fs.readFileSync( path.join( dir, name ), 'utf8' );
	let ran = false;
	let threw = '';

	/* A page with no boot.js: window.CardsCo simply is not there. */
	const win = { CardsCo: undefined };
	const doc = {
		readyState: 'complete',
		addEventListener: () => {},
		getElementById: () => null,
		querySelector: () => null,
		querySelectorAll: () => [],
		documentElement: { classList: { add: () => {}, remove: () => {} } },
		body: { classList: { add: () => {}, remove: () => {} } },
	};

	try {
		new Function( 'window', 'document', src ).call( win, win, doc );
		ran = true;
	} catch ( e ) {
		threw = e.message;
	}

	/*
	 * Asserted on the boolean, not on `ran ? true : threw`: a thrown
	 * message is a non-empty string, and a truthy check on it passes.
	 * The first version of this line did exactly that and reported the
	 * crash as a pass.
	 */
	ck( `${ name } does not throw`, ran, true );
	if ( ! ran ) { console.log( `        threw ${ threw }` ); }
	ck( `${ name } registers itself anyway`, typeof win.CardsCo?.add === 'function' );
}

console.log( '\n=== and the real boot.js still wins when it is there ===' );
{
	const boot = fs.readFileSync( path.join( dir, 'boot.js' ), 'utf8' );
	const win = {};
	const doc = { readyState: 'complete', addEventListener: () => {}, querySelector: () => null };

	new Function( 'window', 'document', boot ).call( win, win, doc );
	const real = win.CardsCo.add;

	const writer = fs.readFileSync( path.join( dir, 'writer.js' ), 'utf8' );
	new Function( 'window', 'document', writer ).call( win, win, {
		...doc, getElementById: () => null, querySelectorAll: () => [],
	} );

	ck( 'boot.js keeps its own add()', win.CardsCo.add === real );
	ck( 'and rerun is still there', typeof win.CardsCo.rerun === 'function' );
}

console.log( `\n${ fail ? 'FAIL' : 'all good' } (${ fail } failed)` );
process.exit( fail ? 1 : 0 );
