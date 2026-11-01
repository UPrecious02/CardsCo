<?php
/**
 * Merge fields.
 *
 * Turns "Happy birthday {first name}" into the words that actually get
 * handwritten on a card. Every card goes through here, so the rule is simple:
 * a token is either replaced with a real value or reported as unresolved.
 * Nothing is ever quietly swapped for an empty string, because a card reading
 * "Thanks for your custom, " is worse than one that never got written.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Merge {

	/**
	 * Matches {first name}, {First_Name}, { company } and so on.
	 */
	const PATTERN = '/\{\s*([A-Za-z][A-Za-z0-9 _\-]{0,40}?)\s*\}/';

	/**
	 * The fields a message may use.
	 *
	 * `needs_data` marks a field whose source is not settled yet, so the
	 * screens can warn before somebody builds a campaign around it.
	 *
	 * @return array<string, array{label:string, sample:string, note:string, needs_data:bool}>
	 */
	public static function fields() {
		return array(
			'first_name' => array(
				'label'      => __( 'First name', 'cardsco' ),
				'sample'     => 'Sarah',
				'note'       => __( 'Everybody has one, so this is always safe.', 'cardsco' ),
				'needs_data' => false,
			),
			'last_name'  => array(
				'label'      => __( 'Last name', 'cardsco' ),
				'sample'     => 'Whitfield',
				'note'       => __( 'Missing for anyone stored with a single name.', 'cardsco' ),
				'needs_data' => false,
			),
			'full_name'  => array(
				'label'      => __( 'Full name', 'cardsco' ),
				'sample'     => 'Sarah Whitfield',
				'note'       => __( 'First and last together.', 'cardsco' ),
				'needs_data' => false,
			),
			'company'    => array(
				'label'      => __( 'Their company', 'cardsco' ),
				'sample'     => 'Northgate Interiors',
				'note'       => __( 'Blank for anyone added without one.', 'cardsco' ),
				'needs_data' => false,
			),
			'last_order' => array(
				'label'      => __( 'Last order date', 'cardsco' ),
				'sample'     => '14 March',
				'note'       => __( 'Needs order dates, which CardsCo does not hold yet. Waiting on the client.', 'cardsco' ),
				'needs_data' => true,
			),
			// Only a campaign can fill these two, because only a campaign has
			// an offer. Used in an occasion's wording they would come out
			// blank, and a blank must never reach a card, so the campaign
			// builder is the only screen that offers them.
			'offer_code' => array(
				'label'      => __( 'Offer code', 'cardsco' ),
				'sample'     => 'SPRING20',
				'note'       => __( 'Campaigns only, and only when an offer is switched on.', 'cardsco' ),
				'needs_data' => false,
				'campaign'   => true,
			),
			'offer_expires' => array(
				'label'      => __( 'Offer expiry', 'cardsco' ),
				'sample'     => '31 March',
				'note'       => __( 'Campaigns only, and only when the offer has an end date.', 'cardsco' ),
				'needs_data' => false,
				'campaign'   => true,
			),
		);
	}

	/**
	 * The fields a given screen should offer.
	 *
	 * @param bool $campaign True for the campaign builder.
	 * @return array
	 */
	public static function fields_for( $campaign = false ) {
		$fields = self::fields();

		if ( $campaign ) {
			return $fields;
		}

		return array_filter(
			$fields,
			static function ( $field ) {
				return empty( $field['campaign'] );
			}
		);
	}

	/**
	 * Normalise a token to its field key.
	 *
	 * "First Name", "first-name" and "FIRST_NAME" are all first_name, because
	 * nobody writing a message is going to be consistent about it.
	 *
	 * @param string $raw Token as written.
	 * @return string
	 */
	public static function normalise_key( $raw ) {
		$key = strtolower( trim( (string) $raw ) );
		$key = preg_replace( '/[\s\-]+/', '_', $key );

		return preg_replace( '/[^a-z0-9_]/', '', (string) $key );
	}

	/**
	 * Every token used in a piece of text, normalised and de-duplicated.
	 *
	 * @param string $text Message text.
	 * @return string[]
	 */
	public static function tokens( $text ) {
		if ( ! preg_match_all( self::PATTERN, (string) $text, $matches ) ) {
			return array();
		}

		$keys = array_map( array( __CLASS__, 'normalise_key' ), $matches[1] );

		return array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
	}

	/**
	 * Tokens used that are not fields we know about.
	 *
	 * @param string $text Message text.
	 * @return string[]
	 */
	public static function unknown_tokens( $text ) {
		$known = array_keys( self::fields() );

		return array_values( array_diff( self::tokens( $text ), $known ) );
	}

	/**
	 * Fill a message in.
	 *
	 * @param string $text Message template.
	 * @param array  $values Field key mapped to its value.
	 * @return array{text:string, missing:string[], unknown:string[], length:int} {
	 *     text    The message with known, available fields filled in.
	 *     missing Known fields the recipient has no value for.
	 *     unknown Tokens that are not fields at all.
	 *     length  Characters in the filled-in text.
	 * }
	 */
	public static function render( $text, array $values ) {
		$text    = (string) $text;
		$fields  = self::fields();
		$missing = array();
		$unknown = array();

		$filled = preg_replace_callback(
			self::PATTERN,
			static function ( $match ) use ( $values, $fields, &$missing, &$unknown ) {
				$key = self::normalise_key( $match[1] );

				if ( ! isset( $fields[ $key ] ) ) {
					$unknown[] = $key;

					// Left exactly as written. A token nobody recognises must
					// stay visible so the card is held back, not written with
					// a hole in the middle of a sentence.
					return $match[0];
				}

				$value = isset( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';

				if ( '' === $value ) {
					$missing[] = $key;

					return $match[0];
				}

				return $value;
			},
			$text
		);

		return array(
			'text'    => $filled,
			'missing' => array_values( array_unique( $missing ) ),
			'unknown' => array_values( array_unique( $unknown ) ),
			'length'  => mb_strlen( $filled ),
		);
	}

	/**
	 * Whether a rendered result is fit to be written on a card.
	 *
	 * @param array $rendered Result from render().
	 * @param int   $limit Character limit, 0 for none.
	 * @return true|WP_Error
	 */
	public static function check( array $rendered, $limit = 0 ) {
		$errors = new WP_Error();
		$fields = self::fields();

		if ( ! empty( $rendered['unknown'] ) ) {
			$errors->add(
				'cardsco_merge_unknown',
				sprintf(
					/* translators: %s: comma separated list of tokens. */
					__( 'This message uses something we do not recognise: %s', 'cardsco' ),
					'{' . implode( '}, {', $rendered['unknown'] ) . '}'
				)
			);
		}

		if ( ! empty( $rendered['missing'] ) ) {
			$labels = array();

			foreach ( $rendered['missing'] as $key ) {
				$labels[] = $fields[ $key ]['label'] ?? $key;
			}

			$errors->add(
				'cardsco_merge_missing',
				sprintf(
					/* translators: %s: comma separated list of field names. */
					__( 'Nothing to fill in for: %s', 'cardsco' ),
					implode( ', ', $labels )
				)
			);
		}

		if ( $limit > 0 && $rendered['length'] > $limit ) {
			$errors->add(
				'cardsco_merge_length',
				sprintf(
					/* translators: 1: length once filled in, 2: the limit. */
					__( 'Once filled in this runs to %1$d characters and the limit is %2$d.', 'cardsco' ),
					(int) $rendered['length'],
					(int) $limit
				)
			);
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Build the values for a recipient.
	 *
	 * @param array $recipient Recipient row.
	 * @param array $extra Anything else to make available.
	 * @return array<string, string>
	 */
	public static function context_for_recipient( array $recipient, array $extra = array() ) {
		$first = trim( (string) ( $recipient['first_name'] ?? '' ) );
		$last  = trim( (string) ( $recipient['last_name'] ?? '' ) );

		$values = array(
			'first_name' => $first,
			'last_name'  => $last,
			'full_name'  => trim( $first . ' ' . $last ),
			'company'    => trim( (string) ( $recipient['recipient_company'] ?? '' ) ),
			'last_order' => '',
		);

		$last_order = $recipient['last_order_date'] ?? '';

		if ( $last_order && CardsCo_Validator::date( $last_order ) ) {
			$parts = explode( '-', $last_order );
			$values['last_order'] = cardsco_format_occasion_date( (int) $parts[1], (int) $parts[2] );
		}

		return array_merge( $values, $extra );
	}

	/**
	 * Stand-in values for previewing a message with nobody in particular.
	 *
	 * @return array<string, string>
	 */
	public static function sample_context() {
		$values = array();

		foreach ( self::fields() as $key => $field ) {
			$values[ $key ] = $field['sample'];
		}

		return $values;
	}

	/**
	 * A short note listing the fields that can be used, for screen help.
	 *
	 * @return string
	 */
	public static function help_text() {
		$parts = array();

		foreach ( self::fields() as $key => $field ) {
			$parts[] = '{' . str_replace( '_', ' ', $key ) . '}';
		}

		return implode( '  ', $parts );
	}
}
