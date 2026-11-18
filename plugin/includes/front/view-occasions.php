<?php
/**
 * Occasions.
 *
 * Built against frame 253:56252: a header with a "Set up an occasion"
 * button, then two columns — the six occasion categories at 776 wide on the
 * left, and a 320-wide "Coming up" panel on the right.
 *
 * @package CardsCo
 *
 * @var array[] $cards
 * @var array[] $upcoming
 * @var array   $links
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';
?>
<div class="cc cc-main">

	<header class="cc-page-head cc-page-head-row">
		<div class="cc-page-head-text">
			<h1 class="cc-page-title"><?php esc_html_e( 'Occasions', 'cardsco' ); ?></h1>
			<p class="cc-page-sub"><?php esc_html_e( 'Cards that go out automatically, every time the date comes round.', 'cardsco' ); ?></p>
		</div>
		<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( $links['setup'] ); ?>">
			<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			<span><?php esc_html_e( 'Set up an occasion', 'cardsco' ); ?></span>
		</a>
	</header>

	<div class="cc-occ-cols">
		<div class="cc-occ-list">
			<?php foreach ( $cards as $cc_occ ) : ?>
				<article class="cc-occ<?php echo $cc_occ['coming_soon'] ? ' is-soon' : ''; ?>">
					<div class="cc-occ-art">
						<?php if ( $cc_occ['art']['image'] ) : ?>
							<img src="<?php echo esc_url( $cc_occ['art']['image'] ); ?>" alt="" />
						<?php else : ?>
							<span class="cc-occ-art-blank" aria-hidden="true"></span>
						<?php endif; ?>
					</div>

					<div class="cc-occ-body">
						<p class="cc-occ-head">
							<span class="cc-occ-name"><?php echo esc_html( $cc_occ['label'] ); ?></span>
							<?php if ( $cc_occ['coming_soon'] ) : ?>
								<span class="cc-pill is-neutral"><?php esc_html_e( 'Coming soon', 'cardsco' ); ?></span>
							<?php elseif ( $cc_occ['active'] ) : ?>
								<span class="cc-pill is-success"><?php esc_html_e( 'Active', 'cardsco' ); ?></span>
							<?php elseif ( ! empty( $cc_occ['draft'] ) ) : ?>
								<span class="cc-pill is-draft"><?php esc_html_e( 'Draft', 'cardsco' ); ?></span>
							<?php endif; ?>
						</p>
						<p class="cc-occ-blurb"><?php echo esc_html( $cc_occ['blurb'] ); ?></p>
						<p class="cc-occ-stat<?php echo $cc_occ['stat_strong'] ? ' is-strong' : ''; ?>">
							<?php echo esc_html( $cc_occ['stat'] ); ?>
						</p>
					</div>

					<?php
					/*
					 * The frame gives a coming-soon row no button at all,
					 * rather than a disabled one.
					 */
					?>
					<?php if ( ! $cc_occ['coming_soon'] ) : ?>
						<?php
						/*
						 * A draft is picked up where it was left, so the button
						 * says so and is filled in rather than outlined — it is
						 * the one thing on this row somebody already started.
						 */
						if ( $cc_occ['active'] ) {
							$cc_label = __( 'Manage', 'cardsco' );
							$cc_class = 'cc-btn-outline';
						} elseif ( ! empty( $cc_occ['draft'] ) ) {
							$cc_label = __( 'Continue', 'cardsco' );
							$cc_class = 'cc-btn-primary';
						} else {
							$cc_label = __( 'Set up', 'cardsco' );
							$cc_class = 'cc-btn-outline';
						}

						/*
						 * A draft opens at the step it reached. Everything
						 * else starts a new one for that category.
						 */
						if ( ! $cc_occ['active'] && ! empty( $cc_occ['draft_id'] ) ) {
							$cc_href = add_query_arg(
								array(
									CardsCo_Setup::ARG => (int) $cc_occ['draft_id'],
									'step'             => max( 1, (int) $cc_occ['draft_step'] ),
								),
								$links['setup']
							);
						} else {
							$cc_href = add_query_arg(
								'occasion',
								$cc_occ['slug'],
								$cc_occ['active'] ? $links['manage'] : $links['setup']
							);
						}
						?>
						<a class="cc-btn <?php echo esc_attr( $cc_class ); ?> cc-occ-btn"
							href="<?php echo esc_url( $cc_href ); ?>">
							<span><?php echo esc_html( $cc_label ); ?></span>
						</a>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>

		<aside class="cc-coming">
			<p class="cc-coming-head">
				<span class="cc-coming-title"><?php esc_html_e( 'Coming up', 'cardsco' ); ?></span>
				<span class="cc-coming-when"><?php esc_html_e( 'Next 30 days', 'cardsco' ); ?></span>
			</p>

			<?php if ( ! $upcoming ) : ?>
				<p class="cc-coming-none"><?php esc_html_e( 'Nothing in the next 30 days.', 'cardsco' ); ?></p>
			<?php else : ?>
				<?php foreach ( $upcoming as $cc_up ) : ?>
					<div class="cc-up">
						<span class="cc-up-date">
							<span class="cc-up-day"><?php echo esc_html( $cc_up['day'] ); ?></span>
							<span class="cc-up-month"><?php echo esc_html( $cc_up['month'] ); ?></span>
						</span>
						<span class="cc-up-text">
							<span class="cc-up-who"><?php echo esc_html( $cc_up['who'] ); ?></span>
							<span class="cc-up-what"><?php echo esc_html( $cc_up['what'] ); ?></span>
						</span>
						<span class="cc-pill is-<?php echo esc_attr( $cc_up['tone'] ); ?>"><?php echo esc_html( $cc_up['status'] ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<a class="cc-coming-all" href="<?php echo esc_url( $links['all_dates'] ); ?>">
				<span><?php esc_html_e( 'See all dates', 'cardsco' ); ?></span>
				<?php echo call_user_func( array( $cc, 'icon' ), 'arrow-right', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</a>
		</aside>
	</div>
</div>
