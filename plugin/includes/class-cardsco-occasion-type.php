<?php
/**
 * Occasion types.
 *
 * The library of things a card can be sent for. Rows with company_id 0 are
 * global and available to everyone; a company can add its own on top.
 *
 * Two modes:
 *   dated    - the date belongs to the recipient (birthday, work anniversary).
 *              These become rows in the occasions table, one per person.
 *   seasonal - one date for everyone (Christmas, Valentine's). These drive
 *              campaigns and never get a row per person.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Occasion_Type {

	/**
	 * Settings key holding confirmed dates for moveable occasions.
	 */
	const ANNUAL_DATES = 'annual_dates';

	/**
	 * Option holding the slugs retire_unspecified() switched off rather than
	 * deleted, because work was hanging off them. Read only by unretire().
	 */
	const RETIRED_OPTION = 'cardsco_retired_types';

	/**
	 * Types available to a company: the global library plus its own.
	 *
	 * @param int   $company_id Company ID.
	 * @param array $args {
	 *     @type string $mode dated | seasonal | any
	 *     @type string $applies_to customer | employee | any
	 *     @type bool   $active_only
	 * }
	 * @return array[]
	 */
	public static function all( $company_id = 0, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'mode'        => 'any',
				'applies_to'  => 'any',
				'active_only' => true,
			)
		);

		$table  = cardsco_table( 'occasion_types' );
		$where  = array( '(company_id = 0 OR company_id = %d)' );
		$params = array( absint( $company_id ) );

		if ( 'any' !== $args['mode'] ) {
			$where[]  = 'mode = %s';
			$params[] = $args['mode'];
		}

		if ( 'any' !== $args['applies_to'] ) {
			$where[]  = 'applies_to IN (%s, %s)';
			$params[] = $args['applies_to'];
			$params[] = 'both';
		}

		if ( $args['active_only'] ) {
			$where[] = 'is_active = 1';
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_order ASC, label ASC';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Fetch one type.
	 *
	 * @param int $type_id Type ID.
	 * @return array|null
	 */
	public static function get( $type_id ) {
		global $wpdb;

		$type_id = absint( $type_id );

		if ( ! $type_id ) {
			return null;
		}

		$cached = wp_cache_get( $type_id, 'cardsco_occasion_types' );

		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'occasion_types' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$type_id
			),
			ARRAY_A
		);

		wp_cache_set( $type_id, $row ? $row : 0, 'cardsco_occasion_types', HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * Fetch a type by slug.
	 *
	 * @param string $slug Slug.
	 * @param int    $company_id Prefer the company's own version if it has one.
	 * @return array|null
	 */
	public static function get_by_slug( $slug, $company_id = 0 ) {
		global $wpdb;

		$table = cardsco_table( 'occasion_types' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slug = %s AND (company_id = 0 OR company_id = %d) ORDER BY company_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				sanitize_title( $slug ),
				absint( $company_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Create a company's own occasion type.
	 *
	 * @param array $data Type fields.
	 * @return int|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$company_id = absint( $data['company_id'] ?? 0 );
		$label      = sanitize_text_field( (string) ( $data['label'] ?? '' ) );

		if ( '' === trim( $label ) ) {
			return new WP_Error( 'cardsco_type_label', __( 'Give the occasion a name.', 'cardsco' ) );
		}

		$mode  = in_array( $data['mode'] ?? '', array( 'dated', 'seasonal' ), true ) ? $data['mode'] : 'dated';
		$month = isset( $data['fixed_month'] ) && $data['fixed_month'] ? absint( $data['fixed_month'] ) : null;
		$day   = isset( $data['fixed_day'] ) && $data['fixed_day'] ? absint( $data['fixed_day'] ) : null;

		if ( 'seasonal' === $mode && $month && $day && ! checkdate( $month, $day, 2024 ) ) {
			return new WP_Error( 'cardsco_type_date', __( 'That is not a real date.', 'cardsco' ) );
		}

		if ( 'dated' === $mode ) {
			$month = null;
			$day   = null;
		}

		$row = array(
			'company_id'           => $company_id,
			'slug'                 => self::unique_slug( $company_id, $label ),
			'label'                => $label,
			'mode'                 => $mode,
			'recurrence'           => in_array( $data['recurrence'] ?? '', array( 'annual', 'once' ), true ) ? $data['recurrence'] : 'annual',
			'fixed_month'          => $month,
			'fixed_day'            => $day,
			'requires_annual_date' => ( 'seasonal' === $mode && ( ! $month || ! $day ) ) ? 1 : 0,
			'lead_days'            => absint( $data['lead_days'] ?? CardsCo_Settings::get( 'default_lead_days', null, $company_id ) ),
			'applies_to'           => in_array( $data['applies_to'] ?? '', array( 'customer', 'employee', 'both' ), true ) ? $data['applies_to'] : 'both',
			'is_active'            => 1,
			'sort_order'           => 500,
			'created_at'           => cardsco_now(),
		);

		$inserted = $wpdb->insert(
			cardsco_table( 'occasion_types' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_type_insert', __( 'The occasion could not be saved.', 'cardsco' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a type.
	 *
	 * Global types can have their lead time changed but not their identity,
	 * so one company cannot rename Christmas for everybody.
	 *
	 * @param int   $type_id Type ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $type_id, array $data ) {
		global $wpdb;

		$type_id  = absint( $type_id );
		$existing = self::get( $type_id );

		if ( ! $existing ) {
			return new WP_Error( 'cardsco_type_missing', __( 'That occasion does not exist.', 'cardsco' ) );
		}

		$fields = array();
		$format = array();

		if ( array_key_exists( 'lead_days', $data ) ) {
			$fields['lead_days'] = max( 0, absint( $data['lead_days'] ) );
			$format[]            = '%d';
		}

		if ( array_key_exists( 'is_active', $data ) ) {
			$fields['is_active'] = $data['is_active'] ? 1 : 0;
			$format[]            = '%d';
		}

		// Only a company's own type may be renamed or re-dated.
		if ( (int) $existing['company_id'] > 0 ) {
			if ( array_key_exists( 'label', $data ) ) {
				$fields['label'] = sanitize_text_field( $data['label'] );
				$format[]        = '%s';
			}

			if ( array_key_exists( 'applies_to', $data ) ) {
				$fields['applies_to'] = in_array( $data['applies_to'], array( 'customer', 'employee', 'both' ), true ) ? $data['applies_to'] : 'both';
				$format[]             = '%s';
			}
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$wpdb->update( cardsco_table( 'occasion_types' ), $fields, array( 'id' => $type_id ), $format, array( '%d' ) );

		wp_cache_delete( $type_id, 'cardsco_occasion_types' );

		return true;
	}

	/**
	 * Delete a company's own type. Global types cannot be deleted.
	 *
	 * @param int $type_id Type ID.
	 * @return bool|WP_Error
	 */
	public static function delete( $type_id ) {
		global $wpdb;

		$existing = self::get( $type_id );

		if ( ! $existing ) {
			return false;
		}

		if ( 0 === (int) $existing['company_id'] ) {
			return new WP_Error( 'cardsco_type_global', __( 'Shared occasions cannot be deleted. Switch it off instead.', 'cardsco' ) );
		}

		$wpdb->delete( cardsco_table( 'occasions' ), array( 'occasion_type_id' => absint( $type_id ) ), array( '%d' ) );
		$wpdb->delete( cardsco_table( 'occasion_types' ), array( 'id' => absint( $type_id ) ), array( '%d' ) );

		wp_cache_delete( absint( $type_id ), 'cardsco_occasion_types' );

		return true;
	}

	/**
	 * Work out the date a seasonal occasion falls on in a given year.
	 *
	 * Fixed occasions come straight from the type. Moveable ones (Easter, Eid,
	 * Diwali, Mothering Sunday) are looked up from the dates staff have
	 * confirmed, and return null until somebody confirms that year. Returning
	 * null on purpose is the point: a guessed Easter would send cards in the
	 * wrong week and nobody would notice until they arrived.
	 *
	 * @param array|int $type Type row or ID.
	 * @param int       $year Four digit year.
	 * @return string|null Y-m-d.
	 */
	public static function resolve_date( $type, $year ) {
		$type = is_array( $type ) ? $type : self::get( $type );

		if ( ! $type || 'seasonal' !== $type['mode'] ) {
			return null;
		}

		$year = absint( $year );

		if ( ! $year ) {
			return null;
		}

		if ( $type['requires_annual_date'] ) {
			return self::get_annual_date( $type['slug'], $year );
		}

		$month = (int) $type['fixed_month'];
		$day   = (int) $type['fixed_day'];

		if ( ! $month || ! $day || ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * A confirmed date for a moveable occasion.
	 *
	 * @param string $slug Type slug.
	 * @param int    $year Four digit year.
	 * @return string|null Y-m-d.
	 */
	public static function get_annual_date( $slug, $year ) {
		$dates = (array) CardsCo_Settings::get( self::ANNUAL_DATES, array() );
		$slug  = sanitize_title( $slug );
		$year  = (string) absint( $year );

		$date = $dates[ $slug ][ $year ] ?? null;

		return ( $date && CardsCo_Validator::date( $date ) ) ? $date : null;
	}

	/**
	 * Confirm the date a moveable occasion falls on in a given year.
	 *
	 * @param string $slug Type slug.
	 * @param int    $year Four digit year.
	 * @param string $date Y-m-d, or an empty string to clear it.
	 * @return bool|WP_Error
	 */
	public static function set_annual_date( $slug, $year, $date ) {
		$slug = sanitize_title( $slug );
		$year = (string) absint( $year );
		$date = trim( (string) $date );

		if ( '' !== $date && ! CardsCo_Validator::date( $date ) ) {
			return new WP_Error( 'cardsco_annual_date', __( 'Dates must be in YYYY-MM-DD format.', 'cardsco' ) );
		}

		if ( '' !== $date && substr( $date, 0, 4 ) !== $year ) {
			return new WP_Error( 'cardsco_annual_year', __( 'That date is not in the year it is set against.', 'cardsco' ) );
		}

		$dates = (array) CardsCo_Settings::get( self::ANNUAL_DATES, array() );

		if ( '' === $date ) {
			unset( $dates[ $slug ][ $year ] );
		} else {
			$dates[ $slug ][ $year ] = $date;
		}

		return CardsCo_Settings::set( self::ANNUAL_DATES, $dates, 0 );
	}

	/**
	 * Moveable types that have no confirmed date for a year yet.
	 *
	 * This is what the admin screen nags about, early enough to fix.
	 *
	 * @param int $year Four digit year.
	 * @param int $company_id Company ID.
	 * @return array[]
	 */
	public static function unconfirmed( $year, $company_id = 0 ) {
		$missing = array();

		foreach ( self::all( $company_id, array( 'mode' => 'seasonal' ) ) as $type ) {
			if ( ! $type['requires_annual_date'] ) {
				continue;
			}

			if ( ! self::get_annual_date( $type['slug'], $year ) ) {
				$missing[] = $type;
			}
		}

		return $missing;
	}

	/**
	 * Lead days for a type, falling back to the company default.
	 *
	 * @param array|int $type Type row or ID.
	 * @param int       $company_id Company ID.
	 * @return int
	 */
	public static function lead_days( $type, $company_id = 0 ) {
		$type = is_array( $type ) ? $type : self::get( $type );

		if ( $type && (int) $type['lead_days'] > 0 ) {
			return (int) $type['lead_days'];
		}

		return (int) CardsCo_Settings::get( 'default_lead_days', null, $company_id );
	}

	/**
	 * Build a slug unique across the global library and this company.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $label Label.
	 * @return string
	 */
	protected static function unique_slug( $company_id, $label ) {
		global $wpdb;

		$base = sanitize_title( $label );
		$base = '' === $base ? 'occasion' : $base;
		$slug = $base;
		$i    = 1;

		$table = cardsco_table( 'occasion_types' );

		while (
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE slug = %s AND (company_id = 0 OR company_id = %d)", // phpcs:ignore WordPress.DB.PreparedSQL
					$slug,
					absint( $company_id )
				)
			)
		) {
			$i++;
			$slug = $base . '-' . $i;
		}

		return $slug;
	}

	/**
	 * How many things point at one occasion type.
	 *
	 * Five places can: a scheduled occasion, a campaign, a programme, and
	 * the post meta that ties a card design or a starter message to an
	 * occasion. A type with none of these behind it is safe to remove; a
	 * type with any of them is somebody's live work.
	 *
	 * @param int $type_id Occasion type id.
	 * @return int
	 */
	public static function references( $type_id ) {
		global $wpdb;

		$type_id = absint( $type_id );

		if ( ! $type_id ) {
			return 0;
		}

		$count = 0;

		foreach ( array( 'occasions', 'campaigns', 'programmes' ) as $cardsco_tbl ) {
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . cardsco_table( $cardsco_tbl ) . ' WHERE occasion_type_id = %d', // phpcs:ignore WordPress.DB
					$type_id
				)
			);
		}

		/* Cards and messages both tie themselves to an occasion by post meta. */
		$count += (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				CardsCo_Card::OCCASION,
				(string) $type_id
			)
		);

		return $count;
	}

	/**
	 * Retire the global occasions no design asks for.
	 *
	 * An earlier seed shipped fourteen occasions nobody specified. Taking
	 * them out of the seed fixes new installs and does nothing for the
	 * ones already running, so this clears them out on upgrade.
	 *
	 * What it will not do is delete a type somebody is using. A company
	 * that set Easter up months ago has a programme, queued cards and a
	 * written message hanging off that row, and deleting it would leave
	 * all three pointing at nothing — far worse than a longer list. Those
	 * are deactivated instead: they disappear from every picker and keep
	 * the work they are holding up. Company-owned occasions are never
	 * touched at all; adding your own is the supported way to have one.
	 *
	 * @return array{deleted: string[], kept: string[]} Slugs, by what happened.
	 */
	public static function retire_unspecified() {
		global $wpdb;

		$table   = cardsco_table( 'occasion_types' );
		$designed = wp_list_pluck( CardsCo_Activator::designed_types(), 0 );
		$result  = array(
			'deleted' => array(),
			'kept'    => array(),
		);

		/* Global rows only: company_id = 0. A company's own are its business. */
		$rows = $wpdb->get_results(
			"SELECT id, slug FROM {$table} WHERE company_id = 0", // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( in_array( $row['slug'], $designed, true ) ) {
				continue;
			}

			$id = (int) $row['id'];

			if ( self::references( $id ) > 0 ) {
				$wpdb->update( $table, array( 'is_active' => 0 ), array( 'id' => $id ) );
				$result['kept'][] = $row['slug'];
				continue;
			}

			$wpdb->delete( $table, array( 'id' => $id ) );
			$result['deleted'][] = $row['slug'];
		}

		/*
		 * Promotions & Offers was seeded as an employee occasion, so the
		 * client's nine offer messages had no audience that could reach a
		 * customer. The seed only inserts what is missing, so a running
		 * install keeps the old value unless it is corrected here.
		 */
		$wpdb->update(
			$table,
			array(
				'label'      => 'Promotions & Offers',
				'applies_to' => 'customer',
			),
			array(
				'company_id' => 0,
				'slug'       => 'promotion',
			)
		);

		/*
		 * Remember which ones this switched off, so that if one of them is
		 * later approved and joins the designed list, the seed can switch it
		 * back on. Without the list the seed would have to either leave it
		 * off — a category offering an occasion no picker holds — or switch
		 * every designed occasion on, which would quietly undo the "On"
		 * checkbox an administrator cleared on the Occasions screen.
		 *
		 * Appended to, not replaced: this runs once per upgrade step, and a
		 * second run finding nothing must not wipe what the first recorded.
		 */
		if ( $result['kept'] ) {
			update_option(
				self::RETIRED_OPTION,
				array_values( array_unique( array_merge( (array) get_option( self::RETIRED_OPTION, array() ), $result['kept'] ) ) ),
				false
			);
		}

		return $result;
	}

	/**
	 * Switch a retired occasion back on, if this plugin was what switched it
	 * off. Returns false for one a person switched off themselves, which is
	 * their decision and not ours to reverse.
	 *
	 * @param string $slug Occasion slug.
	 * @return bool Whether it was switched back on.
	 */
	public static function unretire( $slug ) {
		global $wpdb;

		$slug    = (string) $slug;
		$retired = (array) get_option( self::RETIRED_OPTION, array() );

		if ( ! in_array( $slug, $retired, true ) ) {
			return false;
		}

		$table = cardsco_table( 'occasion_types' );

		$wpdb->update(
			$table,
			array( 'is_active' => 1 ),
			array(
				'company_id' => 0,
				'slug'       => $slug,
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		/* Spent: from here on, "off" means somebody meant it. */
		update_option( self::RETIRED_OPTION, array_values( array_diff( $retired, array( $slug ) ) ), false );

		return true;
	}
}
