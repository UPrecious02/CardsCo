<?php
/**
 * One box: the cards in it, and the buttons that move it along.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var array         $box
 * @var array[]       $cards
 * @var array|null    $programme
 * @var array|null    $company
 * @var string        $today
 * @var int           $held
 */

defined( 'ABSPATH' ) || exit;

$statuses = CardsCo_Queue::statuses();
$reasons  = CardsCo_Queue::hold_reasons();
$month    = $box['period'] ? gmdate( 'F Y', strtotime( $box['period'] . '-01' ) ) : '';

// What this box can do next, in the order it actually happens.
$next = array(
	'planned' => array( 'writing', __( 'Start writing', 'cardsco' ) ),
	'writing' => array( 'packed', __( 'Mark written and packed', 'cardsco' ) ),
	'packed'  => array( 'posted', __( 'Mark posted', 'cardsco' ) ),
);
?>
<div class="wrap">
	<h1 class="wp-heading-inline">
		<?php
		printf(
			/* translators: 1: company name, 2: month. */
			esc_html__( '%1$s — %2$s box', 'cardsco' ),
			esc_html( $company['name'] ?? __( 'Unknown company', 'cardsco' ) ),
			esc_html( $month )
		);
		?>
	</h1>
	<a class="page-title-action" href="<?php echo esc_url( CardsCo_Fulfilment::url() ); ?>"><?php esc_html_e( 'All boxes', 'cardsco' ); ?></a>
	<?php if ( $cards ) : ?>
		<a class="page-title-action" target="_blank" rel="noopener" href="<?php echo esc_url( CardsCo_Fulfilment::url( array( 'box' => (int) $box['id'], 'view' => 'write' ) ) ); ?>">
			<?php esc_html_e( 'Open write list', 'cardsco' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php $admin->print_notices(); ?>

	<div class="cardsco-tiles">
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( (int) $box['card_count'] ) ); ?></strong><span><?php esc_html_e( 'Cards', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( $box['write_by'] ? gmdate( 'D j M', strtotime( $box['write_by'] ) ) : '—' ); ?></strong><span><?php esc_html_e( 'Write by', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( $box['arrive_by'] ? gmdate( 'D j M', strtotime( $box['arrive_by'] ) ) : '—' ); ?></strong><span><?php esc_html_e( 'Must arrive', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong<?php echo $held ? ' style="color:#d63638"' : ''; ?>><?php echo esc_html( number_format_i18n( $held ) ); ?></strong><span><?php esc_html_e( 'On hold', 'cardsco' ); ?></span></div>
	</div>

	<?php if ( $box['ship_address'] ) : ?>
		<h2><?php esc_html_e( 'Send it to', 'cardsco' ); ?></h2>
		<p>
			<?php echo nl2br( esc_html( $box['ship_address'] ) ); ?><br />
			<strong><?php echo esc_html( $box['ship_postcode'] ); ?></strong>
		</p>
	<?php endif; ?>

	<?php if ( $held ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of cards on hold. */
					esc_html( _n(
						'%d card in this month is on hold and will not go in the box until it is sorted out.',
						'%d cards in this month are on hold and will not go in the box until they are sorted out.',
						$held,
						'cardsco'
					) ),
					esc_html( number_format_i18n( $held ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $next[ $box['status'] ] ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_box_advance" />
			<input type="hidden" name="box_id" value="<?php echo esc_attr( (int) $box['id'] ); ?>" />
			<input type="hidden" name="status" value="<?php echo esc_attr( $next[ $box['status'] ][0] ); ?>" />
			<button type="submit" class="button button-primary button-hero"><?php echo esc_html( $next[ $box['status'] ][1] ); ?></button>
		</form>
	<?php else : ?>
		<p><strong><?php esc_html_e( 'This box has been posted. Nothing left to do.', 'cardsco' ); ?></strong></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'What is in it', 'cardsco' ); ?></h2>

	<?php if ( empty( $cards ) ) : ?>
		<p><?php esc_html_e( 'Nothing in this box yet.', 'cardsco' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_cards_bulk" />
			<input type="hidden" name="box_id" value="<?php echo esc_attr( (int) $box['id'] ); ?>" />

			<div class="tablenav top">
				<div class="alignleft actions">
					<label class="screen-reader-text" for="cardsco-bulk"><?php esc_html_e( 'With the ticked cards', 'cardsco' ); ?></label>
					<select name="bulk_status" id="cardsco-bulk">
						<option value=""><?php esc_html_e( 'With the ticked cards…', 'cardsco' ); ?></option>
						<option value="written"><?php esc_html_e( 'Mark written', 'cardsco' ); ?></option>
						<option value="posted"><?php esc_html_e( 'Mark posted', 'cardsco' ); ?></option>
						<option value="on_hold"><?php esc_html_e( 'Put on hold', 'cardsco' ); ?></option>
						<option value="cancelled"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Apply', 'cardsco' ); ?></button>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.cardsco-pick').forEach(function(b){b.checked=this.checked}.bind(this))" /></td>
						<th scope="col"><?php esc_html_e( 'Who', 'cardsco' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'cardsco' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Card', 'cardsco' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stage', 'cardsco' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cards as $card ) : ?>
					<tr>
						<th scope="row" class="check-column">
							<input type="checkbox" class="cardsco-pick" name="cards[]" value="<?php echo esc_attr( (int) $card['id'] ); ?>" />
						</th>
						<td>
							<strong><?php echo esc_html( $card['recipient_name'] ); ?></strong>
							<?php if ( 'on_hold' === $card['status'] && $card['hold_reason'] ) : ?>
								<br /><span class="description" style="color:#d63638">
									<?php echo esc_html( $reasons[ $card['hold_reason'] ] ?? $card['hold_reason'] ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $card['event_date'] ? gmdate( 'j M', strtotime( $card['event_date'] ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $card['card_title'] ); ?></td>
						<td><?php echo esc_html( $statuses[ $card['status'] ] ?? $card['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
	<?php endif; ?>
</div>
