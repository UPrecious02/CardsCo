<?php
/**
 * Campaign step 6 — when it arrives.
 *
 * The lock date is shown because it is the thing that actually constrains
 * the choice: cards are written by hand, so the list has to be settled
 * some days before they land. Somebody choosing a date that is already
 * inside that window should find out here rather than at the review.
 *
 * @package CardsCo
 *
 * @var array  $campaign
 * @var string $earliest
 * @var array  $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_builder', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="6" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

	<div class="cc-bd-field">
		<label for="cc-bd-date"><?php esc_html_e( 'Arrive on', 'cardsco' ); ?></label>
		<input type="date" id="cc-bd-date" name="send_date" min="<?php echo esc_attr( $earliest ); ?>"
			value="<?php echo esc_attr( $campaign['send_date'] && '0000-00-00' !== $campaign['send_date'] ? $campaign['send_date'] : '' ); ?>" required />
		<p class="cc-bd-hint">
			<?php
			printf(
				/* translators: %s: the earliest date that can be chosen. */
				esc_html__( 'The soonest we can manage is %s. Every card is written by hand, so the list has to be settled a few days before they land.', 'cardsco' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $earliest ) ) )
			);
			?>
		</p>
	</div>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
