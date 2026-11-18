<?php
/**
 * Date parsing for imports.
 *
 * Spreadsheets hand over dates in whatever shape the person who made them
 * felt like. The job here is to read them without ever quietly guessing wrong:
 * 01/02/2020 is genuinely ambiguous, so the order is worked out from the whole
 * column rather than from one value, and the person importing can override it.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Date_Parser {

	/**
	 * Month names and the abbreviations spreadsheets actually produce.
	 *
	 * @var array<string, int>
	 */
	const MONTH_WORDS = array(
		'jan' => 1,
		'january' => 1,
		'feb' => 2,
		'febr' => 2,
		'february' => 2,
		'mar' => 3,
		'march' => 3,
		'apr' => 4,
		'april' => 4,
		'may' => 5,
		'jun' => 6,
		'june' => 6,
		'jul' => 7,
		'july' => 7,
		'aug' => 8,
		'august' => 8,
		'sep' => 9,
		'sept' => 9,
		'september' => 9,
		'oct' => 10,
		'october' => 10,
		'nov' => 11,
		'november' => 11,
		'dec' => 12,
		'december' => 12,
	);

	/**
	 * Parse one value into its parts.
	 *
	 * @param string $value Raw cell value.
	 * @param string $order 'day' for day-first (UK), 'month' for month-first (US).
	 * @return array{day:int,month:int,year:int|null}|null Null when it cannot be read.
	 */
	public static function parse( $value, $order = 'day' ) {
		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return null;
		}

		// Strip a time portion; nobody needs the hour a birthday falls on.
		$raw = preg_replace( '/[T\s]+\d{1,2}:\d{2}(:\d{2})?(\s*[AaPp][Mm])?.*$/', '', $raw );
		$raw = trim( $raw );

		// Ordinal suffixes: 1st, 2nd, 23rd, 14th.
		$raw = preg_replace( '/(\d+)(st|nd|rd|th)\b/i', '$1', $raw );
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		foreach ( array( 'iso', 'compact', 'worded', 'numeric', 'serial' ) as $strategy ) {
			$parsed = call_user_func( array( __CLASS__, 'try_' . $strategy ), $raw, $order );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Work out whether a column is day-first or month-first.
	 *
	 * Decided on evidence, not assumption: a value whose first number is above
	 * 12 can only be a day, and one whose second number is above 12 can only be
	 * a month. When a column holds nothing but values that work either way, it
	 * says so rather than picking one, and the screen asks.
	 *
	 * @param string[] $values Sample cell values.
	 * @return array{order:string,confident:bool,day_evidence:int,month_evidence:int,ambiguous:int}
	 */
	public static function detect_order( array $values ) {
		$day_evidence   = 0;
		$month_evidence = 0;
		$ambiguous      = 0;

		foreach ( $values as $value ) {
			$raw = trim( (string) $value );

			if ( '' === $raw ) {
				continue;
			}

			$raw = preg_replace( '/(\d+)(st|nd|rd|th)\b/i', '$1', $raw );

			// Only slash/dot/dash numeric forms are ambiguous. An ISO date or a
			// worded month carries its own order and tells us nothing.
			if ( ! preg_match( '#^(\d{1,2})[/.\-](\d{1,2})([/.\-](\d{2,4}))?$#', trim( $raw ), $m ) ) {
				continue;
			}

			$first  = (int) $m[1];
			$second = (int) $m[2];

			if ( $first > 12 && $second <= 12 ) {
				$day_evidence++;
			} elseif ( $second > 12 && $first <= 12 ) {
				$month_evidence++;
			} else {
				$ambiguous++;
			}
		}

		if ( $day_evidence && ! $month_evidence ) {
			return self::verdict( 'day', true, $day_evidence, $month_evidence, $ambiguous );
		}

		if ( $month_evidence && ! $day_evidence ) {
			return self::verdict( 'month', true, $day_evidence, $month_evidence, $ambiguous );
		}

		// Both kinds of evidence means the column is inconsistent. Day-first is
		// the UK default, but this is reported as not confident so the screen
		// shows a warning rather than sailing past it.
		if ( $day_evidence && $month_evidence ) {
			return self::verdict( 'day', false, $day_evidence, $month_evidence, $ambiguous );
		}

		return self::verdict( 'day', 0 === $ambiguous, $day_evidence, $month_evidence, $ambiguous );
	}

	/**
	 * Shape a detection result.
	 *
	 * @param string $order Chosen order.
	 * @param bool   $confident Whether the data settled it.
	 * @param int    $day Day evidence count.
	 * @param int    $month Month evidence count.
	 * @param int    $ambiguous Ambiguous count.
	 * @return array
	 */
	protected static function verdict( $order, $confident, $day, $month, $ambiguous ) {
		return array(
			'order'          => $order,
			'confident'      => (bool) $confident,
			'day_evidence'   => (int) $day,
			'month_evidence' => (int) $month,
			'ambiguous'      => (int) $ambiguous,
		);
	}

	/**
	 * ISO: 2020-02-14, 2020/02/14.
	 *
	 * @param string $raw Value.
	 * @param string $order Unused.
	 * @return array|null
	 */
	protected static function try_iso( $raw, $order ) {
		if ( ! preg_match( '#^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})$#', $raw, $m ) ) {
			return null;
		}

		return self::build( (int) $m[3], (int) $m[2], (int) $m[1] );
	}

	/**
	 * Compact: 20200214.
	 *
	 * @param string $raw Value.
	 * @param string $order Unused.
	 * @return array|null
	 */
	protected static function try_compact( $raw, $order ) {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) {
			return null;
		}

		return self::build( (int) $m[3], (int) $m[2], (int) $m[1] );
	}

	/**
	 * Worded: 14 Feb 2020, Feb 14 2020, 14 February, March 3.
	 *
	 * @param string $raw Value.
	 * @param string $order Unused.
	 * @return array|null
	 */
	protected static function try_worded( $raw, $order ) {
		$clean = str_replace( ',', ' ', $raw );
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		// 14 Feb 2020 / 14 Feb
		if ( preg_match( '/^(\d{1,2})[\s\-]+([A-Za-z]+)([\s\-]+(\d{2,4}))?$/', $clean, $m ) ) {
			$month = self::word_to_month( $m[2] );

			if ( $month ) {
				return self::build( (int) $m[1], $month, isset( $m[4] ) ? self::expand_year( $m[4] ) : null );
			}
		}

		// Feb 14 2020 / February 14
		if ( preg_match( '/^([A-Za-z]+)[\s\-]+(\d{1,2})([\s\-]+(\d{2,4}))?$/', $clean, $m ) ) {
			$month = self::word_to_month( $m[1] );

			if ( $month ) {
				return self::build( (int) $m[2], $month, isset( $m[4] ) ? self::expand_year( $m[4] ) : null );
			}
		}

		return null;
	}

	/**
	 * Numeric: 14/02/2020, 02-14-2020, 14.02.20, 14/02.
	 *
	 * @param string $raw Value.
	 * @param string $order 'day' or 'month'.
	 * @return array|null
	 */
	protected static function try_numeric( $raw, $order ) {
		if ( ! preg_match( '#^(\d{1,2})[/.\-](\d{1,2})([/.\-](\d{2,4}))?$#', $raw, $m ) ) {
			return null;
		}

		$first  = (int) $m[1];
		$second = (int) $m[2];
		$year   = isset( $m[4] ) ? self::expand_year( $m[4] ) : null;

		// A value above 12 can only be a day, whatever the column order says.
		// Honouring that here stops one odd row being thrown away because the
		// rest of the column pointed the other way.
		if ( $first > 12 && $second <= 12 ) {
			return self::build( $first, $second, $year );
		}

		if ( $second > 12 && $first <= 12 ) {
			return self::build( $second, $first, $year );
		}

		return 'month' === $order
			? self::build( $second, $first, $year )
			: self::build( $first, $second, $year );
	}

	/**
	 * Excel serial numbers, counted from 1899-12-30.
	 *
	 * Only accepted inside a sensible range so a stray order number or a
	 * quantity never becomes a date.
	 *
	 * @param string $raw Value.
	 * @param string $order Unused.
	 * @return array|null
	 */
	protected static function try_serial( $raw, $order ) {
		// Five digits only. Four digits is almost always a bare year, and
		// reading 2020 as a serial would quietly produce a date in 1905.
		// Every serial for a date a person could plausibly have is five
		// digits anyway: 10000 is 1927, 80000 is 2089.
		if ( ! preg_match( '/^\d{5}$/', $raw ) ) {
			return null;
		}

		$serial = (int) $raw;

		if ( $serial < 10000 || $serial > 80000 ) {
			return null;
		}

		$timestamp = ( $serial - 25569 ) * DAY_IN_SECONDS;

		return self::build(
			(int) gmdate( 'j', $timestamp ),
			(int) gmdate( 'n', $timestamp ),
			(int) gmdate( 'Y', $timestamp )
		);
	}

	/**
	 * Turn a month word into its number.
	 *
	 * @param string $word Month name or abbreviation.
	 * @return int|null
	 */
	protected static function word_to_month( $word ) {
		$key = strtolower( trim( (string) $word, " \t.\u{00a0}" ) );

		return self::MONTH_WORDS[ $key ] ?? null;
	}

	/**
	 * Expand a two digit year.
	 *
	 * Not the usual 00-69 rule. Every date in an import of this kind is in the
	 * past: a birthday, a start date, a last order. So a two digit year that
	 * would land in the future belongs to the last century. That turns '65
	 * into 1965 rather than 2065, which is what somebody typing a birth year
	 * meant.
	 *
	 * @param string $year Raw year.
	 * @return int
	 */
	protected static function expand_year( $year ) {
		$year = (int) $year;

		if ( $year >= 100 ) {
			return $year;
		}

		$current = (int) gmdate( 'Y' );
		$guess   = 2000 + $year;

		return $guess > $current ? 1900 + $year : $guess;
	}

	/**
	 * Validate the parts and return them, or null if the date is not real.
	 *
	 * @param int      $day Day.
	 * @param int      $month Month.
	 * @param int|null $year Year.
	 * @return array|null
	 */
	protected static function build( $day, $month, $year = null ) {
		$day   = (int) $day;
		$month = (int) $month;
		$year  = $year ? (int) $year : null;

		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return null;
		}

		// 29 February with no year is valid: it is a real birthday.
		if ( ! checkdate( $month, $day, $year ? $year : 2024 ) ) {
			return null;
		}

		// Generous bounds. The year is informational for an annual occasion,
		// which runs off the month and day, so a long-established company's
		// "customer since 1887" is kept rather than thrown away. The range
		// still rejects the junk that matters: year 0050, year 9999.
		if ( $year && ( $year < 1800 || $year > 2100 ) ) {
			return null;
		}

		return array(
			'day'   => $day,
			'month' => $month,
			'year'  => $year,
		);
	}
}
