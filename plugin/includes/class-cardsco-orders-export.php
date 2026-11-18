<?php
/**
 * The orders list as a spreadsheet.
 *
 * The frame's button says "Download invoices". There are no invoices —
 * no numbering, no VAT breakdown, no PDF — so this hands over the list
 * itself, which is what an accountant actually wants from a screen like
 * this and is honest about what the plugin holds. If real invoices are
 * wanted later they belong in their own class, not behind this button
 * pretending to be ready.
 *
 * It exports what is on screen: the same tab, search and window the person
 * is looking at. A download that quietly ignores the filters would be a
 * different list from the one they just narrowed down.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Orders_Export
 */
class CardsCo_Orders_Export {

	/**
	 * Where the button points, carrying the filters on screen.
	 *
	 * @param string $kind   Tab.
	 * @param string $search Search box.
	 * @param int    $months Window.
	 * @return string
	 */
	public static function url( $kind, $search, $months ) {
		return CardsCo_Download::url(
			'orders',
			array(
				'tab'    => $kind,
				'q'      => $search,
				'months' => (int) $months,
			)
		);
	}

	/**
	 * Send the file.
	 *
	 * @return void
	 */
	public static function send() {
		/* The nonce and the access check are done by CardsCo_Download. */
		$company_id = CardsCo_Access::company_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked above.
		$kind   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$months = isset( $_GET['months'] ) ? (int) $_GET['months'] : 12;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$kind = in_array( $kind, CardsCo_Orders::KINDS, true ) ? $kind : 'all';

		$found = CardsCo_Orders::all(
			$company_id,
			array(
				'kind'     => $kind,
				'search'   => $search,
				'months'   => $months,
				'page'     => 1,
				'per_page' => 10000,
			)
		);

		$name = sprintf( 'cardsco-orders-%s.csv', gmdate( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$out = fopen( 'php://output', 'w' );

		// Excel reads a bare UTF-8 CSV as Latin-1 and turns £ into junk.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Order', 'cardsco' ),
				__( 'Date', 'cardsco' ),
				__( 'Type', 'cardsco' ),
				__( 'Description', 'cardsco' ),
				__( 'Detail', 'cardsco' ),
				__( 'Cards', 'cardsco' ),
				__( 'Status', 'cardsco' ),
				__( 'Currency', 'cardsco' ),
				__( 'Total', 'cardsco' ),
			)
		);

		foreach ( $found['rows'] as $row ) {
			$stamp = strtotime( (string) $row['placed'] );

			fputcsv(
				$out,
				array(
					$row['reference'],
					$stamp ? gmdate( 'Y-m-d', $stamp ) : '',
					$row['kind_label'],
					$row['title'],
					$row['detail'],
					(int) $row['cards'],
					$row['status_label'],
					$row['currency'] ? $row['currency'] : '',
					/*
					 * Left empty rather than zero when there is no price
					 * yet: a spreadsheet will happily add up a column of
					 * zeroes and quietly understate the year.
					 */
					null === $row['amount'] ? '' : number_format( (float) $row['amount'], 2, '.', '' ),
				)
			);
		}

		fclose( $out );
		exit;
	}
}
