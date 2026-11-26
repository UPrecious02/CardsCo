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

	<?php
	/*
	 * A bulk order can mix designs: up to five, or ten on a large order,
	 * with a number against each. Everything else takes one, and gets the
	 * picker it always had.
	 */
	$cc_limit   = CardsCo_Campaign::design_limit( $campaign );
	$cc_multi   = $cc_limit > 1;
	$cc_total   = (int) $campaign['recipient_count'];
	$cc_picked  = array();

	foreach ( CardsCo_Campaign::designs( $campaign ) as $cc_d ) {
		$cc_picked[ (int) $cc_d['card'] ] = (int) $cc_d['qty'];
	}

	$cc_wrong = CardsCo_Campaign::mix_problems( $campaign, 'designs' );
	?>

	<?php if ( $cc_wrong && $cc_picked ) : ?>
		<div class="cc-bd-warn" role="alert">
			<?php foreach ( $cc_wrong as $cc_w ) : ?>
				<p><?php echo esc_html( $cc_w ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $cc_multi && $cards ) : ?>
		<input type="hidden" name="cc_designs" value="1" />
		<p class="cc-bd-mix-lead">
			<?php
			printf(
				/* translators: 1: how many designs may be chosen, 2: how many cards the order is for. */
				esc_html__( 'Choose up to %1$d designs for your %2$d cards. Say how many of each, or leave the numbers empty and we split the cards evenly.', 'cardsco' ),
				(int) $cc_limit,
				(int) $cc_total
			);
			?>
		</p>
	<?php endif; ?>

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

		<?php /* Above the tiles, not under them: the list is long and the count has to be in sight while choosing. */ ?>
		<?php if ( $cc_multi ) : ?>
			<p class="cc-bd-mix-status" data-cc-bd-mix-status aria-live="polite"
				data-cc-none="<?php esc_attr_e( 'No design chosen yet.', 'cardsco' ); ?>"
				data-cc-even="<?php esc_attr_e( '%1$d of %2$d designs chosen. We will split the cards evenly.', 'cardsco' ); ?>"
				data-cc-sum="<?php esc_attr_e( '%1$d of %2$d designs chosen. Your numbers add up to %3$d of %4$d cards.', 'cardsco' ); ?>"
				data-cc-part="<?php esc_attr_e( 'Give a number for every design you have chosen, or clear them all.', 'cardsco' ); ?>"
				data-cc-full="<?php esc_attr_e( 'That is the most designs this order takes. Untick one to choose another.', 'cardsco' ); ?>"></p>
		<?php endif; ?>

		<div class="cc-bd-cards<?php echo $cc_multi ? ' is-multi' : ''; ?>"
			<?php if ( $cc_multi ) : ?>
				data-cc-bd-mix data-cc-limit="<?php echo esc_attr( (int) $cc_limit ); ?>" data-cc-total="<?php echo esc_attr( (int) $cc_total ); ?>"
			<?php endif; ?>>
			<?php foreach ( $cards as $cc_card ) : ?>
				<?php
				/* for_occasion() hands back WP_Post objects, not rows. */
				$cc_card_id = (int) $cc_card->ID;
				$cc_img     = get_the_post_thumbnail_url( $cc_card_id, 'medium' );
				$cc_on      = $cc_multi ? isset( $cc_picked[ $cc_card_id ] ) : (int) $campaign['card_id'] === $cc_card_id;
				$cc_in      = CardsCo_Card::category( $cc_card_id );
				$cc_qty     = (int) ( $cc_picked[ $cc_card_id ] ?? 0 );
				?>
				<?php if ( $cc_multi ) : ?>
					<?php
					/*
					 * A tile and, under it, how many. A div rather than a
					 * label around the lot: a number box inside the label
					 * would untick the card every time it was clicked into.
					 */
					?>
					<div class="cc-bd-pick<?php echo $cc_on ? ' is-on' : ''; ?>" data-cc-in="<?php echo esc_attr( $cc_in ); ?>">
						<label class="cc-bd-card<?php echo $cc_on ? ' is-on' : ''; ?>">
							<input type="checkbox" name="card_ids[]" value="<?php echo esc_attr( $cc_card_id ); ?>" <?php checked( $cc_on ); ?> />
							<?php if ( $cc_img ) : ?>
								<img src="<?php echo esc_url( $cc_img ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="cc-bd-card-blank" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="cc-bd-card-name"><?php echo esc_html( $cc_card->post_title ); ?></span>
						</label>
						<span class="cc-bd-qty">
							<label for="cc-bd-qty-<?php echo esc_attr( $cc_card_id ); ?>"><?php esc_html_e( 'How many', 'cardsco' ); ?></label>
							<input type="number" id="cc-bd-qty-<?php echo esc_attr( $cc_card_id ); ?>"
								name="qty[<?php echo esc_attr( $cc_card_id ); ?>]" min="1" step="1" inputmode="numeric"
								value="<?php echo $cc_qty ? esc_attr( $cc_qty ) : ''; ?>"
								<?php disabled( ! $cc_on ); ?> />
						</span>
					</div>
				<?php else : ?>
					<label class="cc-bd-card<?php echo $cc_on ? ' is-on' : ''; ?>" data-cc-in="<?php echo esc_attr( $cc_in ); ?>">
						<input type="radio" name="card_id" value="<?php echo esc_attr( $cc_card_id ); ?>" <?php checked( $cc_on ); ?> required />
						<?php if ( $cc_img ) : ?>
							<img src="<?php echo esc_url( $cc_img ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="cc-bd-card-blank" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="cc-bd-card-name"><?php echo esc_html( $cc_card->post_title ); ?></span>
					</label>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<p class="cc-bd-none" data-cc-pills-none hidden>
			<?php esc_html_e( 'No cards in that collection for this occasion. Choose All to see them again.', 'cardsco' ); ?>
		</p>
	<?php endif; ?>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
