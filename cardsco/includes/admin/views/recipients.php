<?php
/**
 * Recipients list.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var array         $results
 * @var array         $counts
 * @var array[]       $segments
 * @var int           $paged
 * @var int           $per_page
 */

defined( 'ABSPATH' ) || exit;

$cardsco_type    = sanitize_key( $_GET['type'] ?? 'any' );
$cardsco_status  = sanitize_key( $_GET['status'] ?? 'active' );
$cardsco_segment = absint( $_GET['segment'] ?? 0 );
$cardsco_address = sanitize_key( $_GET['address'] ?? '' );
$cardsco_search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Recipients', 'cardsco' ); ?></h1>

	<?php if ( $company_id ) : ?>
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => CardsCo_Admin::MENU, 'company' => $company_id, 'action' => 'new' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add recipient', 'cardsco' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php $this->print_notices(); ?>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'Recipients belong to a company, so create one first.', 'cardsco' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>">
				<?php esc_html_e( 'Go to Companies', 'cardsco' ); ?>
			</a>
		</div>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="cardsco-tiles">
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['total'] ) ); ?></strong><span><?php esc_html_e( 'Active', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['customers'] ) ); ?></strong><span><?php esc_html_e( 'Customers', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['employees'] ) ); ?></strong><span><?php esc_html_e( 'Employees', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['needs_address'] ) ); ?></strong><span><?php esc_html_e( 'Need an address', 'cardsco' ); ?></span></div>
	</div>

	<div class="cardsco-bar">
		<?php $this->company_switcher( $company_id, CardsCo_Admin::MENU ); ?>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( CardsCo_Admin::MENU ); ?>" />
			<input type="hidden" name="company" value="<?php echo esc_attr( $company_id ); ?>" />

			<div class="cardsco-field">
				<label for="cardsco-type"><?php esc_html_e( 'Type', 'cardsco' ); ?></label>
				<select name="type" id="cardsco-type">
					<option value="any" <?php selected( $cardsco_type, 'any' ); ?>><?php esc_html_e( 'All', 'cardsco' ); ?></option>
					<option value="customer" <?php selected( $cardsco_type, 'customer' ); ?>><?php esc_html_e( 'Customers', 'cardsco' ); ?></option>
					<option value="employee" <?php selected( $cardsco_type, 'employee' ); ?>><?php esc_html_e( 'Employees', 'cardsco' ); ?></option>
				</select>
			</div>

			<div class="cardsco-field">
				<label for="cardsco-segment"><?php esc_html_e( 'Segment', 'cardsco' ); ?></label>
				<select name="segment" id="cardsco-segment">
					<option value="0"><?php esc_html_e( 'Any segment', 'cardsco' ); ?></option>
					<?php foreach ( $segments as $cardsco_seg ) : ?>
						<option value="<?php echo esc_attr( $cardsco_seg['id'] ); ?>" <?php selected( $cardsco_segment, (int) $cardsco_seg['id'] ); ?>>
							<?php echo esc_html( $cardsco_seg['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="cardsco-field">
				<label for="cardsco-address"><?php esc_html_e( 'Address', 'cardsco' ); ?></label>
				<select name="address" id="cardsco-address">
					<option value=""><?php esc_html_e( 'Any', 'cardsco' ); ?></option>
					<option value="complete" <?php selected( $cardsco_address, 'complete' ); ?>><?php esc_html_e( 'Complete', 'cardsco' ); ?></option>
					<option value="incomplete" <?php selected( $cardsco_address, 'incomplete' ); ?>><?php esc_html_e( 'Incomplete', 'cardsco' ); ?></option>
					<option value="missing" <?php selected( $cardsco_address, 'missing' ); ?>><?php esc_html_e( 'Missing', 'cardsco' ); ?></option>
				</select>
			</div>

			<div class="cardsco-field">
				<label for="cardsco-status"><?php esc_html_e( 'Status', 'cardsco' ); ?></label>
				<select name="status" id="cardsco-status">
					<option value="active" <?php selected( $cardsco_status, 'active' ); ?>><?php esc_html_e( 'Active', 'cardsco' ); ?></option>
					<option value="archived" <?php selected( $cardsco_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'cardsco' ); ?></option>
					<option value="any" <?php selected( $cardsco_status, 'any' ); ?>><?php esc_html_e( 'All', 'cardsco' ); ?></option>
				</select>
			</div>

			<div class="cardsco-field">
				<label for="cardsco-search"><?php esc_html_e( 'Search', 'cardsco' ); ?></label>
				<input type="text" name="s" id="cardsco-search" value="<?php echo esc_attr( $cardsco_search ); ?>" placeholder="<?php esc_attr_e( 'Name, email or company', 'cardsco' ); ?>" />
			</div>

			<div class="cardsco-field">
				<label>&nbsp;</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'cardsco' ); ?></button>
			</div>
		</form>
	</div>

	<?php if ( empty( $results['items'] ) ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'Nothing to show.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'Either this company has no recipients yet, or the filters above are too narrow.', 'cardsco' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:20%"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
					<th scope="col" style="width:10%"><?php esc_html_e( 'Type', 'cardsco' ); ?></th>
					<th scope="col" style="width:20%"><?php esc_html_e( 'Company', 'cardsco' ); ?></th>
					<th scope="col" style="width:22%"><?php esc_html_e( 'Address', 'cardsco' ); ?></th>
					<th scope="col" style="width:16%"><?php esc_html_e( 'Segments', 'cardsco' ); ?></th>
					<th scope="col" style="width:12%"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $results['items'] as $cardsco_row ) : ?>
				<?php
				$cardsco_id       = (int) $cardsco_row['id'];
				$cardsco_name     = trim( $cardsco_row['first_name'] . ' ' . $cardsco_row['last_name'] );
				$cardsco_edit     = add_query_arg(
					array(
						'page'      => CardsCo_Admin::MENU,
						'company'   => $company_id,
						'action'    => 'edit',
						'recipient' => $cardsco_id,
					),
					admin_url( 'admin.php' )
				);
				$cardsco_segs     = CardsCo_Segment::for_recipient( $cardsco_id );
				$cardsco_seg_name = array();

				foreach ( $cardsco_segs as $cardsco_seg_id ) {
					$cardsco_seg_row = CardsCo_Segment::get( $cardsco_seg_id );

					if ( $cardsco_seg_row ) {
						$cardsco_seg_name[] = $cardsco_seg_row['name'];
					}
				}

				$cardsco_archive = wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'cardsco_delete_recipient',
							'recipient' => $cardsco_id,
							'company'   => $company_id,
							'mode'      => 'archived' === $cardsco_row['status'] ? 'restore' : 'archive',
						),
						admin_url( 'admin-post.php' )
					),
					CardsCo_Admin::NONCE
				);

				$cardsco_delete = wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'cardsco_delete_recipient',
							'recipient' => $cardsco_id,
							'company'   => $company_id,
							'mode'      => 'delete',
						),
						admin_url( 'admin-post.php' )
					),
					CardsCo_Admin::NONCE
				);
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $cardsco_edit ); ?>"><?php echo esc_html( $cardsco_name ); ?></a></strong>
						<?php if ( $cardsco_row['email'] ) : ?>
							<div class="row-actions" style="left:0"><span><?php echo esc_html( $cardsco_row['email'] ); ?></span></div>
						<?php endif; ?>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $cardsco_edit ); ?>"><?php esc_html_e( 'Edit', 'cardsco' ); ?></a> | </span>
							<span><a href="<?php echo esc_url( $cardsco_archive ); ?>">
								<?php echo 'archived' === $cardsco_row['status'] ? esc_html__( 'Restore', 'cardsco' ) : esc_html__( 'Archive', 'cardsco' ); ?>
							</a> | </span>
							<span class="trash"><a href="<?php echo esc_url( $cardsco_delete ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Delete this recipient permanently? Cards already queued are kept.', 'cardsco' ) ); ?>');"><?php esc_html_e( 'Delete', 'cardsco' ); ?></a></span>
						</div>
					</td>
					<td><?php echo 'employee' === $cardsco_row['type'] ? esc_html__( 'Employee', 'cardsco' ) : esc_html__( 'Customer', 'cardsco' ); ?></td>
					<td><?php echo $cardsco_row['recipient_company'] ? esc_html( $cardsco_row['recipient_company'] ) : '&mdash;'; ?></td>
					<td>
						<?php
						$cardsco_lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', (string) $cardsco_row['address'] ) ) );
						$cardsco_first = $cardsco_lines ? reset( $cardsco_lines ) : '';
						?>
						<?php if ( $cardsco_first ) : ?>
							<div><?php echo esc_html( wp_trim_words( $cardsco_first, 6, '…' ) ); ?></div>
						<?php endif; ?>

						<?php if ( $cardsco_row['postcode'] ) : ?>
							<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_row['postcode'] ); ?></div>
						<?php endif; ?>

						<?php if ( 'complete' === $cardsco_row['address_status'] ) : ?>
							<span class="cardsco-pill is-good"><?php esc_html_e( 'Complete', 'cardsco' ); ?></span>
						<?php else : ?>
							<span class="cardsco-pill is-warn">
								<?php echo esc_html( cardsco_address_issue( $cardsco_row['address'], $cardsco_row['postcode'] ) ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo $cardsco_seg_name ? esc_html( implode( ', ', $cardsco_seg_name ) ) : '&mdash;'; ?>
					</td>
					<td>
						<span class="cardsco-pill"><?php echo esc_html( ucfirst( $cardsco_row['status'] ) ); ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $results['pages'] > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						printf(
							/* translators: %s: number of recipients. */
							esc_html( _n( '%s item', '%s items', $results['total'], 'cardsco' ) ),
							esc_html( number_format_i18n( $results['total'] ) )
						);
						?>
					</span>
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $results['pages'],
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
