/**
 * The dashboard sidebar's behaviour.
 *
 *   drawer    on a narrow screen, opened from the menu button
 *   collapse  on a desktop, folds the sidebar down to an icon rail
 *   search    filters the nav items and the recent campaigns
 *   more      the "…" menu on the Recent campaigns heading
 *
 * All of it is in one file because it is all one component, and all of it
 * degrades to a plain list of links if this never loads.
 */
( function () {
	'use strict';

	var nav = document.getElementById( 'ccNav' );
	if ( ! nav ) { return; }

	var veil     = document.querySelector( '.cc-nav-veil' );
	var toggle   = document.querySelector( '[data-cc-nav-open]' );
	var collapse = nav.querySelector( '[data-cc-collapse]' );
	var find     = nav.querySelector( '[data-cc-find]' );
	var findBox  = document.getElementById( 'ccNavFind' );
	var more     = document.getElementById( 'ccNavMore' );
	var empty    = nav.querySelector( '[data-cc-find-empty]' );
	var last     = null;

	var RAIL = 'cardsco-nav-rail';

	/* -----------------------------------------------------------------
	 * Private browsing, blocked site data and some embeds make
	 * localStorage throw on access rather than return null, so every
	 * read and write is wrapped. The sidebar works without it; it just
	 * forgets whether it was collapsed.
	 * -------------------------------------------------------------- */

	function remember( value ) {
		try { window.localStorage.setItem( RAIL, value ); } catch ( e ) {}
	}

	function recall() {
		try { return window.localStorage.getItem( RAIL ); } catch ( e ) { return null; }
	}

	/* ------------------------------------------------------------------
	 * The drawer
	 * --------------------------------------------------------------- */

	function open() {
		last = document.activeElement;
		nav.classList.add( 'is-open' );
		document.body.classList.add( 'cc-nav-open' );

		if ( veil ) { veil.hidden = false; }
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'true' ); }

		var first = nav.querySelector( 'a, button' );
		if ( first ) { first.focus(); }
	}

	function close() {
		nav.classList.remove( 'is-open' );
		document.body.classList.remove( 'cc-nav-open' );

		if ( veil ) { veil.hidden = true; }
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }

		if ( last && typeof last.focus === 'function' ) { last.focus(); }
	}

	/* ------------------------------------------------------------------
	 * The rail
	 * --------------------------------------------------------------- */

	function setRail( on ) {
		nav.classList.toggle( 'is-rail', on );

		if ( collapse ) {
			// aria-expanded describes the sidebar, so collapsed is false.
			collapse.setAttribute( 'aria-expanded', on ? 'false' : 'true' );
			collapse.setAttribute(
				'aria-label',
				on ? 'Expand the sidebar' : 'Collapse the sidebar'
			);
		}

		// Collapsing with the search open would leave an orphaned box.
		if ( on ) { showFind( false ); hideMore(); }

		remember( on ? '1' : '0' );
	}

	if ( '1' === recall() ) { setRail( true ); }

	/* ------------------------------------------------------------------
	 * The search
	 * --------------------------------------------------------------- */

	function showFind( on ) {
		if ( ! findBox ) { return; }

		findBox.hidden = ! on;

		var buttons = nav.querySelectorAll( '[data-cc-find-toggle]' );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].setAttribute( 'aria-expanded', on ? 'true' : 'false' );
		}

		if ( on && find ) {
			find.focus();
		} else if ( find ) {
			find.value = '';
			filter( '' );
		}
	}

	function filter( term ) {
		term = term.trim().toLowerCase();

		var rows  = nav.querySelectorAll( '[data-cc-findable]' );
		var shown = 0;

		for ( var i = 0; i < rows.length; i++ ) {
			var text = ( rows[ i ].getAttribute( 'data-cc-findable' ) || '' ).toLowerCase();
			var hit  = '' === term || text.indexOf( term ) !== -1;

			rows[ i ].hidden = ! hit;
			if ( hit ) { shown++; }
		}

		// The "Recent campaigns" heading is pointless with nothing under it.
		var section = nav.querySelector( '.cc-nav-section' );
		var recent  = nav.querySelectorAll( '.cc-nav-recent [data-cc-findable]:not([hidden])' );

		if ( section ) { section.hidden = '' !== term && 0 === recent.length; }
		if ( empty ) { empty.hidden = ! ( '' !== term && 0 === shown ); }
	}

	if ( find ) {
		find.addEventListener( 'input', function () { filter( find.value ); } );
	}

	/* ------------------------------------------------------------------
	 * The "…" menu
	 * --------------------------------------------------------------- */

	function hideMore() {
		if ( ! more ) { return; }

		more.hidden = true;

		var button = nav.querySelector( '[data-cc-more-toggle]' );
		if ( button ) { button.setAttribute( 'aria-expanded', 'false' ); }
	}

	function toggleMore() {
		if ( ! more ) { return; }

		var on     = more.hidden;
		var button = nav.querySelector( '[data-cc-more-toggle]' );

		more.hidden = ! on;
		if ( button ) { button.setAttribute( 'aria-expanded', on ? 'true' : 'false' ); }

		if ( on ) {
			var first = more.querySelector( 'a' );
			if ( first ) { first.focus(); }
		}
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * --------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;

		// Browsers without Element.closest get the plain links and nothing else.
		if ( ! target || ! target.closest ) { return; }

		if ( target.closest( '[data-cc-nav-open]' ) ) {
			e.preventDefault();
			open();
			return;
		}

		if ( target.closest( '[data-cc-nav-close]' ) ) {
			e.preventDefault();
			close();
			return;
		}

		if ( target.closest( '[data-cc-collapse]' ) ) {
			e.preventDefault();
			setRail( ! nav.classList.contains( 'is-rail' ) );
			return;
		}

		if ( target.closest( '[data-cc-find-toggle]' ) ) {
			e.preventDefault();

			// Collapsed, the search has nowhere to go, so open out first.
			if ( nav.classList.contains( 'is-rail' ) ) { setRail( false ); }

			showFind( !! ( findBox && findBox.hidden ) );
			return;
		}

		if ( target.closest( '[data-cc-more-toggle]' ) ) {
			e.preventDefault();
			toggleMore();
			return;
		}

		// A click anywhere else closes the menu.
		if ( more && ! more.hidden && ! target.closest( '#ccNavMore' ) ) {
			hideMore();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) { return; }

		if ( more && ! more.hidden ) {
			hideMore();

			var button = nav.querySelector( '[data-cc-more-toggle]' );
			if ( button ) { button.focus(); }
			return;
		}

		if ( findBox && ! findBox.hidden ) {
			showFind( false );
			return;
		}

		if ( nav.classList.contains( 'is-open' ) ) { close(); }
	} );

	// Following a link should not leave the drawer open behind the new page
	// if the browser restores it from cache.
	window.addEventListener( 'pageshow', function () {
		if ( nav.classList.contains( 'is-open' ) ) { close(); }
		hideMore();
	} );
}() );

/**
 * The toolbar's dropdowns, on the dashboard.
 *
 * Its own block because the dashboard shortcode can be used on a page without
 * the sidebar, and the sidebar block above gives up when there is no sidebar.
 */
( function () {
	'use strict';

	function shut( except ) {
		var open = document.querySelectorAll( '[data-cc-drop][aria-expanded="true"]' );

		for ( var i = 0; i < open.length; i++ ) {
			if ( open[ i ] === except ) { continue; }

			var panel = document.getElementById( open[ i ].getAttribute( 'aria-controls' ) );
			if ( panel ) { panel.hidden = true; }
			open[ i ].setAttribute( 'aria-expanded', 'false' );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || ! target.closest ) { return; }

		var button = target.closest( '[data-cc-drop]' );

		if ( button ) {
			e.preventDefault();

			var panel = document.getElementById( button.getAttribute( 'aria-controls' ) );
			if ( ! panel ) { return; }

			var show = panel.hidden;
			shut( button );
			panel.hidden = ! show;
			button.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
			return;
		}

		// A click outside any open menu closes them.
		if ( ! target.closest( '.cc-drop-menu' ) ) { shut( null ); }
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) { shut( null ); }
	} );

	/*
	 * The bell on the phone's top bar.
	 *
	 * Opens a panel that says nothing is waiting. There is nothing to
	 * count yet, so it counts nothing and says so — a button that
	 * answers honestly rather than one that does nothing at all.
	 */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-cc-bell]' ) : null;
		var all = document.querySelectorAll( '[data-cc-bell]' );

		Array.prototype.forEach.call( all, function ( other ) {
			if ( other === btn ) { return; }

			var p = document.getElementById( other.getAttribute( 'aria-controls' ) );
			if ( p ) { p.hidden = true; }
			other.setAttribute( 'aria-expanded', 'false' );
		} );

		if ( ! btn ) { return; }

		e.preventDefault();

		var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		if ( ! panel ) { return; }

		var show = panel.hidden;
		panel.hidden = ! show;
		btn.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) { return; }

		Array.prototype.forEach.call( document.querySelectorAll( '[data-cc-bell]' ), function ( btn ) {
			var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			if ( panel ) { panel.hidden = true; }
			btn.setAttribute( 'aria-expanded', 'false' );
		} );
	} );

	/*
	 * How tall the bottom tab bar actually is.
	 *
	 * The stylesheet needs this to keep the end of a page clear of the
	 * bar, and it cannot measure. It was a number copied from the design
	 * instead — 64 against a bar that renders at 67 — and three pixels
	 * was enough to put Settings' "Save changes" underneath it. Measured
	 * here, the clearance follows the bar wherever the type takes it.
	 *
	 * The height is only written when the bar is actually on screen; the
	 * stylesheet's own fallback covers everything else.
	 */
	function tabsHeight() {
		var bar = document.querySelector( '.cc-mtabs' );
		var h   = bar ? bar.getBoundingClientRect().height : 0;

		if ( h > 0 ) {
			document.documentElement.style.setProperty( '--cc-tabs-h', Math.ceil( h ) + 'px' );
		} else {
			document.documentElement.style.removeProperty( '--cc-tabs-h' );
		}
	}

	tabsHeight();
	window.addEventListener( 'resize', tabsHeight );
	window.addEventListener( 'orientationchange', tabsHeight );

	/* Web fonts land after first paint and can change the bar's height. */
	if ( document.fonts && document.fonts.ready && document.fonts.ready.then ) {
		document.fonts.ready.then( tabsHeight );
	}

	/*
	 * Don't lose a half-typed answer on the way out of a step.
	 *
	 * Both wizards put their step list, "Save and exit" and the back link
	 * in the header — outside the form. They are ordinary links, so
	 * clicking one after changing a field throws the change away without
	 * a word, and the next screen shows the old value. Change the arrival
	 * date, click Review in the step list, and the review says the date
	 * you were trying to replace.
	 *
	 * Continue and the footer's own Save and exit are submits, so they
	 * clear the flag before anything unloads and never prompt. What is
	 * left is the case that loses work: leaving by a link, the browser's
	 * back button, or closing the tab.
	 */
	( function () {
		var form = document.querySelector( '.cc-wz form, form.cc-wz-form' );

		if ( ! form ) {
			return;
		}

		var dirty = false;

		form.addEventListener( 'input', function () { dirty = true; } );
		form.addEventListener( 'change', function () { dirty = true; } );
		form.addEventListener( 'submit', function () { dirty = false; } );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! dirty ) {
				return;
			}

			/* Browsers show their own wording and ignore ours; both of
			   these are needed for all of them to show anything at all. */
			e.preventDefault();
			e.returnValue = '';
			return '';
		} );
	}() );

	/*
	 * The wizard's step list on a phone.
	 *
	 * The seven chips are folded away under one line saying where you are;
	 * this opens them so a finished step can still be jumped back to. A
	 * class rather than the hidden attribute, because the same list is on
	 * screen unconditionally above 720 and hidden="" would take it away
	 * there too.
	 *
	 * Stays wired at every width: the stylesheet decides whether any of it
	 * is visible, so there is no breakpoint to keep in step here.
	 */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-cc-steps]' ) : null;
		if ( ! btn ) { return; }

		var list = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		if ( ! list ) { return; }

		e.preventDefault();

		var open = ! list.classList.contains( 'is-open' );
		list.classList.toggle( 'is-open', open );
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );
}() );
