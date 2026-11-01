<?php
/**
 * Back and Continue, shared by the steps that save a form.
 *
 * Expects $step, $cardsco_pid and $cardsco_step_url to be in scope.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cardsco-wiz-foot">
	<?php if ( (int) $step > 2 ) : ?>
		<a class="button" href="<?php echo esc_url( $cardsco_step_url( (int) $step - 1 ) ); ?>"><?php esc_html_e( 'Back', 'cardsco' ); ?></a>
	<?php else : ?>
		<span></span>
	<?php endif; ?>

	<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'cardsco' ); ?></button>
</div>
