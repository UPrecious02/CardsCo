<?php
/**
 * Step 5 — where the cards get shipped.
 *
 * Measured from the step-5 frame: two 468-wide mode cards at y=213 with a
 * 16 gutter, then a 952-wide panel at y=312 holding three full-width 910
 * inputs on a 74 pitch and a row of three 295-wide ones.
 *
 * Note on what is not here: the frame draws no postcode field. The model has
 * somewhere to put one, so adding it is a one-line change — but it is not in
 * the design, so it is not invented here.
 *
 * @package CardsCo
 *
 * @var array $shipping  From CardsCo_Dashboard::setup_shipping().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_labels = array(
	__( 'Address line 1', 'cardsco' ),
	__( 'Address line 2', 'cardsco' ),
	__( 'Address line 3', 'cardsco' ),
);
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="5" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<div class="cc-ship-modes">
		<?php foreach ( $shipping['modes'] as $cc_mode ) : ?>
			<label class="cc-ship-mode<?php echo $cc_mode['on'] ? ' is-on' : ''; ?>">
				<input type="radio" name="shipping_mode" value="<?php echo esc_attr( $cc_mode['key'] ); ?>" <?php checked( $cc_mode['on'] ); ?> />
				<span class="cc-ship-mode-icon" aria-hidden="true">
					<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), $cc_mode['icon'], 'cc-ship-mode-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</span>
				<span class="cc-ship-mode-text">
					<span class="cc-ship-mode-head"><?php echo esc_html( $cc_mode['head'] ); ?></span>
					<span class="cc-ship-mode-sub"><?php echo esc_html( $cc_mode['sub'] ); ?></span>
				</span>
				<span class="cc-ship-mode-dot" aria-hidden="true"></span>
			</label>
		<?php endforeach; ?>
	</div>

	<section class="cc-ship-panel<?php echo 'individual' === $shipping['mode'] ? ' is-off' : ''; ?>">
		<header class="cc-ship-head">
			<span class="cc-ship-pin" aria-hidden="true"></span>
			<span>
				<span class="cc-ship-title"><?php esc_html_e( 'Shipping address', 'cardsco' ); ?></span>
				<span class="cc-ship-source"><?php echo esc_html( $shipping['source'] ); ?></span>
			</span>
		</header>

		<?php foreach ( $cc_labels as $cc_i => $cc_label ) : ?>
			<p class="cc-field">
				<label for="cc-ship-<?php echo esc_attr( $cc_i ); ?>"><?php echo esc_html( $cc_label ); ?></label>
				<input type="text"
					id="cc-ship-<?php echo esc_attr( $cc_i ); ?>"
					name="ship_line[]"
					value="<?php echo esc_attr( $shipping['lines'][ $cc_i ] ); ?>"
					<?php echo 2 === $cc_i ? 'placeholder="' . esc_attr__( 'Area or estate', 'cardsco' ) . '"' : ''; ?> />
			</p>
		<?php endforeach; ?>

		<div class="cc-ship-row">
			<p class="cc-field">
				<label for="cc-ship-postcode"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
				<input type="text" id="cc-ship-postcode" name="ship_postcode" value="<?php echo esc_attr( $shipping['postcode'] ); ?>" />
			</p>
			<p class="cc-field">
				<label for="cc-ship-town"><?php esc_html_e( 'Town/city', 'cardsco' ); ?></label>
				<input type="text" id="cc-ship-town" name="ship_line[]" value="<?php echo esc_attr( $shipping['lines'][3] ); ?>" />
			</p>
			<p class="cc-field">
				<label for="cc-ship-county"><?php esc_html_e( 'County', 'cardsco' ); ?></label>
				<input type="text" id="cc-ship-county" name="ship_line[]" value="<?php echo esc_attr( $shipping['lines'][4] ); ?>" />
			</p>
			<p class="cc-field">
				<label for="cc-ship-country"><?php esc_html_e( 'Country', 'cardsco' ); ?></label>
				<span class="cc-select">
					<select id="cc-ship-country" name="ship_country">
						<?php foreach ( CardsCo_Validator::countries() as $cc_code => $cc_name ) : ?>
							<option value="<?php echo esc_attr( $cc_code ); ?>" <?php selected( $shipping['country'], $cc_code ); ?>>
								<?php echo esc_html( $cc_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'chevron-down', 'cc-select-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</span>
			</p>
		</div>
	</section>
</form>
