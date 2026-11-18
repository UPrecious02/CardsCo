<?php
/**
 * Staff admin screens.
 *
 * These are CardsCo's own internal screens, not the company-facing dashboard.
 * The company dashboard is built on the front end in a later segment; this is
 * where CardsCo staff look at a company's data and where the build gets
 * tested before any of that exists.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Admin {

	const CAP      = 'cardsco_manage_all_companies';
	const MENU     = CARDSCO_MENU_SLUG;
	const NONCE    = 'cardsco_admin';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );

		add_action( 'admin_post_cardsco_save_company', array( $this, 'handle_save_company' ) );
		add_action( 'admin_post_cardsco_save_recipient', array( $this, 'handle_save_recipient' ) );
		add_action( 'admin_post_cardsco_delete_recipient', array( $this, 'handle_delete_recipient' ) );
		add_action( 'admin_post_cardsco_save_segment', array( $this, 'handle_save_segment' ) );
		add_action( 'admin_post_cardsco_delete_segment', array( $this, 'handle_delete_segment' ) );
		add_action( 'admin_post_cardsco_save_occasion_types', array( $this, 'handle_save_occasion_types' ) );
		add_action( 'admin_post_cardsco_add_occasion_type', array( $this, 'handle_add_occasion_type' ) );
		add_action( 'admin_post_cardsco_run_scan', array( $this, 'handle_run_scan' ) );
		add_action( 'admin_post_cardsco_import_upload', array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_cardsco_import_map', array( $this, 'handle_import_map' ) );
		add_action( 'admin_post_cardsco_import_occasions', array( $this, 'handle_import_occasions' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'CardsCo', 'cardsco' ),
			__( 'CardsCo', 'cardsco' ),
			self::CAP,
			self::MENU,
			array( $this, 'page_recipients' ),
			'dashicons-forms',
			26
		);

		add_submenu_page( self::MENU, __( 'Recipients', 'cardsco' ), __( 'Recipients', 'cardsco' ), self::CAP, self::MENU, array( $this, 'page_recipients' ) );
		add_submenu_page( self::MENU, __( 'Occasions', 'cardsco' ), __( 'Occasions', 'cardsco' ), self::CAP, CardsCo_Wizard::PAGE, array( $this, 'page_programmes' ) );
		add_submenu_page( self::MENU, __( 'Campaigns', 'cardsco' ), __( 'Campaigns', 'cardsco' ), self::CAP, CardsCo_Campaign_Builder::PAGE, array( $this, 'page_campaigns' ) );
		add_submenu_page( self::MENU, __( 'Segments', 'cardsco' ), __( 'Segments', 'cardsco' ), self::CAP, 'cardsco-segments', array( $this, 'page_segments' ) );
		add_submenu_page( self::MENU, __( 'Occasion library', 'cardsco' ), __( 'Occasion library', 'cardsco' ), self::CAP, 'cardsco-occasions', array( $this, 'page_occasions' ) );
		add_submenu_page( self::MENU, __( 'Card queue', 'cardsco' ), __( 'Card queue', 'cardsco' ), self::CAP, 'cardsco-queue', array( $this, 'page_queue' ) );
		add_submenu_page( self::MENU, __( 'Boxes', 'cardsco' ), __( 'Boxes', 'cardsco' ), self::CAP, CardsCo_Fulfilment::PAGE, array( $this, 'page_boxes' ) );
		add_submenu_page( self::MENU, __( 'Import', 'cardsco' ), __( 'Import', 'cardsco' ), self::CAP, 'cardsco-import', array( $this, 'page_import' ) );

		// The two post types are attached here by hand rather than through
		// show_in_menu. WordPress builds post type menus while assembling its
		// own, which is before admin_menu fires, so at that moment this parent
		// does not exist yet and the submenu is dropped without a word.
		add_submenu_page(
			self::MENU,
			__( 'Cards', 'cardsco' ),
			__( 'Cards', 'cardsco' ),
			'cardsco_manage_catalogue',
			'edit.php?post_type=' . CardsCo_Card::POST_TYPE
		);

		add_submenu_page(
			self::MENU,
			__( 'Messages', 'cardsco' ),
			__( 'Messages', 'cardsco' ),
			'cardsco_manage_catalogue',
			'edit.php?post_type=' . CardsCo_Message::POST_TYPE
		);

		add_submenu_page( self::MENU, __( 'Companies', 'cardsco' ), __( 'Companies', 'cardsco' ), self::CAP, 'cardsco-companies', array( $this, 'page_companies' ) );
	}

	/**
	 * Keep the CardsCo menu open and highlighted while editing a card or a
	 * message, which attaching the submenu by hand does not do on its own.
	 *
	 * @param string $parent Current parent file.
	 * @return string
	 */
	public function highlight_parent( $parent ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->post_type, array( CardsCo_Card::POST_TYPE, CardsCo_Message::POST_TYPE ), true ) ) {
			return self::MENU;
		}

		return $parent;
	}

	/**
	 * Highlight the right submenu item alongside it.
	 *
	 * @param string $submenu Current submenu file.
	 * @return string
	 */
	public function highlight_submenu( $submenu ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && in_array( $screen->post_type, array( CardsCo_Card::POST_TYPE, CardsCo_Message::POST_TYPE ), true ) ) {
			return 'edit.php?post_type=' . $screen->post_type;
		}

		return $submenu;
	}

	/**
	 * Inline styles for the admin screens. Small enough that a stylesheet
	 * file would cost more than it saves.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'cardsco' ) ) {
			return;
		}

		$css = '
		.cardsco-bar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:16px 0}
		.cardsco-bar form{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
		.cardsco-field{display:flex;flex-direction:column;gap:4px}
		.cardsco-field label{font-weight:600;font-size:12px}
		.cardsco-tiles{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
		.cardsco-tile{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 18px;min-width:120px}
		.cardsco-tile strong{display:block;font-size:22px;line-height:1.2}
		.cardsco-tile span{color:#646970;font-size:12px}
		.cardsco-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;background:#f0f0f1;color:#3c434a}
		.cardsco-pill.is-warn{background:#fcf0e4;color:#8a4b00}
		.cardsco-pill.is-good{background:#edfaef;color:#17641f}
		.cardsco-form{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:18px;max-width:780px}
		.cardsco-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
		.cardsco-grid .full{grid-column:1/-1}
		.cardsco-grid input[type=text],.cardsco-grid input[type=email],.cardsco-grid input[type=date],.cardsco-grid select,.cardsco-grid textarea{width:100%}
		.cardsco-empty{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:28px;text-align:center;color:#646970}
		.cardsco-steps{display:flex;gap:8px;flex-wrap:wrap;list-style:none;margin:16px 0 20px;padding:0}
		.cardsco-steps li{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #c3c4c7;border-radius:999px;padding:6px 16px 6px 6px;color:#646970;font-size:13px}
		.cardsco-steps li span{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#f0f0f1;font-size:12px;font-weight:600}
		.cardsco-steps li.is-now{border-color:#2271b1;color:#1d2327;font-weight:600}
		.cardsco-steps li.is-now span{background:#2271b1;color:#fff}
		.cardsco-steps li.is-done span{background:#17641f;color:#fff}
		.cardsco-progress{height:10px;border-radius:999px;background:#f0f0f1;overflow:hidden;margin:10px 0}
		.cardsco-progress span{display:block;height:100%;background:#2271b1;transition:width .2s ease}
		@media(max-width:782px){.cardsco-grid{grid-template-columns:1fr}.cardsco-steps li{flex:1 1 100%}}

		/* the occasion setup */
		.cardsco-wizard{max-width:1200px}
		.cardsco-wiz-top{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px 16px;margin:12px 0 18px}
		.cardsco-wiz-sub{color:#646970;font-size:12px}
		.cardsco-wiz-steps{display:flex;gap:4px;flex-wrap:wrap;list-style:none;margin:0;padding:0;font-size:12px}
		.cardsco-wiz-steps li a,.cardsco-wiz-static{display:flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;color:#646970;text-decoration:none}
		.cardsco-wiz-steps li a:hover{background:#f0f0f1}
		.cardsco-wiz-steps li span span,.cardsco-wiz-steps li a>span:first-child,.cardsco-wiz-static>span:first-child{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border-radius:50%;background:#f0f0f1;font-size:11px;font-weight:600}
		.cardsco-wiz-steps li.is-now .cardsco-wiz-static,.cardsco-wiz-steps li.is-now a{color:#1d2327;font-weight:600}
		.cardsco-wiz-steps li.is-now>*>span:first-child{background:#2271b1;color:#fff}
		.cardsco-wiz-steps li.is-done>*>span:first-child{background:#17641f;color:#fff}
		.cardsco-wiz-body{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start}
		.cardsco-wiz-main{min-width:0}
		.cardsco-wiz-side{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;position:sticky;top:46px}
		.cardsco-wiz-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid #dcdcde}
		.cardsco-wiz-foot form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
		.cardsco-stepline{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin:0 0 2px}
		.cardsco-stephead{font-size:clamp(20px,2.4vw,28px);margin:0 0 6px;font-weight:600}

		.cardsco-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
		.cardsco-cards-two{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
		.cardsco-choice{display:block;position:relative;background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px;cursor:pointer}
		.cardsco-choice:hover{border-color:#2271b1}
		.cardsco-choice.is-soon{opacity:.6;cursor:not-allowed}
		.cardsco-choice input{position:absolute;top:12px;right:12px}
		.cardsco-choice:has(input:checked){border-color:#17641f;box-shadow:0 0 0 1px #17641f}
		.cardsco-choice-wide{display:block}
		.cardsco-choice-body{display:block;padding-right:22px}
		.cardsco-choice-title{display:block;font-weight:600;margin-bottom:3px}
		.cardsco-choice-blurb{display:block;color:#50575e;font-size:13px;line-height:1.5}
		.cardsco-choice-meta{display:block;color:#646970;font-size:12px;margin-top:6px}
		.cardsco-choice-art{display:block;margin-bottom:10px}
		.cardsco-choice-art img{width:100%;height:auto;border-radius:4px;display:block}

        .cardsco-panel{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px}
		.cardsco-panel-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px}
		.cardsco-upload{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px}
		.cardsco-preview{background:#fffdf7;border:1px solid #e3dfd2;border-radius:4px;padding:14px 16px;font-family:Georgia,serif;font-size:15px;line-height:1.7;white-space:pre-wrap}

		.cardsco-review{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}
		.cardsco-review-row{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #f0f0f1}
		.cardsco-review-row:last-child{border-bottom:0}
		.cardsco-review-label{color:#646970;font-size:12px}
		.cardsco-review-main{font-weight:600}
		.cardsco-review-sub{color:#646970;font-size:12px;margin-top:2px}
		.cardsco-pay-row{display:flex;justify-content:space-between;gap:12px;padding:6px 0;font-size:13px}
		.cardsco-pay-row.is-total{border-top:1px solid #dcdcde;margin-top:6px;padding-top:10px;font-weight:600}

		.cardsco-side-row{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f0f0f1}
		.cardsco-side-row:last-of-type{border-bottom:0}
		.cardsco-side-dot{width:11px;height:11px;border-radius:50%;border:2px solid #c3c4c7;margin-top:4px;flex:none}
		.cardsco-side-dot.is-done{background:#17641f;border-color:#17641f}
		.cardsco-side-row.is-now .cardsco-side-label{font-weight:600;color:#1d2327}
		.cardsco-side-label{display:block;font-size:13px;color:#50575e}
		.cardsco-side-value{display:block;color:#646970;font-size:12px}
		.cardsco-side-price{border-top:1px solid #dcdcde;margin-top:12px;padding-top:12px}

		@media(max-width:1100px){.cardsco-wiz-body,.cardsco-review{grid-template-columns:1fr}.cardsco-wiz-side{position:static}}
		@media(max-width:782px){.cardsco-wiz-top{align-items:flex-start}.cardsco-wiz-steps{font-size:11px}.cardsco-wiz-foot{flex-direction:column;align-items:stretch}}

		/* The write list. Somebody reads this with a pen in one hand, so the
		   message is set large and the blocks are kept off page breaks. */
		.cardsco-write{max-width:860px}
		.cardsco-write-head{margin-bottom:24px}
		.cardsco-write-card{display:flex;gap:16px;border:1px solid #dcdcde;border-radius:8px;
			padding:18px 20px;margin:0 0 14px;background:#fff;break-inside:avoid;page-break-inside:avoid}
		.cardsco-write-no{flex:0 0 34px;height:34px;border-radius:50%;background:#f0f0f1;
			display:flex;align-items:center;justify-content:center;font-weight:600;color:#50575e}
		.cardsco-write-body{flex:1 1 auto;min-width:0}
		.cardsco-write-who{margin:0 0 2px;font-size:16px}
		.cardsco-write-who span{color:#646970;font-weight:400;margin-left:10px;font-size:14px}
		.cardsco-write-card-name{margin:0 0 10px;color:#646970;font-size:13px}
		.cardsco-write-message{font-size:17px;line-height:1.6;color:#1d2327;
			border-left:3px solid #dcdcde;padding-left:14px;white-space:normal}
		.cardsco-write-sign{margin:10px 0 0;font-size:15px;color:#50575e;font-style:italic}
		.cardsco-write-offer{margin:10px 0 0;font-size:15px;color:#1d2327}

		@media print{
			#adminmenumain,#wpadminbar,#wpfooter,.cardsco-write-hide,.notice{display:none!important}
			#wpcontent,#wpbody-content{margin:0!important;padding:0!important}
			.cardsco-write{max-width:none}
			.cardsco-write-card{border-color:#999}
		}
		';

		wp_register_style( 'cardsco-admin', false, array(), CARDSCO_VERSION );
		wp_enqueue_style( 'cardsco-admin' );
		wp_add_inline_style( 'cardsco-admin', $css );
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * Recipients screen.
	 *
	 * @return void
	 */
	public function page_recipients() {
		$this->guard();

		$company_id = $this->current_company();
		$action     = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';

		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$recipient = 'edit' === $action ? CardsCo_Recipient::get( absint( $_GET['recipient'] ?? 0 ) ) : null;

			if ( 'edit' === $action && ! $recipient ) {
				$this->notice( __( 'That recipient could not be found.', 'cardsco' ), 'error' );
				$action = 'list';
			} else {
				$segments = $company_id ? CardsCo_Segment::all( $company_id ) : array();
				$selected = $recipient ? CardsCo_Segment::for_recipient( (int) $recipient['id'] ) : array();

				include CARDSCO_PATH . 'includes/admin/views/recipient-form.php';
				return;
			}
		}

		$per_page = 25;
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$results = CardsCo_Recipient::query(
			array(
				'company_id'     => $company_id,
				'type'           => sanitize_key( $_GET['type'] ?? 'any' ),
				'status'         => sanitize_key( $_GET['status'] ?? 'active' ),
				'segment_id'     => absint( $_GET['segment'] ?? 0 ),
				'search'         => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'address_status' => sanitize_key( $_GET['address'] ?? '' ),
				'orderby'        => sanitize_key( $_GET['orderby'] ?? 'first_name' ),
				'order'          => sanitize_key( $_GET['order'] ?? 'asc' ),
				'per_page'       => $per_page,
				'paged'          => $paged,
			)
		);

		$counts   = $company_id ? CardsCo_Recipient::counts( $company_id ) : array(
			'total'         => 0,
			'customers'     => 0,
			'employees'     => 0,
			'needs_address' => 0,
		);
		$segments = $company_id ? CardsCo_Segment::all( $company_id ) : array();

		include CARDSCO_PATH . 'includes/admin/views/recipients.php';
	}

	/**
	 * Segments screen.
	 *
	 * @return void
	 */
	public function page_segments() {
		$this->guard();

		$company_id = $this->current_company();
		$segments   = $company_id ? CardsCo_Segment::all( $company_id ) : array();
		$editing    = isset( $_GET['segment'] ) ? CardsCo_Segment::get( absint( $_GET['segment'] ) ) : null;

		include CARDSCO_PATH . 'includes/admin/views/segments.php';
	}

	/**
	 * Occasions screen: what is coming up, and the occasion library.
	 *
	 * @return void
	 */
	public function page_occasions() {
		$this->guard();

		$company_id = $this->current_company();
		$tab        = isset( $_GET['tab'] ) && 'library' === $_GET['tab'] ? 'library' : 'upcoming';
		$days       = absint( $_GET['days'] ?? 60 );
		$days       = $days ? min( 365, $days ) : 60;
		$year       = absint( $_GET['year'] ?? 0 );
		$year       = $year ? $year : (int) substr( cardsco_now( 'date' ), 0, 4 );

		$upcoming = ( 'upcoming' === $tab && $company_id )
			? CardsCo_Occasion::upcoming(
				array(
					'company_id' => $company_id,
					'days'       => $days,
					'limit'      => 300,
				)
			)
			: array();

		$types        = CardsCo_Occasion_Type::all( $company_id, array( 'active_only' => false ) );
		$unconfirmed  = CardsCo_Occasion_Type::unconfirmed( $year, $company_id );

		include CARDSCO_PATH . 'includes/admin/views/occasions.php';
	}

	/**
	 * Save lead times, on/off switches and confirmed annual dates.
	 *
	 * @return void
	 */
	public function handle_save_occasion_types() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$year       = absint( $_POST['year'] ?? 0 );
		$problems   = array();

		foreach ( (array) ( $_POST['types'] ?? array() ) as $type_id => $values ) {
			$type_id = absint( $type_id );

			if ( ! $type_id ) {
				continue;
			}

			CardsCo_Occasion_Type::update(
				$type_id,
				array(
					'lead_days' => absint( $values['lead_days'] ?? 0 ),
					'is_active' => ! empty( $values['is_active'] ),
				)
			);

			// The standing card and wording belong to the company, not the
			// occasion, so they are stored per company rather than on the type.
			if ( $company_id && ( array_key_exists( 'card', $values ) || array_key_exists( 'message', $values ) ) ) {
				CardsCo_Scheduler::set_defaults(
					$company_id,
					$type_id,
					absint( $values['card'] ?? 0 ),
					absint( $values['message'] ?? 0 )
				);
			}

			if ( $year && array_key_exists( 'annual_date', $values ) ) {
				$type = CardsCo_Occasion_Type::get( $type_id );

				if ( $type && $type['requires_annual_date'] ) {
					$set = CardsCo_Occasion_Type::set_annual_date(
						$type['slug'],
						$year,
						sanitize_text_field( wp_unslash( $values['annual_date'] ) )
					);

					if ( is_wp_error( $set ) ) {
						$problems[] = $type['label'] . ': ' . implode( ' ', $set->get_error_messages() );
					}
				}
			}
		}

		$args = array(
			'company' => $company_id,
			'tab'     => 'library',
			'year'    => $year,
		);

		if ( $problems ) {
			$args['cardsco_error'] = implode( ' ', $problems );
		} else {
			$args['cardsco_message'] = __( 'Occasions saved.', 'cardsco' );
		}

		$this->redirect( 'cardsco-occasions', $args );
	}

	/**
	 * Add a company's own occasion type.
	 *
	 * @return void
	 */
	public function handle_add_occasion_type() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );

		$result = CardsCo_Occasion_Type::create(
			array(
				'company_id'  => $company_id,
				'label'       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
				'mode'        => sanitize_key( $_POST['mode'] ?? 'dated' ),
				'recurrence'  => sanitize_key( $_POST['recurrence'] ?? 'annual' ),
				'fixed_month' => absint( $_POST['fixed_month'] ?? 0 ),
				'fixed_day'   => absint( $_POST['fixed_day'] ?? 0 ),
				'applies_to'  => sanitize_key( $_POST['applies_to'] ?? 'both' ),
			)
		);

		$args = array(
			'company' => $company_id,
			'tab'     => 'library',
		);

		if ( is_wp_error( $result ) ) {
			$args['cardsco_error'] = implode( ' ', $result->get_error_messages() );
		} else {
			$args['cardsco_message'] = __( 'Occasion added.', 'cardsco' );
		}

		$this->redirect( 'cardsco-occasions', $args );
	}

	/**
	 * The occasion setup, handed to its own controller.
	 *
	 * @return void
	 */
	public function page_programmes() {
		$this->guard();

		$wizard = new CardsCo_Wizard();
		$wizard->render( $this );
	}

	/**
	 * Campaigns: the list and the seven step builder.
	 *
	 * @return void
	 */
	public function page_campaigns() {
		$this->guard();

		$builder = new CardsCo_Campaign_Builder();
		$builder->render( $this );
	}

	/**
	 * Fulfilment: boxes, the cards in them, and the write list.
	 *
	 * @return void
	 */
	public function page_boxes() {
		$this->guard();

		$fulfilment = new CardsCo_Fulfilment();
		$fulfilment->render( $this );
	}

	/**
	 * Card queue: every card waiting to be written, and why anything is held.
	 *
	 * @return void
	 */
	public function page_queue() {
		$this->guard();

		$company_id = $this->current_company();
		$per_page   = 50;
		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$results = CardsCo_Queue::query(
			array(
				'company_id' => $company_id,
				'status'     => sanitize_key( $_GET['status'] ?? 'open' ),
				'search'     => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
				'due_before' => sanitize_text_field( wp_unslash( $_GET['due'] ?? '' ) ),
				'orderby'    => sanitize_key( $_GET['orderby'] ?? 'write_by' ),
				'order'      => sanitize_key( $_GET['order'] ?? 'asc' ),
				'per_page'   => $per_page,
				'paged'      => $paged,
			)
		);

		$counts    = CardsCo_Queue::counts( $company_id );
		$last_scan = CardsCo_Scheduler::last_run();
		$needs     = $company_id ? CardsCo_Scheduler::needs_setup( $company_id ) : array();

		include CARDSCO_PATH . 'includes/admin/views/queue.php';
	}

	/**
	 * Run the scan by hand.
	 *
	 * @return void
	 */
	public function handle_run_scan() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );
		$summary    = CardsCo_Scheduler::run( array( 'company_id' => $company_id ) );

		$this->redirect(
			'cardsco-queue',
			array(
				'company'         => $company_id,
				'cardsco_message' => sprintf(
					/* translators: 1: occasions looked at, 2: cards queued, 3: cards held. */
					__( 'Looked at %1$d occasions: %2$d queued, %3$d held.', 'cardsco' ),
					(int) $summary['looked_at'],
					(int) $summary['queued'],
					(int) $summary['held']
				),
			)
		);
	}

	/**
	 * Import screen. Routes between the four steps.
	 *
	 * @return void
	 */
	public function page_import() {
		$this->guard();

		$company_id = $this->current_company();
		$step       = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload';
		$batch_id   = absint( $_GET['batch'] ?? 0 );
		$batch      = $batch_id ? CardsCo_Import::get( $batch_id ) : null;

		$steps = array(
			'upload'    => __( 'Upload', 'cardsco' ),
			'map'       => __( 'Match columns', 'cardsco' ),
			'occasions' => __( 'Set occasions', 'cardsco' ),
			'review'    => __( 'Check what we found', 'cardsco' ),
		);

		if ( ! $batch && in_array( $step, array( 'map', 'occasions', 'review', 'run', 'done' ), true ) ) {
			$this->notice( __( 'That import could not be found. Start again.', 'cardsco' ), 'error' );
			$step = 'upload';
		}

		if ( $batch ) {
			$company_id = (int) $batch['company_id'];
		}

		switch ( $step ) {
			case 'map':
			case 'occasions':
				$csv = CardsCo_Import::csv( $batch );

				if ( is_wp_error( $csv ) ) {
					$this->notice( $csv->get_error_message(), 'error' );
					$step = 'upload';
					break;
				}

				$headers = $csv->headers();
				$samples = $this->column_samples( $csv, $headers );
				break;

			case 'review':
				$report = CardsCo_Import::analyse( $batch_id );

				if ( is_wp_error( $report ) ) {
					$this->notice( $report->get_error_message(), 'error' );
					$step = 'upload';
				}
				break;

			case 'run':
				check_admin_referer( self::NONCE );

				$offset   = absint( $_GET['offset'] ?? 0 );
				$strategy = sanitize_key( $_GET['strategy'] ?? 'skip' );
				$result   = CardsCo_Import::commit_chunk( $batch_id, $offset, $strategy );

				if ( is_wp_error( $result ) ) {
					$this->notice( $result->get_error_message(), 'error' );
					$step = 'upload';
					break;
				}

				$batch = CardsCo_Import::get( $batch_id );
				break;
		}

		if ( 'upload' === $step ) {
			$history = $company_id ? CardsCo_Import::recent( $company_id ) : array();
		}

		include CARDSCO_PATH . 'includes/admin/views/import.php';
	}

	/**
	 * A few example values from each column, for the mapping screen.
	 *
	 * @param CardsCo_CSV $csv Reader.
	 * @param string[]    $headers Column names.
	 * @return array<string, string[]>
	 */
	protected function column_samples( CardsCo_CSV $csv, array $headers ) {
		$samples = array_fill_keys( $headers, array() );

		foreach ( $csv->records( 8 ) as $record ) {
			foreach ( $headers as $header ) {
				$value = trim( (string) ( $record['data'][ $header ] ?? '' ) );

				if ( '' !== $value && count( $samples[ $header ] ) < 3 ) {
					$samples[ $header ][] = $value;
				}
			}
		}

		return $samples;
	}

	/**
	 * Take the uploaded file and move to the mapping step.
	 *
	 * @return void
	 */
	public function handle_import_upload() {
		$this->verify();

		$company_id = absint( $_POST['company_id'] ?? 0 );

		$batch_id = CardsCo_Import::create_batch(
			$company_id,
			isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			sanitize_key( $_POST['recipient_type'] ?? 'customer' )
		);

		if ( is_wp_error( $batch_id ) ) {
			$this->redirect(
				'cardsco-import',
				array(
					'company'       => $company_id,
					'cardsco_error' => $batch_id->get_error_message(),
				)
			);
		}

		$this->redirect(
			'cardsco-import',
			array(
				'company' => $company_id,
				'step'    => 'map',
				'batch'   => $batch_id,
			)
		);
	}

	/**
	 * Save the column mapping and move to occasions.
	 *
	 * @return void
	 */
	public function handle_import_map() {
		$this->verify();

		$batch_id = absint( $_POST['batch_id'] ?? 0 );
		$batch    = CardsCo_Import::get( $batch_id );

		if ( ! $batch ) {
			$this->redirect( 'cardsco-import', array( 'cardsco_error' => __( 'That import could not be found.', 'cardsco' ) ) );
		}

		$fields    = array();
		$allowed   = array_keys( CardsCo_Import::fields() );
		$posted    = (array) ( $_POST['map'] ?? array() );
		$assigned  = array();

		foreach ( $posted as $header => $field ) {
			$header = sanitize_text_field( wp_unslash( $header ) );
			$field  = sanitize_key( $field );

			if ( '' === $field || ! in_array( $field, $allowed, true ) ) {
				continue;
			}

			// One column per field. If two columns claim the same field the
			// first wins, rather than the later one silently overwriting it.
			if ( isset( $assigned[ $field ] ) ) {
				continue;
			}

			$assigned[ $field ] = true;
			$fields[ $header ]  = $field;
		}

		$map           = $batch['column_map'];
		$map['fields'] = $fields;

		CardsCo_Import::update_batch(
			$batch_id,
			array(
				'column_map' => $map,
				'status'     => 'mapped',
			)
		);

		$this->redirect(
			'cardsco-import',
			array(
				'company' => (int) $batch['company_id'],
				'step'    => 'occasions',
				'batch'   => $batch_id,
			)
		);
	}

	/**
	 * Save the occasion columns, the date order and the segments to apply.
	 *
	 * @return void
	 */
	public function handle_import_occasions() {
		$this->verify();

		$batch_id = absint( $_POST['batch_id'] ?? 0 );
		$batch    = CardsCo_Import::get( $batch_id );

		if ( ! $batch ) {
			$this->redirect( 'cardsco-import', array( 'cardsco_error' => __( 'That import could not be found.', 'cardsco' ) ) );
		}

		$occasions = array();
		$used      = array();

		foreach ( (array) ( $_POST['occasions'] ?? array() ) as $header => $type_id ) {
			$header  = sanitize_text_field( wp_unslash( $header ) );
			$type_id = absint( $type_id );

			if ( ! $type_id || isset( $used[ $type_id ] ) ) {
				continue;
			}

			$type = CardsCo_Occasion_Type::get( $type_id );

			if ( ! $type || 'dated' !== $type['mode'] ) {
				continue;
			}

			$used[ $type_id ]       = true;
			$occasions[ $header ]   = $type_id;
		}

		$map               = $batch['column_map'];
		$map['occasions']  = $occasions;
		$map['date_order'] = 'month' === sanitize_key( $_POST['date_order'] ?? 'day' ) ? 'month' : 'day';
		$map['segments']   = array_filter( array_map( 'absint', (array) ( $_POST['segments'] ?? array() ) ) );

		CardsCo_Import::update_batch( $batch_id, array( 'column_map' => $map ) );

		$this->redirect(
			'cardsco-import',
			array(
				'company' => (int) $batch['company_id'],
				'step'    => 'review',
				'batch'   => $batch_id,
			)
		);
	}

	/**
	 * Companies screen.
	 *
	 * @return void
	 */
	public function page_companies() {
		$this->guard();

		$companies = CardsCo_Company::all( array( 'status' => 'any' ) );

		include CARDSCO_PATH . 'includes/admin/views/companies.php';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Create a company.
	 *
	 * @return void
	 */
	public function handle_save_company() {
		$this->verify();

		$result = CardsCo_Company::create(
			array(
				'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'contact_name'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
				'contact_email' => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
				'contact_phone' => sanitize_text_field( wp_unslash( $_POST['contact_phone'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'cardsco-companies', array( 'cardsco_error' => $result->get_error_message() ) );
		}

		$this->redirect( 'cardsco-companies', array( 'cardsco_message' => __( 'Company created.', 'cardsco' ) ) );
	}

	/**
	 * Create or update a recipient.
	 *
	 * @return void
	 */
	public function handle_save_recipient() {
		$this->verify();

		$recipient_id = absint( $_POST['recipient_id'] ?? 0 );
		$company_id   = absint( $_POST['company_id'] ?? 0 );

		$data = array(
			'company_id'         => $company_id,
			'type'               => sanitize_key( $_POST['type'] ?? 'customer' ),
			'first_name'         => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'          => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'email'              => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'              => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'recipient_company'  => sanitize_text_field( wp_unslash( $_POST['recipient_company'] ?? '' ) ),
			'job_title'          => sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) ),
			'address'            => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'postcode'           => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
			'country'            => sanitize_text_field( wp_unslash( $_POST['country'] ?? 'GB' ) ),
			'relationship_since' => sanitize_text_field( wp_unslash( $_POST['relationship_since'] ?? '' ) ),
			'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'segments'           => array_map( 'absint', (array) ( $_POST['segments'] ?? array() ) ),
			'allow_duplicate'    => ! empty( $_POST['allow_duplicate'] ),
		);

		$result = $recipient_id
			? CardsCo_Recipient::update( $recipient_id, $data )
			: CardsCo_Recipient::create( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				self::MENU,
				array(
					'company'       => $company_id,
					'action'        => $recipient_id ? 'edit' : 'new',
					'recipient'     => $recipient_id,
					'cardsco_error' => implode( ' ', $result->get_error_messages() ),
				)
			);
		}

		$saved_id = $recipient_id ? $recipient_id : (int) $result;
		$problems = $this->save_occasions( $saved_id, (array) ( $_POST['occasions'] ?? array() ) );

		$args = array(
			'company'         => $company_id,
			'cardsco_message' => $recipient_id ? __( 'Recipient updated.', 'cardsco' ) : __( 'Recipient added.', 'cardsco' ),
		);

		if ( $problems ) {
			// The person saved; only some dates did not. Say so plainly rather
			// than implying the whole save failed.
			$args['cardsco_error'] = implode( ' ', $problems );
		}

		$this->redirect( self::MENU, $args );
	}

	/**
	 * Save the occasion rows posted with a recipient.
	 *
	 * A row with a day and a month is saved, a row with neither is removed,
	 * and a half-filled row is reported rather than silently dropped.
	 *
	 * @param int   $recipient_id Recipient ID.
	 * @param array $posted Raw occasions input, keyed by type ID.
	 * @return string[] Problems to report.
	 */
	protected function save_occasions( $recipient_id, array $posted ) {
		$problems = array();

		foreach ( $posted as $type_id => $values ) {
			$type_id = absint( $type_id );

			if ( ! $type_id ) {
				continue;
			}

			$day   = absint( $values['day'] ?? 0 );
			$month = absint( $values['month'] ?? 0 );
			$year  = absint( $values['year'] ?? 0 );

			if ( ! $day && ! $month ) {
				CardsCo_Occasion::delete_by_type( $recipient_id, $type_id );
				continue;
			}

			$saved = CardsCo_Occasion::save(
				array(
					'recipient_id'     => $recipient_id,
					'occasion_type_id' => $type_id,
					'event_day'        => $day,
					'event_month'      => $month,
					'event_year'       => $year,
				)
			);

			if ( is_wp_error( $saved ) ) {
				$type       = CardsCo_Occasion_Type::get( $type_id );
				$label      = $type ? $type['label'] : __( 'Occasion', 'cardsco' );
				$problems[] = $label . ': ' . implode( ' ', $saved->get_error_messages() );
			}
		}

		return $problems;
	}

	/**
	 * Archive, restore or delete a recipient.
	 *
	 * @return void
	 */
	public function handle_delete_recipient() {
		$this->verify();

		$recipient_id = absint( $_REQUEST['recipient'] ?? 0 );
		$company_id   = absint( $_REQUEST['company'] ?? 0 );
		$mode         = sanitize_key( $_REQUEST['mode'] ?? 'archive' );

		if ( 'delete' === $mode ) {
			CardsCo_Recipient::delete( $recipient_id );
			$message = __( 'Recipient deleted.', 'cardsco' );
		} elseif ( 'restore' === $mode ) {
			CardsCo_Recipient::restore( $recipient_id );
			$message = __( 'Recipient restored.', 'cardsco' );
		} else {
			CardsCo_Recipient::archive( $recipient_id );
			$message = __( 'Recipient archived.', 'cardsco' );
		}

		$this->redirect(
			self::MENU,
			array(
				'company'         => $company_id,
				'cardsco_message' => $message,
			)
		);
	}

	/**
	 * Create or update a segment.
	 *
	 * @return void
	 */
	public function handle_save_segment() {
		$this->verify();

		$segment_id = absint( $_POST['segment_id'] ?? 0 );
		$company_id = absint( $_POST['company_id'] ?? 0 );

		$data = array(
			'company_id'  => $company_id,
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'description' => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'applies_to'  => sanitize_key( $_POST['applies_to'] ?? 'both' ),
		);

		$result = $segment_id
			? CardsCo_Segment::update( $segment_id, $data )
			: CardsCo_Segment::create( $data );

		$args = array( 'company' => $company_id );

		if ( is_wp_error( $result ) ) {
			$args['cardsco_error'] = implode( ' ', $result->get_error_messages() );
		} else {
			$args['cardsco_message'] = $segment_id ? __( 'Segment updated.', 'cardsco' ) : __( 'Segment created.', 'cardsco' );
		}

		$this->redirect( 'cardsco-segments', $args );
	}

	/**
	 * Delete a segment.
	 *
	 * @return void
	 */
	public function handle_delete_segment() {
		$this->verify();

		CardsCo_Segment::delete( absint( $_REQUEST['segment'] ?? 0 ) );

		$this->redirect(
			'cardsco-segments',
			array(
				'company'         => absint( $_REQUEST['company'] ?? 0 ),
				'cardsco_message' => __( 'Segment deleted.', 'cardsco' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The company currently being viewed.
	 *
	 * @return int
	 */
	public function current_company() {
		$company_id = absint( $_GET['company'] ?? 0 );

		if ( $company_id && CardsCo_Company::get( $company_id ) ) {
			return $company_id;
		}

		$companies = CardsCo_Company::all( array( 'limit' => 1 ) );

		return $companies ? (int) $companies[0]['id'] : 0;
	}

	/**
	 * Company switcher markup.
	 *
	 * @param int    $company_id Selected company.
	 * @param string $page Page slug to stay on.
	 * @return void
	 */
	public function company_switcher( $company_id, $page ) {
		$companies = CardsCo_Company::all( array( 'status' => 'any' ) );

		if ( count( $companies ) < 1 ) {
			return;
		}

		echo '<form method="get" class="cardsco-field">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
		echo '<label for="cardsco-company">' . esc_html__( 'Company', 'cardsco' ) . '</label>';
		echo '<select name="company" id="cardsco-company" onchange="this.form.submit()">';

		foreach ( $companies as $company ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $company['id'],
				selected( $company_id, (int) $company['id'], false ),
				esc_html( $company['name'] )
			);
		}

		echo '</select></form>';
	}

	/**
	 * Print any notice carried in the query string.
	 *
	 * @return void
	 */
	public function print_notices() {
		if ( ! empty( $_GET['cardsco_message'] ) ) {
			$this->notice( sanitize_text_field( wp_unslash( $_GET['cardsco_message'] ) ), 'success' );
		}

		if ( ! empty( $_GET['cardsco_error'] ) ) {
			$this->notice( sanitize_text_field( wp_unslash( $_GET['cardsco_error'] ) ), 'error' );
		}
	}

	/**
	 * Render a notice.
	 *
	 * @param string $message Message.
	 * @param string $type success | error | warning.
	 * @return void
	 */
	public function notice( $message, $type = 'success' ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Capability check for a page render.
	 *
	 * @return void
	 */
	protected function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'cardsco' ) );
		}
	}

	/**
	 * Capability and nonce check for a form submission.
	 *
	 * @return void
	 */
	protected function verify() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cardsco' ) );
		}

		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirect back to an admin page and stop.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 * @return void
	 */
	protected function redirect( $page, array $args = array() ) {
		$args['page'] = $page;

		// add_query_arg encodes for us, so nothing is pre-encoded here or the
		// message text would come back double escaped.
		$args = array_filter(
			$args,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
