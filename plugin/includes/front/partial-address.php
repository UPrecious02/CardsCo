<?php
/**
 * One address, asked for in parts.
 *
 * The only address fieldset in the portal. Step 5 drew two of them by
 * hand — the company's and each person's — and they drifted: the company
 * was asked for three street lines, a town, a county, a postcode and a
 * country, and each person was asked for a two-row textarea and a
 * postcode, on the same screen, one above the other. An individual
 * address has to be as postable as a company one, so there is now one
 * fieldset and both panels include it.
 *
 * @package CardsCo
 *
 * @var array $address Fields to draw:
 *   id       string  Prefix for element ids, unique on the page.
 *   name     string  Field name for the parts, e.g. 'ship_line' or
 *                    'each_line[12]'. Rendered as name[] so the parts
 *                    arrive in ADDRESS_PARTS order.
 *   pc_name  string  Field name for the postcode.
 *   cy_name  string  Field name for the country.
 *   lines    array   The parts, from CardsCo_Dashboard::address_lines().
 *   postcode string
 *   country  string  Two-letter code.
 *   compact  bool    Optional. Stack the lines tighter, for a long list
 *                    of people rather than one company panel.
 */

defined( 'ABSPATH' ) || exit;

$cc_a = array_merge(
	array(
		'id'       => 'cc-addr',
		'name'     => 'address_line',
		'pc_name'  => 'postcode',
		'cy_name'  => 'country',
		'lines'    => array( '', '', '', '', '' ),
		'postcode' => '',
		'country'  => 'GB',
		'compact'  => false,
	),
	$address
);

/*
 * Padded to the right length, and NOT normalised.
 *
 * This used to run the parts through address_block() and back, to be
 * tidy about it. address_block() closes gaps — that is its job, so an
 * envelope has no blank line in the middle — so a stored address of
 *   22 Mill Lane / (blank) / (blank) / York / (blank)
 * came back as "22 Mill Lane\nYork" and split into line 1 and line 2,
 * putting the town in the second street line and leaving Town/city
 * empty. Every part would have crept up a box each time the page was
 * opened. The parts arrive in their slots and stay in them.
 */
$cc_a['lines'] = array_map(
	'strval',
	array_slice(
		array_pad( array_values( (array) $cc_a['lines'] ), CardsCo_Dashboard::ADDRESS_PARTS, '' ),
		0,
		CardsCo_Dashboard::ADDRESS_PARTS
	)
);

$cc_labels  = CardsCo_Dashboard::address_labels();
$cc_street  = array_slice( $cc_labels, 0, 3 );
$cc_wrap    = $cc_a['compact'] ? 'cc-addr is-compact' : 'cc-addr';
?>
<div class="<?php echo esc_attr( $cc_wrap ); ?>">
	<?php foreach ( $cc_street as $cc_i => $cc_label ) : ?>
		<p class="cc-field">
			<label for="<?php echo esc_attr( $cc_a['id'] . '-' . $cc_i ); ?>"><?php echo esc_html( $cc_label ); ?></label>
			<input type="text"
				id="<?php echo esc_attr( $cc_a['id'] . '-' . $cc_i ); ?>"
				name="<?php echo esc_attr( $cc_a['name'] ); ?>[]"
				value="<?php echo esc_attr( $cc_a['lines'][ $cc_i ] ); ?>"
				autocomplete="<?php echo 0 === $cc_i ? 'address-line1' : ( 1 === $cc_i ? 'address-line2' : 'address-line3' ); ?>"
				<?php echo 2 === $cc_i ? 'placeholder="' . esc_attr__( 'Area or estate', 'cardsco' ) . '"' : ''; ?> />
		</p>
	<?php endforeach; ?>

	<div class="cc-addr-row">
		<p class="cc-field">
			<label for="<?php echo esc_attr( $cc_a['id'] . '-pc' ); ?>"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
			<input type="text"
				id="<?php echo esc_attr( $cc_a['id'] . '-pc' ); ?>"
				name="<?php echo esc_attr( $cc_a['pc_name'] ); ?>"
				value="<?php echo esc_attr( $cc_a['postcode'] ); ?>"
				autocomplete="postal-code" />
		</p>
		<p class="cc-field">
			<label for="<?php echo esc_attr( $cc_a['id'] . '-town' ); ?>"><?php echo esc_html( $cc_labels[3] ); ?></label>
			<input type="text"
				id="<?php echo esc_attr( $cc_a['id'] . '-town' ); ?>"
				name="<?php echo esc_attr( $cc_a['name'] ); ?>[]"
				value="<?php echo esc_attr( $cc_a['lines'][3] ); ?>"
				autocomplete="address-level2" />
		</p>
		<p class="cc-field">
			<label for="<?php echo esc_attr( $cc_a['id'] . '-county' ); ?>"><?php echo esc_html( $cc_labels[4] ); ?></label>
			<input type="text"
				id="<?php echo esc_attr( $cc_a['id'] . '-county' ); ?>"
				name="<?php echo esc_attr( $cc_a['name'] ); ?>[]"
				value="<?php echo esc_attr( $cc_a['lines'][4] ); ?>"
				autocomplete="address-level1" />
		</p>
		<p class="cc-field">
			<label for="<?php echo esc_attr( $cc_a['id'] . '-cy' ); ?>"><?php esc_html_e( 'Country', 'cardsco' ); ?></label>
			<span class="cc-select">
				<select id="<?php echo esc_attr( $cc_a['id'] . '-cy' ); ?>" name="<?php echo esc_attr( $cc_a['cy_name'] ); ?>" autocomplete="country">
					<?php foreach ( CardsCo_Validator::countries() as $cc_code => $cc_name ) : ?>
						<option value="<?php echo esc_attr( $cc_code ); ?>" <?php selected( $cc_a['country'], $cc_code ); ?>>
							<?php echo esc_html( $cc_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'chevron-down', 'cc-select-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</span>
		</p>
	</div>
</div>
