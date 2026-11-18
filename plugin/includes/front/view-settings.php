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
			if ( $settings['name'] ) {
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

	<?php elseif ( 'profile' !== $settings['tab'] ) : ?>

		<section class="cc-set-soon">
			<p class="cc-set-soon-head"><?php esc_html_e( 'Billing is not built yet', 'cardsco' ); ?></p>
			<p><?php esc_html_e( 'Your plan, renewal date and invoices will live here once pricing is agreed.', 'cardsco' ); ?></p>
		</section>

	<?php else : ?>

		<?php /* multipart, because the logo is picked on this form. */ ?>
		<form class="cc-set-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $cc_base ); ?>">
			<?php wp_nonce_field( 'cardsco_settings', '_cardsco_nonce' ); ?>
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
					<h2><?php esc_html_e( 'Addresses', 'cardsco' ); ?></h2>
					<p><?php esc_html_e( 'Your billing address, and where returned cards come back to.', 'cardsco' ); ?></p>
				</div>

				<div class="cc-set-panel">
					<p class="cc-field cc-field-half">
						<label for="cc-set-address"><?php esc_html_e( 'Billing address', 'cardsco' ); ?></label>
						<textarea id="cc-set-address" name="billing_address" rows="4"><?php echo esc_textarea( $settings['address'] ); ?></textarea>
						<span class="cc-field-help"><?php esc_html_e( 'UK addresses only for now', 'cardsco' ); ?></span>
					</p>

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
