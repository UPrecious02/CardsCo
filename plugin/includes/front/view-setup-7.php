<?php
/**
 * Step 7 — check it over, then pay.
 *
 * Measured from the step-7 frame: a 608x475 review card at x=64 holding six
 * rows on a 79 pitch, and a 320-wide "What you'll pay" panel at x=696.
 *
 * The footer button says "Go to payment" rather than Continue, because that
 * is where it goes. Nothing is activated or charged on this screen.
 *
 * @package CardsCo
 *
 * @var array $review    From CardsCo_Dashboard::setup_review().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
	<?php /* Not "step": there is no step eight. This one hands over to payment. */ ?>
	<input type="hidden" name="cardsco_setup_action" value="pay" />
	<input type="hidden" name="step" value="7" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
</form>

<div class="cc-rev">

	<section class="cc-rev-list">
		<?php foreach ( $review['rows'] as $cc_row ) : ?>
			<div class="cc-rev-row">
				<div class="cc-rev-text">
					<p class="cc-rev-label"><?php echo esc_html( $cc_row['label'] ); ?></p>
					<p class="cc-rev-value"><?php echo esc_html( $cc_row['value'] ); ?></p>
					<?php if ( $cc_row['detail'] ) : ?>
						<p class="cc-rev-detail"><?php echo esc_html( $cc_row['detail'] ); ?></p>
					<?php endif; ?>
				</div>
				<a class="cc-rev-edit" href="<?php echo esc_url( $cc_row['edit'] ); ?>">
					<?php esc_html_e( 'Edit', 'cardsco' ); ?>
					<span class="cc-sr">
						<?php
						printf(
							/* translators: %s: which part of the set-up. */
							esc_html__( '— %s', 'cardsco' ),
							esc_html( $cc_row['label'] )
						);
						?>
					</span>
				</a>
			</div>
		<?php endforeach; ?>
	</section>

	<?php
	/*
	 * Twenty-five or more posted to individual addresses is not priced
	 * here — see CardsCo_Programme::needs_referral(). The whole payment
	 * panel is replaced rather than sat alongside a total, because a
	 * figure on this screen is a figure the customer will hold us to.
	 */
	if ( CardsCo_Programme::needs_referral( $programme ) ) :
		$referral = CardsCo_Programme::referral( $programme );
		?>
		<aside class="cc-rev-pay is-referral">
			<?php require CARDSCO_PATH . 'includes/front/partial-referral.php'; ?>
		</aside>
	<?php else : ?>
	<aside class="cc-rev-pay">
		<p class="cc-rev-pay-head"><?php esc_html_e( 'What you’ll pay', 'cardsco' ); ?></p>

		<dl class="cc-rev-lines">
			<?php foreach ( $review['lines'] as $cc_line ) : ?>
				<div class="cc-rev-line">
					<dt><?php echo esc_html( $cc_line['label'] ); ?></dt>
					<dd><?php echo esc_html( $cc_line['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>

		<div class="cc-rev-due">
			<span><?php echo esc_html( $review['due']['label'] ); ?></span>
			<span><?php echo esc_html( $review['due']['value'] ); ?></span>
		</div>

		<p class="cc-rev-note">
			<span class="cc-rev-note-icon" aria-hidden="true">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'info', 'cc-rev-note-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</span>
			<span><?php echo esc_html( $review['note'] ); ?></span>
		</p>
	</aside>
	<?php endif; ?>
</div>
