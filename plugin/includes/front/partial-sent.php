<?php
/**
 * "Your card is on its way" — shown on the order-received page.
 *
 * From the last frame. It says what happens next in terms of the card and
 * the person it is going to, not the order number: somebody who has just
 * sent one card to one friend is not thinking about order references.
 *
 * The offer of an account sits underneath, and only for somebody who does
 * not already have one. This is the one moment a person who has bought a
 * single card has a reason to want one — they have just typed an address
 * and a message they might want again.
 *
 * @package CardsCo
 *
 * @var array  $sent          Who the cards are going to.
 * @var bool   $offer_account Whether to offer one.
 * @var object $order         WC_Order.
 */

defined( 'ABSPATH' ) || exit;

$cc_first = CardsCo_Send::first_name( $sent[0]['to'] );
?>
<section class="cc cc-sent">
	<h2 class="cc-sent-title">
		<?php
		echo esc_html(
			count( $sent ) > 1
				? __( 'Your cards are on their way', 'cardsco' )
				: __( 'Your card is on its way', 'cardsco' )
		);
		?>
	</h2>

	<?php foreach ( $sent as $cc_one ) : ?>
		<p class="cc-sent-line">
			<?php
			printf(
				/* translators: 1: a first name, 2: an address. */
				esc_html__( 'We’ll write %1$s’s card and post it to %2$s.', 'cardsco' ),
				esc_html( CardsCo_Send::first_name( $cc_one['to'] ) ),
				esc_html( $cc_one['address'] )
			);
			?>
		</p>
	<?php endforeach; ?>

	<p class="cc-sent-note"><?php esc_html_e( 'We’ll email you when it’s posted.', 'cardsco' ); ?></p>

	<?php if ( $offer_account ) : ?>
		<div class="cc-sent-account">
			<h3 class="cc-sent-account-head"><?php esc_html_e( 'Want to keep track of it?', 'cardsco' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: their email address. */
					esc_html__( 'Set a password for %s and you can see when it’s written and posted, send another in a couple of clicks, and set up cards that go out on their own.', 'cardsco' ),
					esc_html( $order->get_billing_email() )
				);
				?>
			</p>
			<form class="cc-sent-form" method="post" action="<?php echo esc_url( $order->get_checkout_order_received_url() ); ?>">
				<?php wp_nonce_field( 'cardsco_claim_' . $order->get_id(), '_cardsco_nonce' ); ?>
				<input type="hidden" name="cardsco_send_action" value="claim" />
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
				<?php
				/*
				 * The order key, which WooCommerce put in the address of
				 * this page. It is what proves this is the person who
				 * placed the order rather than somebody guessing an order
				 * number, so the account can only ever be made by whoever
				 * holds the link.
				 */
				?>
				<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>" />

				<p class="cc-field">
					<label for="cc-claim-pass"><?php esc_html_e( 'Choose a password', 'cardsco' ); ?></label>
					<input type="password" id="cc-claim-pass" name="password" autocomplete="new-password" minlength="8" required />
					<span class="cc-field-help"><?php esc_html_e( 'At least 8 characters.', 'cardsco' ); ?></span>
				</p>

				<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8">
					<span><?php esc_html_e( 'Create my account', 'cardsco' ); ?></span>
				</button>
			</form>
		</div>
	<?php endif; ?>

	<p class="cc-sent-go">
		<a class="cc-btn cc-btn-quiet" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Back to CardsCo', 'cardsco' ); ?>
		</a>
	</p>
</section>
