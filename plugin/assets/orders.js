/**
 * Orders — the two selects.
 *
 * Both are real controls in the frame, so both do something here rather
 * than sitting there looking like they might. Without this script the
 * period still works (it is inside a form with a noscript submit) and the
 * row count falls back to ten, which is the number it is drawn at.
 */
( function () {
	'use strict';

	/* The period: change it and the surrounding form carries tab and search
	   along with it. */
	var period = document.querySelector( '[data-cc-filter]' );

	if ( period && period.form ) {
		period.addEventListener( 'change', function () {
			period.form.submit();
		} );
	}

	/* Rows per page: each option already holds the address it leads to, so
	   there is nothing to assemble here. */
	var per = document.querySelector( '[data-cc-nav]' );

	if ( per ) {
		per.addEventListener( 'change', function () {
			var to = per.value;

			if ( to ) {
				window.location.href = to;
			}
		} );
	}
}() );
