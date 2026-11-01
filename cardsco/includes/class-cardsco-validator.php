<?php
/**
 * Validation.
 *
 * Every write to a custom table goes through here first. Validation returns a
 * WP_Error carrying one message per bad field, so a form can mark the fields
 * that failed rather than showing a single generic message.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Validator {

	/**
	 * Validate recipient input.
	 *
	 * @param array $data Raw input.
	 * @param bool  $partial True when updating, so absent fields are skipped.
	 * @return true|WP_Error
	 */
	public static function recipient( array $data, $partial = false ) {
		$errors = new WP_Error();

		// One name is enough. Plenty of people have exactly one, and a card
		// addressed to "Prince" posts perfectly well. Demanding both would
		// throw away real rows on import.
		$has_name = array_key_exists( 'first_name', $data ) || array_key_exists( 'last_name', $data );

		if ( ! $partial || $has_name ) {
			$first = trim( (string) ( $data['first_name'] ?? '' ) );
			$last  = trim( (string) ( $data['last_name'] ?? '' ) );

			if ( '' === $first && '' === $last ) {
				$errors->add( 'first_name', __( 'Enter a name.', 'cardsco' ) );
			}
		}

		if ( array_key_exists( 'type', $data ) && ! in_array( $data['type'], array( 'customer', 'employee' ), true ) ) {
			$errors->add( 'type', __( 'Type must be customer or employee.', 'cardsco' ) );
		}

		if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
			$errors->add( 'email', __( 'That email address does not look valid.', 'cardsco' ) );
		}

		if ( ! empty( $data['postcode'] ) && ! self::postcode( $data['postcode'], $data['country'] ?? 'GB' ) ) {
			$errors->add( 'postcode', __( 'That postcode does not look valid for the United Kingdom.', 'cardsco' ) );
		}

		foreach ( array( 'relationship_since', 'last_order_date' ) as $field ) {
			if ( ! empty( $data[ $field ] ) && ! self::date( $data[ $field ] ) ) {
				$errors->add( $field, __( 'Dates must be in YYYY-MM-DD format.', 'cardsco' ) );
			}
		}

		$lengths = array(
			'first_name'        => 191,
			'last_name'         => 191,
			'email'             => 191,
			'recipient_company' => 191,
			'job_title'         => 191,
			'postcode'          => 20,
		);

		foreach ( $lengths as $field => $max ) {
			if ( isset( $data[ $field ] ) && mb_strlen( (string) $data[ $field ] ) > $max ) {
				$errors->add(
					$field,
					sprintf(
						/* translators: %d: maximum number of characters. */
						__( 'That field cannot be longer than %d characters.', 'cardsco' ),
						$max
					)
				);
			}
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Validate segment input.
	 *
	 * @param array $data Raw input.
	 * @param bool  $partial True when updating.
	 * @return true|WP_Error
	 */
	public static function segment( array $data, $partial = false ) {
		$errors = new WP_Error();

		if ( ! $partial || array_key_exists( 'name', $data ) ) {
			$name = trim( (string) ( $data['name'] ?? '' ) );

			if ( '' === $name ) {
				$errors->add( 'name', __( 'Give the segment a name.', 'cardsco' ) );
			} elseif ( mb_strlen( $name ) > 191 ) {
				$errors->add( 'name', __( 'That name is too long.', 'cardsco' ) );
			}
		}

		if ( isset( $data['applies_to'] ) && ! in_array( $data['applies_to'], array( 'customer', 'employee', 'both' ), true ) ) {
			$errors->add( 'applies_to', __( 'Applies to must be customer, employee or both.', 'cardsco' ) );
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Loose UK postcode check.
	 *
	 * Deliberately permissive. The job here is to catch a phone number typed
	 * into the postcode box, not to reject a valid address the Royal Mail
	 * would happily deliver to.
	 *
	 * @param string $postcode Postcode.
	 * @param string $country Two letter country code.
	 * @return bool
	 */
	public static function postcode( $postcode, $country = 'GB' ) {
		$postcode = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );

		if ( '' === $postcode ) {
			return true;
		}

		if ( 'GB' !== strtoupper( $country ) ) {
			return (bool) preg_match( '/^[A-Z0-9\- ]{2,12}$/', $postcode );
		}

		return (bool) preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode );
	}

	/**
	 * Y-m-d date check.
	 *
	 * @param string $value Date string.
	 * @return bool
	 */
	public static function date( $value ) {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Format a postcode for storage: uppercase with a single space before the
	 * final three characters.
	 *
	 * @param string $postcode Postcode.
	 * @param string $country Two letter country code.
	 * @return string
	 */
	public static function format_postcode( $postcode, $country = 'GB' ) {
		$postcode = strtoupper( trim( preg_replace( '/\s+/', '', (string) $postcode ) ) );

		// Only ever reformat something that actually is a UK postcode. A
		// foreign code, or anything typed into the wrong box, is left exactly
		// as it came in rather than having a space pushed into it.
		if ( '' === $postcode || 'GB' !== strtoupper( $country ) ) {
			return $postcode;
		}

		if ( ! preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode ) ) {
			return $postcode;
		}

		return substr( $postcode, 0, -3 ) . ' ' . substr( $postcode, -3 );
	}
}
