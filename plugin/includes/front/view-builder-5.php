<?php
/**
 * Campaign step 5 — the offer.
 *
 * Optional. The warning underneath is the one case where switching this
 * off breaks something already written: a message using {offer code} or
 * {offer expires} would go out with a gap, so the model holds the card
 * back rather than send it blank.
 *
 * @package CardsCo
 *
 * @var array  $campaign
 * @var string $warning
 * @var array  $links
 */

defined( 'ABSPATH' ) || exit;

$cc_on = ! empty( $campaign['offer_enabled'] );
?>
<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_builder', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="5" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

	<label class="cc-bd-check cc-bd-toggle<?php echo $cc_on ? ' is-on' : ''; ?>">
		<input type="checkbox" name="offer_enabled" value="1" <?php checked( $cc_on ); ?> data-cc-toggle="ccOffer" />
		<span class="cc-bd-check-name"><?php esc_html_e( 'Include an offer code', 'cardsco' ); ?></span>
	</label>

	<?php if ( $warning ) : ?>
		<p class="cc-bd-warn" role="alert"><?php echo esc_html( $warning ); ?></p>
	<?php endif; ?>

	<div class="cc-bd-offer<?php echo $cc_on ? ' is-on' : ''; ?>" id="ccOffer">
		<div class="cc-bd-field">
			<label for="cc-bd-code"><?php esc_html_e( 'Code', 'cardsco' ); ?></label>
			<input type="text" id="cc-bd-code" name="offer_code" value="<?php echo esc_attr( $campaign['offer_code'] ); ?>"
				placeholder="<?php esc_attr_e( 'SPRING20', 'cardsco' ); ?>" />
		</div>

		<div class="cc-bd-field">
			<label for="cc-bd-desc"><?php esc_html_e( 'What it gives them', 'cardsco' ); ?></label>
			<input type="text" id="cc-bd-desc" name="offer_description" value="<?php echo esc_attr( $campaign['offer_description'] ); ?>"
				placeholder="<?php esc_attr_e( '20% off your next order', 'cardsco' ); ?>" />
		</div>

		<div class="cc-bd-field">
			<label for="cc-bd-exp"><?php esc_html_e( 'Runs out on', 'cardsco' ); ?></label>
			<input type="date" id="cc-bd-exp" name="offer_expires"
				value="<?php echo esc_attr( $campaign['offer_expires'] && '0000-00-00' !== $campaign['offer_expires'] ? $campaign['offer_expires'] : '' ); ?>" />
		</div>
	</div>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
