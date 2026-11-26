<?php
/**
 * Campaign step 1 — what the card is for.
 *
 * The name is the company's own handle for this send, shown on the list
 * and on the order. The occasion decides which cards and which wording
 * are offered later, so it is asked for first.
 *
 * @package CardsCo
 *
 * @var array $campaign
 * @var array $types
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_builder', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="1" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

	<div class="cc-bd-field">
		<label for="cc-bd-name"><?php esc_html_e( 'Name this campaign', 'cardsco' ); ?></label>
		<input type="text" id="cc-bd-name" name="name" value="<?php echo esc_attr( $campaign['name'] ); ?>"
			placeholder="<?php esc_attr_e( 'Spring thank-you', 'cardsco' ); ?>" required />
		<p class="cc-bd-hint"><?php esc_html_e( 'Only you see this. It is how the campaign is listed.', 'cardsco' ); ?></p>
	</div>

	<fieldset class="cc-bd-field">
		<legend><?php esc_html_e( 'What is it for?', 'cardsco' ); ?></legend>

		<?php if ( ! $types ) : ?>
			<p class="cc-bd-none"><?php esc_html_e( 'There are no occasions set up yet, so there is nothing to choose from.', 'cardsco' ); ?></p>
		<?php else : ?>
			<?php
			/*
			 * A filter rather than a scroll. The list is every occasion the
			 * company has, which is long enough that finding one by eye is
			 * the slowest part of the step. It filters what is already on
			 * the page, so it needs no request and the full list is there
			 * before any script runs.
			 */
			?>
			<?php if ( count( $types ) > 8 ) : ?>
				<input type="search" class="cc-bd-filter" data-cc-filter
					placeholder="<?php esc_attr_e( 'Search occasions…', 'cardsco' ); ?>"
					aria-label="<?php esc_attr_e( 'Search occasions', 'cardsco' ); ?>" />
			<?php endif; ?>

			<div class="cc-bd-radios">
				<?php foreach ( $types as $cc_type ) : ?>
					<?php $cc_on = (int) $campaign['occasion_type_id'] === (int) $cc_type['id']; ?>
					<label class="cc-bd-radio<?php echo $cc_on ? ' is-on' : ''; ?>">
						<input type="radio" name="occasion_type_id" value="<?php echo esc_attr( (int) $cc_type['id'] ); ?>"
							<?php checked( $cc_on ); ?> required />
						<span class="cc-bd-radio-name"><?php echo esc_html( $cc_type['label'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<p class="cc-bd-none" data-cc-filter-none hidden>
				<?php esc_html_e( 'Nothing matches that. Clear the search to see them all.', 'cardsco' ); ?>
			</p>
		<?php endif; ?>
	</fieldset>

	<?php
	/*
	 * Single cards or a bulk order, asked at the start because everything
	 * after it depends on the answer: what a card costs, how many designs
	 * and messages the order can carry, and whether anybody's own address
	 * is needed at all. The same choice, in the same words and from the
	 * same function, as the first step of an occasion.
	 */
	$cc_order = CardsCo_Dashboard::setup_order( $campaign );
	$cc_bulk  = CardsCo_Campaign::one_address( $campaign );
	$cc_co    = CardsCo_Company::get( (int) $campaign['company_id'] );
	$cc_block = (string) ( $campaign['ship_address'] ?? '' );

	/* Nothing typed yet: start from the address on the company's profile. */
	if ( '' === trim( $cc_block ) && $cc_co && ! empty( $cc_co['billing_address'] ) ) {
		$cc_block = (string) $cc_co['billing_address'];
	}
	?>
	<fieldset class="cc-kind cc-bd-kind">
		<legend class="cc-kind-legend"><?php esc_html_e( 'Single cards or a bulk order?', 'cardsco' ); ?></legend>

		<div class="cc-kind-list">
			<?php foreach ( $cc_order['options'] as $cc_opt ) : ?>
				<?php $cc_on = ( 'bulk' === $cc_opt['value'] ) === $cc_bulk; ?>
				<label class="cc-kind-one<?php echo $cc_on ? ' is-on' : ''; ?>">
					<input type="radio" name="order_type" value="<?php echo esc_attr( $cc_opt['value'] ); ?>"
						data-cc-kind <?php checked( $cc_on ); ?> />
					<span class="cc-kind-dot" aria-hidden="true"></span>
					<span class="cc-kind-text">
						<span class="cc-kind-head"><?php echo esc_html( $cc_opt['head'] ); ?></span>
						<span class="cc-kind-body"><?php echo esc_html( $cc_opt['body'] ); ?></span>
						<span class="cc-kind-small"><?php echo esc_html( $cc_opt['small'] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>

	<?php
	/*
	 * Where a bulk order is delivered. On the page either way, so the step
	 * works without script; shown only with "Bulk order" chosen. The
	 * fields are the same seven every other address in the portal has.
	 */
	?>
	<fieldset class="cc-bd-field cc-bd-ship<?php echo $cc_bulk ? ' is-on' : ''; ?>" data-cc-kind-ship>
		<legend><?php esc_html_e( 'Where should the cards be delivered?', 'cardsco' ); ?></legend>
		<p class="cc-bd-hint"><?php esc_html_e( 'Every card on a bulk order arrives together at this one address, for you to hand out.', 'cardsco' ); ?></p>

		<?php
		$address = array(
			'id'       => 'cc-bd-ship',
			'name'     => 'ship_line',
			'pc_name'  => 'ship_postcode',
			'cy_name'  => 'ship_country',
			'lines'    => CardsCo_Dashboard::address_lines( $cc_block ),
			'postcode' => (string) ( $campaign['ship_postcode'] ?? '' ),
			'country'  => (string) ( $campaign['ship_country'] ?? '' ) ? (string) $campaign['ship_country'] : 'GB',
		);
		require CARDSCO_PATH . 'includes/front/partial-address.php';
		?>
	</fieldset>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
