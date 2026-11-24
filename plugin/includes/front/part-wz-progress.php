<?php
/**
 * The wizard stepper's phone controls.
 *
 * On a wide screen the seven steps sit across the top bar and this file
 * draws nothing anyone can see. On a phone those seven wrap to three lines
 * of chips above every screen, so the chips are folded away behind this
 * button and a progress bar takes their place: one line that says where you
 * are, tapped to open the full list and jump back to a finished step.
 *
 * It is included by both wizards — the occasion set-up and the campaign
 * builder — which share the .cc-wz chrome. The step list itself stays in
 * each view, because what counts as a finished step differs between them.
 *
 * Emitted before the <ol> it controls and placed after it by order, so the
 * button comes first in the markup for the keyboard and the bar reads last
 * on the screen.
 *
 * @package CardsCo
 *
 * @var array $steps    Step number => spec, with a 'label'.
 * @var int   $step     The step on screen.
 * @var bool  $finished Whether the flow is already done.
 */

defined( 'ABSPATH' ) || exit;

$cc_total = max( 1, count( $steps ) );
$cc_at    = $finished ? $cc_total : max( 1, min( $cc_total, (int) $step ) );
$cc_label = (string) ( $steps[ $step ]['label'] ?? '' );
?>
<button type="button" class="cc-wz-steps-toggle" data-cc-steps
	aria-controls="ccWzSteps" aria-expanded="false">
	<span class="cc-wz-steps-now">
		<?php
		if ( $finished ) {
			esc_html_e( 'All steps done', 'cardsco' );
		} else {
			printf(
				/* translators: 1: this step's number, 2: how many steps there are, 3: the step's name. */
				esc_html__( 'Step %1$s of %2$s · %3$s', 'cardsco' ),
				esc_html( number_format_i18n( $cc_at ) ),
				esc_html( number_format_i18n( $cc_total ) ),
				esc_html( $cc_label )
			);
		}
		?>
	</span>
	<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'chevron-down', 'cc-wz-steps-chev' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
</button>

<?php
/*
 * Width in a style attribute because it is data, not design: the fraction
 * changes with every step and there is no sane way to express seven of them
 * in a stylesheet. aria-hidden because the button beside it already says
 * the same thing in words.
 */
?>
<div class="cc-wz-bar" aria-hidden="true">
	<span style="width: <?php echo esc_attr( round( $cc_at / $cc_total * 100, 2 ) ); ?>%;"></span>
</div>
