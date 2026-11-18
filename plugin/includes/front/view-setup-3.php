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
 * @var array $cards     Tabs, tiles and what is chosen.
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_here = $links['here'];
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $cc_here ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="3" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<?php if ( count( $cards['tabs'] ) > 1 ) : ?>
		<nav class="cc-src-tabs" aria-label="<?php esc_attr_e( 'Where these cards come from', 'cardsco' ); ?>">
			<?php foreach ( $cards['tabs'] as $cc_tab ) : ?>
				<a class="cc-src-tab<?php echo $cc_tab['on'] ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'cards', $cc_tab['key'], $cc_here ) ); ?>"
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

	<?php if ( ! $cards['tiles'] ) : ?>

		<p class="cc-wz-empty">
			<?php
			if ( 'mine' === $cards['tab'] ) {
				esc_html_e( 'You have no designs of your own yet. Talk to us about having some made and they will show up here.', 'cardsco' );
			} else {
				esc_html_e( 'No cards are set up for this occasion yet. Get in touch and we will sort it out.', 'cardsco' );
			}
			?>
		</p>

	<?php else : ?>

		<ul class="cc-card-picker">
			<?php foreach ( $cards['tiles'] as $cc_tile ) : ?>
				<li class="cc-card-pick<?php echo $cc_tile['selected'] ? ' is-on' : ''; ?>">
					<label class="cc-card-pick-label">
						<input class="cc-card-pick-input"
							type="radio"
							name="card_id"
							value="<?php echo esc_attr( $cc_tile['id'] ); ?>"
							<?php checked( $cc_tile['selected'] ); ?> />

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
