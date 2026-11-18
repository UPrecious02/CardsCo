/**
 * CardsCo — the card writer.
 *
 * Keeps the counter, the preview card and the Save button in step with what
 * is in the box.
 *
 * The count here is a courtesy. It is the same arithmetic the server does on
 * save, but it runs against the text as typed rather than against every
 * recipient's merged copy, so the server can still refuse a message this
 * says is fine — a long first name is what pushes one over. The server's
 * answer is the one that counts.
 */

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

	var form = document.getElementById( 'ccWriter' );

	if ( ! form ) {
		return;
	}

	var text  = form.querySelector( '[data-cc-text]' );
	var count = form.querySelector( '[data-cc-count]' );
	var hand  = form.querySelector( '[data-cc-hand]' );
	var sign  = form.querySelector( '[data-cc-sign]' );
	var signIn = form.querySelector( '[data-cc-signin]' );
	var blank = form.querySelector( '[data-cc-blank]' );
	var pane  = form.querySelector( '[data-cc-preview]' );
	var go    = form.querySelector( '[data-cc-go]' );
	var clear = form.querySelector( '[data-cc-clear]' );
	var max   = parseInt( form.getAttribute( 'data-cc-max' ), 10 ) || 0;

	/* Merge tags that would come out empty on somebody's card. The server
	   refuses these on save; knowing them here means the person is told
	   while the words are still in front of them, rather than after a
	   round trip. */
	var blanks = [];

	try {
		blanks = JSON.parse( form.getAttribute( 'data-cc-blanks' ) || '[]' ) || [];
	} catch ( e ) {
		blanks = [];
	}

	if ( ! text ) {
		return;
	}

	function words( value ) {
		var trimmed = value.trim();
		return trimmed ? trimmed.split( /\s+/ ).length : 0;
	}

	function used() {
		var signature = signIn && signIn.value.trim() ? '\n' + signIn.value.trim() : '';
		return ( text.value + signature ).length;
	}

	function paint() {
		var value = text.value;
		var has   = '' !== value.trim();
		var n     = used();

		if ( blank ) { blank.hidden = has; }
		if ( pane )  { pane.hidden = ! has; }

		/* The card keeps up with the box. Built with textContent per line
		   rather than innerHTML, so a message is never markup. */
		if ( hand ) {
			hand.textContent = '';
			value.split( /\r?\n/ ).forEach( function ( line ) {
				if ( ! line.trim() ) { return; }
				var p = document.createElement( 'p' );
				p.textContent = line;
				hand.appendChild( p );
			} );
		}

		if ( sign ) {
			var who = signIn ? signIn.value.trim() : '';
			sign.hidden = ! who;
			sign.textContent = who ? '— ' + who : '';
		}

		var over = max > 0 && n > max;

		/* The starter messages leave [square brackets] for the sender to
		   replace. They are not merge tags — nothing fills them in — so the
		   server refuses to save one, and this says so before the click
		   rather than after it. */
		var gap = value.match( /\[([^\]]{1,40})\]/ );

		/* A tag that fills in as nothing leaves a hole in the sentence —
		   "from all of us at ." — and the pen writes it that way. */
		var empty = '';

		for ( var i = 0; i < blanks.length; i++ ) {
			if ( value.indexOf( '{' + blanks[ i ] + '}' ) !== -1 ) {
				empty = blanks[ i ];
				break;
			}
		}

		if ( count ) {
			/* The verdict only means something once there is something to
			   judge: "0 characters · fits the card" reads like a bug. */
			var verdict = ( has && max > 0 ) ? ( over ? ' · too long for the card' : ' · fits the card' ) : '';

			if ( empty ) {
				verdict = ' · {' + empty + '} is blank — take it out or fill it in';
			}

			if ( gap ) {
				verdict = ' · replace [' + gap[1] + ']';
			}

			count.textContent = 'Words: ' + words( value ) + ' · Characters: ' + n + verdict;
			count.classList.toggle( 'is-over', over || !! gap || !! empty );
		}

		form.classList.toggle( 'is-over', over || !! gap || !! empty );

		if ( go ) {
			go.disabled = ! has || over || !! gap || !! empty;
		}
	}

	text.addEventListener( 'input', paint );

	if ( signIn ) {
		signIn.addEventListener( 'input', paint );
	}

	form.querySelectorAll( '[data-cc-fill]' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function () {
			text.value = chip.getAttribute( 'data-cc-fill' ) || '';
			paint();
			text.focus();
			/* Caret to the end, so typing carries on rather than overwriting. */
			text.setSelectionRange( text.value.length, text.value.length );
		} );
	} );

	if ( clear ) {
		clear.addEventListener( 'click', function () {
			text.value = '';
			paint();
			text.focus();
		} );
	}

	paint();
} );
