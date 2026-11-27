<?php
/**
 * Step 3 — Choose a card.
 *
 * Measured from the step-3 frame on the Occasions setup board. A pill pair
 * for the two sources at y=213, then a row of five tiles from x=64 on a
 * 192 pitch: 176 wide, 269 tall, the artwork filling 154x200 inside, then
 * the card's name and what it is underneath.
 *
 * The tile is a label wrapping a radio rather than a link, so the choice
 * survives without JavaScript and the whole tile is the hit area. The eye
 * on each tile is a separate link to the card on its own, which is why it
 * sits outside the label.
 *
 * @package CardsCo
 *
 * SEVERAL DESIGNS
 *
 * A bulk order — 25 cards or more to one address — may carry more than one
 * design: up to five, and up to ten from fifty cards. Where it may, this is
 * a different control on the same tiles. They are tickboxes rather than
 * radios, each chosen one grows a box for how many cards it is to have,
 * and every card is on the page whichever tab is open so that a tick made
 * under one tab is not lost by looking at the other.
 *
 * @package CardsCo
 *
 * @var array $cards     Tabs, tiles and what is chosen.
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_here  = $links['here'];
$cc_multi = ! empty( $cards['multi'] );
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $cc_here ); ?>">
	<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="3" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<?php if ( $cc_multi ) : ?>
		<?php
		/*
		 * Says "this is the several-designs form" even when nothing is
		 * ticked. Tickboxes that are all empty send nothing at all, and
		 * without this an order with every design unticked would be saved
		 * as no change — and carry on with the designs it had.
		 */
		?>
		<input type="hidden" name="cc_designs" value="1" />

		<p class="cc-mix-lead">
			<?php
			printf(
				/* translators: 1: how many cards, 2: how many designs. */
				esc_html__( 'Your order of %1$s cards can have up to %2$s designs. Tick the ones you want — one is fine.', 'cardsco' ),
				esc_html( number_format_i18n( (int) $cards['quantity'] ) ),
				esc_html( number_format_i18n( (int) $cards['limit'] ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $cards['problems'] ) ) : ?>
		<div class="cc-mix-warn" role="alert">
			<?php foreach ( $cards['problems'] as $cc_problem ) : ?>
				<p><?php echo esc_html( $cc_problem ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( count( $cards['tabs'] ) > 1 ) : ?>
		<nav class="cc-src-tabs" aria-label="<?php esc_attr_e( 'Where these cards come from', 'cardsco' ); ?>"<?php echo $cc_multi ? ' data-cc-mix-tabs' : ''; ?>>
			<?php foreach ( $cards['tabs'] as $cc_tab ) : ?>
				<a class="cc-src-tab<?php echo $cc_tab['on'] ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'cards', $cc_tab['key'], $cc_here ) ); ?>"
					<?php echo $cc_multi ? 'data-cc-mix-tab="' . esc_attr( $cc_tab['key'] ) . '"' : ''; ?>
					<?php echo $cc_tab['on'] ? 'aria-current="true"' : ''; ?>>
					<?php
					echo esc_html(
						'mine' === $cc_tab['key']
							? $cc_tab['label']
							: sprintf(
								/* translators: 1: the occasion, 2: how many cards. */
								_x( '%1$s · %2$s', 'card source tab', 'cardsco' ),
								$cc_tab['label'],
								number_format_i18n( $cc_tab['n'] )
							)
					);
					?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php
	/*
	 * With several designs every tile is on the page and the open tab
	 * decides which are on show, so "nothing here" is a property of the
	 * tab rather than of the list. It is drawn either way and hidden
	 * unless it applies, so that switching tabs can reveal it.
	 */
	$cc_on_show = 0;

	foreach ( $cards['tiles'] as $cc_tile ) {
		if ( empty( $cc_tile['hidden'] ) ) {
			$cc_on_show++;
		}
	}
	?>

	<?php if ( ! $cc_on_show || $cc_multi ) : ?>

		<p class="cc-wz-empty"<?php echo $cc_on_show ? ' hidden' : ''; ?> data-cc-mix-empty>
			<?php
			if ( 'mine' === $cards['tab'] || ( $cc_multi && $cards['tiles'] ) ) {
				esc_html_e( 'You have no designs of your own yet. Talk to us about having some made and they will show up here.', 'cardsco' );
			} else {
				esc_html_e( 'No cards are set up for this occasion yet. Get in touch and we will sort it out.', 'cardsco' );
			}
			?>
		</p>

	<?php endif; ?>

	<?php if ( $cards['tiles'] ) : ?>

		<ul class="cc-card-picker<?php echo $cc_multi ? ' is-multi' : ''; ?>"
			<?php if ( $cc_multi ) : ?>
				data-cc-mix
				data-cc-limit="<?php echo esc_attr( (int) $cards['limit'] ); ?>"
				data-cc-cards="<?php echo esc_attr( (int) $cards['quantity'] ); ?>"
			<?php endif; ?>>
			<?php foreach ( $cards['tiles'] as $cc_tile ) : ?>
				<li class="cc-card-pick<?php echo $cc_tile['selected'] ? ' is-on' : ''; ?>"
					<?php echo $cc_multi ? 'data-cc-mine="' . ( ! empty( $cc_tile['mine'] ) ? '1' : '0' ) . '"' : ''; ?>
					<?php echo ! empty( $cc_tile['hidden'] ) ? 'hidden' : ''; ?>>
					<label class="cc-card-pick-label">
						<?php if ( $cc_multi ) : ?>
							<input class="cc-card-pick-input"
								type="checkbox"
								name="card_ids[]"
								value="<?php echo esc_attr( $cc_tile['id'] ); ?>"
								<?php checked( $cc_tile['selected'] ); ?> />
						<?php else : ?>
						<input class="cc-card-pick-input"
							type="radio"
							name="card_id"
							value="<?php echo esc_attr( $cc_tile['id'] ); ?>"
							<?php checked( $cc_tile['selected'] ); ?> />
						<?php endif; ?>

						<span class="cc-card-pick-art">
							<?php if ( $cc_tile['image'] ) : ?>
								<img src="<?php echo esc_url( $cc_tile['image'] ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="cc-card-pick-blank" aria-hidden="true"></span>
							<?php endif; ?>
						</span>

						<span class="cc-card-pick-meta">
							<span class="cc-card-pick-name"><?php echo esc_html( $cc_tile['title'] ); ?></span>
							<?php if ( $cc_tile['sub'] ) : ?>
								<span class="cc-card-pick-sub"><?php echo esc_html( $cc_tile['sub'] ); ?></span>
							<?php endif; ?>
						</span>

						<span class="cc-card-pick-mark" aria-hidden="true">
							<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-card-pick-tick' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						</span>
					</label>

					<?php if ( $cc_multi ) : ?>
						<?php
						/*
						 * How many cards of this design. Outside the label,
						 * or a click in the box would untick the tile. Only
						 * shown, and only sent, for a design that is ticked.
						 */
						?>
						<p class="cc-card-pick-qty">
							<label for="cc-qty-<?php echo esc_attr( (int) $cc_tile['id'] ); ?>"><?php esc_html_e( 'How many', 'cardsco' ); ?></label>
							<input type="number"
								id="cc-qty-<?php echo esc_attr( (int) $cc_tile['id'] ); ?>"
								name="qty[<?php echo esc_attr( (int) $cc_tile['id'] ); ?>]"
								min="1"
								max="<?php echo esc_attr( max( 1, (int) $cards['quantity'] ) ); ?>"
								step="1"
								inputmode="numeric"
								placeholder="<?php esc_attr_e( 'Even', 'cardsco' ); ?>"
								value="<?php echo esc_attr( $cc_tile['qty'] ); ?>" />
						</p>
					<?php endif; ?>

					<?php
					/*
					 * Opens the card bigger, here, without leaving the step.
					 *
					 * It used to be a link to get_permalink() on the card
					 * post, which is not publicly viewable — so WordPress
					 * handed back the site's home page and the eye opened
					 * the marketing site in a new tab.
					 */
					?>
					<button type="button" class="cc-card-pick-eye"
						data-cc-pv="<?php echo esc_attr( (int) $cc_tile['id'] ); ?>">
						<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'eye', 'cc-card-pick-eye-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<span class="cc-sr">
							<?php
							printf(
								/* translators: %s: the card's name. */
								esc_html__( 'Preview %s', 'cardsco' ),
								esc_html( $cc_tile['title'] )
							);
							?>
						</span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $cc_multi ) : ?>
			<p class="cc-mix-help">
				<?php
				printf(
					/* translators: %s: how many cards. */
					esc_html__( 'Say how many cards each design should have, or leave the boxes empty and we will split your %s cards evenly between them.', 'cardsco' ),
					esc_html( number_format_i18n( (int) $cards['quantity'] ) )
				);
				?>
			</p>

			<?php
			/*
			 * The running total. Empty until the script fills it in, and
			 * the wording lives here rather than in the script so it can
			 * be translated. Without the script the page still works: the
			 * numbers are checked when the step is saved.
			 */
			?>
			<p class="cc-mix-status" aria-live="polite" data-cc-mix-status
				data-cc-none="<?php esc_attr_e( 'No design ticked yet.', 'cardsco' ); ?>"
				data-cc-even="<?php esc_attr_e( '%1$s designs · %2$s cards split evenly.', 'cardsco' ); ?>"
				data-cc-one="<?php esc_attr_e( '1 design · all %2$s cards.', 'cardsco' ); ?>"
				data-cc-right="<?php esc_attr_e( '%1$s designs · %3$s of %2$s cards shared out. That adds up.', 'cardsco' ); ?>"
				data-cc-short="<?php esc_attr_e( '%3$s of %2$s cards shared out — %4$s still to place.', 'cardsco' ); ?>"
				data-cc-over="<?php esc_attr_e( '%3$s of %2$s cards shared out — %4$s too many.', 'cardsco' ); ?>"
				data-cc-part="<?php esc_attr_e( 'Give every ticked design a number, or leave them all empty to split evenly.', 'cardsco' ); ?>"
				data-cc-full="<?php esc_attr_e( 'That is the most this order can have. Untick one to choose another.', 'cardsco' ); ?>"
				data-cc-count="<?php esc_attr_e( '%1$s designs selected', 'cardsco' ); ?>"
				data-cc-count-one="<?php esc_attr_e( '1 design selected', 'cardsco' ); ?>"></p>
		<?php endif; ?>

	<?php endif; ?>
</form>

<?php
/*
 * The preview panel, shared with the catalogue. Built from the same tile
 * data so the two screens describe a card identically, and in "pick" mode
 * so its action selects the card rather than sending anybody shopping.
 */
$cc_pv = array();

foreach ( $cards['tiles'] as $cc_t ) {
	$cc_full = CardsCo_Catalogue::tile( (int) $cc_t['id'] );

	if ( ! $cc_full ) {
		continue;
	}

	$cc_pv[ (string) $cc_t['id'] ] = array(
		'title'      => $cc_full['title'],
		'pattern'    => $cc_full['pattern'],
		'occasions'  => $cc_full['occasions'],
		'collection' => $cc_full['collection'],
		'front'      => $cc_full['front'],
		'format'     => $cc_full['format'],
		'image'      => $cc_full['image_lg'] ? $cc_full['image_lg'] : $cc_full['image'],
		'url'        => $cc_full['url'],
		'use'        => '',
	);
}

$cc_pv_mode = 'pick';
include CARDSCO_PATH . 'includes/front/partial-preview.php';
