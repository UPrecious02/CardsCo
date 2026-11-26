<?php
/**
 * Twenty-five or more cards, posted to individual addresses.
 *
 * Neither of the two products: the volume of a bulk order with the
 * postage of a single one. It is priced case by case, so rather than
 * charging one rate or the other and sorting it out afterwards, the
 * customer is pointed at CardsCo with the details already written out.
 *
 * Drawn on the shipping step, on the review step and at the till, from
 * here, so the three cannot say three different things.
 *
 * @package CardsCo
 *
 * @var array $referral From CardsCo_Programme::referral().
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="cc-referral" role="note">
	<div class="cc-referral-mark" aria-hidden="true">
		<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'mail', 'cc-referral-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
	</div>
	<div class="cc-referral-say">
		<p class="cc-referral-title"><?php echo esc_html( $referral['title'] ); ?></p>
		<p class="cc-referral-body"><?php echo esc_html( $referral['body'] ); ?></p>
		<p class="cc-referral-do">
			<a class="cc-btn cc-btn-primary" href="<?php echo esc_url( $referral['mailto'] ); ?>">
				<?php echo esc_html( $referral['action'] ); ?>
			</a>
			<?php
			/*
			 * The address in plain sight as well as behind the button. A
			 * mailto does nothing on a machine with no mail client set up,
			 * and a dead button with no address beside it is a dead end.
			 */
			?>
			<span class="cc-referral-addr">
				<?php
				printf(
					/* translators: %s: an email address. */
					esc_html__( 'or write to %s', 'cardsco' ),
					'<a href="' . esc_url( 'mailto:' . $referral['email'] ) . '">' . esc_html( $referral['email'] ) . '</a>'
				);
				?>
			</span>
		</p>
	</div>
</div>
