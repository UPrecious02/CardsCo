<?php
/**
 * Putting card designs in the shop.
 *
 * A card lives in this plugin, not in WooCommerce. It carries things a
 * product has no idea about — which occasions it suits, its reference, its
 * format and orientation — and the scheduler reads those every night to pick
 * a birthday card over a Christmas one. Keeping the design here means the
 * dashboard still works if WooCommerce is ever switched off.
 *
 * What this class does is mirror a card into a product so guests can buy one
 * from the catalogue without an account. One design, entered once, under
 * Cards; the product is a shadow of it.
 *
 * The mirror is deliberately one-way. Editing the product's title or price in
 * WooCommerce will be overwritten the next time the card is saved, and that
 * is the point: two editable copies of the same thing drift apart.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors sellable cards into WooCommerce products.
 */
class CardsCo_Catalogue {

	/**
	 * On the card: whether to offer it as a one-off purchase.
	 */
	const SELLABLE = '_cardsco_sellable';

	/**
	 * On the card: the product mirroring it.
	 */
	const PRODUCT = '_cardsco_product_id';

	/**
	 * On the product: the card it came from.
	 */
	const CARD = '_cardsco_card_id';

	/** Set once the one-per-line repair has been run. */
	const UNCAPPED = 'cardsco_products_uncapped';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'cardsco_catalogue', array( __CLASS__, 'screen' ) );
		add_shortcode( 'cardsco_collections', array( __CLASS__, 'collections_screen' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'save_post_' . CardsCo_Card::POST_TYPE, array( __CLASS__, 'sync' ), 20, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'withdraw' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'restore' ) );

		/*
		 * The one-per-line repair, run once and then never again.
		 *
		 * Deliberately not hung off the schema version. The first attempt
		 * was, and the edit that bumped that version silently failed, so
		 * the repair sat in the file complete and uncalled while the
		 * products stayed capped and the checkout kept charging for one
		 * card. A flag of its own cannot be undone by an edit elsewhere,
		 * and it costs one option read per request.
		 */
		add_action( 'woocommerce_init', array( __CLASS__, 'maybe_uncap' ) );
	}

	/**
	 * Run the one-per-line repair if it has not been run.
	 *
	 * @return void
	 */
	public static function maybe_uncap() {
		if ( get_option( self::UNCAPPED ) ) {
			return;
		}

		self::uncap_quantities();
		update_option( self::UNCAPPED, 1, true );
	}

	/**
	 * Whether WooCommerce is here to talk to.
	 *
	 * Everything below is a no-op without it, so the plugin runs perfectly
	 * well on a site with no shop.
	 *
	 * @return bool
	 */
	public static function shop_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether a card is meant to be on sale one at a time.
	 *
	 * Needs the tick and a published card. A price is not required: without
	 * one the standard price applies, same as everywhere else.
	 *
	 * @param int $card_id Card.
	 * @return bool
	 */
	public static function is_sellable( $card_id ) {
		return 'publish' === get_post_status( $card_id )
			&& (bool) get_post_meta( $card_id, self::SELLABLE, true );
	}

	/**
	 * Mirror a card into its product.
	 *
	 * @param int     $card_id Card.
	 * @param WP_Post $card    The card.
	 * @return int|WP_Error The product ID, 0 if there should not be one, or an error.
	 */
	public static function sync( $card_id, $card = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return 0;
		}

		$card = $card ? $card : get_post( $card_id );

		if ( ! $card || CardsCo_Card::POST_TYPE !== $card->post_type || 'auto-draft' === $card->post_status ) {
			return 0;
		}

		if ( ! self::shop_available() ) {
			return 0;
		}

		$product_id = (int) get_post_meta( $card_id, self::PRODUCT, true );
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		/*
		 * If somebody deleted the product in WooCommerce, wc_get_product()
		 * returns false, a fresh one is made below and the link is rewritten
		 * at the end — so a stale id needs no special handling.
		 *
		 * Two blocks that used to sit here, clearing the stale meta and
		 * resetting the id, were both removed: mutation checks showed
		 * deleting them changed nothing observable, which is the definition
		 * of dead code.
		 */

		if ( ! self::is_sellable( $card_id ) ) {
			// Unticked or unpublished. The product is hidden, never deleted:
			// it may appear on orders already placed, and deleting it would
			// leave those orders pointing at nothing.
			if ( $product ) {
				$product->set_status( 'draft' );
				$product->set_catalog_visibility( 'hidden' );
				$product->save();
			}

			return 0;
		}

		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		$price = CardsCo_Card::price( $card_id );

		$product->set_name( $card->post_title );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( false );
		/*
		 * NOT sold individually. WooCommerce reads that flag as a hard cap
		 * of one per line and silently clamps the quantity, so an occasion
		 * for forty people and a campaign to a hundred both reached the
		 * checkout as a single £3.60 card. The basket said one, the
		 * checkout charged for one, and nothing anywhere reported an
		 * error — the builder's own total was the only place the real
		 * figure appeared.
		 *
		 * It was set to stop somebody buying ten of the same card from the
		 * shop. That is now handled properly, by CardsCo_Shop refusing a
		 * card into the basket from anywhere but our own flows, which is
		 * the right place for it: a rule about who may add, not a cap on
		 * how many.
		 */
		$product->set_sold_individually( false );
		$product->set_regular_price( (string) $price );
		$product->set_short_description( (string) $card->post_excerpt );
		$product->set_description( (string) $card->post_content );

		$sku = (string) get_post_meta( $card_id, '_cardsco_code', true );

		if ( $sku ) {
			self::set_sku( $product, $sku );
		}

		$image = (int) get_post_thumbnail_id( $card_id );

		if ( $image ) {
			$product->set_image_id( $image );
		}

		$product_id = (int) $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'cardsco_product_failed', __( 'We could not put that card in the shop.', 'cardsco' ) );
		}

		update_post_meta( $product_id, self::CARD, (int) $card_id );
		update_post_meta( $card_id, self::PRODUCT, $product_id );

		return $product_id;
	}

	/**
	 * Give the product the card's reference, unless another product has it.
	 *
	 * A clashing SKU makes WooCommerce refuse the whole save, which would
	 * lose the rest of the card's details for the sake of a code.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $sku     Wanted SKU.
	 * @return void
	 */
	protected static function set_sku( $product, $sku ) {
		/*
		 * There was a wc_get_product_id_by_sku() check in front of this. It
		 * was redundant: WooCommerce throws on a clash anyway, and catching
		 * that covers every reason it might refuse a SKU rather than only
		 * the one we thought to look for. A mutation check could not tell
		 * the two apart, which is what gave it away.
		 */
		try {
			$product->set_sku( $sku );
		} catch ( Exception $e ) {
			// Go without a reference rather than lose the title and price.
			return;
		}
	}

	/**
	 * Hide the product when its card goes in the bin.
	 *
	 * @param int $post_id The trashed post.
	 * @return void
	 */
	public static function withdraw( $post_id ) {
		if ( ! self::shop_available() || CardsCo_Card::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$product_id = (int) get_post_meta( $post_id, self::PRODUCT, true );
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( $product ) {
			$product->set_status( 'draft' );
			$product->set_catalog_visibility( 'hidden' );
			$product->save();
		}
	}

	/**
	 * Put it back if the card comes out of the bin.
	 *
	 * @param int $post_id The restored post.
	 * @return void
	 */
	public static function restore( $post_id ) {
		if ( CardsCo_Card::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		self::sync( $post_id );
	}

	/**
	 * Make sure this one product is not capped, right now.
	 *
	 * The cap only matters at the moment something goes into the basket,
	 * so it is cleared at that moment, for the product in hand. That makes
	 * the quantity correct without depending on a migration having run, on
	 * when it ran, or on whether a flag saying it ran was telling the
	 * truth — a one-shot sweep marked itself done on a live site and left
	 * every product capped, and there was no way to tell from outside
	 * which of those had happened.
	 *
	 * Writes the meta directly as well as going through the API, so a
	 * cached product object cannot quietly swallow it.
	 *
	 * @param int $product_id The product about to be bought.
	 * @return void
	 */
	public static function ensure_uncapped( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_sold_individually() ) {
			return;
		}

		$product->set_sold_individually( false );
		$product->save();

		/* Belt and braces: WooCommerce stores this as yes/no. */
		update_post_meta( $product_id, '_sold_individually', 'no' );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Clear the one-per-line cap from products that already carry it.
	 *
	 * sync() only runs when a card is saved, so fixing the code fixes new
	 * products and leaves every existing one capped — and a capped product
	 * charges for one card however many were ordered. This runs once, on
	 * upgrade, over the products already there.
	 *
	 * @return int How many were changed.
	 */
	public static function uncap_quantities() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$products = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::CARD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'     => 'EXISTS',
				'suppress_filters' => false,
			)
		);

		$fixed = 0;

		foreach ( $products as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->is_sold_individually() ) {
				continue;
			}

			$product->set_sold_individually( false );
			$product->save();
			$fixed++;
		}

		return $fixed;
	}

	/**
	 * The card behind a product, if there is one.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public static function card_for_product( $product_id ) {
		return (int) get_post_meta( absint( $product_id ), self::CARD, true );
	}

	/**
	 * The product mirroring a card, if there is one.
	 *
	 * @param int $card_id Card.
	 * @return int
	 */
	public static function product_for_card( $card_id ) {
		return (int) get_post_meta( absint( $card_id ), self::PRODUCT, true );
	}

	/**
	 * Mirror every sellable card.
	 *
	 * For the day WooCommerce is first switched on, with cards already in
	 * the catalogue — including the one you added before any of this existed.
	 *
	 * @return array How many were put in the shop, hidden, or failed.
	 */
	public static function sync_all() {
		$done = array(
			'listed' => 0,
			'hidden' => 0,
			'failed' => 0,
		);

		if ( ! self::shop_available() ) {
			return $done;
		}

		$cards = get_posts(
			array(
				'post_type'      => CardsCo_Card::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $cards as $card_id ) {
			$result = self::sync( $card_id );

			if ( is_wp_error( $result ) ) {
				$done['failed']++;
			} elseif ( $result ) {
				$done['listed']++;
			} else {
				$done['hidden']++;
			}
		}

		return $done;
	}

	/* ---------------------------------------------------------------------
	 * The public catalogue
	 * ------------------------------------------------------------------ */

	/**
	 * How many cards a page of the catalogue shows before "Show more".
	 */
	const PER_PAGE = 12;

	/**
	 * Every card the public may browse.
	 *
	 * Sellable and published only: a design used solely inside company
	 * programmes has no business on a page where the next step is buying one.
	 *
	 * @return int[] Card IDs.
	 */
	public static function browsable() {
		$ids = get_posts(
			array(
				'post_type'      => CardsCo_Card::POST_TYPE,
				// get_posts() defaults to publish anyway, so this is stated
				// rather than needed. Keeping a draft card off a public page
				// should not rest on somebody remembering that default.
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::SELLABLE,
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * The filter row.
	 *
	 * One pill per collection, in the order the collections are declared.
	 *
	 * This used to be built from occasion types — about twenty of them, cut
	 * to the nine with the most cards so the row stayed on one line. The
	 * cards behind the other eleven could not be reached by browsing at all.
	 * Six collections fit, so nothing is hidden and nothing is capped, and
	 * the row matches what the set-up wizard offers.
	 *
	 * A collection with no cards is left out rather than shown empty.
	 *
	 * @param int[] $card_ids Cards in the catalogue.
	 * @return array
	 */
	public static function filters( array $card_ids ) {
		$counts = array();

		foreach ( $card_ids as $card_id ) {
			$slug = CardsCo_Card::category( (int) $card_id );

			if ( ! $slug ) {
				continue;
			}

			if ( ! isset( $counts[ $slug ] ) ) {
				$counts[ $slug ] = 0;
			}

			++$counts[ $slug ];
		}

		$out = array();

		foreach ( CardsCo_Category::all() as $slug => $category ) {
			if ( empty( $counts[ $slug ] ) ) {
				continue;
			}

			$out[] = array(
				'id'    => $slug,
				'label' => $category['label'],
				'count' => $counts[ $slug ],
			);
		}

		return $out;
	}

	/**
	 * The six collections, as the Collections screen needs them.
	 *
	 * Counts come from the browsable catalogue rather than from
	 * CardsCo_Category::cards(), which also returns programme-only designs a
	 * visitor cannot buy. The two would disagree, and the number beside a
	 * collection has to match what clicking it shows.
	 *
	 * The cover is the first card in the collection — the library's own
	 * artwork, so there is nothing extra to upload and a new collection is
	 * never a blank tile.
	 *
	 * Every collection is listed, including empty ones: the set is fixed and
	 * published, and a page that quietly drops two of six looks broken. An
	 * empty one is marked and does not link anywhere.
	 *
	 * @return array
	 */
	public static function collections() {
		$browsable = self::browsable();
		$grouped   = array();

		foreach ( $browsable as $card_id ) {
			$slug = CardsCo_Card::category( (int) $card_id );

			if ( ! $slug ) {
				continue;
			}

			$grouped[ $slug ][] = (int) $card_id;
		}

		$out = array();

		foreach ( CardsCo_Category::all() as $slug => $category ) {
			$ids   = $grouped[ $slug ] ?? array();
			$cover = $ids ? get_the_post_thumbnail_url( $ids[0], 'medium_large' ) : '';

			$out[] = array(
				'slug'        => (string) $slug,
				'label'       => $category['label'],
				'blurb'       => $category['blurb'],
				'format'      => $category['format'],
				'count'       => count( $ids ),
				'cover'       => $cover ? (string) $cover : '',
				'coming_soon' => ! empty( $category['coming_soon'] ),
			);
		}

		return $out;
	}

	/**
	 * One card, as the grid needs it.
	 *
	 * @param int $card_id Card.
	 * @return array
	 */
	public static function tile( $card_id ) {
		$card_id = absint( $card_id );
		$labels  = array();

		foreach ( CardsCo_Card::occasions( $card_id ) as $type_id ) {
			$type = CardsCo_Occasion_Type::get( (int) $type_id );

			if ( $type ) {
				$labels[] = $type['label'];
			}
		}

		$product = self::product_for_card( $card_id );

		$category = CardsCo_Card::category( $card_id );
		$category = $category ? CardsCo_Category::get( $category ) : null;

		return array(
			'id'        => $card_id,
			'title'     => get_the_title( $card_id ),
			'pattern'   => (string) get_post_meta( $card_id, '_cardsco_pattern', true ),
			// The design joins several occasions with a middle dot.
			'occasions' => implode( ' · ', $labels ),
			'image'     => get_the_post_thumbnail_url( $card_id, 'medium_large' ),
			'product'   => $product,
			'url'       => $product ? (string) get_permalink( $product ) : '',

			/*
			 * The rest is for the preview panel only. The tile itself shows
			 * none of it — the approved frame has a name, an artwork name and
			 * the occasions, and nothing else — but the panel opens from the
			 * tile without a round trip, so the data has to be on the page
			 * already.
			 *
			 * No price. The catalogue is a browse page and the frame carries
			 * no prices anywhere on it; the product page is where that lives.
			 */
			'image_lg'  => get_the_post_thumbnail_url( $card_id, 'large' ),
			'front'     => (string) get_post_meta( $card_id, '_cardsco_front', true ),
			'format'    => (string) get_post_meta( $card_id, '_cardsco_format', true ),
			'collection' => $category ? $category['label'] : '',
		);
	}

	/**
	 * The whole page's worth of data.
	 *
	 * @param array $args 'filter' (collection slug), 'sort', 'show'.
	 * @return array
	 */
	public static function page( array $args = array() ) {
		$all     = self::browsable();
		$filters = self::filters( $all );
		$filter  = isset( $args['filter'] ) ? sanitize_key( (string) $args['filter'] ) : '';
		$sort    = isset( $args['sort'] ) ? sanitize_key( $args['sort'] ) : 'popular';
		$show    = isset( $args['show'] ) ? absint( $args['show'] ) : self::PER_PAGE;
		$show    = max( self::PER_PAGE, $show );

		// A slug that is not a collection is treated as no filter, so a
		// mistyped or stale link shows the whole catalogue rather than
		// nothing at all.
		if ( $filter && ! CardsCo_Category::get( $filter ) ) {
			$filter = '';
		}

		$ids = $all;

		if ( $filter ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $card_id ) use ( $filter ) {
						return CardsCo_Card::category( (int) $card_id ) === $filter;
					}
				)
			);
		}

		if ( 'newest' === $sort ) {
			$ids = array_reverse( $ids );
		} elseif ( 'name' === $sort ) {
			usort(
				$ids,
				static function ( $a, $b ) {
					return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
				}
			);
		}

		$total = count( $ids );
		$shown = array_slice( $ids, 0, $show );

		return array(
			'filters'  => $filters,
			'filter'   => $filter,
			'sort'     => $sort,
			'total'    => $total,
			'all'      => count( $all ),
			'tiles'    => array_map( array( __CLASS__, 'tile' ), $shown ),
			'shown'    => count( $shown ),
			'more'     => $total > count( $shown ),
			'next'     => count( $shown ) + self::PER_PAGE,
		);
	}

	/**
	 * How the sort control is labelled.
	 *
	 * @return array
	 */
	public static function sorts() {
		return array(
			'popular' => __( 'Most popular', 'cardsco' ),
			'newest'  => __( 'Newest first', 'cardsco' ),
			'name'    => __( 'Name A to Z', 'cardsco' ),
		);
	}

	/**
	 * Styles, on a page that uses the shortcode.
	 *
	 * Enqueued again from the shortcode itself, for the same reason the
	 * dashboard does it: a page builder keeps its content out of
	 * post_content, and a screen that rendered has to be styled.
	 *
	 * @return void
	 */
	public static function assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( $post && cardsco_page_uses_shortcode( $post, array( 'cardsco_catalogue', 'cardsco_collections' ) ) ) {
			self::enqueue_catalogue();
		}
	}

	/**
	 * Load the catalogue's styles.
	 *
	 * @return void
	 */
	public static function enqueue_catalogue() {
		wp_enqueue_style(
			'cardsco-fonts-cat',
			'https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500&family=Inter:wght@400;500;600&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'cardsco-catalogue', CARDSCO_URL . 'assets/catalogue.css', array( 'cardsco-fonts-cat' ), CARDSCO_VERSION );
		wp_enqueue_script( 'cardsco-preview', CARDSCO_URL . 'assets/preview.js', array(), CARDSCO_VERSION, true );
	}

	/**
	 * [cardsco_catalogue]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function screen( $atts ) {
		$atts = shortcode_atts(
			array(
				'use'     => '',
				'per_page' => '',
			),
			$atts,
			'cardsco_catalogue'
		);

		self::enqueue_catalogue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public listing read from the query string.
		$page = self::page(
			array(
				'filter' => isset( $_GET['cc_filter'] ) ? sanitize_key( wp_unslash( $_GET['cc_filter'] ) ) : '',
				'sort'   => isset( $_GET['cc_sort'] ) ? sanitize_key( wp_unslash( $_GET['cc_sort'] ) ) : 'popular',
				'show'   => isset( $_GET['cc_show'] ) ? absint( wp_unslash( $_GET['cc_show'] ) ) : 0,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$links = $atts;
		$path  = CARDSCO_PATH . 'includes/front/view-catalogue.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * [cardsco_collections]
	 *
	 * The front door to the catalogue: six tiles, each landing on the
	 * catalogue with that collection already selected.
	 *
	 * @param array $atts Shortcode attributes. 'catalogue' is the URL of the
	 *                    page holding [cardsco_catalogue].
	 * @return string
	 */
	public static function collections_screen( $atts ) {
		$atts = shortcode_atts(
			array(
				'catalogue' => '',
				'heading'   => '',
				'lede'      => '',
			),
			$atts,
			'cardsco_collections'
		);

		self::enqueue_catalogue();

		$collections = self::collections();
		$links       = $atts;
		$path        = CARDSCO_PATH . 'includes/front/view-collections.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}
}
