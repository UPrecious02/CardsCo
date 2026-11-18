<?php
/**
 * The campaign builder: seven steps, then it goes in the diary.
 *
 * Same shape as the occasion setup, and deliberately so — somebody who has
 * done one should recognise the other. The difference is at the end: an
 * occasion is switched on and left running, a campaign is scheduled for one
 * day and then locks.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Campaign_Builder {

	const PAGE = 'cardsco-campaigns';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_cardsco_campaign_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_cardsco_campaign_step', array( $this, 'handle_step' ) );
		add_action( 'admin_post_cardsco_campaign_schedule', array( $this, 'handle_schedule' ) );
		add_action( 'admin_post_cardsco_campaign_cancel', array( $this, 'handle_cancel' ) );
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * List or build.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @return void
	 */
	public function render( CardsCo_Admin $admin ) {
		$company_id  = $admin->current_company();
		$campaign_id = absint( $_GET['campaign'] ?? 0 );
		$campaign    = $campaign_id ? CardsCo_Campaign::get( $campaign_id ) : null;

		if ( $campaign ) {
			$company_id = (int) $campaign['company_id'];
			$step       = absint( $_GET['step'] ?? $campaign['builder_step'] );
			$step       = ( $step >= 1 && $step <= 7 ) ? $step : 1;

			$this->render_step( $admin, $campaign, $step, $company_id );
			return;
		}

		$this->render_list( $admin, $company_id );
	}

	/**
	 * A company's campaigns.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param int           $company_id Company ID.
	 * @return void
	 */
	protected function render_list( CardsCo_Admin $admin, $company_id ) {
		$campaigns = $company_id ? CardsCo_Campaign::for_company( $company_id ) : array();
		$statuses  = CardsCo_Campaign::statuses();

		include CARDSCO_PATH . 'includes/admin/views/campaigns-list.php';
	}

	/**
	 * One step of the builder.
	 *
	 * @param CardsCo_Admin $admin Admin helper.
	 * @param array         $campaign Campaign row.
	 * @param int           $step Step number.
	 * @param int           $company_id Company ID.
	 * @return void
	 */
	protected function render_step( CardsCo_Admin $admin, array $campaign, $step, $company_id ) {
		$steps    = CardsCo_Campaign::steps();
		$types    = CardsCo_Occasion_Type::all( $company_id );
		$segments = CardsCo_Segment::all( $company_id );

		// Cards and wording are offered for the occasion this campaign is
		// for, so a Christmas campaign is not offered a birthday card.
		$type_id  = (int) $campaign['occasion_type_id'];
		$cards    = CardsCo_Card::for_occasion( $type_id, $company_id );
		$messages = CardsCo_Message::for_occasion( $type_id, $company_id );
		$fields   = CardsCo_Merge::fields_for( true );
		$audience = CardsCo_Campaign::audience( $campaign );
		$picked   = CardsCo_Campaign::clean_audience( $campaign['audience'] );
		$preview  = CardsCo_Campaign::preview( $campaign );
		$ready    = CardsCo_Campaign::can_schedule( $campaign );

		include CARDSCO_PATH . 'includes/admin/views/campaign-step.php';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Start a campaign.
	 *
	 * @return void
	 */
	public function handle_start() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$created    = CardsCo_Campaign::create( $company_id, $name );

		if ( is_wp_error( $created ) ) {
			$this->go(
				array(
					'company'       => $company_id,
					'cardsco_error' => $created->get_error_message(),
				)
			);
		}

		$this->go(
			array(
				'campaign' => (int) $created,
				'step'     => 1,
			)
		);
	}

	/**
	 * Save a step and move on.
	 *
	 * @return void
	 */
	public function handle_step() {
		$this->verify();

		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		$step        = absint( $_POST['step'] ?? 1 );
		$campaign    = CardsCo_Campaign::get( $campaign_id );

		if ( ! $campaign ) {
			$this->go( array( 'cardsco_error' => __( 'That campaign could not be found.', 'cardsco' ) ) );
		}

		$data = $this->fields_for_step( $step );
		$done = CardsCo_Campaign::update( $campaign_id, $data );

		if ( is_wp_error( $done ) ) {
			$this->go(
				array(
					'campaign'      => $campaign_id,
					'step'          => $step,
					'cardsco_error' => $done->get_error_message(),
				)
			);
		}

		$next = min( 7, $step + 1 );

		// Remember how far they got, so the step bar knows what to unlock,
		// but never walk it backwards if they went back to change something.
		if ( $next > (int) $campaign['builder_step'] ) {
			CardsCo_Campaign::update( $campaign_id, array( 'builder_step' => $next ) );
		}

		$this->go(
			array(
				'campaign' => $campaign_id,
				'step'     => $next,
			)
		);
	}

	/**
	 * What each step submits.
	 *
	 * @param int $step Step number.
	 * @return array
	 */
	protected function fields_for_step( $step ) {
		switch ( (int) $step ) {
			case 1:
				return array(
					'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
					'occasion_type_id' => absint( $_POST['occasion_type_id'] ?? 0 ),
				);

			case 2:
				return array(
					'audience' => array(
						'segments'   => array_map( 'absint', (array) ( $_POST['segments'] ?? array() ) ),
						'recipients' => array_map( 'absint', (array) ( $_POST['recipients'] ?? array() ) ),
						'exclude'    => array_map( 'absint', (array) ( $_POST['exclude'] ?? array() ) ),
					),
				);

			case 3:
				return array( 'card_id' => absint( $_POST['card_id'] ?? 0 ) );

			case 4:
				return array(
					'message_template_id' => absint( $_POST['message_template_id'] ?? 0 ),
					'message_body'        => wp_unslash( $_POST['message_body'] ?? '' ),
				);

			case 5:
				return array(
					'offer_enabled'     => ! empty( $_POST['offer_enabled'] ),
					'offer_code'        => sanitize_text_field( wp_unslash( $_POST['offer_code'] ?? '' ) ),
					'offer_description' => sanitize_text_field( wp_unslash( $_POST['offer_description'] ?? '' ) ),
					'offer_expires'     => sanitize_text_field( wp_unslash( $_POST['offer_expires'] ?? '' ) ),
				);

			case 6:
				return array( 'send_date' => sanitize_text_field( wp_unslash( $_POST['send_date'] ?? '' ) ) );

			default:
				return array();
		}
	}

	/**
	 * Put it in the diary.
	 *
	 * @return void
	 */
	public function handle_schedule() {
		$this->verify();

		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		$done        = CardsCo_Campaign::schedule( $campaign_id );

		if ( is_wp_error( $done ) ) {
			$this->go(
				array(
					'campaign'      => $campaign_id,
					'step'          => 7,
					'cardsco_error' => implode( ' ', $done->get_error_messages() ),
				)
			);
		}

		$campaign = CardsCo_Campaign::get( $campaign_id );

		$this->go(
			array(
				'campaign'        => $campaign_id,
				'step'            => 7,
				'cardsco_message' => sprintf(
					/* translators: 1: number of cards, 2: send date. */
					__( 'Scheduled. %1$d cards will arrive on %2$s.', 'cardsco' ),
					(int) $campaign['recipient_count'],
					gmdate( 'j F Y', strtotime( $campaign['send_date'] ) )
				),
			)
		);
	}

	/**
	 * Stop one.
	 *
	 * @return void
	 */
	public function handle_cancel() {
		$this->verify();

		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		$campaign    = CardsCo_Campaign::get( $campaign_id );
		$company_id  = $campaign ? (int) $campaign['company_id'] : 0;
		$done        = CardsCo_Campaign::cancel( $campaign_id );

		$this->go(
			array(
				'company'         => $company_id,
				'cardsco_message' => is_wp_error( $done ) ? null : __( 'Campaign cancelled.', 'cardsco' ),
				'cardsco_error'   => is_wp_error( $done ) ? $done->get_error_message() : null,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

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
	 * Redirect back.
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
	 * A link to this screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args['page'] = self::PAGE;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
