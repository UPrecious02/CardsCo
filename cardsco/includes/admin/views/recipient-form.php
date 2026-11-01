<?php
/**
 * Add or edit a recipient.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var array|null    $recipient
 * @var array[]       $segments
 * @var int[]         $selected
 */

defined( 'ABSPATH' ) || exit;

$cardsco_is_edit = ! empty( $recipient );
$cardsco_value   = static function ( $key, $fallback = '' ) use ( $recipient ) {
	return isset( $recipient[ $key ] ) ? $recipient[ $key ] : $fallback;
};
?>
<div class="wrap">
	<h1><?php echo $cardsco_is_edit ? esc_html__( 'Edit recipient', 'cardsco' ) : esc_html__( 'Add recipient', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-form">
		<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
		<input type="hidden" name="action" value="cardsco_save_recipient" />
		<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />
		<input type="hidden" name="recipient_id" value="<?php echo esc_attr( $cardsco_value( 'id', 0 ) ); ?>" />

		<div class="cardsco-grid">
			<div>
				<label for="cardsco-first"><strong><?php esc_html_e( 'First name', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-first" name="first_name" required value="<?php echo esc_attr( $cardsco_value( 'first_name' ) ); ?>" />
			</div>

			<div>
				<label for="cardsco-last"><strong><?php esc_html_e( 'Last name', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-last" name="last_name" value="<?php echo esc_attr( $cardsco_value( 'last_name' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Optional. Some people have one name.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-rtype"><strong><?php esc_html_e( 'Type', 'cardsco' ); ?></strong></label>
				<select id="cardsco-rtype" name="type">
					<option value="customer" <?php selected( $cardsco_value( 'type', 'customer' ), 'customer' ); ?>><?php esc_html_e( 'Customer', 'cardsco' ); ?></option>
					<option value="employee" <?php selected( $cardsco_value( 'type' ), 'employee' ); ?>><?php esc_html_e( 'Employee', 'cardsco' ); ?></option>
				</select>
			</div>

			<div>
				<label for="cardsco-email"><strong><?php esc_html_e( 'Email', 'cardsco' ); ?></strong></label>
				<input type="email" id="cardsco-email" name="email" value="<?php echo esc_attr( $cardsco_value( 'email' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Used for order updates only. Recipients are never emailed.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-rcompany"><strong><?php esc_html_e( 'Their company', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-rcompany" name="recipient_company" value="<?php echo esc_attr( $cardsco_value( 'recipient_company' ) ); ?>" />
			</div>

			<div>
				<label for="cardsco-job"><strong><?php esc_html_e( 'Job title', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-job" name="job_title" value="<?php echo esc_attr( $cardsco_value( 'job_title' ) ); ?>" />
			</div>

			<div class="full">
				<label for="cardsco-address"><strong><?php esc_html_e( 'Shipping address', 'cardsco' ); ?></strong></label>
				<?php
				// Real newlines, not &#10;: the entity would be escaped again
				// on the way out and show up literally in the placeholder.
				$cardsco_placeholder = __( "12 High Street\nLondon\nSW1A 1AA", 'cardsco' );
				?>
				<textarea id="cardsco-address" name="address" rows="4" placeholder="<?php echo esc_attr( $cardsco_placeholder ); ?>"><?php echo esc_textarea( $cardsco_value( 'address' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'The whole address, postcode included, exactly as it should appear on the envelope.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-postcode"><strong><?php esc_html_e( 'Postcode', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-postcode" name="postcode" value="<?php echo esc_attr( $cardsco_value( 'postcode' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'If the postcode is already in the address above, leave this blank and it will be picked up. Fill it in only to override what was found, or when the address has no postcode in it.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-country"><strong><?php esc_html_e( 'Country', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-country" name="country" maxlength="2" value="<?php echo esc_attr( $cardsco_value( 'country', 'GB' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'UK only for launch.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-since"><strong><?php esc_html_e( 'Customer since', 'cardsco' ); ?></strong></label>
				<input type="date" id="cardsco-since" name="relationship_since" value="<?php echo esc_attr( $cardsco_value( 'relationship_since' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Drives anniversary occasions.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-phone"><strong><?php esc_html_e( 'Phone', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-phone" name="phone" value="<?php echo esc_attr( $cardsco_value( 'phone' ) ); ?>" />
			</div>

			<div class="full">
				<strong><?php esc_html_e( 'Segments', 'cardsco' ); ?></strong>
				<?php if ( empty( $segments ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'No segments yet.', 'cardsco' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-segments&company=' . $company_id ) ); ?>"><?php esc_html_e( 'Create one', 'cardsco' ); ?></a>
					</p>
				<?php else : ?>
					<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
						<?php foreach ( $segments as $cardsco_seg ) : ?>
							<label style="display:flex;gap:6px;align-items:center">
								<input type="checkbox" name="segments[]" value="<?php echo esc_attr( $cardsco_seg['id'] ); ?>" <?php checked( in_array( (int) $cardsco_seg['id'], $selected, true ) ); ?> />
								<?php echo esc_html( $cardsco_seg['name'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="full">
				<label for="cardsco-notes"><strong><?php esc_html_e( 'Notes', 'cardsco' ); ?></strong></label>
				<textarea id="cardsco-notes" name="notes" rows="3"><?php echo esc_textarea( $cardsco_value( 'notes' ) ); ?></textarea>
			</div>

			<div class="full">
				<strong><?php esc_html_e( 'Occasions', 'cardsco' ); ?></strong>
				<p class="description">
					<?php esc_html_e( 'Leave a row blank to skip it. Clearing a saved date removes that occasion. The year is optional for anything that comes round every year.', 'cardsco' ); ?>
				</p>

				<?php
				$cardsco_rtype    = $cardsco_value( 'type', 'customer' );
				$cardsco_types    = CardsCo_Occasion_Type::all(
					$company_id,
					array(
						'mode'       => 'dated',
						'applies_to' => $cardsco_rtype,
					)
				);
				$cardsco_existing = array();

				if ( $cardsco_is_edit ) {
					foreach ( CardsCo_Occasion::for_recipient( (int) $recipient['id'] ) as $cardsco_occ ) {
						$cardsco_existing[ (int) $cardsco_occ['occasion_type_id'] ] = $cardsco_occ;
					}
				}

				$cardsco_months = cardsco_month_choices();
				?>

				<table class="wp-list-table widefat striped" style="margin-top:8px">
					<thead>
						<tr>
							<th scope="col" style="width:40%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
							<th scope="col" style="width:16%"><?php esc_html_e( 'Day', 'cardsco' ); ?></th>
							<th scope="col" style="width:28%"><?php esc_html_e( 'Month', 'cardsco' ); ?></th>
							<th scope="col" style="width:16%"><?php esc_html_e( 'Year', 'cardsco' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $cardsco_types as $cardsco_type ) : ?>
						<?php
						$cardsco_tid  = (int) $cardsco_type['id'];
						$cardsco_have = $cardsco_existing[ $cardsco_tid ] ?? null;
						$cardsco_once = 'once' === $cardsco_type['recurrence'];
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $cardsco_type['label'] ); ?></strong>
								<?php if ( $cardsco_once ) : ?>
									<span class="cardsco-pill"><?php esc_html_e( 'One-off, needs a year', 'cardsco' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<select name="occasions[<?php echo esc_attr( $cardsco_tid ); ?>][day]">
									<option value="">&mdash;</option>
									<?php for ( $cardsco_d = 1; $cardsco_d <= 31; $cardsco_d++ ) : ?>
										<option value="<?php echo esc_attr( $cardsco_d ); ?>" <?php selected( (int) ( $cardsco_have['event_day'] ?? 0 ), $cardsco_d ); ?>>
											<?php echo esc_html( $cardsco_d ); ?>
										</option>
									<?php endfor; ?>
								</select>
							</td>
							<td>
								<select name="occasions[<?php echo esc_attr( $cardsco_tid ); ?>][month]">
									<option value="">&mdash;</option>
									<?php foreach ( $cardsco_months as $cardsco_mnum => $cardsco_mname ) : ?>
										<option value="<?php echo esc_attr( $cardsco_mnum ); ?>" <?php selected( (int) ( $cardsco_have['event_month'] ?? 0 ), $cardsco_mnum ); ?>>
											<?php echo esc_html( $cardsco_mname ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="number" min="1900" max="2100" step="1" style="width:90px"
									name="occasions[<?php echo esc_attr( $cardsco_tid ); ?>][year]"
									value="<?php echo esc_attr( $cardsco_have['event_year'] ?? '' ); ?>"
									placeholder="<?php echo esc_attr( $cardsco_once ? esc_html__( 'Required', 'cardsco' ) : esc_html__( 'Optional', 'cardsco' ) ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! $cardsco_is_edit ) : ?>
					<p class="description">
						<?php esc_html_e( 'Occasions shown are the ones that apply to the type selected above. Save, then reopen if you change the type.', 'cardsco' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! $cardsco_is_edit ) : ?>
				<div class="full">
					<label style="display:flex;gap:6px;align-items:center">
						<input type="checkbox" name="allow_duplicate" value="1" />
						<?php esc_html_e( 'Save even if someone with this name and address already exists', 'cardsco' ); ?>
					</label>
				</div>
			<?php endif; ?>
		</div>

		<p style="margin-top:18px">
			<button type="submit" class="button button-primary">
				<?php echo $cardsco_is_edit ? esc_html__( 'Save changes', 'cardsco' ) : esc_html__( 'Add recipient', 'cardsco' ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . CardsCo_Admin::MENU . '&company=' . $company_id ) ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
		</p>
	</form>
</div>
