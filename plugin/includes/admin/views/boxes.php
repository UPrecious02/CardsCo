<?php
/**
 * Boxes with work left in them.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var int           $company_id
 * @var array[]       $boxes
 * @var array[]       $recent
 * @var string        $today
 */

defined( 'ABSPATH' ) || exit;

$labels = array(
	'planned' => __( 'Planned', 'cardsco' ),
	'writing' => __( 'Being written', 'cardsco' ),
	'packed'  => __( 'Packed', 'cardsco' ),
	'posted'  => __( 'Posted', 'cardsco' ),
);
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Boxes', 'cardsco' ); ?></h1>
	<hr class="wp-header-end" />

	<?php $admin->print_notices(); ?>

	<p class="description">
		<?php esc_html_e( 'Everything still to be written, packed or posted. The one with the nearest write-by date is at the top.', 'cardsco' ); ?>
	</p>

	<div class="cardsco-bar">
		<?php $admin->company_switcher( $company_id, CardsCo_Fulfilment::PAGE ); ?>
	</div>

	<?php if ( empty( $boxes ) ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'Nothing waiting.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'Boxes appear here once an occasion is switched on and its writing week comes round.', 'cardsco' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Company', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Month', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cards', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Write by', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Must arrive', 'cardsco' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stage', 'cardsco' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $boxes as $box ) : ?>
				<?php
				$company  = CardsCo_Company::get( (int) $box['company_id'] );
				$late     = $box['write_by'] && $box['write_by'] < $today && 'packed' !== $box['status'];
				$link     = CardsCo_Fulfilment::url( array( 'box' => (int) $box['id'] ) );
				$month    = $box['period'] ? gmdate( 'F Y', strtotime( $box['period'] . '-01' ) ) : '';
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $company['name'] ?? __( 'Unknown', 'cardsco' ) ); ?></a></strong>
					</td>
					<td><?php echo esc_html( $month ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $box['card_count'] ) ); ?></td>
					<td<?php echo $late ? ' style="color:#d63638;font-weight:600"' : ''; ?>>
						<?php echo esc_html( $box['write_by'] ? gmdate( 'D j M', strtotime( $box['write_by'] ) ) : '—' ); ?>
						<?php if ( $late ) : ?>
							<br /><span class="description" style="color:#d63638"><?php esc_html_e( 'overdue', 'cardsco' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $box['arrive_by'] ? gmdate( 'D j M', strtotime( $box['arrive_by'] ) ) : '—' ); ?></td>
					<td><?php echo esc_html( $labels[ $box['status'] ] ?? $box['status'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $recent ) ) : ?>
		<h2><?php esc_html_e( 'Recently posted', 'cardsco' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<tbody>
			<?php foreach ( $recent as $box ) : ?>
				<?php $company = CardsCo_Company::get( (int) $box['company_id'] ); ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( CardsCo_Fulfilment::url( array( 'box' => (int) $box['id'] ) ) ); ?>">
							<?php echo esc_html( $company['name'] ?? __( 'Unknown', 'cardsco' ) ); ?>
						</a>
					</td>
					<td><?php echo esc_html( $box['period'] ? gmdate( 'F Y', strtotime( $box['period'] . '-01' ) ) : '' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $box['card_count'] ) ); ?> <?php esc_html_e( 'cards', 'cardsco' ); ?></td>
					<td><?php echo esc_html( $box['posted_at'] ? gmdate( 'j M Y', strtotime( $box['posted_at'] ) ) : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
