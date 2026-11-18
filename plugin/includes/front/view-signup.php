<?php
/**
 * Create a company account.
 *
 * Company name is required, not a nicety: everything the dashboard reads is
 * scoped to one, so an account without a company has nothing to show.
 *
 * @package CardsCo
 *
 * @var array  $links  login, terms, privacy and dashboard URLs.
 * @var string $error  Whatever went wrong, if anything.
 * @var string $action Where the form posts.
 */

defined( 'ABSPATH' ) || exit;

$cc_terms   = $links['terms'] ? $links['terms'] : home_url( '/terms/' );
$cc_privacy = $links['privacy'] ? $links['privacy'] : home_url( '/privacy/' );
?>
<div class="cc-auth">
	<div class="cc-auth-split">

		<div class="cc-auth-form">

			<?php require __DIR__ . '/part-auth-logo.php'; ?>

			<main class="cc-auth-main">
				<div class="cc-auth-inner">

					<h1><?php esc_html_e( 'Set up your company', 'cardsco' ); ?></h1>
					<p class="cc-auth-lede"><?php esc_html_e( 'Takes about a minute. No card needed until you send your first batch.', 'cardsco' ); ?></p>

					<?php if ( $error ) : ?>
						<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
					<?php endif; ?>

					<form class="cc-auth-fields" method="post" action="<?php echo esc_url( $action ); ?>">
						<?php wp_nonce_field( CardsCo_Auth::NONCE_SIGNUP, '_cardsco_nonce' ); ?>
						<input type="hidden" name="cardsco_auth_action" value="signup" />
						<?php if ( ! empty( $links['dashboard'] ) ) : ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $links['dashboard'] ); ?>" />
						<?php endif; ?>

						<div class="cc-field-pair">
							<div class="cc-field">
								<label class="cc-label" for="cc-first"><?php esc_html_e( 'First name', 'cardsco' ); ?></label>
								<input class="cc-input" id="cc-first" name="first_name" type="text" autocomplete="given-name"
									value="<?php echo esc_attr( CardsCo_Auth::posted( 'first_name' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Janet', 'cardsco' ); ?>" required />
							</div>
							<div class="cc-field">
								<label class="cc-label" for="cc-last"><?php esc_html_e( 'Last name', 'cardsco' ); ?></label>
								<input class="cc-input" id="cc-last" name="last_name" type="text" autocomplete="family-name"
									value="<?php echo esc_attr( CardsCo_Auth::posted( 'last_name' ) ); ?>"
									placeholder="<?php esc_attr_e( 'Jane', 'cardsco' ); ?>" />
							</div>
						</div>

						<div class="cc-field">
							<label class="cc-label" for="cc-company"><?php esc_html_e( 'Company name', 'cardsco' ); ?></label>
							<input class="cc-input" id="cc-company" name="company" type="text" autocomplete="organization"
								value="<?php echo esc_attr( CardsCo_Auth::posted( 'company' ) ); ?>"
								aria-describedby="cc-company-hint"
								placeholder="<?php esc_attr_e( 'Brightside Ltd', 'cardsco' ); ?>" required />
							<p class="cc-hint" id="cc-company-hint"><?php esc_html_e( 'This is the name your team will see. You can change it later.', 'cardsco' ); ?></p>
						</div>

						<div class="cc-field">
							<label class="cc-label" for="cc-email"><?php esc_html_e( 'Work email', 'cardsco' ); ?></label>
							<input class="cc-input" id="cc-email" name="email" type="email" autocomplete="username"
								value="<?php echo esc_attr( CardsCo_Auth::posted( 'email' ) ); ?>"
								placeholder="<?php esc_attr_e( 'you@company.co.uk', 'cardsco' ); ?>" required />
						</div>

						<div class="cc-field">
							<label class="cc-label" for="cc-password"><?php esc_html_e( 'Password', 'cardsco' ); ?></label>
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

						<label class="cc-check" for="cc-terms">
							<input id="cc-terms" name="terms" type="checkbox" value="1" required />
							<span>
								<?php
								printf(
									/* translators: 1: link to the terms, 2: link to the privacy notice. */
									esc_html__( 'I agree to the %1$s and the %2$s.', 'cardsco' ),
									'<a href="' . esc_url( $cc_terms ) . '">' . esc_html__( 'terms', 'cardsco' ) . '</a>',
									'<a href="' . esc_url( $cc_privacy ) . '">' . esc_html__( 'privacy notice', 'cardsco' ) . '</a>'
								);
								?>
							</span>
						</label>

						<button class="cc-btn" type="submit"><?php esc_html_e( 'Create account', 'cardsco' ); ?></button>

					</form>

					<?php if ( ! empty( $links['login'] ) ) : ?>
						<p class="cc-auth-foot">
							<?php esc_html_e( 'Already have an account?', 'cardsco' ); ?>
							<a href="<?php echo esc_url( $links['login'] ); ?>"><strong><?php esc_html_e( 'Sign in', 'cardsco' ); ?></strong></a>
						</p>
					<?php endif; ?>

				</div>
			</main>

			<p class="cc-auth-smallprint">
				<?php esc_html_e( 'Been invited by a colleague? Use the link in their email so you join their company.', 'cardsco' ); ?>
			</p>

		</div>

		<div class="cc-auth-panel">
			<div class="cc-panel-inner">

				<p class="cc-panel-title" style="margin-bottom:32px"><?php esc_html_e( 'Three steps and you are done for the year.', 'cardsco' ); ?></p>

				<ol class="cc-steps">
					<li class="cc-step">
						<span class="cc-step-n">1</span>
						<span>
							<span class="cc-step-title"><?php esc_html_e( 'Add your people', 'cardsco' ); ?></span>
							<span class="cc-step-copy"><?php esc_html_e( 'Upload a spreadsheet of names and birthdays, or type them in.', 'cardsco' ); ?></span>
						</span>
					</li>
					<li class="cc-step">
						<span class="cc-step-n">2</span>
						<span>
							<span class="cc-step-title"><?php esc_html_e( 'Choose a card and the words', 'cardsco' ); ?></span>
							<span class="cc-step-copy"><?php esc_html_e( 'Pick a design, write the message once, and we fill in each name.', 'cardsco' ); ?></span>
						</span>
					</li>
					<li class="cc-step">
						<span class="cc-step-n">3</span>
						<span>
							<span class="cc-step-title"><?php esc_html_e( 'We write and post them', 'cardsco' ); ?></span>
							<span class="cc-step-copy"><?php esc_html_e( 'Real pen on real card stock, in the post ahead of the date.', 'cardsco' ); ?></span>
						</span>
					</li>
				</ol>

			</div>
		</div>

	</div>
</div>
