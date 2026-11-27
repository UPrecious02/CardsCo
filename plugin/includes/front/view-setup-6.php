<?php
/**
 * Step 6 — when the cards arrive.
 *
 * Measured from the step-6 frame: two stacked 952-wide options at y=213 and
 * y=338 with a 12 gap, then a 952x192 settings panel at y=438 holding two
 * 453-wide selects and a pair of switches.
 *
 * The chip inside the monthly option is the one piece of this screen that
 * answers the question people actually have — "so when does the first lot
 * turn up?" — so it is worked out from their own dates rather than written.
 *
 * @package CardsCo
 *
 * Three shapes, because step 6 is a different question depending on what
 * kind of date the occasion has. A birthday differs per person, so the
 * question is how to group the sending. Christmas is the same day for
 * everybody but the category covers eleven of them, so the question is which
 * one. A promotion has no date at all, so the question is simply when.
 *
 * @var array $timing    From CardsCo_Dashboard::setup_timing().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php cardsco_nonce_field( 'cardsco_setup' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="6" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<?php if ( 'fixed' === ( $timing['shape'] ?? '' ) ) : ?>

		<div class="cc-tim">
			<fieldset class="cc-tim-pick">
				<legend class="cc-tim-legend"><?php esc_html_e( 'Which occasion is this for?', 'cardsco' ); ?></legend>
				<p class="cc-tim-note"><?php esc_html_e( 'The date comes with it — these fall on the same day every year.', 'cardsco' ); ?></p>

				<div class="cc-tim-grid">
					<?php foreach ( $timing['types'] as $cc_type ) : ?>
						<label class="cc-tim-chip<?php echo (int) $timing['chosen'] === (int) $cc_type['id'] ? ' is-on' : ''; ?>">
							<input type="radio" name="occasion_type_id" value="<?php echo esc_attr( (int) $cc_type['id'] ); ?>"
								<?php checked( (int) $timing['chosen'], (int) $cc_type['id'] ); ?> />
							<span class="cc-tim-chip-name"><?php echo esc_html( $cc_type['label'] ); ?></span>
							<span class="cc-tim-chip-when"><?php echo esc_html( $cc_type['when'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="cc-tim-panel">
				<p class="cc-tim-row">
					<label for="cc-tim-days"><?php esc_html_e( 'Arrive this many days before', 'cardsco' ); ?></label>
					<select id="cc-tim-days" name="days_before">
						<?php foreach ( array( 3, 5, 7, 10, 14 ) as $cc_d ) : ?>
							<option value="<?php echo esc_attr( $cc_d ); ?>" <?php selected( (int) $timing['days'], $cc_d ); ?>>
								<?php
								printf(
									/* translators: %s: a number of days. */
									esc_html( _n( '%s day', '%s days', $cc_d, 'cardsco' ) ),
									esc_html( number_format_i18n( $cc_d ) )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="cc-tim-row">
					<label for="cc-tim-runs"><?php esc_html_e( 'How often', 'cardsco' ); ?></label>
					<select id="cc-tim-runs" name="recurrence">
						<?php foreach ( $timing['runs'] as $cc_key => $cc_label ) : ?>
							<option value="<?php echo esc_attr( $cc_key ); ?>" <?php selected( $timing['run'], $cc_key ); ?>>
								<?php echo esc_html( $cc_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php if ( $timing['lands'] ) : ?>
					<p class="cc-tim-when">
						<?php
						printf(
							/* translators: 1: the date cards land, 2: the date the box is posted. */
							esc_html__( 'Lands %1$s · we post by %2$s', 'cardsco' ),
							esc_html( $timing['lands'] ),
							esc_html( $timing['posts'] )
						);
						?>
					</p>
				<?php else : ?>
					<p class="cc-tim-when"><?php esc_html_e( 'Pick an occasion above to see the dates.', 'cardsco' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'none' === ( $timing['shape'] ?? '' ) ) : ?>

		<div class="cc-tim">
			<div class="cc-tim-panel">
				<p class="cc-tim-lede"><?php esc_html_e( 'This occasion has no date of its own, so you choose when the cards land.', 'cardsco' ); ?></p>

				<p class="cc-tim-row">
					<label for="cc-tim-lands"><?php esc_html_e( 'Cards should land on', 'cardsco' ); ?></label>
					<input type="date" id="cc-tim-lands" name="starts_on"
						min="<?php echo esc_attr( $timing['earliest'] ); ?>"
						value="<?php echo esc_attr( $timing['lands'] ); ?>" />
				</p>

				<p class="cc-tim-row">
					<label for="cc-tim-days"><?php esc_html_e( 'Post this many days ahead', 'cardsco' ); ?></label>
					<select id="cc-tim-days" name="days_before">
						<?php foreach ( array( 3, 5, 7, 10 ) as $cc_d ) : ?>
							<option value="<?php echo esc_attr( $cc_d ); ?>" <?php selected( (int) $timing['days'], $cc_d ); ?>>
								<?php
								printf(
									/* translators: %s: a number of days. */
									esc_html( _n( '%s day', '%s days', $cc_d, 'cardsco' ) ),
									esc_html( number_format_i18n( $cc_d ) )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php if ( $timing['posts'] ) : ?>
					<p class="cc-tim-when">
						<?php
						printf(
							/* translators: 1: how many cards, 2: the date they are posted. */
							esc_html__( '%1$s written and posted by %2$s', 'cardsco' ),
							esc_html( sprintf( _n( '%s card', '%s cards', $timing['people'], 'cardsco' ), number_format_i18n( $timing['people'] ) ) ),
							esc_html( $timing['posts'] )
						);
						?>
					</p>
				<?php else : ?>
					<p class="cc-tim-when"><?php esc_html_e( 'Pick a date to see when we post.', 'cardsco' ); ?></p>
				<?php endif; ?>

				<p class="cc-tim-note"><?php esc_html_e( 'Cards are written by hand, so the earliest date is a week out.', 'cardsco' ); ?></p>
			</div>
		</div>

	<?php else : ?>

	<div class="cc-tim">
		<?php foreach ( $timing['modes'] as $cc_opt ) : ?>
			<label class="cc-opt<?php echo $cc_opt['on'] ? ' is-on' : ''; ?>">
				<input type="radio" name="timing_mode" value="<?php echo esc_attr( $cc_opt['key'] ); ?>" <?php checked( $cc_opt['on'] ); ?> />
				<span class="cc-opt-dot" aria-hidden="true"></span>

				<span class="cc-opt-text">
					<span class="cc-opt-head"><?php echo esc_html( $cc_opt['head'] ); ?></span>
					<span class="cc-opt-sub"><?php echo esc_html( $cc_opt['sub'] ); ?></span>

					<?php if ( $cc_opt['chip'] ) : ?>
						<span class="cc-opt-chip">
							<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'box', 'cc-opt-chip-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
							<span><?php echo esc_html( $cc_opt['chip'] ); ?></span>
						</span>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<section class="cc-tim-panel">
		<p class="cc-tim-title"><?php esc_html_e( 'Settings', 'cardsco' ); ?></p>

		<div class="cc-tim-row">
			<p class="cc-field">
				<label for="cc-tim-start"><?php esc_html_e( 'Start from', 'cardsco' ); ?></label>
				<span class="cc-select">
					<select id="cc-tim-start" name="starts_on">
						<?php foreach ( $timing['starts'] as $cc_value => $cc_label ) : ?>
							<option value="<?php echo esc_attr( $cc_value ); ?>" <?php selected( $timing['start'], $cc_value ); ?>>
								<?php echo esc_html( $cc_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'chevron-down', 'cc-select-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</span>
			</p>

			<p class="cc-field">
				<label for="cc-tim-runs"><?php esc_html_e( 'Runs', 'cardsco' ); ?></label>

				<?php if ( empty( $timing['runs'] ) ) : ?>
					<?php
					/*
					 * Nothing to choose: this occasion happens once for each
					 * person. The value still goes with the form so the saved
					 * programme says the same thing the screen does.
					 */
					?>
					<span class="cc-tim-fixed"><?php echo esc_html( $timing['run_fixed'] ); ?></span>
					<input type="hidden" name="recurrence" value="<?php echo esc_attr( $timing['run'] ); ?>" />
				<?php else : ?>
					<span class="cc-select">
						<select id="cc-tim-runs" name="recurrence">
							<?php foreach ( $timing['runs'] as $cc_value => $cc_label ) : ?>
								<option value="<?php echo esc_attr( $cc_value ); ?>" <?php selected( $timing['run'], $cc_value ); ?>>
									<?php echo esc_html( $cc_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'chevron-down', 'cc-select-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</span>
				<?php endif; ?>
			</p>
		</div>

		<?php /* A switch about boxes, on an occasion that has none, is a switch about nothing. */ ?>
		<?php if ( ! empty( $timing['boxes'] ) ) : ?>
		<label class="cc-switch">
			<input type="checkbox" name="notify_before_box" value="1" <?php checked( $timing['notify'] ); ?> />
			<span class="cc-switch-track" aria-hidden="true"><span class="cc-switch-knob"></span></span>
			<span class="cc-switch-text"><?php esc_html_e( 'Email me before each box ships', 'cardsco' ); ?></span>
		</label>
		<?php endif; ?>

		<label class="cc-switch">
			<input type="checkbox" name="weekend_rule" value="1" <?php checked( $timing['weekend'] ); ?> />
			<span class="cc-switch-track" aria-hidden="true"><span class="cc-switch-knob"></span></span>
			<span class="cc-switch-text">
				<?php
				/* Named rather than assumed: not every occasion is a birthday. */
				printf(
					/* translators: %s: the occasion, e.g. "birthday". */
					esc_html__( 'If a %s falls at the weekend, send the card on the Friday before', 'cardsco' ),
					esc_html( $timing['noun'] ?? __( 'date', 'cardsco' ) )
				);
				?>
			</span>
		</label>
	</section>

	<?php endif; ?>
</form>
