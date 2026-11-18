/**
 * A place for the other scripts to say "run me again".
 *
 * Every screen's script binds its listeners once, when the page loads.
 * That is fine while each step is a fresh page, but the wizard now swaps
 * one step for the next without reloading, and a listener bound to an
 * element that has just been replaced is a listener on nothing.
 *
 * So each script registers what it does rather than doing it directly.
 * This runs them on load, and swap.js runs them again after it has put a
 * new step on the screen.
 *
 * Anything registered here must be safe to run more than once. Since the
 * swap replaces the whole panel, the elements a part binds to are new
 * every time, so binding again is binding to something different — not
 * doubling up on what is already there.
 */
( function () {
	'use strict';

	var parts = [];
	var ran   = false;

	window.CardsCo = window.CardsCo || {};

	/**
	 * Register a piece of setup.
	 *
	 * Registering after the first run — a script that loads late — runs it
	 * straight away, so load order does not decide whether it works.
	 */
	window.CardsCo.add = function ( fn ) {
		if ( typeof fn !== 'function' ) {
			return;
		}

		parts.push( fn );

		if ( ran ) {
			safely( fn );
		}
	};

	/** Run every registered piece again, after the screen has changed. */
	window.CardsCo.rerun = function () {
		ran = true;
		parts.forEach( safely );
	};

	/*
	 * One part throwing must not stop the rest. A broken preview should
	 * cost you the preview, not the Continue button underneath it.
	 */
	function safely( fn ) {
		try {
			fn();
		} catch ( e ) {
			if ( window.console && window.console.error ) {
				window.console.error( '[cardsco]', e );
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', window.CardsCo.rerun );
	} else {
		window.CardsCo.rerun();
	}
}() );
