<?php
/**
 * Fulfilment: the screens CardsCo's own team work from.
 *
 * Everything else in the admin is about setting things up. This is the part
 * where somebody picks up a pen. Three screens:
 *
 *   Boxes      what has to be written, packed and posted, soonest first
 *   One box    the cards in it, and the buttons that move it along
 *   Write list a plain printable sheet to actually write from
 *
 * The client never sees any of this.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Fulfilment {

	const PAGE = 'cardsco-boxes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_cardsco_box_advance', array( $this, 'handle_advance' ) );
		add_action( 'admin_post_cardsco_card_status', array( $this, 'handle_card_status' ) );
		add_action( 'admin_post_cardsco_cards_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_cardsco_campaign_mark', array( $this, 'handle_campaign_mark' ) );
		add_action( 'admin_post_cardsco_run_scan', array( $this, 'handle_run_scan' ) );
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * Route between the list, one box and the write list.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @return void
	 */
	public function render( CardsCo_Admin $admin ) {
		/* The box list shows every company unless one is chosen. */
		$company_id = $admin->viewing_company();
		$box_id     = absint( $_GET['box'] ?? 0 );
		$box        = $box_id ? CardsCo_Box::get( $box_id ) : null;

		if ( $box && 'write' === sanitize_key( $_GET['view'] ?? '' ) ) {
			$this->render_write_list( $admin, $box );
			return;
		}

		if ( $box ) {
			$this->render_box( $admin, $box );
			return;
		}

		$this->render_list( $admin, $company_id );
	}

	/**
	 * Every box with work left in it.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param int           $company_id Company ID, 0 for all.
	 * @return void
	 */
	protected function render_list( CardsCo_Admin $admin, $company_id ) {
		$boxes  = CardsCo_Box::outstanding( array( 'company_id' => $company_id ) );
		$recent = CardsCo_Box::for_company( $company_id, array( 'status' => 'posted', 'limit' => 10 ) );
		$today  = cardsco_now( 'date' );

		include CARDSCO_PATH . 'includes/admin/views/boxes.php';
	}

	/**
	 * One box and its cards.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param array         $box Box row.
	 * @return void
	 */
	protected function render_box( CardsCo_Admin $admin, array $box ) {
		$cards     = CardsCo_Box::cards( (int) $box['id'] );
		$programme = CardsCo_Programme::get( (int) $box['programme_id'] );
		$company   = CardsCo_Company::get( (int) $box['company_id'] );
		$today     = cardsco_now( 'date' );
		$held      = 0;

		foreach ( $cards as $card ) {
			if ( 'on_hold' === $card['status'] ) {
				$held++;
			}
		}

		include CARDSCO_PATH . 'includes/admin/views/box.php';
	}

	/**
	 * The printable sheet somebody writes from.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param array         $box Box row.
	 * @return void
	 */
	protected function render_write_list( CardsCo_Admin $admin, array $box ) {
		$all       = CardsCo_Box::cards( (int) $box['id'] );
		$programme = CardsCo_Programme::get( (int) $box['programme_id'] );
		$company   = CardsCo_Company::get( (int) $box['company_id'] );

		// Only what can actually be written. A held card has no finished
		// message or no address, so printing it would waste a card.
		$cards = array();

		foreach ( $all as $card ) {
			if ( ! in_array( $card['status'], array( 'on_hold', 'cancelled', 'failed' ), true ) ) {
				$cards[] = $card;
			}
		}

		include CARDSCO_PATH . 'includes/admin/views/write-list.php';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Move a box along.
	 *
	 * @return void
	 */
	public function handle_advance() {
		$this->verify();

		$box_id = absint( $_POST['box_id'] ?? 0 );
		$status = sanitize_key( $_POST['status'] ?? '' );
		$result = CardsCo_Box::advance( $box_id, $status );

		if ( is_wp_error( $result ) ) {
			$this->go(
				array(
					'box'           => $box_id,
					'cardsco_error' => $result->get_error_message(),
				)
			);
		}

		$message = sprintf(
			/* translators: 1: number of cards, 2: the new status. */
			_n( '%1$d card marked %2$s.', '%1$d cards marked %2$s.', (int) $result['moved'], 'cardsco' ),
			(int) $result['moved'],
			$this->card_word( $status )
		);

		if ( $result['left'] ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of cards left on hold. */
				_n(
					'%d card was left on hold and is not in the box.',
					'%d cards were left on hold and are not in the box.',
					(int) $result['left'],
					'cardsco'
				),
				(int) $result['left']
			);
		}

		$this->go(
			array(
				'box'             => $box_id,
				'cardsco_message' => $message,
			)
		);
	}

	/**
	 * Change one card.
	 *
	 * @return void
	 */
	public function handle_card_status() {
		$this->verify();

		$card_id = absint( $_POST['card_id'] ?? 0 );
		$status  = sanitize_key( $_POST['status'] ?? '' );
		$box_id  = absint( $_POST['box_id'] ?? 0 );

		$done = CardsCo_Queue::set_status(
			$card_id,
			$status,
			array( 'hold_reason' => sanitize_key( $_POST['hold_reason'] ?? 'needs_attention' ) )
		);

		if ( $box_id ) {
			CardsCo_Box::recalc( $box_id );
		}

		$this->go(
			array(
				'box'             => $box_id,
				'cardsco_message' => is_wp_error( $done ) ? null : __( 'Card updated.', 'cardsco' ),
				'cardsco_error'   => is_wp_error( $done ) ? $done->get_error_message() : null,
			)
		);
	}

	/**
	 * Change several cards at once.
	 *
	 * @return void
	 */
	public function handle_bulk() {
		$this->verify();

		$ids    = array_map( 'absint', (array) ( $_POST['cards'] ?? array() ) );
		$ids    = array_filter( $ids );
		$status = sanitize_key( $_POST['bulk_status'] ?? '' );
		$box_id = absint( $_POST['box_id'] ?? 0 );

		if ( ! $ids || ! $status ) {
			$this->go(
				array(
					'box'           => $box_id,
					'cardsco_error' => __( 'Pick some cards and what to do with them.', 'cardsco' ),
				)
			);
		}

		$done = 0;

		foreach ( $ids as $id ) {
			if ( ! is_wp_error( CardsCo_Queue::set_status( $id, $status ) ) ) {
				$done++;
			}
		}

		if ( $box_id ) {
			CardsCo_Box::recalc( $box_id );
		}

		$this->go(
			array(
				'box'             => $box_id,
				'cardsco_message' => sprintf(
					/* translators: 1: number of cards, 2: the new status. */
					_n( '%1$d card marked %2$s.', '%1$d cards marked %2$s.', $done, 'cardsco' ),
					$done,
					$this->card_word( $status )
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The word to show for a status, in lower case, for sentences.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	protected function card_word( $status ) {
		$statuses = CardsCo_Queue::statuses();
		$boxes    = array(
			'packed'  => __( 'written', 'cardsco' ),
			'posted'  => __( 'posted', 'cardsco' ),
			'writing' => __( 'being written', 'cardsco' ),
		);

		if ( isset( $boxes[ $status ] ) ) {
			return $boxes[ $status ];
		}

		return isset( $statuses[ $status ] ) ? strtolower( $statuses[ $status ] ) : $status;
	}

	/**
	 * Permission and nonce.
	 *
	 * @return void
	 */
	protected function verify() {
		if ( ! current_user_can( CardsCo_Admin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cardsco' ) );
		}

		check_admin_referer( CardsCo_Admin::NONCE );
	}

	/**
	 * Redirect back to this screen.
	 *
	 * @param array $args Query arguments.
	 * @return void
	 */
	protected function go( array $args ) {
		$args['page'] = self::PAGE;

		$args = array_filter(
			$args,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Run the daily scan now, by hand.
	 *
	 * WordPress has no clock. Its scheduled work runs when somebody loads
	 * a page, so on a quiet site the scan that queues a day's cards waits
	 * for the next visitor — which on a product built entirely around
	 * dates means cards queued late with nothing to say so.
	 *
	 * A server cron hitting wp-cron.php is the real answer. This is for
	 * the days you need it now, and for finding out whether it works at
	 * all without waiting until Friday to learn that it does not.
	 *
	 * @return void
	 */
	public function handle_run_scan() {
		$this->verify();

		$boxes     = CardsCo_Box::run();
		$campaigns = CardsCo_Campaign::run();
		$scan      = CardsCo_Scheduler::run();

		$count = static function ( $result, $key ) {
			return is_array( $result ) && isset( $result[ $key ] ) ? (int) $result[ $key ] : 0;
		};

		$this->go(
			array(
				'cardsco_message' => sprintf(
					/* translators: 1: boxes made, 2: campaign cards queued, 3: cards queued by the scan. */
					__( 'Scan finished. %1$d box(es) made, %2$d campaign card(s) queued, %3$d card(s) queued.', 'cardsco' ),
					$count( $boxes, 'made' ),
					$count( $campaigns, 'queued' ),
					$count( $scan, 'queued' )
				),
			)
		);
	}

	/**
	 * Mark a whole campaign's cards at once.
	 *
	 * The cards are written by hand, so nothing can know when that
	 * happened except the person who did it. This is that person saying
	 * so — one action for the batch, because nobody should tick thirty
	 * cards one at a time.
	 *
	 * Cards on hold are left alone. A card is held because it cannot go
	 * out — no address, usually — and sweeping it up with the rest would
	 * tell the customer a card was posted that nobody could post.
	 *
	 * @return void
	 */
	public function handle_campaign_mark() {
		$this->verify();

		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		$status      = sanitize_key( $_POST['status'] ?? '' );
		$campaign    = $campaign_id ? CardsCo_Campaign::get( $campaign_id ) : null;

		if ( ! $campaign || ! array_key_exists( $status, CardsCo_Queue::statuses() ) ) {
			$this->go( array( 'cardsco_error' => __( 'That campaign or status could not be found.', 'cardsco' ) ) );
		}

		$found = CardsCo_Queue::query(
			array(
				'campaign_id' => $campaign_id,
				'limit'       => 0,
			)
		);

		$cards = isset( $found['items'] ) ? (array) $found['items'] : (array) $found;
		$done  = 0;
		$held  = 0;

		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) || empty( $card['id'] ) ) {
				continue;
			}

			if ( 'on_hold' === ( $card['status'] ?? '' ) ) {
				$held++;
				continue;
			}

			if ( ! is_wp_error( CardsCo_Queue::set_status( (int) $card['id'], $status ) ) ) {
				$done++;
			}
		}

		$note = sprintf(
			/* translators: 1: number of cards, 2: the new status. */
			_n( '%1$d card marked %2$s.', '%1$d cards marked %2$s.', $done, 'cardsco' ),
			$done,
			$this->card_word( $status )
		);

		if ( $held ) {
			$note .= ' ' . sprintf(
				/* translators: %d: how many cards were left alone. */
				_n( '%d was left on hold.', '%d were left on hold.', $held, 'cardsco' ),
				$held
			);
		}

		$this->go( array( 'cardsco_message' => $note ) );
	}

	/**
	 * A link to one of these screens.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args['page'] = self::PAGE;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
