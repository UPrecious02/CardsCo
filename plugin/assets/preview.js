/**
 * CardsCo — catalogue preview panel.
 *
 * Turns the Preview link on a card tile into a panel that opens over the
 * grid, so somebody can look through the library without leaving the page
 * and losing their filter and scroll position.
 *
 * Progressive: the link still points at the product page and still works as
 * one. If this script does not run, or the browser has no <dialog>, nothing
 * is intercepted and the click navigates exactly as it did before.
 */
/*
 * Registered rather than run, so the wizard can run it again after it has
 * swapped one step for the next without reloading the page. See boot.js.
 *
 * The click handler at the bottom is delegated from the document, which
 * outlives any swap — so it is attached once, guarded by the flag below.
 * Without the guard every swap would add another copy of it, and opening
 * one preview would try to open the panel as many times as steps you had
 * been through.
 */
var ccPvBound = false;
var ccPvOpen  = null;

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

	var dialog = document.getElementById( 'cc-pv' );
	var source = document.getElementById( 'cc-pv-data' );

	/* Cleared first: a step without a preview must not be left holding the
	   opener from the step before, whose dialog is no longer on the page. */
	ccPvOpen = null;

	if ( ! dialog || ! source || typeof dialog.showModal !== 'function' ) {
		return;
	}

	var cards;

	try {
		cards = JSON.parse( source.textContent || '{}' );
	} catch ( e ) {
		return;
	}

	/* The order the grid is in, which is the order the arrows follow. Any
	   trigger will do: the catalogue marks its Preview link, the wizard its
	   eye button on each card tile. */
	var order = [].map.call(
		document.querySelectorAll( '[data-cc-pv]' ),
		function ( link ) { return link.getAttribute( 'data-cc-pv' ); }
	).filter( function ( id ) { return Object.prototype.hasOwnProperty.call( cards, id ); } );

	if ( ! order.length ) {
		return;
	}

	var at = 0;

	var el = {
		img:        document.getElementById( 'cc-pv-img' ),
		collection: document.getElementById( 'cc-pv-collection' ),
		title:      document.getElementById( 'cc-pv-title' ),
		pattern:    document.getElementById( 'cc-pv-pattern' ),
		front:      document.getElementById( 'cc-pv-front' ),
		format:     document.getElementById( 'cc-pv-format' ),
		occasions:  document.getElementById( 'cc-pv-occasions' ),
		count:      document.getElementById( 'cc-pv-count' ),
		use:        document.getElementById( 'cc-pv-use' ),
		pick:       document.getElementById( 'cc-pv-pick' )
	};

	var facts = {
		front:     document.getElementById( 'cc-pv-fact-front' ),
		format:    document.getElementById( 'cc-pv-fact-format' ),
		occasions: document.getElementById( 'cc-pv-fact-occasions' )
	};

	/* A fact with nothing in it is hidden rather than left as an empty row:
	   not every card has an artwork name or words on the front. */
	function fill( node, row, value ) {
		if ( ! node ) { return; }
		node.textContent = value || '';
		if ( row ) { row.hidden = ! value; }
	}

	/**
	 * Give a button its address, or take the button away.
	 *
	 * @param {HTMLElement} button.
	 * @param {string}      href   Where it goes, if anywhere.
	 */
	function point( button, href ) {
		if ( href ) {
			button.setAttribute( 'href', href );
			button.hidden = false;
			return;
		}

		button.removeAttribute( 'href' );
		button.hidden = true;
	}

	function show( index ) {
		if ( index < 0 ) { index = order.length - 1; }
		if ( index >= order.length ) { index = 0; }

		at = index;

		var card = cards[ order[ at ] ];
		if ( ! card ) { return; }

		if ( el.img ) {
			el.img.setAttribute( 'src', card.image || '' );
			/* The card's own name is the alt text; the panel's heading says
			   the same thing, so a screen reader would hear it twice if this
			   were not empty. */
			el.img.setAttribute( 'alt', '' );
			el.img.hidden = ! card.image;
		}

		fill( el.collection, null, card.collection );
		fill( el.title, null, card.title );
		fill( el.pattern, null, card.pattern );
		fill( el.front, facts.front, card.front );
		fill( el.format, facts.format, card.format );
		fill( el.occasions, facts.occasions, card.occasions );

		if ( el.count ) {
			el.count.textContent = ( at + 1 ) + ' / ' + order.length;
		}

		/*
		 * A card with nowhere to go gets no button. These used to fall back
		 * to "#", which looks like a button, clicks like a button and does
		 * nothing — the same dead control as a link that points at the page
		 * it is already on.
		 */
		if ( el.use ) { point( el.use, card.use ); }
	}

	function open( id ) {
		var index = order.indexOf( id );
		if ( index < 0 ) { return false; }
		show( index );
		dialog.showModal();
		return true;
	}

	/*
	 * This screen's opener, which the delegated handler below calls
	 * through. Reassigned on every run, so the handler always works
	 * against the dialog that is actually on the page rather than the one
	 * that was there when it was first attached.
	 */
	ccPvOpen = open;

	/* Attached once: the document outlives every swap. */
	if ( ! ccPvBound ) {
		ccPvBound = true;

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '[data-cc-pv]' ) : null;

			/* Let a modified click do what the person asked: open the product
			   page in a new tab, save it, and so on. */
			if ( ! link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button ) {
				return;
			}

			if ( ccPvOpen && ccPvOpen( link.getAttribute( 'data-cc-pv' ) ) ) {
				event.preventDefault();
			}
		} );
	}

	/* The dialog itself is replaced by a swap, so this one is attached
	   afresh each time, to the dialog that is on the page now. */
	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '[data-cc-pv-close]' ) ) {
			dialog.close();
			return;
		}

		/* Wizard only: take the card that is on screen and close. */
		if ( el.pick && event.target.closest( '#cc-pv-pick' ) ) {
			var radio = document.querySelector( 'input[name="card_id"][value="' + order[ at ] + '"]' );

			if ( radio ) {
				radio.checked = true;
				/* wizard.js listens for this to move the selected outline
				   and enable Continue. */
				radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			dialog.close();
			return;
		}

		var step = event.target.closest( '[data-cc-pv-step]' );

		if ( step ) {
			show( at + parseInt( step.getAttribute( 'data-cc-pv-step' ), 10 ) );
			return;
		}

		/* A click on the backdrop lands on the dialog itself, because the
		   panel's own content is inside .cc-pv-inner. */
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'keydown', function ( event ) {
		if ( 'ArrowLeft' === event.key ) {
			show( at - 1 );
		} else if ( 'ArrowRight' === event.key ) {
			show( at + 1 );
		}
	} );
} );
