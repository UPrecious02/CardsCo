<?php
/**
 * Occasions: what is coming up, and the occasion library.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var string        $tab
 * @var int           $days
 * @var int           $year
 * @var array[]       $upcoming
 * @var array[]       $types
 * @var array[]       $unconfirmed
 */

defined( 'ABSPATH' ) || exit;

$cardsco_base   = admin_url( 'admin.php?page=cardsco-occasions' );
$cardsco_months = cardsco_month_choices();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Occasions', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<?php if ( $unconfirmed ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong>
					<?php
					printf(
						/* translators: %d: year. */
						esc_html__( '%d needs confirming for moveable occasions.', 'cardsco' ),
						(int) $year
					);
					?>
				</strong>
			</p>
			<p>
				<?php
				$cardsco_names = wp_list_pluck( $unconfirmed, 'label' );
				echo esc_html( implode( ', ', $cardsco_names ) );
				?>
				&mdash;
				<?php esc_html_e( 'these move each year, so nothing is scheduled for them until a date is set.', 'cardsco' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'library', 'company' => $company_id, 'year' => $year ), $cardsco_base ) ); ?>">
					<?php esc_html_e( 'Set them now', 'cardsco' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'upcoming', 'company' => $company_id ), $cardsco_base ) ); ?>"
			class="nav-tab <?php echo 'upcoming' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Upcoming', 'cardsco' ); ?>
		</a>
		<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'library', 'company' => $company_id, 'year' => $year ), $cardsco_base ) ); ?>"
			class="nav-tab <?php echo 'library' === $tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Occasion library', 'cardsco' ); ?>
		</a>
	</h2>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty" style="margin-top:16px">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( 'upcoming' === $tab ) : ?>

		<div class="cardsco-bar">
			<?php $this->company_switcher( $company_id, 'cardsco-occasions' ); ?>

			<form method="get">
				<input type="hidden" name="page" value="cardsco-occasions" />
				<input type="hidden" name="company" value="<?php echo esc_attr( $company_id ); ?>" />
				<div class="cardsco-field">
					<label for="cardsco-days"><?php esc_html_e( 'Looking ahead', 'cardsco' ); ?></label>
					<select name="days" id="cardsco-days" onchange="this.form.submit()">
						<?php foreach ( array( 7, 30, 60, 90, 180, 365 ) as $cardsco_opt ) : ?>
							<option value="<?php echo esc_attr( $cardsco_opt ); ?>" <?php selected( $days, $cardsco_opt ); ?>>
								<?php
								printf(
									/* translators: %d: number of days. */
									esc_html( _n( '%d day', '%d days', $cardsco_opt, 'cardsco' ) ),
									(int) $cardsco_opt
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</form>
		</div>

		<?php if ( empty( $upcoming ) ) : ?>
			<div class="cardsco-empty">
				<p><strong><?php esc_html_e( 'Nothing due in this window.', 'cardsco' ); ?></strong></p>
				<p><?php esc_html_e( 'Add a birthday or an anniversary to someone and it will appear here.', 'cardsco' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:22%"><?php esc_html_e( 'Who', 'cardsco' ); ?></th>
						<th scope="col" style="width:18%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
						<th scope="col" style="width:16%"><?php esc_html_e( 'Date', 'cardsco' ); ?></th>
						<th scope="col" style="width:14%"><?php esc_html_e( 'When', 'cardsco' ); ?></th>
						<th scope="col" style="width:16%"><?php esc_html_e( 'Write by', 'cardsco' ); ?></th>
						<th scope="col" style="width:14%"><?php esc_html_e( 'Address', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $upcoming as $cardsco_occ ) : ?>
					<?php
					$cardsco_lead = $cardsco_occ['lead_days'] ? (int) $cardsco_occ['lead_days'] : (int) $cardsco_occ['type_lead_days'];
					$cardsco_lead = $cardsco_lead ? $cardsco_lead : (int) CardsCo_Settings::get( 'default_lead_days', null, $company_id );
					$cardsco_wby  = cardsco_write_by_date( $cardsco_occ['next_occurrence'], $cardsco_lead );
					$cardsco_late = $cardsco_wby < cardsco_now( 'date' );
					$cardsco_edit = add_query_arg(
						array(
							'page'      => CardsCo_Admin::MENU,
							'company'   => $company_id,
							'action'    => 'edit',
							'recipient' => (int) $cardsco_occ['recipient_id'],
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $cardsco_edit ); ?>">
								<?php echo esc_html( trim( $cardsco_occ['first_name'] . ' ' . $cardsco_occ['last_name'] ) ); ?>
							</a></strong>
							<?php if ( $cardsco_occ['recipient_company'] ) : ?>
								<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_occ['recipient_company'] ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $cardsco_occ['label'] ); ?></td>
						<td>
							<?php echo esc_html( cardsco_format_occasion_date( $cardsco_occ['event_month'], $cardsco_occ['event_day'] ) ); ?>
						</td>
						<td><?php echo esc_html( cardsco_days_away( $cardsco_occ['next_occurrence'] ) ); ?></td>
						<td>
							<?php echo esc_html( $cardsco_wby ); ?>
							<?php if ( $cardsco_late ) : ?>
								<span class="cardsco-pill is-warn"><?php esc_html_e( 'Overdue', 'cardsco' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'complete' === $cardsco_occ['address_status'] ) : ?>
								<span class="cardsco-pill is-good"><?php esc_html_e( 'Ready', 'cardsco' ); ?></span>
							<?php else : ?>
								<span class="cardsco-pill is-warn"><?php esc_html_e( 'Will hold', 'cardsco' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php else : ?>

		<div class="cardsco-bar">
			<?php $this->company_switcher( $company_id, 'cardsco-occasions' ); ?>

			<form method="get">
				<input type="hidden" name="page" value="cardsco-occasions" />
				<input type="hidden" name="tab" value="library" />
				<input type="hidden" name="company" value="<?php echo esc_attr( $company_id ); ?>" />
				<div class="cardsco-field">
					<label for="cardsco-year"><?php esc_html_e( 'Confirming dates for', 'cardsco' ); ?></label>
					<select name="year" id="cardsco-year" onchange="this.form.submit()">
						<?php
						$cardsco_this = (int) substr( cardsco_now( 'date' ), 0, 4 );
						for ( $cardsco_y = $cardsco_this; $cardsco_y <= $cardsco_this + 2; $cardsco_y++ ) :
							?>
							<option value="<?php echo esc_attr( $cardsco_y ); ?>" <?php selected( $year, $cardsco_y ); ?>><?php echo esc_html( $cardsco_y ); ?></option>
						<?php endfor; ?>
					</select>
				</div>
			</form>
		</div>

		<?php
		// Loaded once for the whole table rather than per row.
		$cardsco_all_cards    = CardsCo_Card::for_occasion( 0, $company_id );
		$cardsco_all_messages = CardsCo_Message::for_occasion( 0, $company_id );
		?>

		<p class="description" style="max-width:760px">
			<?php esc_html_e( 'Pick the card and wording to send for each personal occasion. Until both are chosen nothing is queued for it, because the system will not choose artwork on a company\'s behalf.', 'cardsco' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_save_occasion_types" />
			<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />
			<input type="hidden" name="year" value="<?php echo esc_attr( $year ); ?>" />

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:20%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
						<th scope="col" style="width:14%"><?php printf( esc_html__( 'Date in %d', 'cardsco' ), (int) $year ); ?></th>
						<th scope="col" style="width:21%"><?php esc_html_e( 'Card to send', 'cardsco' ); ?></th>
						<th scope="col" style="width:21%"><?php esc_html_e( 'Wording', 'cardsco' ); ?></th>
						<th scope="col" style="width:12%"><?php esc_html_e( 'Lead days', 'cardsco' ); ?></th>
						<th scope="col" style="width:12%"><?php esc_html_e( 'In use', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $types as $cardsco_type ) : ?>
					<?php
					$cardsco_tid  = (int) $cardsco_type['id'];
					$cardsco_own  = (int) $cardsco_type['company_id'] > 0;
					$cardsco_date = CardsCo_Occasion_Type::resolve_date( $cardsco_type, $year );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $cardsco_type['label'] ); ?></strong>
							<?php if ( $cardsco_own ) : ?>
								<span class="cardsco-pill"><?php esc_html_e( 'Yours', 'cardsco' ); ?></span>
							<?php endif; ?>
							<div style="color:#646970;font-size:12px">
								<?php
								echo esc_html(
									( 'seasonal' === $cardsco_type['mode'] ? __( 'Seasonal', 'cardsco' ) : __( 'Per person', 'cardsco' ) )
									. ' · '
									. ( 'both' === $cardsco_type['applies_to'] ? __( 'everyone', 'cardsco' ) : $cardsco_type['applies_to'] )
								);
								?>
							</div>
						</td>
						<td>
							<?php if ( 'dated' === $cardsco_type['mode'] ) : ?>
								<span style="color:#646970"><?php esc_html_e( 'Set per person', 'cardsco' ); ?></span>
							<?php elseif ( $cardsco_type['requires_annual_date'] ) : ?>
								<input type="date" name="types[<?php echo esc_attr( $cardsco_tid ); ?>][annual_date]"
									value="<?php echo esc_attr( CardsCo_Occasion_Type::get_annual_date( $cardsco_type['slug'], $year ) ); ?>" />
								<?php if ( ! $cardsco_date ) : ?>
									<div><span class="cardsco-pill is-warn"><?php esc_html_e( 'Not set', 'cardsco' ); ?></span></div>
								<?php endif; ?>
							<?php else : ?>
								<?php echo esc_html( cardsco_format_occasion_date( $cardsco_type['fixed_month'], $cardsco_type['fixed_day'] ) ); ?>
							<?php endif; ?>
						</td>

						<?php if ( 'dated' === $cardsco_type['mode'] ) : ?>
							<?php
							$cardsco_pick    = CardsCo_Scheduler::defaults_for( $company_id, $cardsco_tid );
							$cardsco_cards   = cardsco_filter_by_occasion( $cardsco_all_cards, $cardsco_tid );
							$cardsco_msgs    = cardsco_filter_by_occasion( $cardsco_all_messages, $cardsco_tid );
							?>
							<td>
								<select name="types[<?php echo esc_attr( $cardsco_tid ); ?>][card]" style="width:100%">
									<option value="0"><?php esc_html_e( '— none yet —', 'cardsco' ); ?></option>
									<?php foreach ( $cardsco_cards as $cardsco_opt ) : ?>
										<option value="<?php echo esc_attr( $cardsco_opt->ID ); ?>" <?php selected( $cardsco_pick['card_id'], (int) $cardsco_opt->ID ); ?>>
											<?php echo esc_html( $cardsco_opt->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( ! $cardsco_pick['card_id'] ) : ?>
									<div><span class="cardsco-pill is-warn"><?php esc_html_e( 'Not sending', 'cardsco' ); ?></span></div>
								<?php endif; ?>
							</td>
							<td>
								<select name="types[<?php echo esc_attr( $cardsco_tid ); ?>][message]" style="width:100%">
									<option value="0"><?php esc_html_e( '— none yet —', 'cardsco' ); ?></option>
									<?php foreach ( $cardsco_msgs as $cardsco_opt ) : ?>
										<option value="<?php echo esc_attr( $cardsco_opt->ID ); ?>" <?php selected( $cardsco_pick['message_id'], (int) $cardsco_opt->ID ); ?>>
											<?php echo esc_html( $cardsco_opt->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						<?php else : ?>
							<td colspan="2" style="color:#646970">
								<?php esc_html_e( 'Chosen when you build the campaign', 'cardsco' ); ?>
							</td>
						<?php endif; ?>

						<td>
							<input type="number" min="0" max="120" step="1" style="width:70px"
								name="types[<?php echo esc_attr( $cardsco_tid ); ?>][lead_days]"
								value="<?php echo esc_attr( $cardsco_type['lead_days'] ); ?>" />
						</td>
						<td>
							<label>
								<input type="checkbox" name="types[<?php echo esc_attr( $cardsco_tid ); ?>][is_active]" value="1" <?php checked( (int) $cardsco_type['is_active'], 1 ); ?> />
								<?php esc_html_e( 'On', 'cardsco' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save occasions', 'cardsco' ); ?></button></p>
		</form>

		<hr />

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-form" style="max-width:640px">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_add_occasion_type" />
			<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />

			<h2 style="margin-top:0"><?php esc_html_e( 'Add an occasion for this company', 'cardsco' ); ?></h2>
			<p class="description" style="margin-bottom:14px">
				<?php esc_html_e( 'The brief leaves the occasion list open ended, so companies can add their own on top of the shared library.', 'cardsco' ); ?>
			</p>

			<div class="cardsco-grid">
				<div class="full">
					<label for="cardsco-new-label"><strong><?php esc_html_e( 'Name', 'cardsco' ); ?></strong></label>
					<input type="text" id="cardsco-new-label" name="label" required />
				</div>

				<div>
					<label for="cardsco-new-mode"><strong><?php esc_html_e( 'Kind', 'cardsco' ); ?></strong></label>
					<select id="cardsco-new-mode" name="mode">
						<option value="dated"><?php esc_html_e( 'Per person (a date each)', 'cardsco' ); ?></option>
						<option value="seasonal"><?php esc_html_e( 'Seasonal (one date for everyone)', 'cardsco' ); ?></option>
					</select>
				</div>

				<div>
					<label for="cardsco-new-applies"><strong><?php esc_html_e( 'Applies to', 'cardsco' ); ?></strong></label>
					<select id="cardsco-new-applies" name="applies_to">
						<option value="both"><?php esc_html_e( 'Everyone', 'cardsco' ); ?></option>
						<option value="customer"><?php esc_html_e( 'Customers', 'cardsco' ); ?></option>
						<option value="employee"><?php esc_html_e( 'Employees', 'cardsco' ); ?></option>
					</select>
				</div>

				<div>
					<label for="cardsco-new-rec"><strong><?php esc_html_e( 'Repeats', 'cardsco' ); ?></strong></label>
					<select id="cardsco-new-rec" name="recurrence">
						<option value="annual"><?php esc_html_e( 'Every year', 'cardsco' ); ?></option>
						<option value="once"><?php esc_html_e( 'Once only', 'cardsco' ); ?></option>
					</select>
				</div>

				<div>
					<label><strong><?php esc_html_e( 'Fixed date, if seasonal', 'cardsco' ); ?></strong></label>
					<div style="display:flex;gap:6px">
						<select name="fixed_day">
							<option value="">&mdash;</option>
							<?php for ( $cardsco_d = 1; $cardsco_d <= 31; $cardsco_d++ ) : ?>
								<option value="<?php echo esc_attr( $cardsco_d ); ?>"><?php echo esc_html( $cardsco_d ); ?></option>
							<?php endfor; ?>
						</select>
						<select name="fixed_month">
							<option value="">&mdash;</option>
							<?php foreach ( $cardsco_months as $cardsco_mnum => $cardsco_mname ) : ?>
								<option value="<?php echo esc_attr( $cardsco_mnum ); ?>"><?php echo esc_html( $cardsco_mname ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<p class="description"><?php esc_html_e( 'Leave blank if the date moves each year.', 'cardsco' ); ?></p>
				</div>
			</div>

			<p style="margin-top:16px"><button type="submit" class="button button-primary"><?php esc_html_e( 'Add occasion', 'cardsco' ); ?></button></p>
		</form>

	<?php endif; ?>
</div>
