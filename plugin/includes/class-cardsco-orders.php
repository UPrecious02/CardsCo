<?php
/**
 * Orders — every order a company has placed, of all three kinds.
 *
 * There is no orders table, and there should not be one. An order is not a
 * thing this plugin creates separately; it is a thing that already exists
 * under one of three names:
 *
 *   recurring  a monthly box of cards for an occasion      (boxes)
 *   campaign   one send to many customers at once          (campaigns)
 *   bulk       cards bought outright from the shop         (WooCommerce)
 *
 * So this reads the three and presents them as one list. Writing them into
 * a fourth table would mean keeping a copy in step with three originals,
 * and the copy would be wrong the first time any of them changed.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Orders
 */
class CardsCo_Orders {

	/**
	 * The three kinds, in the order the tabs show them.
	 */
	const KINDS = array( 'recurring', 'campaign', 'bulk' );

	/**
	 * Which digit stands for which kind inside a reference.
	 *
	 * A box, a campaign and a shop order can all be id 42, so the kind has
	 * to be part of the reference or three different orders would answer to
	 * CC-10042. The digit keeps references unique and, more usefully, keeps
	 * them reversible: a row can be opened from its reference alone.
	 */
	const PREFIX = array(
		'recurring' => 1,
		'campaign'  => 2,
		'bulk'      => 3,
	);

	/**
	 * The reference a customer sees and quotes back at you.
	 *
	 * @param string $kind One of KINDS.
	 * @param int    $id   Row ID within that kind.
	 * @return string e.g. CC-10491
	 */
	public static function reference( $kind, $id ) {
		$digit = self::PREFIX[ $kind ] ?? 0;

		return sprintf( 'CC-%d%04d', $digit, (int) $id );
	}

	/**
	 * Read a reference back into the thing it points at.
	 *
	 * @param string $reference e.g. CC-10491.
	 * @return array|null array( 'kind' => 'recurring', 'id' => 491 )
	 */
	public static function from_reference( $reference ) {
		if ( ! preg_match( '/^CC-(\d)(\d{4,})$/i', trim( (string) $reference ), $m ) ) {
			return null;
		}

		$kind = array_search( (int) $m[1], self::PREFIX, true );

		if ( ! $kind ) {
			return null;
		}

		return array(
			'kind' => $kind,
			'id'   => (int) $m[2],
		);
	}

	/**
	 * Every order for a company, newest first.
	 *
	 * The three sources are read whole and sorted together rather than
	 * paged in SQL. A union across two tables and the WooCommerce API is
	 * not something the database can sort for us, and a company with
	 * thousands of orders is a problem worth having before it is a problem
	 * worth solving.
	 *
	 * @param int   $company_id Company.
	 * @param array $args       kind, search, months, page, per_page.
	 * @return array
	 */
	public static function all( $company_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'kind'     => 'all',
				'search'   => '',
				'months'   => 12,
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$rows = array_merge(
			self::recurring( $company_id ),
			self::campaigns( $company_id ),
			self::bulk( $company_id )
		);

		// Newest first, by the date the order was placed.
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) $b['placed'], (string) $a['placed'] );
			}
		);

		$within = self::within_months( $rows, (int) $args['months'] );
		$counts = self::counts( $within );
		$found  = self::filter( $within, $args['kind'], (string) $args['search'] );

		$per   = max( 1, (int) $args['per_page'] );
		$pages = max( 1, (int) ceil( count( $found ) / $per ) );
		$page  = min( max( 1, (int) $args['page'] ), $pages );

		return array(
			'rows'   => array_slice( $found, ( $page - 1 ) * $per, $per ),
			'total'  => count( $found ),
			'counts' => $counts,
			'page'   => $page,
			'pages'  => $pages,
			'per'    => $per,
			'from'   => count( $found ) ? ( ( $page - 1 ) * $per ) + 1 : 0,
			'to'     => min( count( $found ), $page * $per ),
		);
	}

	/**
	 * Drop anything older than the window.
	 *
	 * @param array $rows   Orders.
	 * @param int   $months How far back, 0 for everything.
	 * @return array
	 */
	protected static function within_months( array $rows, $months ) {
		if ( $months <= 0 ) {
			return $rows;
		}

		$cut = gmdate( 'Y-m-d', strtotime( '-' . (int) $months . ' months', (int) current_time( 'timestamp' ) ) );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $cut ) {
					return substr( (string) $row['placed'], 0, 10 ) >= $cut;
				}
			)
		);
	}

	/**
	 * How many of each kind, for the tab counts.
	 *
	 * Counted before the kind filter and after the date window, so the
	 * numbers on the tabs do not change as you click between them.
	 *
	 * @param array $rows Orders.
	 * @return array
	 */
	public static function counts( array $rows ) {
		$counts = array( 'all' => count( $rows ) );

		foreach ( self::KINDS as $kind ) {
			$counts[ $kind ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['kind'] ] ) ) {
				$counts[ $row['kind'] ]++;
			}
		}

		return $counts;
	}

	/**
	 * Narrow by tab and by the search box.
	 *
	 * The frame's placeholder says "Search orders or recipients", so the
	 * reference, the description and the people on the order are all worth
	 * matching.
	 *
	 * @param array  $rows   Orders.
	 * @param string $kind   One of KINDS, or 'all'.
	 * @param string $search What was typed.
	 * @return array
	 */
	public static function filter( array $rows, $kind, $search ) {
		$kind   = (string) $kind;
		$search = trim( (string) $search );

		if ( $kind && 'all' !== $kind ) {
			$rows = array_filter(
				$rows,
				static function ( $row ) use ( $kind ) {
					return $row['kind'] === $kind;
				}
			);
		}

		if ( '' !== $search ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

			$rows = array_filter(
				$rows,
				static function ( $row ) use ( $needle ) {
					$hay = implode(
						' ',
						array(
							$row['reference'],
							$row['title'],
							$row['detail'],
							$row['status_label'],
							implode( ' ', (array) $row['names'] ),
						)
					);

					$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );

					return false !== strpos( $hay, $needle );
				}
			);
		}

		return array_values( $rows );
	}

	/**
	 * The three figures across the top.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	public static function stats( $company_id ) {
		$rows  = array_merge(
			self::recurring( $company_id ),
			self::campaigns( $company_id ),
			self::bulk( $company_id )
		);
		$year  = (int) current_time( 'Y' );
		$this_year = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $year ) {
					return (int) substr( (string) $row['placed'], 0, 4 ) === $year;
				}
			)
		);

		$cards     = 0;
		$last      = 0;
		$month     = (int) current_time( 'n' );
		$in_flight = array();
		$writing   = 0;

		foreach ( $this_year as $row ) {
			$cards += (int) $row['cards'];

			if ( (int) substr( (string) $row['placed'], 5, 2 ) === $month ) {
				$last++;
			}
		}

		/*
		 * Two different questions, so two different counts. An order is in
		 * progress from the moment it is placed until it lands, but a card
		 * is only being written once somebody is actually writing it — a
		 * box scheduled for December is not 400 cards in hand today.
		 */
		$underway = array( 'writing', 'written', 'processing', 'dispatched', 'posted' );

		foreach ( $rows as $row ) {
			if ( in_array( $row['status'], array( 'delivered', 'completed', 'cancelled', 'failed', 'refunded' ), true ) ) {
				continue;
			}

			$in_flight[] = $row;

			if ( in_array( $row['status'], $underway, true ) ) {
				$writing += (int) $row['cards'];
			}
		}

		return array(
			'orders'    => count( $this_year ),
			'added'     => $last,
			'month'     => date_i18n( 'F', mktime( 0, 0, 0, $month, 1, $year ) ),
			'cards'     => $cards,
			'in_flight' => count( $in_flight ),
			'writing'   => $writing,
		);
	}

	/**
	 * Monthly boxes — the recurring kind.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	protected static function recurring( $company_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, p.category FROM ' . cardsco_table( 'boxes' ) . ' b'
				. ' LEFT JOIN ' . cardsco_table( 'programmes' ) . ' p ON p.id = b.programme_id'
				. ' WHERE b.company_id = %d',
				(int) $company_id
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$stamp = strtotime( (string) $row['period'] . '-01' );
			$title = $stamp
				/* translators: %s: a month, e.g. October. */
				? sprintf( __( '%s occasions', 'cardsco' ), date_i18n( 'F', $stamp ) )
				: __( 'Occasions', 'cardsco' );

			$out[] = array(
				'kind'         => 'recurring',
				'kind_label'   => __( 'Recurring', 'cardsco' ),
				'id'           => (int) $row['id'],
				'reference'    => self::reference( 'recurring', (int) $row['id'] ),
				'placed'       => (string) ( $row['created_at'] ? $row['created_at'] : $row['arrive_by'] ),
				'title'        => $title,
				'detail'       => self::box_detail( $row ),
				'names'        => array(),
				'cards'        => (int) $row['card_count'],
				'status'       => (string) $row['status'],
				'status_label' => self::status_label( (string) $row['status'] ),
				'tone'         => self::tone( (string) $row['status'] ),
				'amount'       => null === $row['amount'] ? null : (float) $row['amount'],
				'currency'     => (string) $row['currency'],
				'awaiting'     => 'paid' !== (string) $row['payment_status'] && null === $row['amount'],
			);
		}

		return $out;
	}

	/**
	 * What a box was for, in the words the frame uses.
	 *
	 * @param array $row Box row.
	 * @return string
	 */
	protected static function box_detail( array $row ) {
		if ( empty( $row['programme_id'] ) ) {
			return '';
		}

		$category = CardsCo_Category::get( (string) $row['category'] );

		return $category ? (string) $category['label'] : '';
	}

	/**
	 * Campaigns — one send to many customers.
	 *
	 * A campaign that has not been priced yet shows its cost as pending
	 * rather than as zero, because zero reads as free.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	protected static function campaigns( $company_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'campaigns' ) . ' WHERE company_id = %d AND status <> %s',
				(int) $company_id,
				'draft'
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$sent   = $row['send_date'] ? strtotime( (string) $row['send_date'] ) : 0;
			$detail = sprintf(
				/* translators: %s: how many customers. */
				_n( '%s customer', '%s customers', (int) $row['recipient_count'], 'cardsco' ),
				number_format_i18n( (int) $row['recipient_count'] )
			);

			if ( $sent ) {
				$detail .= ' · ' . sprintf(
					/* translators: %s: a date. */
					__( 'posts %s', 'cardsco' ),
					date_i18n( 'D j M', $sent )
				);
			}

			$out[] = array(
				'kind'         => 'campaign',
				'kind_label'   => __( 'Campaign', 'cardsco' ),
				'id'           => (int) $row['id'],
				'reference'    => self::reference( 'campaign', (int) $row['id'] ),
				'placed'       => (string) $row['created_at'],
				'title'        => (string) $row['name'],
				'detail'       => $detail,
				'names'        => array(),
				'cards'        => (int) $row['recipient_count'],
				'status'       => (string) $row['status'],
				'status_label' => self::status_label( (string) $row['status'] ),
				'tone'         => self::tone( (string) $row['status'] ),
				'amount'       => null === $row['total_amount'] ? null : (float) $row['total_amount'],
				'currency'     => (string) $row['currency'],
				'awaiting'     => 'quote' === (string) $row['billing_status'] || null === $row['total_amount'],
			);
		}

		return $out;
	}

	/**
	 * Everyone currently on a company's account.
	 *
	 * Used to find orders that carry no company of their own. Only active
	 * members: somebody removed from the account should not pull their
	 * orders back into its list.
	 *
	 * @param int $company_id Company.
	 * @return array User ids.
	 */
	protected static function company_user_ids( $company_id ) {
		global $wpdb;

		$company_id = (int) $company_id;

		if ( ! $company_id ) {
			return array();
		}

		$table = cardsco_table( 'company_users' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE company_id = %d AND status = 'active'", // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Cards bought outright — WooCommerce orders.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	protected static function bulk( $company_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		/*
		 * Found two ways, because for a long time only one of them worked.
		 *
		 * The company used to be written onto an order by a hook the block
		 * checkout never fires, so orders placed through the shop carried
		 * no company at all and this list came back empty for people who
		 * had definitely bought something. The meta is written on a hook
		 * both checkouts fire now, but that does nothing for orders already
		 * placed, and asking people to take our word for a missing order is
		 * not a fix.
		 *
		 * So the customers are asked for as well: whoever is on this
		 * company's account. An order belongs here if either says so.
		 */
		$orders = wc_get_orders(
			array(
				'limit'      => 200,
				'meta_key'   => '_cardsco_company_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (int) $company_id,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$people = self::company_user_ids( (int) $company_id );

		if ( $people ) {
			$also = wc_get_orders(
				array(
					'limit'    => 200,
					'customer' => $people,
				)
			);

			$seen = array();

			foreach ( (array) $orders as $order ) {
				if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
					$seen[ (int) $order->get_id() ] = true;
				}
			}

			foreach ( (array) $also as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
					continue;
				}

				if ( isset( $seen[ (int) $order->get_id() ] ) ) {
					continue;
				}

				$orders[] = $order;
			}
		}

		$out = array();

		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}

			$cards = 0;

			/*
			 * Checked before it is called. get_items() is documented to
			 * return item objects, and mostly does, but an order can come
			 * back from a store with a line that is not one — a half-built
			 * record, or an item type this shop has no class for — and the
			 * whole Orders screen died on it with a fatal rather than
			 * showing the other nineteen orders.
			 */
			foreach ( (array) $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
					continue;
				}

				$cards += (int) $item->get_quantity();
			}

			$created = $order->get_date_created();

			$out[] = array(
				'kind'         => 'bulk',
				'kind_label'   => __( 'Bulk', 'cardsco' ),
				'id'           => (int) $order->get_id(),
				'reference'    => self::reference( 'bulk', (int) $order->get_id() ),
				'placed'       => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
				'title'        => self::bulk_title( $order ),
				'detail'       => (string) $order->get_shipping_city(),
				'names'        => array( trim( $order->get_formatted_billing_full_name() ) ),
				'cards'        => $cards,
				'status'       => (string) $order->get_status(),
				'status_label' => self::status_label( (string) $order->get_status() ),
				'tone'         => self::tone( (string) $order->get_status() ),
				'amount'       => (float) $order->get_total(),
				'currency'     => (string) $order->get_currency(),
				'awaiting'     => false,
			);
		}

		return $out;
	}

	/**
	 * What a shop order was for.
	 *
	 * @param object $order WC_Order.
	 * @return string
	 */
	protected static function bulk_title( $order ) {
		$names = array();

		/* Same guard as the quantity count above, and for the same reason. */
		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}

			$names[] = $item->get_name();
		}

		$names = array_filter( array_unique( $names ) );

		if ( ! $names ) {
			return __( 'Card order', 'cardsco' );
		}

		if ( count( $names ) > 1 ) {
			return sprintf(
				/* translators: 1: the first card, 2: how many others. */
				__( '%1$s and %2$s more', 'cardsco' ),
				reset( $names ),
				number_format_i18n( count( $names ) - 1 )
			);
		}

		return (string) reset( $names );
	}

	/**
	 * A status in words somebody outside the company would understand.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$words = array(
			'scheduled'  => __( 'Scheduled', 'cardsco' ),
			'queued'     => __( 'Scheduled', 'cardsco' ),
			'writing'    => __( 'Writing', 'cardsco' ),
			'written'    => __( 'Written', 'cardsco' ),
			'dispatched' => __( 'Dispatched', 'cardsco' ),
			'posted'     => __( 'Dispatched', 'cardsco' ),
			'delivered'  => __( 'Delivered', 'cardsco' ),
			'completed'  => __( 'Delivered', 'cardsco' ),
			'cancelled'  => __( 'Cancelled', 'cardsco' ),
			'refunded'   => __( 'Refunded', 'cardsco' ),
			'failed'     => __( 'Failed', 'cardsco' ),
			'on-hold'    => __( 'On hold', 'cardsco' ),
			'processing' => __( 'Writing', 'cardsco' ),
			'pending'    => __( 'Awaiting payment', 'cardsco' ),
		);

		return $words[ $status ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', (string) $status ) );
	}

	/**
	 * Which pill a status gets.
	 *
	 * These are the modifiers .cc-pill already carries, so the chips here
	 * match the ones on Occasions and Campaigns rather than starting a
	 * second vocabulary that drifts from the first.
	 *
	 * @param string $status Stored status.
	 * @return string One of success, info, neutral, error.
	 */
	public static function tone( $status ) {
		if ( in_array( $status, array( 'delivered', 'completed' ), true ) ) {
			return 'success';
		}

		if ( in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return 'error';
		}

		if ( in_array( $status, array( 'writing', 'written', 'processing', 'dispatched', 'posted' ), true ) ) {
			return 'info';
		}

		return 'neutral';
	}
}
