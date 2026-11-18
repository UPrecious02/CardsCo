<?php
/**
 * A stand-in for the small slice of WooCommerce the catalogue sync touches.
 *
 * WooCommerce cannot be installed in this container — downloads.wordpress.org
 * is not reachable — so this exists to exercise OUR logic: when a product is
 * made, when it is hidden rather than deleted, that the two records stay
 * linked, and that a clashing reference does not lose the save.
 *
 * What it does NOT prove is that real WooCommerce accepts these calls. The
 * method names and behaviour here are written from its documented API, and
 * the integration still has to be checked once on a site that has it. That
 * limit is the reason this file says so out loud rather than quietly
 * pretending to be the real thing.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooCommerce' ) ) {
	/** Presence of this class is how the plugin decides a shop exists. */
	class WooCommerce {}
}

if ( ! class_exists( 'WC_Product_Simple' ) ) {
	/**
	 * Backed by a real post so the meta written against it is real too.
	 */
	class WC_Product_Simple {

		/** @var int */
		protected $id = 0;

		/** @var array */
		protected $data = array(
			'name'               => '',
			'status'             => 'publish',
			'catalog_visibility' => 'visible',
			'virtual'            => false,
			'sold_individually'  => false,
			'regular_price'      => '',
			'short_description'  => '',
			'description'        => '',
			'sku'                => '',
			'image_id'           => 0,
		);

		/**
		 * @param int $id Existing product.
		 */
		public function __construct( $id = 0 ) {
			$this->id = (int) $id;

			if ( $this->id ) {
				$post = get_post( $this->id );

				if ( $post ) {
					$this->data['name']   = $post->post_title;
					$this->data['status'] = $post->post_status;

					foreach ( array( 'catalog_visibility', 'regular_price', 'sku', 'image_id', 'sold_individually' ) as $k ) {
						$v = get_post_meta( $this->id, '_' . $k, true );

						if ( '' !== $v ) {
							$this->data[ $k ] = $v;
						}
					}
				}
			}
		}

		public function get_id() { return $this->id; }
		public function set_name( $v ) { $this->data['name'] = $v; }
		public function set_status( $v ) { $this->data['status'] = $v; }
		public function set_catalog_visibility( $v ) { $this->data['catalog_visibility'] = $v; }
		public function set_virtual( $v ) { $this->data['virtual'] = $v; }
		public function set_sold_individually( $v ) { $this->data['sold_individually'] = $v; }
		public function set_regular_price( $v ) { $this->data['regular_price'] = $v; }
		public function set_short_description( $v ) { $this->data['short_description'] = $v; }
		public function set_description( $v ) { $this->data['description'] = $v; }
		public function set_image_id( $v ) { $this->data['image_id'] = $v; }
		public function get_status() { return $this->data['status']; }
		public function get_catalog_visibility() { return $this->data['catalog_visibility']; }
		public function get_regular_price() { return $this->data['regular_price']; }
		public function get_name() { return $this->data['name']; }
		public function get_sku() { return $this->data['sku']; }
		public function get_image_id() { return (int) $this->data['image_id']; }

		/**
		 * Real WooCommerce throws on a duplicate SKU, which is the behaviour
		 * the sync has to survive.
		 *
		 * @param string $v SKU.
		 * @throws Exception When another product already has it.
		 */
		public function set_sku( $v ) {
			$taken = wc_get_product_id_by_sku( $v );

			if ( $taken && (int) $taken !== $this->id ) {
				throw new Exception( 'Duplicate SKU' );
			}

			$this->data['sku'] = $v;
		}

		/**
		 * @return int
		 */
		public function save() {
			$args = array(
				'post_type'    => 'product',
				'post_title'   => $this->data['name'],
				'post_status'  => $this->data['status'],
				'post_content' => $this->data['description'],
				'post_excerpt' => $this->data['short_description'],
			);

			if ( $this->id ) {
				$args['ID'] = $this->id;
				wp_update_post( $args );
			} else {
				$this->id = (int) wp_insert_post( $args );
			}

			foreach ( array( 'catalog_visibility', 'regular_price', 'sku', 'image_id', 'sold_individually' ) as $k ) {
				update_post_meta( $this->id, '_' . $k, $this->data[ $k ] );
			}

			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int $id Product.
	 * @return WC_Product_Simple|false
	 */
	function wc_get_product( $id ) {
		$post = get_post( (int) $id );

		if ( ! $post || 'product' !== $post->post_type || 'trash' === $post->post_status ) {
			return false;
		}

		return new WC_Product_Simple( (int) $id );
	}
}

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	/**
	 * @param string $sku SKU.
	 * @return int
	 */
	function wc_get_product_id_by_sku( $sku ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
				 WHERE m.meta_value = %s AND p.post_type = 'product' AND p.post_status <> 'trash'
				 LIMIT 1",
				$sku
			)
		);
	}
}
