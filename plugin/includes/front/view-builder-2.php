<?php
/**
 * Campaign step 2 — who gets it.
 *
 * Three lists in one screen: saved segments, people picked one at a time,
 * and people held back. Exclusions are deliberately a list of their own
 * rather than unticking inside a segment, because a segment is a rule and
 * its membership changes on its own between now and the send date.
 *
 * @package CardsCo
 *
 * @var array $campaign
 * @var array $segments
 * @var array $people
 * @var array $picked
 * @var array $audience
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

/*
 * Getting people in, before choosing which of them gets the card.
 *
 * A company that has just signed up has nobody, and this screen used to say
 * so and stop: one line reading "Nobody has been added to this company yet"
 * and nothing to click. Adding anybody meant a screen in the WordPress
 * admin that a company account is redirected away from, so the first
 * campaign a new customer tried to build could not be finished at all.
 *
 * Both forms sit above the step's own form rather than inside it, because
 * the file input needs a multipart form of its own and forms cannot nest.
 * Whoever arrives this way is ticked straight away — somebody adding a name
 * here means that name to get the card.
 */
$cc_has_people = ! empty( $people );
?>
<section class="cc-bd-add<?php echo $cc_has_people ? ' cc-bd-add-quiet' : ''; ?>">

	<?php if ( ! $cc_has_people ) : ?>
		<h2 class="cc-bd-add-head"><?php esc_html_e( 'First, add the people', 'cardsco' ); ?></h2>
		<p class="cc-bd-add-say">
			<?php esc_html_e( 'Nobody has been added to this company yet. Upload a list, or add somebody by hand — either way they are picked for this campaign.', 'cardsco' ); ?>
		</p>
	<?php else : ?>
		<h2 class="cc-bd-add-head"><?php esc_html_e( 'Somebody missing?', 'cardsco' ); ?></h2>
	<?php endif; ?>

	<form class="cc-up-file" method="post" enctype="multipart/form-data" id="ccBdUpload"
		action="<?php echo esc_url( $links['here'] ); ?>">

		<?php cardsco_nonce_field( 'cardsco_builder' ); ?>
		<input type="hidden" name="cardsco_builder_action" value="upload_people" />
		<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

		<span class="cc-up-file-icon" aria-hidden="true">
			<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'box', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</span>

		<span class="cc-up-file-text">
			<span class="cc-up-file-name" data-cc-up-name><?php esc_html_e( 'Upload a list', 'cardsco' ); ?></span>
			<span class="cc-up-file-meta" data-cc-up-meta><?php esc_html_e( 'A CSV with a name column, and an email if you have one', 'cardsco' ); ?></span>
		</span>

		<label class="cc-link cc-up-choose" for="cc-bd-up-input">
			<?php esc_html_e( 'Choose file', 'cardsco' ); ?>
			<input type="file" id="cc-bd-up-input" name="people_file" accept=".csv,.tsv,.txt,text/csv"
				class="screen-reader-text" data-cc-up-input />
		</label>

		<button type="submit" class="cc-btn cc-btn-quiet cc-up-go" data-cc-up-go hidden>
			<?php esc_html_e( 'Upload', 'cardsco' ); ?>
		</button>
	</form>

	<p class="cc-bd-one-head"><?php esc_html_e( 'Or add someone manually', 'cardsco' ); ?></p>

	<form class="cc-bd-one" method="post" action="<?php echo esc_url( $links['here'] ); ?>">
		<?php cardsco_nonce_field( 'cardsco_builder' ); ?>
		<input type="hidden" name="cardsco_builder_action" value="add_person" />
		<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />

		<div class="cc-field">
			<label for="cc-bd-first"><?php esc_html_e( 'First name', 'cardsco' ); ?></label>
			<input type="text" id="cc-bd-first" name="first_name" autocomplete="off" />
		</div>

		<div class="cc-field">
			<label for="cc-bd-last"><?php esc_html_e( 'Last name', 'cardsco' ); ?></label>
			<input type="text" id="cc-bd-last" name="last_name" autocomplete="off" />
		</div>

		<div class="cc-field">
			<label for="cc-bd-email"><?php esc_html_e( 'Email', 'cardsco' ); ?> <span class="cc-field-opt"><?php esc_html_e( 'optional', 'cardsco' ); ?></span></label>
			<input type="email" id="cc-bd-email" name="email" autocomplete="off" />
		</div>

		<?php /* Outlined, with a plus: the grey one read as a disabled button. */ ?>
		<button type="submit" class="cc-btn cc-btn-outline cc-bd-one-go">
			<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			<?php esc_html_e( 'Add manually', 'cardsco' ); ?>
		</button>
	</form>
</section>

<form method="post" id="ccBdForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php cardsco_nonce_field( 'cardsco_builder' ); ?>
	<input type="hidden" name="cardsco_builder_action" value="step" />
	<input type="hidden" name="step" value="2" />
	<input type="hidden" name="campaign" value="<?php echo esc_attr( (int) $campaign['id'] ); ?>" />
	<?php
	/*
	 * An empty value for each list, so a screen with nothing ticked still
	 * says so. Without it the step would look like it sent nothing, and the
	 * handler would leave the previous audience alone — which is right for
	 * a screen that does not carry the field, and wrong for this one, where
	 * unticking everything is a thing somebody can mean.
	 */
	?>
	<input type="hidden" name="segments[]" value="" />
	<input type="hidden" name="recipients[]" value="" />
	<input type="hidden" name="exclude[]" value="" />

	<?php if ( $segments ) : ?>
		<fieldset class="cc-bd-field">
			<legend><?php esc_html_e( 'Groups', 'cardsco' ); ?></legend>
			<div class="cc-bd-checks">
				<?php foreach ( $segments as $cc_seg ) : ?>
					<?php $cc_on = in_array( (int) $cc_seg['id'], $picked['segments'], true ); ?>
					<label class="cc-bd-check<?php echo $cc_on ? ' is-on' : ''; ?>">
						<input type="checkbox" name="segments[]" value="<?php echo esc_attr( (int) $cc_seg['id'] ); ?>" <?php checked( $cc_on ); ?> />
						<span class="cc-bd-check-name"><?php echo esc_html( $cc_seg['name'] ); ?></span>
						<span class="cc-bd-check-n">
							<?php
							printf(
								/* translators: %s: how many people are in this group. */
								esc_html( _n( '%s person', '%s people', (int) $cc_seg['count'], 'cardsco' ) ),
								esc_html( number_format_i18n( (int) $cc_seg['count'] ) )
							);
							?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	<?php endif; ?>

	<fieldset class="cc-bd-field">
		<legend><?php esc_html_e( 'People', 'cardsco' ); ?></legend>

		<?php if ( ! $people ) : ?>
			<p class="cc-bd-none"><?php esc_html_e( 'Nobody yet — add somebody above and they will appear here, already picked.', 'cardsco' ); ?></p>
		<?php else : ?>
			<?php
			/*
			 * Ticking a list of forty one at a time is forty clicks. Shown
			 * by builder.js, because without a script it would be a button
			 * that does nothing; it turns into "Clear all" once everybody
			 * is ticked.
			 */
			if ( count( $people ) > 1 ) :
				?>
				<p class="cc-bd-all" data-cc-bd-all hidden>
					<button type="button" class="cc-btn cc-btn-outline cc-bd-all-go" data-cc-bd-all-go
						data-cc-all="<?php esc_attr_e( 'Select all', 'cardsco' ); ?>"
						data-cc-none="<?php esc_attr_e( 'Clear all', 'cardsco' ); ?>">
						<?php esc_html_e( 'Select all', 'cardsco' ); ?>
					</button>
					<span class="cc-bd-all-n" data-cc-bd-all-n
						data-cc-say="<?php esc_attr_e( '%1$s of %2$s selected', 'cardsco' ); ?>"></span>
				</p>
			<?php endif; ?>

			<div class="cc-bd-people">
				<?php foreach ( $people as $cc_p ) : ?>
					<?php
					$cc_id   = (int) $cc_p['id'];
					$cc_in   = in_array( $cc_id, $picked['recipients'], true );
					$cc_out  = in_array( $cc_id, $picked['exclude'], true );
					$cc_from = in_array( $cc_id, $audience['ids'], true ) && ! $cc_in;
					?>
					<div class="cc-bd-person<?php echo $cc_out ? ' is-out' : ''; ?>">
						<label class="cc-bd-check<?php echo $cc_in ? ' is-on' : ''; ?>">
							<input type="checkbox" name="recipients[]" value="<?php echo esc_attr( $cc_id ); ?>" <?php checked( $cc_in ); ?> />
							<span class="cc-bd-check-name"><?php echo esc_html( trim( $cc_p['first_name'] . ' ' . $cc_p['last_name'] ) ); ?></span>
						</label>

						<?php
						/*
						 * Holding somebody back only means anything when a
						 * group could put them in. Picking people one at a
						 * time, the way to leave somebody out is not to
						 * tick them — so offering both a tick and a hold
						 * back for the same person was two controls for one
						 * decision, and no way to tell which won.
						 */
						if ( $picked['segments'] ) :
							?>
							<?php if ( $cc_from ) : ?>
								<span class="cc-bd-from"><?php esc_html_e( 'from a group', 'cardsco' ); ?></span>
							<?php endif; ?>

							<label class="cc-bd-out" title="<?php esc_attr_e( 'Leave this person out, even though a group they are in is selected.', 'cardsco' ); ?>">
								<input type="checkbox" name="exclude[]" value="<?php echo esc_attr( $cc_id ); ?>" <?php checked( $cc_out ); ?> />
								<span><?php esc_html_e( 'Hold back', 'cardsco' ); ?></span>
							</label>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</fieldset>

	<?php
	/*
	 * Counted as it stands on screen, not as it was last saved. The first
	 * version printed the saved audience, so ticking three people and
	 * watching it say "This would send 0 cards" was the only feedback
	 * anybody got — and it looked exactly like the ticks not working.
	 *
	 * The number rendered here is still the saved one, so the sentence is
	 * right before any script runs; builder.js recounts on every change.
	 */
	?>
	<p class="cc-bd-count" data-cc-count
		data-cc-one="<?php esc_attr_e( 'This would send %s card.', 'cardsco' ); ?>"
		data-cc-many="<?php esc_attr_e( 'This would send %s cards.', 'cardsco' ); ?>">
		<?php
		printf(
			/* translators: %s: how many cards this campaign would send. */
			esc_html( _n( 'This would send %s card.', 'This would send %s cards.', count( $audience['ids'] ), 'cardsco' ) ),
			esc_html( number_format_i18n( count( $audience['ids'] ) ) )
		);
		?>
	</p>

	<?php include CARDSCO_PATH . 'includes/front/view-builder-foot.php'; ?>
</form>
