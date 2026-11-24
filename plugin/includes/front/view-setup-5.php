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

	<?php
	/*
	 * The other half of the question.
	 *
	 * Choosing individual addresses used to switch the company panel off
	 * and put nothing in its place, so the step asked where each card
	 * should go and then gave no way to say. Everyone on the occasion is
	 * listed here with whatever the list already holds — an upload that
	 * carried addresses fills these in and nobody retypes anything — and
	 * the rest are empty boxes with the name already against them.
	 *
	 * Inside the step's own form, so Continue saves these along with
	 * everything else and there is no separate thing to remember to press.
	 */
	$cc_missing = 0;

	foreach ( $shipping['people'] as $cc_one ) {
		if ( ! $cc_one['ready'] ) {
			$cc_missing++;
		}
	}
	?>
	<section class="cc-each<?php echo 'individual' === $shipping['mode'] ? '' : ' is-off'; ?>">
		<header class="cc-each-head">
			<span class="cc-each-title"><?php esc_html_e( 'Where each card goes', 'cardsco' ); ?></span>
			<span class="cc-each-count">
				<?php
				if ( ! $shipping['people'] ) {
					esc_html_e( 'Nobody on this occasion yet', 'cardsco' );
				} elseif ( $cc_missing ) {
					printf(
						/* translators: 1: how many are missing an address, 2: how many people in total. */
						esc_html( _n( '%1$s of %2$s still needs an address', '%1$s of %2$s still need an address', $cc_missing, 'cardsco' ) ),
						esc_html( number_format_i18n( $cc_missing ) ),
						esc_html( number_format_i18n( count( $shipping['people'] ) ) )
					);
				} else {
					esc_html_e( 'Everyone has an address', 'cardsco' );
				}
				?>
			</span>
		</header>

		<?php if ( $shipping['people'] ) : ?>
			<ul class="cc-each-list">
				<?php foreach ( $shipping['people'] as $cc_one ) : ?>
					<li class="cc-each-one<?php echo $cc_one['ready'] ? '' : ' is-missing'; ?>">
						<p class="cc-each-who">
							<span class="cc-each-mark" aria-hidden="true"><?php echo esc_html( $cc_one['initials'] ); ?></span>
							<span class="cc-each-name"><?php echo esc_html( $cc_one['name'] ); ?></span>
							<?php if ( ! $cc_one['ready'] ) : ?>
								<span class="cc-each-flag"><?php esc_html_e( 'Needs an address', 'cardsco' ); ?></span>
							<?php endif; ?>
						</p>

						<div class="cc-each-fields">
							<p class="cc-field">
								<label for="cc-each-a-<?php echo esc_attr( $cc_one['id'] ); ?>"><?php esc_html_e( 'Address', 'cardsco' ); ?></label>
								<textarea id="cc-each-a-<?php echo esc_attr( $cc_one['id'] ); ?>"
									name="each_address[<?php echo esc_attr( $cc_one['id'] ); ?>]"
									rows="2"
									placeholder="<?php esc_attr_e( 'House and street, then town', 'cardsco' ); ?>"><?php echo esc_textarea( $cc_one['address'] ); ?></textarea>
							</p>
							<p class="cc-field cc-each-pc">
								<label for="cc-each-p-<?php echo esc_attr( $cc_one['id'] ); ?>"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
								<input type="text" id="cc-each-p-<?php echo esc_attr( $cc_one['id'] ); ?>"
									name="each_postcode[<?php echo esc_attr( $cc_one['id'] ); ?>]"
									value="<?php echo esc_attr( $cc_one['postcode'] ); ?>" />
							</p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</form>

<?php
/*
 * Addresses by spreadsheet, for a list too long to type. Its own form,
 * because a file needs one — and the same importer as step 2, so a file
 * with names and addresses on it matches the people already here rather
 * than making second copies of them.
 */
?>
<section class="cc-each-up<?php echo 'individual' === $shipping['mode'] ? '' : ' is-off'; ?>">
	<p class="cc-each-up-head"><?php esc_html_e( 'Or upload the addresses', 'cardsco' ); ?></p>
	<p class="cc-each-up-sub">
		<?php esc_html_e( 'A CSV with first name, last name, address and postcode. Names that are already here are matched, not added again.', 'cardsco' ); ?>
	</p>
	<?php
	$upload = CardsCo_Dashboard::upload_row( wp_list_pluck( $shipping['people'], 'id' ) );
	require CARDSCO_PATH . 'includes/front/partial-upload.php';
	?>
</section>
