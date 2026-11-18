<?php
/**
 * The front-end dashboard, as shortcodes to drop into Oxygen pages.
 *
 * Three of them for now:
 *
 *   [cardsco_dashboard]  the overview
 *   [cardsco_occasions]  what is running, and what is coming up
 *   [cardsco_campaigns]  one-off sends
 *
 * Orders joins them in segment 10, once there is an orders table to read.
 *
 * Every one of these starts by asking CardsCo_Access whether this person may
 * see anything at all, and renders a plain explanation rather than an empty
 * page if not. None of them take a company from the page or the URL.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Shortcodes {

	/**
	 * Register the shortcodes.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'cardsco_dashboard', array( $this, 'dashboard' ) );
		add_shortcode( 'cardsco_occasions', array( $this, 'occasions' ) );
		add_shortcode( 'cardsco_setup', array( $this, 'setup' ) );
		add_shortcode( 'cardsco_campaigns', array( $this, 'campaigns' ) );
		add_shortcode( 'cardsco_orders', array( $this, 'orders' ) );
		add_shortcode( 'cardsco_send', array( $this, 'send' ) );
		add_shortcode( 'cardsco_nav', array( $this, 'nav' ) );
		add_shortcode( 'cardsco_settings', array( $this, 'settings' ) );
		add_action( 'init', array( $this, 'handle_settings' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Load the tokens and the dashboard styles, but only on a page that
	 * actually uses one of these.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post ) {
			return;
		}

		$used = cardsco_page_uses_shortcode( $post, self::tags() );

		/**
		 * The last word on whether the dashboard styles load.
		 *
		 * cardsco_page_uses_shortcode() reads the post meta as well as
		 * post_content, so a page builder no longer defeats the check on its
		 * own. This stays as an escape hatch.
		 *
		 * @param bool    $used Whether to load the dashboard styles.
		 * @param WP_Post $post The post being viewed.
		 */
		$used = (bool) apply_filters( 'cardsco_load_dashboard_assets', $used, $post );

		if ( ! $used ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * The shortcodes this class answers to.
	 *
	 * @return string[]
	 */
	public static function tags() {
		return array( 'cardsco_dashboard', 'cardsco_occasions', 'cardsco_campaigns', 'cardsco_setup', 'cardsco_nav', 'cardsco_settings' );
	}

	/**
	 * Load the styles and script.
	 *
	 * Called from wp_enqueue_scripts to get them into the head, and again
	 * from each shortcode as it renders. The second call is what makes this
	 * correct rather than merely likely: a shortcode that ran brings its own
	 * stylesheet, whatever the page is built with.
	 *
	 * @return void
	 */
	public static function enqueue() {
		// Inter for the interface, EB Garamond for headings, as the design has.
		wp_enqueue_style(
			'cardsco-fonts',
			// Italic Garamond is for the lettering baked into the card-stack
			// drawing; Caveat is the handwriting in the card previews.
			'https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,500;1,500&family=Inter:wght@400;500;600&family=Caveat:wght@400;500&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'cardsco-tokens', CARDSCO_URL . 'assets/tokens.css', array( 'cardsco-fonts' ), CARDSCO_VERSION );
		wp_enqueue_style( 'cardsco-dashboard', CARDSCO_URL . 'assets/dashboard.css', array( 'cardsco-tokens' ), CARDSCO_VERSION );
		wp_enqueue_script( 'cardsco-nav', CARDSCO_URL . 'assets/nav.js', array(), CARDSCO_VERSION, true );

		/*
		 * boot.js holds the list the other scripts register with, so every
		 * one of them depends on it rather than on load order.
		 */
		wp_enqueue_script( 'cardsco-boot', CARDSCO_URL . 'assets/boot.js', array(), CARDSCO_VERSION, true );
		wp_enqueue_script( 'cardsco-wizard', CARDSCO_URL . 'assets/wizard.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * The screens
	 * ------------------------------------------------------------------ */

	/**
	 * The overview.
	 *
	 * @return string
	 */
	public function dashboard( $atts ) {
		self::enqueue();

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return self::locked( $refusal );
		}

		$atts = shortcode_atts(
			array(
				'campaigns' => home_url( '/campaigns/' ),
				'cards'     => '',
			),
			$atts,
			'cardsco_dashboard'
		);

		/*
		 * The toolbar reads its state out of the query string so a filtered
		 * view can be linked to and bookmarked, which a POST could not do.
		 * Everything taken from $_GET here is either matched against a known
		 * key or passed to a prepared statement.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state, no action taken.
		$views    = CardsCo_Dashboard::views();
		$view     = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
		$view     = isset( $views[ $view ] ) ? $view : 'all';
		$sort     = isset( $_GET['sort'] ) && 'soonest' === $_GET['sort'] ? 'soonest' : 'newest';
		$occasion = isset( $_GET['occasion'] ) ? sanitize_text_field( wp_unslash( $_GET['occasion'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$base = remove_query_arg( array( 'view', 'sort', 'occasion' ) );

		$summary   = CardsCo_Dashboard::summary();
		$held      = CardsCo_Dashboard::held( 5 );
		$sent_year = CardsCo_Dashboard::sent_this_year();
		$occasions = CardsCo_Dashboard::card_occasions();
		$counts    = CardsCo_Dashboard::view_counts();

		$cards = CardsCo_Dashboard::sends(
			array(
				'view'     => $view,
				'sort'     => $sort,
				'occasion' => $occasion,
				'limit'    => 24,
			)
		);

		$keep = array();

		if ( 'newest' !== $sort ) {
			$keep['sort'] = $sort;
		}

		if ( '' !== $occasion ) {
			$keep['occasion'] = $occasion;
		}

		$tabs = array();

		foreach ( $views as $key => $spec ) {
			$count = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;

			$tabs[ $key ] = array(
				/*
				 * The frame's first item is 36 wide with 10px side padding,
				 * which leaves room for one 16px glyph and nothing else — so
				 * "All" is the icon, and its count goes in the label a screen
				 * reader hears rather than on screen.
				 */
				'title' => 'all' === $key
					/* translators: %s: how many sends there are. */
					? sprintf( _n( 'All sends (%s)', 'All sends (%s)', $count, 'cardsco' ), number_format_i18n( $count ) )
					: $spec['label'],
				'label' => 'all' === $key ? '' : $spec['label'],
				'icon'  => 'all' === $key ? 'dashboard' : '',
				'count' => $count,
				'on'    => $key === $view,
				'url'   => add_query_arg( array_merge( $keep, 'all' === $key ? array() : array( 'view' => $key ) ), $base ),
			);
		}

		$toolbar = array(
			'base'           => $base,
			'views'          => $tabs,
			'occasion_label' => '' === $occasion ? __( 'All occasions', 'cardsco' ) : $occasion,
			'all_occasions'  => add_query_arg( array_diff_key( $keep, array( 'occasion' => '' ) ), $base ),
			'sort_label'     => 'soonest' === $sort ? __( 'Soonest first', 'cardsco' ) : __( 'Newest first', 'cardsco' ),
			'sort_url'       => add_query_arg(
				array_merge( $keep, array( 'sort' => 'soonest' === $sort ? 'newest' : 'soonest' ) ),
				$base
			),
			'new_campaign'   => add_query_arg( 'new', '1', $atts['campaigns'] ),
			'new_card'       => $atts['cards'] ? $atts['cards'] : add_query_arg( 'new', '1', $atts['campaigns'] ),
		);

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-dashboard.php';

		return (string) ob_get_clean();
	}

	/**
	 * The Settings screen.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function settings( $atts ) {
		self::enqueue();

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return $refusal;
		}

		$atts = shortcode_atts( array( 'logo' => '' ), $atts, 'cardsco_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'profile';
		$notice = isset( $_GET['cardsco_saved'] ) ? __( 'Saved.', 'cardsco' ) : '';
		$error  = isset( $_GET['cardsco_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cardsco_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_enqueue_script( 'cardsco-settings', CARDSCO_URL . 'assets/settings.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );

		$settings = CardsCo_Dashboard::settings_screen( $tab );
		$links    = array( 'logo' => $atts['logo'] ? $atts['logo'] : admin_url( 'upload.php' ) );

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-settings.php';

		return (string) ob_get_clean();
	}

	/**
	 * Save the Settings form.
	 *
	 * The company's name and billing address live on the company row;
	 * everything else is a company-scoped setting, which is where the
	 * validation lives. A value the model refuses stops the whole save
	 * rather than writing half of it.
	 *
	 * @return void
	 */
	/**
	 * The Team access panel's buttons.
	 *
	 * Each one acts on a person, so each one is checked against the person
	 * it names rather than against the panel being on screen: a form can be
	 * posted without the panel ever being drawn.
	 *
	 * @param int    $company_id Company.
	 * @param string $back       Where to go afterwards.
	 * @return bool True when this handled the request.
	 */
	protected function handle_team( $company_id, $back ) {
		$doing = '';
		$who   = 0;

		/* Each button names the action and carries the person as its value. */
		foreach ( array( 'invite', 'resend', 'revoke', 'remove', 'set_role' ) as $one ) {
			if ( ! empty( $_POST[ 'team_' . $one ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by the caller.
				$doing = $one;
				$who   = absint( $_POST[ 'team_' . $one ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by the caller.
				break;
			}
		}

		if ( ! $doing ) {
			return false;
		}

		$fail = static function ( $message ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( $message ), $back ) );
			exit;
		};

		$done = static function ( $message ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'cardsco_saved', rawurlencode( $message ), $back ) );
			exit;
		};

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked by the caller.
		$mail = isset( $_POST['team_email'] ) ? sanitize_email( wp_unslash( $_POST['team_email'] ) ) : '';

		/*
		 * The invite form has one role field; each row in the list has its
		 * own, keyed by person, so changing one person's role cannot carry
		 * somebody else's selection with it.
		 */
		if ( 'set_role' === $doing ) {
			$keyed = isset( $_POST['role_for'] ) && is_array( $_POST['role_for'] ) ? wp_unslash( $_POST['role_for'] ) : array();
			$role  = isset( $keyed[ $who ] ) ? sanitize_key( $keyed[ $who ] ) : 'member';
		} else {
			$role = isset( $_POST['team_role'] ) ? sanitize_key( wp_unslash( $_POST['team_role'] ) ) : 'member';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'invite' === $doing ) {
			$who = 0;
		}

		/* The owner is not on offer, so it cannot be granted from here. */
		$role = in_array( $role, array( 'admin', 'member' ), true ) ? $role : 'member';

		if ( 'invite' === $doing ) {
			if ( ! is_email( $mail ) ) {
				$fail( __( 'That does not look like an email address.', 'cardsco' ) );
			}

			$sent = CardsCo_Invite::send( $company_id, $mail, $role );

			if ( is_wp_error( $sent ) ) {
				$fail( $sent->get_error_message() );
			}

			$done(
				sprintf(
					/* translators: %s: an email address. */
					__( 'Invitation sent to %s.', 'cardsco' ),
					$mail
				)
			);
		}

		if ( ! $who ) {
			$fail( __( 'That person could not be found.', 'cardsco' ) );
		}

		if ( ! CardsCo_Access::may_manage_person( $who ) ) {
			$fail(
				$who === get_current_user_id()
					? __( 'You cannot change your own place on the account.', 'cardsco' )
					: __( 'That person cannot be changed from here.', 'cardsco' )
			);
		}

		if ( 'resend' === $doing ) {
			$out = CardsCo_Invite::resend( $company_id, $who );
			if ( is_wp_error( $out ) ) { $fail( $out->get_error_message() ); }
			$done( __( 'Invitation sent again.', 'cardsco' ) );
		}

		if ( 'revoke' === $doing ) {
			/*
			 * Withdrawing an invitation nobody accepted. This also deletes
			 * the half-made account behind it, which is right for somebody
			 * who never arrived and wrong for a colleague who did — hence
			 * the two paths below rather than one.
			 */
			$out = CardsCo_Invite::revoke( $company_id, $who );

			if ( is_wp_error( $out ) ) {
				$fail( $out->get_error_message() );
			}

			$done( __( 'Invitation withdrawn.', 'cardsco' ) );
		}

		if ( 'remove' === $doing ) {
			if ( ! CardsCo_Access::company_role( $who ) ) {
				$fail( __( 'They are not on this account.', 'cardsco' ) );
			}

			/*
			 * Unlinked from the company, not deleted: the person keeps
			 * their account, and their occasions and campaigns stay with
			 * the company and keep sending. What stops is their way in.
			 */
			CardsCo_Company::remove_user( $company_id, $who );

			$done( __( 'Removed from the account. Their occasions keep running.', 'cardsco' ) );
		}

		if ( 'set_role' === $doing ) {
			CardsCo_Company::add_user( $company_id, $who, $role, CardsCo_Access::company_role( $who ) ? 'active' : 'invited' );
			$done( __( 'Role changed.', 'cardsco' ) );
		}

		return true;
	}

	public function handle_settings() {
		if ( empty( $_POST['cardsco_settings_action'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_cardsco_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cardsco_nonce'] ) ), 'cardsco_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'cardsco_manage_company' ) ) {
			return;
		}

		$company_id = CardsCo_Access::company_id();

		if ( ! $company_id ) {
			return;
		}

		$back = remove_query_arg( array( 'cardsco_saved', 'cardsco_error' ) );

		/*
		 * Every company account holds the same WordPress capability, so the
		 * check above lets a member through. What they may actually change
		 * depends on their role within the company.
		 */
		if ( ! CardsCo_Access::may_manage_company() ) {
			wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( __( 'Only an owner or admin can change this.', 'cardsco' ) ), $back ) );
			exit;
		}

		$team = self::handle_team( $company_id, $back );

		if ( $team ) {
			return;
		}

		if ( ! empty( $_POST['remove_logo'] ) ) {
			CardsCo_Company::update( $company_id, array( 'logo_attachment_id' => 0 ) );
			wp_safe_redirect( add_query_arg( 'cardsco_saved', '1', $back ) );
			exit;
		}

		if ( ! empty( $_FILES['logo']['name'] ) ) {
			$logo = CardsCo_Logo::store( $_FILES['logo'], $company_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- store() validates the upload.

			if ( is_wp_error( $logo ) ) {
				wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( $logo->get_error_message() ), $back ) );
				exit;
			}

			CardsCo_Company::update( $company_id, array( 'logo_attachment_id' => (int) $logo ) );
		}

		$company = array();

		if ( isset( $_POST['name'] ) ) {
			$company['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );

			if ( '' === $company['name'] ) {
				wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( __( 'Your company needs a name.', 'cardsco' ) ), $back ) );
				exit;
			}
		}

		if ( isset( $_POST['billing_address'] ) ) {
			$company['billing_address'] = sanitize_textarea_field( wp_unslash( $_POST['billing_address'] ) );
		}

		$keys = array( 'trading_name', 'company_number', 'vat_number', 'return_mode', 'return_address', 'card_signoff' );
		$ours = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$clean = CardsCo_Settings::clean( $key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- clean() is the sanitiser.

			if ( is_wp_error( $clean ) ) {
				wp_safe_redirect( add_query_arg( 'cardsco_error', rawurlencode( $clean->get_error_message() ), $back ) );
				exit;
			}

			$ours[ $key ] = $clean;
		}

		if ( $company ) {
			CardsCo_Company::update( $company_id, $company );
		}

		foreach ( $ours as $key => $value ) {
			CardsCo_Settings::set( $key, $value, $company_id );
		}

		$who = wp_get_current_user();
		CardsCo_Settings::set( 'profile_saved_by', $who->display_name, $company_id );

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'company',
				'object_id'   => $company_id,
				'action'      => 'settings_saved',
				'summary'     => __( 'Company settings saved', 'cardsco' ),
			)
		);

		wp_safe_redirect( add_query_arg( 'cardsco_saved', '1', $back ) );
		exit;
	}
	/**
	 * What is running and what is coming up.
	 *
	 * @return string
	 */
	public function setup( $atts ) {
		self::enqueue();

		/*
		 * Moves between steps without reloading. Loaded only here, because
		 * it is the only screen built as a sequence of steps, and it falls
		 * back to an ordinary submit wherever it cannot help.
		 */
		wp_enqueue_script( 'cardsco-swap', CARDSCO_URL . 'assets/swap.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );

		/*
		 * The writer and the card preview belong to particular steps, and
		 * used to be loaded only on those steps. Now that a step can arrive
		 * without a page load, a script that was not in the first response
		 * is never there at all — the writer opened with a dead counter.
		 * Both are small and both return immediately when their markup is
		 * absent, so the wizard carries them throughout.
		 */
		wp_enqueue_script( 'cardsco-writer', CARDSCO_URL . 'assets/writer.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );
		wp_enqueue_script( 'cardsco-preview', CARDSCO_URL . 'assets/preview.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return self::locked( $refusal );
		}

		$atts = shortcode_atts(
			array(
				'exit'     => '',
				'people'   => '',
				'import'   => '',
				'template' => '',
				'writer'   => '',
			),
			$atts,
			'cardsco_setup'
		);

		$company_id = CardsCo_Access::company_id();
		$steps      = CardsCo_Setup::steps();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$asked_id = isset( $_GET[ CardsCo_Setup::ARG ] ) ? absint( $_GET[ CardsCo_Setup::ARG ] ) : 0;
		$asked_st = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 0;
		$error    = isset( $_GET['cardsco_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cardsco_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$here      = remove_query_arg( array( 'cardsco_error' ) );
		$programme = $asked_id ? CardsCo_Programme::get( $asked_id ) : null;

		// Somebody else's programme is simply not there.
		if ( $programme && (int) $programme['company_id'] !== $company_id ) {
			$programme = null;
		}

		/*
		 * Nothing started yet: step 1 runs against a stand-in rather than
		 * creating an empty programme row for anybody who merely looks at
		 * the page.
		 */
		if ( ! $programme ) {
			$programme = array(
				'id'         => 0,
				'company_id' => $company_id,
				'category'   => '',
				'setup_step' => 1,
				'card_id'    => 0,
			);
		}

		$step = CardsCo_Setup::step_on_screen( $programme, $asked_st );
		$body = CARDSCO_PATH . 'includes/front/view-setup-' . $step . '.php';

		/*
		 * Step 2 has a second screen behind it: adding one person by hand,
		 * rather than uploading a spreadsheet. It is a state of step 2, not a
		 * step of its own — the rail and the stepper still read "People".
		 *
		 * cc_add asks for the empty form; cc_added arrives on the redirect
		 * after a save and carries who was just added.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$add_person = isset( $_GET['cc_add'] ) && $_GET['cc_add'];
		$added      = isset( $_GET['cc_added'] ) ? absint( $_GET['cc_added'] ) : 0;
		// cc_person opens the same form on somebody already on the list.
		$editing    = isset( $_GET['cc_person'] ) ? absint( $_GET['cc_person'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$on_person = ( 2 === $step ) && ( $add_person || $added || $editing ) && $programme['id'];

		if ( $on_person ) {
			$body = CARDSCO_PATH . 'includes/front/view-setup-2-person.php';
		}

		/*
		 * Step 4 has the card writer behind it, the same way step 2 has the
		 * add-a-person form: a state of the step, not a step of its own.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$on_writer = ( 4 === $step ) && ! empty( $_GET['cc_write'] ) && $programme['id'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $on_writer ) {
			$body = CARDSCO_PATH . 'includes/front/view-setup-4-writer.php';
			wp_enqueue_script( 'cardsco-writer', CARDSCO_URL . 'assets/writer.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );
		}

		/*
		 * Once the occasion is live there is no step to be on: the wizard is
		 * finished and shows what it has done instead. Still reachable by
		 * asking for an earlier step, so somebody can go back and change
		 * something afterwards.
		 */
		$finished = 'active' === (string) ( $programme['status'] ?? '' ) && ! $asked_st;

		if ( $finished ) {
			$step = count( CardsCo_Setup::steps() );
			$body = CARDSCO_PATH . 'includes/front/view-setup-done.php';
		}

		// Only the steps that are built are reachable; the rest come later.
		if ( ! file_exists( $body ) ) {
			$step = 1;
			$body = CARDSCO_PATH . 'includes/front/view-setup-1.php';
		}

		$links = array(
			'here'     => $here,
			// The step on its own, with the add-a-person state dropped:
			// where Cancel goes, and what "Add another" builds on.
			'here_step' => add_query_arg(
				array( CardsCo_Setup::ARG => (int) $programme['id'], 'step' => $step ),
				remove_query_arg( array( 'cc_add', 'cc_added', 'cc_person', 'cardsco_error' ), $here )
			),
			'exit'     => $atts['exit'] ? $atts['exit'] : home_url( '/occasions/' ),
			/*
			 * "Add someone by hand" on the step 2 list. It used to be
			 * whatever URL the people attribute held, which by default was
			 * this page again — so the link went nowhere. It now opens the
			 * form, and the attribute is only a manual override.
			 */
			'people'   => $atts['people'] ? $atts['people'] : add_query_arg(
				array( CardsCo_Setup::ARG => (int) $programme['id'], 'step' => 2, 'cc_add' => 1 ),
				remove_query_arg( array( 'cc_add', 'cc_added', 'cc_person', 'cardsco_error' ), $here )
			),
			/*
			 * The upload is a form on the step itself, so there is no page
			 * to link to; the attribute stays only as an override for a site
			 * that wants to send people somewhere else entirely.
			 */
			'import'   => $atts['import'] ? $atts['import'] : $here,
			'template' => $atts['template']
				? $atts['template']
				: ( $programme['id'] ? CardsCo_Setup::template_url( $programme ) : $here ),
			/*
			 * The card writer, which is a state of step 4 rather than a page
			 * of its own, so there is nothing for the site owner to create.
			 * The attribute stays as a manual override.
			 */
			'writer'   => $atts['writer'] ? $atts['writer'] : add_query_arg(
				array( CardsCo_Setup::ARG => (int) $programme['id'], 'step' => 4, 'cc_write' => 1 ),
				remove_query_arg( array( 'cc_add', 'cc_added', 'cc_person', 'cc_write', 'cardsco_error' ), $here )
			),
		);

		$rail = CardsCo_Dashboard::setup_rail( $programme, $step );

		switch ( $step ) {
			case 1:
				$choices = CardsCo_Dashboard::setup_choices( $programme );
				$note    = CardsCo_Dashboard::setup_note( $programme );
				break;

			case 3:
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
				$asked_tab = isset( $_GET['cards'] ) ? sanitize_key( wp_unslash( $_GET['cards'] ) ) : 'occasion';
				$cards     = CardsCo_Dashboard::setup_cards( $programme, $asked_tab );

				// The card tiles each open the shared preview panel.
				wp_enqueue_script( 'cardsco-preview', CARDSCO_URL . 'assets/preview.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );
				break;

			case 4:
				if ( $on_writer ) {
					$writer = CardsCo_Dashboard::setup_writer( $programme );

					// The writer is the full width of the screen in the
					// frames; the rail would halve the card preview.
					$rail['hide_rail']     = true;
					$rail['hide_continue'] = true;
					$rail['heading']       = __( 'Write the card', 'cardsco' );
					$rail['blurb']         = __( 'The same message goes on every card, with each person’s name written in.', 'cardsco' );
					$rail['footer']        = sprintf(
						/* translators: %s: how many characters the card holds. */
						__( 'Up to %s characters', 'cardsco' ),
						number_format_i18n( (int) $writer['max'] )
					);
					break;
				}

				$message = CardsCo_Dashboard::setup_message( $programme );
				break;

			case 5:
				$shipping = CardsCo_Dashboard::setup_shipping( $programme );
				break;

			case 6:
				$timing = CardsCo_Dashboard::setup_timing( $programme );

				/*
				 * The step's own wording is written for a birthday — "we
				 * work back from each birthday" — which is wrong for the two
				 * shapes that have no per-person date.
				 */
				if ( 'fixed' === ( $timing['shape'] ?? '' ) ) {
					$rail['blurb']  = __( 'These fall on the same day for everyone, so pick the occasion and we work back from its date.', 'cardsco' );
					$rail['footer'] = $timing['lands']
						? sprintf(
							/* translators: %s: the date cards land. */
							__( 'Lands %s', 'cardsco' ),
							$timing['lands']
						)
						: __( 'No occasion picked yet', 'cardsco' );
					$rail['can_continue'] = (bool) $timing['chosen'];
				}

				if ( 'none' === ( $timing['shape'] ?? '' ) ) {
					$rail['blurb']  = __( 'Nothing sets the date for you here, so choose the day the cards should land.', 'cardsco' );
					$rail['footer'] = $timing['posts']
						? sprintf(
							/* translators: %s: the date the cards are posted. */
							__( 'Posted by %s', 'cardsco' ),
							$timing['posts']
						)
						: __( 'No date chosen yet', 'cardsco' );
					$rail['can_continue'] = (bool) $timing['lands'];
				}
				break;

			case 7:
				$review = CardsCo_Dashboard::setup_review( $programme, $links );

				if ( $finished ) {
					$done = CardsCo_Dashboard::setup_done( $programme );
				}
				break;

			default:
				if ( $on_person ) {
					$person = CardsCo_Dashboard::setup_person( $programme, $added, $editing );

					/*
					 * The frames put a running count where the rail's usual
					 * footer line goes, and drop Continue entirely while the
					 * form is open.
					 */
					$rail['footer'] = sprintf(
						/* translators: %s: how many people have been added. */
						_n( '%s person added', '%s people added', $person['total'], 'cardsco' ),
						number_format_i18n( $person['total'] )
					);

					$rail['hide_continue'] = ! $person['saved'];
					$rail['can_continue']  = $person['total'] > 0;

					// The frames retitle the screen; the stepper and the rail
					// still read People, because this is a state of step 2.
					if ( $person['edit'] ) {
						$rail['heading'] = $person['per_person']
							? sprintf(
								/* translators: %s: the occasion, e.g. "Birthday". */
								__( 'Add a %s', 'cardsco' ),
								cardsco_lcfirst( $person['noun'] )
							)
							: __( 'Edit this person', 'cardsco' );
						$rail['blurb']   = __( 'Change what we hold for somebody already on this occasion.', 'cardsco' );
					} else {
						$rail['heading'] = __( 'Add someone by hand', 'cardsco' );
						$rail['blurb']   = $person['saved']
							? sprintf(
								/* translators: %s: the person's name. */
								__( '%s has been added. Add another person, or carry on when you’re done.', 'cardsco' ),
								$person['saved']['name']
							)
							: __( 'Add one person at a time. You can add more straight after.', 'cardsco' );
					}
					break;
				}

				$people = CardsCo_Dashboard::setup_people( $programme );
				$counts = $people['counts'];
				$upload = $people['upload'];
				break;
		}

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-setup.php';

		return (string) ob_get_clean();
	}

	/**
	 * The occasions list.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function occasions( $atts ) {
		self::enqueue();

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return self::locked( $refusal );
		}

		$atts = shortcode_atts(
			array(
				'setup'     => '',
				'manage'    => '',
				'all_dates' => '',
			),
			$atts,
			'cardsco_occasions'
		);

		$cards = CardsCo_Dashboard::occasion_cards();

		/*
		 * The frame's panel holds four rows and then a link, so the list is
		 * cut there rather than scrolled.
		 */
		$upcoming = array();

		foreach ( CardsCo_Dashboard::upcoming( 30, 4 ) as $occasion ) {
			$stamp = strtotime( $occasion['next_occurrence'] );

            $upcoming[] = array(
				'day'    => $stamp ? gmdate( 'j', $stamp ) : '',
				'month'  => $stamp ? strtoupper( gmdate( 'M', $stamp ) ) : '',
				'who'    => $occasion['name'],
				'what'   => $occasion['label'],
				'status' => __( 'Queued', 'cardsco' ),
				'tone'   => 'info',
			);
		}

		$here  = remove_query_arg( array( 'occasion' ) );
		$links = array(
			'setup'     => $atts['setup'] ? $atts['setup'] : add_query_arg( 'new', '1', $here ),
			'manage'    => $atts['manage'] ? $atts['manage'] : $here,
			'all_dates' => $atts['all_dates'] ? $atts['all_dates'] : $here,
		);

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-occasions.php';

		return (string) ob_get_clean();
	}

	/**
	 * One-off sends.
	 *
	 * @return string
	 */
	public function campaigns( $atts ) {
		self::enqueue();

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return self::locked( $refusal );
		}

		$atts = shortcode_atts(
			array(
				'new'    => '',
				'single' => '',
				'one'    => '',
			),
			$atts,
			'cardsco_campaigns'
		);

		$here = remove_query_arg( array( 'tab' ) );

		/*
		 * The tab is in the query string so a filtered view can be linked to
		 * and bookmarked. It is matched against known keys before use.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'progress';
		$spec = CardsCo_Dashboard::campaign_tabs();
		$tab  = isset( $spec[ $tab ] ) ? $tab : 'progress';

		$counts = CardsCo_Dashboard::campaign_tab_counts();
		$tabs   = array();

		foreach ( $spec as $key => $one ) {
			$tabs[ $key ] = array(
				'label' => $one['label'],
				'count' => isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0,
				'on'    => $key === $tab,
				'url'   => 'progress' === $key ? $here : add_query_arg( 'tab', $key, $here ),
			);
		}

		$rows           = CardsCo_Dashboard::campaign_rows( $tab );
		$finished       = CardsCo_Dashboard::finished_campaigns( 4 );
		$finished_total = CardsCo_Dashboard::finished_count();

		/*
		 * The builder still lives in the admin, so each action takes an
		 * address. Without one the buttons come back to this page rather
		 * than pointing at nothing.
		 */
		$links = array(
			'new'    => $atts['new'] ? $atts['new'] : add_query_arg( 'new', '1', $here ),
			'single' => $atts['single'] ? $atts['single'] : add_query_arg( 'new', '1', $here ),
			'one'    => $atts['one'] ? $atts['one'] : $here,
			'past'   => add_query_arg( 'tab', 'past', $here ),
		);

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-campaigns.php';

		return (string) ob_get_clean();
	}

	/**
	 * The Orders screen.
	 *
	 * Reads the query string for its tab, search, page and window, so a
	 * filtered view can be linked to, bookmarked and sent to somebody else.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function orders( $atts ) {
		self::enqueue();
		wp_enqueue_script( 'cardsco-orders', CARDSCO_URL . 'assets/orders.js', array(), CARDSCO_VERSION, true );

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			return self::locked( $refusal );
		}

		$atts = shortcode_atts( array( 'one' => '' ), $atts, 'cardsco_orders' );

		$company_id = CardsCo_Access::company_id();
		$here       = remove_query_arg( array( 'tab', 'q', 'paged', 'months', 'per' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$months = isset( $_GET['months'] ) ? (int) $_GET['months'] : 12;
		$per    = isset( $_GET['per'] ) ? (int) $_GET['per'] : 10;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$tab    = in_array( $tab, CardsCo_Orders::KINDS, true ) ? $tab : 'all';
		$months = in_array( $months, array( 1, 3, 12, 0 ), true ) ? $months : 12;
		$per    = in_array( $per, array( 10, 25, 50 ), true ) ? $per : 10;

		$found = CardsCo_Orders::all(
			$company_id,
			array(
				'kind'   => $tab,
				'search' => $search,
				'months'   => $months,
				'page'     => $paged,
				'per_page' => $per,
			)
		);

		$stats = CardsCo_Orders::stats( $company_id );

		$tabs = array();

		foreach ( array_merge( array( 'all' ), CardsCo_Orders::KINDS ) as $key ) {
			$tabs[ $key ] = array(
				'label' => self::order_tab_label( $key ),
				'count' => (int) ( $found['counts'][ $key ] ?? 0 ),
				'on'    => $key === $tab,
				'url'   => 'all' === $key ? $here : add_query_arg( 'tab', $key, $here ),
			);
		}

		$windows = array(
			12 => __( 'Last 12 months', 'cardsco' ),
			3  => __( 'Last 3 months', 'cardsco' ),
			1  => __( 'This month', 'cardsco' ),
			0  => __( 'All time', 'cardsco' ),
		);

		$links = array(
			'here'    => $here,
			'one'     => $atts['one'] ? $atts['one'] : $here,
			'invoice' => CardsCo_Orders_Export::url( $tab, $search, $months ),
		);

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-orders.php';

		return (string) ob_get_clean();
	}

	/**
	 * What an orders tab is called.
	 *
	 * @param string $key Tab key.
	 * @return string
	 */
	protected static function order_tab_label( $key ) {
		$words = array(
			'all'       => __( 'All', 'cardsco' ),
			'recurring' => __( 'Recurring', 'cardsco' ),
			'campaign'  => __( 'Campaigns', 'cardsco' ),
			'bulk'      => __( 'Bulk', 'cardsco' ),
		);

		return $words[ $key ] ?? ucfirst( $key );
	}

	/**
	 * Sending one card.
	 *
	 * Public: no sign-in, no company. The half-written card lives in the
	 * WooCommerce session, which every visitor has.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function send( $atts ) {
		self::enqueue();
		wp_enqueue_script( 'cardsco-writer', CARDSCO_URL . 'assets/writer.js', array( 'cardsco-boot' ), CARDSCO_VERSION, true );

		$atts = shortcode_atts( array( 'cards' => '' ), $atts, 'cardsco_send' );

		if ( ! CardsCo_Send::available() ) {
			return '<div class="cc cc-send"><p class="cc-send-off">'
				. esc_html__( 'Sending a card is not available right now. Try again shortly.', 'cardsco' )
				. '</p></div>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$want  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		$error = isset( $_GET['cardsco_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cardsco_error'] ) ) : '';
		$pick  = isset( $_GET['card'] ) ? absint( $_GET['card'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/*
		 * Arriving from the catalogue with a card already chosen: keep it,
		 * so "Use this card" means what it says rather than dropping them
		 * at a chooser they have just come from.
		 */
		if ( $pick && CardsCo_Catalogue::is_sellable( $pick ) ) {
			CardsCo_Send::save( array( 'card_id' => $pick ) );
		}

		$draft = CardsCo_Send::draft();
		$step  = CardsCo_Send::step_on_screen( $draft, $want );
		$here  = remove_query_arg( array( 'step', 'cardsco_error', 'card' ) );

		/* The browsable catalogue, narrowed to a collection if one is named. */
		$cards = CardsCo_Catalogue::page(
			array(
				'filter' => $atts['cards'],
				'show'   => 24,
			)
		);

		$order = array(
			'card' => CardsCo_Send::card_price( $draft ),
		);

		$order['total'] = $order['card'];

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-send.php';

		return (string) ob_get_clean();
	}

	/**
	 * The sidebar.
	 *
	 * Takes the page addresses as attributes because only you know what you
	 * called the pages in Oxygen:
	 *
	 *   [cardsco_nav dashboard="/dashboard" occasions="/occasions"
	 *                campaigns="/campaigns" orders="/orders"
	 *                settings="/settings" help="/help"
	 *                logo="https://cardsco.uk/wp-content/uploads/logo.png"]
	 *
	 * Leave the addresses out and it guesses those four slugs off the site
	 * address. Leave the logo out and the wordmark is set in type instead.
	 *
	 * The footer icons — notifications, settings, help — each appear only when
	 * given an address, because an icon that goes nowhere is worse than none.
	 * The same goes for "upgrade", which the design hides for this audience.
	 * search="no" drops both magnifiers; collapsible="no" drops the collapse.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function nav( $atts ) {
		self::enqueue();

		$refusal = CardsCo_Access::refusal();

		if ( $refusal ) {
			// No menu for somebody who cannot see anything. The comment is
			// there so that "blank because it declined" can be told apart from
			// "blank because the shortcode never ran" — in a page builder those
			// look identical, and guessing between them wastes an afternoon.
			return '<!-- cardsco: sidebar hidden, this account has no dashboard access -->';
		}

		$atts = shortcode_atts(
			array(
				'dashboard'     => home_url( '/dashboard/' ),
				'occasions'     => home_url( '/occasions/' ),
				'campaigns'     => home_url( '/campaigns/' ),
				'orders'        => home_url( '/orders/' ),
				'settings'      => '',
				'notifications' => '',
				'help'          => '',
				'upgrade'       => '',
				'search'        => 'yes',
				'collapsible'   => 'yes',
				'recent'        => 4,
				'logo'          => '',
				'logo_mark'     => '',
				'logo_alt'      => get_bloginfo( 'name' ),
			),
			$atts,
			'cardsco_nav'
		);

		$items = array(
			array(
				'label' => __( 'Dashboard', 'cardsco' ),
				'url'   => $atts['dashboard'],
				'icon'  => 'dashboard',
				'count' => 0,
			),
			array(
				'label' => __( 'Occasions', 'cardsco' ),
				'url'   => $atts['occasions'],
				'icon'  => 'calendar',
				'count' => 0,
			),
			array(
				'label' => __( 'Campaigns', 'cardsco' ),
				'url'   => $atts['campaigns'],
				'icon'  => 'megaphone',
				'count' => CardsCo_Dashboard::campaigns_needing_review(),
			),
			array(
				'label' => __( 'Orders', 'cardsco' ),
				'url'   => $atts['orders'],
				'icon'  => 'package',
				'count' => 0,
			),
		);

		$search      = self::yes( $atts['search'] );
		$collapsible = self::yes( $atts['collapsible'] );

		// The footer only exists if there is something to put in it. An empty
		// bordered strip at the bottom of the sidebar looks like a bug.
		$has_foot = (bool) ( $atts['notifications'] || $atts['settings'] || $atts['help'] || $atts['upgrade'] );

		$recent = CardsCo_Dashboard::recent_campaigns( (int) $atts['recent'] );

		ob_start();
		include CARDSCO_PATH . 'includes/front/view-nav.php';

		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Helpers the views use
	 * ------------------------------------------------------------------ */

	/**
	 * One of the illustrations, inlined from the plugin's own SVG.
	 *
	 * Inlined rather than linked so the drawing inherits the page's fonts —
	 * the card-stack one sets "Thank you" in EB Garamond as live text, which
	 * an <img> would not resolve.
	 *
	 * @param string $name File stem in assets/img.
	 * @return string
	 */
	public static function art( $name ) {
		// Whitelisted: this reads a file off disk, so the name cannot be loose.
		$allowed = array( 'envelope-open', 'card-stack', 'empty-no-content' );

		if ( ! in_array( $name, $allowed, true ) ) {
			return '';
		}

		$path = CARDSCO_PATH . 'assets/img/' . $name . '.svg';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$svg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a file shipped with the plugin, not a remote fetch.

		return $svg;
	}

	/**
	 * The status badge for a send.
	 *
	 * Campaign statuses and card statuses are different vocabularies and both
	 * land on this grid, so they are mapped here rather than at the call site.
	 *
	 * @param array $send A row from CardsCo_Dashboard::sends().
	 * @return array{label:string, tone:string}
	 */
	public static function badge( array $send ) {
		$status = isset( $send['status'] ) ? $send['status'] : '';

		if ( isset( $send['kind'] ) && 'campaign' === $send['kind'] ) {
			/*
			 * Labels and tones read off the Campaigns frame, where Scheduled
			 * is deliberately neutral grey rather than blue, Draft is an
			 * outline with no fill at all, and the one labelled "Queued" is a
			 * locked campaign — cards cut and waiting to be written.
			 */
			$map = array(
				'draft'     => array( __( 'Draft', 'cardsco' ), 'outline' ),
				'scheduled' => array( __( 'Scheduled', 'cardsco' ), 'neutral' ),
				'locked'    => array( __( 'Queued', 'cardsco' ), 'info' ),
				'sent'      => array( __( 'Delivered', 'cardsco' ), 'success' ),
				'cancelled' => array( __( 'Cancelled', 'cardsco' ), 'neutral' ),
			);
		} else {
			$map = array(
				'queued'    => array( __( 'Queued', 'cardsco' ), 'info' ),
				'on_hold'   => array( __( 'On hold', 'cardsco' ), 'warning' ),
				'writing'   => array( __( 'Being written', 'cardsco' ), 'info' ),
				'written'   => array( __( 'Written', 'cardsco' ), 'success' ),
				'posted'    => array( __( 'Posted', 'cardsco' ), 'success' ),
				'delivered' => array( __( 'Delivered', 'cardsco' ), 'success' ),
				'cancelled' => array( __( 'Cancelled', 'cardsco' ), 'neutral' ),
				'failed'    => array( __( 'Failed', 'cardsco' ), 'error' ),
			);
		}

		if ( ! isset( $map[ $status ] ) ) {
			return array(
				'label' => ucfirst( str_replace( '_', ' ', (string) $status ) ),
				'tone'  => 'neutral',
			);
		}

		return array(
			'label' => $map[ $status ][0],
			'tone'  => $map[ $status ][1],
		);
	}

	/**
	 * The handwritten message as the card preview shows it.
	 *
	 * Splits a trailing signature line off so it can sit at the foot of the
	 * paper, the way the tile in the design does.
	 *
	 * @param array $send A row from CardsCo_Dashboard::sends().
	 * @return array{body:string, sign:string}
	 */
	public static function card_message( array $send ) {
		$text = isset( $send['message'] ) ? trim( (string) $send['message'] ) : '';

		if ( '' === $text ) {
			return array( 'body' => '', 'sign' => '' );
		}

		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' ) );

		if ( ! $lines ) {
			return array( 'body' => '', 'sign' => '' );
		}

		$sign = '';
		$last = end( $lines );

		// A signature is a short last line, usually opened with a dash.
		if ( count( $lines ) > 1 && ( preg_match( '/^[—–-]/u', $last ) || mb_strlen( $last ) <= 24 ) ) {
			$sign = array_pop( $lines );
		}

		$body = implode( ' ', $lines );

		if ( mb_strlen( $body ) > 90 ) {
			$body = mb_substr( $body, 0, 89 ) . '…';
		}

		return array( 'body' => $body, 'sign' => $sign );
	}

	/**
	 * The one-line description under a tile's title.
	 *
	 * @param array $send A row from CardsCo_Dashboard::sends().
	 * @return string
	 */
	public static function send_meta( array $send ) {
		$bits = array();

		if ( ! empty( $send['occasion'] ) ) {
			$bits[] = $send['occasion'];
		}

		if ( ! empty( $send['date'] ) ) {
			$stamp = strtotime( $send['date'] );

			if ( $stamp ) {
				$bits[] = 'campaign' === $send['kind']
					/* translators: %s: the date it posts. */
					? sprintf( __( 'posts %s', 'cardsco' ), gmdate( 'j M', $stamp ) )
					/* translators: %s: the date it arrives. */
					: sprintf( __( 'lands %s', 'cardsco' ), gmdate( 'j M', $stamp ) );
			}
		}

		if ( ! $bits ) {
			return __( 'No date yet', 'cardsco' );
		}

		return implode( ' · ', $bits );
	}

	/**
	 * What a campaign row's progress column says.
	 *
	 * The frame shows three shapes: a bar with a percentage while cards are
	 * being written, a bare "Scheduled" with an empty track, and a review
	 * warning with the label and caption in amber.
	 *
	 * @param array $row A row from CardsCo_Dashboard::campaign_rows().
	 * @return array{label:string, percent:string, note:string, attention:bool}
	 */
	public static function campaign_progress( array $row ) {
		$total   = (int) $row['total'];
		$written = (int) $row['written'];

		if ( 'draft' === $row['status'] ) {
			$note = $row['holds']
				? sprintf(
					/* translators: %s: how many recipients have no address. */
					_n(
						'%s recipient has no address · not scheduled yet',
						'%s recipients have no address · not scheduled yet',
						(int) $row['holds'],
						'cardsco'
					),
					number_format_i18n( (int) $row['holds'] )
				)
				: __( 'Not scheduled yet', 'cardsco' );

			return array(
				'label'     => __( 'Needs your review', 'cardsco' ),
				'percent'   => '',
				'note'      => $note,
				'attention' => true,
			);
		}

		if ( 'scheduled' === $row['status'] ) {
			$note = $row['send_date']
				? sprintf(
					/* translators: 1: the date it posts, 2: the last date it can be changed. */
					__( 'Posts %1$s · you can edit until %2$s', 'cardsco' ),
					gmdate( 'D j M', strtotime( $row['send_date'] ) ),
					gmdate( 'D j M', strtotime( $row['lock_date'] ? $row['lock_date'] : $row['send_date'] ) )
				)
				: __( 'Waiting on a date', 'cardsco' );

			return array(
				'label'     => __( 'Scheduled', 'cardsco' ),
				'percent'   => '',
				'note'      => $note,
				'attention' => false,
			);
		}

		$percent = $total > 0 ? (int) floor( ( $written / $total ) * 100 ) : 0;

		$note = $row['send_date']
			? sprintf(
				/* translators: 1: written so far, 2: total, 3: the date they post. */
				__( '%1$s of %2$s written · all post %3$s', 'cardsco' ),
				number_format_i18n( $written ),
				number_format_i18n( $total ),
				gmdate( 'D j M', strtotime( $row['send_date'] ) )
			)
			: sprintf(
				/* translators: 1: written so far, 2: total. */
				__( '%1$s of %2$s written', 'cardsco' ),
				number_format_i18n( $written ),
				number_format_i18n( $total )
			);

		return array(
			'label'     => 'sent' === $row['status'] ? __( 'Sent', 'cardsco' ) : __( 'Writing', 'cardsco' ),
			'percent'   => (string) $percent,
			'note'      => $note,
			'attention' => false,
		);
	}

	/**
	 * The button on a campaign row, which differs per state.
	 *
	 * @param array $row   A row from CardsCo_Dashboard::campaign_rows().
	 * @param array $links Page addresses.
	 * @return array{label:string, url:string, primary:bool}
	 */
	public static function campaign_action( array $row, array $links ) {
		$base = isset( $links['one'] ) ? $links['one'] : '';
		$url  = $base ? add_query_arg( 'campaign', (int) $row['id'], $base ) : '#';

		if ( 'draft' === $row['status'] ) {
			return array( 'label' => __( 'Review and schedule', 'cardsco' ), 'url' => $url, 'primary' => true );
		}

		if ( 'scheduled' === $row['status'] ) {
			return array( 'label' => __( 'Edit campaign', 'cardsco' ), 'url' => $url, 'primary' => false );
		}

		return array( 'label' => __( 'View progress', 'cardsco' ), 'url' => $url, 'primary' => false );
	}

	/**
	 * The middle line on a campaign row.
	 *
	 * "Thank you · Retail and Trade segments · 250 cards", as the frame has it.
	 *
	 * @param array $row A row from CardsCo_Dashboard::campaign_rows().
	 * @return string
	 */
	public static function campaign_meta( array $row ) {
		$bits = array();

		if ( $row['occasion'] ) {
			$bits[] = $row['occasion'];
		}

		if ( $row['audience'] ) {
			$bits[] = $row['audience'];
		}

		$bits[] = sprintf(
			/* translators: %s: number of cards. */
			_n( '%s card', '%s cards', (int) $row['total'], 'cardsco' ),
			number_format_i18n( (int) $row['total'] )
		);

		return implode( ' · ', $bits );
	}

	/**
	 * The offer, in the words the row uses.
	 *
	 * @param array $offer code and description.
	 * @return string
	 */
	public static function offer_words( array $offer ) {
		$description = isset( $offer['description'] ) ? trim( (string) $offer['description'] ) : '';
		$code        = isset( $offer['code'] ) ? trim( (string) $offer['code'] ) : '';

		if ( $description && $code ) {
			/* translators: 1: what the offer is, 2: the code. */
			return sprintf( __( '%1$s with code %2$s', 'cardsco' ), $description, $code );
		}

		if ( $description ) {
			return $description;
		}

		if ( $code ) {
			/* translators: %s: the offer code. */
			return sprintf( __( 'Code %s', 'cardsco' ), $code );
		}

		return __( 'Offer included', 'cardsco' );
	}

	/**
	 * Read a yes/no shortcode attribute.
	 *
	 * Oxygen users type all sorts of things, so "no", "false", "0" and "off"
	 * all mean no and anything else means yes.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function yes( $value ) {
		$value = strtolower( trim( (string) $value ) );

		return ! in_array( $value, array( 'no', 'false', '0', 'off', '' ), true );
	}

	/**
	 * Is this link the page we are on?
	 *
	 * Compares paths only, so it does not matter whether the attribute was
	 * given as a full address or just a slug.
	 *
	 * @param string $url The link.
	 * @return bool
	 */
	public static function is_here( $url ) {
		$here = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$here = trim( (string) wp_parse_url( esc_url_raw( $here ), PHP_URL_PATH ), '/' );
		$there = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

		return '' !== $there && $here === $there;
	}

	/**
	 * What a campaign is doing, in the sidebar's words.
	 *
	 * @param array $campaign Campaign row.
	 * @return array{text:string, tone:string}
	 */
	public static function campaign_line( array $campaign ) {
		$count = (int) $campaign['recipient_count'];

		switch ( $campaign['status'] ) {
			case 'draft':
				return array(
					'text' => __( 'Needs your review', 'cardsco' ),
					'tone' => 'warn',
				);

			case 'scheduled':
				return array(
					'text' => $campaign['send_date']
						/* translators: %s: the date it is due to arrive. */
						? sprintf( __( 'Scheduled · posts %s', 'cardsco' ), gmdate( 'j M', strtotime( $campaign['send_date'] ) ) )
						: __( 'Scheduled', 'cardsco' ),
					'tone' => '',
				);

			case 'locked':
				$written = CardsCo_Shortcodes::written_count( (int) $campaign['id'] );

				return array(
					'text' => sprintf(
						/* translators: 1: cards written, 2: cards in total. */
						__( 'Writing · %1$d of %2$d cards', 'cardsco' ),
						$written,
						$count
					),
					'tone' => '',
				);

			case 'sent':
				return array(
					'text' => sprintf(
						/* translators: %d: how many cards went out. */
						__( 'Delivered · %d cards', 'cardsco' ),
						$count
					),
					'tone' => 'good',
				);

			default:
				return array(
					'text' => __( 'Cancelled', 'cardsco' ),
					'tone' => '',
				);
		}
	}

	/**
	 * How many of a campaign's cards have been written.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int
	 */
	public static function written_count( $campaign_id ) {
		$done = 0;

		foreach ( CardsCo_Campaign::cards( $campaign_id ) as $card ) {
			if ( in_array( $card['status'], array( 'written', 'posted', 'delivered' ), true ) ) {
				$done++;
			}
		}

		return $done;
	}

	/**
	 * One of the small line icons, as inline SVG.
	 *
	 * @param string $name  Icon name.
	 * @param string $class Class on the <svg>, so the same path can be drawn at
	 *                      nav size or at action-button size.
	 * @return string
	 */
	public static function icon( $name, $class = 'cc-nav-icon' ) {
		/*
		 * These are Lucide (ISC licence), which is the set the design uses —
		 * the exported PNGs are named for their Lucide icons: layout-dashboard,
		 * calendar, megaphone, package, panel-left, search, bell, settings,
		 * circle-help. Drawn inline from the real paths rather than loaded as
		 * images, so they take the hover and active colour from currentColor
		 * and cost no extra requests. Lucide's own 24 viewBox and 2px stroke
		 * are kept, so they are pixel-identical to the exports.
		 */
		$paths = array(
			/* the four nav items */
			'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
			'calendar'  => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
			'megaphone' => '<path d="M11 6a13 13 0 0 0 8.4-2.8A1 1 0 0 1 21 4v12a1 1 0 0 1-1.6.8A13 13 0 0 0 11 14H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/><path d="M6 14a12 12 0 0 0 2.4 7.2 2 2 0 0 0 3.2-2.4A8 8 0 0 1 10 14"/><path d="M8 6v8"/>',
			'package'   => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
			'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',

			/* the header and footer actions, as the Side Bar frame has them */
			'search'    => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
			'collapse'  => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
			'bell'      => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
			'gear'      => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
			'help'      => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
			'dots'      => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
			'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'close'     => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
			'check'     => '<path d="M20 6 9 17l-5-5"/>',
			'alert'     => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
			'pen'       => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
			'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
			'box'       => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
			'eye'       => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
			'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
			'arrow-right'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
			'arrow-left'   => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
			'chevrons'     => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
			'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'building'     => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
			'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
			'arrow-right'  => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
			'arrow-left'   => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
			'chevrons'     => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
			'menu'      => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
			'spark'     => '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" '
			. 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. $paths[ $name ] . '</svg>';
	}

	/**
	 * The panel shown to somebody who cannot see the dashboard.
	 *
	 * @param string $message Why.
	 * @return string
	 */
	public static function locked( $message ) {
		return '<div class="cc cc-locked"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * A date, written the way a person would say it.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function nice_date( $date ) {
		if ( ! $date ) {
			return '—';
		}

		$stamp = strtotime( $date );

		return $stamp ? gmdate( 'D j M', $stamp ) : '—';
	}

	/**
	 * "Today", "Tomorrow", "in 4 days".
	 *
	 * @param int $days Days away.
	 * @return string
	 */
	public static function when( $days ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return __( 'Today', 'cardsco' );
		}

		if ( 1 === $days ) {
			return __( 'Tomorrow', 'cardsco' );
		}

		/* translators: %d: number of days. */
		return sprintf( __( 'in %d days', 'cardsco' ), $days );
	}

	/**
	 * Money, with the currency in front.
	 *
	 * @param string|float $amount Amount.
	 * @param string       $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency = 'GBP' ) {
		$symbols = array(
			'GBP' => '£',
			'USD' => '$',
			'EUR' => '€',
		);

		$symbol = $symbols[ $currency ] ?? ( $currency . ' ' );

		return $symbol . number_format( (float) $amount, 2 );
	}

	/**
	 * Why a card is held, in words a person can act on.
	 *
	 * @param string $reason Hold reason key.
	 * @return string
	 */
	public static function hold_reason( $reason ) {
		$reasons = CardsCo_Queue::hold_reasons();

		if ( isset( $reasons[ $reason ] ) ) {
			return $reasons[ $reason ];
		}

		return __( 'Needs attention', 'cardsco' );
	}
}
