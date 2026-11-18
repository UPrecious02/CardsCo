<?php
/**
 * Step 4 — the message.
 *
 * Measured from the step-4 frame. Not an editor: a 628x356 preview panel at
 * x=64 holding the card beside the written page, and a 300-wide summary card
 * at x=716 listing what the message is made of, with the way into the card
 * writer at the bottom of it.
 *
 * The writing is deliberately somewhere else. This screen's job is to let
 * somebody see what will land on the doormat before they commit to sending
 * forty of them.
 *
 * @package CardsCo
 *
 * @var array $message   From CardsCo_Dashboard::setup_message().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="4" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
</form>

<div class="cc-msg">

	<section class="cc-msg-preview">
		<div class="cc-msg-card">
			<?php if ( $message['card'] && $message['card']['image'] ) : ?>
				<img src="<?php echo esc_url( $message['card']['image'] ); ?>" alt="" />
			<?php else : ?>
				<span class="cc-msg-card-blank" aria-hidden="true"></span>
			<?php endif; ?>
		</div>

		<div class="cc-msg-paper">
			<?php if ( $message['lines'] ) : ?>
				<?php foreach ( $message['lines'] as $cc_line ) : ?>
					<p><?php echo esc_html( $cc_line ); ?></p>
				<?php endforeach; ?>
				<?php if ( $message['signature'] ) : ?>
					<p class="cc-msg-sign"><?php echo esc_html( $message['signature'] ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="cc-msg-none"><?php esc_html_e( 'Nothing written yet.', 'cardsco' ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<aside class="cc-msg-sum">
		<p class="cc-msg-sum-head"><?php esc_html_e( 'Message', 'cardsco' ); ?></p>

		<dl class="cc-msg-fields">
			<div class="cc-msg-field">
				<dt><?php esc_html_e( 'Opening', 'cardsco' ); ?></dt>
				<dd><?php echo esc_html( $message['opening'] ? $message['opening'] : '—' ); ?></dd>
			</div>
			<div class="cc-msg-field">
				<dt><?php esc_html_e( 'Handwriting', 'cardsco' ); ?></dt>
				<dd><?php echo esc_html( $message['hand'] ); ?></dd>
			</div>
			<div class="cc-msg-field">
				<dt><?php esc_html_e( 'Signed', 'cardsco' ); ?></dt>
				<dd><?php echo esc_html( $message['signature'] ? $message['signature'] : '—' ); ?></dd>
			</div>
			<div class="cc-msg-field">
				<dt><?php esc_html_e( 'Length', 'cardsco' ); ?></dt>
				<dd class="<?php echo $message['over'] ? 'cc-msg-over' : ''; ?>">
					<?php
					printf(
						/* translators: 1: characters used, 2: the most a card holds. */
						esc_html__( '%1$s of %2$s characters', 'cardsco' ),
						esc_html( number_format_i18n( $message['used'] ) ),
						esc_html( number_format_i18n( $message['max'] ) )
					);
					?>
				</dd>
			</div>
		</dl>

		<a class="cc-btn cc-btn-primary cc-btn-r8 cc-msg-write" href="<?php echo esc_url( $links['writer'] ); ?>">
			<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'pen', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			<span><?php esc_html_e( 'Edit in card writer', 'cardsco' ); ?></span>
		</a>

		<p class="cc-msg-note">
			<span class="cc-msg-note-icon" aria-hidden="true">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'info', 'cc-msg-note-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</span>
			<span>
				<?php if ( $message['for'] ) : ?>
					<?php
					printf(
						/* translators: %s: whose card the preview shows. */
						esc_html__( 'Preview shows %s’s card. Each card uses that person’s first name.', 'cardsco' ),
						esc_html( $message['for'] )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Each card uses that person’s first name.', 'cardsco' ); ?>
				<?php endif; ?>
			</span>
		</p>
	</aside>
</div>
