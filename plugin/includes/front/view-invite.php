<?php
/**
 * Accepting an invitation to join a company.
 *
 * The person already exists as a WordPress user with no usable password and
 * a membership row that is not yet active, so the link carries core's own
 * reset key. Accepting sets the password and turns the membership on.
 *
 * @package CardsCo
 *
 * @var array      $links   login, terms and privacy URLs.
 * @var string     $key     The invitation key.
 * @var string     $login   Who it belongs to.
 * @var bool       $valid   Whether the key and the pending membership check out.
 * @var string     $email   The invited address, shown but not editable.
 * @var array|null $company The company they are joining.
 * @var string     $error   Whatever went wrong, if anything.
 * @var string     $action  Where the form posts.
 */

defined( 'ABSPATH' ) || exit;

$cc_terms   = $links['terms'] ? $links['terms'] : home_url( '/terms/' );
$cc_privacy = $links['privacy'] ? $links['privacy'] : home_url( '/privacy/' );
$cc_name    = $company ? $company['name'] : '';
?>
<div class="cc-auth">
	<div class="cc-auth-split">

		<div class="cc-auth-form">

			<?php require __DIR__ . '/part-auth-logo.php'; ?>

			<main class="cc-auth-main">
				<div class="cc-auth-inner">

					<?php if ( ! $valid ) : ?>

						<h1><?php esc_html_e( 'That invitation is not open', 'cardsco' ); ?></h1>
						<p class="cc-auth-lede">
							<?php esc_html_e( 'It may have expired, or somebody may have used it already. Ask whoever invited you to send another.', 'cardsco' ); ?>
						</p>

						<?php if ( $error ) : ?>
							<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
						<?php endif; ?>

						<?php if ( ! empty( $links['login'] ) ) : ?>
							<p class="cc-auth-foot">
								<?php esc_html_e( 'Already have an account?', 'cardsco' ); ?>
								<a href="<?php echo esc_url( $links['login'] ); ?>"><strong><?php esc_html_e( 'Sign in', 'cardsco' ); ?></strong></a>
							</p>
						<?php endif; ?>

					<?php else : ?>

						<span class="cc-pill">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
								<circle cx="9" cy="7" r="4"></circle>
								<path d="M22 11h-6"></path>
							</svg>
							<?php esc_html_e( 'You have been invited', 'cardsco' ); ?>
						</span>

						<h1>
							<?php
							printf(
								/* translators: %s: the company name. */
								esc_html__( 'Join %s', 'cardsco' ),
								esc_html( $cc_name )
							);
							?>
						</h1>
						<p class="cc-auth-lede">
							<?php
							printf(
								/* translators: %s: the company name. */
								esc_html__( 'You have been asked to help look after %s&#39;s cards. Pick a password and you are in.', 'cardsco' ),
								esc_html( $cc_name )
							);
							?>
						</p>

						<?php if ( $error ) : ?>
							<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
						<?php endif; ?>

						<form class="cc-auth-fields" method="post" action="<?php echo esc_url( $action ); ?>">
							<?php wp_nonce_field( CardsCo_Auth::NONCE_INVITE, '_cardsco_nonce' ); ?>
							<input type="hidden" name="cardsco_auth_action" value="invite_accept" />
							<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
							<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />

							<div class="cc-field">
								<label class="cc-label" for="cc-email"><?php esc_html_e( 'Your email', 'cardsco' ); ?></label>
								<input class="cc-input" id="cc-email" type="email" value="<?php echo esc_attr( $email ); ?>"
									readonly aria-describedby="cc-email-hint" />
								<p class="cc-hint" id="cc-email-hint"><?php esc_html_e( 'The invitation was sent to this address, so it cannot be changed here.', 'cardsco' ); ?></p>
							</div>

							<div class="cc-field-pair">
								<div class="cc-field">
									<label class="cc-label" for="cc-first"><?php esc_html_e( 'First name', 'cardsco' ); ?></label>
									<input class="cc-input" id="cc-first" name="first_name" type="text" autocomplete="given-name"
										value="<?php echo esc_attr( CardsCo_Auth::posted( 'first_name' ) ); ?>" required />
								</div>
								<div class="cc-field">
									<label class="cc-label" for="cc-last"><?php esc_html_e( 'Last name', 'cardsco' ); ?></label>
									<input class="cc-input" id="cc-last" name="last_name" type="text" autocomplete="family-name"
										value="<?php echo esc_attr( CardsCo_Auth::posted( 'last_name' ) ); ?>" />
								</div>
							</div>

							<div class="cc-field">
								<label class="cc-label" for="cc-password"><?php esc_html_e( 'Choose a password', 'cardsco' ); ?></label>
								<div class="cc-password">
									<input class="cc-input" id="cc-password" name="password" type="password" autocomplete="new-password"
										minlength="<?php echo esc_attr( CardsCo_Auth::MIN_PASSWORD ); ?>"
										aria-describedby="cc-pw-hint"
										placeholder="<?php esc_attr_e( 'At least 10 characters', 'cardsco' ); ?>" required />
									<button class="cc-reveal" type="button" data-cc-reveal="cc-password"
										aria-label="<?php esc_attr_e( 'Show password', 'cardsco' ); ?>"><?php esc_html_e( 'Show', 'cardsco' ); ?></button>
								</div>
								<p class="cc-hint" id="cc-pw-hint"><?php esc_html_e( 'Ten characters or more. A short phrase beats a clever word.', 'cardsco' ); ?></p>
							</div>

							<button class="cc-btn" type="submit">
								<?php
								printf(
									/* translators: %s: the company name. */
									esc_html__( 'Join %s', 'cardsco' ),
									esc_html( $cc_name )
								);
								?>
							</button>
						</form>

						<p class="cc-auth-foot">
							<?php
							printf(
								/* translators: 1: link to the terms, 2: link to the privacy notice. */
								esc_html__( 'By joining you agree to the %1$s and the %2$s.', 'cardsco' ),
								'<a href="' . esc_url( $cc_terms ) . '">' . esc_html__( 'terms', 'cardsco' ) . '</a>',
								'<a href="' . esc_url( $cc_privacy ) . '">' . esc_html__( 'privacy notice', 'cardsco' ) . '</a>'
							);
							?>
						</p>

					<?php endif; ?>

				</div>
			</main>

			<p class="cc-auth-smallprint">
				<?php
				printf(
					/* translators: %s: a mailto link reading "Let us know". */
					esc_html__( 'Not expecting this? %s and we will cancel the invitation.', 'cardsco' ),
					'<a href="mailto:' . esc_attr( CardsCo_Settings::get( 'support_email', 'hello@cardsco.uk' ) ) . '">' . esc_html__( 'Let us know', 'cardsco' ) . '</a>'
				);
				?>
			</p>

		</div>

		<div class="cc-auth-panel">
			<div class="cc-panel-inner">

				<p class="cc-panel-title" style="margin-bottom:28px"><?php esc_html_e( 'What you will be able to do', 'cardsco' ); ?></p>

				<ul class="cc-ticks">
					<li><?php esc_html_e( 'See every occasion coming up and what has already gone out', 'cardsco' ); ?></li>
					<li><?php esc_html_e( 'Add people, fix addresses and set up new occasions', 'cardsco' ); ?></li>
					<li><?php esc_html_e( 'Write the wording and choose the cards', 'cardsco' ); ?></li>
				</ul>

				<p class="cc-panel-note"><?php esc_html_e( 'Billing and account settings stay with the owner.', 'cardsco' ); ?></p>

			</div>
		</div>

	</div>
</div>
