<?php
/**
 * Segments.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var array[]       $segments
 * @var array|null    $editing
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Segments', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="cardsco-bar">
		<?php $this->company_switcher( $company_id, 'cardsco-segments' ); ?>
	</div>

	<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
		<div style="flex:1 1 420px;min-width:320px">
			<?php if ( empty( $segments ) ) : ?>
				<div class="cardsco-empty">
					<p><strong><?php esc_html_e( 'No segments yet.', 'cardsco' ); ?></strong></p>
					<p><?php esc_html_e( 'Segments are the named groups a company sends campaigns to.', 'cardsco' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
							<th scope="col" style="width:22%"><?php esc_html_e( 'Applies to', 'cardsco' ); ?></th>
							<th scope="col" style="width:18%"><?php esc_html_e( 'Members', 'cardsco' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $segments as $cardsco_seg ) : ?>
						<?php
						$cardsco_edit_url = add_query_arg(
							array(
								'page'    => 'cardsco-segments',
								'company' => $company_id,
								'segment' => (int) $cardsco_seg['id'],
							),
							admin_url( 'admin.php' )
						);

						$cardsco_del_url = wp_nonce_url(
							add_query_arg(
								array(
									'action'  => 'cardsco_delete_segment',
									'segment' => (int) $cardsco_seg['id'],
									'company' => $company_id,
								),
								admin_url( 'admin-post.php' )
							),
							CardsCo_Admin::NONCE
						);

						$cardsco_people_url = add_query_arg(
							array(
								'page'    => CardsCo_Admin::MENU,
								'company' => $company_id,
								'segment' => (int) $cardsco_seg['id'],
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $cardsco_edit_url ); ?>"><?php echo esc_html( $cardsco_seg['name'] ); ?></a></strong>
								<?php if ( $cardsco_seg['description'] ) : ?>
									<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_seg['description'] ); ?></div>
								<?php endif; ?>
								<div class="row-actions">
									<span class="edit"><a href="<?php echo esc_url( $cardsco_edit_url ); ?>"><?php esc_html_e( 'Edit', 'cardsco' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( $cardsco_people_url ); ?>"><?php esc_html_e( 'View people', 'cardsco' ); ?></a> | </span>
									<span class="trash"><a class="submitdelete" href="<?php echo esc_url( $cardsco_del_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this segment? The people in it are kept.', 'cardsco' ) ); ?>');"><?php esc_html_e( 'Delete', 'cardsco' ); ?></a></span>
								</div>
							</td>
							<td><?php echo esc_html( ucfirst( $cardsco_seg['applies_to'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $cardsco_seg['member_count'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="flex:0 1 340px;min-width:300px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-form">
				<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
				<input type="hidden" name="action" value="cardsco_save_segment" />
				<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />
				<input type="hidden" name="segment_id" value="<?php echo esc_attr( $editing['id'] ?? 0 ); ?>" />

				<h2 style="margin-top:0">
					<?php echo $editing ? esc_html__( 'Edit segment', 'cardsco' ) : esc_html__( 'New segment', 'cardsco' ); ?>
				</h2>

				<p>
					<label for="cardsco-seg-name"><strong><?php esc_html_e( 'Name', 'cardsco' ); ?></strong></label><br />
					<input type="text" id="cardsco-seg-name" name="name" required style="width:100%" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" />
				</p>

				<p>
					<label for="cardsco-seg-desc"><strong><?php esc_html_e( 'Description', 'cardsco' ); ?></strong></label><br />
					<input type="text" id="cardsco-seg-desc" name="description" style="width:100%" value="<?php echo esc_attr( $editing['description'] ?? '' ); ?>" />
				</p>

				<p>
					<label for="cardsco-seg-applies"><strong><?php esc_html_e( 'Applies to', 'cardsco' ); ?></strong></label><br />
					<select id="cardsco-seg-applies" name="applies_to" style="width:100%">
						<option value="both" <?php selected( $editing['applies_to'] ?? 'both', 'both' ); ?>><?php esc_html_e( 'Everyone', 'cardsco' ); ?></option>
						<option value="customer" <?php selected( $editing['applies_to'] ?? '', 'customer' ); ?>><?php esc_html_e( 'Customers', 'cardsco' ); ?></option>
						<option value="employee" <?php selected( $editing['applies_to'] ?? '', 'employee' ); ?>><?php esc_html_e( 'Employees', 'cardsco' ); ?></option>
					</select>
				</p>

				<p>
					<button type="submit" class="button button-primary">
						<?php echo $editing ? esc_html__( 'Save segment', 'cardsco' ) : esc_html__( 'Create segment', 'cardsco' ); ?>
					</button>
					<?php if ( $editing ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-segments&company=' . $company_id ) ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
	</div>
</div>
