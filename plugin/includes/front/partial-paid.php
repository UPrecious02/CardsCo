<?php
/**
 * "Your occasion is on" — shown on the order-received page.
 *
 * The way back into the portal after paying. WooCommerce's own receipt
 * says an order was received and nothing about the thing being set up,
 * and leaves no route back to it.
 *
 * @package CardsCo
 *
 * @var string $title
 * @var string $blurb
 * @var string $back  Where to return to, or '' if that page is not published.
 * @var string $label What to call the way back.
 * @var string $dash  The dashboard, or ''.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="cc-paid">
	<h2 class="cc-paid-head"><?php echo esc_html( $title ); ?></h2>
	<p class="cc-paid-blurb"><?php echo esc_html( $blurb ); ?></p>

	<?php if ( $back || $dash ) : ?>
		<p class="cc-paid-go">
			<?php if ( $back ) : ?>
				<a class="cc-btn cc-btn-primary" href="<?php echo esc_url( $back ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endif; ?>

			<?php if ( $dash ) : ?>
				<a class="cc-btn <?php echo $back ? 'cc-btn-ghost' : 'cc-btn-primary'; ?>" href="<?php echo esc_url( $dash ); ?>">
					<?php esc_html_e( 'Back to CardsCo', 'cardsco' ); ?>
				</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</section>
