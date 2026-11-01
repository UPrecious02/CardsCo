<?php
/**
 * The occasion setup, in seven steps.
 *
 * Kept apart from the rest of the admin because it is a flow rather than a
 * screen: every step saves as a draft, you can leave and come back, and
 * nothing is turned on until the last step.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Wizard {

	const PAGE = 'cardsco-programmes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_cardsco_programme_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_cardsco_programme_step', array( $this, 'handle_step' ) );
		add_action( 'admin_post_cardsco_programme_people', array( $this, 'handle_people' ) );
		add_action( 'admin_post_cardsco_programme_activate', array( $this, 'handle_activate' ) );
		add_action( 'admin_post_cardsco_programme_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_cardsco_programme_template', array( $this, 'handle_template' ) );
	}

	/**
	 * Render the list or the flow.
	 *
	 * @param CardsCo_Admin $admin The admin screen helper, for notices and the
	 *                             company switcher.
	 * @return void
	 */
	public function render( CardsCo_Admin $admin ) {
		$company_id   = $admin->current_company();
		$programme_id = absint( $_GET['programme'] ?? 0 );
		$programme    = $programme_id ? CardsCo_Programme::get( $programme_id ) : null;
		$action       = sanitize_key( $_GET['action'] ?? '' );

		if ( $programme ) {
			$company_id = (int) $programme['company_id'];
			$step       = absint( $_GET['step'] ?? $programme['setup_step'] );
			$step       = ( $step >= 1 && $step <= 7 ) ? $step : 1;

			$this->render_step( $admin, $programme, $step, $company_id );
			return;
		}

		if ( 'new' === $action ) {
			$this->render_chooser( $admin, $company_id );
			return;
		}

		$this->render_list( $admin, $company_id );
	}

	/**
	 * The list of a company's occasions.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param int           $company_id Company ID.
	 * @return void
	 */
	protected function render_list( CardsCo_Admin $admin, $company_id ) {
		$programmes = $company_id ? CardsCo_Programme::for_company( $company_id ) : array();

		include CARDSCO_PATH . 'includes/admin/views/programmes-list.php';
	}

	/**
	 * Step 1: which occasion.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param int           $company_id Company ID.
	 * @return void
	 */
	protected function render_chooser( CardsCo_Admin $admin, $company_id ) {
		$categories = CardsCo_Category::all();

		include CARDSCO_PATH . 'includes/admin/views/programme-choose.php';
	}

	/**
	 * Steps 2 to 7.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param array         $programme Programme row.
	 * @param int           $step Step number.
	 * @param int           $company_id Company ID.
	 * @return void
	 */
	protected function render_step( CardsCo_Admin $admin, array $programme, $step, $company_id ) {
		$steps    = CardsCo_Programme::steps();
		$category = CardsCo_Category::get( $programme['category'] );
		$people   = CardsCo_Programme::people_summary( $programme );
		$estimate = CardsCo_Programme::estimate( $programme );

		include CARDSCO_PATH . 'includes/admin/views/programme-step.php';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Create a draft from the chosen occasion.
	 *
	 * @return void
	 */
	public function handle_start() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$category   = sanitize_key( $_POST['category'] ?? '' );

		$result = CardsCo_Programme::create( $company_id, $category );

		if ( is_wp_error( $result ) ) {
			$this->go(
				array(
					'company'       => $company_id,
					'action'        => 'new',
					'cardsco_error' => $result->get_error_message(),
				)
			);
		}

		CardsCo_Programme::update( $result, array( 'setup_step' => 2 ) );

		$this->go(
			array(
				'programme' => $result,
				'step'      => 2,
			)
		);
	}

	/**
	 * Save one step and move on.
	 *
	 * @return void
	 */
	public function handle_step() {
		$this->verify();

		$programme_id = absint( $_POST['programme_id'] ?? 0 );
		$step         = absint( $_POST['step'] ?? 0 );
		$programme    = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			$this->go( array( 'cardsco_error' => __( 'That occasion could not be found.', 'cardsco' ) ) );
		}

		$data = array();

		switch ( $step ) {
			case 3:
				$data['card_id'] = absint( $_POST['card_id'] ?? 0 );
				break;

			case 4:
				$data['message_id']       = absint( $_POST['message_id'] ?? 0 );
				$data['message_override'] = isset( $_POST['message_override'] ) ? wp_unslash( $_POST['message_override'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$data['handwriting']      = sanitize_text_field( wp_unslash( $_POST['handwriting'] ?? '' ) );
				$data['ink']              = sanitize_text_field( wp_unslash( $_POST['ink'] ?? '' ) );
				$data['signature']        = sanitize_text_field( wp_unslash( $_POST['signature'] ?? '' ) );
				break;

			case 5:
				$data['shipping_mode'] = sanitize_key( $_POST['shipping_mode'] ?? 'company' );
				$data['ship_address']  = sanitize_textarea_field( wp_unslash( $_POST['ship_address'] ?? '' ) );
				$data['ship_postcode'] = sanitize_text_field( wp_unslash( $_POST['ship_postcode'] ?? '' ) );
				$data['ship_country']  = sanitize_text_field( wp_unslash( $_POST['ship_country'] ?? 'GB' ) );
				break;

			case 6:
				$data['timing_mode']       = sanitize_key( $_POST['timing_mode'] ?? 'monthly_box' );
				$data['box_arrive_day']    = absint( $_POST['box_arrive_day'] ?? 25 );
				$data['days_before']       = absint( $_POST['days_before'] ?? 3 );
				$data['weekend_rule']      = ! empty( $_POST['weekend_rule'] );
				$data['notify_before_box'] = ! empty( $_POST['notify_before_box'] );
				$data['starts_on']         = sanitize_text_field( wp_unslash( $_POST['starts_on'] ?? '' ) );
				$data['recurrence']        = sanitize_key( $_POST['recurrence'] ?? 'annual' );
				break;
		}

		$next = min( 7, max( 2, $step + 1 ) );

		$data['setup_step'] = max( (int) $programme['setup_step'], $next );

		$saved = CardsCo_Programme::update( $programme_id, $data );

		$args = array(
			'programme' => $programme_id,
			'step'      => $next,
		);

		if ( is_wp_error( $saved ) ) {
			$args['step']          = $step;
			$args['cardsco_error'] = $saved->get_error_message();
		}

		$this->go( $args );
	}

	/**
	 * Step 2: upload a list, add somebody, or take somebody out.
	 *
	 * @return void
	 */
	public function handle_people() {
		$this->verify();

		$programme_id = absint( $_POST['programme_id'] ?? 0 );
		$what         = sanitize_key( $_POST['what'] ?? '' );
		$programme    = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			$this->go( array( 'cardsco_error' => __( 'That occasion could not be found.', 'cardsco' ) ) );
		}

		$args = array(
			'programme' => $programme_id,
			'step'      => 2,
		);

		switch ( $what ) {
			case 'upload':
				$result = CardsCo_Programme_Import::run(
					$programme_id,
					isset( $_FILES['people_file'] ) ? $_FILES['people_file'] : array() // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				);

				if ( is_wp_error( $result ) ) {
					$args['cardsco_error'] = $result->get_error_message();
					break;
				}

				$args['cardsco_message'] = sprintf(
					/* translators: 1: people added, 2: people matched, 3: how many had a date. */
					__( '%1$d added, %2$d already on your list, %3$d with a date.', 'cardsco' ),
					(int) $result['added'],
					(int) $result['matched'],
					(int) $result['with_date']
				);

				if ( ! empty( $result['problem_count'] ) ) {
					set_transient( 'cardsco_prog_problems_' . $programme_id, $result['problems'], 300 );
				}
				break;

			case 'add':
				$added = CardsCo_Programme_Import::add_one(
					$programme_id,
					array(
						'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
						'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
						'email'      => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
						'day'        => absint( $_POST['day'] ?? 0 ),
						'month'      => absint( $_POST['month'] ?? 0 ),
						'year'       => absint( $_POST['year'] ?? 0 ),
					)
				);

				if ( is_wp_error( $added ) ) {
					$args['cardsco_error'] = implode( ' ', $added->get_error_messages() );
				} else {
					$args['cardsco_message'] = __( 'Added.', 'cardsco' );
				}
				break;

			case 'existing':
				$count = CardsCo_Programme::add_recipients(
					$programme_id,
					array_map( 'absint', (array) ( $_POST['recipients'] ?? array() ) )
				);

				$args['cardsco_message'] = sprintf(
					/* translators: %d: number of people. */
					_n( '%d person added.', '%d people added.', $count, 'cardsco' ),
					$count
				);
				break;

			case 'remove':
				CardsCo_Programme::remove_recipients( $programme_id, array( absint( $_POST['recipient_id'] ?? 0 ) ) );
				$args['cardsco_message'] = __( 'Removed.', 'cardsco' );
				break;
		}

		$this->go( $args );
	}

	/**
	 * Turn a programme on.
	 *
	 * @return void
	 */
	public function handle_activate() {
		$this->verify();

		$programme_id = absint( $_POST['programme_id'] ?? 0 );
		$result       = CardsCo_Programme::activate( $programme_id );

		if ( is_wp_error( $result ) ) {
			$this->go(
				array(
					'programme'     => $programme_id,
					'step'          => 7,
					'cardsco_error' => implode( ' ', $result->get_error_messages() ),
				)
			);
		}

		$programme = CardsCo_Programme::get( $programme_id );

		$this->go(
			array(
				'company'         => (int) $programme['company_id'],
				'cardsco_message' => sprintf(
					/* translators: %s: occasion name. */
					__( '%s is on. Cards will be queued automatically from now on.', 'cardsco' ),
					$programme['name']
				),
			)
		);
	}

	/**
	 * Delete a draft.
	 *
	 * @return void
	 */
	public function handle_delete() {
		$this->verify();

		global $wpdb;

		$programme_id = absint( $_REQUEST['programme_id'] ?? 0 );
		$programme    = CardsCo_Programme::get( $programme_id );

		if ( ! $programme ) {
			$this->go( array() );
		}

		// Only a draft can be deleted outright. A live one is paused instead,
		// so its history is kept.
		if ( 'draft' !== $programme['status'] ) {
			CardsCo_Programme::update( $programme_id, array( 'status' => 'paused' ) );

			$this->go(
				array(
					'company'         => (int) $programme['company_id'],
					'cardsco_message' => __( 'Paused. No more cards will be queued for it.', 'cardsco' ),
				)
			);
		}

		$wpdb->delete( cardsco_table( 'programme_recipients' ), array( 'programme_id' => $programme_id ), array( '%d' ) );
		$wpdb->delete( cardsco_table( 'programmes' ), array( 'id' => $programme_id ), array( '%d' ) );

		$this->go(
			array(
				'company'         => (int) $programme['company_id'],
				'cardsco_message' => __( 'Draft discarded.', 'cardsco' ),
			)
		);
	}

	/**
	 * Send the example CSV.
	 *
	 * @return void
	 */
	public function handle_template() {
		if ( ! current_user_can( CardsCo_Admin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cardsco' ) );
		}

		check_admin_referer( CardsCo_Admin::NONCE );

		$programme = CardsCo_Programme::get( absint( $_GET['programme'] ?? 0 ) );

		if ( ! $programme ) {
			wp_die( esc_html__( 'That occasion could not be found.', 'cardsco' ) );
		}

		$body = CardsCo_Programme_Import::template( $programme );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cardsco-people-template.csv"' );
		header( 'Content-Length: ' . strlen( $body ) );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Capability and nonce check.
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
	 * Go back to the flow.
	 *
	 * @param array $args Query args.
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
	 * A link into the flow.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args['page'] = self::PAGE;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
