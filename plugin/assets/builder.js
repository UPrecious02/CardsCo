/*
 * The campaign builder's screens.
 *
 * Three small jobs, none of which the server can do: count the audience
 * as it is being ticked, drop a merge field into the message where the
 * cursor is, and narrow a long list of occasions down to the one being
 * looked for.
 *
 * Everything here is an improvement on a screen that already works
 * without it. The count is rendered by PHP first, the merge fields can
 * be typed by hand, and the occasion list is complete before any
 * filtering. If this file never loads, nothing becomes unreachable.
 */

/*
 * A standing-in registry, for the case where boot.js is not on the page.
 *
 * A page served from a cache can be older than the plugin: the stale
 * HTML asks for this file and not for boot.js, the browser fetches the
 * current version of it, and window.CardsCo.add is undefined. Without
 * this the line below would throw and take the whole script with it.
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

	/* ---------------------------------------------------------- step 2 */

	var count = document.querySelector( '[data-cc-count]' );
	var people = document.querySelector( '.cc-bd-people' );

	if ( count && people ) {
		var one = count.getAttribute( 'data-cc-one' ) || '%s';
		var many = count.getAttribute( 'data-cc-many' ) || '%s';

		var recount = function () {
			var picked = {};

			/*
			 * Somebody ticked directly, minus anybody held back. Groups
			 * are not counted here: their membership is a rule the server
			 * resolves, and guessing at it would put a number on screen
			 * that the review page then contradicts. With a group
			 * selected the sentence is left as the server wrote it.
			 */
			var groups = document.querySelectorAll( 'input[name="segments[]"]:checked' );

			if ( groups.length ) {
				return;
			}

			Array.prototype.forEach.call(
				document.querySelectorAll( 'input[name="recipients[]"]:checked' ),
				function ( box ) {
					if ( box.value ) {
						picked[ box.value ] = true;
					}
				}
			);

			Array.prototype.forEach.call(
				document.querySelectorAll( 'input[name="exclude[]"]:checked' ),
				function ( box ) {
					delete picked[ box.value ];
				}
			);

			var n = Object.keys( picked ).length;

			count.textContent = ( 1 === n ? one : many ).replace( '%s', String( n ) );
		};

		document.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.name && /^(recipients|exclude|segments)\[\]$/.test( e.target.name ) ) {
				recount();
			}
		} );

		recount();
	}

	/* ---------------------------------------------------------- step 4 */

	var text = document.querySelector( '#cc-bd-msg' );

	if ( text ) {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cc-tag]' ), function ( tag ) {
			tag.addEventListener( 'click', function () {
				var token = tag.getAttribute( 'data-cc-tag' ) || '';
				var at = text.selectionStart;
				var to = text.selectionEnd;

				/*
				 * Dropped where the cursor is, rather than appended. Somebody
				 * who put the cursor mid-sentence meant there.
				 */
				if ( typeof at === 'number' && typeof to === 'number' ) {
					text.value = text.value.slice( 0, at ) + token + text.value.slice( to );
					text.selectionStart = at + token.length;
					text.selectionEnd = at + token.length;
				} else {
					text.value += token;
				}

				text.focus();
				text.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		} );

		/* The starters fill the box rather than adding to what is there. */
		Array.prototype.forEach.call( document.querySelectorAll( '[data-cc-fill]' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				text.value = btn.getAttribute( 'data-cc-fill' ) || '';
				text.focus();
				text.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		} );
	}

	/* ---------------------------------------------------------- step 3 */

	var pills = document.querySelector( '[data-cc-pills]' );

	if ( pills ) {
		var cards = Array.prototype.slice.call( document.querySelectorAll( '.cc-bd-card' ) );
		var none = document.querySelector( '[data-cc-pills-none]' );

		pills.addEventListener( 'click', function ( e ) {
			var pill = e.target.closest( '[data-cc-pill]' );

			if ( ! pill ) {
				return;
			}

			var want = pill.getAttribute( 'data-cc-pill' ) || '';
			var shown = 0;

			Array.prototype.forEach.call( pills.querySelectorAll( '[data-cc-pill]' ), function ( p ) {
				p.classList.toggle( 'is-on', p === pill );
			} );

			cards.forEach( function ( card ) {
				/*
				 * The chosen card stays visible whatever is filtered, or
				 * the form would be submitting a card with nothing on
				 * screen to say where it came from.
				 */
				var checked = card.querySelector( 'input:checked' );
				var hit = ! want || card.getAttribute( 'data-cc-in' ) === want || checked;

				card.hidden = ! hit;

				if ( hit ) {
					shown++;
				}
			} );

			if ( none ) {
				none.hidden = shown > 0;
			}
		} );
	}

	/* ---------------------------------------------------------- step 1 */

	var filter = document.querySelector( '[data-cc-filter]' );

	if ( filter ) {
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.cc-bd-radios .cc-bd-radio' ) );
		var empty = document.querySelector( '[data-cc-filter-none]' );

		filter.addEventListener( 'input', function () {
			var want = filter.value.trim().toLowerCase();
			var shown = 0;

			rows.forEach( function ( row ) {
				var label = ( row.textContent || '' ).toLowerCase();

				/*
				 * A row holding the chosen occasion stays visible whatever
				 * is typed. Hiding it would leave the form submitting a
				 * value with nothing on screen explaining where it came
				 * from.
				 */
				var checked = row.querySelector( 'input:checked' );
				var hit = ! want || label.indexOf( want ) !== -1 || checked;

				row.hidden = ! hit;

				if ( hit ) {
					shown++;
				}
			} );

			if ( empty ) {
				empty.hidden = shown > 0;
			}
		} );
	}
} );

/*
 * The arrival date, checked as it is picked.
 *
 * Every card is written by hand, so a date inside the writing window
 * cannot be met. The server refuses one and says so, but that costs a
 * round trip and a page you have to read to find out — and before the
 * handler learned to refuse it, the first anybody heard was the payment
 * screen six steps later, with Pay simply not working.
 *
 * The field already carries the earliest workable date in its min
 * attribute, which is where the message gets its date from, so there is
 * no second copy of that rule to drift. Browsers enforce min on submit
 * by themselves; what they will not do is say anything until you try.
 *
 * Nothing here blocks the form. The field stays usable, Continue stays
 * live, and the server has the final word — this only means the answer
 * arrives while the date picker is still open rather than afterwards.
 */
window.CardsCo.add( function () {
	/* boot.js calls these with no argument — see safely() in boot.js. A
	   parameter here is undefined, and the throw it causes is caught and
	   logged rather than raised, so the screen simply does nothing. */
	var field = document.querySelector( '#cc-bd-date' );

	if ( ! field || ! field.min ) {
		return;
	}

	var hint = field.parentNode ? field.parentNode.querySelector( '.cc-bd-hint' ) : null;

	if ( ! hint ) {
		return;
	}

	var note = document.createElement( 'p' );
	note.className = 'cc-bd-hint cc-bd-hint-bad';
	note.hidden = true;
	/* Spoken when it appears, so it is not only a colour change. */
	note.setAttribute( 'role', 'status' );
	hint.parentNode.insertBefore( note, hint.nextSibling );

	/*
	 * Read from min rather than built here: min is a Y-m-d string, and
	 * comparing Y-m-d strings is the same as comparing the dates, with
	 * no timezone in the way. Turning them into Date objects is what
	 * puts a day either side of the answer.
	 */
	function say() {
		var picked = field.value;
		var early  = picked && picked < field.min;

		note.hidden = ! early;
		field.setAttribute( 'aria-invalid', early ? 'true' : 'false' );
		field.classList.toggle( 'is-bad', !! early );

		if ( ! early ) {
			return;
		}

		var when = field.min;

		/* The date in the sentence below it is already written out in
		   the reader's own format; reuse it rather than formatting a
		   second time and risking a different one. */
		var written = ( hint.textContent || '' ).match( /is ([^.]+)\./ );

		note.textContent = window.CardsCo.i18n && window.CardsCo.i18n.tooSoon
			? window.CardsCo.i18n.tooSoon
			: 'That is too soon. The soonest we can manage is ' + ( written ? written[1] : when ) + '.';
	}

	field.addEventListener( 'change', say );
	field.addEventListener( 'input', say );
	say();
} );
