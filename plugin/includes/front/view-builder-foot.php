<?php
/**
 * The builder's footer bar, shared by every step.
 *
 * Back is a link rather than a button so it never submits and so it never
 * saves: going back to look at something should not write anything.
 *
 * The classes here are the ones the occasion wizard already uses. They were
 * cc-btn-go and cc-btn-quiet, names invented for this footer and never
 * written into the stylesheet, so every button on every builder step drew
 * as a bare .cc-btn: Back, Save and exit and Continue all identically grey,
 * with nothing on the screen showing which one was the way forward.
 *
 * @package CardsCo
 *
 * @var array $campaign
 * @var int   $step
 * @var array $steps
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cc-wz-foot">
	<?php if ( $step > 1 ) : ?>
		<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( add_query_arg( 'step', $step - 1, $links['here'] ) ); ?>">
			<?php esc_html_e( 'Back', 'cardsco' ); ?>
		</a>
	<?php else : ?>
		<span></span>
	<?php endif; ?>

	<div class="cc-wz-foot-go">
		<button type="submit" class="cc-btn cc-btn-ghost" name="save_exit" value="1">
			<?php esc_html_e( 'Save and exit', 'cardsco' ); ?>
		</button>
		<button type="submit" class="cc-btn cc-btn-primary">
			<?php echo esc_html( $step < count( $steps ) ? __( 'Continue', 'cardsco' ) : __( 'Review', 'cardsco' ) ); ?>
		</button>
	</div>
</div>
