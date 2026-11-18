<?php
/**
 * The Collections screen.
 *
 * Six tiles, one per collection, each landing on the catalogue with that
 * collection selected. There is no approved frame for this screen — the
 * catalogue is in the Figma and this is not — so it is built from the same
 * system the catalogue uses: the 1360 container, EB Garamond headings, Inter
 * for everything else, and the tile treatment from the card grid.
 *
 * @var array $collections From CardsCo_Catalogue::collections().
 * @var array $links       Shortcode attributes.
 */

defined( 'ABSPATH' ) || exit;

$cc_catalogue = isset( $links['catalogue'] ) ? trim( (string) $links['catalogue'] ) : '';
$cc_heading   = isset( $links['heading'] ) ? trim( (string) $links['heading'] ) : '';
$cc_lede      = isset( $links['lede'] ) ? trim( (string) $links['lede'] ) : '';
$cc_total     = 0;

foreach ( $collections as $cc_c ) {
	$cc_total += (int) $cc_c['count'];
}
?>
<div class="cc-cat cc-coll">

	<header class="cc-cat-head">
		<div class="cc-cat-head-text">
			<h1><?php echo esc_html( $cc_heading ? $cc_heading : __( 'Collections', 'cardsco' ) ); ?></h1>
			<p class="cc-cat-lede">
				<?php
				echo esc_html(
					$cc_lede
						? $cc_lede
						: __( 'Every card we make, grouped by the moment it is for. Pick a collection to see what is in it.', 'cardsco' )
				);
				?>
			</p>
		</div>
		<p class="cc-cat-count">
			<?php
			printf(
				/* translators: %s: how many designs there are. */
				esc_html( _n( '%s design', '%s designs', $cc_total, 'cardsco' ) ),
				esc_html( number_format_i18n( $cc_total ) )
			);
			?>
		</p>
	</header>

	<ul class="cc-coll-grid">
		<?php
		foreach ( $collections as $cc_c ) :
			$cc_empty = ( 0 === (int) $cc_c['count'] );

			/*
			 * An empty collection has nothing to show, so it is a plain tile
			 * rather than a link into an empty catalogue. The link is only
			 * built when there is a catalogue page to point at — without the
			 * attribute the tile still renders, it just does not navigate.
			 */
			$cc_href = ( ! $cc_empty && $cc_catalogue )
				? add_query_arg( 'cc_filter', $cc_c['slug'], $cc_catalogue )
				: '';

			$cc_tag = $cc_href ? 'a' : 'div';
			?>
			<li class="cc-coll-tile<?php echo $cc_empty ? ' is-empty' : ''; ?>">
				<<?php echo esc_attr( $cc_tag ); ?> class="cc-coll-card"
					<?php echo $cc_href ? 'href="' . esc_url( $cc_href ) . '"' : ''; ?>>

					<span class="cc-coll-art">
						<?php if ( $cc_c['cover'] ) : ?>
							<img src="<?php echo esc_url( $cc_c['cover'] ); ?>" alt="" loading="lazy" />
						<?php else : ?>
							<span class="cc-coll-art-blank" aria-hidden="true"></span>
						<?php endif; ?>

						<?php if ( $cc_c['coming_soon'] ) : ?>
							<span class="cc-coll-chip"><?php esc_html_e( 'Coming soon', 'cardsco' ); ?></span>
						<?php endif; ?>
					</span>

					<span class="cc-coll-body">
						<span class="cc-coll-name"><?php echo esc_html( $cc_c['label'] ); ?></span>
						<span class="cc-coll-blurb"><?php echo esc_html( $cc_c['blurb'] ); ?></span>
						<span class="cc-coll-meta">
							<?php if ( $cc_empty ) : ?>
								<?php esc_html_e( 'Designs on the way', 'cardsco' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: card format, 2: how many designs. */
									esc_html__( '%1$s · %2$s', 'cardsco' ),
									esc_html( $cc_c['format'] ),
									esc_html( sprintf( /* translators: %s: a number. */ _n( '%s design', '%s designs', $cc_c['count'], 'cardsco' ), number_format_i18n( $cc_c['count'] ) ) )
								);
								?>
							<?php endif; ?>
						</span>
					</span>
				</<?php echo esc_attr( $cc_tag ); ?>>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $cc_catalogue ) : ?>
		<p class="cc-coll-all">
			<a class="cc-pill-filter" href="<?php echo esc_url( $cc_catalogue ); ?>">
				<?php esc_html_e( 'Or browse every card', 'cardsco' ); ?>
			</a>
		</p>
	<?php endif; ?>

</div>
