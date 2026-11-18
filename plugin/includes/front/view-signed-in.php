<?php
/**
 * What somebody already signed in sees on a login or sign-up page.
 *
 * Bouncing them straight to the dashboard would be worse: somebody who
 * clicked "sign up" while signed in as a colleague needs to see whose
 * account they are in before they do anything else.
 *
 * @package CardsCo
 *
 * @var string $dashboard Where the dashboard lives.
 */

defined( 'ABSPATH' ) || exit;

$cc_user    = wp_get_current_user();
$cc_company = CardsCo_Company::get( cardsco_get_user_company_id( $cc_user->ID ) );
?>
<div class="cc-auth">
	<div class="cc-auth-shell">

		<?php require __DIR__ . '/part-auth-logo.php'; ?>

		<main class="cc-auth-card">

			<h1><?php esc_html_e( 'You are already signed in', 'cardsco' ); ?></h1>
			<p class="cc-auth-lede">
				<?php
				if ( $cc_company ) {
					printf(
						/* translators: 1: the person's name, 2: their company. */
						esc_html__( 'You are signed in as %1$s at %2$s.', 'cardsco' ),
						'<strong>' . esc_html( $cc_user->display_name ) . '</strong>',
						'<strong>' . esc_html( $cc_company['name'] ) . '</strong>'
					);
				} else {
					printf(
						/* translators: %s: the person's name. */
						esc_html__( 'You are signed in as %s, but your account is not attached to a company yet.', 'cardsco' ),
						'<strong>' . esc_html( $cc_user->display_name ) . '</strong>'
					);
				}
				?>
			</p>

			<div class="cc-auth-fields">
				<?php if ( $cc_company ) : ?>
					<a class="cc-btn" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Go to your dashboard', 'cardsco' ); ?></a>
				<?php endif; ?>
				<a class="cc-btn-ghost" href="<?php echo esc_url( wp_logout_url( $dashboard ) ); ?>"><?php esc_html_e( 'Sign out', 'cardsco' ); ?></a>
			</div>

		</main>

	</div>
</div>
