<?php
/**
 * Step 2 — Add someone by hand.
 *
 * Occasions_setup frames 08 and 09, which are two states of one screen
 * rather than two screens. 08 is the empty form; 09 is what you get back
 * after saving, with the person confirmed and a way to add another.
 *
 * Geometry from the frames: panel x=64 w=952, fields 449 wide on a 12px
 * gutter at y=294 and y=370, buttons at y=454. The saved state puts a 70px
 * banner at y=213, the list at y=335 on 54px rows, and the add-another
 * button at y=413.
 *
 * @package CardsCo
 *
 * @var array $programme
 * @var array $person
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;

$cc_saved = $person['saved'];
?>

<?php if ( $cc_saved ) : ?>

	<?php
	/*
	 * The footer's Continue reaches the step form by its id, and on this
	 * state there is no other form on the screen — so the plain step form
	 * comes along, exactly as the step 2 list has it, and Continue moves on
	 * to the card.
	 */
	?>
	<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
		<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
		<input type="hidden" name="cardsco_setup_action" value="step" />
		<input type="hidden" name="step" value="2" />
		<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
	</form>

	<div class="cc-pn-done">
		<span class="cc-pn-done-mark" aria-hidden="true">
			<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</span>
		<span class="cc-pn-done-text">
			<span class="cc-pn-done-title">
				<?php
				printf(
					/* translators: %s: the person's name. */
					esc_html__( '%s added', 'cardsco' ),
					esc_html( $cc_saved['name'] )
				);
				?>
			</span>
			<span class="cc-pn-done-sub">
				<?php
				printf(
					/* translators: 1: card noun, e.g. "birthday", 2: a date like "29 October". */
					esc_html__( 'Their first card goes out for their %1$s on %2$s.', 'cardsco' ),
					esc_html( cardsco_lcfirst( $person['noun'] ) ),
					esc_html( $cc_saved['birthday'] )
				);
				?>
			</span>
		</span>
	</div>

	<div class="cc-pn-listhead">
		<h2 class="cc-pn-listtitle"><?php esc_html_e( 'Added so far', 'cardsco' ); ?></h2>
		<span class="cc-pn-listcount">
			<?php
			printf(
				/* translators: %s: how many people. */
				esc_html( _n( '%s person', '%s people', $person['total'], 'cardsco' ) ),
				esc_html( number_format_i18n( $person['total'] ) )
			);
			?>
		</span>
	</div>

	<ul class="cc-pn-list">
		<?php foreach ( $person['rows'] as $cc_row ) : ?>
			<?php $cc_is_new = (int) $cc_row['id'] === (int) $cc_saved['id']; ?>
			<li class="cc-pn-row<?php echo $cc_is_new ? ' is-new' : ''; ?>">
				<span class="cc-pn-avatar" aria-hidden="true"><?php echo esc_html( $cc_row['initials'] ); ?></span>

				<span class="cc-pn-who">
					<span class="cc-pn-name"><?php echo esc_html( $cc_row['name'] ); ?></span>
					<span class="cc-pn-meta">
						<?php
						$cc_bits = array_filter(
							array(
								$cc_row['birthday']
									? sprintf(
										/* translators: 1: card noun, 2: a date. */
										esc_html__( '%1$s %2$s', 'cardsco' ),
										esc_html( $person['noun'] ),
										esc_html( $cc_row['birthday'] )
									)
									: esc_html__( 'No date yet', 'cardsco' ),
								esc_html( $cc_row['team'] ),
							)
						);

						echo esc_html( implode( ' · ', $cc_bits ) );
						?>
					</span>
				</span>

				<?php if ( $cc_is_new ) : ?>
					<span class="cc-pn-saved">
						<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<?php esc_html_e( 'Saved', 'cardsco' ); ?>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="cc-pn-again">
		<a class="cc-pn-again-btn" href="<?php echo esc_url( add_query_arg( 'cc_add', 1, $links['here_step'] ) ); ?>">
			<span aria-hidden="true">+</span>
			<?php esc_html_e( 'Add another person', 'cardsco' ); ?>
		</a>
	</p>

<?php else : ?>

	<?php
	/*
	 * Its own id, and deliberately not .cc-wz-form: that class is
	 * display:contents, which is right for the step forms that are only
	 * there to carry state for the footer's Continue button, and wrong for
	 * this one, which is the visible panel.
	 */
	?>
	<?php $cc_edit = $person['edit']; ?>
	<form method="post" id="ccPersonForm" class="cc-pn-panel" action="<?php echo esc_url( $links['here'] ); ?>">
		<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
		<input type="hidden" name="cardsco_setup_action" value="add_person" />
		<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

		<?php if ( $cc_edit ) : ?>
			<input type="hidden" name="person_id" value="<?php echo esc_attr( (int) $cc_edit['id'] ); ?>" />
		<?php endif; ?>

		<div class="cc-pn-head">
			<h2 class="cc-pn-title">
				<?php
				if ( $cc_edit ) {
					echo esc_html( $cc_edit['name'] ? $cc_edit['name'] : __( 'This person', 'cardsco' ) );
				} else {
					printf(
						/* translators: %s: which person this is, e.g. 2. */
						esc_html__( 'Person %s', 'cardsco' ),
						esc_html( number_format_i18n( $person['ordinal'] ) )
					);
				}
				?>
			</h2>
			<a class="cc-pn-upload" href="<?php echo esc_url( $links['import'] ); ?>">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'upload', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<?php esc_html_e( 'Upload a spreadsheet instead', 'cardsco' ); ?>
			</a>
		</div>

		<div class="cc-pn-grid">
			<p class="cc-pn-field">
				<label for="cc-first"><?php esc_html_e( 'First name', 'cardsco' ); ?></label>
				<input type="text" id="cc-first" name="first_name" value="<?php echo esc_attr( $cc_edit ? $cc_edit['first'] : '' ); ?>" autocomplete="given-name" />
			</p>

			<p class="cc-pn-field">
				<label for="cc-last"><?php esc_html_e( 'Last name', 'cardsco' ); ?></label>
				<input type="text" id="cc-last" name="last_name" value="<?php echo esc_attr( $cc_edit ? $cc_edit['last'] : '' ); ?>" autocomplete="family-name" />
			</p>

			<?php
			/*
			 * Only occasions whose date differs per person ask for one.
			 * Christmas is the 25th for everybody and a promotion goes out
			 * when the campaign runs, so for those the form takes a name and
			 * stops. It used to show these fields regardless and then refuse
			 * the save, after the whole form had been filled in.
			 */
			?>
			<?php if ( $person['per_person'] ) : ?>
			<p class="cc-pn-field">
				<label for="cc-day">
					<?php
					printf(
						/* translators: %s: card noun, e.g. "Birthday". */
						esc_html__( '%s · day', 'cardsco' ),
						esc_html( $person['noun'] )
					);
					?>
				</label>
				<select id="cc-day" name="event_day">
					<option value=""><?php esc_html_e( 'Day', 'cardsco' ); ?></option>
					<?php for ( $cc_d = 1; $cc_d <= 31; $cc_d++ ) : ?>
						<option value="<?php echo esc_attr( $cc_d ); ?>" <?php selected( $cc_edit ? (int) $cc_edit['day'] : 0, $cc_d ); ?>><?php echo esc_html( number_format_i18n( $cc_d ) ); ?></option>
					<?php endfor; ?>
				</select>
			</p>

			<p class="cc-pn-field">
				<label for="cc-month">
					<?php
					printf(
						/* translators: %s: card noun, e.g. "Birthday". */
						esc_html__( '%s · month', 'cardsco' ),
						esc_html( $person['noun'] )
					);
					?>
				</label>
				<select id="cc-month" name="event_month">
					<option value=""><?php esc_html_e( 'Month', 'cardsco' ); ?></option>
					<?php foreach ( $person['months'] as $cc_n => $cc_label ) : ?>
						<option value="<?php echo esc_attr( $cc_n ); ?>" <?php selected( $cc_edit ? (int) $cc_edit['month'] : 0, $cc_n ); ?>><?php echo esc_html( $cc_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php endif; ?>
		</div>

		<p class="cc-pn-note">
			<span class="cc-pn-note-icon" aria-hidden="true">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'info', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</span>
			<?php
			echo esc_html(
				$person['per_person']
					? __( 'We don’t need the year. You’ll choose where cards are shipped in step 5.', 'cardsco' )
					: __( 'This occasion has no date of its own — cards go out when you send the campaign. You’ll choose where they’re shipped in step 5.', 'cardsco' )
			);
			?>
		</p>

		<div class="cc-pn-actions">
			<a class="cc-btn cc-btn-quiet" href="<?php echo esc_url( $links['here_step'] ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
			<button type="submit" class="cc-btn cc-btn-primary">
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'check', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<?php echo esc_html( $cc_edit ? __( 'Save changes', 'cardsco' ) : __( 'Save person', 'cardsco' ) ); ?>
			</button>
		</div>
	</form>

<?php endif; ?>
