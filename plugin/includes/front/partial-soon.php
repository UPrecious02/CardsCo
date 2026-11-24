<?php
/**
 * The "not open yet" dialog.
 *
 * An occasion marked coming soon cannot be set up, and saying so on the
 * tile alone leaves somebody clicking a thing that does nothing. This is
 * what the click opens: one dialog per screen, filled in with whichever
 * occasion was pressed.
 *
 * Included by any screen that lists occasions. The markup is divs rather
 * than a <dialog> element so that a theme's own dialog styling cannot
 * take it over, and so it behaves the same everywhere.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/* Its styles live beside the card screens, which not every caller loads. */
CardsCo_Catalogue::enqueue_catalogue();

/* No dependency: the portal's registry is not on a public page. */
wp_enqueue_script( 'cardsco-soon', CARDSCO_URL . 'assets/soon.js', array(), CARDSCO_VERSION, true );
?>

<div class="cc-soon" data-cc-soon-box hidden>
	<div class="cc-soon-sheet" role="dialog" aria-modal="true" aria-labelledby="ccSoonTitle">
		<h2 class="cc-soon-title" id="ccSoonTitle" data-cc-soon-title>
			<?php esc_html_e( 'Not open yet', 'cardsco' ); ?>
		</h2>

		<p class="cc-soon-blurb">
			<?php esc_html_e( 'The cards are drawn and ready. Setting this one up to run on its own is not switched on yet — we are finishing it.', 'cardsco' ); ?>
		</p>

		<p class="cc-soon-blurb">
			<?php esc_html_e( 'You can still send one today as a single card from the catalogue.', 'cardsco' ); ?>
		</p>

		<div class="cc-soon-feet">
			<button type="button" class="cc-btn cc-btn-ghost cc-btn-r8" data-cc-soon-close>
				<span><?php esc_html_e( 'Close', 'cardsco' ); ?></span>
			</button>

			<?php
			$cc_soon_cards = CardsCo_Shortcodes::page_for( 'cardsco_catalogue' );

			if ( $cc_soon_cards ) :
				?>
				<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( $cc_soon_cards ); ?>">
					<span><?php esc_html_e( 'See the cards', 'cardsco' ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>
