<?php
/**
 * Resetting a password, in its three states.
 *
 *   ask  — type your address and we send a link
 *   sent — the same screen whether or not that address has an account
 *   set  — arrived from the link, choose a new password
 *
 * One page rather than three, the way core's own reset works, so there is
 * one URL to configure and one page for you to build.
 *
 * @package CardsCo
 *
 * @var array  $links  login URL.
 * @var string $stage  ask | sent | set
 * @var string $key    The reset key, on the set stage.
 * @var string $login  Who it belongs to, on the set stage.
 * @var string $error  Whatever went wrong, if anything.
 * @var string $action Where the form posts.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cc-auth">
	<div class="cc-auth-shell">

		<?php require __DIR__ . '/part-auth-logo.php'; ?>

		<main class="cc-auth-card<?php echo ( 'sent' === $stage ) ? ' cc-auth-card--centred' : ''; ?>">

			<?php if ( 'ask' === $stage ) : ?>

				<h1><?php esc_html_e( 'Reset your password', 'cardsco' ); ?></h1>
				<p class="cc-auth-lede"><?php esc_html_e( 'Give us the address you sign in with and we will send you a link to set a new password.', 'cardsco' ); ?></p>

				<?php if ( $error ) : ?>
					<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>

				<form class="cc-auth-fields" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php cardsco_nonce_field( CardsCo_Auth::NONCE_RESET ); ?>
					<input type="hidden" name="cardsco_auth_action" value="reset_request" />

					<div class="cc-field">
						<label class="cc-label" for="cc-email"><?php esc_html_e( 'Work email', 'cardsco' ); ?></label>
						<input class="cc-input" id="cc-email" name="email" type="email" autocomplete="username"
							value="<?php echo esc_attr( CardsCo_Auth::posted( 'email' ) ); ?>"
							placeholder="<?php esc_attr_e( 'you@company.co.uk', 'cardsco' ); ?>" required />
					</div>

					<button class="cc-btn" type="submit"><?php esc_html_e( 'Send the link', 'cardsco' ); ?></button>
				</form>

			<?php elseif ( 'sent' === $stage ) : ?>

				<span class="cc-auth-icon" aria-hidden="true">
					<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0F5A43" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 8.5V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8.5"></path>
						<path d="m3 8.5 9-5.5 9 5.5"></path>
						<path d="m3 8.5 8.1 5.4a1.7 1.7 0 0 0 1.8 0L21 8.5"></path>
					</svg>
				</span>

				<h1><?php esc_html_e( 'Check your email', 'cardsco' ); ?></h1>
				<p class="cc-auth-lede">
					<?php esc_html_e( 'If there is an account on that address, a link is on its way. It works for one hour.', 'cardsco' ); ?>
				</p>

				<div class="cc-note" style="text-align:left">
					<p class="cc-note-title"><?php esc_html_e( 'Nothing there after a minute?', 'cardsco' ); ?></p>
					<p class="cc-note-copy"><?php esc_html_e( 'Have a look in spam, and check the address you typed is the one you sign in with.', 'cardsco' ); ?></p>
				</div>

				<p class="cc-auth-foot cc-auth-foot--ruled">
					<a href="<?php echo esc_url( $action ); ?>"><?php esc_html_e( 'Try a different address', 'cardsco' ); ?></a>
				</p>

			<?php else : ?>

				<h1><?php esc_html_e( 'Set a new password', 'cardsco' ); ?></h1>
				<p class="cc-auth-lede"><?php esc_html_e( 'Choose something you will remember. We will sign you in straight after.', 'cardsco' ); ?></p>

				<?php if ( $error ) : ?>
					<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>

				<form class="cc-auth-fields" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php cardsco_nonce_field( CardsCo_Auth::NONCE_RESET ); ?>
					<input type="hidden" name="cardsco_auth_action" value="reset_save" />
					<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
					<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />

					<div class="cc-field">
						<label class="cc-label" for="cc-pw1"><?php esc_html_e( 'New password', 'cardsco' ); ?></label>
						<div class="cc-password">
							<input class="cc-input" id="cc-pw1" name="password" type="password" autocomplete="new-password"
								minlength="<?php echo esc_attr( CardsCo_Auth::MIN_PASSWORD ); ?>"
								placeholder="<?php esc_attr_e( 'At least 10 characters', 'cardsco' ); ?>" required />
							<button class="cc-reveal" type="button" data-cc-reveal="cc-pw1"
								aria-label="<?php esc_attr_e( 'Show password', 'cardsco' ); ?>"><?php esc_html_e( 'Show', 'cardsco' ); ?></button>
						</div>
					</div>

					<div class="cc-field">
						<label class="cc-label" for="cc-pw2"><?php esc_html_e( 'Type it again', 'cardsco' ); ?></label>
						<input class="cc-input" id="cc-pw2" name="password_confirm" type="password" autocomplete="new-password"
							placeholder="<?php esc_attr_e( 'The same password', 'cardsco' ); ?>" required />
					</div>

					<ul class="cc-rules" aria-live="polite">
						<li class="cc-rule" data-cc-rule="length" role="checkbox" aria-checked="false" tabindex="-1">
							<span class="cc-rule-mark" aria-hidden="true"></span>
							<span><?php esc_html_e( 'At least 10 characters', 'cardsco' ); ?></span>
						</li>
						<li class="cc-rule" data-cc-rule="match" role="checkbox" aria-checked="false" tabindex="-1">
							<span class="cc-rule-mark" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Both boxes match', 'cardsco' ); ?></span>
						</li>
					</ul>

					<button class="cc-btn" type="submit"><?php esc_html_e( 'Save and sign in', 'cardsco' ); ?></button>
				</form>

			<?php endif; ?>

			<?php if ( ! empty( $links['login'] ) && 'sent' !== $stage ) : ?>
				<p class="cc-auth-foot cc-auth-foot--ruled">
					<a href="<?php echo esc_url( $links['login'] ); ?>"><strong><?php esc_html_e( 'Back to sign in', 'cardsco' ); ?></strong></a>
				</p>
			<?php endif; ?>

		</main>

		<p class="cc-auth-smallprint" style="max-width:460px;text-align:center">
			<?php
			if ( 'set' === $stage ) {
				esc_html_e( 'Saving a new password signs out every other device.', 'cardsco' );
			} else {
				esc_html_e( 'The link lasts one hour. If it expires, come back here and ask for another.', 'cardsco' );
			}
			?>
		</p>

	</div>
</div>
