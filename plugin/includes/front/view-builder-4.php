<?php
/**
 * Campaign step 4 — the message.
 *
 * A starter can be picked or the wording typed. Unlike the occasion
 * wizard, the writing happens on this screen rather than behind a
 * separate writer: a campaign is one send with one message, so there is
 * nothing to preview per person that is worth a screen of its own.
 *
 * @package CardsCo
 *
 * @var array $campaign
 * @var array $messages
 * @var array $fields
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_max = (int) CardsCo_Settings::get( 'message_max', null, (int) $campaign['company_id'] );
$cc_max = $cc_max > 0 ? $cc_max : 400;
?>
<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_builder', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="4" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

	<?php if ( $messages ) : ?>
		<div class="cc-bd-starters">
			<p class="cc-bd-starters-head">
				<?php
				echo esc_html(
					! empty( $starters_are_general )
						? __( 'Start from one of ours — nothing written for this occasion yet, so here is everything', 'cardsco' )
						: __( 'Start from one of ours', 'cardsco' )
				);
				?>
			</p>
			<?php foreach ( $messages as $cc_msg ) : ?>
				<?php
				/*
				 * The wording lives in meta, not in post_content, which is
				 * empty on every one of these. Reading the wrong field drew
				 * the starters as a column of blank grey bars — buttons
				 * that looked broken rather than missing.
				 */
				$cc_body = (string) CardsCo_Message::text( $cc_msg->ID );
				?>
				<?php if ( '' !== trim( $cc_body ) ) : ?>
					<button type="button" class="cc-bd-starter" data-cc-fill="<?php echo esc_attr( $cc_body ); ?>">
						<?php echo esc_html( wp_trim_words( $cc_body, 14 ) ); ?>
					</button>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="cc-bd-field">
		<label for="cc-bd-msg"><?php esc_html_e( 'What should it say?', 'cardsco' ); ?></label>
		<textarea id="cc-bd-msg" name="message_body" rows="6" maxlength="<?php echo esc_attr( $cc_max ); ?>"
			data-cc-text required><?php echo esc_textarea( $campaign['message_body'] ); ?></textarea>
		<p class="cc-bd-hint">
			<?php
			printf(
				/* translators: %s: the longest a message may be. */
				esc_html__( 'Up to %s characters, written by hand.', 'cardsco' ),
				esc_html( number_format_i18n( $cc_max ) )
			);
			?>
		</p>
	</div>

	<?php if ( $fields ) : ?>
		<div class="cc-bd-merge">
			<?php /* Not "tap": most of these campaigns are built at a desk. */ ?>
			<p class="cc-bd-merge-head"><?php esc_html_e( 'Add one to your message', 'cardsco' ); ?></p>
			<div class="cc-bd-tags">
				<?php foreach ( $fields as $cc_key => $cc_field ) : ?>
					<?php
					/*
					 * Each field is an array of label, sample and note, not
					 * a string. The first version printed the array, so
					 * every one of these read "Array".
					 */
					$cc_label = is_array( $cc_field ) ? (string) ( $cc_field['label'] ?? $cc_key ) : (string) $cc_field;
					$cc_note  = is_array( $cc_field ) ? (string) ( $cc_field['note'] ?? '' ) : '';
					?>
					<button type="button" class="cc-bd-tag" data-cc-tag="{<?php echo esc_attr( $cc_key ); ?>}"
						<?php echo $cc_note ? 'title="' . esc_attr( $cc_note ) . '"' : ''; ?>>
						<span class="cc-bd-tag-key">{<?php echo esc_html( $cc_key ); ?>}</span>
						<span class="cc-bd-tag-label"><?php echo esc_html( $cc_label ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
