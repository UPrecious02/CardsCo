<?php
/**
 * Step 2 — Who's celebrating?
 *
 * Frame 253:56964: an upload panel with a template link, three counts, then
 * a table of Name / Birthday / Next card, a link to add somebody by hand,
 * and a "Showing 4 of 42" count.
 *
 * @package CardsCo
 *
 * @var array   $people
 * @var array   $counts
 * @var array   $upload
 * @var array   $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="2" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
</form>

<?php if ( ! empty( $upload['report'] ) ) : ?>
	<p class="cc-up-report" role="status"><?php echo esc_html( $upload['report'] ); ?></p>
<?php endif; ?>

<section class="cc-up-panel">
	<div class="cc-up-panel-head">
		<div>
			<p class="cc-up-panel-title"><?php esc_html_e( 'Your team', 'cardsco' ); ?></p>
			<p class="cc-up-panel-sub">
				<?php
				/* The third column is whatever this occasion is, not always a birthday. */
				printf(
					/* translators: %s: the occasion, e.g. "birthday". */
					esc_html__( 'Columns: first name, last name, %s', 'cardsco' ),
					esc_html( cardsco_lcfirst( $people['noun'] ) )
				);
				?>
			</p>
		</div>
		<?php
		/*
		 * The other way to put somebody on the list, beside the upload it
		 * is the alternative to. It used to be a text link at the foot of
		 * the page, under the whole list, where nobody found it.
		 */
		?>
		<div class="cc-up-panel-acts">
			<a class="cc-link" href="<?php echo esc_url( $links['template'] ); ?>"><?php esc_html_e( 'Download template', 'cardsco' ); ?></a>
			<a class="cc-btn cc-btn-outline cc-up-manual" href="<?php echo esc_url( $links['people'] ); ?>">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<?php esc_html_e( 'Add manually', 'cardsco' ); ?>
			</a>
		</div>
	</div>

	<?php
	/*
	 * A real upload, not a link.
	 *
	 * Both controls here used to be anchors pointing at the page they were
	 * already on, so neither did anything. The file input is the one thing
	 * that cannot be a link: it needs a multipart form of its own, which is
	 * why this is not part of the step's own form.
	 *
	 * The input is hidden behind its label rather than left as a browser
	 * file control, so the row keeps the frame's shape. Choosing a file
	 * submits, which saves a second click; without JavaScript the Upload
	 * button below does the same job.
	 */
	?>
	<?php require CARDSCO_PATH . 'includes/front/partial-upload.php'; ?>

	<?php
	/*
	 * A file is waiting and the occasion already has people on it, so
	 * what the file means is a real question: more people, or the list
	 * from now on. It used to be assumed to mean the first, which left
	 * a company re-uploading a corrected spreadsheet holding both
	 * versions of it.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
	$cc_asking = ! empty( $_GET['cc_up_ask'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	?>
	<?php if ( $cc_asking ) : ?>
		<form class="cc-up-ask" method="post">
			<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
			<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

			<p class="cc-up-ask-head">
				<strong>
					<?php
					printf(
						/* translators: %s: how many people are already on the occasion. */
						esc_html( _n( 'You already have %s person on this occasion.', 'You already have %s people on this occasion.', (int) $people['total'], 'cardsco' ) ),
						esc_html( number_format_i18n( (int) $people['total'] ) )
					);
					?>
				</strong>
				<span><?php esc_html_e( 'What should this file do?', 'cardsco' ); ?></span>
			</p>

			<?php /* is-on from the start, or the checked option sits unhighlighted until something is clicked. */ ?>
			<label class="cc-up-ask-one is-on">
				<input type="radio" name="how" value="add" checked />
				<span class="cc-up-ask-title"><?php esc_html_e( 'Add these people to the list', 'cardsco' ); ?></span>
				<span class="cc-up-ask-sub"><?php esc_html_e( 'Everyone already here stays. Names in the file that are already on the list are matched, not added twice.', 'cardsco' ); ?></span>
			</label>

			<label class="cc-up-ask-one">
				<input type="radio" name="how" value="replace" />
				<span class="cc-up-ask-title"><?php esc_html_e( 'Make this file the list', 'cardsco' ); ?></span>
				<span class="cc-up-ask-sub">
					<?php
					printf(
						/* translators: 1: how many people come off, 2: the occasion, e.g. "welcome". */
						esc_html( _n( '%1$s person comes off this occasion and gets no %2$s card. They stay in your people list and keep their date.', '%1$s people come off this occasion and get no %2$s card. They stay in your people list and keep their dates.', (int) $people['total'], 'cardsco' ) ),
						esc_html( number_format_i18n( (int) $people['total'] ) ),
						esc_html( cardsco_lcfirst( $people['noun'] ) )
					);
					?>
				</span>
			</label>

			<p class="cc-up-ask-go">
				<button type="submit" name="cardsco_setup_action" value="upload_choice" class="cc-btn cc-btn-primary">
					<?php esc_html_e( 'Use this file', 'cardsco' ); ?>
				</button>
				<button type="submit" name="cardsco_setup_action" value="upload_cancel" class="cc-btn cc-btn-quiet">
					<?php esc_html_e( 'Cancel', 'cardsco' ); ?>
				</button>
			</p>
		</form>
	<?php endif; ?>
</section>

<div class="cc-counts">
	<?php foreach ( $counts as $cc_count ) : ?>
		<div class="cc-count">
			<span class="cc-count-n"><?php echo esc_html( number_format_i18n( $cc_count['n'] ) ); ?></span>
			<span class="cc-count-label"><?php echo esc_html( $cc_count['label'] ); ?></span>
		</div>
	<?php endforeach; ?>
</div>

<div class="cc-table-wrap cc-people-scroll">
	<table class="cc-table cc-people">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
				<?php /* The occasion names its own column: not every one is a birthday. */ ?>
				<th scope="col"><?php echo esc_html( $people['noun'] ); ?></th>
				<th scope="col"><?php esc_html_e( 'Next card', 'cardsco' ); ?></th>
				<th scope="col" class="cc-people-act"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'cardsco' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $people['rows'] as $cc_person ) : ?>
				<tr>
					<td class="cc-people-name"><?php echo esc_html( $cc_person['name'] ); ?></td>
					<td class="<?php echo $cc_person['has_date'] ? '' : 'cc-people-missing'; ?>">
						<?php echo esc_html( $cc_person['birthday'] ); ?>
					</td>
					<td>
						<?php if ( $cc_person['has_date'] ) : ?>
							<?php echo esc_html( $cc_person['next'] ); ?>
						<?php else : ?>
							<?php
							/*
							 * Goes to this person, not to a blank form. It
							 * used to point at the same URL as "Add someone
							 * by hand", so clicking it opened an empty panel
							 * headed "Person 4" and made a second, nameless
							 * copy instead of giving this one a date.
							 */
							$cc_fix = add_query_arg( 'cc_person', (int) $cc_person['id'], $links['here_step'] );
							?>
							<a class="cc-link" href="<?php echo esc_url( $cc_fix ); ?>">
								<?php
								printf(
									/* translators: %s: the occasion, e.g. "birthday". */
									esc_html__( 'Add a %s', 'cardsco' ),
									esc_html( cardsco_lcfirst( $people['noun'] ) )
								);
								?>
							</a>
						<?php endif; ?>
					</td>

					<?php
					/*
					 * Taking somebody off the list. An upload adds to what is
					 * already there rather than replacing it, so without this
					 * a name typed in by mistake, or one row too many in a
					 * spreadsheet, could not be got rid of at all.
					 */
					?>
					<td class="cc-people-act">
						<form method="post" class="cc-drop">
							<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
							<input type="hidden" name="cardsco_setup_action" value="remove_person" />
							<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
							<input type="hidden" name="recipient_id" value="<?php echo esc_attr( (int) $cc_person['id'] ); ?>" />
							<?php
							/*
							 * "Remove" on screen, the name only for a screen
							 * reader: a column of "Remove Jane Doe" buttons is
							 * the person's name said twice on every row, and
							 * as wide as the names are long.
							 */
							?>
							<button type="submit" class="cc-drop-btn">
								<?php esc_html_e( 'Remove', 'cardsco' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( $cc_person['name'] ); ?></span>
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php
/*
 * Say what just happened. Adding people used to reload the page with
 * no word about it, which left you looking for the two names you had
 * ticked and deciding the thing was broken.
 */
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
$cc_joined  = isset( $_GET['cc_joined'] ) ? absint( $_GET['cc_joined'] ) : null;
$cc_dropped = isset( $_GET['cc_dropped'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_dropped'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<?php if ( '' !== $cc_dropped ) : ?>
	<p class="cc-have-said">
		<?php
		printf(
			/* translators: 1: the person's name, 2: the occasion, e.g. "welcome". */
			esc_html__( '%1$s taken off this occasion. No %2$s card will be sent to them.', 'cardsco' ),
			esc_html( $cc_dropped ),
			esc_html( cardsco_lcfirst( $people['noun'] ) )
		);
		?>
	</p>
<?php endif; ?>
<?php if ( null !== $cc_joined ) : ?>
	<p class="cc-have-said">
		<?php
		echo esc_html(
			$cc_joined
				? sprintf(
					/* translators: %s: how many people. */
					_n( '%s person added to this occasion.', '%s people added to this occasion.', $cc_joined, 'cardsco' ),
					number_format_i18n( $cc_joined )
				)
				: __( 'Those people were already on this occasion.', 'cardsco' )
		);
		?>
	</p>
<?php endif; ?>

<?php
/*
 * The people this company already has. Without this the only ways on
 * were a spreadsheet or typing names in one at a time, so a company
 * setting up its second occasion had to upload the same list twice.
 */
?>
<?php if ( ! empty( $people['available'] ) ) : ?>
	<form class="cc-have" method="post">
		<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
		<input type="hidden" name="cardsco_setup_action" value="add_existing" />
		<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

		<p class="cc-have-head">
			<strong><?php esc_html_e( 'Already on your list', 'cardsco' ); ?></strong>
			<span class="cc-have-sub">
				<?php
				printf(
					/* translators: %s: how many people. */
					esc_html( _n( '%s person you have already added can join this occasion.', '%s people you have already added can join this occasion.', count( $people['available'] ), 'cardsco' ) ),
					esc_html( number_format_i18n( count( $people['available'] ) ) )
				);
				?>
			</span>
		</p>

		<ul class="cc-have-list">
			<?php foreach ( $people['available'] as $cc_one ) : ?>
				<li class="cc-have-one">
					<label>
						<input type="checkbox" name="recipients[]" value="<?php echo esc_attr( $cc_one['id'] ); ?>" checked />
						<span class="cc-have-name"><?php echo esc_html( $cc_one['name'] ); ?></span>
						<span class="cc-have-what"><?php echo esc_html( $cc_one['what'] ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="cc-have-actions">
			<button type="submit" class="cc-btn cc-btn-primary">
				<?php esc_html_e( 'Add the ticked people', 'cardsco' ); ?>
			</button>
			<?php if ( $people['per_person'] ) : ?>
				<span class="cc-have-note">
					<?php
					printf(
						/* translators: %s: the occasion, e.g. "birthday". */
						esc_html__( 'Anyone without a %s date yet will show as missing one, and you can fill it in above.', 'cardsco' ),
						esc_html( cardsco_lcfirst( $people['noun'] ) )
					);
					?>
				</span>
			<?php endif; ?>
		</p>
	</form>
<?php endif; ?>

<p class="cc-people-foot">
	<span class="cc-people-showing">
		<?php
		/*
		 * "Showing 4 of 6" was true and useless: there was no way to see
		 * the other two. Everyone is listed now, so this only has
		 * something to say when a list is long enough to be cut off.
		 */
		if ( count( $people['rows'] ) < $people['total'] ) {
			printf(
				/* translators: 1: how many rows are listed, 2: how many there are. */
				esc_html__( 'Showing the first %1$s of %2$s', 'cardsco' ),
				esc_html( number_format_i18n( count( $people['rows'] ) ) ),
				esc_html( number_format_i18n( $people['total'] ) )
			);
		} else {
			printf(
				/* translators: %s: how many people. */
				esc_html( _n( '%s person', '%s people', (int) $people['total'], 'cardsco' ) ),
				esc_html( number_format_i18n( $people['total'] ) )
			);
		}
		?>
	</span>
</p>
