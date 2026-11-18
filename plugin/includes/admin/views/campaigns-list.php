<?php
/**
 * A company's campaigns.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var int           $company_id
 * @var array[]       $campaigns
 * @var array         $statuses
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'cardsco' ); ?></h1>
	<hr class="wp-header-end" />

	<?php $admin->print_notices(); ?>

	<p class="description">
		<?php esc_html_e( 'A campaign is one send on a day you choose — a seasonal card, a promotion, an offer. For birthdays and other dates people hold, use Occasions instead.', 'cardsco' ); ?>
	</p>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="cardsco-bar">
		<?php $admin->company_switcher( $company_id, CardsCo_Campaign_Builder::PAGE ); ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-bar">
		<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
		<input type="hidden" name="action" value="cardsco_campaign_start" />
		<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />
		<label class="screen-reader-text" for="cardsco-cname"><?php esc_html_e( 'Name', 'cardsco' ); ?></label>
		<input type="text" id="cardsco-cname" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'Spring offer, Christmas 2026…', 'cardsco' ); ?>" />
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Start a campaign', 'cardsco' ); ?></button>
	</form>

	<?php if ( empty( $campaigns ) ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No campaigns yet.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'Give one a name above to get started.', 'cardsco' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Campaign', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Arrives', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cost', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stage', 'cardsco' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $campaigns as $cardsco_c ) : ?>
				<?php
				$cardsco_link = CardsCo_Campaign_Builder::url(
					array(
						'campaign' => (int) $cardsco_c['id'],
						'step'     => (int) $cardsco_c['builder_step'],
					)
				);
				$cardsco_open = in_array( $cardsco_c['status'], array( 'draft', 'scheduled' ), true );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $cardsco_link ); ?>"><?php echo esc_html( $cardsco_c['name'] ); ?></a></strong>
						<?php if ( ! empty( $cardsco_c['offer_enabled'] ) && $cardsco_c['offer_code'] ) : ?>
							<br /><span class="description"><?php echo esc_html( $cardsco_c['offer_code'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $cardsco_c['recipient_count'] ) ); ?></td>
					<td>
						<?php echo esc_html( $cardsco_c['send_date'] ? gmdate( 'j M Y', strtotime( $cardsco_c['send_date'] ) ) : '—' ); ?>
					</td>
					<td>
						<?php
						echo $cardsco_c['total_amount']
							? esc_html( $cardsco_c['currency'] . ' ' . number_format( (float) $cardsco_c['total_amount'], 2 ) )
							: '—';
						?>
					</td>
					<td><?php echo esc_html( $statuses[ $cardsco_c['status'] ] ?? $cardsco_c['status'] ); ?></td>
					<td>
						<?php if ( $cardsco_open ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this campaign?', 'cardsco' ) ); ?>')">
								<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
								<input type="hidden" name="action" value="cardsco_campaign_cancel" />
								<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (int) $cardsco_c['id'] ); ?>" />
								<button type="submit" class="button-link delete"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
