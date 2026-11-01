<?php
/**
 * The campaign builder, steps 1 to 7.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin    $admin
 * @var array            $campaign
 * @var int              $step
 * @var int              $company_id
 * @var array            $steps
 * @var array[]          $types
 * @var array[]          $segments
 * @var WP_Post[]        $cards
 * @var WP_Post[]        $messages
 * @var array            $fields
 * @var array            $audience
 * @var array            $picked
 * @var array            $preview
 * @var true|WP_Error    $ready
 */

defined( 'ABSPATH' ) || exit;

$cardsco_cid     = (int) $campaign['id'];
$cardsco_reached = (int) $campaign['builder_step'];
$cardsco_locked  = in_array( $campaign['status'], array( 'locked', 'sent', 'cancelled' ), true );
$cardsco_live    = 'scheduled' === $campaign['status'];

$cardsco_step_url = static function ( $n ) use ( $cardsco_cid ) {
	return CardsCo_Campaign_Builder::url(
		array(
			'campaign' => $cardsco_cid,
			'step'     => (int) $n,
		)
	);
};
?>
<div class="wrap cardsco-wizard">
	<div class="cardsco-wiz-top">
		<div>
			<strong><?php echo esc_html( $campaign['name'] ); ?></strong>
			<div class="cardsco-wiz-sub">
				<?php
				$cardsco_words = CardsCo_Campaign::statuses();
				echo esc_html( $cardsco_words[ $campaign['status'] ] ?? $campaign['status'] );
				?>
			</div>
		</div>

		<ol class="cardsco-wiz-steps">
			<?php foreach ( $steps as $cardsco_n => $cardsco_s ) : ?>
				<?php
				$cardsco_done = CardsCo_Campaign::step_complete( $campaign, $cardsco_n );
				$cardsco_now  = ( (int) $cardsco_n === (int) $step );
				$cardsco_can  = ( $cardsco_n <= $cardsco_reached );
				$cardsco_cls  = $cardsco_now ? 'is-now' : ( $cardsco_done ? 'is-done' : '' );
				?>
				<li class="<?php echo esc_attr( $cardsco_cls ); ?>">
					<?php if ( $cardsco_can && ! $cardsco_now ) : ?>
						<a href="<?php echo esc_url( $cardsco_step_url( $cardsco_n ) ); ?>">
							<span><?php echo $cardsco_done ? '&#10003;' : esc_html( $cardsco_n ); ?></span><?php echo esc_html( $cardsco_s['label'] ); ?>
						</a>
					<?php else : ?>
						<span><?php echo $cardsco_done ? '&#10003;' : esc_html( $cardsco_n ); ?></span><?php echo esc_html( $cardsco_s['label'] ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<a class="button" href="<?php echo esc_url( CardsCo_Campaign_Builder::url( array( 'company' => $company_id ) ) ); ?>">
			<?php esc_html_e( 'All campaigns', 'cardsco' ); ?>
		</a>
	</div>

	<?php $admin->print_notices(); ?>

	<?php if ( $cardsco_locked ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'This campaign is with the writers and can no longer be changed. Everything below is how it went out.', 'cardsco' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="cardsco-wiz-body">
		<div class="cardsco-wiz-main">
			<h1><?php echo esc_html( $steps[ $step ]['heading'] ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
				<input type="hidden" name="action" value="cardsco_campaign_step" />
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $cardsco_cid ); ?>" />
				<input type="hidden" name="step" value="<?php echo esc_attr( (int) $step ); ?>" />

				<?php if ( 1 === (int) $step ) : ?>

					<p>
						<label for="cardsco-name"><strong><?php esc_html_e( 'Call it something you will recognise', 'cardsco' ); ?></strong></label><br />
						<input type="text" id="cardsco-name" name="name" class="regular-text" value="<?php echo esc_attr( $campaign['name'] ); ?>" />
					</p>

					<p><strong><?php esc_html_e( 'What is the card for?', 'cardsco' ); ?></strong></p>
					<?php if ( empty( $types ) ) : ?>
						<p class="description"><?php esc_html_e( 'No occasions set up yet. Add one under Occasion library first.', 'cardsco' ); ?></p>
					<?php else : ?>
						<?php foreach ( $types as $cardsco_t ) : ?>
							<p>
								<label>
									<input type="radio" name="occasion_type_id" value="<?php echo esc_attr( (int) $cardsco_t['id'] ); ?>"
										<?php checked( (int) $campaign['occasion_type_id'], (int) $cardsco_t['id'] ); ?> />
									<?php echo esc_html( $cardsco_t['label'] ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					<?php endif; ?>

				<?php elseif ( 2 === (int) $step ) : ?>

					<p class="description">
						<?php esc_html_e( 'Pick the groups this should go to. Anybody in more than one group still only gets one card.', 'cardsco' ); ?>
					</p>

					<?php if ( empty( $segments ) ) : ?>
						<p><?php esc_html_e( 'No groups yet. Create one under Segments, then come back.', 'cardsco' ); ?></p>
					<?php else : ?>
						<?php foreach ( $segments as $cardsco_seg ) : ?>
							<p>
								<label>
									<input type="checkbox" name="segments[]" value="<?php echo esc_attr( (int) $cardsco_seg['id'] ); ?>"
										<?php checked( in_array( (int) $cardsco_seg['id'], $picked['segments'], true ) ); ?> />
									<strong><?php echo esc_html( $cardsco_seg['name'] ); ?></strong>
									<span class="description">
										<?php
										printf(
											/* translators: %d: number of people in the group. */
											esc_html( _n( '%d person', '%d people', (int) $cardsco_seg['member_count'], 'cardsco' ) ),
											(int) $cardsco_seg['member_count']
										);
										?>
									</span>
								</label>
							</p>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php if ( ! empty( $audience['ids'] ) ) : ?>
						<h2><?php esc_html_e( 'Leave anybody out', 'cardsco' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Tick anyone who should not get this one. They stay in the group for next time.', 'cardsco' ); ?></p>
						<div style="max-height:280px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;padding:10px">
							<?php foreach ( $audience['ids'] as $cardsco_rid ) : ?>
								<?php $cardsco_r = CardsCo_Recipient::get( $cardsco_rid ); ?>
								<?php if ( ! $cardsco_r ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<label style="display:block;padding:2px 0">
									<input type="checkbox" name="exclude[]" value="<?php echo esc_attr( $cardsco_rid ); ?>" />
									<?php echo esc_html( trim( $cardsco_r['first_name'] . ' ' . $cardsco_r['last_name'] ) ); ?>
								</label>
							<?php endforeach; ?>
							<?php foreach ( $picked['exclude'] as $cardsco_rid ) : ?>
								<?php $cardsco_r = CardsCo_Recipient::get( $cardsco_rid ); ?>
								<?php if ( ! $cardsco_r ) : ?>
									<?php continue; ?>
								<?php endif; ?>
								<label style="display:block;padding:2px 0;color:#646970">
									<input type="checkbox" name="exclude[]" value="<?php echo esc_attr( $cardsco_rid ); ?>" checked />
									<?php echo esc_html( trim( $cardsco_r['first_name'] . ' ' . $cardsco_r['last_name'] ) ); ?>
									<span class="description"><?php esc_html_e( 'left out', 'cardsco' ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				<?php elseif ( 3 === (int) $step ) : ?>

					<?php if ( empty( $cards ) ) : ?>
						<p><?php esc_html_e( 'No published cards for this occasion yet. Add one under Cards.', 'cardsco' ); ?></p>
					<?php else : ?>
						<?php foreach ( $cards as $cardsco_card ) : ?>
							<p>
								<label>
									<input type="radio" name="card_id" value="<?php echo esc_attr( $cardsco_card->ID ); ?>"
										<?php checked( (int) $campaign['card_id'], (int) $cardsco_card->ID ); ?> />
									<?php echo esc_html( $cardsco_card->post_title ); ?>
									<span class="description">
										<?php echo esc_html( $campaign['currency'] . ' ' . number_format( (float) CardsCo_Card::price( $cardsco_card->ID, $company_id ), 2 ) ); ?>
									</span>
								</label>
							</p>
						<?php endforeach; ?>
					<?php endif; ?>

				<?php elseif ( 4 === (int) $step ) : ?>

					<?php if ( ! empty( $messages ) ) : ?>
						<p>
							<label for="cardsco-tmpl"><strong><?php esc_html_e( 'Start from saved wording', 'cardsco' ); ?></strong></label><br />
							<select name="message_template_id" id="cardsco-tmpl">
								<option value="0"><?php esc_html_e( 'Write my own below', 'cardsco' ); ?></option>
								<?php foreach ( $messages as $cardsco_m ) : ?>
									<option value="<?php echo esc_attr( $cardsco_m->ID ); ?>" <?php selected( (int) $campaign['message_template_id'], (int) $cardsco_m->ID ); ?>>
										<?php echo esc_html( $cardsco_m->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>

					<p>
						<label for="cardsco-body"><strong><?php esc_html_e( 'The wording', 'cardsco' ); ?></strong></label><br />
						<textarea id="cardsco-body" name="message_body" rows="6" class="large-text"><?php echo esc_textarea( $campaign['message_body'] ); ?></textarea>
					</p>

					<p class="description"><strong><?php esc_html_e( 'You can drop these in:', 'cardsco' ); ?></strong></p>
					<ul>
						<?php foreach ( $fields as $cardsco_key => $cardsco_f ) : ?>
							<li>
								<code>{<?php echo esc_html( str_replace( '_', ' ', $cardsco_key ) ); ?>}</code>
								— <?php echo esc_html( $cardsco_f['note'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>

				<?php elseif ( 5 === (int) $step ) : ?>

					<p>
						<label>
							<input type="checkbox" name="offer_enabled" value="1" <?php checked( ! empty( $campaign['offer_enabled'] ) ); ?> />
							<strong><?php esc_html_e( 'Include an offer code on this card', 'cardsco' ); ?></strong>
						</label>
					</p>

					<p>
						<label for="cardsco-code"><?php esc_html_e( 'The code', 'cardsco' ); ?></label><br />
						<input type="text" id="cardsco-code" name="offer_code" class="regular-text" value="<?php echo esc_attr( $campaign['offer_code'] ); ?>" />
					</p>

					<p>
						<label for="cardsco-desc"><?php esc_html_e( 'What it gets them', 'cardsco' ); ?></label><br />
						<input type="text" id="cardsco-desc" name="offer_description" class="regular-text" value="<?php echo esc_attr( $campaign['offer_description'] ); ?>" placeholder="<?php esc_attr_e( '20% off the next order', 'cardsco' ); ?>" />
					</p>

					<p>
						<label for="cardsco-exp"><?php esc_html_e( 'Runs out on', 'cardsco' ); ?></label><br />
						<input type="date" id="cardsco-exp" name="offer_expires" value="<?php echo esc_attr( (string) $campaign['offer_expires'] ); ?>" />
					</p>

					<p class="description">
						<?php esc_html_e( 'If you use {offer code} or {offer expires} in the wording, switching the offer off here would leave a gap, and the card would be held back rather than sent with a blank.', 'cardsco' ); ?>
					</p>

				<?php elseif ( 6 === (int) $step ) : ?>

					<p>
						<label for="cardsco-send"><strong><?php esc_html_e( 'The day the cards should arrive', 'cardsco' ); ?></strong></label><br />
						<input type="date" id="cardsco-send" name="send_date" value="<?php echo esc_attr( (string) $campaign['send_date'] ); ?>" />
					</p>

					<p class="description">
						<?php
						printf(
							/* translators: %d: number of days. */
							esc_html__( 'Wording and artwork freeze %d days before that, because by then the cards are being written by hand.', 'cardsco' ),
							(int) CardsCo_Settings::get( 'campaign_lock_days', null, $company_id )
						);
						?>
					</p>

					<?php if ( $campaign['send_date'] ) : ?>
						<p>
							<strong><?php esc_html_e( 'Freezes on:', 'cardsco' ); ?></strong>
							<?php
							$cardsco_lock = CardsCo_Campaign::lock_date( $campaign['send_date'], $company_id );
							echo esc_html( $cardsco_lock ? gmdate( 'l j F Y', strtotime( $cardsco_lock ) ) : '—' );
							?>
						</p>
					<?php endif; ?>

				<?php else : ?>

					<?php if ( is_wp_error( $ready ) ) : ?>
						<div class="notice notice-warning inline">
							<p><strong><?php esc_html_e( 'Not quite ready:', 'cardsco' ); ?></strong></p>
							<ul style="margin:0 0 0 18px;list-style:disc">
								<?php foreach ( $ready->get_error_messages() as $cardsco_why ) : ?>
									<li><?php echo esc_html( $cardsco_why ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php $cardsco_offer_warn = CardsCo_Campaign::offer_warning( $campaign ); ?>
					<?php if ( $cardsco_offer_warn ) : ?>
						<div class="notice notice-warning inline">
							<p><?php echo esc_html( $cardsco_offer_warn ); ?></p>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $campaign['offer_enabled'] ) && $campaign['offer_code'] ) : ?>
						<h2><?php esc_html_e( 'The offer', 'cardsco' ); ?></h2>
						<p>
							<strong><?php echo esc_html( $campaign['offer_code'] ); ?></strong>
							<?php if ( $campaign['offer_description'] ) : ?>
								— <?php echo esc_html( $campaign['offer_description'] ); ?>
							<?php endif; ?>
							<?php if ( $campaign['offer_expires'] ) : ?>
								<br /><span class="description">
									<?php
									printf(
										/* translators: %s: expiry date. */
										esc_html__( 'Runs out on %s', 'cardsco' ),
										esc_html( gmdate( 'j F Y', strtotime( $campaign['offer_expires'] ) ) )
									);
									?>
								</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<h2><?php esc_html_e( 'How the card will read', 'cardsco' ); ?></h2>
					<div class="cardsco-write-message"><?php echo nl2br( esc_html( $preview['text'] ) ); ?></div>
					<?php if ( $preview['problem'] ) : ?>
						<p class="description" style="color:#d63638"><?php echo esc_html( $preview['problem'] ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Shown with sample details. Each card gets the real ones.', 'cardsco' ); ?></p>
					<?php endif; ?>

				<?php endif; ?>

				<div class="cardsco-wiz-foot">
					<?php if ( $step > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( $cardsco_step_url( $step - 1 ) ); ?>"><?php esc_html_e( 'Back', 'cardsco' ); ?></a>
					<?php else : ?>
						<span></span>
					<?php endif; ?>

					<?php if ( 7 === (int) $step ) : ?>
						<span></span>
					<?php elseif ( ! $cardsco_locked ) : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and carry on', 'cardsco' ); ?></button>
					<?php endif; ?>
				</div>
			</form>

			<?php if ( 7 === (int) $step && ! $cardsco_locked && ! $cardsco_live ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
					<input type="hidden" name="action" value="cardsco_campaign_schedule" />
					<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $cardsco_cid ); ?>" />
					<button type="submit" class="button button-primary button-hero" <?php disabled( is_wp_error( $ready ) ); ?>>
						<?php esc_html_e( 'Schedule it', 'cardsco' ); ?>
					</button>
				</form>
			<?php elseif ( 7 === (int) $step && $cardsco_live ) : ?>
				<p><strong><?php esc_html_e( 'This campaign is in the diary. You can still change it until it freezes.', 'cardsco' ); ?></strong></p>
			<?php endif; ?>
		</div>

		<aside class="cardsco-wiz-side">
			<div class="cardsco-side-row">
				<span class="cardsco-side-label"><?php esc_html_e( 'People', 'cardsco' ); ?></span>
				<span class="cardsco-side-value"><?php echo esc_html( number_format_i18n( (int) $campaign['recipient_count'] ) ); ?></span>
			</div>
			<?php if ( (int) $campaign['excluded_count'] ) : ?>
				<div class="cardsco-side-row">
					<span class="cardsco-side-label"><?php esc_html_e( 'Left out', 'cardsco' ); ?></span>
					<span class="cardsco-side-value"><?php echo esc_html( number_format_i18n( (int) $campaign['excluded_count'] ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="cardsco-side-row">
				<span class="cardsco-side-label"><?php esc_html_e( 'Arrives', 'cardsco' ); ?></span>
				<span class="cardsco-side-value">
					<?php echo esc_html( $campaign['send_date'] ? gmdate( 'j M Y', strtotime( $campaign['send_date'] ) ) : '—' ); ?>
				</span>
			</div>
			<div class="cardsco-side-row cardsco-side-price">
				<span class="cardsco-side-label"><?php esc_html_e( 'Cost', 'cardsco' ); ?></span>
				<span class="cardsco-side-value">
					<?php
					echo esc_html(
						$campaign['currency'] . ' ' . number_format( (float) $campaign['total_amount'], 2 )
						. ' (' . $campaign['currency'] . ' ' . number_format( (float) $campaign['unit_price'], 2 ) . ' each)'
					);
					?>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'Postage is added on top.', 'cardsco' ); ?></p>
		</aside>
	</div>
</div>
