<?php
/**
 * Step 5 — where the cards get shipped.
 *
 * Measured from the step-5 frame: two 468-wide mode cards at y=213 with a
 * 16 gutter, then a 952-wide panel at y=312 holding three full-width 910
 * inputs on a 74 pitch and a row of three 295-wide ones.
 *
 * Both panels on this screen — the company's one address and each person's
 * own — draw the same fieldset, partial-address.php. They were written out
 * separately and drifted: the company was asked for three street lines, a
 * town, a county, a postcode and a country, while each person below it got
 * a two-row textarea and a postcode. The CEO's note was that an individual
 * shipping address should be as detailed as the company one, and the way to
 * keep it that way is for there to be one fieldset rather than two.
 *
 * @package CardsCo
 *
 * @var array $shipping  From CardsCo_Dashboard::setup_shipping().
 * @var array $programme
 * @var array $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="5" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<div class="cc-ship-modes">
		<?php foreach ( $shipping['modes'] as $cc_mode ) : ?>
			<label class="cc-ship-mode<?php echo $cc_mode['on'] ? ' is-on' : ''; ?>">
				<input type="radio" name="shipping_mode" value="<?php echo esc_attr( $cc_mode['key'] ); ?>" <?php checked( $cc_mode['on'] ); ?> />
				<span class="cc-ship-mode-icon" aria-hidden="true">
					<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), $cc_mode['icon'], 'cc-ship-mode-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</span>
				<span class="cc-ship-mode-text">
					<span class="cc-ship-mode-head"><?php echo esc_html( $cc_mode['head'] ); ?></span>
					<span class="cc-ship-mode-sub"><?php echo esc_html( $cc_mode['sub'] ); ?></span>
				</span>
				<span class="cc-ship-mode-dot" aria-hidden="true"></span>
			</label>
		<?php endforeach; ?>
	</div>

	<section class="cc-ship-panel<?php echo 'individual' === $shipping['mode'] ? ' is-off' : ''; ?>">
		<header class="cc-ship-head">
			<span class="cc-ship-pin" aria-hidden="true"></span>
			<span>
				<span class="cc-ship-title"><?php esc_html_e( 'Shipping address', 'cardsco' ); ?></span>
				<span class="cc-ship-source"><?php echo esc_html( $shipping['source'] ); ?></span>
			</span>
		</header>

		<?php
		$address = array(
			'id'       => 'cc-ship',
			'name'     => 'ship_line',
			'pc_name'  => 'ship_postcode',
			'cy_name'  => 'ship_country',
			'lines'    => $shipping['lines'],
			'postcode' => $shipping['postcode'],
			'country'  => $shipping['country'],
		);
		require CARDSCO_PATH . 'includes/front/partial-address.php';
		?>
	</section>

	<?php
	/*
	 * The other half of the question.
	 *
	 * Choosing individual addresses used to switch the company panel off
	 * and put nothing in its place, so the step asked where each card
	 * should go and then gave no way to say. Everyone on the occasion is
	 * listed here with whatever the list already holds — an upload that
	 * carried addresses fills these in and nobody retypes anything — and
	 * the rest are empty boxes with the name already against them.
	 *
	 * Inside the step's own form, so Continue saves these along with
	 * everything else and there is no separate thing to remember to press.
	 */
	$cc_missing = 0;

	foreach ( $shipping['people'] as $cc_one ) {
		if ( ! $cc_one['ready'] ) {
			$cc_missing++;
		}
	}
	?>
	<section class="cc-each<?php echo 'individual' === $shipping['mode'] ? '' : ' is-off'; ?>">
		<header class="cc-each-head">
			<span class="cc-each-title"><?php esc_html_e( 'Where each card goes', 'cardsco' ); ?></span>
			<span class="cc-each-count">
				<?php
				if ( ! $shipping['people'] ) {
					esc_html_e( 'Nobody on this occasion yet', 'cardsco' );
				} elseif ( $cc_missing ) {
					printf(
						/* translators: 1: how many are missing an address, 2: how many people in total. */
						esc_html( _n( '%1$s of %2$s still needs an address', '%1$s of %2$s still need an address', $cc_missing, 'cardsco' ) ),
						esc_html( number_format_i18n( $cc_missing ) ),
						esc_html( number_format_i18n( count( $shipping['people'] ) ) )
					);
				} else {
					esc_html_e( 'Everyone has an address', 'cardsco' );
				}
				?>
			</span>
		</header>

		<?php
		/*
		 * Twenty-five or more posted to individual addresses is neither of
		 * the two products — the volume of a bulk order with the postage
		 * of a single one — so it is arranged with CardsCo rather than
		 * priced here. Said at the moment they choose it, not discovered
		 * at the till after they have typed in thirty-four addresses.
		 */
		if ( CardsCo_Programme::needs_referral( $programme ) ) :
			$referral = CardsCo_Programme::referral( $programme );
			require CARDSCO_PATH . 'includes/front/partial-referral.php';
		endif;
		?>

		<?php if ( $shipping['people'] ) : ?>
			<ul class="cc-each-list">
				<?php foreach ( $shipping['people'] as $cc_one ) : ?>
					<li class="cc-each-one<?php echo $cc_one['ready'] ? '' : ' is-missing'; ?>">
						<p class="cc-each-who">
							<span class="cc-each-mark" aria-hidden="true"><?php echo esc_html( $cc_one['initials'] ); ?></span>
							<span class="cc-each-name"><?php echo esc_html( $cc_one['name'] ); ?></span>
							<?php if ( ! $cc_one['ready'] ) : ?>
								<span class="cc-each-flag"><?php esc_html_e( 'Needs an address', 'cardsco' ); ?></span>
							<?php endif; ?>
						</p>

						<div class="cc-each-fields">
							<?php
							/*
							 * The same fieldset the company panel above
							 * uses. This was a two-row textarea and a
							 * postcode while the company was asked for
							 * seven fields on the same screen.
							 */
							$address = array(
								'id'       => 'cc-each-' . (int) $cc_one['id'],
								'name'     => 'each_line[' . (int) $cc_one['id'] . ']',
								'pc_name'  => 'each_postcode[' . (int) $cc_one['id'] . ']',
								'cy_name'  => 'each_country[' . (int) $cc_one['id'] . ']',
								'lines'    => $cc_one['lines'],
								'postcode' => $cc_one['postcode'],
								'country'  => $cc_one['country'],
								'compact'  => true,
							);
							require CARDSCO_PATH . 'includes/front/partial-address.php';
							?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</form>

<?php
/*
 * Addresses by spreadsheet, for a list too long to type. Its own form,
 * because a file needs one — and the same importer as step 2, so a file
 * with names and addresses on it matches the people already here rather
 * than making second copies of them.
 */
?>
<section class="cc-each-up<?php echo 'individual' === $shipping['mode'] ? '' : ' is-off'; ?>">
	<p class="cc-each-up-head"><?php esc_html_e( 'Or upload the addresses', 'cardsco' ); ?></p>
	<p class="cc-each-up-sub">
		<?php esc_html_e( 'A CSV with first name, last name, address and postcode. Names that are already here are matched, not added again.', 'cardsco' ); ?>
	</p>
	<?php
	$upload = CardsCo_Dashboard::upload_row( wp_list_pluck( $shipping['people'], 'id' ) );
	require CARDSCO_PATH . 'includes/front/partial-upload.php';
	?>
</section>
