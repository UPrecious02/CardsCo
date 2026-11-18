<?php
/**
 * Import, in four steps.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var string        $step
 * @var int           $batch_id
 * @var array|null    $batch
 * @var array         $steps
 * @var string[]      $headers
 * @var array         $samples
 * @var array         $report
 * @var array         $result
 * @var array[]       $history
 */

defined( 'ABSPATH' ) || exit;

$cardsco_base = admin_url( 'admin.php?page=cardsco-import' );
$cardsco_now  = in_array( $step, array( 'run', 'done' ), true ) ? 'review' : $step;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Import people', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<ol class="cardsco-steps">
		<?php
		$cardsco_i    = 0;
		$cardsco_keys = array_keys( $steps );
		$cardsco_at   = array_search( $cardsco_now, $cardsco_keys, true );
		$cardsco_at   = false === $cardsco_at ? 0 : $cardsco_at;

		foreach ( $steps as $cardsco_key => $cardsco_label ) :
			$cardsco_state = $cardsco_i < $cardsco_at ? 'is-done' : ( $cardsco_i === $cardsco_at ? 'is-now' : '' );
			$cardsco_i++;
			?>
			<li class="<?php echo esc_attr( $cardsco_state ); ?>">
				<span><?php echo esc_html( $cardsco_i ); ?></span><?php echo esc_html( $cardsco_label ); ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<?php if ( 'upload' === $step ) : ?>

		<?php if ( ! $company_id ) : ?>
			<div class="cardsco-empty">
				<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
				<p><?php esc_html_e( 'People are imported into a company, so create one first.', 'cardsco' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
			</div>
		<?php else : ?>

			<div class="cardsco-bar">
				<?php $this->company_switcher( $company_id, 'cardsco-import' ); ?>
			</div>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-form">
				<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
				<input type="hidden" name="action" value="cardsco_import_upload" />
				<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />

				<p>
					<strong><?php esc_html_e( 'These people are', 'cardsco' ); ?></strong><br />
					<label style="margin-right:16px">
						<input type="radio" name="recipient_type" value="customer" checked /> <?php esc_html_e( 'Customers', 'cardsco' ); ?>
					</label>
					<label>
						<input type="radio" name="recipient_type" value="employee" /> <?php esc_html_e( 'Employees', 'cardsco' ); ?>
					</label>
				</p>

				<p>
					<label for="cardsco-file"><strong><?php esc_html_e( 'CSV file', 'cardsco' ); ?></strong></label><br />
					<input type="file" id="cardsco-file" name="import_file" accept=".csv,.txt,.tsv,text/csv" required />
				</p>

				<p class="description" style="max-width:620px">
					<?php esc_html_e( 'The first row should be the column names. Nothing is saved until the last step, so you can back out at any point. In Excel or Google Sheets, use File then Download or Save As and pick CSV.', 'cardsco' ); ?>
				</p>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and continue', 'cardsco' ); ?></button></p>
			</form>

			<?php if ( ! empty( $history ) ) : ?>
				<h2 style="margin-top:32px"><?php esc_html_e( 'Recent imports', 'cardsco' ); ?></h2>
				<table class="wp-list-table widefat fixed striped" style="max-width:900px">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'File', 'cardsco' ); ?></th>
							<th scope="col" style="width:14%"><?php esc_html_e( 'Rows', 'cardsco' ); ?></th>
							<th scope="col" style="width:14%"><?php esc_html_e( 'Added', 'cardsco' ); ?></th>
							<th scope="col" style="width:14%"><?php esc_html_e( 'Updated', 'cardsco' ); ?></th>
							<th scope="col" style="width:16%"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $history as $cardsco_h ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $cardsco_h['filename'] ); ?></strong>
								<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_h['created_at'] ); ?></div>
							</td>
							<td><?php echo esc_html( number_format_i18n( $cardsco_h['total_rows'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $cardsco_h['created_count'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $cardsco_h['updated_count'] ) ); ?></td>
							<td><span class="cardsco-pill"><?php echo esc_html( ucfirst( $cardsco_h['status'] ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		<?php endif; ?>

	<?php elseif ( 'map' === $step ) : ?>

		<p class="description" style="max-width:720px">
			<?php
			printf(
				/* translators: 1: file name, 2: number of rows. */
				esc_html__( '%1$s, %2$s rows. Anything already matched was recognised from the column name — change anything that looks wrong. Columns holding dates are set on the next step.', 'cardsco' ),
				'<strong>' . esc_html( $batch['filename'] ) . '</strong>',
				esc_html( number_format_i18n( $batch['total_rows'] ) )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_import_map" />
			<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch['id'] ); ?>" />

			<table class="wp-list-table widefat fixed striped" style="max-width:1000px">
				<thead>
					<tr>
						<th scope="col" style="width:26%"><?php esc_html_e( 'Column in your file', 'cardsco' ); ?></th>
						<th scope="col" style="width:38%"><?php esc_html_e( 'Example values', 'cardsco' ); ?></th>
						<th scope="col" style="width:36%"><?php esc_html_e( 'Becomes', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $headers as $cardsco_header ) : ?>
					<?php
					$cardsco_current  = $batch['column_map']['fields'][ $cardsco_header ] ?? '';
					$cardsco_occasion = $batch['column_map']['occasions'][ $cardsco_header ] ?? 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $cardsco_header ); ?></strong></td>
						<td style="color:#646970">
							<?php
							echo empty( $samples[ $cardsco_header ] )
								? '<em>' . esc_html__( 'empty', 'cardsco' ) . '</em>'
								: esc_html( implode( ' · ', $samples[ $cardsco_header ] ) );
							?>
						</td>
						<td>
							<?php if ( $cardsco_occasion ) : ?>
								<?php $cardsco_type = CardsCo_Occasion_Type::get( $cardsco_occasion ); ?>
								<span class="cardsco-pill is-good">
									<?php
									printf(
										/* translators: %s: occasion name. */
										esc_html__( 'Date for %s', 'cardsco' ),
										esc_html( $cardsco_type ? $cardsco_type['label'] : '' )
									);
									?>
								</span>
								<div style="color:#646970;font-size:12px"><?php esc_html_e( 'Set on the next step', 'cardsco' ); ?></div>
							<?php else : ?>
								<select name="map[<?php echo esc_attr( $cardsco_header ); ?>]" style="width:100%">
									<option value=""><?php esc_html_e( '— Skip this column —', 'cardsco' ); ?></option>
									<?php foreach ( CardsCo_Import::fields() as $cardsco_key => $cardsco_field ) : ?>
										<option value="<?php echo esc_attr( $cardsco_key ); ?>" <?php selected( $cardsco_current, $cardsco_key ); ?>>
											<?php echo esc_html( $cardsco_field['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:16px">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue to occasions', 'cardsco' ); ?></button>
				<a class="button" href="<?php echo esc_url( $cardsco_base ); ?>"><?php esc_html_e( 'Start over', 'cardsco' ); ?></a>
			</p>
		</form>

	<?php elseif ( 'occasions' === $step ) : ?>

		<?php
		$cardsco_types = CardsCo_Occasion_Type::all(
			$company_id,
			array(
				'mode'       => 'dated',
				'applies_to' => $batch['recipient_type'],
			)
		);

		$cardsco_mapped_fields = array_keys( (array) ( $batch['column_map']['fields'] ?? array() ) );
		$cardsco_date_headers  = array_keys( (array) ( $batch['column_map']['occasions'] ?? array() ) );
		$cardsco_csv           = CardsCo_Import::csv( $batch );
		$cardsco_detect        = ( ! is_wp_error( $cardsco_csv ) && $cardsco_date_headers )
			? CardsCo_Import::inspect_date_order( $cardsco_csv, $cardsco_date_headers )
			: array(
				'order'     => $batch['column_map']['date_order'] ?? 'day',
				'confident' => true,
				'ambiguous' => 0,
			);
		$cardsco_order    = $batch['column_map']['date_order'] ?? $cardsco_detect['order'];
		$cardsco_segments = CardsCo_Segment::all( $company_id );
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_import_occasions" />
			<input type="hidden" name="batch_id" value="<?php echo esc_attr( $batch['id'] ); ?>" />

			<p class="description" style="max-width:720px">
				<?php esc_html_e( 'Point any column holding a date at the occasion it belongs to. A blank year is fine for anything that comes round every year.', 'cardsco' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped" style="max-width:1000px">
				<thead>
					<tr>
						<th scope="col" style="width:26%"><?php esc_html_e( 'Column in your file', 'cardsco' ); ?></th>
						<th scope="col" style="width:38%"><?php esc_html_e( 'Example values', 'cardsco' ); ?></th>
						<th scope="col" style="width:36%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $headers as $cardsco_header ) : ?>
					<?php
					// A column already claimed as a contact field is not offered
					// here, so one column cannot be two things at once.
					if ( in_array( $cardsco_header, $cardsco_mapped_fields, true ) ) {
						continue;
					}

					$cardsco_current = $batch['column_map']['occasions'][ $cardsco_header ] ?? 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $cardsco_header ); ?></strong></td>
						<td style="color:#646970">
							<?php
							echo empty( $samples[ $cardsco_header ] )
								? '<em>' . esc_html__( 'empty', 'cardsco' ) . '</em>'
								: esc_html( implode( ' · ', $samples[ $cardsco_header ] ) );
							?>
						</td>
						<td>
							<select name="occasions[<?php echo esc_attr( $cardsco_header ); ?>]" style="width:100%">
								<option value="0"><?php esc_html_e( '— Not a date —', 'cardsco' ); ?></option>
								<?php foreach ( $cardsco_types as $cardsco_type ) : ?>
									<option value="<?php echo esc_attr( $cardsco_type['id'] ); ?>" <?php selected( (int) $cardsco_current, (int) $cardsco_type['id'] ); ?>>
										<?php echo esc_html( $cardsco_type['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="cardsco-form" style="margin-top:20px;max-width:720px">
				<h2 style="margin-top:0"><?php esc_html_e( 'How dates are written', 'cardsco' ); ?></h2>

				<?php if ( ! $cardsco_detect['confident'] ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 12px">
						<p>
							<strong><?php esc_html_e( 'Worth checking.', 'cardsco' ); ?></strong>
							<?php esc_html_e( 'Every date in this file works read either way, so the file itself cannot settle it. Pick the one your spreadsheet uses — getting this wrong sends cards in the wrong month.', 'cardsco' ); ?>
						</p>
					</div>
				<?php elseif ( $cardsco_date_headers ) : ?>
					<p class="description" style="margin-top:0">
						<?php esc_html_e( 'Worked out from the dates in the file.', 'cardsco' ); ?>
					</p>
				<?php endif; ?>

				<p>
					<label style="display:block;margin-bottom:6px">
						<input type="radio" name="date_order" value="day" <?php checked( 'month' !== $cardsco_order ); ?> />
						<?php esc_html_e( 'Day first — 03/04/1990 is the 3rd of April (UK)', 'cardsco' ); ?>
					</label>
					<label style="display:block">
						<input type="radio" name="date_order" value="month" <?php checked( 'month' === $cardsco_order ); ?> />
						<?php esc_html_e( 'Month first — 03/04/1990 is the 4th of March (US)', 'cardsco' ); ?>
					</label>
				</p>

				<p class="description">
					<?php esc_html_e( 'Dates written out in full, like 14 Feb 1990 or 1990-02-14, are read correctly either way.', 'cardsco' ); ?>
				</p>
			</div>

			<?php if ( $cardsco_segments ) : ?>
				<div class="cardsco-form" style="margin-top:20px;max-width:720px">
					<h2 style="margin-top:0"><?php esc_html_e( 'Add everyone to a segment', 'cardsco' ); ?></h2>
					<p class="description" style="margin-top:0"><?php esc_html_e( 'Optional. Everyone in this file joins the segments ticked here.', 'cardsco' ); ?></p>
					<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px">
						<?php foreach ( $cardsco_segments as $cardsco_seg ) : ?>
							<label style="display:flex;gap:6px;align-items:center">
								<input type="checkbox" name="segments[]" value="<?php echo esc_attr( $cardsco_seg['id'] ); ?>"
									<?php checked( in_array( (int) $cardsco_seg['id'], (array) ( $batch['column_map']['segments'] ?? array() ), true ) ); ?> />
								<?php echo esc_html( $cardsco_seg['name'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<p style="margin-top:16px">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Check what we found', 'cardsco' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'step' => 'map', 'batch' => $batch['id'], 'company' => $company_id ), $cardsco_base ) ); ?>">
					<?php esc_html_e( 'Back', 'cardsco' ); ?>
				</a>
			</p>
		</form>

	<?php elseif ( 'review' === $step ) : ?>

		<div class="cardsco-tiles">
			<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $report['new'] ) ); ?></strong><span><?php esc_html_e( 'New people', 'cardsco' ); ?></span></div>
			<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $report['updates'] ) ); ?></strong><span><?php esc_html_e( 'Already on the list', 'cardsco' ); ?></span></div>
			<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $report['occasions'] ) ); ?></strong><span><?php esc_html_e( 'Dates found', 'cardsco' ); ?></span></div>
			<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $report['no_address'] ) ); ?></strong><span><?php esc_html_e( 'Without a full address', 'cardsco' ); ?></span></div>
			<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $report['error_count'] ) ); ?></strong><span><?php esc_html_e( 'Rows with a problem', 'cardsco' ); ?></span></div>
		</div>

		<?php if ( ! empty( $report['samples'] ) ) : ?>
			<h2><?php esc_html_e( 'First few rows, as they will be saved', 'cardsco' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="max-width:1000px">
				<thead>
					<tr>
						<th scope="col" style="width:22%"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
						<th scope="col" style="width:22%"><?php esc_html_e( 'Email', 'cardsco' ); ?></th>
						<th scope="col" style="width:28%"><?php esc_html_e( 'Address', 'cardsco' ); ?></th>
						<th scope="col" style="width:28%"><?php esc_html_e( 'Dates', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['samples'] as $cardsco_s ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $cardsco_s['name'] ); ?></strong>
							<?php if ( ! empty( $cardsco_s['existing'] ) ) : ?>
								<span class="cardsco-pill"><?php esc_html_e( 'Already here', 'cardsco' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $cardsco_s['email'] ? esc_html( $cardsco_s['email'] ) : '&mdash;'; ?></td>
						<td style="white-space:pre-line"><?php echo esc_html( $cardsco_s['address'] ? $cardsco_s['address'] : '—' ); ?></td>
						<td>
							<?php if ( empty( $cardsco_s['occasions'] ) ) : ?>
								&mdash;
							<?php else : ?>
								<?php foreach ( $cardsco_s['occasions'] as $cardsco_tid => $cardsco_parsed ) : ?>
									<?php $cardsco_type = CardsCo_Occasion_Type::get( $cardsco_tid ); ?>
									<div>
										<?php echo esc_html( $cardsco_type ? $cardsco_type['label'] : '' ); ?>:
										<strong><?php echo esc_html( cardsco_format_occasion_date( $cardsco_parsed['month'], $cardsco_parsed['day'], $cardsco_parsed['year'] ) ); ?></strong>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Check a date here against the spreadsheet before going on. If the day and month are the wrong way round, go back and switch the date order.', 'cardsco' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $report['errors'] ) ) : ?>
			<h2><?php esc_html_e( 'Rows worth a look', 'cardsco' ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="max-width:760px">
				<thead>
					<tr>
						<th scope="col" style="width:14%"><?php esc_html_e( 'Line', 'cardsco' ); ?></th>
						<th scope="col" style="width:30%"><?php esc_html_e( 'Who', 'cardsco' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What is wrong', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['errors'] as $cardsco_err ) : ?>
					<tr>
						<td><?php echo esc_html( $cardsco_err['line'] ); ?></td>
						<td><?php echo $cardsco_err['name'] ? esc_html( $cardsco_err['name'] ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( $cardsco_err['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $report['error_count'] > count( $report['errors'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: number of further problems. */
						esc_html__( 'and %s more.', 'cardsco' ),
						esc_html( number_format_i18n( $report['error_count'] - count( $report['errors'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<div class="cardsco-form" style="margin-top:20px;max-width:720px">
			<h2 style="margin-top:0"><?php esc_html_e( 'People already on the list', 'cardsco' ); ?></h2>

			<?php if ( $report['updates'] ) : ?>
				<p class="description" style="margin-top:0">
					<?php
					printf(
						/* translators: %s: number of rows. */
						esc_html__( '%s rows match somebody already here, by email or by name and address.', 'cardsco' ),
						esc_html( number_format_i18n( $report['updates'] ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="description" style="margin-top:0"><?php esc_html_e( 'Nobody in this file is already on the list, so this makes no difference.', 'cardsco' ); ?></p>
			<?php endif; ?>

			<?php
			$cardsco_run = static function ( $strategy ) use ( $batch, $company_id ) {
				return wp_nonce_url(
					add_query_arg(
						array(
							'page'     => 'cardsco-import',
							'step'     => 'run',
							'batch'    => (int) $batch['id'],
							'company'  => (int) $company_id,
							'offset'   => 0,
							'strategy' => $strategy,
						),
						admin_url( 'admin.php' )
					),
					CardsCo_Admin::NONCE
				);
			};
			?>

			<p style="margin-bottom:6px">
				<a class="button button-primary" href="<?php echo esc_url( $cardsco_run( 'skip' ) ); ?>">
					<?php esc_html_e( 'Import, leaving existing people alone', 'cardsco' ); ?>
				</a>
			</p>
			<p style="margin-bottom:6px">
				<a class="button" href="<?php echo esc_url( $cardsco_run( 'update' ) ); ?>">
					<?php esc_html_e( 'Import and update existing people', 'cardsco' ); ?>
				</a>
			</p>
			<p class="description" style="margin-top:0">
				<?php esc_html_e( 'Updating fills in what the file has and leaves anything it does not mention untouched, so a missing column never blanks what is already saved.', 'cardsco' ); ?>
			</p>

			<p style="margin-top:14px">
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'step' => 'occasions', 'batch' => $batch['id'], 'company' => $company_id ), $cardsco_base ) ); ?>">
					<?php esc_html_e( 'Back', 'cardsco' ); ?>
				</a>
			</p>
		</div>

	<?php elseif ( 'run' === $step ) : ?>

		<?php
		$cardsco_total = max( 1, (int) $batch['total_rows'] );
		$cardsco_done  = min( $cardsco_total, (int) $result['offset'] );
		$cardsco_pct   = (int) round( ( $cardsco_done / $cardsco_total ) * 100 );

		$cardsco_next = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'cardsco-import',
					'step'     => 'run',
					'batch'    => (int) $batch['id'],
					'company'  => (int) $company_id,
					'offset'   => (int) $result['offset'],
					'strategy' => sanitize_key( $_GET['strategy'] ?? 'skip' ),
				),
				admin_url( 'admin.php' )
			),
			CardsCo_Admin::NONCE
		);
		?>

		<?php if ( empty( $result['done'] ) ) : ?>
			<meta http-equiv="refresh" content="0;url=<?php echo esc_attr( $cardsco_next ); ?>" />

			<div class="cardsco-form" style="max-width:620px">
				<h2 style="margin-top:0"><?php esc_html_e( 'Importing', 'cardsco' ); ?></h2>
				<div class="cardsco-progress"><span style="width:<?php echo esc_attr( $cardsco_pct ); ?>%"></span></div>
				<p>
					<?php
					printf(
						/* translators: 1: rows done, 2: rows in total. */
						esc_html__( '%1$s of %2$s rows.', 'cardsco' ),
						esc_html( number_format_i18n( $cardsco_done ) ),
						esc_html( number_format_i18n( $cardsco_total ) )
					);
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Leave this page open. If it stops moving, use the link below to carry on from where it got to.', 'cardsco' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $cardsco_next ); ?>"><?php esc_html_e( 'Carry on', 'cardsco' ); ?></a></p>
			</div>
		<?php else : ?>
			<div class="cardsco-form" style="max-width:620px">
				<h2 style="margin-top:0"><?php esc_html_e( 'Import finished', 'cardsco' ); ?></h2>

				<div class="cardsco-tiles" style="margin-top:4px">
					<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $batch['created_count'] ) ); ?></strong><span><?php esc_html_e( 'Added', 'cardsco' ); ?></span></div>
					<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $batch['updated_count'] ) ); ?></strong><span><?php esc_html_e( 'Updated', 'cardsco' ); ?></span></div>
					<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $batch['skipped_count'] ) ); ?></strong><span><?php esc_html_e( 'Already here', 'cardsco' ); ?></span></div>
					<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $batch['error_count'] ) ); ?></strong><span><?php esc_html_e( 'Could not be read', 'cardsco' ); ?></span></div>
				</div>

				<?php
				$cardsco_sum = (int) $batch['created_count'] + (int) $batch['updated_count']
					+ (int) $batch['skipped_count'] + (int) $batch['error_count'];
				?>
				<p>
					<?php
					printf(
						/* translators: 1: rows accounted for, 2: rows in the file. */
						esc_html__( '%1$s of the %2$s rows in the file are accounted for above.', 'cardsco' ),
						esc_html( number_format_i18n( $cardsco_sum ) ),
						esc_html( number_format_i18n( $batch['total_rows'] ) )
					);
					?>
				</p>

				<?php if ( (int) $batch['error_count'] ) : ?>
					<p class="description">
						<?php esc_html_e( '"Could not be read" means a row had no name at all, so there was nobody to address a card to. Everything else went in.', 'cardsco' ); ?>
					</p>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'The uploaded file has been deleted from the server now the people are saved.', 'cardsco' ); ?></p>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . CardsCo_Admin::MENU . '&company=' . $company_id ) ); ?>">
						<?php esc_html_e( 'See the people', 'cardsco' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-occasions&company=' . $company_id ) ); ?>">
						<?php esc_html_e( 'See what is coming up', 'cardsco' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( $cardsco_base ); ?>"><?php esc_html_e( 'Import another file', 'cardsco' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

	<?php endif; ?>
</div>
