<?php
/**
 * The card writer.
 *
 * Writing_Card frames 00, 01 and 02 — empty, typing, and preview. The three
 * are one screen in different states, so this is one view and the state is
 * decided by whether anything has been written.
 *
 * Geometry from the frames: the composer shell is 1144 wide with a 1120
 * field inside it, the starter chips sit in two rows at y=428 and y=468, and
 * the preview panel is 1176 wide holding a 397x533 card.
 *
 * WHAT IS NOT HERE, AND WHY
 *
 * The frames show a workspace with its own sidebar — New card, Drafts, Sent
 * cards, and a list of past drafts — for composing one-off cards to a person
 * you pick at the top. That is a separate feature with its own data model:
 * a card that belongs to nobody's programme. This screen is the one the
 * occasion flow needs, reached from step 4, writing the message that goes on
 * every card in the programme. The recipients are already chosen by then, so
 * the frames' "To" picker is a summary line here instead.
 *
 * The frames also offer AI drafting — "tell us who it's for and the feeling
 * you want, we'll draft it" and "ask for help any time, like make this
 * warmer". That was cut, so the starter chips drop a written message in to
 * edit rather than prompting a draft.
 *
 * @package CardsCo
 *
 * @var array $programme
 * @var array $writer
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_has = '' !== trim( (string) $writer['text'] );
?>

<form method="post" id="ccWriter" class="cc-wr" action="<?php echo esc_url( $links['here'] ); ?>"
	data-cc-max="<?php echo esc_attr( (int) $writer['max'] ); ?>"
	data-cc-blanks="<?php echo esc_attr( wp_json_encode( array_map( 'cardsco_tag_label', (array) $writer['blanks'] ) ) ); ?>"
	data-cc-sample="<?php echo esc_attr( wp_json_encode( (array) $writer['sample'] ) ); ?>">

	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="write_message" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<div class="cc-wr-top">
		<p class="cc-wr-to">
			<span class="cc-wr-to-label"><?php esc_html_e( 'For', 'cardsco' ); ?></span>
			<span class="cc-wr-chip">
				<?php
				printf(
					/* translators: %s: how many people are on the occasion. */
					esc_html( _n( '%s person', '%s people', $writer['people'], 'cardsco' ) ),
					esc_html( number_format_i18n( $writer['people'] ) )
				);
				?>
			</span>
			<span class="cc-wr-chip"><?php echo esc_html( $writer['noun'] ); ?></span>
			<?php if ( $writer['hand'] && '—' !== $writer['hand'] ) : ?>
				<span class="cc-wr-chip"><?php echo esc_html( $writer['hand'] ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( $writer['posts'] ) : ?>
			<p class="cc-wr-when">
				<?php
				printf(
					/* translators: %s: a date like "Mon 26 Oct". */
					esc_html__( 'Next box posts %s', 'cardsco' ),
					esc_html( $writer['posts'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * The empty state. Hidden rather than dropped once something is written,
	 * because Clear brings it straight back without a round trip.
	 */
	?>
	<div class="cc-wr-blank" data-cc-blank <?php echo $cc_has ? 'hidden' : ''; ?>>
		<h2 class="cc-wr-blank-h"><?php esc_html_e( 'What would you like to say?', 'cardsco' ); ?></h2>
		<p class="cc-wr-blank-p">
			<?php
			echo esc_html(
				$writer['templates']
					? __( 'Write your message, or start from one of ours. A pen writes it in real ink.', 'cardsco' )
					: __( 'Write your message. A pen writes it in real ink.', 'cardsco' )
			);
			?>
		</p>

		<?php
		/*
		 * The ready-written messages for this occasion, if there are any.
		 *
		 * Not every occasion has them: the client's own wording covers six
		 * of the eleven, and the rest have none until somebody writes
		 * them. The line above used to offer "start from one of ours"
		 * regardless, over the empty space where they would have been.
		 */
		?>
		<?php if ( $writer['templates'] ) : ?>
			<div class="cc-wr-starters">
				<?php foreach ( $writer['templates'] as $cc_t ) : ?>
					<button type="button" class="cc-wr-starter" data-cc-fill="<?php echo esc_attr( $cc_t['text'] ); ?>">
						<?php echo esc_html( $cc_t['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * The preview. The frame renders the message in handwriting on a card;
	 * the lines come from the server already merged against a real recipient,
	 * and the script rewrites them as you type so the card keeps up.
	 */
	?>
	<div class="cc-wr-preview" data-cc-preview <?php echo $cc_has ? '' : 'hidden'; ?>>
		<div class="cc-wr-card">
			<div class="cc-wr-hand" data-cc-hand>
				<?php foreach ( $writer['lines'] as $cc_line ) : ?>
					<p><?php echo esc_html( $cc_line ); ?></p>
				<?php endforeach; ?>
			</div>

			<p class="cc-wr-sign" data-cc-sign <?php echo $writer['signature'] ? '' : 'hidden'; ?>>
				<?php echo esc_html( $writer['signature'] ? '— ' . $writer['signature'] : '' ); ?>
			</p>
		</div>
	</div>

	<div class="cc-wr-bar">
		<div class="cc-wr-bar-head">
			<span class="cc-wr-bar-label"><?php esc_html_e( 'Your message', 'cardsco' ); ?></span>
			<?php if ( $writer['for'] ) : ?>
				<span class="cc-wr-bar-note">
					<?php
					printf(
						/* translators: %s: a first name. */
						esc_html__( 'Previewed for %s', 'cardsco' ),
						esc_html( $writer['for'] )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<div class="cc-wr-field">
			<label class="screen-reader-text" for="cc-wr-text"><?php esc_html_e( 'Your message', 'cardsco' ); ?></label>
			<textarea id="cc-wr-text" name="message" rows="2" data-cc-text
				placeholder="<?php esc_attr_e( 'Write your message, or start from one of ours', 'cardsco' ); ?>"><?php echo esc_textarea( $writer['text'] ); ?></textarea>

			<button type="submit" class="cc-wr-go" data-cc-go
				aria-label="<?php esc_attr_e( 'Save message', 'cardsco' ); ?>">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</button>
		</div>

		<div class="cc-wr-bar-foot">
			<span class="cc-wr-count" data-cc-count aria-live="polite"></span>
			<button type="button" class="cc-wr-clear" data-cc-clear><?php esc_html_e( 'Clear', 'cardsco' ); ?></button>
		</div>
	</div>

	<?php
	/*
	 * The merge tags.
	 *
	 * The engine has known about these all along — it is what writes each
	 * person's own name onto their card — but the writer gave no way to
	 * put one in, so the only people who could use them were the ones who
	 * already knew the wording by heart. Pressing a chip drops the tag in
	 * at the cursor.
	 *
	 * A tag that would come out empty for somebody on this occasion says
	 * so rather than being hidden: it is still usable, and the person
	 * writing needs to know a card may land with a hole in it.
	 */
	$cc_blank = (array) $writer['blanks'];
	?>
	<div class="cc-wr-tags">
		<span class="cc-wr-tags-head"><?php esc_html_e( 'Put in', 'cardsco' ); ?></span>

		<?php foreach ( $writer['tags'] as $cc_tag ) : ?>
			<?php $cc_empty = in_array( $cc_tag['key'], $cc_blank, true ); ?>
			<button type="button"
				class="cc-wr-tag<?php echo $cc_empty ? ' is-empty' : ''; ?>"
				data-cc-insert="<?php echo esc_attr( $cc_tag['tag'] ); ?>"
				title="<?php echo esc_attr( $cc_empty ? $cc_tag['empty'] : $cc_tag['note'] ); ?>">
				<?php echo esc_html( $cc_tag['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<p class="cc-wr-tags-hint">
		<?php esc_html_e( 'Each card is written with that person’s own details in place of the tag.', 'cardsco' ); ?>
	</p>

	<div class="cc-wr-sign-row">
		<label for="cc-wr-sign"><?php esc_html_e( 'Sign off as', 'cardsco' ); ?></label>
		<input type="text" id="cc-wr-sign" name="signature" data-cc-signin
			value="<?php echo esc_attr( $writer['signature'] ); ?>"
			placeholder="<?php esc_attr_e( 'The team at Brightside', 'cardsco' ); ?>" />
		<span class="cc-wr-sign-hint"><?php esc_html_e( 'Written under the message, in the same hand.', 'cardsco' ); ?></span>
	</div>
</form>
