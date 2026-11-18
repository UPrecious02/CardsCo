<?php
/**
 * Sending one card.
 *
 * Built from the Sending Card frames, minus the signed-in shell around
 * them: those frames sit inside the app, with Drafts and Sent cards down
 * the side, and this page has to work for somebody who has never signed
 * in. What is kept is the centre of each frame — the 500-wide panel the
 * work actually happens in — on a plain page.
 *
 * @package CardsCo
 *
 * @var array  $draft From CardsCo_Send::draft().
 * @var int    $step  Which step is on screen.
 * @var array  $cards From CardsCo_Catalogue::page().
 * @var array  $order Card price and total. Postage is inside the price.
 * @var string $here  This page.
 * @var string $error Anything to tell them.
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';

$cc_steps = array(
	1 => __( 'Who it’s for', 'cardsco' ),
	2 => __( 'Your message', 'cardsco' ),
	3 => __( 'The card', 'cardsco' ),
	4 => __( 'Your order', 'cardsco' ),
);
?>
<div class="cc cc-send">

	<ol class="cc-send-steps">
		<?php foreach ( $cc_steps as $cc_n => $cc_label ) : ?>
			<?php
			$cc_state = '';

			if ( $cc_n === $step ) {
				$cc_state = ' is-on';
			} elseif ( $cc_n < $step || $cc_n <= (int) $draft['reached'] ) {
				$cc_state = ' is-done';
			}
			?>
			<li class="cc-send-step<?php echo esc_attr( $cc_state ); ?>">
				<?php if ( $cc_n < $step ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'step', $cc_n, $here ) ); ?>">
						<span class="cc-send-step-n"><?php echo esc_html( number_format_i18n( $cc_n ) ); ?></span>
						<span><?php echo esc_html( $cc_label ); ?></span>
					</a>
				<?php else : ?>
					<span class="cc-send-step-n"><?php echo esc_html( number_format_i18n( $cc_n ) ); ?></span>
					<span><?php echo esc_html( $cc_label ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php if ( $error ) : ?>
		<p class="cc-send-error" role="alert"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<form class="cc-send-panel" method="post" action="<?php echo esc_url( $here ); ?>" id="ccSendForm">
		<?php wp_nonce_field( 'cardsco_send', '_cardsco_nonce' ); ?>
		<input type="hidden" name="cardsco_send_action" value="step" />
		<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />

		<?php if ( 1 === $step ) : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'Who’s it for?', 'cardsco' ); ?></h1>
			<p class="cc-send-sub"><?php esc_html_e( 'We’ll post the card straight to this address.', 'cardsco' ); ?></p>

			<div class="cc-send-fields">
				<p class="cc-field">
					<label for="cc-send-name"><?php esc_html_e( 'Full name', 'cardsco' ); ?></label>
					<input type="text" id="cc-send-name" name="name" value="<?php echo esc_attr( $draft['name'] ); ?>" autocomplete="name" required />
				</p>
				<p class="cc-field">
					<label for="cc-send-company"><?php esc_html_e( 'Company', 'cardsco' ); ?> <span class="cc-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
					<input type="text" id="cc-send-company" name="company" value="<?php echo esc_attr( $draft['company'] ); ?>" autocomplete="organization" />
				</p>
				<p class="cc-field cc-field-wide">
					<label for="cc-send-line1"><?php esc_html_e( 'Address line 1', 'cardsco' ); ?></label>
					<input type="text" id="cc-send-line1" name="line1" value="<?php echo esc_attr( $draft['line1'] ); ?>" autocomplete="address-line1" required />
				</p>
				<p class="cc-field cc-field-wide">
					<label for="cc-send-line2"><?php esc_html_e( 'Address line 2', 'cardsco' ); ?> <span class="cc-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
					<input type="text" id="cc-send-line2" name="line2" value="<?php echo esc_attr( $draft['line2'] ); ?>" autocomplete="address-line2" />
				</p>
				<p class="cc-field">
					<label for="cc-send-town"><?php esc_html_e( 'Town or city', 'cardsco' ); ?></label>
					<input type="text" id="cc-send-town" name="town" value="<?php echo esc_attr( $draft['town'] ); ?>" autocomplete="address-level2" required />
				</p>
				<p class="cc-field">
					<label for="cc-send-postcode"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
					<input type="text" id="cc-send-postcode" name="postcode" value="<?php echo esc_attr( $draft['postcode'] ); ?>" autocomplete="postal-code" required />
				</p>
			</div>

		<?php elseif ( 2 === $step ) : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'What would you like to say?', 'cardsco' ); ?></h1>
			<p class="cc-send-sub">
				<?php
				printf(
					/* translators: %s: the recipient's first name. */
					esc_html__( 'Write it in your own words. A pen writes it in real ink and we post it to %s.', 'cardsco' ),
					esc_html( CardsCo_Send::first_name( $draft['name'] ) )
				);
				?>
			</p>

			<?php
			$cc_starters = CardsCo_Send::starters( 6 );

			if ( $cc_starters ) :
				?>
				<div class="cc-send-starters">
					<p class="cc-send-starters-head"><?php esc_html_e( 'Write your message, or start from one of ours', 'cardsco' ); ?></p>
					<div class="cc-send-chips">
						<?php foreach ( $cc_starters as $cc_one ) : ?>
							<button type="button" class="cc-send-chip" data-cc-fill="<?php echo esc_attr( $cc_one['text'] ); ?>">
								<?php echo esc_html( $cc_one['label'] ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<p class="cc-field cc-field-wide">
				<label class="cc-sr" for="cc-send-message"><?php esc_html_e( 'Your message', 'cardsco' ); ?></label>
				<textarea id="cc-send-message" name="message" rows="6" data-cc-text required><?php echo esc_textarea( $draft['message'] ); ?></textarea>
				<span class="cc-send-count" data-cc-count></span>
			</p>

			<p class="cc-field">
				<label for="cc-send-signature"><?php esc_html_e( 'Sign it', 'cardsco' ); ?> <span class="cc-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
				<input type="text" id="cc-send-signature" name="signature" value="<?php echo esc_attr( $draft['signature'] ); ?>" placeholder="<?php esc_attr_e( 'The Acme team', 'cardsco' ); ?>" />
			</p>

		<?php elseif ( 3 === $step ) : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'Pick a card', 'cardsco' ); ?></h1>
			<p class="cc-send-sub"><?php esc_html_e( 'Every one is printed on heavy card and written by hand.', 'cardsco' ); ?></p>

			<?php if ( empty( $cards['tiles'] ) ) : ?>
				<p class="cc-send-none"><?php esc_html_e( 'There are no cards to choose from yet.', 'cardsco' ); ?></p>
			<?php else : ?>
				<div class="cc-send-cards">
					<?php foreach ( $cards['tiles'] as $cc_tile ) : ?>
						<label class="cc-send-card<?php echo (int) $draft['card_id'] === (int) $cc_tile['id'] ? ' is-on' : ''; ?>">
							<input type="radio" name="card_id" value="<?php echo esc_attr( (int) $cc_tile['id'] ); ?>"
								<?php checked( (int) $draft['card_id'], (int) $cc_tile['id'] ); ?> required />
							<?php if ( ! empty( $cc_tile['image'] ) ) : ?>
								<img src="<?php echo esc_url( $cc_tile['image'] ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
							<span class="cc-send-card-name"><?php echo esc_html( $cc_tile['title'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php else : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'Your order', 'cardsco' ); ?></h1>
			<p class="cc-send-sub"><?php esc_html_e( 'Check the total, then pay on our secure payment page.', 'cardsco' ); ?></p>

			<dl class="cc-send-sum">
				<div class="cc-send-sum-row">
					<dt><?php esc_html_e( 'To', 'cardsco' ); ?></dt>
					<dd>
						<?php echo esc_html( $draft['name'] ); ?>
						<span class="cc-send-sum-quiet"><?php echo esc_html( CardsCo_Send::address_line( $draft ) ); ?></span>
					</dd>
				</div>
				<div class="cc-send-sum-row">
					<dt><?php esc_html_e( 'Message', 'cardsco' ); ?></dt>
					<dd class="cc-send-sum-msg"><?php echo esc_html( $draft['message'] ); ?></dd>
				</div>
			</dl>

			<div class="cc-send-lines">
				<p class="cc-send-line">
					<span><?php esc_html_e( '1 card', 'cardsco' ); ?></span>
					<span><?php echo esc_html( call_user_func( array( $cc, 'money' ), $order['card'], 'GBP' ) ); ?></span>
				</p>

				<p class="cc-send-note">
					<?php esc_html_e( 'Postage and envelope included — Royal Mail 1st class.', 'cardsco' ); ?>
				</p>

				<p class="cc-send-line cc-send-total">
					<span><?php esc_html_e( 'Total to pay', 'cardsco' ); ?></span>
					<span><?php echo esc_html( call_user_func( array( $cc, 'money' ), $order['total'], 'GBP' ) ); ?></span>
				</p>
			</div>

			<p class="cc-send-note">
				<?php esc_html_e( 'You’ll leave CardsCo to pay securely. Once payment goes through, we bring you back here and start writing.', 'cardsco' ); ?>
			</p>

		<?php endif; ?>

		<footer class="cc-send-foot">
			<?php if ( $step > 1 ) : ?>
				<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( add_query_arg( 'step', $step - 1, $here ) ); ?>">
					<span><?php esc_html_e( 'Back', 'cardsco' ); ?></span>
				</a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>

			<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8">
				<span>
					<?php
					echo esc_html(
						CardsCo_Send::STEPS === $step
							? __( 'Go to payment', 'cardsco' )
							: __( 'Continue', 'cardsco' )
					);
					?>
				</span>
			</button>
		</footer>
	</form>
</div>
