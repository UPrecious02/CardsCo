<?php
/**
 * The Done screen — the occasion is on.
 *
 * Measured from the Done frame: a 952x394 panel at y=213, a 44px paid mark
 * at x=89, then upcoming rows 902 wide and 54 tall on a 70 pitch, and a
 * warning strip at the foot when somebody still has no date.
 *
 * No Continue in the footer: the only way on from here is back to the
 * occasions list, which is why that button sits on the left on its own.
 *
 * @package CardsCo
 *
 * @var array $done      From CardsCo_Dashboard::setup_done().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cc-done">

	<div class="cc-done-paid">
		<span class="cc-done-mark" aria-hidden="true">
			<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-done-mark-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</span>
		<span class="cc-done-paid-text">
			<span class="cc-done-paid-head"><?php echo esc_html( $done['paid'] ); ?></span>
			<?php if ( $done['receipt'] ) : ?>
				<span class="cc-done-paid-sub"><?php echo esc_html( $done['receipt'] ); ?></span>
			<?php endif; ?>
		</span>
	</div>

	<p class="cc-done-title"><?php esc_html_e( 'Coming up', 'cardsco' ); ?></p>

	<?php if ( ! $done['coming'] ) : ?>

		<p class="cc-done-none">
			<?php esc_html_e( 'Nothing due yet. Dates will appear here as soon as they are in range.', 'cardsco' ); ?>
		</p>

	<?php else : ?>

		<ul class="cc-done-list">
			<?php foreach ( $done['coming'] as $cc_up ) : ?>
				<li class="cc-done-row">
					<span class="cc-done-date">
						<span class="cc-done-day"><?php echo esc_html( $cc_up['day'] ); ?></span>
						<span class="cc-done-month"><?php echo esc_html( $cc_up['month'] ); ?></span>
					</span>

					<span class="cc-done-who">
						<span class="cc-done-name"><?php echo esc_html( $cc_up['name'] ); ?></span>
						<span class="cc-done-detail"><?php echo esc_html( $cc_up['detail'] ); ?></span>
					</span>

					<span class="cc-pill <?php echo 'out' === $cc_up['state'] ? 'is-neutral' : 'is-info'; ?>">
						<?php echo esc_html( $cc_up['status'] ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>

	<?php if ( $done['warning'] ) : ?>
		<p class="cc-done-warn">
			<span class="cc-done-warn-icon" aria-hidden="true">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'alert', 'cc-done-warn-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</span>
			<span><?php echo esc_html( $done['warning'] ); ?></span>
		</p>
	<?php endif; ?>
</div>
