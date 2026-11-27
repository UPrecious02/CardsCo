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
	<?php cardsco_nonce_field( 'cardsco_builder' ); ?>
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

	<?php
	/*
	 * A bulk order can carry several messages, shared out between the
	 * cards in turn and matched to the designs at random. The first box is
	 * the one that was always here; the rest sit under it. One empty box
	 * is always on offer while there is room for another, so the step
	 * works with no script at all.
	 */
	$cc_room  = CardsCo_Campaign::message_limit( $campaign );
	$cc_all   = CardsCo_Campaign::messages( $campaign );
	$cc_more  = array_slice( $cc_all, 1 );
	$cc_wrong = CardsCo_Campaign::mix_problems( $campaign, 'messages' );
	?>
	<?php if ( $cc_room > 1 || $cc_more ) : ?>
		<input type="hidden" name="cc_messages" value="1" />

		<?php if ( $cc_wrong ) : ?>
			<div class="cc-bd-warn" role="alert">
				<?php foreach ( $cc_wrong as $cc_w ) : ?>
					<p><?php echo esc_html( $cc_w ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="cc-bd-field cc-bd-more" data-cc-bd-more data-cc-limit="<?php echo esc_attr( max( 1, (int) $cc_room ) ); ?>">
			<p class="cc-bd-more-head"><?php esc_html_e( 'More messages', 'cardsco' ); ?></p>
			<p class="cc-bd-hint">
				<?php
				printf(
					/* translators: %d: the most messages the order can carry. */
					esc_html__( 'Optional. Write up to %d in all and we share them out between the cards in turn, matched to your designs at random. Leave a box empty and it is ignored.', 'cardsco' ),
					(int) max( 1, $cc_room )
				);
				?>
			</p>

			<?php
			$cc_boxes = $cc_more;

			if ( count( $cc_all ) < $cc_room || ! $cc_all ) {
				$cc_boxes[] = '';
			}
			?>
			<?php foreach ( $cc_boxes as $cc_i => $cc_one ) : ?>
				<div class="cc-bd-more-one">
					<label for="cc-bd-more-<?php echo esc_attr( (int) $cc_i ); ?>">
						<?php
						/* translators: %d: which message this is, counting the first box as 1. */
						printf( esc_html__( 'Message %d', 'cardsco' ), (int) $cc_i + 2 );
						?>
					</label>
					<textarea id="cc-bd-more-<?php echo esc_attr( (int) $cc_i ); ?>" name="more_messages[]" rows="4"
						maxlength="<?php echo esc_attr( $cc_max ); ?>" data-cc-text><?php echo esc_textarea( $cc_one ); ?></textarea>
				</div>
			<?php endforeach; ?>

			<button type="button" class="cc-bd-more-add" data-cc-bd-more-add hidden><?php esc_html_e( 'Add another message', 'cardsco' ); ?></button>
		</div>
	<?php endif; ?>

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
