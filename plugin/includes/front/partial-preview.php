<?php
/**
 * The card preview panel, shared by the catalogue and the set-up wizard.
 *
 * One dialog per page, filled from the JSON block below rather than one
 * hidden copy per tile. <dialog> handles the backdrop, focus trapping,
 * Escape and returning focus afterwards.
 *
 * Two modes, because the same panel answers two different questions:
 *
 *   shop  the catalogue. The primary action puts the card in the basket.
 *   pick  step 3 of the wizard. The person is choosing a card for an
 *         occasion they are already setting up, so the primary action
 *         selects it and closes; sending them to a shop page mid-wizard
 *         would be wrong.
 *
 * @package CardsCo
 *
 * @var array  $cc_pv      Card data keyed by card id.
 * @var string $cc_pv_mode 'shop' or 'pick'.
 */

defined( 'ABSPATH' ) || exit;

$cc_pv_mode = isset( $cc_pv_mode ) && 'pick' === $cc_pv_mode ? 'pick' : 'shop';
?>
<script type="application/json" id="cc-pv-data"><?php echo wp_json_encode( $cc_pv ); ?></script>

<dialog class="cc-pv" id="cc-pv" data-cc-pv-mode="<?php echo esc_attr( $cc_pv_mode ); ?>" aria-labelledby="cc-pv-title">
	<div class="cc-pv-inner">
		<button type="button" class="cc-pv-close" data-cc-pv-close
			aria-label="<?php esc_attr_e( 'Close preview', 'cardsco' ); ?>">&times;</button>

		<div class="cc-pv-art">
			<img src="" alt="" id="cc-pv-img" />
		</div>

		<div class="cc-pv-body">
			<p class="cc-pv-collection" id="cc-pv-collection"></p>
			<h2 class="cc-pv-title" id="cc-pv-title"></h2>
			<p class="cc-pv-pattern" id="cc-pv-pattern"></p>

			<dl class="cc-pv-facts">
				<div class="cc-pv-fact" id="cc-pv-fact-front">
					<dt><?php esc_html_e( 'Words on the front', 'cardsco' ); ?></dt>
					<dd id="cc-pv-front"></dd>
				</div>
				<div class="cc-pv-fact" id="cc-pv-fact-format">
					<dt><?php esc_html_e( 'Size', 'cardsco' ); ?></dt>
					<dd id="cc-pv-format"></dd>
				</div>
				<div class="cc-pv-fact" id="cc-pv-fact-occasions">
					<dt><?php esc_html_e( 'Suits', 'cardsco' ); ?></dt>
					<dd id="cc-pv-occasions"></dd>
				</div>
			</dl>

			<p class="cc-pv-note"><?php esc_html_e( 'Inside is left blank. Your message is written by hand in real ink.', 'cardsco' ); ?></p>

			<div class="cc-pv-actions">
				<?php if ( 'pick' === $cc_pv_mode ) : ?>
					<?php
					/*
					 * In the wizard the card is being chosen for an occasion
					 * already under way, so this selects it and closes. A
					 * link to the shop would drop somebody out of a flow
					 * they are halfway through.
					 */
					?>
					<button type="button" class="cc-pv-use" id="cc-pv-pick"><?php esc_html_e( 'Choose this card', 'cardsco' ); ?></button>
				<?php else : ?>
					<?php
					/*
					 * No href and hidden until a card is chosen: preview.js
					 * points it at the card being shown, and hides it for a
					 * card with nowhere to go. Starting it as href="" meant
					 * the template shipped a link to the current page, which
					 * is what an empty href is.
					 *
					 * There was a second link here, "See full details". A
					 * card has no page of its own for it to open, so it went
					 * wherever the card's address happened to point — the
					 * send-a-card page — and it is gone.
					 */
					?>
					<a class="cc-pv-use" id="cc-pv-use" hidden><?php esc_html_e( 'Use this card', 'cardsco' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<nav class="cc-pv-nav" aria-label="<?php esc_attr_e( 'Move between cards', 'cardsco' ); ?>">
			<button type="button" class="cc-pv-arrow" data-cc-pv-step="-1"
				aria-label="<?php esc_attr_e( 'Previous card', 'cardsco' ); ?>">&#8249;</button>
			<span class="cc-pv-count" id="cc-pv-count"></span>
			<button type="button" class="cc-pv-arrow" data-cc-pv-step="1"
				aria-label="<?php esc_attr_e( 'Next card', 'cardsco' ); ?>">&#8250;</button>
		</nav>
	</div>
</dialog>
