<?php
/**
 * Sign in.
 *
 * @package CardsCo
 *
 * @var array  $links  signup, reset and dashboard URLs from the shortcode.
 * @var string $error  Whatever went wrong, if anything.
 * @var string $action Where the form posts, which is this page.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cc-auth">
	<div class="cc-auth-split">

		<div class="cc-auth-form">

			<?php require __DIR__ . '/part-auth-logo.php'; ?>

			<main class="cc-auth-main">
				<div class="cc-auth-inner">

					<h1><?php esc_html_e( 'Welcome back', 'cardsco' ); ?></h1>
					<p class="cc-auth-lede"><?php esc_html_e( 'Sign in to manage your occasions, cards and campaigns.', 'cardsco' ); ?></p>

					<?php if ( $error ) : ?>
						<p class="cc-alert" role="alert"><?php echo esc_html( $error ); ?></p>
					<?php endif; ?>

					<form class="cc-auth-fields" method="post" action="<?php echo esc_url( $action ); ?>">
						<?php cardsco_nonce_field( CardsCo_Auth::NONCE_LOGIN ); ?>
						<input type="hidden" name="cardsco_auth_action" value="login" />
						<?php if ( ! empty( $links['dashboard'] ) ) : ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $links['dashboard'] ); ?>" />
						<?php endif; ?>

						<div class="cc-field">
							<label class="cc-label" for="cc-email"><?php esc_html_e( 'Work email', 'cardsco' ); ?></label>
							<input class="cc-input" id="cc-email" name="email" type="email" autocomplete="username"
								value="<?php echo esc_attr( CardsCo_Auth::posted( 'email' ) ); ?>"
								placeholder="<?php esc_attr_e( 'you@company.co.uk', 'cardsco' ); ?>" required />
						</div>

						<div class="cc-field">
							<div class="cc-field-row">
								<label class="cc-label" for="cc-password"><?php esc_html_e( 'Password', 'cardsco' ); ?></label>
								<?php if ( ! empty( $links['reset'] ) ) : ?>
									<a class="cc-forgot" href="<?php echo esc_url( $links['reset'] ); ?>"><?php esc_html_e( 'Forgot password?', 'cardsco' ); ?></a>
								<?php endif; ?>
							</div>
							<div class="cc-password">
								<input class="cc-input" id="cc-password" name="password" type="password" autocomplete="current-password"
									placeholder="<?php esc_attr_e( 'Your password', 'cardsco' ); ?>" required />
								<button class="cc-reveal" type="button" data-cc-reveal="cc-password"
									aria-label="<?php esc_attr_e( 'Show password', 'cardsco' ); ?>"><?php esc_html_e( 'Show', 'cardsco' ); ?></button>
							</div>
						</div>

						<label class="cc-check" for="cc-remember">
							<input id="cc-remember" name="remember" type="checkbox" value="1" />
							<span><?php esc_html_e( 'Keep me signed in for 30 days', 'cardsco' ); ?></span>
						</label>

						<button class="cc-btn" type="submit"><?php esc_html_e( 'Sign in', 'cardsco' ); ?></button>

					</form>

					<?php if ( ! empty( $links['signup'] ) ) : ?>
						<p class="cc-auth-foot">
							<?php esc_html_e( 'New to CardsCo?', 'cardsco' ); ?>
							<a href="<?php echo esc_url( $links['signup'] ); ?>"><strong><?php esc_html_e( 'Create an account', 'cardsco' ); ?></strong></a>
						</p>
					<?php endif; ?>

				</div>
			</main>

			<p class="cc-auth-smallprint">
				<?php
				printf(
					/* translators: %s: a mailto link reading "Email us". */
					esc_html__( 'Having trouble? %s and we will sort it out.', 'cardsco' ),
					'<a href="mailto:' . esc_attr( CardsCo_Settings::get( 'support_email', 'hello@cardsco.uk' ) ) . '">' . esc_html__( 'Email us', 'cardsco' ) . '</a>'
				);
				?>
			</p>

		</div>

		<div class="cc-auth-panel">
			<div class="cc-panel-inner">

				<div class="cc-cardmock" aria-hidden="true">
					<div class="cc-cardmock-back"></div>
					<div class="cc-cardmock-front">
						<p class="cc-cardmock-msg"><?php esc_html_e( 'Happy birthday, Janet — hope it is a good one.', 'cardsco' ); ?></p>
						<p class="cc-cardmock-sign"><?php esc_html_e( 'Everyone at Brightside', 'cardsco' ); ?></p>
					</div>
				</div>

				<p class="cc-panel-title"><?php esc_html_e( 'Written by hand. Posted for you.', 'cardsco' ); ?></p>
				<p class="cc-panel-copy"><?php esc_html_e( 'Set an occasion once and we write, stamp and post the card every year without being asked.', 'cardsco' ); ?></p>

			</div>
		</div>

	</div>
</div>
