<?php
/**
 * Shared helpers.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fully qualified custom table name.
 *
 * @param string $name Logical name, e.g. 'recipients'.
 * @return string
 */
function cardsco_table( $name ) {
	return CardsCo_Schema::table( $name );
}

/**
 * Current time in site timezone, MySQL format.
 *
 * @param string $type 'mysql' or 'date'.
 * @return string
 */
function cardsco_now( $type = 'mysql' ) {
	return 'date' === $type ? current_time( 'Y-m-d' ) : current_time( 'mysql' );
}

/**
 * Normalise an address into a hash used for duplicate detection.
 *
 * Case, punctuation and whitespace are stripped so "12 High St." and
 * "12 high street" collapse to the same value.
 *
 * @param string $address Full address.
 * @param string $postcode Postcode.
 * @return string 32 character hash, or an empty string when there is no address.
 */
function cardsco_address_hash( $address, $postcode = '' ) {
	$raw = strtolower( trim( $address . ' ' . $postcode ) );

	if ( '' === $raw ) {
		return '';
	}

	$raw = str_replace(
		array( ' street', ' road', ' avenue', ' drive', ' lane', ' court' ),
		array( ' st', ' rd', ' ave', ' dr', ' ln', ' ct' ),
		$raw
	);

	$raw = preg_replace( '/[^a-z0-9]/', '', $raw );

	return '' === $raw ? '' : md5( $raw );
}

/**
 * Pull a UK postcode out of a block of address text.
 *
 * People type the whole address into one box, postcode included, which is
 * exactly what the brief asks for. This finds it so the postcode column and
 * the address check still work without making anyone fill in a second field.
 *
 * @param string $address Full address.
 * @return string Postcode, formatted, or an empty string.
 */
function cardsco_extract_postcode( $address ) {
	$address = (string) $address;

	if ( '' === trim( $address ) ) {
		return '';
	}

	// Match anywhere, then keep the last hit: the postcode is at the end of a
	// UK address, and an earlier line can look postcode-ish by accident.
	if ( ! preg_match_all( '/\b([A-Z]{1,2}[0-9][A-Z0-9]?)\s*([0-9][A-Z]{2})\b/i', $address, $matches, PREG_SET_ORDER ) ) {
		return '';
	}

	$last = end( $matches );

	return strtoupper( $last[1] ) . ' ' . strtoupper( $last[2] );
}

/**
 * Say in a few words what is wrong with an address.
 *
 * Fulfilment needs to know which part is missing, not just that something is.
 *
 * @param string $address Full address.
 * @param string $postcode Postcode.
 * @return string Short reason, or an empty string when the address is fine.
 */
function cardsco_address_issue( $address, $postcode = '' ) {
	$status = cardsco_address_status( $address, $postcode );

	if ( 'complete' === $status ) {
		return '';
	}

	if ( 'missing' === $status ) {
		return __( 'No address', 'cardsco' );
	}

	if ( '' === trim( (string) $postcode ) && '' === cardsco_extract_postcode( $address ) ) {
		return __( 'No postcode', 'cardsco' );
	}

	return __( 'Needs a street address', 'cardsco' );
}

/**
 * Whether an address is complete enough to post a card to.
 *
 * Deliberately loose. A postcode plus something that looks like an address is
 * enough; judging whether a house is really called The Old Rectory is the
 * fulfilment team's job, not a regular expression's.
 *
 * @param string $address Full address.
 * @param string $postcode Postcode.
 * @return string One of 'complete', 'missing', 'incomplete'.
 */
function cardsco_address_status( $address, $postcode = '' ) {
	$address  = trim( (string) $address );
	$postcode = trim( (string) $postcode );

	if ( '' === $address && '' === $postcode ) {
		return 'missing';
	}

	if ( '' === $postcode ) {
		$postcode = cardsco_extract_postcode( $address );
	}

	if ( '' === $postcode ) {
		return 'incomplete';
	}

	// Strip the postcode out before measuring, so a box containing nothing but
	// a postcode is not mistaken for a full address.
	$remainder = trim( str_ireplace( array( $postcode, str_replace( ' ', '', $postcode ) ), '', $address ) );
	$remainder = trim( $remainder, " \t\n\r,." );

	return mb_strlen( $remainder ) >= 6 ? 'complete' : 'incomplete';
}

/**
 * The company a user belongs to.
 *
 * @param int $user_id User ID, or 0 for the current user.
 * @return int Company ID, or 0 when the user has no company.
 */
function cardsco_get_user_company_id( $user_id = 0 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	if ( ! $user_id ) {
		return 0;
	}

	$cached = wp_cache_get( $user_id, 'cardsco_user_company' );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	$table = cardsco_table( 'company_users' );

	$company_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT company_id FROM {$table} WHERE user_id = %d AND status = 'active' ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
			$user_id
		)
	);

	wp_cache_set( $user_id, $company_id, 'cardsco_user_company', HOUR_IN_SECONDS );

	return $company_id;
}

/**
 * The company the current request is acting for.
 *
 * Staff viewing a company in the admin can set this via the
 * cardsco_current_company_id filter; everyone else gets their own company.
 *
 * @return int
 */
function cardsco_current_company_id() {
	return (int) apply_filters( 'cardsco_current_company_id', cardsco_get_user_company_id() );
}

/**
 * Settings accessor.
 *
 * @param string $key Setting key.
 * @param mixed  $default Fallback when nothing is stored.
 * @param int    $company_id Company, or 0 for platform defaults.
 * @return mixed
 */
function cardsco_get_setting( $key, $default = null, $company_id = 0 ) {
	return CardsCo_Settings::get( $key, $default, $company_id );
}

/**
 * Write an activity log entry.
 *
 * @param array $args {
 *     @type int    $company_id
 *     @type string $object_type
 *     @type int    $object_id
 *     @type string $action
 *     @type string $summary
 *     @type array  $detail
 * }
 * @return int Inserted row ID, or 0 on failure.
 */
function cardsco_log( array $args ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'company_id'  => cardsco_current_company_id(),
			'user_id'     => get_current_user_id(),
			'object_type' => '',
			'object_id'   => 0,
			'action'      => '',
			'summary'     => '',
			'detail'      => null,
		)
	);

	$inserted = $wpdb->insert(
		cardsco_table( 'activity_log' ),
		array(
			'company_id'  => absint( $args['company_id'] ),
			'user_id'     => absint( $args['user_id'] ),
			'object_type' => sanitize_key( $args['object_type'] ),
			'object_id'   => absint( $args['object_id'] ),
			'action'      => sanitize_key( $args['action'] ),
			'summary'     => sanitize_text_field( $args['summary'] ),
			'detail'      => null === $args['detail'] ? null : wp_json_encode( $args['detail'] ),
			'created_at'  => cardsco_now(),
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
	);

	return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Work out the next calendar date an occasion falls on.
 *
 * For annual occasions with a known month and day, this is the next time that
 * month and day occurs on or after $from. For one-off occasions it is the
 * single stored date, or null once it has passed.
 *
 * @param array  $occasion Row with event_month, event_day, event_year, recurrence.
 * @param string $from Date to count forward from, Y-m-d. Defaults to today.
 * @return string|null Y-m-d, or null when it cannot be worked out.
 */
function cardsco_next_occurrence( array $occasion, $from = '' ) {
	$month = isset( $occasion['event_month'] ) ? (int) $occasion['event_month'] : 0;
	$day   = isset( $occasion['event_day'] ) ? (int) $occasion['event_day'] : 0;
	$year  = isset( $occasion['event_year'] ) ? (int) $occasion['event_year'] : 0;
	$once  = isset( $occasion['recurrence'] ) && 'once' === $occasion['recurrence'];

	if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
		return null;
	}

	$from = $from ? $from : cardsco_now( 'date' );

	if ( $once ) {
		if ( ! $year ) {
			return null;
		}

		$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

		return $date >= $from ? $date : null;
	}

	$from_year = (int) substr( $from, 0, 4 );

	for ( $offset = 0; $offset <= 1; $offset++ ) {
		$candidate_year = $from_year + $offset;

		// 29 February in a non-leap year is marked on the 28th.
		$candidate_day = $day;

		if ( 2 === $month && 29 === $day && ! cardsco_is_leap_year( $candidate_year ) ) {
			$candidate_day = 28;
		}

		if ( ! checkdate( $month, $candidate_day, $candidate_year ) ) {
			return null;
		}

		$candidate = sprintf( '%04d-%02d-%02d', $candidate_year, $month, $candidate_day );

		if ( $candidate >= $from ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Leap year check.
 *
 * @param int $year Four digit year.
 * @return bool
 */
function cardsco_is_leap_year( $year ) {
	$year = (int) $year;

	return ( 0 === $year % 4 && 0 !== $year % 100 ) || 0 === $year % 400;
}

/**
 * Month numbers mapped to names in the site's language.
 *
 * @return array<int, string>
 */
function cardsco_month_choices() {
	global $wp_locale;

	$months = array();

	for ( $i = 1; $i <= 12; $i++ ) {
		$months[ $i ] = ( $wp_locale && isset( $wp_locale->month[ zeroise( $i, 2 ) ] ) )
			? $wp_locale->month[ zeroise( $i, 2 ) ]
			: gmdate( 'F', mktime( 0, 0, 0, $i, 1, 2024 ) );
	}

	return $months;
}

/**
 * Format an occasion date for reading.
 *
 * Years are left off annual occasions, because "14 February" is the fact and
 * the year it happens to fall in next is noise.
 *
 * @param int      $month Month number.
 * @param int      $day Day number.
 * @param int|null $year Optional year.
 * @return string
 */
function cardsco_format_occasion_date( $month, $day, $year = null ) {
	$month = (int) $month;
	$day   = (int) $day;

	if ( ! $month || ! $day ) {
		return '';
	}

	$months = cardsco_month_choices();
	$label  = sprintf( '%d %s', $day, $months[ $month ] ?? '' );

	return $year ? $label . ' ' . (int) $year : $label;
}

/**
 * Days between today and a date, as a readable phrase.
 *
 * @param string $date Y-m-d.
 * @param string $from Y-m-d, defaults to today.
 * @return string
 */
function cardsco_days_away( $date, $from = '' ) {
	$from = $from ? $from : cardsco_now( 'date' );

	$start = strtotime( $from );
	$end   = strtotime( (string) $date );

	if ( ! $start || ! $end ) {
		return '';
	}

	$days = (int) round( ( $end - $start ) / DAY_IN_SECONDS );

	if ( $days < 0 ) {
		return __( 'Passed', 'cardsco' );
	}

	if ( 0 === $days ) {
		return __( 'Today', 'cardsco' );
	}

	if ( 1 === $days ) {
		return __( 'Tomorrow', 'cardsco' );
	}

	/* translators: %d: number of days. */
	return sprintf( _n( 'In %d day', 'In %d days', $days, 'cardsco' ), $days );
}

/**
 * Narrow a list of cards or messages to those offered for an occasion.
 *
 * An item with no occasions ticked suits everything, which is how a plain
 * design stays available without ticking every box.
 *
 * @param WP_Post[] $posts Cards or messages.
 * @param int       $type_id Occasion type ID.
 * @return WP_Post[]
 */
function cardsco_filter_by_occasion( array $posts, $type_id ) {
	$type_id = absint( $type_id );

	return array_values(
		array_filter(
			$posts,
			static function ( $post ) use ( $type_id ) {
				$set = array_map( 'intval', (array) get_post_meta( $post->ID, '_cardsco_occasion', false ) );

				return ! $set || in_array( $type_id, $set, true );
			}
		)
	);
}

/**
 * Subtract lead days from an event date to get the write-by date.
 *
 * @param string $event_date Y-m-d.
 * @param int    $lead_days Days needed before the event.
 * @return string Y-m-d.
 */
function cardsco_write_by_date( $event_date, $lead_days ) {
	$timestamp = strtotime( $event_date . ' -' . absint( $lead_days ) . ' days' );

	return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : $event_date;
}
