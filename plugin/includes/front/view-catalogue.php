<?php
/**
 * The public card catalogue.
 *
 * Measured from the 1440 frame: four columns from x=80, tiles 302 wide with
 * a 24px gutter, 384px between rows. Heading 40, lede 16, pill label 13 with
 * a 12px count, card title 14, artwork name 12 right-aligned to the tile,
 * occasion line 13.
 *
 * There are no prices here, deliberately — the frame has none. This is a
 * browse-and-choose page; the money happens after "Use this card".
 *
 * @package CardsCo
 *
 * @var array $page  Everything from CardsCo_Catalogue::page().
 * @var array $links Shortcode attributes.
 */

defined( 'ABSPATH' ) || exit;

$cc_base = remove_query_arg( array( 'cc_filter', 'cc_show' ) );
?>
<div class="cc-cat">

	<header class="cc-cat-head">
		<div class="cc-cat-head-text">
			<h1><?php esc_html_e( 'Card catalogue', 'cardsco' ); ?></h1>
			<p class="cc-cat-lede"><?php esc_html_e( 'Every card is designed by the CardsCo team. Choose one, add your message, and we’ll write it by hand.', 'cardsco' ); ?></p>
		</div>
		<p class="cc-cat-count">
			<?php
			printf(
				/* translators: %s: how many designs there are. */
				esc_html( _n( '%s design', '%s designs', $page['all'], 'cardsco' ) ),
				esc_html( number_format_i18n( $page['all'] ) )
			);
			?>
		</p>
	</header>

	<?php if ( $page['filters'] ) : ?>
		<nav class="cc-cat-filters" aria-label="<?php esc_attr_e( 'Filter by collection', 'cardsco' ); ?>">
			<a class="cc-pill-filter<?php echo $page['filter'] ? '' : ' is-on'; ?>"
				href="<?php echo esc_url( $cc_base ); ?>"
				<?php echo $page['filter'] ? '' : 'aria-current="true"'; ?>>
				<?php esc_html_e( 'All', 'cardsco' ); ?>
				<span class="cc-pill-n"><?php echo esc_html( number_format_i18n( $page['all'] ) ); ?></span>
			</a>
			<?php foreach ( $page['filters'] as $cc_f ) : ?>
				<?php $cc_on = (string) $page['filter'] === (string) $cc_f['id']; ?>
				<a class="cc-pill-filter<?php echo $cc_on ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( 'cc_filter', (string) $cc_f['id'], $cc_base ) ); ?>"
					<?php echo $cc_on ? 'aria-current="true"' : ''; ?>>
					<?php echo esc_html( $cc_f['label'] ); ?>
					<span class="cc-pill-n"><?php echo esc_html( number_format_i18n( $cc_f['count'] ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $page['all'] ) : ?>
	<form class="cc-cat-sort" method="get" action="<?php echo esc_url( $cc_base ); ?>">
		<?php if ( $page['filter'] ) : ?>
			<input type="hidden" name="cc_filter" value="<?php echo esc_attr( (string) $page['filter'] ); ?>" />
		<?php endif; ?>
		<label class="cc-cat-sort-label" for="cc-cat-sort"><?php esc_html_e( 'Sort by', 'cardsco' ); ?></label>
		<select id="cc-cat-sort" name="cc_sort" onchange="this.form.submit()">
			<?php foreach ( CardsCo_Catalogue::sorts() as $cc_key => $cc_label ) : ?>
				<option value="<?php echo esc_attr( $cc_key ); ?>" <?php selected( $page['sort'], $cc_key ); ?>>
					<?php echo esc_html( $cc_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<noscript><button class="cc-cat-sort-go" type="submit"><?php esc_html_e( 'Sort', 'cardsco' ); ?></button></noscript>
	</form>
	<?php endif; ?>

	<?php if ( ! $page['tiles'] ) : ?>

		<p class="cc-cat-empty">
			<?php
			if ( $page['all'] ) {
				esc_html_e( 'No cards for that occasion yet. Try another, or see them all.', 'cardsco' );
			} else {
				// Nothing in the catalogue at all, which is a different thing
				// from a filter finding nothing.
				esc_html_e( 'No cards here yet. They will appear as soon as the first designs go in.', 'cardsco' );
			}
			?>
		</p>

	<?php else : ?>

		<ul class="cc-cat-grid">
			<?php foreach ( $page['tiles'] as $cc_tile ) : ?>
				<li class="cc-cat-tile">
					<div class="cc-cat-art">
						<?php if ( $cc_tile['image'] ) : ?>
							<img src="<?php echo esc_url( $cc_tile['image'] ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="cc-cat-art-blank" aria-hidden="true"></span>
						<?php endif; ?>

						<?php if ( $cc_tile['url'] ) : ?>
							<div class="cc-cat-actions">
								<?php
								/*
								 * Still a link to the product page, and it
								 * still works as one. preview.js intercepts
								 * the click and opens the panel instead; with
								 * no JavaScript, or on a browser without
								 * <dialog>, the click falls through to the
								 * product page exactly as it did before.
								 */
								?>
								<a class="cc-cat-preview" href="<?php echo esc_url( $cc_tile['url'] ); ?>"
									data-cc-pv="<?php echo esc_attr( (int) $cc_tile['id'] ); ?>">
									<?php
									printf(
										/* translators: %s: the card's name. */
										esc_html__( 'Preview %s', 'cardsco' ),
										esc_html( $cc_tile['title'] )
									);
									?>
								</a>
								<?php
								/*
								 * Only offered when there is somewhere for it
								 * to go.
								 *
								 * A card with no product behind it has no
								 * permalink to fall back to, so when the send
								 * page has not been found either this came out
								 * as href="" — a green button, sitting under a
								 * card, that quietly reloaded the catalogue.
								 * A card nobody can buy should not be offering
								 * to sell itself.
								 */
								$cc_use = CardsCo_Send::use_url( $cc_tile['id'], $links['use'] ? $links['use'] : $cc_tile['url'] );
								?>
								<?php if ( '' !== $cc_use ) : ?>
									<a class="cc-cat-use" href="<?php echo esc_url( $cc_use ); ?>">
										<?php esc_html_e( 'Use this card', 'cardsco' ); ?>
									</a>
								<?php else : ?>
									<span class="cc-cat-use cc-cat-use-off">
										<?php esc_html_e( 'Coming soon', 'cardsco' ); ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="cc-cat-meta">
						<p class="cc-cat-name"><?php echo esc_html( $cc_tile['title'] ); ?></p>
						<?php if ( $cc_tile['pattern'] ) : ?>
							<p class="cc-cat-pattern"><?php echo esc_html( $cc_tile['pattern'] ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( $cc_tile['occasions'] ) : ?>
						<p class="cc-cat-occasions"><?php echo esc_html( $cc_tile['occasions'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="cc-cat-foot">
			<p class="cc-cat-showing">
				<?php
				printf(
					/* translators: 1: how many are on screen, 2: how many there are. */
					esc_html__( 'Showing %1$s of %2$s', 'cardsco' ),
					esc_html( number_format_i18n( $page['shown'] ) ),
					esc_html( number_format_i18n( $page['total'] ) )
				);
				?>
			</p>
			<?php if ( $page['more'] ) : ?>
				<a class="cc-cat-more" href="<?php echo esc_url( add_query_arg( 'cc_show', (int) $page['next'] ) ); ?>#cc-cat-more">
					<?php esc_html_e( 'Show more cards', 'cardsco' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php
		/*
		 * The preview panel.
		 *
		 * One dialog for the whole grid, filled from the data below rather
		 * than one hidden copy per tile — twelve tiles would otherwise mean
		 * twelve copies of the same markup sitting in the page.
		 *
		 * <dialog> is doing the work a hand-rolled modal would need a lot of
		 * script for: the backdrop, trapping focus inside, closing on Escape,
		 * and putting focus back on the tile afterwards.
		 */
		$cc_pv = array();

		foreach ( $page['tiles'] as $cc_t ) {
			$cc_pv[ (string) $cc_t['id'] ] = array(
				'title'      => $cc_t['title'],
				'pattern'    => $cc_t['pattern'],
				'occasions'  => $cc_t['occasions'],
				'collection' => $cc_t['collection'],
				'front'      => $cc_t['front'],
				'format'     => $cc_t['format'],
				'image'      => $cc_t['image_lg'] ? $cc_t['image_lg'] : $cc_t['image'],
				'url'        => $cc_t['url'],
				'use'        => CardsCo_Send::use_url( $cc_t['id'], $links['use'] ? $links['use'] : $cc_t['url'] ),
			);
		}
		?>

		<?php
		$cc_pv_mode = 'shop';
		include CARDSCO_PATH . 'includes/front/partial-preview.php';
		?>


	<?php endif; ?>

</div>
