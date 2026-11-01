<?php
/**
 * Companies.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var array[]       $companies
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Companies', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;margin-top:16px">
		<div style="flex:1 1 460px;min-width:320px">
			<?php if ( empty( $companies ) ) : ?>
				<div class="cardsco-empty">
					<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
					<p><?php esc_html_e( 'Create one on the right, then add recipients to it.', 'cardsco' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Company', 'cardsco' ); ?></th>
							<th scope="col" style="width:26%"><?php esc_html_e( 'Contact', 'cardsco' ); ?></th>
							<th scope="col" style="width:16%"><?php esc_html_e( 'Recipients', 'cardsco' ); ?></th>
							<th scope="col" style="width:14%"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $companies as $cardsco_co ) : ?>
						<?php
						$cardsco_co_id   = (int) $cardsco_co['id'];
						$cardsco_counts  = CardsCo_Recipient::counts( $cardsco_co_id );
						$cardsco_people  = add_query_arg(
							array(
								'page'    => CardsCo_Admin::MENU,
								'company' => $cardsco_co_id,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( $cardsco_people ); ?>"><?php echo esc_html( $cardsco_co['name'] ); ?></a></strong>
								<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_co['slug'] ); ?></div>
							</td>
							<td>
								<?php echo $cardsco_co['contact_name'] ? esc_html( $cardsco_co['contact_name'] ) : '&mdash;'; ?>
								<?php if ( $cardsco_co['contact_email'] ) : ?>
									<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_co['contact_email'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $cardsco_counts['total'] ) ); ?></td>
							<td><span class="cardsco-pill"><?php echo esc_html( ucfirst( $cardsco_co['status'] ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="flex:0 1 340px;min-width:300px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-form">
				<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
				<input type="hidden" name="action" value="cardsco_save_company" />

				<h2 style="margin-top:0"><?php esc_html_e( 'New company', 'cardsco' ); ?></h2>

				<p>
					<label for="cardsco-co-name"><strong><?php esc_html_e( 'Company name', 'cardsco' ); ?></strong></label><br />
					<input type="text" id="cardsco-co-name" name="name" required style="width:100%" />
				</p>

				<p>
					<label for="cardsco-co-contact"><strong><?php esc_html_e( 'Contact name', 'cardsco' ); ?></strong></label><br />
					<input type="text" id="cardsco-co-contact" name="contact_name" style="width:100%" />
				</p>

				<p>
					<label for="cardsco-co-email"><strong><?php esc_html_e( 'Contact email', 'cardsco' ); ?></strong></label><br />
					<input type="email" id="cardsco-co-email" name="contact_email" style="width:100%" />
				</p>

				<p>
					<label for="cardsco-co-phone"><strong><?php esc_html_e( 'Phone', 'cardsco' ); ?></strong></label><br />
					<input type="text" id="cardsco-co-phone" name="contact_phone" style="width:100%" />
				</p>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create company', 'cardsco' ); ?></button></p>
			</form>
		</div>
	</div>
</div>
