<?php
/**
 * CSV reading.
 *
 * Deals with what spreadsheet exports actually look like: a byte order mark at
 * the front, semicolons instead of commas, Windows-1252 bytes in names with
 * accents, and blank rows at the bottom.
 *
 * Rows are read a line at a time rather than loaded into memory, so a file of
 * ten thousand people does not need ten thousand rows held at once.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_CSV {

	/**
	 * Delimiters tried when sniffing a file.
	 *
	 * @var string[]
	 */
	const DELIMITERS = array( ',', ';', "\t", '|' );

	/**
	 * Path to the file.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Detected delimiter.
	 *
	 * @var string
	 */
	protected $delimiter = ',';

	/**
	 * Header row.
	 *
	 * @var string[]
	 */
	protected $headers = array();

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to a readable CSV file.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Read the header row and work out the delimiter.
	 *
	 * @return true|WP_Error
	 */
	public function open() {
		if ( ! is_readable( $this->path ) ) {
			return new WP_Error( 'cardsco_csv_missing', __( 'That file could not be read.', 'cardsco' ) );
		}

		if ( ! filesize( $this->path ) ) {
			return new WP_Error( 'cardsco_csv_empty', __( 'That file is empty.', 'cardsco' ) );
		}

		$handle = fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return new WP_Error( 'cardsco_csv_open', __( 'That file could not be opened.', 'cardsco' ) );
		}

		$first = fgets( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $first ) {
			return new WP_Error( 'cardsco_csv_empty', __( 'That file is empty.', 'cardsco' ) );
		}

		$this->delimiter = self::sniff_delimiter( $first );

		foreach ( $this->rows( 1 ) as $row ) {
			$this->headers = array_map( array( __CLASS__, 'clean_cell' ), $row );
			break;
		}

		$this->headers = self::dedupe_headers( $this->headers );

		if ( ! array_filter( $this->headers, 'strlen' ) ) {
			return new WP_Error(
				'cardsco_csv_headers',
				__( 'The first row should be column names. This file does not seem to have any.', 'cardsco' )
			);
		}

		return true;
	}

	/**
	 * Column names from the first row.
	 *
	 * @return string[]
	 */
	public function headers() {
		return $this->headers;
	}

	/**
	 * The delimiter in use.
	 *
	 * @return string
	 */
	public function delimiter() {
		return $this->delimiter;
	}

	/**
	 * Iterate data rows, keyed by column name.
	 *
	 * @param int $limit Stop after this many rows, 0 for all.
	 * @param int $offset Skip this many data rows first.
	 * @return Generator
	 */
	public function records( $limit = 0, $offset = 0 ) {
		$index   = 0;
		$yielded = 0;
		$headers = $this->headers;
		$width   = count( $headers );

		foreach ( $this->rows() as $position => $row ) {
			// Row 0 is the header.
			if ( 0 === $position ) {
				continue;
			}

			// Skip rows that are entirely empty; exports often end with some.
			if ( ! array_filter( array_map( 'trim', $row ), 'strlen' ) ) {
				continue;
			}

			if ( $index < $offset ) {
				$index++;
				continue;
			}

			$row = array_map( array( __CLASS__, 'clean_cell' ), $row );
			$row = array_pad( array_slice( $row, 0, $width ), $width, '' );

			yield array(
				'line' => $position + 1,
				'data' => array_combine( $headers, $row ),
			);

			$index++;
			$yielded++;

			if ( $limit && $yielded >= $limit ) {
				return;
			}
		}
	}

	/**
	 * Count data rows, ignoring blank ones.
	 *
	 * @return int
	 */
	public function count_records() {
		$count = 0;

		foreach ( $this->rows() as $position => $row ) {
			if ( 0 === $position ) {
				continue;
			}

			if ( array_filter( array_map( 'trim', $row ), 'strlen' ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Raw rows straight from the file.
	 *
	 * @param int $limit Stop after this many rows including the header.
	 * @return Generator
	 */
	protected function rows( $limit = 0 ) {
		$handle = fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return;
		}

		$position = 0;

		// try/finally so the handle is released even when the caller stops
		// reading early, which records() does on every chunked import.
		try {
			while ( false !== ( $row = fgetcsv( $handle, 0, $this->delimiter ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				// fgetcsv gives array(null) for a blank line.
				if ( array( null ) === $row ) {
					continue;
				}

				if ( 0 === $position ) {
					$row[0] = self::strip_bom( (string) $row[0] );
				}

				yield $position => $row;

				$position++;

				if ( $limit && $position >= $limit ) {
					break;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Guess the delimiter from the header line.
	 *
	 * @param string $line First line of the file.
	 * @return string
	 */
	public static function sniff_delimiter( $line ) {
		$line  = self::strip_bom( $line );
		$best  = ',';
		$score = 0;

		foreach ( self::DELIMITERS as $delimiter ) {
			$count = substr_count( $line, $delimiter );

			if ( $count > $score ) {
				$score = $count;
				$best  = $delimiter;
			}
		}

		return $best;
	}

	/**
	 * Remove a UTF-8 byte order mark.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function strip_bom( $value ) {
		return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
	}

	/**
	 * Trim a cell and force it to valid UTF-8.
	 *
	 * Exports from older Excel are Windows-1252, where a curly apostrophe is a
	 * single byte. Left alone those bytes break JSON encoding later on, so the
	 * conversion happens here, once, at the edge.
	 *
	 * @param string $value Raw cell.
	 * @return string
	 */
	public static function clean_cell( $value ) {
		$value = (string) $value;

		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$converted = mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
			$value     = false === $converted ? '' : $converted;
		}

		// Strip control characters but keep tabs and newlines inside a cell.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );

		return trim( (string) $value );
	}

	/**
	 * Make header names unique and non-empty so array_combine is safe.
	 *
	 * @param string[] $headers Raw headers.
	 * @return string[]
	 */
	public static function dedupe_headers( array $headers ) {
		$seen   = array();
		$output = array();

		foreach ( $headers as $index => $header ) {
			$header = trim( (string) $header );

			if ( '' === $header ) {
				/* translators: %d: column number. */
				$header = sprintf( __( 'Column %d', 'cardsco' ), $index + 1 );
			}

			$base = $header;
			$i    = 2;

			while ( isset( $seen[ strtolower( $header ) ] ) ) {
				$header = $base . ' (' . $i . ')';
				$i++;
			}

			$seen[ strtolower( $header ) ] = true;
			$output[]                      = $header;
		}

		return $output;
	}
}
