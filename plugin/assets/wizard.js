/**
 * Selection feedback in the set-up wizard.
 *
 * The screens are plain forms and work without this — a radio is still a
 * radio, and the server validates on submit. What this adds is the thing a
 * person expects the instant they click: the card they picked looks picked,
 * and the footer stops saying nothing is chosen.
 *
 * Written against what is on the page rather than against a step number, so
 * a new step that uses the same markup gets the behaviour for nothing.
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

	/* Each group: the radio's name, the element that carries the state, and
	   the class it carries. */
	/*
	 * Step 6 was missing from this table, and its radios are the invisible
	 * kind: the panel's green border and filled dot come from the class
	 * below, so clicking "A few days before each birthday" checked the
	 * radio and changed nothing on screen. It read as an option that could
	 * not be chosen when it had in fact been chosen.
	 */
	var GROUPS = [
		{ name: 'category', item: '.cc-pick', on: 'is-chosen' },
		{ name: 'card_id', item: '.cc-card-pick', on: 'is-on' },
		{ name: 'shipping_mode', item: '.cc-ship-mode', on: 'is-on' },
		{ name: 'return_mode', item: '.cc-radio', on: 'is-on' },
		{ name: 'timing_mode', item: '.cc-opt', on: 'is-on' },
		{ name: 'occasion_type_id', item: '.cc-tim-chip', on: 'is-on' },
		{ name: 'how', item: '.cc-up-ask-one', on: 'is-on' },
		{ name: 'order_type', item: '.cc-kind-one', on: 'is-on' }
	];

	function closest( el, selector ) {
		while ( el && el.nodeType === 1 ) {
			if ( el.matches && el.matches( selector ) ) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	/**
	 * What the footer says beside Continue, once something is chosen.
	 */
	function labelFor( input, item ) {
		var named = item.querySelector( '.cc-pick-name, .cc-card-pick-name, .cc-ship-mode-head, .cc-opt-head, .cc-tim-chip-name' );

		return named ? named.textContent.trim() : '';
	}

	function wire( group ) {
		var inputs = document.querySelectorAll( 'input[type="radio"][name="' + group.name + '"]' );

		if ( ! inputs.length ) {
			return;
		}

		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'change', function () {
				if ( ! input.checked ) {
					return;
				}

				var mine = closest( input, group.item );

				Array.prototype.forEach.call( inputs, function ( other ) {
					var box = closest( other, group.item );

					if ( box ) {
						box.classList.toggle( group.on, other === input );
					}
				} );

				var button = document.querySelector( '.cc-wz-foot .cc-btn-primary' );

				if ( button && button.hasAttribute( 'disabled' ) ) {
					button.removeAttribute( 'disabled' );
				}

				var state = document.querySelector( '.cc-wz-state' );
				var name = mine ? labelFor( input, mine ) : '';

				if ( state && name ) {
					/* Kept in the markup rather than built here, so the
					   wording stays translatable. */
					var pattern = state.getAttribute( 'data-cc-chosen' );

					if ( pattern ) {
						state.textContent = pattern.replace( '%s', name );
					}
				}

				/* Step 5 asks for one address when the batch goes to one
				   place, and for everybody's when it does not.

				   Only the company panel was switched here. The per-person
				   boxes and the address upload are rendered switched off,
				   and nothing ever switched them back on, so choosing
				   "individual addresses" took the one panel away and put
				   nothing in its place: an empty screen with no way to
				   enter an address at all. */
				if ( group.name === 'shipping_mode' ) {
					var toOnePlace = input.value === 'company';
					var panel      = document.querySelector( '.cc-ship-panel' );

					if ( panel ) {
						panel.classList.toggle( 'is-off', ! toOnePlace );
					}

					[ '.cc-each', '.cc-each-up' ].forEach( function ( sel ) {
						var each = document.querySelector( sel );

						if ( each ) {
							each.classList.toggle( 'is-off', toOnePlace );
						}
					} );
				}

				/* A different return address is only worth asking for when
				   that is what was picked. */
				if ( group.name === 'return_mode' ) {
					var box = document.querySelector( '.cc-set-return' );

					if ( box ) {
						box.classList.toggle( 'is-hidden', input.value !== 'other' );
					}
				}
			} );
		} );
	}

	function start() {
		GROUPS.forEach( wire );
	}

	/* boot.js already waits for the document before running this, and runs
	   it again after each swap, so there is nothing to wait for here. */
	start();
} );

/**
 * Step 2 — the upload row.
 *
 * Choosing a file submits, which saves a second click and matches the frame,
 * where there is no separate Upload button. The button exists in the markup
 * for anyone without JavaScript and is revealed here only if this runs and
 * the auto-submit is somehow prevented.
 */
window.CardsCo.add( function () {
	'use strict';

	/* Every upload on the page, not the first: the control is a partial
	   now and more than one screen carries it. */
	var forms = document.querySelectorAll( '.cc-up-file' );
	if ( ! forms.length ) { return; }

	Array.prototype.forEach.call( forms, function ( form ) {
		var input = form.querySelector( '[data-cc-up-input]' );
		var name  = form.querySelector( '[data-cc-up-name]' );
		var meta  = form.querySelector( '[data-cc-up-meta]' );

		if ( ! input ) { return; }

		input.addEventListener( 'change', function () {
			if ( ! input.files || ! input.files.length ) { return; }

			var file = input.files[0];

			/* Say what is happening before the page goes, so a large file
			   does not look like a dead click. */
			if ( name ) { name.textContent = file.name; }
			if ( meta ) { meta.textContent = 'Reading your file…'; }

			form.submit();
		} );
	} );
} );

/**
 * Step 3 — several designs.
 *
 * Where an order may carry more than one design the tiles are tickboxes.
 * This keeps the screen honest about what is ticked: the tile's highlight,
 * the box for how many cards a ticked design is to have, the most that may
 * be ticked, and a running line saying whether the numbers add up.
 *
 * None of it is the rule. The server counts the designs and adds up the
 * numbers when the step is saved, so without this the page still works —
 * it is just told afterwards rather than as it goes.
 */
window.CardsCo.add( function () {
	'use strict';

	var list = document.querySelector( '[data-cc-mix]' );

	if ( ! list || list.getAttribute( 'data-cc-mix-ready' ) ) {
		return;
	}

	list.setAttribute( 'data-cc-mix-ready', '1' );

	var limit  = parseInt( list.getAttribute( 'data-cc-limit' ), 10 ) || 1;
	var cards  = parseInt( list.getAttribute( 'data-cc-cards' ), 10 ) || 0;
	var boxes  = list.querySelectorAll( 'input[type="checkbox"][name="card_ids[]"]' );
	var status = document.querySelector( '[data-cc-mix-status]' );
	var full   = false;

	function tileOf( box ) {
		var el = box;

		while ( el && ! ( el.classList && el.classList.contains( 'cc-card-pick' ) ) ) {
			el = el.parentElement;
		}

		return el;
	}

	function qtyOf( box ) {
		var tile = tileOf( box );

		return tile ? tile.querySelector( '.cc-card-pick-qty input' ) : null;
	}

	function fill( pattern, a, b, c, d ) {
		return String( pattern || '' )
			.replace( '%1$s', a )
			.replace( '%2$s', b )
			.replace( '%3$s', c )
			.replace( '%4$s', d );
	}

	function say( text, tone ) {
		if ( ! status ) {
			return;
		}

		status.textContent = text;
		status.classList.toggle( 'is-wrong', tone === 'wrong' );
		status.classList.toggle( 'is-right', tone === 'right' );
	}

	function refresh() {
		var ticked = 0;
		var typed  = 0;
		var sum    = 0;

		Array.prototype.forEach.call( boxes, function ( box ) {
			var tile = tileOf( box );
			var qty  = qtyOf( box );

			if ( tile ) {
				tile.classList.toggle( 'is-on', box.checked );
			}

			if ( qty ) {
				/* An unticked design sends no number. */
				qty.disabled = ! box.checked;
			}

			if ( ! box.checked ) {
				return;
			}

			ticked++;

			var n = qty ? parseInt( qty.value, 10 ) : NaN;

			if ( qty && qty.value !== '' && ! isNaN( n ) && n > 0 ) {
				typed++;
				sum += n;
			} else if ( qty && qty.value !== '' ) {
				/* Something typed that is not a number above nought. */
				typed += 0.5;
			}
		} );

		var button = document.querySelector( '.cc-wz-foot .cc-btn-primary' );

		if ( button ) {
			if ( ticked > 0 ) {
				button.removeAttribute( 'disabled' );
			} else {
				button.setAttribute( 'disabled', 'disabled' );
			}
		}

		var state = document.querySelector( '.cc-wz-state' );

		if ( state && status ) {
			state.textContent = ticked === 1
				? status.getAttribute( 'data-cc-count-one' )
				: ( ticked > 1 ? fill( status.getAttribute( 'data-cc-count' ), ticked ) : status.getAttribute( 'data-cc-none' ) );
		}

		if ( ! status ) {
			return;
		}

		if ( full ) {
			full = false;
			say( status.getAttribute( 'data-cc-full' ), 'wrong' );
			return;
		}

		if ( ! ticked ) {
			say( status.getAttribute( 'data-cc-none' ), '' );
		} else if ( ticked === 1 ) {
			say( fill( status.getAttribute( 'data-cc-one' ), 1, cards ), 'right' );
		} else if ( typed === 0 ) {
			say( fill( status.getAttribute( 'data-cc-even' ), ticked, cards ), 'right' );
		} else if ( typed !== ticked ) {
			say( status.getAttribute( 'data-cc-part' ), 'wrong' );
		} else if ( sum === cards ) {
			say( fill( status.getAttribute( 'data-cc-right' ), ticked, cards, sum ), 'right' );
		} else if ( sum < cards ) {
			say( fill( status.getAttribute( 'data-cc-short' ), ticked, cards, sum, cards - sum ), 'wrong' );
		} else {
			say( fill( status.getAttribute( 'data-cc-over' ), ticked, cards, sum, sum - cards ), 'wrong' );
		}
	}

	Array.prototype.forEach.call( boxes, function ( box ) {
		box.addEventListener( 'change', function () {
			if ( box.checked ) {
				var ticked = 0;

				Array.prototype.forEach.call( boxes, function ( other ) {
					if ( other.checked ) {
						ticked++;
					}
				} );

				/* One more than the order may carry: it does not take. */
				if ( ticked > limit ) {
					box.checked = false;
					full        = true;
				}
			}

			refresh();
		} );

		var qty = qtyOf( box );

		if ( qty ) {
			qty.addEventListener( 'input', refresh );
		}
	} );

	/*
	 * The tabs. Every card is already on the page, so they hide and show
	 * rather than load — a reload would drop every tick not yet saved.
	 */
	var tabs  = document.querySelectorAll( '[data-cc-mix-tab]' );
	var empty = document.querySelector( '[data-cc-mix-empty]' );

	Array.prototype.forEach.call( tabs, function ( tab ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			/* The wizard's own link handling must not load the tab. */
			e.stopPropagation();

			var mineOnly = tab.getAttribute( 'data-cc-mix-tab' ) === 'mine';
			var shown    = 0;

			Array.prototype.forEach.call( list.querySelectorAll( '.cc-card-pick' ), function ( tile ) {
				var hide = mineOnly && tile.getAttribute( 'data-cc-mine' ) !== '1';

				if ( hide ) {
					tile.setAttribute( 'hidden', 'hidden' );
				} else {
					tile.removeAttribute( 'hidden' );
					shown++;
				}
			} );

			if ( empty ) {
				if ( shown ) {
					empty.setAttribute( 'hidden', 'hidden' );
				} else {
					empty.removeAttribute( 'hidden' );
				}
			}

			Array.prototype.forEach.call( tabs, function ( other ) {
				other.classList.toggle( 'is-on', other === tab );

				if ( other === tab ) {
					other.setAttribute( 'aria-current', 'true' );
				} else {
					other.removeAttribute( 'aria-current' );
				}
			} );
		} );
	} );

	refresh();
} );
