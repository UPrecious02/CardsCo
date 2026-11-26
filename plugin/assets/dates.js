/**
 * A date field opens its calendar when you click it.
 *
 * Every date on these screens is an <input type="date">, which the browser
 * draws as a dd/mm/yyyy mask with a small calendar icon at one end. The
 * calendar only opens from that icon, so clicking the field — which is
 * what people do — puts a cursor in the mask and leaves them typing the
 * date out by hand. The CEO's note was that a picker should pop up instead
 * of typing the date in manually.
 *
 * So a click anywhere on the field, or arriving on it by keyboard, opens
 * the picker. Typing still works for anyone who prefers it: this opens the
 * calendar, it does not take the keyboard away.
 *
 * One listener on the document rather than one per field, because the
 * wizard swaps whole steps in and out and a listener bound to a field that
 * has just been replaced is a listener on nothing.
 *
 * @package CardsCo
 */

( function () {
	'use strict';

	/*
	 * showPicker() is what opens it. Where the browser does not have it
	 * the field still works exactly as it did — a mask you can type into
	 * — so there is nothing to fall back to and nothing to apologise for.
	 */
	function openable( el ) {
		return !! (
			el &&
			'INPUT' === el.tagName &&
			'date' === ( el.getAttribute( 'type' ) || '' ).toLowerCase() &&
			! el.disabled &&
			! el.readOnly &&
			typeof el.showPicker === 'function'
		);
	}

	function show( el ) {
		try {
			el.showPicker();
		} catch ( e ) {
			/*
			 * Firefox throws if the field is not user-activated, and a
			 * focus that came from script rather than a press is not. The
			 * field is still usable, so this is deliberately silent.
			 */
		}
	}

	function bind() {
		if ( document.documentElement.dataset.ccDatesReady ) {
			return;
		}

		document.documentElement.dataset.ccDatesReady = '1';

		/*
		 * Pointer first. 'click' rather than 'mousedown' so the field is
		 * focused by the time the picker opens, and the browser lines the
		 * calendar up with it.
		 */
		document.addEventListener( 'click', function ( e ) {
			var el = e.target;

			if ( openable( el ) ) {
				show( el );
			}
		} );

		/*
		 * And the keyboard. Tabbing onto the field opens the calendar too,
		 * so it is reachable without a mouse. Arrow keys and typing then
		 * work inside it as they always have.
		 */
		document.addEventListener( 'focusin', function ( e ) {
			var el = e.target;

			if ( openable( el ) ) {
				show( el );
			}
		} );
	}

	/*
	 * Registers with the portal's script registry where there is one, and
	 * runs on its own where there is not — a public page does not load
	 * boot.js, and a date field on one still has to open.
	 */
	if ( window.CardsCo && typeof window.CardsCo.add === 'function' ) {
		window.CardsCo.add( bind );
	} else {
		bind();
	}
}() );
