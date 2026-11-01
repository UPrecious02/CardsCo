<?php
/**
 * Settings store.
 *
 * Settings resolve in three steps: the company's own value, then the platform
 * default stored against company 0, then the hard-coded default below. That
 * means CardsCo staff can change a platform-wide default without touching code
 * and without overwriting anything a company has set for itself.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Settings {

	/**
	 * In-request cache, keyed by company ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected static $cache = array();

	/**
	 * Hard-coded fallbacks.
	 *
	 * Anything marked provisional is waiting on a client decision and must stay
	 * editable rather than being hard-coded anywhere else in the plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			// Money. Bulk and campaign pricing is not finalised, so nothing
			// here is treated as final anywhere in the plugin.
			'currency'                 => 'GBP',
			'price_employee_birthday'  => '3.60',
			'price_default'            => '3.60',
			'bulk_mode'                => 'quote', // quote | checkout. Provisional.

			// Billing. The designs show invoice with 30 day terms; the signed
			// proposal assumed card checkout. Provisional until confirmed.
			// The approved designs settle this: a card is charged monthly,
			// before each box is written. Postage is added on top.
			'billing_mode'             => 'card', // invoice | card
			'invoice_terms_days'       => 30,
			'require_total_approval'   => 1,

			// Scheduling.
			'default_lead_days'        => 7, // Brief says 5, designs say 7. Provisional.
			'queue_horizon_days'       => 30,
			'postage_days'             => 2,
			// How long before a box must arrive that it has to be written and
			// packed. Boxes are built this far ahead of their arrive-by date.
			'box_production_days'      => 7,
			'campaign_lock_days'       => 3,

			// Content.
			'message_max_chars'        => 400,
			'allow_custom_artwork'     => 0, // Provisional.

			// Delivery.
			'default_country'          => 'GB',
			'shipping_countries'       => array( 'GB' ),

			// Data hygiene.
			'duplicate_detection'      => 1,
			'import_max_rows'          => 10000,
		);
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback, overriding the built-in default.
	 * @param int    $company_id Company, or 0 for the platform value.
	 * @return mixed
	 */
	public static function get( $key, $default = null, $company_id = 0 ) {
		$company_id = absint( $company_id );

		$company_values = $company_id ? self::all( $company_id ) : array();

		if ( array_key_exists( $key, $company_values ) ) {
			return $company_values[ $key ];
		}

		$platform_values = self::all( 0 );

		if ( array_key_exists( $key, $platform_values ) ) {
			return $platform_values[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		$defaults = self::defaults();

		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	}

	/**
	 * Write a setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $value Value. Arrays and objects are stored as JSON.
	 * @param int    $company_id Company, or 0 for the platform value.
	 * @return bool
	 */
	public static function set( $key, $value, $company_id = 0 ) {
		global $wpdb;

		$company_id = absint( $company_id );
		$key        = sanitize_key( $key );

		if ( '' === $key ) {
			return false;
		}

		$stored = ( is_array( $value ) || is_object( $value ) ) ? wp_json_encode( $value ) : (string) $value;

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . cardsco_table( 'settings' ) . ' (company_id, setting_key, setting_value, updated_at)
				 VALUES (%d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				$key,
				$stored,
				cardsco_now()
			)
		);

		unset( self::$cache[ $company_id ] );

		return false !== $result;
	}

	/**
	 * Every stored setting for a company.
	 *
	 * @param int $company_id Company, or 0 for platform values.
	 * @return array<string, mixed>
	 */
	public static function all( $company_id = 0 ) {
		global $wpdb;

		$company_id = absint( $company_id );

		if ( isset( self::$cache[ $company_id ] ) ) {
			return self::$cache[ $company_id ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT setting_key, setting_value FROM ' . cardsco_table( 'settings' ) . ' WHERE company_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id
			),
			ARRAY_A
		);

		$values = array();

		foreach ( (array) $rows as $row ) {
			$values[ $row['setting_key'] ] = self::decode( $row['setting_value'] );
		}

		self::$cache[ $company_id ] = $values;

		return $values;
	}

	/**
	 * Turn a stored value back into what was put in.
	 *
	 * Only arrays and objects were encoded as JSON, so only those are decoded.
	 * Running every value through json_decode would quietly retype the scalars:
	 * the price "3.60" would come back as the float 3.6 and print as "3.6" on
	 * an invoice, and "007" would become 7.
	 *
	 * @param string|null $stored Raw stored value.
	 * @return mixed
	 */
	protected static function decode( $stored ) {
		$stored = (string) $stored;
		$first  = substr( ltrim( $stored ), 0, 1 );

		if ( '[' !== $first && '{' !== $first ) {
			return $stored;
		}

		$decoded = json_decode( $stored, true );

		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $stored;
	}

	/**
	 * Remove a setting so it falls back to the platform or built-in value.
	 *
	 * @param string $key Setting key.
	 * @param int    $company_id Company.
	 * @return bool
	 */
	public static function delete( $key, $company_id = 0 ) {
		global $wpdb;

		$company_id = absint( $company_id );

		$result = $wpdb->delete(
			cardsco_table( 'settings' ),
			array(
				'company_id'  => $company_id,
				'setting_key' => sanitize_key( $key ),
			),
			array( '%d', '%s' )
		);

		unset( self::$cache[ $company_id ] );

		return (bool) $result;
	}

	/**
	 * Write the built-in defaults as platform values, without overwriting
	 * anything already stored. Called on activation.
	 *
	 * @return void
	 */
	public static function seed() {
		$existing = self::all( 0 );

		foreach ( self::defaults() as $key => $value ) {
			if ( ! array_key_exists( $key, $existing ) ) {
				self::set( $key, $value, 0 );
			}
		}
	}
}
