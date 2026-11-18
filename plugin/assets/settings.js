

/*
 * A standing-in registry, for the case where boot.js is not on the page.
 *
 * boot.js is declared as this script's dependency, so WordPress loads it
 * first and the real registry is already here. But a page served from a
 * cache can be older than the plugin: the stale HTML asks for this file
 * and not for boot.js, the browser fetches the current version of it, and
 * `window.CardsCo.add` is undefined. The line below used to throw there,
 * which killed the whole script — the writer opened with a dead counter
 * and a Save button that stayed disabled no matter what was typed, with
 * nothing on screen to say why.
 *
 * Standing in costs nothing and turns that into a screen that works. The
 * real boot.js, when it is there, has already replaced this.
 */
window.CardsCo = window.CardsCo || {};
window.CardsCo.add = window.CardsCo.add || function ( fn ) {
	if ( typeof fn !== 'function' ) {
		return;
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', fn );
		return;
	}

	fn();
};

window.CardsCo.add( function () {
	'use strict';

	var input = document.querySelector( '[data-cc-logo]' );

	if ( ! input ) {
		return;
	}

	var name = document.querySelector( '.cc-set-logo-name' );
	var meta = document.querySelector( '.cc-set-logo-meta' );

	input.addEventListener( 'change', function () {
		var file = input.files && input.files[0];

		if ( ! file ) {
			return;
		}

		if ( name ) {
			name.textContent = file.name;
		}

		if ( meta ) {
			meta.textContent = meta.getAttribute( 'data-cc-staged' ) || 'Chosen — press Save to upload it';
		}
	} );
} );
