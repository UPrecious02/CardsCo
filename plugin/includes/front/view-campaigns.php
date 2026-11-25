<?php
/**
 * Campaigns.
 *
 * Built against frames 7:4419 (filled) and 14:14055 (empty): a header with a
 * New campaign button, three tabs with counts, a list of 130px rows each
 * carrying cover art, audience, offer and a progress bar, and then a table of
 * recently finished campaigns.
 *
 * @package CardsCo
 *
 * @var array[] $rows
 * @var array   $tabs
 * @var array[] $finished
 * @var int     $finished_total
 * @var array   $links
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';
?>
<div class="cc cc-main">

	<header class="cc-page-head cc-page-head-row">
		<div class="cc-page-head-text">
			<h1 class="cc-page-title"><?php esc_html_e( 'Campaigns', 'cardsco' ); ?></h1>
			<p class="cc-page-sub"><?php esc_html_e( 'Handwritten cards to many customers at once, with an offer if you like', 'cardsco' ); ?></p>
		</div>
		<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( $links['new'] ); ?>">
			<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			<span><?php esc_html_e( 'New campaign', 'cardsco' ); ?></span>
		</a>
	</header>

	<div class="cc-tabs">
		<?php foreach ( $tabs as $cc_tab ) : ?>
			<a class="cc-tab<?php echo $cc_tab['on'] ? ' is-on' : ''; ?>"
				href="<?php echo esc_url( $cc_tab['url'] ); ?>"
				<?php echo $cc_tab['on'] ? ' aria-current="page"' : ''; ?>>
				<span><?php echo esc_html( $cc_tab['label'] ); ?></span>
				<span class="cc-tab-count"><?php echo esc_html( number_format_i18n( $cc_tab['count'] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $rows ) : ?>
		<?php
		/*
		 * The empty tile from frame 14:14055. The two explainer cards and both
		 * buttons are the first-run case only — a company that has campaigns
		 * but none in this tab does not need the introduction again.
		 */
		$cc_first_run = 0 === array_sum( wp_list_pluck( $tabs, 'count' ) );
		?>
		<div class="cc-empty cc-empty-lg">
			<div class="cc-empty-art cc-empty-art-figure" aria-hidden="true">
				<?php echo call_user_func( array( $cc, 'art' ), 'empty-no-content' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg read from the plugin. ?>
			</div>

			<?php if ( $cc_first_run ) : ?>
				<h2 class="cc-empty-head"><?php esc_html_e( 'Send your first campaign', 'cardsco' ); ?></h2>
				<p class="cc-empty-body"><?php esc_html_e( 'One card design, written for many people. Add an offer or a QR code if you like.', 'cardsco' ); ?></p>

				<div class="cc-explainers">
					<div class="cc-explainer">
						<span class="cc-explainer-icon" aria-hidden="true">
							<?php echo call_user_func( array( $cc, 'icon' ), 'users', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						</span>
						<span class="cc-explainer-text">
							<span class="cc-explainer-title"><?php esc_html_e( 'Post to each person', 'cardsco' ); ?></span>
							<span class="cc-explainer-body"><?php esc_html_e( 'Upload names and addresses. Every card goes out in its own envelope.', 'cardsco' ); ?></span>
						</span>
					</div>
					<div class="cc-explainer">
						<span class="cc-explainer-icon" aria-hidden="true">
							<?php echo call_user_func( array( $cc, 'icon' ), 'building', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						</span>
						<span class="cc-explainer-text">
							<span class="cc-explainer-title"><?php esc_html_e( 'Ship to one address', 'cardsco' ); ?></span>
							<span class="cc-explainer-body"><?php esc_html_e( 'All the cards arrive together in one box, ready to hand out.', 'cardsco' ); ?></span>
						</span>
					</div>
				</div>

				<div class="cc-empty-actions">
					<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( $links['new'] ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<span><?php esc_html_e( 'New campaign', 'cardsco' ); ?></span>
					</a>
					<?php if ( ! empty( $links['single'] ) ) : ?>
						<a class="cc-btn cc-btn-secondary" href="<?php echo esc_url( $links['single'] ); ?>">
							<span><?php esc_html_e( 'Send a single card', 'cardsco' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="cc-empty-title"><?php esc_html_e( 'Nothing here yet', 'cardsco' ); ?></p>
				<p class="cc-empty-body"><?php esc_html_e( 'No campaigns are in this state at the moment.', 'cardsco' ); ?></p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="cc-camps">
			<?php foreach ( $rows as $cc_row ) : ?>
				<?php
				$cc_badge = call_user_func( array( $cc, 'badge' ), array( 'kind' => 'campaign', 'status' => $cc_row['status'] ) );
				$cc_prog  = call_user_func( array( $cc, 'campaign_progress' ), $cc_row );
				$cc_act   = call_user_func( array( $cc, 'campaign_action' ), $cc_row, $links );
				?>
				<article class="cc-camp<?php echo $cc_prog['attention'] ? ' needs-review' : ''; ?>">
					<?php
					/*
					 * The frame's cover is a 58x78 coloured block carrying the
					 * card's own name ("Thank you"), not a photograph and not
					 * an empty rectangle. A real card thumbnail is used when
					 * there is one; otherwise the name is set on the block, so
					 * the row still says which card is going out.
					 */
					?>
					<div class="cc-camp-cover">
						<?php if ( $cc_row['cover']['image'] ) : ?>
							<img src="<?php echo esc_url( $cc_row['cover']['image'] ); ?>" alt="" />
						<?php else : ?>
							<span class="cc-camp-cover-blank" aria-hidden="true">
								<span><?php echo esc_html( $cc_row['cover']['title'] ); ?></span>
							</span>
						<?php endif; ?>
					</div>

					<div class="cc-camp-body">
						<p class="cc-camp-head">
							<span class="cc-camp-name"><?php echo esc_html( $cc_row['name'] ); ?></span>
							<span class="cc-badge is-<?php echo esc_attr( $cc_badge['tone'] ); ?>">
								<span class="cc-badge-dot" aria-hidden="true"></span>
								<?php echo esc_html( $cc_badge['label'] ); ?>
							</span>
						</p>
						<p class="cc-camp-meta"><?php echo esc_html( call_user_func( array( $cc, 'campaign_meta' ), $cc_row ) ); ?></p>
						<?php if ( $cc_row['offer'] ) : ?>
							<p class="cc-camp-offer">
								<span class="cc-chip"><?php esc_html_e( 'Offer', 'cardsco' ); ?></span>
								<span class="cc-camp-offer-text"><?php echo esc_html( call_user_func( array( $cc, 'offer_words' ), $cc_row['offer'] ) ); ?></span>
							</p>
						<?php endif; ?>
					</div>

					<div class="cc-camp-progress">
						<p class="cc-camp-progress-head">
							<span class="cc-camp-progress-label"><?php echo esc_html( $cc_prog['label'] ); ?></span>
							<?php if ( '' !== $cc_prog['percent'] ) : ?>
								<span class="cc-camp-progress-pct"><?php echo esc_html( $cc_prog['percent'] ); ?>%</span>
							<?php endif; ?>
						</p>
						<?php $cc_pct = '' === $cc_prog['percent'] ? 0 : (int) $cc_prog['percent']; ?>
						<div class="cc-bar" role="progressbar"
							aria-valuenow="<?php echo esc_attr( $cc_pct ); ?>"
							aria-valuemin="0" aria-valuemax="100"
							aria-label="<?php esc_attr_e( 'Cards written', 'cardsco' ); ?>">
							<?php if ( $cc_pct > 0 ) : ?>
								<span class="cc-bar-fill" style="width:<?php echo esc_attr( $cc_pct ); ?>%"></span>
							<?php endif; ?>
						</div>
						<p class="cc-camp-progress-note"><?php echo esc_html( $cc_prog['note'] ); ?></p>
					</div>

					<a class="cc-btn cc-btn-small<?php echo $cc_act['primary'] ? ' cc-btn-primary' : ' cc-btn-outline'; ?>"
						href="<?php echo esc_url( $cc_act['url'] ); ?>">
						<span><?php echo esc_html( $cc_act['label'] ); ?></span>
					</a>

					<?php
					/*
					 * The frame puts a 16px "more" control at the end of every
					 * row. There is nothing behind it yet — the actions it
					 * would hold (duplicate, cancel, export) are not built —
					 * so it links to the campaign rather than opening an empty
					 * menu, and is labelled for what it does today.
					 */
					?>
					<a class="cc-camp-more" href="<?php echo esc_url( $cc_act['url'] ); ?>"
						aria-label="<?php
						/* translators: %s: the campaign's name. */
						echo esc_attr( sprintf( __( 'Open %s', 'cardsco' ), $cc_row['name'] ) );
						?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'dots', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</a>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $finished ) : ?>
		<section class="cc-finished">
			<p class="cc-finished-head">
				<span class="cc-finished-title"><?php esc_html_e( 'Recently finished', 'cardsco' ); ?></span>
				<?php if ( $finished_total > count( $finished ) ) : ?>
					<a class="cc-link" href="<?php echo esc_url( $links['past'] ); ?>">
						<?php
						printf(
							/* translators: %s: how many finished campaigns there are. */
							esc_html__( 'See all %s', 'cardsco' ),
							esc_html( number_format_i18n( $finished_total ) )
						);
						?>
					</a>
				<?php endif; ?>
			</p>

			<div class="cc-table-wrap">
				<table class="cc-table">
					<thead>
						<tr>
							<th scope="col" class="cc-col-name"><?php esc_html_e( 'Campaign', 'cardsco' ); ?></th>
							<th scope="col" class="cc-col-aud"><?php esc_html_e( 'Address', 'cardsco' ); ?></th>
							<th scope="col" class="cc-col-num"><?php esc_html_e( 'Cards', 'cardsco' ); ?></th>
							<th scope="col" class="cc-col-when"><?php esc_html_e( 'Finished', 'cardsco' ); ?></th>
							<th scope="col" class="cc-col-status"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $finished as $cc_f ) : ?>
							<tr>
								<td class="cc-col-name"><?php echo esc_html( $cc_f['name'] ); ?></td>
								<td class="cc-col-aud"><?php echo esc_html( $cc_f['audience'] ); ?></td>
								<td class="cc-col-num"><?php echo esc_html( number_format_i18n( $cc_f['cards'] ) ); ?></td>
								<td class="cc-col-when">
									<?php
									printf(
										/* translators: %s: the date it was delivered. */
										esc_html__( 'Delivered %s', 'cardsco' ),
										esc_html( $cc_f['finished'] ? gmdate( 'j M', strtotime( $cc_f['finished'] ) ) : '—' )
									);
									?>
								</td>
								<td class="cc-col-status">
									<span class="cc-badge is-success">
										<span class="cc-badge-dot" aria-hidden="true"></span>
										<?php esc_html_e( 'Delivered', 'cardsco' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>
</div>
