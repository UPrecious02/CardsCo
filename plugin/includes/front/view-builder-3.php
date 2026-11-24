<?php
/**
 * Campaign step 3 — the card.
 *
 * Only cards for this campaign's occasion, so a Christmas campaign is not
 * offered a birthday card.
 *
 * @package CardsCo
 *
 * @var array $campaign
 * @var array $cards
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_builder', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="3" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

	<?php if ( ! $cards ) : ?>
		<p class="cc-bd-none"><?php esc_html_e( 'There are no cards for this occasion yet. Go back a step and choose another, or tell us.', 'cardsco' ); ?></p>
	<?php else : ?>
		<?php
		/*
		 * Collections as pills. The list is every card for this occasion,
		 * which is a scroll today and will be a long one once the
		 * catalogue fills up. The pills filter what is already on the page,
		 * so there is no request and the whole list is there before any
		 * script runs — and the pills are only drawn when there is more
		 * than one collection to choose between.
		 */
		$cc_groups = array();

		foreach ( $cards as $cc_c ) {
			$cc_slug = CardsCo_Card::category( $cc_c->ID );
			$cc_def  = $cc_slug ? CardsCo_Category::get( $cc_slug ) : null;

			if ( $cc_def ) {
				$cc_groups[ $cc_slug ] = (string) $cc_def['label'];
			}
		}

		asort( $cc_groups );
		?>

		<?php if ( count( $cc_groups ) > 1 ) : ?>
			<div class="cc-bd-pills" data-cc-pills>
				<button type="button" class="cc-bd-pill is-on" data-cc-pill="">
					<?php esc_html_e( 'All', 'cardsco' ); ?>
				</button>
				<?php foreach ( $cc_groups as $cc_slug => $cc_label ) : ?>
					<button type="button" class="cc-bd-pill" data-cc-pill="<?php echo esc_attr( $cc_slug ); ?>">
						<?php echo esc_html( $cc_label ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="cc-bd-cards">
			<?php foreach ( $cards as $cc_card ) : ?>
				<?php
				/* for_occasion() hands back WP_Post objects, not rows. */
				$cc_card_id = (int) $cc_card->ID;
				$cc_img     = get_the_post_thumbnail_url( $cc_card_id, 'medium' );
				$cc_on      = (int) $campaign['card_id'] === $cc_card_id;
				$cc_in      = CardsCo_Card::category( $cc_card_id );
				?>
				<label class="cc-bd-card<?php echo $cc_on ? ' is-on' : ''; ?>" data-cc-in="<?php echo esc_attr( $cc_in ); ?>">
					<input type="radio" name="card_id" value="<?php echo esc_attr( $cc_card_id ); ?>" <?php checked( $cc_on ); ?> required />
					<?php if ( $cc_img ) : ?>
						<img src="<?php echo esc_url( $cc_img ); ?>" alt="" loading="lazy" />
					<?php else : ?>
						<span class="cc-bd-card-blank" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="cc-bd-card-name"><?php echo esc_html( $cc_card->post_title ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<p class="cc-bd-none" data-cc-pills-none hidden>
			<?php esc_html_e( 'No cards in that collection for this occasion. Choose All to see them again.', 'cardsco' ); ?>
		</p>
	<?php endif; ?>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
