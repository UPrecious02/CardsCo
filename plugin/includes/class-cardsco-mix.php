<?php
/**
 * Several designs and several messages on one order.
 *
 * An order used to carry exactly one card and one message. The rules it
 * follows now, as the client set them out:
 *
 *   designs   Under 25 cards: one. 25 to 49: up to five. 50 or more: up
 *             to ten. Only on a bulk order — 25 or more cards going to one
 *             address. The customer says how many cards of each design;
 *             if they do not, the cards are split evenly.
 *
 *   messages  Several on a bulk order, and on employee birthdays at any
 *             size. They are chosen separately from the designs and paired
 *             with them at random, used in turn so each gets its share.
 *
 *   notes     A short line for one person, written after whichever
 *             message that person's card carries.
 *
 * Nothing here costs more: every card is the price it would have been.
 *
 * This class is the arithmetic and nothing else — no database, no screen —
 * so that the rules can be tested on their own. CardsCo_Programme and
 * CardsCo_Campaign read and store; this decides.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Mix
 */
class CardsCo_Mix {

	/** How many designs an order under the bulk limit may carry. */
	const DESIGNS_SINGLE = 1;

	/** Designs on a bulk order under FIFTY_FROM cards. */
	const DESIGNS_BULK = 5;

	/** Designs on an order of FIFTY_FROM cards or more. */
	const DESIGNS_LARGE = 10;

	/** Where the larger allowance starts. */
	const LARGE_FROM = 50;

	/** The longest a personal note may be, before the card's own limit. */
	const NOTE_MAX = 200;

	/** The one occasion that may carry several messages at any size. */
	const ROTATING_CATEGORY = 'employee-birthdays';

	/* ---------------------------------------------------------------------
	 * How many
	 * ------------------------------------------------------------------ */

	/**
	 * How many designs an order of this size may carry.
	 *
	 * @param int  $quantity    How many cards.
	 * @param bool $one_address Whether they all go to one address.
	 * @param int  $company_id  Company, for its own bulk limit.
	 * @return int
	 */
	public static function design_limit( $quantity, $one_address, $company_id = 0 ) {
		$quantity = (int) $quantity;

		/*
		 * Several designs are a bulk order's privilege, and a bulk order
		 * is twenty-five or more to one address. Twenty-five or more
		 * posted separately is not sold on the site at all.
		 */
		if ( ! $one_address || ! CardsCo_Card::is_bulk( $quantity, $company_id ) ) {
			return self::DESIGNS_SINGLE;
		}

		return $quantity >= self::LARGE_FROM ? self::DESIGNS_LARGE : self::DESIGNS_BULK;
	}

	/**
	 * How many messages an order may carry.
	 *
	 * As many as it may carry designs where it is a bulk order, and never
	 * fewer than DESIGNS_BULK where several are allowed at all. Never more
	 * than there are cards: a message nobody receives is only a way to
	 * wonder where it went.
	 *
	 * @param int    $quantity    How many cards.
	 * @param bool   $one_address Whether they all go to one address.
	 * @param string $category    The occasion's category slug.
	 * @param int    $company_id  Company.
	 * @return int
	 */
	public static function message_limit( $quantity, $one_address, $category, $company_id = 0 ) {
		$quantity = (int) $quantity;
		$bulk     = $one_address && CardsCo_Card::is_bulk( $quantity, $company_id );

		if ( ! $bulk && self::ROTATING_CATEGORY !== (string) $category ) {
			return 1;
		}

		$limit = max( self::DESIGNS_BULK, self::design_limit( $quantity, $one_address, $company_id ) );

		return max( 1, min( $limit, $quantity ) );
	}

	/* ---------------------------------------------------------------------
	 * Designs
	 * ------------------------------------------------------------------ */

	/**
	 * A list of designs made safe to store and to read.
	 *
	 * Accepts what was stored (a JSON string), what a form sent, or a list
	 * already in shape. Each entry comes out as card and qty, a card
	 * appears once, and anything that is not a card id is dropped.
	 *
	 * qty is how many cards the customer asked for in that design; nought
	 * means they did not say, which is how "split them evenly" is stored.
	 *
	 * @param mixed $raw JSON, or a list of ids, or a list of card/qty pairs.
	 * @return array<int, array{card:int, qty:int}>
	 */
	public static function clean_designs( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$out  = array();
		$seen = array();

		/*
		 * A whole number above nought, or nothing. absint() would turn
		 * "-3" into card 3, which is a different card from the one
		 * nobody asked for.
		 */
		$whole = static function ( $value ) {
			return ( is_scalar( $value ) && is_numeric( $value ) && (int) $value > 0 ) ? (int) $value : 0;
		};

		foreach ( (array) $raw as $one ) {
			if ( is_array( $one ) ) {
				$card = $whole( $one['card'] ?? 0 );
				$qty  = $whole( $one['qty'] ?? 0 );
			} else {
				$card = $whole( $one );
				$qty  = 0;
			}

			if ( ! $card || isset( $seen[ $card ] ) ) {
				continue;
			}

			$seen[ $card ] = true;
			$out[]         = array(
				'card' => $card,
				'qty'  => $qty,
			);
		}

		return $out;
	}

	/**
	 * Whether the customer said how many of each design.
	 *
	 * All of them or none: a split with one number filled in and the rest
	 * blank has no sensible reading, so it counts as not said.
	 *
	 * @param array $designs From clean_designs().
	 * @return bool
	 */
	public static function is_explicit( array $designs ) {
		if ( ! $designs ) {
			return false;
		}

		foreach ( $designs as $one ) {
			if ( (int) $one['qty'] < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How many cards of each design, for an order of this size.
	 *
	 * Always adds up to the quantity. Three cases:
	 *
	 *   nothing said      an even split, the spare cards going to the
	 *                     designs chosen first
	 *   said, and it fits exactly what was asked for
	 *   said, but the     the same proportions at the new size — somebody
	 *   order has changed joined or left since the numbers were typed
	 *
	 * @param array $designs  From clean_designs().
	 * @param int   $quantity How many cards the order is for.
	 * @return array<int, int> Card id => how many, in the order chosen.
	 */
	public static function split( array $designs, $quantity ) {
		$quantity = max( 0, (int) $quantity );
		$count    = count( $designs );
		$out      = array();

		if ( ! $count ) {
			return $out;
		}

		foreach ( $designs as $one ) {
			$out[ (int) $one['card'] ] = 0;
		}

		if ( ! $quantity ) {
			return $out;
		}

		$cards = array_keys( $out );

		if ( ! self::is_explicit( $designs ) ) {
			$base  = intdiv( $quantity, $count );
			$spare = $quantity % $count;

			foreach ( $cards as $i => $card ) {
				$out[ $card ] = $base + ( $i < $spare ? 1 : 0 );
			}

			return $out;
		}

		$asked = 0;

		foreach ( $designs as $one ) {
			$asked += (int) $one['qty'];
		}

		if ( $asked === $quantity ) {
			foreach ( $designs as $one ) {
				$out[ (int) $one['card'] ] = (int) $one['qty'];
			}

			return $out;
		}

		/*
		 * Largest remainder. Each design gets the whole part of its share,
		 * and the cards left over go one each to the designs that were
		 * rounded down the furthest. Integer arithmetic throughout, so the
		 * same order always splits the same way.
		 */
		$given = 0;
		$rest  = array();

		foreach ( $designs as $i => $one ) {
			$card  = (int) $one['card'];
			$whole = intdiv( (int) $one['qty'] * $quantity, $asked );

			$out[ $card ] = $whole;
			$given       += $whole;
			$rest[]       = array(
				'card' => $card,
				'left' => ( (int) $one['qty'] * $quantity ) % $asked,
				'at'   => $i,
			);
		}

		usort(
			$rest,
			static function ( $a, $b ) {
				if ( $a['left'] === $b['left'] ) {
					return $a['at'] <=> $b['at'];
				}

				return $b['left'] <=> $a['left'];
			}
		);

		foreach ( $rest as $one ) {
			if ( $given >= $quantity ) {
				break;
			}

			$out[ $one['card'] ]++;
			$given++;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------------ */

	/**
	 * A list of messages made safe to store and to read.
	 *
	 * Empty ones are dropped and the same wording twice is one message.
	 *
	 * @param mixed $raw JSON, or a list of strings.
	 * @return string[]
	 */
	public static function clean_messages( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		$out  = array();
		$seen = array();

		foreach ( (array) $raw as $one ) {
			if ( ! is_string( $one ) ) {
				continue;
			}

			$text = trim( CardsCo_Message::clean_text( $one ) );

			if ( '' === $text || isset( $seen[ $text ] ) ) {
				continue;
			}

			$seen[ $text ] = true;
			$out[]         = $text;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Who gets what
	 * ------------------------------------------------------------------ */

	/**
	 * A list of ids in an order that looks shuffled and never changes.
	 *
	 * The messages are to be matched to the designs at random. Real
	 * randomness would deal a different hand every time the cards were
	 * queued, so somebody added in March would reshuffle everybody else.
	 * This is the same shuffle every time for the same order, and a
	 * different one for each order and each purpose: the salt for designs
	 * is not the salt for messages, which is what makes the pairing of one
	 * with the other arbitrary.
	 *
	 * @param int[]  $ids  What to put in order.
	 * @param string $salt What the order is for.
	 * @return int[]
	 */
	public static function shuffled( array $ids, $salt ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		usort(
			$ids,
			static function ( $a, $b ) use ( $salt ) {
				$ha = md5( $salt . ':' . $a );
				$hb = md5( $salt . ':' . $b );

				if ( $ha === $hb ) {
					return $a <=> $b;
				}

				return strcmp( $ha, $hb );
			}
		);

		return $ids;
	}

	/**
	 * Give each of these people a design.
	 *
	 * Each goes to whichever design is furthest short of its share, so the
	 * split is met exactly when it can be and is as close as possible when
	 * the people outnumber it.
	 *
	 * @param int[]            $ids     People without a design, in the order to deal them.
	 * @param array<int, int>  $targets Card id => how many it should end with.
	 * @param array<int, int>  $have    Card id => how many it already has.
	 * @return array<int, int> Person id => card id.
	 */
	public static function deal_designs( array $ids, array $targets, array $have = array() ) {
		$out   = array();
		$cards = array_keys( $targets );

		if ( ! $cards ) {
			return $out;
		}

		$count = array();

		foreach ( $cards as $card ) {
			$count[ $card ] = (int) ( $have[ $card ] ?? 0 );
		}

		foreach ( $ids as $id ) {
			$best  = null;
			$short = null;

			foreach ( $cards as $card ) {
				$gap = (int) $targets[ $card ] - $count[ $card ];

				// Strictly greater, so a tie goes to the design chosen first.
				if ( null === $best || $gap > $short ) {
					$best  = $card;
					$short = $gap;
				}
			}

			$out[ (int) $id ] = $best;
			$count[ $best ]++;
		}

		return $out;
	}

	/**
	 * Give each of these people a message, taking the messages in turn.
	 *
	 * Each goes to whichever message has been used least, so every message
	 * ends within one card of every other.
	 *
	 * @param int[]           $ids   People without a message, in the order to deal them.
	 * @param int             $total How many messages there are.
	 * @param array<int, int> $used  Message number (from 1) => how many cards carry it.
	 * @return array<int, int> Person id => message number, from 1.
	 */
	public static function deal_messages( array $ids, $total, array $used = array() ) {
		$out   = array();
		$total = (int) $total;

		if ( $total < 1 ) {
			return $out;
		}

		$count = array();

		for ( $no = 1; $no <= $total; $no++ ) {
			$count[ $no ] = (int) ( $used[ $no ] ?? 0 );
		}

		foreach ( $ids as $id ) {
			$best = 1;

			for ( $no = 2; $no <= $total; $no++ ) {
				// Strictly fewer, so a tie goes to the earlier message.
				if ( $count[ $no ] < $count[ $best ] ) {
					$best = $no;
				}
			}

			$out[ (int) $id ] = $best;
			$count[ $best ]++;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Notes
	 * ------------------------------------------------------------------ */

	/**
	 * A personal note made safe to store.
	 *
	 * One line: a note is a sentence written after the message, and a
	 * line break inside it would be a second paragraph nobody asked for.
	 *
	 * @param string $note What was typed.
	 * @return string
	 */
	public static function clean_note( $note ) {
		$note = sanitize_text_field( (string) $note );
		$note = trim( preg_replace( '/\s+/', ' ', $note ) );

		return mb_substr( $note, 0, self::NOTE_MAX );
	}

	/**
	 * A message with a person's note written after it.
	 *
	 * @param string $message The message, before or after its names are filled in.
	 * @param string $note    The note, or nothing.
	 * @return string
	 */
	public static function with_note( $message, $note ) {
		$message = (string) $message;
		$note    = trim( (string) $note );

		if ( '' === $note ) {
			return $message;
		}

		return rtrim( $message ) . "\n" . $note;
	}
}
