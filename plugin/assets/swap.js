/**
 * The wizard, without the page reload between steps.
 *
 * Each step used to be a full navigation: submit, server redirects,
 * browser throws the page away and builds the next one. It works, but it
 * flashes white and loses your place on the way.
 *
 * This intercepts the submit, asks the server for the next step, and puts
 * it in place of the current one. The server does exactly what it did
 * before — same POST, same validation, same redirect — so there is no
 * second code path to keep in step with the first and no JSON endpoint to
 * get out of date. What comes back is the next step's page; this takes
 * the wizard out of it and leaves the rest.
 *
 * It is an improvement on top of something that already works, so every
 * failure falls back to the ordinary navigation rather than trying to
 * recover: no script, an old browser, a network error, a reply that does
 * not parse, a redirect somewhere else entirely. The rule throughout is
 * that the worst case is the behaviour we had before.
 */
( function () {
	'use strict';

	var PANEL = '.cc-wz';

	/* Without these the swap cannot work; fall back for the whole session. */
	if ( ! window.fetch || ! window.history || ! window.DOMParser || ! window.FormData ) {
		return;
	}

	var busy = false;

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;

		if ( ! form || ! form.closest || ! form.closest( PANEL ) ) {
			return;
		}

		/* A form that posts somewhere else is not ours to intercept. */
		if ( form.method && form.method.toLowerCase() !== 'post' ) {
			return;
		}

		if ( busy ) {
			e.preventDefault();
			return;
		}

		e.preventDefault();
		send( form );
	} );

	/*
	 * Back, the stepper and the Edit links on the review step are all
	 * ordinary links within the wizard, so they move in place too. Anything
	 * leaving the wizard — Save and exit, a card preview, a new tab — is
	 * left alone.
	 */
	document.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0 ) {
			return;
		}

		var link = e.target.closest ? e.target.closest( 'a[href]' ) : null;

		if ( ! link || ! link.closest( PANEL ) ) {
			return;
		}

		if ( link.target || link.hasAttribute( 'download' ) || link.dataset.ccFull !== undefined ) {
			return;
		}

		var to;

		try {
			to = new URL( link.href, window.location.href );
		} catch ( err ) {
			return;
		}

		/* Only within this site, and only within the wizard's own page. */
		if ( to.origin !== window.location.origin || to.pathname !== window.location.pathname ) {
			return;
		}

		if ( busy ) {
			e.preventDefault();
			return;
		}

		e.preventDefault();
		go( to.href );
	} );

	/* The browser's own Back button, now that the address changes without a load. */
	window.addEventListener( 'popstate', function () {
		go( window.location.href, true );
	} );

	/**
	 * Post a step and put the answer on screen.
	 *
	 * @param {HTMLFormElement} form The step's form.
	 */
	function send( form ) {
		var panel = form.closest( PANEL );
		var body  = new FormData( form );

		/*
		 * A submit button's own name and value are part of the submission
		 * but not of FormData, and this wizard's footer button lives
		 * outside the form it submits.
		 */
		var pressed = form.ccPressed;

		if ( pressed && pressed.name ) {
			body.append( pressed.name, pressed.value );
		}

		start( panel );

		window.fetch( form.action || window.location.href, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			redirect: 'follow',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		} )
			.then( function ( r ) {
				return r.text().then( function ( html ) {
					return { html: html, url: r.url, ok: r.ok };
				} );
			} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'bad reply' );
				}

				put( r.html, r.url );
			} )
			.catch( function () {
				/* Let the browser do it the old way. */
				stop( panel );
				form.submit();
			} );
	}

	/**
	 * Fetch a step by address.
	 *
	 * @param {string}  url      Where to.
	 * @param {boolean} replace  True when the browser moved us, so the
	 *                           address is already right.
	 */
	function go( url, replace ) {
		var panel = document.querySelector( PANEL );

		if ( ! panel ) {
			window.location.href = url;
			return;
		}

		start( panel );

		window.fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'bad reply' );
				}

				return r.text();
			} )
			.then( function ( html ) {
				put( html, url, replace );
			} )
			.catch( function () {
				window.location.href = url;
			} );
	}

	/**
	 * Swap in what came back.
	 *
	 * @param {string}  html    The next step's whole page.
	 * @param {string}  url     Where it came from.
	 * @param {boolean} replace Do not add a history entry.
	 */
	function put( html, url, replace ) {
		var parsed = new window.DOMParser().parseFromString( html, 'text/html' );
		var next   = parsed.querySelector( PANEL );
		var panel  = document.querySelector( PANEL );

		/*
		 * No wizard in the reply means we were sent somewhere else —
		 * signed out, or finished and bounced to the dashboard. Let the
		 * browser go there properly rather than showing half a screen.
		 */
		if ( ! next || ! panel ) {
			window.location.href = url;
			return;
		}

		panel.innerHTML = next.innerHTML;
		panel.className = next.className;

		if ( url && url !== window.location.href ) {
			window.history[ replace ? 'replaceState' : 'pushState' ]( {}, '', url );
		}

		stop( panel );

		if ( window.CardsCo && window.CardsCo.rerun ) {
			window.CardsCo.rerun();
		}

		/*
		 * A new step is a new screen, so it starts at the top and the
		 * heading is announced — both of which a real navigation would
		 * have done for us.
		 */
		window.scrollTo( 0, 0 );

		var heading = panel.querySelector( 'h1, h2' );

		if ( heading ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
	}

	/** Mark the panel busy, and stop a second press getting through. */
	function start( panel ) {
		busy = true;

		if ( panel ) {
			panel.classList.add( 'is-loading' );
			panel.setAttribute( 'aria-busy', 'true' );
		}
	}

	/** Let go again. */
	function stop( panel ) {
		busy = false;

		if ( panel ) {
			panel.classList.remove( 'is-loading' );
			panel.removeAttribute( 'aria-busy' );
		}
	}

	/*
	 * Remember which button was pressed, so its name and value can be put
	 * back into the submission. Captured rather than bubbled, because the
	 * submit event arrives before a click listener on the way up would.
	 */
	document.addEventListener(
		'click',
		function ( e ) {
			var button = e.target.closest ? e.target.closest( 'button, input[type="submit"]' ) : null;

			if ( ! button ) {
				return;
			}

			var form = button.form || ( button.closest ? button.closest( 'form' ) : null );

			if ( form ) {
				form.ccPressed = button;
			}
		},
		true
	);
}() );
