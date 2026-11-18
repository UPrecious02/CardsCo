<?php
/**
 * Card queue.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $this
 * @var int           $company_id
 * @var array         $results
 * @var array         $counts
 * @var array|null    $last_scan
 * @var array[]       $needs
 * @var int           $paged
 * @var int           $per_page
 */

defined( 'ABSPATH' ) || exit;

$cardsco_status = sanitize_key( $_GET['status'] ?? 'open' );
$cardsco_search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$cardsco_today  = cardsco_now( 'date' );
$cardsco_labels = CardsCo_Queue::statuses();
$cardsco_holds  = CardsCo_Queue::hold_reasons();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Card queue', 'cardsco' ); ?></h1>

	<?php $this->print_notices(); ?>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'No companies yet.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( ! empty( $needs ) ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Some occasions have no card chosen, so nothing is being queued for them.', 'cardsco' ); ?></strong></p>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( $needs as $cardsco_need ) : ?>
					<li>
						<?php
						printf(
							/* translators: 1: occasion name, 2: number of people. */
							esc_html( _n( '%1$s — %2$d person coming up', '%1$s — %2$d people coming up', (int) $cardsco_need['count'], 'cardsco' ) ),
							esc_html( $cardsco_need['label'] ),
							(int) $cardsco_need['count']
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'cardsco-occasions', 'tab' => 'library', 'company' => $company_id ), admin_url( 'admin.php' ) ) ); ?>">
					<?php esc_html_e( 'Choose a card and wording for each', 'cardsco' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div class="cardsco-tiles">
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['queued'] ) ); ?></strong><span><?php esc_html_e( 'Queued', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['on_hold'] ) ); ?></strong><span><?php esc_html_e( 'On hold', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['writing'] ) ); ?></strong><span><?php esc_html_e( 'Being written', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $counts['posted'] ) ); ?></strong><span><?php esc_html_e( 'Posted', 'cardsco' ); ?></span></div>
		<div class="cardsco-tile"><strong style="<?php echo $counts['overdue'] ? 'color:#d63638' : ''; ?>"><?php echo esc_html( number_format_i18n( $counts['overdue'] ) ); ?></strong><span><?php esc_html_e( 'Past write-by', 'cardsco' ); ?></span></div>
	</div>

	<div class="cardsco-bar">
		<?php $this->company_switcher( $company_id, 'cardsco-queue' ); ?>

		<form method="get">
			<input type="hidden" name="page" value="cardsco-queue" />
			<input type="hidden" name="company" value="<?php echo esc_attr( $company_id ); ?>" />

			<div class="cardsco-field">
				<label for="cardsco-qstatus"><?php esc_html_e( 'Showing', 'cardsco' ); ?></label>
				<select name="status" id="cardsco-qstatus" onchange="this.form.submit()">
					<option value="open" <?php selected( $cardsco_status, 'open' ); ?>><?php esc_html_e( 'Still to go out', 'cardsco' ); ?></option>
					<?php foreach ( $cardsco_labels as $cardsco_key => $cardsco_label ) : ?>
						<option value="<?php echo esc_attr( $cardsco_key ); ?>" <?php selected( $cardsco_status, $cardsco_key ); ?>><?php echo esc_html( $cardsco_label ); ?></option>
					<?php endforeach; ?>
					<option value="any" <?php selected( $cardsco_status, 'any' ); ?>><?php esc_html_e( 'Everything', 'cardsco' ); ?></option>
				</select>
			</div>

			<div class="cardsco-field">
				<label for="cardsco-qsearch"><?php esc_html_e( 'Search', 'cardsco' ); ?></label>
				<input type="text" name="s" id="cardsco-qsearch" value="<?php echo esc_attr( $cardsco_search ); ?>" placeholder="<?php esc_attr_e( 'Name, company or postcode', 'cardsco' ); ?>" />
			</div>

			<div class="cardsco-field">
				<label>&nbsp;</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'cardsco' ); ?></button>
			</div>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
			<input type="hidden" name="action" value="cardsco_run_scan" />
			<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />
			<div class="cardsco-field">
				<label>&nbsp;</label>
				<button type="submit" class="button"><?php esc_html_e( 'Check for cards due now', 'cardsco' ); ?></button>
			</div>
		</form>
	</div>

	<?php if ( $last_scan ) : ?>
		<p class="description" style="margin-top:-6px">
			<?php
			printf(
				/* translators: 1: when it ran, 2: occasions looked at, 3: how many days ahead. */
				esc_html__( 'Last checked %1$s — looked at %2$d occasions up to %3$d days ahead. This normally runs on its own once a day.', 'cardsco' ),
				esc_html( $last_scan['ran_at'] ),
				(int) $last_scan['looked_at'],
				(int) $last_scan['horizon']
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( empty( $results['items'] ) ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'Nothing in the queue.', 'cardsco' ); ?></strong></p>
			<p><?php esc_html_e( 'Cards appear here once an occasion gets close enough to need writing.', 'cardsco' ); ?></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:20%"><?php esc_html_e( 'Who', 'cardsco' ); ?></th>
					<th scope="col" style="width:14%"><?php esc_html_e( 'Occasion', 'cardsco' ); ?></th>
					<th scope="col" style="width:14%"><?php esc_html_e( 'Card', 'cardsco' ); ?></th>
					<th scope="col" style="width:22%"><?php esc_html_e( 'Message', 'cardsco' ); ?></th>
					<th scope="col" style="width:15%"><?php esc_html_e( 'Dates', 'cardsco' ); ?></th>
					<th scope="col" style="width:15%"><?php esc_html_e( 'Status', 'cardsco' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $results['items'] as $cardsco_card ) : ?>
				<?php
				$cardsco_late = $cardsco_card['write_by'] && $cardsco_card['write_by'] < $cardsco_today
					&& in_array( $cardsco_card['status'], array( 'queued', 'on_hold', 'writing' ), true );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $cardsco_card['recipient_name'] ); ?></strong>
						<?php if ( $cardsco_card['recipient_company'] ) : ?>
							<div style="color:#646970;font-size:12px"><?php echo esc_html( $cardsco_card['recipient_company'] ); ?></div>
						<?php endif; ?>
						<div style="color:#646970;font-size:12px;white-space:pre-line"><?php echo esc_html( $cardsco_card['address_snapshot'] ); ?></div>
					</td>
					<td><?php echo esc_html( $cardsco_card['occasion_label'] ); ?></td>
					<td><?php echo esc_html( $cardsco_card['card_title'] ); ?></td>
					<td style="white-space:pre-line;color:#50575e"><?php echo esc_html( wp_trim_words( str_replace( "\n", ' ', (string) $cardsco_card['message_final'] ), 18, '…' ) ); ?></td>
					<td>
						<div>
							<?php
							printf(
								/* translators: %s: the date of the occasion. */
								esc_html__( 'Occasion %s', 'cardsco' ),
								esc_html( $cardsco_card['event_date'] )
							);
							?>
						</div>
						<div style="<?php echo $cardsco_late ? 'color:#d63638;font-weight:600' : 'color:#646970'; ?>">
							<?php
							printf(
								/* translators: %s: the date the card must be written by. */
								esc_html__( 'Write by %s', 'cardsco' ),
								esc_html( $cardsco_card['write_by'] )
							);
							?>
						</div>
						<div style="color:#646970;font-size:12px">
							<?php
							printf(
								/* translators: %s: the date the card must be posted by. */
								esc_html__( 'Post by %s', 'cardsco' ),
								esc_html( $cardsco_card['post_by'] )
							);
							?>
						</div>
					</td>
					<td>
						<span class="cardsco-pill <?php echo 'on_hold' === $cardsco_card['status'] ? 'is-warn' : ( in_array( $cardsco_card['status'], array( 'posted', 'delivered' ), true ) ? 'is-good' : '' ); ?>">
							<?php echo esc_html( $cardsco_labels[ $cardsco_card['status'] ] ?? $cardsco_card['status'] ); ?>
						</span>
						<?php if ( $cardsco_card['hold_reason'] ) : ?>
							<div style="color:#8a4b00;font-size:12px;margin-top:4px">
								<?php echo esc_html( $cardsco_holds[ $cardsco_card['hold_reason'] ] ?? $cardsco_card['hold_reason'] ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $cardsco_late ) : ?>
							<div style="color:#d63638;font-size:12px"><?php esc_html_e( 'Overdue', 'cardsco' ); ?></div>
						<?php endif; ?>
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
							/* translators: %s: number of cards. */
							esc_html( _n( '%s card', '%s cards', $results['total'], 'cardsco' ) ),
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

	<p class="description" style="margin-top:18px;max-width:720px">
		<?php esc_html_e( 'Cards are held rather than written when something is missing, so nothing goes out half finished. A card held for an address goes back in the queue by itself once the address is filled in. Marking cards as written and posted comes in the next piece of work.', 'cardsco' ); ?>
	</p>
</div>
