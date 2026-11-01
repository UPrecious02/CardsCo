<?php
/**
 * The sheet somebody actually writes from.
 *
 * Deliberately plain: one card per block, big enough to read at arm's length,
 * and it prints without the WordPress chrome. Nothing on this page can be
 * clicked by mistake, because the person using it has a pen in one hand.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var array         $box
 * @var array[]       $cards
 * @var array|null    $programme
 * @var array|null    $company
 */

defined( 'ABSPATH' ) || exit;

$month = $box['period'] ? gmdate( 'F Y', strtotime( $box['period'] . '-01' ) ) : '';
?>
<div class="wrap cardsco-write">
	<div class="cardsco-write-head">
		<h1>
			<?php
			printf(
				/* translators: 1: company name, 2: month. */
				esc_html__( '%1$s — %2$s', 'cardsco' ),
				esc_html( $company['name'] ?? '' ),
				esc_html( $month )
			);
			?>
		</h1>
		<p>
			<?php
			printf(
				/* translators: 1: number of cards, 2: handwriting style, 3: ink colour. */
				esc_html__( '%1$d cards · %2$s · %3$s ink', 'cardsco' ),
				count( $cards ),
				esc_html( $programme['handwriting'] ?? '—' ),
				esc_html( $programme['ink'] ?? '—' )
			);
			?>
		</p>
		<p class="cardsco-write-hide">
			<button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e( 'Print', 'cardsco' ); ?></button>
			<a class="button" href="<?php echo esc_url( CardsCo_Fulfilment::url( array( 'box' => (int) $box['id'] ) ) ); ?>"><?php esc_html_e( 'Back to the box', 'cardsco' ); ?></a>
		</p>
	</div>

	<?php if ( empty( $cards ) ) : ?>
		<p><?php esc_html_e( 'Nothing to write in this box.', 'cardsco' ); ?></p>
	<?php else : ?>
		<?php foreach ( $cards as $i => $card ) : ?>
			<div class="cardsco-write-card">
				<div class="cardsco-write-no"><?php echo esc_html( $i + 1 ); ?></div>
				<div class="cardsco-write-body">
					<p class="cardsco-write-who">
						<strong><?php echo esc_html( $card['recipient_name'] ); ?></strong>
						<?php if ( $card['event_date'] ) : ?>
							<span><?php echo esc_html( gmdate( 'j F', strtotime( $card['event_date'] ) ) ); ?></span>
						<?php endif; ?>
					</p>
					<p class="cardsco-write-card-name"><?php echo esc_html( $card['card_title'] ); ?></p>
					<div class="cardsco-write-message"><?php echo nl2br( esc_html( $card['message_final'] ) ); ?></div>
					<?php if ( ! empty( $card['offer_code'] ) ) : ?>
						<p class="cardsco-write-offer">
							<?php esc_html_e( 'Offer code:', 'cardsco' ); ?>
							<strong><?php echo esc_html( $card['offer_code'] ); ?></strong>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $programme['signature'] ) ) : ?>
						<p class="cardsco-write-sign"><?php echo esc_html( $programme['signature'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
