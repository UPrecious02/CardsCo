<?php
/**
 * The Settings screen.
 *
 * Measured from the Settings frame: content from x=280, a 328 label column
 * and a 792 panel at x=608 holding 363-wide fields in two columns with a 16
 * gutter, three sections 32 apart, and a save bar at the foot.
 *
 * Only the Company profile tab exists in the design. The other two are real
 * tabs in the frame with nothing drawn behind them, so they say so rather
 * than guess at content nobody has specified.
 *
 * @package CardsCo
 *
 * @var array  $settings From CardsCo_Dashboard::settings_screen().
 * @var array  $links
 * @var string $notice
 * @var string $error
 */

defined( 'ABSPATH' ) || exit;

$cc_base = remove_query_arg( array( 'cardsco_saved', 'cardsco_error' ) );
?>
<div class="cc cc-main">

	<?php require __DIR__ . '/part-verify-banner.php'; ?>

	<header class="cc-page-head">
		<h1 class="cc-page-title"><?php esc_html_e( 'Settings', 'cardsco' ); ?></h1>
		<p class="cc-page-sub">
			<?php
			if ( 'account' === $settings['tab'] ) {
				/* Not the company's: nothing on this tab reaches anybody else. */
				esc_html_e( 'Your own sign-in details', 'cardsco' );
			} elseif ( $settings['name'] ) {
				printf(
					/* translators: %s: the company's name. */
					esc_html__( '%s · changes apply to everyone on your team', 'cardsco' ),
					esc_html( $settings['name'] )
				);
			} else {
				esc_html_e( 'Changes apply to everyone on your team', 'cardsco' );
			}
			?>
		</p>
	</header>

	<nav class="cc-set-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'cardsco' ); ?>">
		<?php foreach ( $settings['tabs'] as $cc_tab ) : ?>
			<a class="cc-set-tab<?php echo $cc_tab['on'] ? ' is-on' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'tab', $cc_tab['key'], $cc_base ) ); ?>"
				<?php echo $cc_tab['on'] ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $cc_tab['label'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( $notice ) : ?>
		<p class="cc-set-notice" role="status"><?php echo esc_html( $notice ); ?></p>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<p class="cc-set-error" role="alert"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<?php if ( 'team' === $settings['tab'] ) : ?>

		<?php include CARDSCO_PATH . 'includes/front/partial-team.php'; ?>

	<?php elseif ( 'billing' === $settings['tab'] ) : ?>

		<?php
		/*
		 * Not a plan or a renewal date — there is no subscription here,
		 * cards are paid for as they are ordered. What this holds is who
		 * the invoice is made out to and where it goes, which the checkout
		 * has been reading from the company record all along with no way
		 * for anyone to set it.
		 *
		 * The billing address moved here from Company profile, where it
		 * sat under a heading about logos and sign-offs. Nothing about the
		 * field changed; it is the same name, the same column, and anything
		 * already saved is still here.
		 */
		?>
		<form class="cc-set-form" method="post" action="<?php echo esc_url( $cc_base ); ?>">
			<?php cardsco_nonce_field( 'cardsco_settings' ); ?>
			<input type="hidden" name="cardsco_settings_action" value="save" />

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Invoices', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Who your invoices are made out to, and where they are sent. Used to fill in the checkout too.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<div class="cc-set-grid">
						<p class="cc-field">
							<label for="cc-bill-to"><?php esc_html_e( 'Invoice to', 'cardsco' ); ?></label>
							<input type="text" id="cc-bill-to" name="billing_name" value="<?php echo esc_attr( $settings['bill_to'] ); ?>" autocomplete="billing name" />
							<span class="cc-field-help"><?php esc_html_e( 'A person or a department — whoever handles the bills', 'cardsco' ); ?></span>
						</p>
						<p class="cc-field">
							<label for="cc-bill-at"><?php esc_html_e( 'Send invoices to', 'cardsco' ); ?></label>
							<input type="email" id="cc-bill-at" name="billing_email" value="<?php echo esc_attr( $settings['bill_at'] ); ?>" autocomplete="billing email" />
							<span class="cc-field-help"><?php esc_html_e( 'Leave blank to use your main contact email', 'cardsco' ); ?></span>
						</p>
					</div>
				</div>
			</section>

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Billing address', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Printed on your invoices, and offered as the return address on envelopes.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<p class="cc-field cc-field-half">
						<label for="cc-set-address"><?php esc_html_e( 'Billing address', 'cardsco' ); ?></label>
						<textarea id="cc-set-address" name="billing_address" rows="4" autocomplete="billing street-address"><?php echo esc_textarea( $settings['address'] ); ?></textarea>
						<span class="cc-field-help"><?php esc_html_e( 'UK addresses only for now', 'cardsco' ); ?></span>
					</p>
				</div>
			</section>

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Past invoices', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Every order you have paid for, with its invoice.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<p class="cc-set-plain">
						<?php
						/*
						 * Only offered as a link when there is somewhere for
						 * it to go. If the orders page has not been found —
						 * the shortcode not placed yet, say — the sentence
						 * still reads, without a link that would quietly
						 * reload this screen instead.
						 */
						if ( ! empty( $links['orders'] ) ) {
							printf(
								/* translators: %s: a link to the orders screen, reading "your orders". */
								esc_html__( 'Invoices live with %s, where you can download them one at a time or all together.', 'cardsco' ),
								'<a href="' . esc_url( $links['orders'] ) . '">' . esc_html__( 'your orders', 'cardsco' ) . '</a>'
							);
						} else {
							esc_html_e( 'Invoices live with your orders, where you can download them one at a time or all together.', 'cardsco' );
						}
						?>
					</p>
				</div>
			</section>

			<?php
			/*
			 * The same footer the profile tab uses, including the disabled
			 * state: every company account carries the same WordPress
			 * capability, so whether somebody may actually change this
			 * depends on their role in the company, and the save handler
			 * refuses them either way.
			 */
			?>
			<footer class="cc-set-foot">
				<span class="cc-set-saved"><?php echo esc_html( $settings['saved'] ); ?></span>
				<a class="cc-set-cancel" href="<?php echo esc_url( $cc_base ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
				<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8"<?php echo $settings['may_edit'] ? '' : ' disabled'; ?>>
					<span><?php esc_html_e( 'Save changes', 'cardsco' ); ?></span>
				</button>
			</footer>
		</form>

	<?php elseif ( 'account' === $settings['tab'] ) : ?>

		<?php
		/*
		 * The one tab that is about the person and not the company, so it
		 * is open to everybody on the team whatever their role, and its
		 * button is never disabled for want of one.
		 */
		$cc_me = wp_get_current_user();
		?>
		<form class="cc-set-form" method="post" action="<?php echo esc_url( $cc_base ); ?>" autocomplete="off">
			<?php cardsco_nonce_field( 'cardsco_settings' ); ?>
			<input type="hidden" name="cardsco_settings_action" value="password" />

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Password', 'cardsco' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: the email address they sign in with. */
							esc_html__( 'You sign in as %s. Changing your password signs you out everywhere else.', 'cardsco' ),
							esc_html( $cc_me->user_email )
						);
						?>
					</p>
				</div>

				<div class="cc-set-panel">
					<?php /* For password managers: which account these belong to. */ ?>
					<input type="text" name="username" value="<?php echo esc_attr( $cc_me->user_email ); ?>" autocomplete="username" class="screen-reader-text" tabindex="-1" aria-hidden="true" readonly />

					<p class="cc-field">
						<label for="cc-pw-now"><?php esc_html_e( 'Current password', 'cardsco' ); ?></label>
						<input type="password" id="cc-pw-now" name="current_password" autocomplete="current-password" required />
					</p>
					<div class="cc-set-grid">
						<p class="cc-field">
							<label for="cc-pw-new"><?php esc_html_e( 'New password', 'cardsco' ); ?></label>
							<input type="password" id="cc-pw-new" name="new_password" autocomplete="new-password"
								minlength="<?php echo esc_attr( (int) CardsCo_Auth::MIN_PASSWORD ); ?>" required />
							<span class="cc-field-help">
								<?php
								/* translators: %d: the smallest number of characters allowed. */
								printf( esc_html__( 'At least %d characters', 'cardsco' ), (int) CardsCo_Auth::MIN_PASSWORD );
								?>
							</span>
						</p>
						<p class="cc-field">
							<label for="cc-pw-again"><?php esc_html_e( 'New password again', 'cardsco' ); ?></label>
							<input type="password" id="cc-pw-again" name="confirm_password" autocomplete="new-password"
								minlength="<?php echo esc_attr( (int) CardsCo_Auth::MIN_PASSWORD ); ?>" required />
						</p>
					</div>
				</div>
			</section>

			<footer class="cc-set-foot">
				<span class="cc-set-saved"></span>
				<a class="cc-set-cancel" href="<?php echo esc_url( $cc_base ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
				<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8">
					<span><?php esc_html_e( 'Change password', 'cardsco' ); ?></span>
				</button>
			</footer>
		</form>

		<?php
		/*
		 * For somebody who cannot fill in the first box. A form of its own,
		 * so pressing it does not need the three boxes above filled in, and
		 * a button rather than a link because it sends an email: nothing
		 * that does something should happen on a page merely being opened.
		 */
		?>
		<form class="cc-set-forgot" method="post" action="<?php echo esc_url( $cc_base ); ?>">
			<?php cardsco_nonce_field( 'cardsco_settings' ); ?>
			<input type="hidden" name="cardsco_settings_action" value="password_email" />
			<p>
				<?php esc_html_e( 'Can’t remember your current password?', 'cardsco' ); ?>
				<button type="submit" class="cc-set-forgot-go"><?php esc_html_e( 'Reset your password', 'cardsco' ); ?></button>
			</p>
			<p class="cc-set-forgot-how">
				<?php
				printf(
					/* translators: %s: the email address on the account. */
					esc_html__( 'We’ll email a link to %s. Open it and choose a new password — you won’t be asked for the old one.', 'cardsco' ),
					esc_html( $cc_me->user_email )
				);
				?>
			</p>
		</form>

	<?php elseif ( 'profile' !== $settings['tab'] ) : ?>

		<section class="cc-set-soon">
			<p class="cc-set-soon-head"><?php esc_html_e( 'Not built yet', 'cardsco' ); ?></p>
			<p><?php esc_html_e( 'There is nothing here at the moment.', 'cardsco' ); ?></p>
		</section>

	<?php else : ?>

		<?php /* multipart, because the logo is picked on this form. */ ?>
		<form class="cc-set-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $cc_base ); ?>">
			<?php cardsco_nonce_field( 'cardsco_settings' ); ?>
			<input type="hidden" name="cardsco_settings_action" value="save" />

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Company details', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Used on invoices and in emails we send on your behalf.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<div class="cc-set-grid">
						<p class="cc-field">
							<label for="cc-set-name"><?php esc_html_e( 'Company name', 'cardsco' ); ?></label>
							<input type="text" id="cc-set-name" name="name" value="<?php echo esc_attr( $settings['name'] ); ?>" />
							<span class="cc-field-help"><?php esc_html_e( 'As it should appear on invoices', 'cardsco' ); ?></span>
						</p>
						<p class="cc-field">
							<label for="cc-set-trading"><?php esc_html_e( 'Trading name', 'cardsco' ); ?></label>
							<input type="text" id="cc-set-trading" name="trading_name" value="<?php echo esc_attr( $settings['trading'] ); ?>" />
							<span class="cc-field-help"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span>
						</p>
						<p class="cc-field">
							<label for="cc-set-number"><?php esc_html_e( 'Company number', 'cardsco' ); ?></label>
							<input type="text" id="cc-set-number" name="company_number" value="<?php echo esc_attr( $settings['number'] ); ?>" />
							<span class="cc-field-help"><?php esc_html_e( 'Companies House', 'cardsco' ); ?></span>
						</p>
						<p class="cc-field">
							<label for="cc-set-vat"><?php esc_html_e( 'VAT number', 'cardsco' ); ?></label>
							<input type="text" id="cc-set-vat" name="vat_number" value="<?php echo esc_attr( $settings['vat'] ); ?>" />
							<span class="cc-field-help"><?php esc_html_e( 'Shown on every invoice', 'cardsco' ); ?></span>
						</p>
					</div>
				</div>
			</section>

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'Return address', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Where a card comes back to if it cannot be delivered.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<?php
					/*
					 * The billing address used to sit here. It is on the
					 * Billing tab now, which is where somebody looking for
					 * it would go. "Use my billing address" below still
					 * means the same field — the same column, the same
					 * saved value — it is just edited elsewhere.
					 */
					?>
					<fieldset class="cc-set-radios">
						<legend><?php esc_html_e( 'Return address on envelopes', 'cardsco' ); ?></legend>
						<?php foreach ( $settings['choices'] as $cc_key => $cc_label ) : ?>
							<label class="cc-radio">
								<input type="radio" name="return_mode" value="<?php echo esc_attr( $cc_key ); ?>" <?php checked( $settings['returns'], $cc_key ); ?> />
								<span class="cc-radio-dot" aria-hidden="true"></span>
								<span><?php echo esc_html( $cc_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<p class="cc-field cc-field-half cc-set-return<?php echo 'other' === $settings['returns'] ? '' : ' is-hidden'; ?>">
						<label for="cc-set-return"><?php esc_html_e( 'Return address', 'cardsco' ); ?></label>
						<textarea id="cc-set-return" name="return_address" rows="3"><?php echo esc_textarea( $settings['return'] ); ?></textarea>
					</p>
				</div>
			</section>

			<section class="cc-set-sec">
				<div class="cc-set-side">
					<h2><?php esc_html_e( 'On your cards', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Your logo goes on the back of every card. The sign-off is the default for new messages.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<div class="cc-set-logo">
						<span class="cc-set-logo-art">
							<?php if ( $settings['logo'] && $settings['logo']['url'] ) : ?>
								<img src="<?php echo esc_url( $settings['logo']['url'] ); ?>" alt="" />
							<?php else : ?>
								<span class="cc-set-logo-blank" aria-hidden="true"></span>
							<?php endif; ?>
						</span>

						<span class="cc-set-logo-text">
							<span class="cc-set-logo-name">
								<?php echo esc_html( $settings['logo'] ? $settings['logo']['name'] : __( 'No logo yet', 'cardsco' ) ); ?>
							</span>
							<span class="cc-set-logo-meta" data-cc-staged="<?php esc_attr_e( 'Chosen — press Save to upload it', 'cardsco' ); ?>">
								<?php
								if ( $settings['logo'] && $settings['logo']['bytes'] ) {
									printf(
										/* translators: %s: the file's size. */
										esc_html__( '%s · printed in one colour, 20 mm wide', 'cardsco' ),
										esc_html( size_format( $settings['logo']['bytes'] ) )
									);
								} else {
									esc_html_e( 'Printed in one colour, 20 mm wide', 'cardsco' );
								}
								?>
							</span>
						</span>

						<?php
						/*
						 * A label wrapping a hidden file input, not a link. This
						 * used to point at /wp-admin/upload.php, which customers
						 * are shut out of, so the button bounced them to the
						 * dashboard instead of letting them choose a file.
						 */
						?>
						<label class="cc-btn cc-btn-ghost cc-btn-r8 cc-set-logo-pick">
							<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'upload', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
							<span><?php echo esc_html( $settings['logo'] ? __( 'Replace', 'cardsco' ) : __( 'Upload', 'cardsco' ) ); ?></span>
							<input type="file" name="logo" accept="image/png,image/jpeg" data-cc-logo />
						</label>

						<?php if ( $settings['logo'] ) : ?>
							<button type="submit" class="cc-set-logo-remove" name="remove_logo" value="1">
								<?php esc_html_e( 'Remove', 'cardsco' ); ?>
							</button>
						<?php endif; ?>
					</div>

					<p class="cc-field cc-field-half">
						<label for="cc-set-signoff"><?php esc_html_e( 'Default sign-off', 'cardsco' ); ?></label>
						<input type="text" id="cc-set-signoff" name="card_signoff" value="<?php echo esc_attr( $settings['signoff'] ); ?>" />
						<span class="cc-field-help"><?php esc_html_e( 'You can change it on any campaign.', 'cardsco' ); ?></span>
					</p>
				</div>
			</section>

			<footer class="cc-set-foot">
				<span class="cc-set-saved"><?php echo esc_html( $settings['saved'] ); ?></span>
				<a class="cc-set-cancel" href="<?php echo esc_url( $cc_base ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
				<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8"<?php echo $settings['may_edit'] ? '' : ' disabled'; ?>>
					<span><?php esc_html_e( 'Save changes', 'cardsco' ); ?></span>
				</button>
			</footer>
		</form>

	<?php endif; ?>
</div>
