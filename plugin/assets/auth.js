/**
 * CardsCo — auth pages
 *
 * WPCode → HTML snippet → Footer, wrapped in <script> tags. Or Oxygen's JS
 * section on each auth page if you would rather keep it per page.
 *
 * Two jobs: reveal a password, and tick off the rules on the reset page.
 * Everything else is the browser's own form validation.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}

	/* ------------------------------------------------------------------
	 * Show / hide a password
	 *
	 * Driven by data-cc-reveal="<id of the input>", so one handler covers
	 * every page and a second password box needs no extra code.
	 * ---------------------------------------------------------------- */

	function wireReveals() {
		var buttons = document.querySelectorAll('[data-cc-reveal]');
		if (!buttons.length) { return; }

		Array.prototype.forEach.call(buttons, function (button) {
			var input = document.getElementById(button.getAttribute('data-cc-reveal'));
			if (!input) { return; }

			button.addEventListener('click', function () {
				var hidden = input.type === 'password';
				input.type = hidden ? 'text' : 'password';
				button.textContent = hidden ? 'Hide' : 'Show';
				button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
				input.focus();
			});
		});
	}

	/* ------------------------------------------------------------------
	 * The two rules on the new-password page
	 *
	 * Only runs if that page's markup is present. The server still has to
	 * check both — this is just so nobody submits and waits to be told.
	 * ---------------------------------------------------------------- */

	function wirePasswordRules() {
		var pw1 = document.getElementById('cc-pw1');
		var pw2 = document.getElementById('cc-pw2');
		var ruleLength = document.querySelector('[data-cc-rule="length"]');
		var ruleMatch = document.querySelector('[data-cc-rule="match"]');

		if (!pw1 || !pw2 || !ruleLength || !ruleMatch) { return; }

		function mark(el, met) {
			el.classList.toggle('is-met', met);
			el.setAttribute('aria-checked', met ? 'true' : 'false');
		}

		function check() {
			var a = pw1.value;
			var b = pw2.value;
			mark(ruleLength, a.length >= 10);
			mark(ruleMatch, a.length > 0 && a === b);
		}

		pw1.addEventListener('input', check);
		pw2.addEventListener('input', check);
		check();
	}

	/* ------------------------------------------------------------------
	 * Resend on the check-your-email page
	 * ---------------------------------------------------------------- */

	function wireResend() {
		var button = document.querySelector('[data-cc-resend]');
		if (!button) { return; }

		button.addEventListener('click', function () {
			button.disabled = true;
			button.textContent = 'Sent — have another look';
		});
	}

	ready(function () {
		wireReveals();
		wirePasswordRules();
		wireResend();
	});
}());
