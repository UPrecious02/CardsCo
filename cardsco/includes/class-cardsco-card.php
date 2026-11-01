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
		$types       = CardsCo_Occasion_Type::all( 0, array( 'active_only' => false ) );
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
				<input type="text" id="cardsco-code" name="cardsco_code" value="<?php echo esc_attr( $code ); ?>" />
				<p class="description"><?php esc_html_e( 'Your own or the printer\'s code for this design.', 'cardsco' ); ?></p>
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

			<div class="full">
				<strong><?php esc_html_e( 'Suits these occasions', 'cardsco' ); ?></strong>
				<p class="description" style="margin-top:2px">
					<?php esc_html_e( 'Tick none and the card is offered for every occasion.', 'cardsco' ); ?>
				</p>
				<div class="cardsco-occ">
					<?php foreach ( $types as $type ) : ?>
						<label>
							<input type="checkbox" name="cardsco_occasions[]" value="<?php echo esc_attr( $type['id'] ); ?>" <?php checked( in_array( (int) $type['id'], $selected, true ) ); ?> />
							<?php echo esc_html( $type['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

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
		update_post_meta( $post_id, '_cardsco_format', sanitize_text_field( wp_unslash( $_POST['cardsco_format'] ?? '' ) ) );

		$orientation = sanitize_key( $_POST['cardsco_orientation'] ?? 'portrait' );
		update_post_meta( $post_id, '_cardsco_orientation', in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : 'portrait' );

		update_post_meta( $post_id, '_cardsco_price', self::clean_price( wp_unslash( $_POST['cardsco_price'] ?? '' ) ) );

		if ( '' === get_post_meta( $post_id, self::COMPANY, true ) ) {
			// 0 means the shared catalogue. Per-company artwork is not a thing
			// yet, but storing it now means it never needs a migration.
			update_post_meta( $post_id, self::COMPANY, 0 );
		}

		self::set_occasions( $post_id, (array) ( $_POST['cardsco_occasions'] ?? array() ) );
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
				$out['cardsco_code']      = __( 'Reference', 'cardsco' );
				$out['cardsco_occasions'] = __( 'Occasions', 'cardsco' );
				$out['cardsco_price']     = __( 'Price', 'cardsco' );
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
