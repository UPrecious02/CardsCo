<?php
/**
 * Campaign step 7 — review, then pay.
 *
 * Nothing is scheduled here. The button goes to the checkout, and the
 * campaign is scheduled on the back of the paid order, so a campaign that
 * was never paid for never goes out.
 *
 * @package CardsCo
 *
 * @var array        $campaign
 * @var array        $audience
 * @var array        $preview
 * @var array        $summary
 * @var WP_Error|true $ready
 * @var array|null   $referral From CardsCo_Campaign::referral(), when it applies.
 * @var array        $links
 */

defined( 'ABSPATH' ) || exit;

$cc       = 'CardsCo_Shortcodes';
$cc_stop  = is_wp_error( $ready ) ? $ready->get_error_message() : '';
$cc_money = static function ( $amount ) use ( $campaign, $cc ) {
	return call_user_func( array( $cc, 'money' ), $amount, $campaign['currency'] ? $campaign['currency'] : 'GBP' );
};
?>
<div class="cc-bd-review">

	<section class="cc-bd-sum">
		<h2><?php esc_html_e( 'What is going out', 'cardsco' ); ?></h2>
		<dl>
			<?php foreach ( $summary as $cc_row ) : ?>
				<div class="cc-bd-sum-row">
					<dt><?php echo esc_html( $cc_row['label'] ); ?></dt>
					<dd>
						<?php echo esc_html( $cc_row['value'] ); ?>
						<?php if ( ! empty( $cc_row['step'] ) ) : ?>
							<a class="cc-bd-change" href="<?php echo esc_url( add_query_arg( 'step', (int) $cc_row['step'], $links['here'] ) ); ?>">
								<?php esc_html_e( 'Change', 'cardsco' ); ?>
							</a>
						<?php endif; ?>
					</dd>
				</div>
			<?php endforeach; ?>
		</dl>
	</section>

	<?php if ( ! empty( $preview['text'] ) || ! empty( $preview['problem'] ) ) : ?>
		<section class="cc-bd-preview">
			<h2><?php esc_html_e( 'How one of them reads', 'cardsco' ); ?></h2>
			<?php $cc_count = count( CardsCo_Campaign::messages( $campaign ) ); ?>
			<?php if ( $cc_count > 1 ) : ?>
				<p class="cc-bd-hint">
					<?php
					/* translators: %d: how many messages there are. */
					printf( esc_html__( 'This is the first of your %d messages.', 'cardsco' ), (int) $cc_count );
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $preview['text'] ) ) : ?>
				<div class="cc-bd-paper">
					<?php foreach ( preg_split( '/\R+/', (string) $preview['text'] ) as $cc_line ) : ?>
						<?php if ( '' !== trim( $cc_line ) ) : ?>
							<p><?php echo esc_html( $cc_line ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $preview['problem'] ) ) : ?>
				<p class="cc-bd-warn" role="alert"><?php echo esc_html( $preview['problem'] ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $referral ) && 'draft' === (string) $campaign['status'] ) : ?>
		<?php
		/*
		 * Twenty-five or more, each posted to its own address: not sold
		 * here, so there is no price to show and nothing to pay. The same
		 * panel an occasion shows, from the same file.
		 */
		?>
		<section class="cc-bd-pay cc-bd-pay-refer">
			<div class="cc-bd-pay-line">
				<span><?php esc_html_e( 'Cards', 'cardsco' ); ?></span>
				<span><?php echo esc_html( number_format_i18n( count( $audience['ids'] ) ) ); ?></span>
			</div>
			<?php require CARDSCO_PATH . 'includes/front/partial-referral.php'; ?>
		</section>
	<?php else : ?>
	<section class="cc-bd-pay">
		<div class="cc-bd-pay-line">
			<span><?php esc_html_e( 'Cards', 'cardsco' ); ?></span>
			<span><?php echo esc_html( number_format_i18n( count( $audience['ids'] ) ) ); ?></span>
		</div>
		<div class="cc-bd-pay-line">
			<span><?php esc_html_e( 'Each', 'cardsco' ); ?></span>
			<span><?php echo esc_html( $cc_money( CardsCo_Campaign::unit_price( $campaign ) ) ); ?></span>
		</div>
		<div class="cc-bd-pay-line cc-bd-pay-total">
			<span><?php esc_html_e( 'Total', 'cardsco' ); ?></span>
			<span><?php echo esc_html( $cc_money( $campaign['total_amount'] ) ); ?></span>
		</div>
		<p class="cc-bd-note"><?php esc_html_e( 'Postage and envelopes are included.', 'cardsco' ); ?></p>

		<?php if ( $cc_stop ) : ?>
			<p class="cc-bd-warn" role="alert"><?php echo esc_html( $cc_stop ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $links['here'] ); ?>">
			<?php cardsco_nonce_field( 'cardsco_builder' ); ?>
			<input type="hidden" name="cardsco_builder_action" value="pay" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />
			<button type="submit" class="cc-btn cc-btn-primary cc-bd-paybtn"<?php disabled( (bool) $cc_stop ); ?>>
				<?php esc_html_e( 'Pay and schedule', 'cardsco' ); ?>
			</button>
		</form>

		<p class="cc-bd-note">
			<?php esc_html_e( 'Nothing is scheduled until the payment goes through.', 'cardsco' ); ?>
		</p>
	</section>
	<?php endif; ?>
</div>

<div class="cc-wz-foot">
	<a class="cc-btn cc-btn-quiet" href="<?php echo esc_url( add_query_arg( 'step', 6, $links['here'] ) ); ?>">
		<?php esc_html_e( 'Back', 'cardsco' ); ?>
	</a>
	<a class="cc-btn cc-btn-quiet" href="<?php echo esc_url( $links['exit'] ); ?>">
		<?php esc_html_e( 'Save and exit', 'cardsco' ); ?>
	</a>
</div>
