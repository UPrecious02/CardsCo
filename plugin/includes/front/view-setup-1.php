<?php
/**
 * Step 1 — Which occasion?
 *
 * Frame 253:56833: a three-across grid of 305-wide category cards with a
 * 284x132 art well, and a note underneath once something is chosen. The
 * selected card is filled #EEF5F1 with a #0F5A43 border; a coming-soon one
 * cannot be chosen at all.
 *
 * @package CardsCo
 *
 * @var array[] $choices
 * @var array   $programme
 * @var array   $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="start" />

	<div class="cc-wz-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Occasion', 'cardsco' ); ?>">
		<?php foreach ( $choices as $cc_pick ) : ?>
			<?php $cc_id = 'cc-occ-' . $cc_pick['slug']; ?>
			<label class="cc-pick<?php echo $cc_pick['coming_soon'] ? ' is-soon' : ''; ?><?php echo $cc_pick['chosen'] ? ' is-chosen' : ''; ?>"
				for="<?php echo esc_attr( $cc_id ); ?>">
				<input type="radio" id="<?php echo esc_attr( $cc_id ); ?>" name="category"
					value="<?php echo esc_attr( $cc_pick['slug'] ); ?>"
					<?php checked( $cc_pick['chosen'] ); ?>
					<?php disabled( $cc_pick['coming_soon'] ); ?> />

				<span class="cc-pick-art">
					<?php if ( $cc_pick['art']['image'] ) : ?>
						<img src="<?php echo esc_url( $cc_pick['art']['image'] ); ?>" alt="" />
					<?php else : ?>
						<span class="cc-pick-art-blank" aria-hidden="true"></span>
					<?php endif; ?>
				</span>

				<span class="cc-pick-head">
					<span class="cc-pick-name"><?php echo esc_html( $cc_pick['label'] ); ?></span>
					<?php if ( $cc_pick['coming_soon'] ) : ?>
						<span class="cc-pill is-neutral"><?php esc_html_e( 'Coming soon', 'cardsco' ); ?></span>
					<?php endif; ?>
				</span>

				<span class="cc-pick-blurb"><?php echo esc_html( $cc_pick['blurb'] ); ?></span>
				<span class="cc-pick-stat<?php echo $cc_pick['coming_soon'] ? ' is-quiet' : ''; ?>">
					<?php echo esc_html( $cc_pick['stat'] ); ?>
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<?php if ( $note ) : ?>
		<p class="cc-wz-note"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>
</form>
