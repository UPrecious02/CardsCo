<?php
/**
 * The occasion setup, steps 2 to 7.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var array         $programme
 * @var int           $step
 * @var int           $company_id
 * @var array         $steps
 * @var array|null    $category
 * @var array         $people
 * @var array         $estimate
 */

defined( 'ABSPATH' ) || exit;

$cardsco_pid     = (int) $programme['id'];
$cardsco_reached = (int) $programme['setup_step'];
$cardsco_months  = cardsco_month_choices();
$cardsco_live    = 'active' === $programme['status'];

/**
 * A link to another step, only if it has been reached.
 */
$cardsco_step_url = static function ( $n ) use ( $cardsco_pid ) {
	return CardsCo_Wizard::url(
		array(
			'programme' => $cardsco_pid,
			'step'      => (int) $n,
		)
	);
};
?>
<div class="wrap cardsco-wizard">
	<div class="cardsco-wiz-top">
		<div>
			<strong><?php echo esc_html( $programme['name'] ); ?></strong>
			<div class="cardsco-wiz-sub">
				<?php
				echo $cardsco_live
					? esc_html__( 'Running', 'cardsco' )
					: esc_html__( 'New occasion · draft saved', 'cardsco' );
				?>
			</div>
		</div>

		<ol class="cardsco-wiz-steps">
			<?php foreach ( $steps as $cardsco_n => $cardsco_s ) : ?>
				<?php
				$cardsco_done = CardsCo_Programme::step_complete( $programme, $cardsco_n );
				$cardsco_now  = ( (int) $cardsco_n === (int) $step );
				$cardsco_open = ( $cardsco_n <= $cardsco_reached );
				$cardsco_cls  = $cardsco_now ? 'is-now' : ( $cardsco_done ? 'is-done' : '' );
				?>
				<li class="<?php echo esc_attr( $cardsco_cls ); ?>">
					<?php if ( $cardsco_open && ! $cardsco_now ) : ?>
						<a href="<?php echo esc_url( $cardsco_step_url( $cardsco_n ) ); ?>">
							<span><?php echo $cardsco_done ? '&#10003;' : esc_html( $cardsco_n ); ?></span><?php echo esc_html( $cardsco_s['label'] ); ?>
						</a>
					<?php else : ?>
						<span class="cardsco-wiz-static">
							<span><?php echo $cardsco_done && ! $cardsco_now ? '&#10003;' : esc_html( $cardsco_n ); ?></span><?php echo esc_html( $cardsco_s['label'] ); ?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<a class="button" href="<?php echo esc_url( CardsCo_Wizard::url( array( 'company' => $company_id ) ) ); ?>">
			<?php esc_html_e( 'Save and exit', 'cardsco' ); ?>
		</a>
	</div>

	<?php $admin->print_notices(); ?>

	<div class="cardsco-wiz-body">
		<div class="cardsco-wiz-main">
			<p class="cardsco-stepline">
				<?php
				printf(
					/* translators: %d: step number. */
					esc_html__( 'Step %d of 7', 'cardsco' ),
					(int) $step
				);
				?>
			</p>
			<h2 class="cardsco-stephead"><?php echo esc_html( $steps[ $step ]['heading'] ); ?></h2>

			<?php
			/* =========================== 2 · PEOPLE =========================== */
			if ( 2 === $step ) :
				$cardsco_list     = CardsCo_Programme_Import::people( $programme, 50 );
				$cardsco_problems = get_transient( 'cardsco_prog_problems_' . $cardsco_pid );
				$cardsco_type     = CardsCo_Category::primary_type( $programme['category'], $company_id );
				delete_transient( 'cardsco_prog_problems_' . $cardsco_pid );
				?>
				<p class="description" style="margin-bottom:16px">
					<?php esc_html_e( 'Upload your list once. We only need a name and the day and month.', 'cardsco' ); ?>
				</p>

				<div class="cardsco-panel">
					<div class="cardsco-panel-head">
						<div>
							<strong><?php esc_html_e( 'Your list', 'cardsco' ); ?></strong>
							<div class="cardsco-wiz-sub"><?php esc_html_e( 'Columns: first name, last name, and the date', 'cardsco' ); ?></div>
						</div>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'cardsco_programme_template', 'programme' => $cardsco_pid ), admin_url( 'admin-post.php' ) ), CardsCo_Admin::NONCE ) ); ?>">
							<?php esc_html_e( 'Download template', 'cardsco' ); ?>
						</a>
					</div>

					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cardsco-upload">
						<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
						<input type="hidden" name="action" value="cardsco_programme_people" />
						<input type="hidden" name="what" value="upload" />
						<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />
						<input type="file" name="people_file" accept=".csv,.txt,.tsv,text/csv" required />
						<button type="submit" class="button"><?php esc_html_e( 'Upload', 'cardsco' ); ?></button>
					</form>

					<div class="cardsco-tiles" style="margin:14px 0 0">
						<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $people['ready'] ) ); ?></strong><span><?php esc_html_e( 'with a date', 'cardsco' ); ?></span></div>
						<div class="cardsco-tile"><strong style="<?php echo $people['missing_date'] ? 'color:#8a4b00' : ''; ?>"><?php echo esc_html( number_format_i18n( $people['missing_date'] ) ); ?></strong><span><?php esc_html_e( 'missing a date', 'cardsco' ); ?></span></div>
						<div class="cardsco-tile"><strong><?php echo esc_html( number_format_i18n( $people['total'] ) ); ?></strong><span><?php esc_html_e( 'people in total', 'cardsco' ); ?></span></div>
					</div>

					<?php if ( ! empty( $cardsco_problems ) ) : ?>
						<div class="notice notice-warning inline" style="margin:14px 0 0">
							<p><strong><?php esc_html_e( 'Some rows need a look:', 'cardsco' ); ?></strong></p>
							<ul style="list-style:disc;margin-left:20px">
								<?php foreach ( (array) $cardsco_problems as $cardsco_problem ) : ?>
									<li><?php echo esc_html( $cardsco_problem ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php if ( $cardsco_list ) : ?>
						<table class="wp-list-table widefat striped" style="margin-top:14px">
							<thead>
								<tr>
									<th scope="col" style="width:38%"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
									<th scope="col" style="width:26%"><?php echo esc_html( $cardsco_type ? $cardsco_type['label'] : __( 'Date', 'cardsco' ) ); ?></th>
									<th scope="col" style="width:26%"><?php esc_html_e( 'Next card', 'cardsco' ); ?></th>
									<th scope="col" style="width:10%"></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $cardsco_list as $cardsco_person ) : ?>
								<tr>
									<td><strong><?php echo esc_html( trim( $cardsco_person['first_name'] . ' ' . $cardsco_person['last_name'] ) ); ?></strong></td>
									<td>
										<?php if ( $cardsco_person['event_month'] ) : ?>
											<?php echo esc_html( cardsco_format_occasion_date( $cardsco_person['event_month'], $cardsco_person['event_day'] ) ); ?>
										<?php else : ?>
											<span style="color:#d63638"><?php esc_html_e( 'Missing', 'cardsco' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										echo $cardsco_person['next_occurrence']
											? esc_html( cardsco_days_away( $cardsco_person['next_occurrence'] ) . ' · ' . $cardsco_person['next_occurrence'] )
											: '&mdash;';
										?>
									</td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
											<input type="hidden" name="action" value="cardsco_programme_people" />
											<input type="hidden" name="what" value="remove" />
											<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />
											<input type="hidden" name="recipient_id" value="<?php echo esc_attr( $cardsco_person['id'] ); ?>" />
											<button type="submit" class="button-link submitdelete"><?php esc_html_e( 'Remove', 'cardsco' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<details class="cardsco-panel" style="margin-top:14px">
					<summary><strong><?php esc_html_e( 'Add someone by hand', 'cardsco' ); ?></strong></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
						<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
						<input type="hidden" name="action" value="cardsco_programme_people" />
						<input type="hidden" name="what" value="add" />
						<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />

						<div class="cardsco-grid">
							<div>
								<label for="cardsco-pf"><strong><?php esc_html_e( 'First name', 'cardsco' ); ?></strong></label>
								<input type="text" id="cardsco-pf" name="first_name" required />
							</div>
							<div>
								<label for="cardsco-pl"><strong><?php esc_html_e( 'Last name', 'cardsco' ); ?></strong></label>
								<input type="text" id="cardsco-pl" name="last_name" />
							</div>
							<div>
								<label><strong><?php esc_html_e( 'Date', 'cardsco' ); ?></strong></label>
								<div style="display:flex;gap:6px">
									<select name="day">
										<option value=""><?php esc_html_e( 'Day', 'cardsco' ); ?></option>
										<?php for ( $cardsco_d = 1; $cardsco_d <= 31; $cardsco_d++ ) : ?>
											<option value="<?php echo esc_attr( $cardsco_d ); ?>"><?php echo esc_html( $cardsco_d ); ?></option>
										<?php endfor; ?>
									</select>
									<select name="month">
										<option value=""><?php esc_html_e( 'Month', 'cardsco' ); ?></option>
										<?php foreach ( $cardsco_months as $cardsco_mn => $cardsco_ml ) : ?>
											<option value="<?php echo esc_attr( $cardsco_mn ); ?>"><?php echo esc_html( $cardsco_ml ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
							<div>
								<label for="cardsco-pe"><strong><?php esc_html_e( 'Email', 'cardsco' ); ?></strong></label>
								<input type="email" id="cardsco-pe" name="email" />
							</div>
						</div>

						<p><button type="submit" class="button"><?php esc_html_e( 'Add', 'cardsco' ); ?></button></p>
					</form>
				</details>

				<div class="cardsco-wiz-foot">
					<span></span>
					<a class="button button-primary" href="<?php echo esc_url( $cardsco_step_url( 3 ) ); ?>">
						<?php
						echo $people['ready']
							? esc_html(
								sprintf(
									/* translators: %d: how many people have a date. */
									_n( '%d date ready — continue', '%d dates ready — continue', (int) $people['ready'], 'cardsco' ),
									(int) $people['ready']
								)
							)
							: esc_html__( 'Continue', 'cardsco' );
						?>
					</a>
				</div>

			<?php
			/* ============================ 3 · CARD ============================ */
			elseif ( 3 === $step ) :
				$cardsco_cards = CardsCo_Category::cards( $programme['category'], $company_id );
				?>
				<p class="description" style="margin-bottom:16px">
					<?php
					printf(
						/* translators: %s: card format, for example "Folded card". */
						esc_html__( 'All designs for this occasion are %s.', 'cardsco' ),
						esc_html( $category['format'] ?? '' )
					);
					?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="cardsco_programme_step" />
					<input type="hidden" name="step" value="3" />
					<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />

					<?php if ( empty( $cardsco_cards ) ) : ?>
						<div class="cardsco-empty">
							<p><strong><?php esc_html_e( 'No designs for this occasion yet.', 'cardsco' ); ?></strong></p>
							<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . CardsCo_Card::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add a card', 'cardsco' ); ?></a>
						</div>
					<?php else : ?>
						<div class="cardsco-cards">
							<?php foreach ( $cardsco_cards as $cardsco_card ) : ?>
								<label class="cardsco-choice">
									<input type="radio" name="card_id" value="<?php echo esc_attr( $cardsco_card->ID ); ?>" <?php checked( (int) $programme['card_id'], (int) $cardsco_card->ID ); ?> required />
									<span class="cardsco-choice-body">
										<?php if ( has_post_thumbnail( $cardsco_card->ID ) ) : ?>
											<span class="cardsco-choice-art"><?php echo get_the_post_thumbnail( $cardsco_card->ID, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
										<?php endif; ?>
										<span class="cardsco-choice-title"><?php echo esc_html( $cardsco_card->post_title ); ?></span>
										<span class="cardsco-choice-meta">
											<?php echo esc_html( CardsCo_Settings::get( 'currency' ) . ' ' . CardsCo_Card::price( $cardsco_card->ID, $company_id ) ); ?>
										</span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php require CARDSCO_PATH . 'includes/admin/views/programme-step-footer.php'; ?>
				</form>

			<?php
			/* ========================== 4 · MESSAGE =========================== */
			elseif ( 4 === $step ) :
				$cardsco_messages = CardsCo_Category::messages( $programme['category'], $company_id );
				$cardsco_chosen   = (int) $programme['message_id'];
				$cardsco_sample   = $cardsco_chosen ? CardsCo_Message::text( $cardsco_chosen ) : '';
				$cardsco_sample   = $programme['message_override'] ? $programme['message_override'] : $cardsco_sample;
				$cardsco_preview  = CardsCo_Merge::render( $cardsco_sample, CardsCo_Merge::sample_context() );
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="cardsco_programme_step" />
					<input type="hidden" name="step" value="4" />
					<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />

					<div class="cardsco-panel">
						<p style="margin-top:0">
							<label for="cardsco-msg"><strong><?php esc_html_e( 'Wording', 'cardsco' ); ?></strong></label><br />
							<select id="cardsco-msg" name="message_id" style="width:100%;max-width:520px">
								<option value="0"><?php esc_html_e( '— choose —', 'cardsco' ); ?></option>
								<?php foreach ( $cardsco_messages as $cardsco_m ) : ?>
									<option value="<?php echo esc_attr( $cardsco_m->ID ); ?>" <?php selected( $cardsco_chosen, (int) $cardsco_m->ID ); ?>>
										<?php echo esc_html( $cardsco_m->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label for="cardsco-override"><strong><?php esc_html_e( 'Or write your own', 'cardsco' ); ?></strong></label>
							<textarea id="cardsco-override" name="message_override" rows="4" style="width:100%;max-width:520px"><?php echo esc_textarea( $programme['message_override'] ); ?></textarea>
							<span class="description"><?php echo esc_html( CardsCo_Merge::help_text() ); ?></span>
						</p>

						<?php if ( '' !== trim( $cardsco_sample ) ) : ?>
							<p style="margin-bottom:6px"><strong><?php esc_html_e( 'How it reads', 'cardsco' ); ?></strong></p>
							<div class="cardsco-preview"><?php echo esc_html( $cardsco_preview['text'] ); ?></div>
						<?php endif; ?>
					</div>

					<div class="cardsco-panel" style="margin-top:14px">
						<strong><?php esc_html_e( 'How it is written', 'cardsco' ); ?></strong>
						<div class="cardsco-grid" style="margin-top:10px">
							<div>
								<label for="cardsco-hand"><?php esc_html_e( 'Handwriting', 'cardsco' ); ?></label>
								<input type="text" id="cardsco-hand" name="handwriting" value="<?php echo esc_attr( $programme['handwriting'] ); ?>" placeholder="Arthur" />
							</div>
							<div>
								<label for="cardsco-ink"><?php esc_html_e( 'Ink', 'cardsco' ); ?></label>
								<input type="text" id="cardsco-ink" name="ink" value="<?php echo esc_attr( $programme['ink'] ); ?>" placeholder="<?php esc_attr_e( 'blue', 'cardsco' ); ?>" />
							</div>
							<div class="full">
								<label for="cardsco-sign"><?php esc_html_e( 'Signed', 'cardsco' ); ?></label>
								<input type="text" id="cardsco-sign" name="signature" value="<?php echo esc_attr( $programme['signature'] ); ?>" placeholder="<?php esc_attr_e( 'Everyone at Acme', 'cardsco' ); ?>" />
							</div>
						</div>
					</div>

					<?php require CARDSCO_PATH . 'includes/admin/views/programme-step-footer.php'; ?>
				</form>

			<?php
			/* ========================= 5 · SHIPPING =========================== */
			elseif ( 5 === $step ) : ?>
				<p class="description" style="margin-bottom:16px">
					<?php esc_html_e( 'Send them to one business address, or to each person’s own address.', 'cardsco' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="cardsco_programme_step" />
					<input type="hidden" name="step" value="5" />
					<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />

					<div class="cardsco-cards cardsco-cards-two">
						<label class="cardsco-choice">
							<input type="radio" name="shipping_mode" value="company" <?php checked( $programme['shipping_mode'], 'company' ); ?> />
							<span class="cardsco-choice-body">
								<span class="cardsco-choice-title"><?php esc_html_e( 'Ship to our company address', 'cardsco' ); ?></span>
								<span class="cardsco-choice-blurb"><?php esc_html_e( 'Cards arrive at the office, ready to hand out.', 'cardsco' ); ?></span>
							</span>
						</label>
						<label class="cardsco-choice">
							<input type="radio" name="shipping_mode" value="individual" <?php checked( $programme['shipping_mode'], 'individual' ); ?> />
							<span class="cardsco-choice-body">
								<span class="cardsco-choice-title"><?php esc_html_e( 'Ship to individual addresses', 'cardsco' ); ?></span>
								<span class="cardsco-choice-blurb"><?php esc_html_e( 'Each card goes to the person’s own address.', 'cardsco' ); ?></span>
							</span>
						</label>
					</div>

					<div class="cardsco-panel" style="margin-top:16px">
						<strong><?php esc_html_e( 'Shipping address', 'cardsco' ); ?></strong>
						<p class="description" style="margin-top:2px"><?php esc_html_e( 'Only needed when the cards come to you in one delivery.', 'cardsco' ); ?></p>

						<p>
							<label for="cardsco-ship"><?php esc_html_e( 'Address', 'cardsco' ); ?></label>
							<textarea id="cardsco-ship" name="ship_address" rows="4" style="width:100%;max-width:520px"><?php echo esc_textarea( $programme['ship_address'] ); ?></textarea>
						</p>

						<div class="cardsco-grid" style="max-width:520px">
							<div>
								<label for="cardsco-shipp"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
								<input type="text" id="cardsco-shipp" name="ship_postcode" value="<?php echo esc_attr( $programme['ship_postcode'] ); ?>" />
							</div>
							<div>
								<label for="cardsco-shipc"><?php esc_html_e( 'Country', 'cardsco' ); ?></label>
								<input type="text" id="cardsco-shipc" name="ship_country" maxlength="2" value="<?php echo esc_attr( $programme['ship_country'] ); ?>" />
							</div>
						</div>

						<?php if ( 'individual' === $programme['shipping_mode'] && $people['missing_address'] ) : ?>
							<div class="notice notice-warning inline" style="margin:14px 0 0">
								<p>
									<?php
									printf(
										/* translators: %d: how many people. */
										esc_html( _n( '%d person has no usable address. Their card will be held until it is filled in.', '%d people have no usable address. Their cards will be held until they are filled in.', (int) $people['missing_address'], 'cardsco' ) ),
										(int) $people['missing_address']
									);
									?>
								</p>
							</div>
						<?php endif; ?>
					</div>

					<?php require CARDSCO_PATH . 'includes/admin/views/programme-step-footer.php'; ?>
				</form>

			<?php
			/* ========================== 6 · TIMING ============================ */
			elseif ( 6 === $step ) :
				$cardsco_next_period = CardsCo_Programme::next_period( $programme );
				$cardsco_next_count  = CardsCo_Programme::count_in_period( $programme, $cardsco_next_period );
				$cardsco_next_arrive = CardsCo_Programme::box_arrive_date( $programme, $cardsco_next_period );
				?>
				<p class="description" style="margin-bottom:16px">
					<?php esc_html_e( 'We work back from each date so nobody’s card is late.', 'cardsco' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="cardsco_programme_step" />
					<input type="hidden" name="step" value="6" />
					<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />

					<label class="cardsco-choice cardsco-choice-wide">
						<input type="radio" name="timing_mode" value="monthly_box" <?php checked( $programme['timing_mode'], 'monthly_box' ); ?> />
						<span class="cardsco-choice-body">
							<span class="cardsco-choice-title"><?php esc_html_e( 'One box a month', 'cardsco' ); ?></span>
							<span class="cardsco-choice-blurb">
								<?php
								printf(
									/* translators: %d: day of the month. */
									esc_html__( 'Every card for the coming month arrives together by the %d, so you can hand them out on the day.', 'cardsco' ),
									(int) $programme['box_arrive_day']
								);
								?>
							</span>
							<?php if ( $cardsco_next_arrive ) : ?>
								<span class="cardsco-choice-meta">
									<?php
									printf(
										/* translators: 1: month, 2: number of cards, 3: arrival date. */
										esc_html__( 'Next box: %1$s · %2$d cards · arrives by %3$s', 'cardsco' ),
										esc_html( gmdate( 'F', strtotime( $cardsco_next_period . '-01' ) ) ),
										(int) $cardsco_next_count,
										esc_html( $cardsco_next_arrive )
									);
									?>
								</span>
							<?php endif; ?>
						</span>
					</label>

					<label class="cardsco-choice cardsco-choice-wide" style="margin-top:10px">
						<input type="radio" name="timing_mode" value="days_before" <?php checked( $programme['timing_mode'], 'days_before' ); ?> />
						<span class="cardsco-choice-body">
							<span class="cardsco-choice-title"><?php esc_html_e( 'A few days before each date', 'cardsco' ); ?></span>
							<span class="cardsco-choice-blurb"><?php esc_html_e( 'One small delivery per card, arriving 2 to 3 working days before.', 'cardsco' ); ?></span>
						</span>
					</label>

					<div class="cardsco-panel" style="margin-top:16px">
						<strong><?php esc_html_e( 'Settings', 'cardsco' ); ?></strong>

						<div class="cardsco-grid" style="margin-top:10px">
							<div>
								<label for="cardsco-start"><?php esc_html_e( 'Start from', 'cardsco' ); ?></label>
								<input type="date" id="cardsco-start" name="starts_on" value="<?php echo esc_attr( $programme['starts_on'] ); ?>" required />
							</div>
							<div>
								<label for="cardsco-runs"><?php esc_html_e( 'Runs', 'cardsco' ); ?></label>
								<select id="cardsco-runs" name="recurrence">
									<option value="annual" <?php selected( $programme['recurrence'], 'annual' ); ?>><?php esc_html_e( 'Every year, until you turn it off', 'cardsco' ); ?></option>
									<option value="once" <?php selected( $programme['recurrence'], 'once' ); ?>><?php esc_html_e( 'Once only', 'cardsco' ); ?></option>
								</select>
							</div>
							<div>
								<label for="cardsco-boxday"><?php esc_html_e( 'Box arrives by day', 'cardsco' ); ?></label>
								<input type="number" id="cardsco-boxday" name="box_arrive_day" min="1" max="28" value="<?php echo esc_attr( $programme['box_arrive_day'] ); ?>" />
							</div>
							<div>
								<label for="cardsco-daysb"><?php esc_html_e( 'Days before each date', 'cardsco' ); ?></label>
								<input type="number" id="cardsco-daysb" name="days_before" min="1" max="30" value="<?php echo esc_attr( $programme['days_before'] ); ?>" />
							</div>
						</div>

						<p style="margin-top:12px">
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="notify_before_box" value="1" <?php checked( (int) $programme['notify_before_box'], 1 ); ?> />
								<?php esc_html_e( 'Email me before each box ships', 'cardsco' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="weekend_rule" value="1" <?php checked( (int) $programme['weekend_rule'], 1 ); ?> />
								<?php esc_html_e( 'Send a card for dates that fall at the weekend on the Friday before', 'cardsco' ); ?>
							</label>
						</p>
					</div>

					<?php require CARDSCO_PATH . 'includes/admin/views/programme-step-footer.php'; ?>
				</form>

			<?php
			/* ========================== 7 · REVIEW ============================ */
			elseif ( 7 === $step ) :
				$cardsco_ready   = CardsCo_Programme::can_activate( $programme );
				$cardsco_cardobj = $programme['card_id'] ? get_post( (int) $programme['card_id'] ) : null;
				$cardsco_msgobj  = $programme['message_id'] ? get_post( (int) $programme['message_id'] ) : null;
				$cardsco_wording = $programme['message_override'] ? $programme['message_override'] : ( $cardsco_msgobj ? CardsCo_Message::text( $cardsco_msgobj->ID ) : '' );
				?>
				<p class="description" style="margin-bottom:16px">
					<?php esc_html_e( 'You only pay for cards as they are written. Nothing is charged today.', 'cardsco' ); ?>
				</p>

				<?php if ( is_wp_error( $cardsco_ready ) ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 14px">
						<p><strong><?php esc_html_e( 'Not quite ready:', 'cardsco' ); ?></strong></p>
						<ul style="list-style:disc;margin-left:20px">
							<?php foreach ( $cardsco_ready->get_error_messages() as $cardsco_why ) : ?>
								<li><?php echo esc_html( $cardsco_why ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="cardsco-review">
					<div class="cardsco-panel">
						<?php
						$cardsco_rows = array(
							array(
								1,
								__( 'Occasion', 'cardsco' ),
								$programme['name'],
								$programme['starts_on']
									? sprintf(
										/* translators: 1: how often, 2: start date. */
										__( '%1$s, from %2$s', 'cardsco' ),
										'annual' === $programme['recurrence'] ? __( 'Every year', 'cardsco' ) : __( 'Once', 'cardsco' ),
										$programme['starts_on']
									)
									: __( 'No start date yet', 'cardsco' ),
							),
							array(
								2,
								__( 'People', 'cardsco' ),
								sprintf(
									/* translators: %d: number of people. */
									_n( '%d person', '%d people', (int) $people['total'], 'cardsco' ),
									(int) $people['total']
								),
								sprintf(
									/* translators: 1: how many have a date, 2: how many do not. */
									__( '%1$d with a date · %2$d to add later', 'cardsco' ),
									(int) $people['ready'],
									(int) $people['missing_date']
								),
							),
							array(
								3,
								__( 'Card', 'cardsco' ),
								$cardsco_cardobj ? $cardsco_cardobj->post_title : __( 'Not chosen', 'cardsco' ),
								$category['format'] ?? '',
							),
							array(
								4,
								__( 'Message', 'cardsco' ),
								$cardsco_wording ? $cardsco_wording : __( 'Not chosen', 'cardsco' ),
								trim( implode( ' · ', array_filter( array( $programme['handwriting'], $programme['ink'], $programme['signature'] ? sprintf( /* translators: %s: signature. */ __( 'signed %s', 'cardsco' ), $programme['signature'] ) : '' ) ) ) ),
							),
							array(
								5,
								__( 'Shipping', 'cardsco' ),
								'company' === $programme['shipping_mode'] ? __( 'To your office', 'cardsco' ) : __( 'To each person', 'cardsco' ),
								'company' === $programme['shipping_mode'] ? trim( str_replace( "\n", ', ', (string) $programme['ship_address'] ) . ' ' . $programme['ship_postcode'] ) : '',
							),
							array(
								6,
								__( 'Timing', 'cardsco' ),
								'monthly_box' === $programme['timing_mode'] ? __( 'One box a month', 'cardsco' ) : __( 'A few days before each date', 'cardsco' ),
								'monthly_box' === $programme['timing_mode']
									? sprintf(
										/* translators: %d: day of the month. */
										__( 'Arrives by the %d of the month before', 'cardsco' ),
										(int) $programme['box_arrive_day']
									)
									: sprintf(
										/* translators: %d: number of days. */
										__( '%d days before', 'cardsco' ),
										(int) $programme['days_before']
									),
							),
						);

						foreach ( $cardsco_rows as $cardsco_row ) :
							list( $cardsco_n, $cardsco_label, $cardsco_main, $cardsco_sub ) = $cardsco_row;
							?>
							<div class="cardsco-review-row">
								<div>
									<div class="cardsco-review-label"><?php echo esc_html( $cardsco_label ); ?></div>
									<div class="cardsco-review-main"><?php echo esc_html( $cardsco_main ); ?></div>
									<?php if ( $cardsco_sub ) : ?>
										<div class="cardsco-review-sub"><?php echo esc_html( $cardsco_sub ); ?></div>
									<?php endif; ?>
								</div>
								<a href="<?php echo esc_url( $cardsco_step_url( $cardsco_n ) ); ?>"><?php esc_html_e( 'Edit', 'cardsco' ); ?></a>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="cardsco-panel cardsco-pay">
						<strong><?php esc_html_e( 'What you’ll pay', 'cardsco' ); ?></strong>

						<div class="cardsco-pay-row"><span><?php esc_html_e( 'Price per card', 'cardsco' ); ?></span><span><?php echo esc_html( $estimate['currency'] . ' ' . $estimate['unit_price'] ); ?></span></div>
						<div class="cardsco-pay-row">
							<span>
								<?php
								printf(
									/* translators: %s: month name. */
									esc_html__( 'First box · %s', 'cardsco' ),
									esc_html( gmdate( 'F', strtotime( $estimate['next_period'] . '-01' ) ) )
								);
								?>
							</span>
							<span>
								<?php
								printf(
									/* translators: %d: number of cards. */
									esc_html( _n( '%d card', '%d cards', (int) $estimate['next_count'], 'cardsco' ) ),
									(int) $estimate['next_count']
								);
								?>
							</span>
						</div>
						<div class="cardsco-pay-row"><span><?php esc_html_e( 'That box', 'cardsco' ); ?></span><span><?php echo esc_html( $estimate['currency'] . ' ' . $estimate['next_total'] ); ?></span></div>
						<div class="cardsco-pay-row"><span><?php esc_html_e( 'Postage', 'cardsco' ); ?></span><span><?php esc_html_e( 'Added at payment', 'cardsco' ); ?></span></div>
						<div class="cardsco-pay-row is-total"><span><?php esc_html_e( 'Due before the first box', 'cardsco' ); ?></span><span><?php echo esc_html( $estimate['currency'] . ' ' . $estimate['next_total'] ); ?> + <?php esc_html_e( 'postage', 'cardsco' ); ?></span></div>

						<p class="description" style="margin-top:12px">
							<?php
							printf(
								/* translators: 1: cards a year, 2: yearly total. */
								esc_html__( 'About %1$d cards a year (about %2$s plus postage). Each month is charged before its box is written.', 'cardsco' ),
								(int) $estimate['per_year'],
								esc_html( $estimate['currency'] . ' ' . $estimate['yearly_total'] )
							);
							?>
						</p>
					</div>
				</div>

				<div class="cardsco-wiz-foot">
					<a class="button" href="<?php echo esc_url( $cardsco_step_url( 6 ) ); ?>"><?php esc_html_e( 'Back', 'cardsco' ); ?></a>

					<?php if ( $cardsco_live ) : ?>
						<span class="cardsco-pill is-good"><?php esc_html_e( 'This occasion is running', 'cardsco' ); ?></span>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
							<input type="hidden" name="action" value="cardsco_programme_activate" />
							<input type="hidden" name="programme_id" value="<?php echo esc_attr( $cardsco_pid ); ?>" />
							<span class="description" style="margin-right:10px"><?php esc_html_e( 'Payment is set up separately before the first box.', 'cardsco' ); ?></span>
							<button type="submit" class="button button-primary" <?php disabled( is_wp_error( $cardsco_ready ) ); ?>>
								<?php esc_html_e( 'Turn it on', 'cardsco' ); ?>
							</button>
						</form>
					<?php endif; ?>
				</div>

			<?php endif; ?>
		</div>

		<aside class="cardsco-wiz-side">
			<strong><?php esc_html_e( 'Your occasion', 'cardsco' ); ?></strong>

			<?php foreach ( $steps as $cardsco_n => $cardsco_s ) : ?>
				<?php
				$cardsco_done = CardsCo_Programme::step_complete( $programme, $cardsco_n );
				$cardsco_val  = '';

				switch ( $cardsco_n ) {
					case 1:
						$cardsco_val = $programme['name'];
						break;
					case 2:
						$cardsco_val = $people['total']
							? sprintf(
								/* translators: 1: total people, 2: how many have a date. */
								__( '%1$d people · %2$d dates', 'cardsco' ),
								(int) $people['total'],
								(int) $people['ready']
							)
							: '';
						break;
					case 3:
						$cardsco_val = $programme['card_id'] ? get_the_title( (int) $programme['card_id'] ) : '';
						break;
					case 4:
						$cardsco_val = $programme['message_override']
							? wp_trim_words( $programme['message_override'], 6, '…' )
							: ( $programme['message_id'] ? get_the_title( (int) $programme['message_id'] ) : '' );
						break;
					case 5:
						$cardsco_val = 'company' === $programme['shipping_mode']
							? ( $programme['ship_postcode'] ? __( 'Your office', 'cardsco' ) : '' )
							: __( 'Each person', 'cardsco' );
						break;
					case 6:
						$cardsco_val = $programme['starts_on']
							? ( 'monthly_box' === $programme['timing_mode'] ? __( 'One box a month', 'cardsco' ) : __( 'Days before', 'cardsco' ) )
							: '';
						break;
					case 7:
						$cardsco_val = $cardsco_live ? __( 'Running', 'cardsco' ) : '';
						break;
				}
				?>
				<div class="cardsco-side-row <?php echo (int) $cardsco_n === (int) $step ? 'is-now' : ''; ?>">
					<span class="cardsco-side-dot <?php echo $cardsco_done ? 'is-done' : ''; ?>"></span>
					<span>
						<span class="cardsco-side-label"><?php echo esc_html( $cardsco_s['label'] ); ?></span>
						<span class="cardsco-side-value">
							<?php
							echo $cardsco_val
								? esc_html( $cardsco_val )
								: ( (int) $cardsco_n === (int) $step ? esc_html__( 'In progress', 'cardsco' ) : '—' );
							?>
						</span>
					</span>
				</div>
			<?php endforeach; ?>

			<div class="cardsco-side-price">
				<div class="cardsco-pay-row"><span><?php esc_html_e( 'Price per card', 'cardsco' ); ?></span><span><?php echo esc_html( $estimate['currency'] . ' ' . $estimate['unit_price'] ); ?></span></div>
				<p class="description"><?php esc_html_e( 'Charged as the cards are written. Postage is extra.', 'cardsco' ); ?></p>
			</div>
		</aside>
	</div>
</div>
