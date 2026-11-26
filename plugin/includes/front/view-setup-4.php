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
 * SEVERAL MESSAGES, AND A NOTE FOR ONE PERSON
 *
 * A bulk order, and employee birthdays at any size, may carry more than
 * one message. They are listed under the preview: each can be looked at,
 * edited in the writer or taken off, and another added until the order
 * has as many as it may. Each card gets one of them, used in turn, and
 * which message goes with which design is arbitrary.
 *
 * Under that, on every occasion, a line for each person. Whatever is
 * typed there is written on that person's card after whichever message
 * they get. The boxes belong to this step's own form, so Continue saves
 * them — a separate Save button is one more thing to forget to press.
 *
 * @package CardsCo
 *
 * @var array $message   From CardsCo_Dashboard::setup_message().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_several = count( $message['messages'] ) > 1;
$cc_slot    = (int) $message['slot'];

/* The writer, open on one particular message. */
$cc_write = static function ( $no ) use ( $links ) {
	return $no > 1 ? add_query_arg( 'cc_slot', (int) $no, $links['writer'] ) : $links['writer'];
};

/* This step, previewing one particular message. */
$cc_show = static function ( $no ) use ( $links ) {
	return $no > 1 ? add_query_arg( 'cc_msg', (int) $no, $links['here_step'] ) : $links['here_step'];
};
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="4" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
</form>

<?php if ( ! empty( $message['problems'] ) ) : ?>
	<div class="cc-mix-warn" role="alert">
		<?php foreach ( $message['problems'] as $cc_problem ) : ?>
			<p><?php echo esc_html( $cc_problem ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

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
		<p class="cc-msg-sum-head">
			<?php
			if ( $cc_several ) {
				printf(
					/* translators: 1: which message, 2: how many there are. */
					esc_html__( 'Message %1$s of %2$s', 'cardsco' ),
					esc_html( number_format_i18n( $cc_slot ) ),
					esc_html( number_format_i18n( count( $message['messages'] ) ) )
				);
			} else {
				esc_html_e( 'Message', 'cardsco' );
			}
			?>
		</p>

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

		<a class="cc-btn cc-btn-primary cc-btn-r8 cc-msg-write" href="<?php echo esc_url( $cc_write( $cc_slot ) ); ?>">
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

<?php if ( $message['limit'] > 1 && $message['messages'] ) : ?>
	<section class="cc-mm">
		<header class="cc-mm-head">
			<h3 class="cc-mm-title"><?php esc_html_e( 'Your messages', 'cardsco' ); ?></h3>
			<p class="cc-mm-sub">
				<?php
				printf(
					/* translators: %s: how many messages this order may carry. */
					esc_html__( 'This order can have up to %s. Each card gets one of them — we use them in turn, and match them to your designs at random.', 'cardsco' ),
					esc_html( number_format_i18n( (int) $message['limit'] ) )
				);
				?>
			</p>
		</header>

		<ol class="cc-mm-list">
			<?php foreach ( $message['messages'] as $cc_one ) : ?>
				<li class="cc-mm-one<?php echo $cc_one['on'] ? ' is-on' : ''; ?>">
					<span class="cc-mm-no" aria-hidden="true"><?php echo esc_html( number_format_i18n( (int) $cc_one['no'] ) ); ?></span>
					<span class="cc-mm-open"><?php echo esc_html( $cc_one['opening'] ); ?></span>
					<span class="cc-mm-acts">
						<?php if ( $cc_several && ! $cc_one['on'] ) : ?>
							<a class="cc-mm-act" href="<?php echo esc_url( $cc_show( $cc_one['no'] ) ); ?>">
								<?php esc_html_e( 'Preview', 'cardsco' ); ?>
								<span class="cc-sr">
									<?php
									/* translators: %s: which message. */
									printf( esc_html__( 'message %s', 'cardsco' ), esc_html( number_format_i18n( (int) $cc_one['no'] ) ) );
									?>
								</span>
							</a>
						<?php endif; ?>
						<a class="cc-mm-act" href="<?php echo esc_url( $cc_write( $cc_one['no'] ) ); ?>">
							<?php esc_html_e( 'Edit', 'cardsco' ); ?>
							<span class="cc-sr">
								<?php
								/* translators: %s: which message. */
								printf( esc_html__( 'message %s', 'cardsco' ), esc_html( number_format_i18n( (int) $cc_one['no'] ) ) );
								?>
							</span>
						</a>
						<?php if ( $cc_several ) : ?>
							<form method="post" class="cc-mm-drop" action="<?php echo esc_url( $links['here_step'] ); ?>">
								<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
								<input type="hidden" name="cardsco_setup_action" value="remove_message" />
								<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
								<input type="hidden" name="slot" value="<?php echo esc_attr( (int) $cc_one['no'] ); ?>" />
								<button type="submit" class="cc-mm-act is-quiet">
									<?php esc_html_e( 'Remove', 'cardsco' ); ?>
									<span class="cc-sr">
										<?php
										/* translators: %s: which message. */
										printf( esc_html__( 'message %s', 'cardsco' ), esc_html( number_format_i18n( (int) $cc_one['no'] ) ) );
										?>
									</span>
								</button>
							</form>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>

		<?php if ( $message['can_add'] ) : ?>
			<a class="cc-btn cc-btn-secondary cc-btn-r8 cc-mm-add" href="<?php echo esc_url( $cc_write( count( $message['messages'] ) + 1 ) ); ?>">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<span><?php esc_html_e( 'Add another message', 'cardsco' ); ?></span>
			</a>
		<?php elseif ( count( $message['messages'] ) >= (int) $message['limit'] ) : ?>
			<p class="cc-mm-full"><?php esc_html_e( 'That is the most this order can have.', 'cardsco' ); ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $message['people'] ) : ?>
	<section class="cc-nt">
		<header class="cc-nt-head">
			<h3 class="cc-nt-title"><?php esc_html_e( 'A personal note for anyone? (optional)', 'cardsco' ); ?></h3>
			<p class="cc-nt-sub">
				<?php esc_html_e( 'Add a line for one person — thanking them for their work on the team, say. It is written on their card after the message, before your sign-off. Leave a box empty for no note.', 'cardsco' ); ?>
			</p>
		</header>

		<ul class="cc-nt-list">
			<?php foreach ( $message['people'] as $cc_person ) : ?>
				<li class="cc-nt-one">
					<label class="cc-nt-name" for="cc-note-<?php echo esc_attr( (int) $cc_person['id'] ); ?>"><?php echo esc_html( $cc_person['name'] ); ?></label>
					<?php /* form="ccWizForm": saved by this step's Continue, wherever it sits on the page. */ ?>
					<input type="text"
						class="cc-nt-input"
						id="cc-note-<?php echo esc_attr( (int) $cc_person['id'] ); ?>"
						form="ccWizForm"
						name="notes[<?php echo esc_attr( (int) $cc_person['id'] ); ?>]"
						maxlength="<?php echo esc_attr( (int) $message['note_max'] ); ?>"
						autocomplete="off"
						placeholder="<?php esc_attr_e( 'No note', 'cardsco' ); ?>"
						value="<?php echo esc_attr( $cc_person['note'] ); ?>" />
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="cc-nt-foot"><?php esc_html_e( 'Notes are saved when you press Continue.', 'cardsco' ); ?></p>
	</section>
<?php endif; ?>
