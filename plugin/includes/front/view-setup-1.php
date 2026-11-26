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
 * @var array   $order     From CardsCo_Dashboard::setup_order().
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
			<?php
			$cc_id = 'cc-occ-' . $cc_pick['slug'];

			/*
			 * One of these is not a choice on this screen: it is set up as
			 * a campaign, and the tile is a way through to that rather
			 * than a radio that refuses once it is pressed. Without a
			 * campaigns page to point at it stays a marked, dead tile,
			 * which is still better than one that looks pickable.
			 */
			$cc_away = ! empty( $cc_pick['campaign_only'] ) && ! empty( $cc_pick['campaign_link'] );

			/*
			 * A coming-soon occasion is a button rather than a label: the
			 * radio behind it is disabled, so pressing the tile did
			 * nothing at all and read as a broken control. It opens the
			 * dialog at the foot of this screen instead.
			 */
			$cc_soon = ! empty( $cc_pick['coming_soon'] );
			$cc_tag  = $cc_away ? 'a' : ( $cc_soon ? 'button' : 'label' );
			?>
			<<?php echo esc_attr( $cc_tag ); ?>
				class="cc-pick<?php echo $cc_pick['coming_soon'] ? ' is-soon' : ''; ?><?php echo ! empty( $cc_pick['campaign_only'] ) ? ' is-elsewhere' : ''; ?><?php echo $cc_pick['chosen'] ? ' is-chosen' : ''; ?>"
				<?php if ( $cc_soon ) : ?>
					type="button"
					data-cc-soon="<?php echo esc_attr( $cc_pick['label'] ); ?>"
				<?php elseif ( $cc_away ) : ?>
					href="<?php echo esc_url( $cc_pick['campaign_link'] ); ?>"
					<?php
					/*
					 * This one leaves the wizard. The wizard swaps its own
					 * links in place rather than reloading, and it decides
					 * what is "its own" by the path — which on a site with
					 * plain permalinks is the same path for every page, so
					 * Campaigns was being pulled into the wizard's panel
					 * instead of opened, and arrived as markup with none of
					 * its scripts. data-cc-full is the swap's own way of
					 * being told to leave a link alone.
					 */
					?>
					data-cc-full
				<?php elseif ( ! $cc_soon ) : ?>
					for="<?php echo esc_attr( $cc_id ); ?>"
				<?php endif; ?>>
				<?php if ( ! $cc_away && ! $cc_soon ) : ?>
				<input type="radio" id="<?php echo esc_attr( $cc_id ); ?>" name="category"
					value="<?php echo esc_attr( $cc_pick['slug'] ); ?>"
					<?php checked( $cc_pick['chosen'] ); ?>
					<?php disabled( $cc_pick['coming_soon'] || ! empty( $cc_pick['campaign_only'] ) ); ?> />
				<?php endif; ?>

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
					<?php elseif ( ! empty( $cc_pick['campaign_only'] ) ) : ?>
						<span class="cc-pill is-neutral"><?php esc_html_e( 'Set up as a campaign', 'cardsco' ); ?></span>
					<?php endif; ?>
				</span>

				<span class="cc-pick-blurb"><?php echo esc_html( $cc_pick['blurb'] ); ?></span>
				<span class="cc-pick-stat<?php echo ( $cc_pick['coming_soon'] || ! empty( $cc_pick['campaign_only'] ) ) ? ' is-quiet' : ''; ?>">
					<?php
					if ( ! empty( $cc_pick['campaign_only'] ) ) {
						echo $cc_away
							? esc_html__( 'Goes out on a day you choose · set it up in Campaigns', 'cardsco' )
							: esc_html__( 'Goes out on a day you choose, so it is set up in Campaigns', 'cardsco' );
					} else {
						echo esc_html( $cc_pick['stat'] );
					}
					?>
				</span>
			</<?php echo esc_attr( $cc_tag ); ?>>
		<?php endforeach; ?>
	</div>

	<?php if ( $note ) : ?>
		<p class="cc-wz-note"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $order['options'] ) ) : ?>
		<?php
		/*
		 * Single or bulk, asked here because the rest of the set-up
		 * follows from it — where the cards go, what each costs, and
		 * whether the order may mix designs. One of the two is always
		 * marked, so pressing Continue without touching it is an answer
		 * rather than an omission.
		 */
		?>
		<fieldset class="cc-kind">
			<legend class="cc-kind-legend"><?php esc_html_e( 'Single cards or a bulk order?', 'cardsco' ); ?></legend>

			<div class="cc-kind-list">
				<?php foreach ( $order['options'] as $cc_opt ) : ?>
					<label class="cc-kind-one<?php echo $cc_opt['on'] ? ' is-on' : ''; ?>">
						<input type="radio" name="order_type"
							value="<?php echo esc_attr( $cc_opt['value'] ); ?>"
							<?php checked( $cc_opt['on'] ); ?> />
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
	<?php endif; ?>
</form>

<?php
/* What a coming-soon tile opens. */
include CARDSCO_PATH . 'includes/front/partial-soon.php';
