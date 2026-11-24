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
	 * What every setting is, declared in one place.
	 *
	 * Until now the defaults were a flat list and anything could be written
	 * under any key, which was fine while only code called set(). It stops
	 * being fine the moment a settings form posts to it: a crafted post could
	 * write whatever it liked. So each setting now declares its type, its
	 * default and who is allowed to change it, set() validates against that,
	 * and an unknown key is refused.
	 *
	 * It also gives the Account Settings screen something to render from, so
	 * that screen is a view over this rather than a second list to keep in
	 * step — and it puts logo_url, support_email and the rest on the record
	 * instead of leaving them as strings invented at the point of use.
	 *
	 * scope:
	 *   company  — a company can set this for itself
	 *   platform — CardsCo staff only; a company inherits it
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema() {
		$schema = array(

			/* ---- Branding. Used by the auth screens and the sidebar. ---- */
			'logo_url'        => array(
				'label' => __( 'Logo', 'cardsco' ),
				'group' => 'branding',
				'type'  => 'url',
				'scope' => 'company',
				'help'  => __( 'Leave empty to use the CardsCo logo.', 'cardsco' ),
			),
			'logo_alt'        => array(
				'label'   => __( 'Logo description', 'cardsco' ),
				'group'   => 'branding',
				'type'    => 'text',
				'scope'   => 'company',
				'help'    => __( 'Read aloud in place of the logo by a screen reader.', 'cardsco' ),
			),
			'marketing_url'   => array(
				'label' => __( 'Where the logo links to', 'cardsco' ),
				'group' => 'branding',
				'type'  => 'url',
				'scope' => 'company',
			),
			'support_email'   => array(
				'label'   => __( 'Support email', 'cardsco' ),
				'group'   => 'branding',
				'type'    => 'email',
				'scope'   => 'platform',
				'default' => 'hello@cardsco.uk',
			),
			'invite_url'      => array(
				'label' => __( 'Invitation page', 'cardsco' ),
				'group' => 'branding',
				'type'  => 'url',
				'scope' => 'platform',
				'help'  => __( 'The page carrying [cardsco_accept_invite]. Defaults to /join/.', 'cardsco' ),
			),
			'dashboard_url'   => array(
				'label' => __( 'Dashboard address', 'cardsco' ),
				'group' => 'branding',
				'type'  => 'url',
				'scope' => 'platform',
				'help'  => __( 'Where people land after signing in.', 'cardsco' ),
			),

			/*
			 * ---- The company's own profile. ----
			 *
			 * Its name, billing address and logo already live on the company
			 * row. These are the rest of what the Settings frame asks for,
			 * kept here rather than added as columns: they are configuration,
			 * they are per-company, and this layer already validates and
			 * scopes that for us.
			 */
			'trading_name'    => array(
				'label' => __( 'Trading name', 'cardsco' ),
				'group' => 'company',
				'type'  => 'text',
				'scope' => 'company',
				'help'  => __( 'Optional.', 'cardsco' ),
			),
			'company_number'  => array(
				'label' => __( 'Company number', 'cardsco' ),
				'group' => 'company',
				'type'  => 'text',
				'scope' => 'company',
				'help'  => __( 'Companies House', 'cardsco' ),
			),
			'vat_number'      => array(
				'label' => __( 'VAT number', 'cardsco' ),
				'group' => 'company',
				'type'  => 'text',
				'scope' => 'company',
				'help'  => __( 'Shown on every invoice', 'cardsco' ),
			),
			'return_mode'     => array(
				'label'   => __( 'Return address on envelopes', 'cardsco' ),
				'group'   => 'company',
				'type'    => 'choice',
				'scope'   => 'company',
				'default' => 'billing',
				'choices' => array(
					'billing' => __( 'Same as billing address', 'cardsco' ),
					'other'   => __( 'Use a different return address', 'cardsco' ),
					'none'    => __( 'No return address', 'cardsco' ),
				),
			),
			'return_address'  => array(
				'label' => __( 'Return address', 'cardsco' ),
				'group' => 'company',
				'type'  => 'textarea',
				'scope' => 'company',
			),
			'profile_saved_by' => array(
				'label' => __( 'Who last saved the profile', 'cardsco' ),
				'group' => 'company',
				'type'  => 'text',
				'scope' => 'company',
			),
			'card_signoff'    => array(
				'label' => __( 'Default sign-off', 'cardsco' ),
				'group' => 'company',
				'type'  => 'text',
				'scope' => 'company',
				'help'  => __( 'You can change it on any campaign.', 'cardsco' ),
			),

			/* ---- The cards themselves. ---- */
			/*
			 * How much writing fits on a card. A physical limit, not a product
			 * one: the frames put it at 400 characters (and the card writer
			 * says "up to 80 words fit", which agrees). It lives here rather
			 * than in the code because the true number belongs to whoever
			 * holds the cards, and changing it must not need a release.
			 */
			'card_max_chars'      => array(
				'label'   => __( 'Longest message a card holds', 'cardsco' ),
				'group'   => 'cards',
				'type'    => 'int',
				'scope'   => 'platform',
				'default' => 400,
				'min'     => 20,
				'max'     => 2000,
				'help'    => __( 'Counted after names are filled in, so the longest name still fits.', 'cardsco' ),
			),

			/* ---- Signing in. ---- */
			'login_max_attempts'  => array( 'label' => __( 'Wrong passwords before a lockout', 'cardsco' ), 'group' => 'security', 'type' => 'int', 'scope' => 'platform', 'default' => 5, 'min' => 3, 'max' => 20 ),
			'login_lockout_mins'  => array( 'label' => __( 'How long a lockout lasts (minutes)', 'cardsco' ), 'group' => 'security', 'type' => 'int', 'scope' => 'platform', 'default' => 15, 'min' => 1, 'max' => 1440 ),
			'login_max_lockouts'  => array(
				'label' => __( 'Lockouts before we stop offering the timer', 'cardsco' ),
				'group' => 'security',
				'type'  => 'int',
				'scope' => 'platform',
				'default' => 3,
				'min'   => 1,
				'max'   => 10,
				'help'  => __( 'After this many, waiting no longer helps — they reset their password or get in touch.', 'cardsco' ),
			),
			'verify_required'     => array(
				'label' => __( 'Confirm the email address before sending or paying', 'cardsco' ),
				'group' => 'security',
				'type'  => 'bool',
				'scope' => 'platform',
				'default' => 1,
				'help'  => __( 'People can sign up and look around either way; this gates turning a programme on and paying.', 'cardsco' ),
			),

			/* ---- Money. Provisional until the client confirms. ---- */
			'currency'                => array( 'label' => __( 'Currency', 'cardsco' ), 'group' => 'money', 'type' => 'choice', 'scope' => 'platform', 'default' => 'GBP', 'choices' => array( 'GBP', 'EUR', 'USD' ) ),
			'price_employee_birthday' => array( 'label' => __( 'Birthday card price', 'cardsco' ), 'group' => 'money', 'type' => 'decimal', 'scope' => 'platform', 'default' => '3.60' ),
			'price_default'           => array( 'label' => __( 'Default card price', 'cardsco' ), 'group' => 'money', 'type' => 'decimal', 'scope' => 'platform', 'default' => '3.60' ),
			'bulk_mode'               => array( 'label' => __( 'Bulk orders', 'cardsco' ), 'group' => 'money', 'type' => 'choice', 'scope' => 'platform', 'default' => 'quote', 'choices' => array( 'quote', 'checkout' ) ),
			'price_bulk'              => array( 'label' => __( 'Bulk card price', 'cardsco' ), 'group' => 'money', 'type' => 'decimal', 'scope' => 'platform', 'default' => '1.99' ),
			// The label describes the rule: the bulk price applies to every
			// card in an order of this many or more, not only to the ones
			// past it. It read "Standard price covers the first" while the
			// pricing was tiered, which is the opposite of what it now does.
			'bulk_from'               => array( 'label' => __( 'Bulk price applies from', 'cardsco' ), 'group' => 'money', 'type' => 'int', 'scope' => 'platform', 'default' => 25, 'min' => 2, 'max' => 10000 ),

			/* ---- Billing. ---- */
			'billing_mode'           => array( 'label' => __( 'How companies pay', 'cardsco' ), 'group' => 'billing', 'type' => 'choice', 'scope' => 'platform', 'default' => 'card', 'choices' => array( 'card', 'invoice' ) ),
			'invoice_terms_days'     => array( 'label' => __( 'Invoice terms (days)', 'cardsco' ), 'group' => 'billing', 'type' => 'int', 'scope' => 'platform', 'default' => 30, 'min' => 0, 'max' => 180 ),
			'require_total_approval' => array( 'label' => __( 'Approve each total before it is charged', 'cardsco' ), 'group' => 'billing', 'type' => 'bool', 'scope' => 'company', 'default' => 1 ),

			/* ---- Scheduling. ---- */
			'default_lead_days'   => array( 'label' => __( 'Days to write a card before the date', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'company', 'default' => 7, 'min' => 1, 'max' => 60 ),
			'queue_horizon_days'  => array( 'label' => __( 'How far ahead the scan looks', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'platform', 'default' => 30, 'min' => 1, 'max' => 365 ),
			'postage_days'        => array( 'label' => __( 'Days allowed for postage', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'platform', 'default' => 2, 'min' => 0, 'max' => 30 ),
			'single_card_days'    => array( 'label' => __( 'Working days to write a single card', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'platform', 'default' => 3, 'min' => 1, 'max' => 30, 'help' => __( 'What a one-off card bought on the site is promised in. The writing deadline is counted this many working days from the order.', 'cardsco' ) ),
			'box_production_days' => array( 'label' => __( 'Days to make up a box', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'platform', 'default' => 7, 'min' => 1, 'max' => 60 ),
			'campaign_lock_days'  => array( 'label' => __( 'Days before a campaign locks', 'cardsco' ), 'group' => 'scheduling', 'type' => 'int', 'scope' => 'platform', 'default' => 3, 'min' => 0, 'max' => 60 ),

			'guest_max_people'  => array( 'label' => __( 'People per guest order', 'cardsco' ), 'group' => 'billing', 'type' => 'int', 'scope' => 'platform', 'default' => 10, 'min' => 1, 'max' => 100, 'help' => __( 'How many separately addressed people somebody can send to without an account. Past this they are asked to create one. A batch posted to a single address is not limited.', 'cardsco' ) ),

			/* ---- Renewal. ---- */
			'renewal_notice_1'  => array( 'label' => __( 'First renewal reminder, weeks before', 'cardsco' ), 'group' => 'billing', 'type' => 'int', 'scope' => 'platform', 'default' => 4, 'min' => 1, 'max' => 26, 'help' => __( 'A recurring occasion is paid for a year at a time. These are the three reminders sent before the next year is due. Set any of them to the same number to send fewer.', 'cardsco' ) ),
			'renewal_notice_2'  => array( 'label' => __( 'Second renewal reminder, weeks before', 'cardsco' ), 'group' => 'billing', 'type' => 'int', 'scope' => 'platform', 'default' => 2, 'min' => 1, 'max' => 26 ),
			'renewal_notice_3'  => array( 'label' => __( 'Last renewal reminder, weeks before', 'cardsco' ), 'group' => 'billing', 'type' => 'int', 'scope' => 'platform', 'default' => 1, 'min' => 1, 'max' => 26 ),

			/* ---- Content. ---- */
			'message_max_chars'    => array( 'label' => __( 'Longest message', 'cardsco' ), 'group' => 'content', 'type' => 'int', 'scope' => 'platform', 'default' => 400, 'min' => 40, 'max' => 2000 ),
			'allow_custom_artwork' => array( 'label' => __( 'Allow their own artwork', 'cardsco' ), 'group' => 'content', 'type' => 'bool', 'scope' => 'platform', 'default' => 0 ),

			/* ---- Delivery. ---- */
			'default_country'     => array( 'label' => __( 'Default country', 'cardsco' ), 'group' => 'delivery', 'type' => 'text', 'scope' => 'company', 'default' => 'GB' ),
			'shipping_countries'  => array( 'label' => __( 'Countries we post to', 'cardsco' ), 'group' => 'delivery', 'type' => 'list', 'scope' => 'platform', 'default' => array( 'GB' ) ),

			/* ---- Data hygiene. ---- */
			'duplicate_detection' => array( 'label' => __( 'Warn about duplicate people', 'cardsco' ), 'group' => 'data', 'type' => 'bool', 'scope' => 'company', 'default' => 1 ),
			'import_max_rows'     => array( 'label' => __( 'Largest import', 'cardsco' ), 'group' => 'data', 'type' => 'int', 'scope' => 'platform', 'default' => 10000, 'min' => 1, 'max' => 200000 ),
		);

		/**
		 * Settings this plugin knows about.
		 *
		 * An unknown key cannot be written, so anything added elsewhere has
		 * to be declared here first.
		 *
		 * @param array $schema The settings.
		 */
		return (array) apply_filters( 'cardsco_settings_schema', $schema );
	}

	/**
	 * Families of settings whose keys are not known in advance.
	 *
	 * A company's standing choice of card and wording is stored per occasion
	 * type — default_card_7, default_message_7 — so those keys depend on
	 * rows in the database and cannot be listed in schema(). Matching them
	 * by shape keeps the rule that nothing undeclared can be written, while
	 * still allowing a family that is genuinely dynamic.
	 *
	 * @return array<string, array<string, mixed>> Pattern => definition.
	 */
	public static function patterns() {
		$patterns = array(
			'/^default_card_[0-9]+$/'    => array(
				'label' => __( 'Standing card for an occasion', 'cardsco' ),
				'group' => 'scheduling',
				'type'  => 'int',
				'scope' => 'company',
				'min'   => 0,
			),
			'/^default_message_[0-9]+$/' => array(
				'label' => __( 'Standing wording for an occasion', 'cardsco' ),
				'group' => 'scheduling',
				'type'  => 'int',
				'scope' => 'company',
				'min'   => 0,
			),
		);

		/**
		 * Settings whose keys are matched by shape rather than listed.
		 *
		 * @param array $patterns Pattern => definition.
		 */
		return (array) apply_filters( 'cardsco_settings_patterns', $patterns );
	}

	/**
	 * One setting's definition, by exact key or by shape.
	 *
	 * @param string $key Setting key.
	 * @return array|null
	 */
	public static function definition( $key ) {
		$schema = self::schema();
		$key    = sanitize_key( $key );

		if ( isset( $schema[ $key ] ) ) {
			return $schema[ $key ];
		}

		foreach ( self::patterns() as $pattern => $def ) {
			if ( preg_match( $pattern, $key ) ) {
				return $def;
			}
		}

		return null;
	}

	/**
	 * The settings in one group, or the ones a company may set for itself.
	 *
	 * The Account Settings screen renders from this, so a setting appears on
	 * it by being declared rather than by being added in two places.
	 *
	 * @param string $group Group name, or '' for all.
	 * @param string $scope 'company', 'platform', or '' for both.
	 * @return array
	 */
	public static function fields( $group = '', $scope = '' ) {
		$out = array();

		foreach ( self::schema() as $key => $def ) {
			if ( $group && ( isset( $def['group'] ) ? $def['group'] : '' ) !== $group ) {
				continue;
			}

			if ( $scope && ( isset( $def['scope'] ) ? $def['scope'] : 'platform' ) !== $scope ) {
				continue;
			}

			$out[ $key ] = $def;
		}

		return $out;
	}

	/**
	 * Clean a value for a setting, by the type it declares.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return mixed|WP_Error The cleaned value, or an error saying why not.
	 */
	public static function clean( $key, $value ) {
		$def = self::definition( $key );

		if ( ! $def ) {
			return new WP_Error( 'cardsco_unknown_setting', __( 'That is not a setting we have.', 'cardsco' ) );
		}

		$type = isset( $def['type'] ) ? $def['type'] : 'text';

		switch ( $type ) {
			case 'int':
				$n = (int) $value;

				if ( isset( $def['min'] ) && $n < $def['min'] ) {
					/* translators: %d: the smallest allowed value. */
					return new WP_Error( 'cardsco_setting_range', sprintf( __( 'That has to be %d or more.', 'cardsco' ), $def['min'] ) );
				}

				if ( isset( $def['max'] ) && $n > $def['max'] ) {
					/* translators: %d: the largest allowed value. */
					return new WP_Error( 'cardsco_setting_range', sprintf( __( 'That has to be %d or less.', 'cardsco' ), $def['max'] ) );
				}

				return $n;

			case 'decimal':
				if ( '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
					return new WP_Error( 'cardsco_setting_type', __( 'That has to be an amount.', 'cardsco' ) );
				}

				return number_format( (float) $value, 2, '.', '' );

			case 'bool':
				return ( $value && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;

			case 'email':
				$email = sanitize_email( (string) $value );

				if ( '' !== trim( (string) $value ) && ! is_email( $email ) ) {
					return new WP_Error( 'cardsco_setting_type', __( 'That does not look like an email address.', 'cardsco' ) );
				}

				return $email;

			case 'url':
				$url = esc_url_raw( trim( (string) $value ) );

				if ( '' !== trim( (string) $value ) && '' === $url ) {
					return new WP_Error( 'cardsco_setting_type', __( 'That does not look like a web address.', 'cardsco' ) );
				}

				return $url;

			case 'choice':
				$choices = isset( $def['choices'] ) ? (array) $def['choices'] : array();

				/*
				 * Options are written either as a flat list of values, or as
				 * value => label where the value is what gets stored and the
				 * label is what a screen shows. Both have to validate, so the
				 * allowed set is the keys of an associative array and the
				 * values of a list.
				 */
				$is_list = array_keys( $choices ) === range( 0, count( $choices ) - 1 );
				$allowed = $is_list ? array_values( $choices ) : array_keys( $choices );

				if ( ! in_array( (string) $value, array_map( 'strval', $allowed ), true ) ) {
					return new WP_Error( 'cardsco_setting_choice', __( 'That is not one of the options.', 'cardsco' ) );
				}

				return (string) $value;

			case 'list':
				$list = is_array( $value ) ? $value : preg_split( '/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

				return array_values( array_map( 'sanitize_text_field', (array) $list ) );

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Whether a company may set this for itself.
	 *
	 * Pricing and scheduling windows belong to CardsCo, not to the customer,
	 * so the settings screen must not offer them and set() must not take them
	 * from a company context.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function company_may_set( $key ) {
		$def = self::definition( $key );

		return $def && 'company' === ( isset( $def['scope'] ) ? $def['scope'] : 'platform' );
	}

	/**
	 * Hard-coded fallbacks.
	 *
	 * Derived from the schema so there is one list rather than two. Anything
	 * marked provisional is waiting on a client decision and must stay
	 * editable rather than being hard-coded anywhere else in the plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$out = array();

		foreach ( self::schema() as $key => $def ) {
			if ( array_key_exists( 'default', $def ) ) {
				$out[ $key ] = $def['default'];
			}
		}

		return $out;
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

		/*
		 * Everything goes through the schema now. A settings form posts
		 * straight into here, so an undeclared key or an out-of-range number
		 * is refused rather than written and discovered later.
		 */
		$clean = self::clean( $key, $value );

		if ( is_wp_error( $clean ) ) {
			return false;
		}

		$value  = $clean;
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
