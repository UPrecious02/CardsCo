<?php
/**
 * Orders.
 *
 * Measured from the Orders frame: a 1440 frame with a 280 sidebar, content
 * from x=280 to x=1400. Title 34px at y=38, subtitle 14px at y=85, and a
 * bordered "Download invoices" button 172x38 at x=1228 y=66.
 *
 * Three stat cards at y=128, 142 tall, on a 16 gap. Then the filter row at
 * y=294: a segmented control 270x34 on the left, a 280x36 search box at
 * x=562, and a 138x32 period select at x=850.
 *
 * The table card is 1120x649 at y=354. Its header sits at y=365 and rows
 * run on a 61 pitch from y=394, each carrying two lines in the Order and
 * Description columns. Columns: Order 297, Type 453, Description 579,
 * Cards right-aligned at 1070, Status 1087, Total right-aligned at 1360.
 *
 * @package CardsCo
 *
 * @var array $found   From CardsCo_Orders::all().
 * @var array $stats   From CardsCo_Orders::stats().
 * @var array $tabs
 * @var array $windows
 * @var array $links
 * @var int   $months
 * @var string $search
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';
?>
<div class="cc cc-main cc-orders">

	<header class="cc-page-head cc-page-head-row">
		<div class="cc-page-head-text">
			<h1 class="cc-page-title"><?php esc_html_e( 'Orders', 'cardsco' ); ?></h1>
			<p class="cc-page-sub"><?php esc_html_e( 'Every order you’ve placed — recurring occasions, campaigns and bulk buys.', 'cardsco' ); ?></p>
		</div>
		<?php /* Nothing to download means no button. A button that hands back a
		         file with nothing but column headings in it is worse than none. */ ?>
		<?php if ( $found['total'] ) : ?>
			<a class="cc-btn cc-btn-quiet cc-btn-r8 cc-ord-dl" href="<?php echo esc_url( $links['invoice'] ); ?>">
				<?php echo call_user_func( array( $cc, 'icon' ), 'download', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<span><?php esc_html_e( 'Download invoices', 'cardsco' ); ?></span>
			</a>
		<?php endif; ?>
	</header>

	<section class="cc-ord-stats">
		<div class="cc-ord-stat">
			<p class="cc-ord-stat-label"><?php esc_html_e( 'Orders this year', 'cardsco' ); ?></p>
			<p class="cc-ord-stat-n"><?php echo esc_html( number_format_i18n( (int) $stats['orders'] ) ); ?></p>
			<p class="cc-ord-stat-sub">
				<?php
				/* "+0 in November" reads like something failed to load. */
				if ( $stats['added'] ) {
					printf(
						/* translators: 1: how many more, 2: a month. */
						esc_html__( '+%1$s in %2$s', 'cardsco' ),
						esc_html( number_format_i18n( (int) $stats['added'] ) ),
						esc_html( $stats['month'] )
					);
				} else {
					printf(
						/* translators: %s: a month. */
						esc_html__( 'None so far in %s', 'cardsco' ),
						esc_html( $stats['month'] )
					);
				}
				?>
			</p>
		</div>
		<div class="cc-ord-stat">
			<p class="cc-ord-stat-label"><?php esc_html_e( 'Cards sent this year', 'cardsco' ); ?></p>
			<p class="cc-ord-stat-n"><?php echo esc_html( number_format_i18n( (int) $stats['cards'] ) ); ?></p>
			<p class="cc-ord-stat-sub"><?php esc_html_e( 'Across all three order types', 'cardsco' ); ?></p>
		</div>
		<div class="cc-ord-stat">
			<p class="cc-ord-stat-label"><?php esc_html_e( 'In progress', 'cardsco' ); ?></p>
			<p class="cc-ord-stat-n">
				<?php
				printf(
					/* translators: %s: how many orders. */
					esc_html( _n( '%s order', '%s orders', (int) $stats['in_flight'], 'cardsco' ) ),
					esc_html( number_format_i18n( (int) $stats['in_flight'] ) )
				);
				?>
			</p>
			<p class="cc-ord-stat-sub">
				<?php
				printf(
					/* translators: %s: how many cards. */
					esc_html( _n( '%s card being written or posted', '%s cards being written or posted', (int) $stats['writing'], 'cardsco' ) ),
					esc_html( number_format_i18n( (int) $stats['writing'] ) )
				);
				?>
			</p>
		</div>
	</section>

	<form class="cc-ord-filters" method="get" action="<?php echo esc_url( $links['here'] ); ?>">

		<div class="cc-ord-tabs" role="tablist">
			<?php foreach ( $tabs as $cc_key => $cc_tab ) : ?>
				<a class="cc-ord-tab<?php echo $cc_tab['on'] ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( array_filter( array( 'q' => $search, 'months' => 12 === $months ? null : $months ) ), $cc_tab['url'] ) ); ?>"
					role="tab" aria-selected="<?php echo $cc_tab['on'] ? 'true' : 'false'; ?>">
					<?php /* The frame's tabs carry no counts, and adding them widens
					         the control past the 270 it is drawn at. */ ?>
					<span><?php echo esc_html( $cc_tab['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="cc-ord-search">
			<?php echo call_user_func( array( $cc, 'icon' ), 'search', 'cc-ord-search-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			<label class="cc-sr" for="ccOrdSearch"><?php esc_html_e( 'Search orders or recipients', 'cardsco' ); ?></label>
			<input type="search" id="ccOrdSearch" name="q" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search orders or recipients', 'cardsco' ); ?>" />
		</div>

		<label class="cc-sr" for="ccOrdMonths"><?php esc_html_e( 'How far back', 'cardsco' ); ?></label>
		<select class="cc-ord-window" id="ccOrdMonths" name="months" data-cc-filter>
			<?php foreach ( $windows as $cc_n => $cc_label ) : ?>
				<option value="<?php echo esc_attr( $cc_n ); ?>" <?php selected( $months, $cc_n ); ?>>
					<?php echo esc_html( $cc_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php /* The tab is a link, so searching from inside a tab has to keep it. */ ?>
		<?php foreach ( $tabs as $cc_key => $cc_tab ) : ?>
			<?php if ( $cc_tab['on'] && 'all' !== $cc_key ) : ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $cc_key ); ?>" />
			<?php endif; ?>
		<?php endforeach; ?>

		<noscript><button type="submit" class="cc-btn cc-btn-quiet cc-btn-r8"><?php esc_html_e( 'Search', 'cardsco' ); ?></button></noscript>
	</form>

	<section class="cc-ord-table">

		<?php if ( ! $found['rows'] ) : ?>

			<div class="cc-ord-empty">
				<p class="cc-ord-empty-head">
					<?php
					echo esc_html(
						$search
							? __( 'Nothing matches that search.', 'cardsco' )
							: __( 'No orders yet.', 'cardsco' )
					);
					?>
				</p>
				<p class="cc-ord-empty-sub">
					<?php
					echo esc_html(
						$search
							? __( 'Try a different order number, description or name.', 'cardsco' )
							: __( 'Occasions, campaigns and bulk buys all show up here once they’re paid for.', 'cardsco' )
					);
					?>
				</p>
			</div>

		<?php else : ?>

			<div class="cc-ord-head" role="row">
				<span class="cc-ord-c-ref"><?php esc_html_e( 'Order', 'cardsco' ); ?></span>
				<span class="cc-ord-c-type"><?php esc_html_e( 'Type', 'cardsco' ); ?></span>
				<span class="cc-ord-c-desc"><?php esc_html_e( 'Description', 'cardsco' ); ?></span>
				<span class="cc-ord-c-cards"><?php esc_html_e( 'Cards', 'cardsco' ); ?></span>
				<span class="cc-ord-c-status"><?php esc_html_e( 'Status', 'cardsco' ); ?></span>
				<span class="cc-ord-c-total"><?php esc_html_e( 'Total', 'cardsco' ); ?></span>
			</div>

			<?php foreach ( $found['rows'] as $cc_row ) : ?>
				<?php $cc_when = strtotime( (string) $cc_row['placed'] ); ?>
				<div class="cc-ord-row" role="row">

					<span class="cc-ord-c-ref">
						<span class="cc-ord-ref"><?php echo esc_html( $cc_row['reference'] ); ?></span>
						<span class="cc-ord-when"><?php echo esc_html( $cc_when ? date_i18n( 'j M Y', $cc_when ) : '' ); ?></span>
					</span>

					<span class="cc-ord-c-type">
						<span class="cc-pill is-neutral"><?php echo esc_html( $cc_row['kind_label'] ); ?></span>
					</span>

					<span class="cc-ord-c-desc">
						<span class="cc-ord-title"><?php echo esc_html( $cc_row['title'] ); ?></span>
						<?php if ( $cc_row['detail'] ) : ?>
							<span class="cc-ord-detail"><?php echo esc_html( $cc_row['detail'] ); ?></span>
						<?php endif; ?>
					</span>

					<?php /* The unit rides along as an attribute so the narrow layout can
					         show it without the wide one repeating it in every row. */ ?>
					<span class="cc-ord-c-cards" data-cc-unit="<?php echo esc_attr( _n( 'card', 'cards', (int) $cc_row['cards'], 'cardsco' ) ); ?>">
						<?php echo esc_html( number_format_i18n( (int) $cc_row['cards'] ) ); ?>
					</span>

					<span class="cc-ord-c-status">
						<span class="cc-pill is-<?php echo esc_attr( $cc_row['tone'] ); ?>">
							<?php echo esc_html( $cc_row['status_label'] ); ?>
						</span>
					</span>

					<span class="cc-ord-c-total">
						<?php if ( null === $cc_row['amount'] ) : ?>
							<?php /* A campaign is priced by hand, so it waits on a quote. A box
							         is priced by the list and simply has not been charged yet;
							         calling that "quote pending" invites an email asking for one. */ ?>
							<span class="cc-ord-pending">
								<?php
								echo esc_html(
									'campaign' === $cc_row['kind']
										? __( 'Quote pending', 'cardsco' )
										: __( 'Not yet charged', 'cardsco' )
								);
								?>
							</span>
						<?php else : ?>
							<?php
							echo esc_html(
								call_user_func(
									array( $cc, 'money' ),
									$cc_row['amount'],
									$cc_row['currency'] ? $cc_row['currency'] : 'GBP'
								)
							);
							?>
						<?php endif; ?>
					</span>

				</div>
			<?php endforeach; ?>

			<footer class="cc-ord-foot">
				<p class="cc-ord-showing">
					<?php
					printf(
						/* translators: 1: first row, 2: last row, 3: how many in total. */
						esc_html__( 'Showing %1$s–%2$s of %3$s', 'cardsco' ),
						esc_html( number_format_i18n( (int) $found['from'] ) ),
						esc_html( number_format_i18n( (int) $found['to'] ) ),
						esc_html( number_format_i18n( (int) $found['total'] ) )
					);
					?>
				</p>

				<?php if ( $found['pages'] > 1 ) : ?>
					<nav class="cc-ord-pages" aria-label="<?php esc_attr_e( 'Pages of orders', 'cardsco' ); ?>">
						<?php for ( $cc_p = 1; $cc_p <= $found['pages']; $cc_p++ ) : ?>
							<a class="cc-ord-page<?php echo (int) $found['page'] === $cc_p ? ' is-on' : ''; ?>"
								href="<?php echo esc_url( add_query_arg( 'paged', $cc_p ) ); ?>"
								<?php echo (int) $found['page'] === $cc_p ? 'aria-current="page"' : ''; ?>>
								<?php echo esc_html( number_format_i18n( $cc_p ) ); ?>
							</a>
						<?php endfor; ?>
					</nav>
				<?php endif; ?>

				<p class="cc-ord-per">
					<?php /* Drawn as a control in the frame, so it is one. A label that
					         looks like a dropdown and does nothing is worse than a number. */ ?>
					<label for="ccOrdPer"><?php esc_html_e( 'Rows per page', 'cardsco' ); ?></label>
					<select id="ccOrdPer" class="cc-ord-per-n" data-cc-nav>
						<?php foreach ( array( 10, 25, 50 ) as $cc_n ) : ?>
							<option value="<?php echo esc_url( add_query_arg( array( 'per' => $cc_n, 'paged' => 1 ) ) ); ?>" <?php selected( (int) $found['per'], $cc_n ); ?>>
								<?php echo esc_html( number_format_i18n( $cc_n ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			</footer>

		<?php endif; ?>
	</section>

	<p class="cc-ord-note">
		<span class="cc-ord-note-icon" aria-hidden="true">
			<?php echo call_user_func( array( $cc, 'icon' ), 'info', 'cc-ord-note-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</span>
		<span><?php esc_html_e( 'Totals include VAT and UK postage.', 'cardsco' ); ?></span>
	</p>

</div>
