<?php
/**
 * "Confirm your email address" — carried at the top of the dashboard until
 * they do.
 *
 * Deliberately a band rather than a blocking screen: they can use the whole
 * product unconfirmed, right up to turning a programme on or paying.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || CardsCo_Verify::is_verified() || ! CardsCo_Verify::required() ) {
	return;
}

$cc_user  = wp_get_current_user();
$cc_said  = CardsCo_Verify::last_message( $cc_user->ID );
$cc_just  = isset( $_GET['cc_verified'] ) && '1' === $_GET['cc_verified']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cc_sent  = isset( $_GET['cc_sent'] ) && '1' === $_GET['cc_sent']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="cc-verify-band<?php echo $cc_said ? ' is-problem' : ''; ?>" role="status">
	<span class="cc-verify-icon" aria-hidden="true">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
			<rect x="2" y="4" width="20" height="16" rx="2"></rect>
			<path d="m2 7 10 7 10-7"></path>
		</svg>
	</span>

	<p class="cc-verify-text">
		<?php if ( $cc_said ) : ?>
			<?php echo esc_html( $cc_said ); ?>
		<?php elseif ( $cc_sent ) : ?>
			<?php
			printf(
				/* translators: %s: their email address. */
				esc_html__( 'Sent. Have a look in %s, and in spam if it is not there.', 'cardsco' ),
				'<strong>' . esc_html( $cc_user->user_email ) . '</strong>'
			);
			?>
		<?php else : ?>
			<?php
			printf(
				/* translators: %s: their email address. */
				esc_html__( 'Please confirm %s. You can carry on setting things up — we will ask for this before any cards go out.', 'cardsco' ),
				'<strong>' . esc_html( $cc_user->user_email ) . '</strong>'
			);
			?>
		<?php endif; ?>
	</p>

	<form class="cc-verify-form" method="post" action="">
		<?php cardsco_nonce_field( 'cardsco_resend_verify' ); ?>
		<input type="hidden" name="cardsco_auth_action" value="resend_verify" />
		<button class="cc-verify-resend" type="submit"><?php esc_html_e( 'Send it again', 'cardsco' ); ?></button>
	</form>
</div>
