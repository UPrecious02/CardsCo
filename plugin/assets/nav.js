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
}() );
