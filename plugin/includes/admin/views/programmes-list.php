<?php
/**
 * A company's occasions.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var int           $company_id
 * @var array[]       $programmes
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Occasions', 'cardsco' ); ?></h1>

	<?php if ( $company_id ) : ?>
		<a class="page-title-action" href="<?php echo esc_url( CardsCo_Wizard::url( array( 'company' => $company_id, 'action' => 'new' ) ) ); ?>">
			<?php esc_html_e( 'Set up an occasion', 'cardsco' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php $admin->print_notices(); ?>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="cardsco-bar">
		<?php $admin->company_switcher( $company_id, CardsCo_Wizard::PAGE ); ?>
	</div>

	<?php if ( empty( $programmes ) ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'Nothing set up yet.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'An occasion is set up once and then runs on its own — the people, the card, the wording and when they should arrive.', 'cardsco' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( CardsCo_Wizard::url( array( 'company' => $company_id, 'action' => 'new' ) ) ); ?>">
				<?php esc_html_e( 'Set one up', 'cardsco' ); ?>
			</a>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:26%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
					<th scope="col" style="width:14%"><?php esc_html_e( 'People', 'cardsco' ); ?></th>
					<th scope="col" style="width:20%"><?php esc_html_e( 'Card', 'cardsco' ); ?></th>
					<th scope="col" style="width:22%"><?php esc_html_e( 'Arrives', 'cardsco' ); ?></th>
					<th scope="col" style="width:18%"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $programmes as $cardsco_p ) : ?>
				<?php
				$cardsco_people = CardsCo_Programme::people_summary( $cardsco_p );
				$cardsco_card   = $cardsco_p['card_id'] ? get_post( (int) $cardsco_p['card_id'] ) : null;
				$cardsco_open   = CardsCo_Wizard::url(
					array(
						'programme' => (int) $cardsco_p['id'],
						'step'      => 'draft' === $cardsco_p['status'] ? (int) $cardsco_p['setup_step'] : 7,
					)
				);
				$cardsco_stop   = wp_nonce_url(
					add_query_arg(
						array(
							'action'       => 'cardsco_programme_delete',
							'programme_id' => (int) $cardsco_p['id'],
						),
						admin_url( 'admin-post.php' )
					),
					CardsCo_Admin::NONCE
				);
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $cardsco_open ); ?>"><?php echo esc_html( $cardsco_p['name'] ); ?></a></strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( $cardsco_open ); ?>">
								<?php echo 'draft' === $cardsco_p['status'] ? esc_html__( 'Carry on setting up', 'cardsco' ) : esc_html__( 'View', 'cardsco' ); ?>
							</a> | </span>
							<span class="trash"><a class="submitdelete" href="<?php echo esc_url( $cardsco_stop ); ?>">
								<?php echo 'draft' === $cardsco_p['status'] ? esc_html__( 'Discard', 'cardsco' ) : esc_html__( 'Pause', 'cardsco' ); ?>
							</a></span>
						</div>
					</td>
					<td>
						<?php echo esc_html( number_format_i18n( $cardsco_people['total'] ) ); ?>
						<?php if ( $cardsco_people['missing_date'] ) : ?>
							<div><span class="cardsco-pill is-warn">
								<?php
								printf(
									/* translators: %d: how many people. */
									esc_html__( '%d without a date', 'cardsco' ),
									(int) $cardsco_people['missing_date']
								);
								?>
							</span></div>
						<?php endif; ?>
					</td>
					<td><?php echo $cardsco_card ? esc_html( $cardsco_card->post_title ) : '&mdash;'; ?></td>
					<td>
						<?php if ( 'monthly_box' === $cardsco_p['timing_mode'] ) : ?>
							<?php
							printf(
								/* translators: %d: day of the month. */
								esc_html__( 'One box a month, by the %d', 'cardsco' ),
								(int) $cardsco_p['box_arrive_day']
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %d: number of days. */
								esc_html__( '%d days before each date', 'cardsco' ),
								(int) $cardsco_p['days_before']
							);
							?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( 'active' === $cardsco_p['status'] ) : ?>
							<span class="cardsco-pill is-good"><?php esc_html_e( 'On', 'cardsco' ); ?></span>
						<?php elseif ( 'draft' === $cardsco_p['status'] ) : ?>
							<span class="cardsco-pill">
								<?php
								printf(
									/* translators: %d: step number. */
									esc_html__( 'Draft, step %d of 7', 'cardsco' ),
									(int) $cardsco_p['setup_step']
								);
								?>
							</span>
						<?php else : ?>
							<span class="cardsco-pill is-warn"><?php echo esc_html( ucfirst( $cardsco_p['status'] ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
