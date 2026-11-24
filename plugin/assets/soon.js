/**
 * The "not open yet" dialog.
 *
 * An occasion marked coming soon cannot be set up. The tile says so, but
 * a tile that says so and then does nothing when pressed reads as broken
 * — so pressing one opens this instead and names the occasion.
 *
 * @package CardsCo
 */

/*
 * Stands on its own. This used to register with the portal's script
 * registry, which a public page does not load — so on the collections
 * page the file was dropped for a missing dependency and the dialog
 * never opened at all. It is printed in the footer, so the page is
 * already there by the time it runs.
 */
( function () {
	var box = document.querySelector( '[data-cc-soon-box]' );

	if ( ! box || box.dataset.ccSoonReady ) {
		return;
	}

	box.dataset.ccSoonReady = '1';

	var title  = box.querySelector( '[data-cc-soon-title]' );
	var opener = null;

	function open( from ) {
		var named = from.getAttribute( 'data-cc-soon' ) || '';

		if ( title && named ) {
			/* textContent, so an occasion's name is never markup. */
			title.textContent = named;
		}

		opener     = from;
		box.hidden = false;

		var first = box.querySelector( '[data-cc-soon-close]' );

		if ( first ) { first.focus(); }
	}

	function close() {
		box.hidden = true;

		/* Back to whatever was pressed, so the keyboard does not start over. */
		if ( opener && opener.focus ) { opener.focus(); }

		opener = null;
	}

	/* One listener on the page: tiles come and go as steps are swapped in. */
	document.addEventListener( 'click', function ( e ) {
		var hit = e.target.closest ? e.target.closest( '[data-cc-soon]' ) : null;

		if ( hit ) {
			e.preventDefault();
			open( hit );
			return;
		}

		if ( e.target.closest && e.target.closest( '[data-cc-soon-close]' ) ) {
			e.preventDefault();
			close();
			return;
		}

		/* Anywhere off the sheet closes it. */
		if ( ! box.hidden && e.target === box ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! box.hidden ) {
			close();
		}
	} );
}() );
