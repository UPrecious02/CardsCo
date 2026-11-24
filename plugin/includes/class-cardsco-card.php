<?php
/**
 * Card catalogue.
 *
 * A post type rather than a custom table: the catalogue holds tens of cards,
 * not tens of thousands, and it wants an image library and an editing screen,
 * both of which WordPress already does well.
 *
 * Which occasions a card suits is stored as one meta row per occasion rather
 * than a serialised list, so the campaign builder can query it properly.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Card {

	const POST_TYPE = 'cardsco_card';
	const OCCASION  = '_cardsco_occasion';
	const CATEGORY  = '_cardsco_category';
	const COMPANY   = '_cardsco_company_id';
	const NONCE     = 'cardsco_card_meta';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'tidy_meta_boxes' ), 999 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 *
	 * Not public: there is no front-end archive of cards. The dashboard and
	 * the campaign builder read it through PHP, so no rewrite rules are
	 * needed and none are created.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Cards', 'cardsco' ),
					'singular_name'      => __( 'Card', 'cardsco' ),
					'add_new'            => __( 'Add card', 'cardsco' ),
					'add_new_item'       => __( 'Add card', 'cardsco' ),
					'edit_item'          => __( 'Edit card', 'cardsco' ),
					'new_item'           => __( 'New card', 'cardsco' ),
					'view_item'          => __( 'View card', 'cardsco' ),
					'search_items'       => __( 'Search cards', 'cardsco' ),
					'not_found'          => __( 'No cards yet.', 'cardsco' ),
					'not_found_in_trash' => __( 'No cards in the bin.', 'cardsco' ),
					'all_items'          => __( 'Cards', 'cardsco' ),
					'menu_name'          => __( 'Cards', 'cardsco' ),
				),
				'public'          => false,
				'show_ui'         => true,
				// Attached to the CardsCo menu by hand in CardsCo_Admin::menu();
				// see the note there for why show_in_menu cannot do it.
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'thumbnail', 'excerpt', 'page-attributes' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'map_meta_cap'    => true,
				'capability_type' => array( 'cardsco_card', 'cardsco_cards' ),
				'capabilities'    => self::capabilities(),
			)
		);
	}

	/**
	 * Every capability mapped onto the single catalogue permission, so there
	 * is one thing to grant rather than a dozen.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities() {
		$cap = 'cardsco_manage_catalogue';

		/*
		 * Only the primitive capabilities are listed.
		 *
		 * The meta capabilities (edit_post, read_post, delete_post) are
		 * deliberately left alone. Naming one of them after a primitive
		 * capability registers that name as a meta capability alias, and a
		 * meta capability needs a post ID to answer. The capability then
		 * evaluates to "do_not_allow" whenever it is checked on its own,
		 * which is exactly how add_submenu_page checks it, so the menu item
		 * silently disappears while the capability looks correct everywhere
		 * you would think to look.
		 *
		 * With map_meta_cap on, WordPress resolves the meta capabilities
		 * through the primitives below, which is what we want anyway.
		 */
		return array(
			'create_posts'           => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_private_posts'   => $cap,
			'delete_published_posts' => $cap,
			'delete_others_posts'    => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
		);
	}

	/**
	 * Add the details box.
	 *
	 * @return void
	 */
	public static function meta_boxes() {
		add_meta_box(
			'cardsco-card-details',
			__( 'Card details', 'cardsco' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Take the page builder's panel off the card and message screens.
	 *
	 * Oxygen adds itself to every post type, but these two are never rendered
	 * as pages, so the panel is noise on a screen that should be quick to
	 * fill in. Matched by prefix rather than by one exact id so an update to
	 * the builder cannot quietly put it back.
	 *
	 * @return void
	 */
	public static function tidy_meta_boxes() {
		global $wp_meta_boxes;

		$screens = array( self::POST_TYPE, CardsCo_Message::POST_TYPE );

		foreach ( $screens as $screen ) {
			if ( empty( $wp_meta_boxes[ $screen ] ) || ! is_array( $wp_meta_boxes[ $screen ] ) ) {
				continue;
			}

			foreach ( $wp_meta_boxes[ $screen ] as $context => $priorities ) {
				if ( ! is_array( $priorities ) ) {
					continue;
				}

				foreach ( $priorities as $priority => $boxes ) {
					if ( ! is_array( $boxes ) ) {
						continue;
					}

					foreach ( array_keys( $boxes ) as $id ) {
						if ( preg_match( '/^(ct_|oxygen|oxy_)/i', (string) $id ) ) {
							unset( $wp_meta_boxes[ $screen ][ $context ][ $priority ][ $id ] );
						}
					}
				}
			}
		}
	}

	/**
	 * Details box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$code        = get_post_meta( $post->ID, '_cardsco_code', true );
		$price       = get_post_meta( $post->ID, '_cardsco_price', true );
		$orientation = get_post_meta( $post->ID, '_cardsco_orientation', true );
		$format      = get_post_meta( $post->ID, '_cardsco_format', true );
		$selected    = self::occasions( $post->ID );
		$currency    = CardsCo_Settings::get( 'currency' );
		$default     = CardsCo_Settings::get( 'price_default' );
		?>
		<style>
			.cardsco-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
			.cardsco-meta-grid .full{grid-column:1/-1}
			.cardsco-meta-grid input[type=text],.cardsco-meta-grid select{width:100%}
			.cardsco-occ{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
			.cardsco-occ label{display:flex;gap:6px;align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:4px 10px}
			@media(max-width:782px){.cardsco-meta-grid{grid-template-columns:1fr}}
		</style>

		<div class="cardsco-meta-grid">
			<div>
				<label for="cardsco-code"><strong><?php esc_html_e( 'Reference', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-code" name="cardsco_code" value="<?php echo esc_attr( $code ); ?>"
					placeholder="<?php esc_attr_e( '10a', 'cardsco' ); ?>" />
				<p class="description"><?php esc_html_e( 'The file code, e.g. 10a. Used for printing, never shown to customers.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-front"><strong><?php esc_html_e( 'Words on the front', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-front" name="cardsco_front"
					value="<?php echo esc_attr( get_post_meta( $post->ID, '_cardsco_front', true ) ); ?>"
					placeholder="<?php esc_attr_e( 'Happy Birthday! Our favourite photo of the year', 'cardsco' ); ?>" />
				<p class="description"><?php esc_html_e( 'Printed on the front of the card. The part in quotes on the card list.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-pattern"><?php esc_html_e( 'Artwork name', 'cardsco' ); ?></label>
				<input type="text" id="cardsco-pattern" name="cardsco_pattern"
					value="<?php echo esc_attr( get_post_meta( $post->ID, '_cardsco_pattern', true ) ); ?>"
					placeholder="<?php esc_attr_e( 'Confetti', 'cardsco' ); ?>" />
				<p class="description"><?php esc_html_e( 'Optional. Shown to customers beside the card name in the catalogue.', 'cardsco' ); ?></p>
			</div>

			<div>
				<label for="cardsco-price"><strong>
					<?php
					printf(
						/* translators: %s: currency code. */
						esc_html__( 'Price (%s)', 'cardsco' ),
						esc_html( $currency )
					);
					?>
				</strong></label>
				<input type="text" id="cardsco-price" name="cardsco_price" value="<?php echo esc_attr( $price ); ?>" inputmode="decimal" />
				<p class="description">
					<?php
					printf(
						/* translators: %s: the default price. */
						esc_html__( 'Leave blank to use the standard price of %s.', 'cardsco' ),
						esc_html( $default )
					);
					?>
				</p>
			</div>

			<div>
				<label for="cardsco-orientation"><strong><?php esc_html_e( 'Orientation', 'cardsco' ); ?></strong></label>
				<select id="cardsco-orientation" name="cardsco_orientation">
					<option value="portrait" <?php selected( $orientation, 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'cardsco' ); ?></option>
					<option value="landscape" <?php selected( $orientation, 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'cardsco' ); ?></option>
				</select>
			</div>

			<div>
				<label for="cardsco-format"><strong><?php esc_html_e( 'Size', 'cardsco' ); ?></strong></label>
				<input type="text" id="cardsco-format" name="cardsco_format" value="<?php echo esc_attr( $format ); ?>" placeholder="<?php esc_attr_e( 'A5 folded', 'cardsco' ); ?>" />
			</div>

			<?php if ( CardsCo_Catalogue::shop_available() ) : ?>
				<div class="full" style="padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px">
					<label style="display:flex;gap:8px;align-items:flex-start">
						<input type="checkbox" name="cardsco_sellable" value="1"
							<?php checked( (bool) get_post_meta( $post->ID, CardsCo_Catalogue::SELLABLE, true ) ); ?> />
						<span>
							<strong><?php esc_html_e( 'Sell this one on its own in the catalogue', 'cardsco' ); ?></strong>
							<span class="description" style="display:block">
								<?php esc_html_e( 'Creates a WooCommerce product from this card so somebody can buy a single copy without an account. Leave unticked for designs only used in company programmes.', 'cardsco' ); ?>
							</span>
							<?php $cc_product = CardsCo_Catalogue::product_for_card( $post->ID ); ?>
							<?php if ( $cc_product ) : ?>
								<span class="description" style="display:block;margin-top:4px">
									<?php
									printf(
										/* translators: %s: link to the product. */
										esc_html__( 'In the shop as %s. The title, price and image come from this page — editing them there will be overwritten.', 'cardsco' ),
										'<a href="' . esc_url( (string) get_edit_post_link( $cc_product ) ) . '">' . esc_html__( 'this product', 'cardsco' ) . '</a>'
									);
									?>
								</span>
							<?php endif; ?>
						</span>
					</label>
				</div>
			<?php endif; ?>

			<?php
			$cc_chosen     = self::category( $post->ID );
			$cc_categories = CardsCo_Category::all();
			?>
			<div class="full">
				<label for="cardsco-category"><strong><?php esc_html_e( 'Collection', 'cardsco' ); ?></strong></label>
				<select id="cardsco-category" name="cardsco_category" style="max-width:420px">
					<option value=""><?php esc_html_e( '— Choose a collection —', 'cardsco' ); ?></option>
					<?php foreach ( $cc_categories as $cc_slug => $cc_cat ) : ?>
						<option value="<?php echo esc_attr( $cc_slug ); ?>" <?php selected( $cc_chosen, $cc_slug ); ?>>
							<?php echo esc_html( $cc_cat['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description" style="margin-top:4px">
					<?php esc_html_e( 'Which of the six collections this card belongs to. This is what customers browse by, and what the set-up wizard offers.', 'cardsco' ); ?>
				</p>
			</div>

			<?php
			/*
			 * The narrowing question, and only where it earns its place.
			 *
			 * Four of the six collections cover a single occasion, or none at
			 * all, so there is nothing to ask: choosing the collection has
			 * already said everything. Christmas & Seasonal covers eleven and
			 * Customer Milestones covers two, and there the distinction
			 * matters — a Diwali card must not go out at Christmas. Each
			 * block is shown only when its own collection is selected.
			 */
			foreach ( $cc_categories as $cc_slug => $cc_cat ) :
				$cc_types = CardsCo_Category::types( $cc_slug );

				if ( count( $cc_types ) < 2 ) {
					continue;
				}
				?>
				<div class="full cardsco-narrow" data-for="<?php echo esc_attr( $cc_slug ); ?>" <?php echo $cc_chosen === $cc_slug ? '' : 'hidden'; ?>>
					<strong>
						<?php
						printf(
							/* translators: %s: collection name. */
							esc_html__( 'Which occasions in %s?', 'cardsco' ),
							esc_html( $cc_cat['label'] )
						);
						?>
					</strong>
					<p class="description" style="margin-top:2px">
						<?php esc_html_e( 'Tick none and the card is offered for every occasion in this collection.', 'cardsco' ); ?>
					</p>
					<div class="cardsco-occ">
						<?php foreach ( $cc_types as $cc_type ) : ?>
							<label>
								<input type="checkbox" name="cardsco_occasions[]" value="<?php echo esc_attr( $cc_type['id'] ); ?>" <?php checked( in_array( (int) $cc_type['id'], $selected, true ) ); ?> />
								<?php echo esc_html( $cc_type['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<script>
		( function () {
			var pick = document.getElementById( 'cardsco-category' );
			if ( ! pick ) { return; }
			var blocks = document.querySelectorAll( '.cardsco-narrow' );
			function sync() {
				blocks.forEach( function ( block ) {
					var mine = block.getAttribute( 'data-for' ) === pick.value;
					block.hidden = ! mine;
					/* A hidden block's ticks must not be submitted: they
					   belong to a collection the card is no longer in. */
					block.querySelectorAll( 'input[type=checkbox]' ).forEach( function ( box ) {
						box.disabled = ! mine;
					} );
				} );
			}
			pick.addEventListener( 'change', sync );
			sync();
		}() );
		</script>

		<p class="description" style="margin-top:14px">
			<?php esc_html_e( 'The featured image is the front of the card. Published cards are offered when building a campaign; drafts are not.', 'cardsco' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the details box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'cardsco_manage_catalogue' ) ) {
			return;
		}

		update_post_meta( $post_id, '_cardsco_code', sanitize_text_field( wp_unslash( $_POST['cardsco_code'] ?? '' ) ) );
		update_post_meta( $post_id, '_cardsco_pattern', sanitize_text_field( wp_unslash( $_POST['cardsco_pattern'] ?? '' ) ) );
		update_post_meta( $post_id, '_cardsco_front', sanitize_text_field( wp_unslash( $_POST['cardsco_front'] ?? '' ) ) );
		update_post_meta( $post_id, '_cardsco_format', sanitize_text_field( wp_unslash( $_POST['cardsco_format'] ?? '' ) ) );

		$orientation = sanitize_key( $_POST['cardsco_orientation'] ?? 'portrait' );
		update_post_meta( $post_id, '_cardsco_orientation', in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : 'portrait' );

		update_post_meta( $post_id, '_cardsco_price', self::clean_price( wp_unslash( $_POST['cardsco_price'] ?? '' ) ) );

		// Only written when the box was on the screen, so saving a card from
		// a context without the shop does not silently unlist it.
		if ( CardsCo_Catalogue::shop_available() ) {
			update_post_meta( $post_id, CardsCo_Catalogue::SELLABLE, empty( $_POST['cardsco_sellable'] ) ? 0 : 1 );
		}

		if ( '' === get_post_meta( $post_id, self::COMPANY, true ) ) {
			// 0 means the shared catalogue. Per-company artwork is not a thing
			// yet, but storing it now means it never needs a migration.
			update_post_meta( $post_id, self::COMPANY, 0 );
		}

		$category = sanitize_key( wp_unslash( $_POST['cardsco_category'] ?? '' ) );
		self::set_category( $post_id, $category );

		/*
		 * Occasions are kept inside the chosen collection. The editor already
		 * disables the boxes belonging to other collections, but a disabled
		 * input is a convenience for the person, not a guarantee to the
		 * server, so the filtering is done again here.
		 */
		$ticked = array_map( 'absint', (array) ( $_POST['cardsco_occasions'] ?? array() ) );

		if ( $category ) {
			$allowed = array_map( 'intval', (array) wp_list_pluck( CardsCo_Category::types( $category ), 'id' ) );
			$ticked  = $allowed ? array_values( array_intersect( $ticked, $allowed ) ) : array();
		}

		self::set_occasions( $post_id, $ticked );
	}

	/**
	 * Tidy a typed price into a plain decimal, or an empty string.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function clean_price( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Strip currency symbols and thousands separators people paste in.
		$value = preg_replace( '/[^0-9.,\-]/', '', $value );
		$value = str_replace( ',', '', (string) $value );

		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			return '';
		}

		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Occasion type IDs a card suits.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function occasions( $post_id ) {
		return array_map( 'intval', (array) get_post_meta( $post_id, self::OCCASION, false ) );
	}

	/**
	 * The collection a card belongs to.
	 *
	 * The stored value wins. Where there is none — any card saved before the
	 * field existed — it is worked out from the occasions ticked on the card,
	 * so nothing has to be re-entered by hand. The derivation is also what
	 * the upgrade routine writes into the field.
	 *
	 * @param int $post_id Post ID.
	 * @return string Category slug, or '' when it cannot be worked out.
	 */
	public static function category( $post_id ) {
		$stored = sanitize_key( (string) get_post_meta( $post_id, self::CATEGORY, true ) );

		if ( $stored && CardsCo_Category::get( $stored ) ) {
			return $stored;
		}

		return self::derive_category( $post_id );
	}

	/**
	 * Work out a card's collection from the occasions ticked on it.
	 *
	 * First match wins, in the order the categories are declared. A card can
	 * only sit in one collection, and in practice the occasions on a card do
	 * not straddle two — a Christmas design is not also a work anniversary.
	 *
	 * @param int $post_id Post ID.
	 * @return string Category slug, or '' when the card has no occasions.
	 */
	public static function derive_category( $post_id ) {
		$set = self::occasions( $post_id );

		if ( ! $set ) {
			return '';
		}

		foreach ( CardsCo_Category::all() as $slug => $category ) {
			$ids = wp_list_pluck( CardsCo_Category::types( $slug ), 'id' );
			$ids = array_map( 'intval', (array) $ids );

			if ( array_intersect( $set, $ids ) ) {
				return (string) $slug;
			}
		}

		return '';
	}

	/**
	 * Put a card in a collection.
	 *
	 * An unknown slug clears the field rather than storing rubbish, which
	 * leaves category() free to fall back to the derivation.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Category slug.
	 * @return void
	 */
	public static function set_category( $post_id, $slug ) {
		$slug = sanitize_key( (string) $slug );

		if ( ! $slug || ! CardsCo_Category::get( $slug ) ) {
			delete_post_meta( $post_id, self::CATEGORY );
			return;
		}

		update_post_meta( $post_id, self::CATEGORY, $slug );
	}

	/** Option that records the one-time category backfill. */
	const BACKFILL_OPTION = 'cardsco_categories_backfilled';

	/**
	 * Write a collection onto every card that predates the field.
	 *
	 * Cards used to carry only occasion ticks, and the collection was worked
	 * out from them on the fly. Storing it makes the catalogue a single meta
	 * query instead of a scan, and gives the person somewhere to correct it.
	 *
	 * Runs once. A card whose occasions are empty cannot be placed and is
	 * left alone: it shows as "Not set" on the cards list so it can be
	 * assigned by hand, which is better than guessing and better than the old
	 * behaviour, where an untagged card quietly appeared in every collection.
	 *
	 * @param bool $force Run even if it has run before.
	 * @return int Number of cards given a collection.
	 */
	public static function backfill_categories( $force = false ) {
		if ( ! $force && get_option( self::BACKFILL_OPTION ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 500,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::CATEGORY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$done = 0;

		foreach ( (array) $ids as $id ) {
			$slug = self::derive_category( (int) $id );

			if ( ! $slug ) {
				continue;
			}

			self::set_category( (int) $id, $slug );
			++$done;
		}

		update_option( self::BACKFILL_OPTION, 1, false );

		return $done;
	}

	/**
	 * Replace the occasions a card suits.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $type_ids Occasion type IDs.
	 * @return void
	 */
	public static function set_occasions( $post_id, array $type_ids ) {
		delete_post_meta( $post_id, self::OCCASION );

		$seen = array();

		foreach ( $type_ids as $type_id ) {
			$type_id = absint( $type_id );

			if ( ! $type_id || isset( $seen[ $type_id ] ) ) {
				continue;
			}

			if ( ! CardsCo_Occasion_Type::get( $type_id ) ) {
				continue;
			}

			$seen[ $type_id ] = true;
			add_post_meta( $post_id, self::OCCASION, $type_id );
		}
	}

	/**
	 * Cards available for an occasion.
	 *
	 * A card with no occasions ticked suits everything, which is how a plain
	 * design gets offered without having to tick twenty five boxes.
	 *
	 * @param int $type_id Occasion type ID, or 0 for all cards.
	 * @param int $company_id Company ID.
	 * @return WP_Post[]
	 */
	public static function for_occasion( $type_id = 0, $company_id = 0 ) {
		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 200,
			'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'suppress_filters' => false,
			'no_found_rows'    => true,
			'meta_query'       => array( self::company_clause( $company_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);

		$type_id = absint( $type_id );

		if ( $type_id ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'   => self::OCCASION,
					'value' => $type_id,
				),
				array(
					'key'     => self::OCCASION,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Meta clause limiting results to the shared catalogue plus a company's
	 * own items.
	 *
	 * @param int $company_id Company ID.
	 * @return array
	 */
	public static function company_clause( $company_id ) {
		$clause = array(
			'relation' => 'OR',
			array(
				'key'   => self::COMPANY,
				'value' => 0,
			),
			array(
				'key'     => self::COMPANY,
				'compare' => 'NOT EXISTS',
			),
		);

		$company_id = absint( $company_id );

		if ( $company_id ) {
			$clause[] = array(
				'key'   => self::COMPANY,
				'value' => $company_id,
			);
		}

		return $clause;
	}

	/**
	 * What a card costs, falling back to the standard price.
	 *
	 * @param int $post_id Post ID.
	 * @param int $company_id Company ID.
	 * @return string Decimal string.
	 */
	public static function price( $post_id, $company_id = 0 ) {
		$price = get_post_meta( $post_id, '_cardsco_price', true );

		if ( '' !== $price && null !== $price ) {
			return (string) $price;
		}

		return (string) CardsCo_Settings::get( 'price_default', null, $company_id );
	}

	/**
	 * What this many cards costs altogether.
	 *
	 * The bulk rate applies to the whole order once it reaches the limit,
	 * not only to the cards past it. With the limit at twenty-five, an
	 * order of twenty-five is 25 x £1.99, and so is every card in an order
	 * of thirty. This is what the published terms promise — "£1.99 per
	 * card, for orders of 25 cards or more" — and the terms are what a
	 * customer is agreeing to at the checkout.
	 *
	 * It was tiered before, charging the first twenty-five at the standard
	 * price and only the cards after that at the bulk rate. That is a
	 * different promise and a materially different bill: thirty cards came
	 * to £99.95 rather than £59.70.
	 *
	 * This leaves a cliff at the limit, and the cliff is deliberate.
	 * Twenty-four cards cost £86.40 and twenty-five cost £49.75, so anyone
	 * ordering just under the limit is better off adding cards to reach it.
	 * That is how a volume discount of this shape behaves; it is not a bug
	 * to be smoothed away, and smoothing it would break the terms.
	 *
	 * A card with its own price keeps it, at every quantity. A price set
	 * on the card itself is somebody overriding the list deliberately, and
	 * a quantity should not quietly undo that.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $quantity   How many are being bought together.
	 * @param int $company_id Company, for its own settings.
	 * @return string Amount to two decimal places.
	 */
	public static function price_total( $post_id, $quantity, $company_id = 0 ) {
		$quantity = max( 0, (int) $quantity );
		$money    = static function ( $amount ) {
			return number_format( (float) $amount, 2, '.', '' );
		};

		if ( ! $quantity ) {
			return $money( 0 );
		}

		$own = get_post_meta( $post_id, '_cardsco_price', true );

		if ( '' !== $own && null !== $own ) {
			return $money( (float) $own * $quantity );
		}

		$standard = (float) self::price( $post_id, $company_id );
		$from     = (int) CardsCo_Settings::get( 'bulk_from', null, $company_id );
		$bulk     = (float) CardsCo_Settings::get( 'price_bulk', null, $company_id );

		/*
		 * A bulk rate that is not actually cheaper is somebody's mistake in
		 * the settings, and charging more for ordering more would be worse
		 * than ignoring it.
		 */
		if ( $from < 1 || $quantity < $from || $bulk >= $standard ) {
			return $money( $standard * $quantity );
		}

		return $money( $bulk * $quantity );
	}

	/**
	 * What one card works out at when buying this many.
	 *
	 * A blended figure once the order crosses the limit, because the cards
	 * in it no longer all cost the same. The total is what is true; this is
	 * for the one price-per-item field a basket line has room for, and is
	 * deliberately left unrounded so that multiplying it back by the
	 * quantity lands on the total to the penny.
	 *
	 * @param int $post_id    Post ID.
	 * @param int $quantity   How many are being bought together.
	 * @param int $company_id Company, for its own settings.
	 * @return float
	 */
	public static function price_for( $post_id, $quantity, $company_id = 0 ) {
		$quantity = max( 1, (int) $quantity );

		return (float) self::price_total( $post_id, $quantity, $company_id ) / $quantity;
	}

	/**
	 * Whether this many cards gets the bulk rate.
	 *
	 * True at the limit as well as past it: with the limit at twenty-five,
	 * an order of exactly twenty-five is a bulk order. It used to be true
	 * only past the limit, which put the break on the twenty-sixth card
	 * while the terms said twenty-five.
	 *
	 * @param int $quantity   How many.
	 * @param int $company_id Company, for its own settings.
	 * @return bool
	 */
	public static function is_bulk( $quantity, $company_id = 0 ) {
		$from = (int) CardsCo_Settings::get( 'bulk_from', null, $company_id );

		return $from > 0 && (int) $quantity >= $from;
	}

	/**
	 * A card boiled down to what the rest of the plugin needs.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function summary( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return array(
			'id'          => (int) $post->ID,
			'title'       => $post->post_title,
			'code'        => (string) get_post_meta( $post->ID, '_cardsco_code', true ),
			'pattern'     => (string) get_post_meta( $post->ID, '_cardsco_pattern', true ),
			'front'       => (string) get_post_meta( $post->ID, '_cardsco_front', true ),
			'price'       => self::price( $post->ID ),
			'orientation' => (string) get_post_meta( $post->ID, '_cardsco_orientation', true ),
			'format'      => (string) get_post_meta( $post->ID, '_cardsco_format', true ),
			'image'       => get_the_post_thumbnail_url( $post->ID, 'medium' ),
			'occasions'   => self::occasions( $post->ID ),
			'available'   => 'publish' === $post->post_status,
		);
	}

	/* ---------------------------------------------------------------------
	 * List table
	 * ------------------------------------------------------------------ */

	/**
	 * Columns on the cards list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$out['cardsco_thumb'] = __( 'Front', 'cardsco' );
			}

			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['cardsco_code']       = __( 'Reference', 'cardsco' );
				$out['cardsco_collection'] = __( 'Collection', 'cardsco' );
				$out['cardsco_occasions']  = __( 'Occasions', 'cardsco' );
				$out['cardsco_price']      = __( 'Price', 'cardsco' );
			}
		}

		return $out;
	}

	/**
	 * Cell contents.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'cardsco_thumb':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 60, 60 ), array( 'style' => 'border-radius:3px;object-fit:cover' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo '<span style="color:#d63638">' . esc_html__( 'No image', 'cardsco' ) . '</span>';
				}
				break;

			case 'cardsco_code':
				$code = get_post_meta( $post_id, '_cardsco_code', true );
				echo $code ? esc_html( $code ) : '&mdash;';
				break;

			case 'cardsco_collection':
				$slug     = self::category( $post_id );
				$category = $slug ? CardsCo_Category::get( $slug ) : null;

				if ( $category ) {
					echo esc_html( $category['label'] );

					/* Worked out from the ticks rather than chosen, so the
					   field is still empty and a re-save would settle it. */
					if ( ! get_post_meta( $post_id, self::CATEGORY, true ) ) {
						echo ' <span style="color:#996800" title="' . esc_attr__( 'Worked out from the occasions ticked. Open the card and save it to set this properly.', 'cardsco' ) . '">' . esc_html__( '(assumed)', 'cardsco' ) . '</span>';
					}
				} else {
					echo '<span style="color:#d63638">' . esc_html__( 'Not set', 'cardsco' ) . '</span>';
				}
				break;

			case 'cardsco_occasions':
				$labels = array();

				foreach ( self::occasions( $post_id ) as $type_id ) {
					$type = CardsCo_Occasion_Type::get( $type_id );

					if ( $type ) {
						$labels[] = $type['label'];
					}
				}

				echo $labels
					? esc_html( implode( ', ', $labels ) )
					: '<em>' . esc_html__( 'Any occasion', 'cardsco' ) . '</em>';
				break;

			case 'cardsco_price':
				echo esc_html( CardsCo_Settings::get( 'currency' ) . ' ' . self::price( $post_id ) );
				break;
		}
	}
}
