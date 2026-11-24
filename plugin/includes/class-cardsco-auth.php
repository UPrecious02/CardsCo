<?php
/**
 * Signing in, signing up, resetting a password and accepting an invitation.
 *
 * All four live on the front end as shortcodes, so the pages can be built in
 * the page builder and still get real WordPress authentication underneath.
 *
 * Three decisions worth knowing about before reading the code:
 *
 * 1. A SIGN-UP CREATES A COMPANY. Everything in this plugin is scoped to one
 *    — recipients, occasions, programmes, the queue — and
 *    cardsco_get_user_company_id() returning 0 means a dashboard with
 *    nothing on it. So the four writes that make an account (user, role,
 *    company, membership) either all happen or none do; see signup().
 *
 * 2. A FAILED ATTEMPT DOES NOT REDIRECT. Nothing was written, so re-posting
 *    is harmless, and rendering the error in place keeps email addresses and
 *    failure reasons out of the URL and out of browser history. Only a
 *    success redirects.
 *
 * 3. NOTHING HERE EVER TOUCHES cardsco_log(). The activity log is readable in
 *    the admin, and a password or a reset key has no business in it.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The front-end account screens.
 */
class CardsCo_Auth {

	/**
	 * One nonce action per form, so a token minted for the login form cannot
	 * be replayed against sign-up.
	 */
	const NONCE_LOGIN  = 'cardsco_login';
	const NONCE_SIGNUP = 'cardsco_signup';
	const NONCE_RESET  = 'cardsco_reset';
	const NONCE_INVITE = 'cardsco_invite';

	/**
	 * How many failures before a lockout, and how long it lasts.
	 *
	 * WordPress core does not throttle sign-in at all, which leaves the live
	 * form open to being guessed against all day. bcrypt makes each guess
	 * expensive, but expensive is not the same as impossible.
	 */
	const MAX_ATTEMPTS = 5;
	const LOCKOUT      = 900;

	/**
	 * The shortest password accepted. Length beats cleverness, so this is the
	 * only rule — no character classes, which only push people towards
	 * Passw0rd! and a sticky note.
	 */
	const MIN_PASSWORD = 10;

	/**
	 * Whatever went wrong on this request, for the view to print.
	 *
	 * @var string
	 */
	protected static $error = '';

	/**
	 * What the person typed, so a failed form comes back filled in rather
	 * than blank. Passwords are never kept here.
	 *
	 * @var array
	 */
	protected static $posted = array();

	/* ---------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------ */

	/**
	 * Register the shortcodes and the handler.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( 'cardsco_login', array( $this, 'login_screen' ) );
		add_shortcode( 'cardsco_signup', array( $this, 'signup_screen' ) );
		add_shortcode( 'cardsco_reset', array( $this, 'reset_screen' ) );
		add_shortcode( 'cardsco_accept_invite', array( $this, 'invite_screen' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'home_to_dashboard' ), 2 );
	}

	/**
	 * The shortcodes this class answers to.
	 *
	 * @return string[]
	 */
	public static function tags() {
		return array( 'cardsco_login', 'cardsco_signup', 'cardsco_reset', 'cardsco_accept_invite' );
	}

	/**
	 * Styles and scripts, only on a page that uses one of these.
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
		 * The last word on whether the auth styles load.
		 *
		 * cardsco_page_uses_shortcode() handles page builders by scanning the
		 * post meta, so this is now only an escape hatch for a setup that
		 * stores content somewhere stranger still.
		 *
		 * @param bool    $used Whether to load the auth styles.
		 * @param WP_Post $post The post being viewed.
		 */
		$used = (bool) apply_filters( 'cardsco_load_auth_assets', $used, $post );

		if ( ! $used ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Load the styles and script.
	 *
	 * Called both from wp_enqueue_scripts, which gets them into the head, and
	 * from each shortcode as it renders. The second call is what makes this
	 * correct rather than merely likely: however a page builder stores its
	 * content, a shortcode that actually ran brings its own stylesheet with
	 * it. wp_enqueue_style() is idempotent, so the usual path costs nothing
	 * and the fallback lands in the footer rather than not at all.
	 *
	 * @return void
	 */
	public static function enqueue() {
		wp_enqueue_style(
			'cardsco-fonts-auth',
			'https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600&family=Inter:wght@400;500;600&family=Caveat:wght@400;500&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'cardsco-auth', CARDSCO_URL . 'assets/auth.css', array( 'cardsco-fonts-auth' ), CARDSCO_VERSION );
		wp_enqueue_script( 'cardsco-auth', CARDSCO_URL . 'assets/auth.js', array(), CARDSCO_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * The handler
	 * ------------------------------------------------------------------ */

	/**
	 * Send somebody already signed in to their own dashboard.
	 *
	 * Closing the tab does not sign anybody out, so the next visit to the
	 * front page would otherwise land them on the marketing home page as
	 * though they had never signed up.
	 *
	 * Only the front page, and only for somebody who belongs to a company:
	 * an administrator is left alone, because the home page is a page they
	 * have every reason to be looking at.
	 *
	 * @return void
	 */
	public static function home_to_dashboard() {
		$to = self::home_lands_on();

		if ( '' === $to ) {
			return;
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Where this visit to the front page should end up.
	 *
	 * Separate from the redirect itself because the redirect ends in exit,
	 * and a decision that can only be made by leaving cannot be tested at
	 * all. This answers the question; the caller acts on it.
	 *
	 * @return string A URL, or '' to stay where they are.
	 */
	public static function home_lands_on() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || ! is_front_page() ) {
			return '';
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return '';
		}

		/* A way to look at the home page on purpose. */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a view preference.
		if ( isset( $_GET['home'] ) ) {
			return '';
		}

		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return '';
		}

		if ( ! CardsCo_Access::company_id() ) {
			return '';
		}

		$to = CardsCo_Shortcodes::page_for( 'cardsco_dashboard' );

		if ( ! $to ) {
			return '';
		}

		/*
		 * If the dashboard is itself the front page there is nowhere to go,
		 * and sending them there would be a loop with no way out of it.
		 */
		if ( untrailingslashit( $to ) === untrailingslashit( home_url( '/' ) ) ) {
			return '';
		}

		return (string) $to;
	}

	/**
	 * Deal with whichever form was posted.
	 *
	 * Runs on template_redirect rather than init so that is_singular() and
	 * the queried post are settled, and so a redirect happens before a byte
	 * of the theme has been sent.
	 *
	 * @return void
	 */
	public function handle() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$action = isset( $_POST['cardsco_auth_action'] )
			? sanitize_key( wp_unslash( $_POST['cardsco_auth_action'] ) )
			: '';

		$nonces = array(
			'login'         => self::NONCE_LOGIN,
			'signup'        => self::NONCE_SIGNUP,
			'reset_request' => self::NONCE_RESET,
			'reset_save'    => self::NONCE_RESET,
			'invite_accept' => self::NONCE_INVITE,
			'resend_verify' => 'cardsco_resend_verify',
		);

		if ( ! isset( $nonces[ $action ] ) ) {
			return;
		}

		$nonce = isset( $_POST['_cardsco_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_cardsco_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $nonces[ $action ] ) ) {
			// Almost always an expired token on a form left open overnight,
			// so say that rather than accusing anybody of anything.
			self::$error = __( 'That form had been open too long. Please try again.', 'cardsco' );
			return;
		}

		switch ( $action ) {
			case 'login':
				$this->login();
				break;
			case 'signup':
				$this->signup();
				break;
			case 'reset_request':
				$this->reset_request();
				break;
			case 'reset_save':
				$this->reset_save();
				break;
			case 'invite_accept':
				$this->invite_accept();
				break;
			case 'resend_verify':
				$this->resend_verify();
				break;
		}
	}

	/* ---------------------------------------------------------------------
	 * Signing in
	 * ------------------------------------------------------------------ */

	/**
	 * Check an email and password, and let them in.
	 *
	 * @return void
	 */
	protected function login() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		self::$posted['email'] = $email;

		$blocked = self::locked_out( 'login', $email );

		if ( $blocked ) {
			self::$error = $blocked;
			return;
		}

		// wp_signon() is given the raw value deliberately: a password may
		// legitimately contain characters sanitising would eat, and it is
		// compared against a hash rather than stored or printed.
		$credentials = array(
			'user_login'    => $email,
			'user_password' => (string) ( $_POST['password'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'remember'      => ! empty( $_POST['remember'] ),
		);

		$user = wp_signon( $credentials, is_ssl() );

		if ( is_wp_error( $user ) ) {
			self::note_failure( 'login', $email );

			// One message for every kind of failure, so this form cannot be
			// used to find out which addresses have accounts.
			self::$error = __( 'That email and password did not match. Please try again.', 'cardsco' );
			return;
		}

		self::clear_failures( 'login', $email );
		wp_set_current_user( $user->ID );

		self::go( self::landing_for( $user->ID ) );
	}

	/* ---------------------------------------------------------------------
	 * Signing up
	 * ------------------------------------------------------------------ */

	/**
	 * Create a person, their company, and the link between the two.
	 *
	 * All four writes succeed together or the account is unwound. A user with
	 * no company reaches a dashboard where every query returns nothing, which
	 * looks far more like a broken product than an error message does.
	 *
	 * @return void
	 */
	protected function signup() {
		$first    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$company  = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = (string) ( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$terms    = ! empty( $_POST['terms'] );

		self::$posted = array(
			'first_name' => $first,
			'last_name'  => $last,
			'company'    => $company,
			'email'      => $email,
		);

		$problem = self::check_signup( $first, $company, $email, $password, $terms );

		if ( $problem ) {
			self::$error = $problem;
			return;
		}

		$made = self::make_account( $email, $password, $first, $last, $company );

		if ( is_wp_error( $made ) ) {
			self::$error = $made->get_error_message();
			return;
		}

		$user_id    = (int) $made['user_id'];
		$company_id = (int) $made['company_id'];

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );

		// Signed in first, confirmation chased afterwards. Making somebody
		// find a code before they see anything loses them at the moment they
		// were most interested; the banner and the gates do the work.
		CardsCo_Verify::send( $user_id );

		self::go( add_query_arg( 'cc_welcome', '1', self::landing_for( $user_id ) ) );
	}

	/**
	 * Everything that has to be true before an account is made.
	 *
	 * @param string $first    First name.
	 * @param string $company  Company name.
	 * @param string $email    Email address.
	 * @param string $password Raw password.
	 * @param bool   $terms    Whether the box was ticked.
	 * @return string A problem to show, or '' when it all checks out.
	 */
	protected static function check_signup( $first, $company, $email, $password, $terms ) {
		if ( '' === trim( $first ) ) {
			return __( 'Please give us your first name.', 'cardsco' );
		}

		if ( '' === trim( $company ) ) {
			return __( 'Please give us your company name — your dashboard is built around it.', 'cardsco' );
		}

		if ( ! is_email( $email ) ) {
			return __( 'That does not look like an email address.', 'cardsco' );
		}

		if ( email_exists( $email ) || username_exists( $email ) ) {
			return __( 'There is already an account on that email. Try signing in instead.', 'cardsco' );
		}

		if ( mb_strlen( $password ) < self::MIN_PASSWORD ) {
			return sprintf(
				/* translators: %d: the smallest number of characters allowed. */
				__( 'Please use a password of at least %d characters.', 'cardsco' ),
				self::MIN_PASSWORD
			);
		}

		if ( ! $terms ) {
			return __( 'Please agree to the terms before carrying on.', 'cardsco' );
		}

		return '';
	}

	/**
	 * Undo a half-made account.
	 *
	 * @param int $user_id    The user to remove.
	 * @param int $company_id The company to remove, if one got as far as being made.
	 * @return void
	 */
	/**
	 * An account somebody can actually use: a user, a role, and a company.
	 *
	 * All three or none. A user without the role cannot reach the dashboard,
	 * and one without a company reaches a dashboard scoped to nothing, so a
	 * half-made account is unwound rather than left for somebody to report.
	 *
	 * Shared because there are two doors into this. Signing up is one. The
	 * other is somebody who bought a single card as a guest and is then
	 * offered an account on the thank-you page — which promises they can see
	 * when the card is written and posted, send another, and set up cards
	 * that go out on their own. Every one of those lives behind the role and
	 * the company, and that door used to make a bare WordPress customer, so
	 * they could sign in and reach nothing at all. Two doors to the same
	 * room, so one way of building it.
	 *
	 * @param string $email    Address, which is also the username.
	 * @param string $password Chosen password.
	 * @param string $first    First name.
	 * @param string $last     Last name, if there is one.
	 * @param string $company  Company name.
	 * @return array{user_id: int, company_id: int}|WP_Error
	 */
	public static function make_account( $email, $password, $first, $last, $company ) {
		$user_id = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'cardsco_account', __( 'We could not create that account. Please try again.', 'cardsco' ) );
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
			)
		);

		$user = new WP_User( $user_id );
		$user->set_role( CardsCo_Roles::COMPANY_ROLE );

		$company_id = CardsCo_Company::create(
			array(
				'name'          => $company,
				'owner_user_id' => $user_id,
				'contact_name'  => trim( $first . ' ' . $last ),
				'contact_email' => $email,
			)
		);

		if ( is_wp_error( $company_id ) ) {
			self::unwind( $user_id );
			return $company_id;
		}

		/*
		 * Company::create() links the owner itself when it is given one, so
		 * this reads the link back rather than writing it twice.
		 */
		wp_cache_delete( $user_id, 'cardsco_user_company' );

		if ( cardsco_get_user_company_id( $user_id ) !== (int) $company_id ) {
			self::unwind( $user_id, $company_id );
			return new WP_Error( 'cardsco_account', __( 'We could not finish setting up your company. Please try again.', 'cardsco' ) );
		}

		return array(
			'user_id'    => (int) $user_id,
			'company_id' => (int) $company_id,
		);
	}

	protected static function unwind( $user_id, $company_id = 0 ) {
		global $wpdb;

		if ( $company_id ) {
			$wpdb->delete( cardsco_table( 'company_users' ), array( 'company_id' => (int) $company_id ), array( '%d' ) );
			$wpdb->delete( cardsco_table( 'companies' ), array( 'id' => (int) $company_id ), array( '%d' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $user_id );

		wp_cache_delete( (int) $user_id, 'cardsco_user_company' );
	}

	/* ---------------------------------------------------------------------
	 * Resetting a password
	 * ------------------------------------------------------------------ */

	/**
	 * Send somebody a link to set a new password.
	 *
	 * Uses core's own key machinery, which hashes the key before storing it
	 * and expires it on its own.
	 *
	 * @return void
	 */
	protected function reset_request() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		self::$posted['email'] = $email;

		$blocked = self::locked_out( 'reset', $email );

		if ( $blocked ) {
			self::$error = $blocked;
			return;
		}

		self::note_failure( 'reset', $email );

		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;

		if ( $user ) {
			$key = get_password_reset_key( $user );

			if ( ! is_wp_error( $key ) ) {
				self::send_reset_mail( $user, $key );
			}
		}

		// Away either way. Saying "no account on that address" here would
		// turn this form into a way of finding out who the client's staff
		// bank with, so the screen says the same thing whatever happened.
		self::go( add_query_arg( 'sent', '1', self::here() ) );
	}

	/**
	 * The email with the link in it.
	 *
	 * @param WP_User $user The person resetting.
	 * @param string  $key  The reset key.
	 * @return void
	 */
	protected static function send_reset_mail( $user, $key ) {
		$link = add_query_arg(
			array(
				'key'   => rawurlencode( $key ),
				'login' => rawurlencode( $user->user_login ),
			),
			self::here()
		);

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$body  = sprintf( __( 'Hello %s,', 'cardsco' ), $user->first_name ? $user->first_name : $user->display_name ) . "\n\n";
		$body .= __( 'Somebody asked to reset the password on your CardsCo account. Open this link to set a new one:', 'cardsco' ) . "\n\n";
		$body .= $link . "\n\n";
		$body .= __( 'The link works for one hour. If this was not you, ignore this email and nothing will change.', 'cardsco' ) . "\n";

		wp_mail(
			$user->user_email,
			/* translators: %s: the site name. */
			sprintf( __( 'Reset your %s password', 'cardsco' ), $site ),
			$body
		);
	}

	/**
	 * Save a new password against a valid key.
	 *
	 * @return void
	 */
	protected function reset_save() {
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
		$password = (string) ( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$confirm  = (string) ( $_POST['password_confirm'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			self::$error = __( 'That link has expired or has already been used. Please ask for another.', 'cardsco' );
			return;
		}

		if ( mb_strlen( $password ) < self::MIN_PASSWORD ) {
			self::$error = sprintf(
				/* translators: %d: the smallest number of characters allowed. */
				__( 'Please use a password of at least %d characters.', 'cardsco' ),
				self::MIN_PASSWORD
			);
			return;
		}

		if ( $password !== $confirm ) {
			self::$error = __( 'Those two passwords are not the same.', 'cardsco' );
			return;
		}

		// Clears the key and signs every other session out.
		reset_password( $user, $password );

		self::clear_failures( 'login', $user->user_email );
		self::clear_failures( 'reset', $user->user_email );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );

		self::go( self::landing_for( $user->ID ) );
	}

	/* ---------------------------------------------------------------------
	 * Accepting an invitation
	 * ------------------------------------------------------------------ */

	/**
	 * Join a company somebody has invited you to.
	 *
	 * An invited person already exists as a WordPress user with no usable
	 * password and a membership row that is not yet active, so the invitation
	 * link is core's own password-reset key. Accepting sets the password and
	 * turns the membership on.
	 *
	 * @return void
	 */
	protected function invite_accept() {
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
		$first    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$password = (string) ( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		self::$posted = array(
			'first_name' => $first,
			'last_name'  => $last,
		);

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			self::$error = __( 'That invitation has expired or has already been used. Ask whoever invited you to send another.', 'cardsco' );
			return;
		}

		$pending = self::pending_invite( $user->ID );

		if ( ! $pending ) {
			self::$error = __( 'That invitation is no longer open. Ask whoever invited you to send another.', 'cardsco' );
			return;
		}

		if ( '' === trim( $first ) ) {
			self::$error = __( 'Please give us your first name.', 'cardsco' );
			return;
		}

		if ( mb_strlen( $password ) < self::MIN_PASSWORD ) {
			self::$error = sprintf(
				/* translators: %d: the smallest number of characters allowed. */
				__( 'Please use a password of at least %d characters.', 'cardsco' ),
				self::MIN_PASSWORD
			);
			return;
		}

		reset_password( $user, $password );

		wp_update_user(
			array(
				'ID'           => $user->ID,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
			)
		);

		// Flips the membership to active and makes sure the role is on.
		CardsCo_Company::add_user( (int) $pending['company_id'], $user->ID, $pending['company_role'] );

		// They reached this page through a link sent to that address, which
		// is the same proof a confirmation email asks for. Sending another
		// would be asking them to do twice what they have already done.
		update_user_meta( $user->ID, CardsCo_Verify::DONE, cardsco_now() );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );

		self::go( self::landing_for( $user->ID ) );
	}

	/**
	 * The membership row waiting on somebody, if there is one.
	 *
	 * @param int $user_id The invited user.
	 * @return array|null
	 */
	public static function pending_invite( $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'company_users' ) . " WHERE user_id = %d AND status = 'invited' ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $user_id )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Send the confirmation email again.
	 *
	 * @return void
	 */
	protected function resend_verify() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$sent = CardsCo_Verify::send( $user_id );

		if ( is_wp_error( $sent ) ) {
			set_transient( 'cardsco_verify_said_' . $user_id, $sent->get_error_message(), 5 * MINUTE_IN_SECONDS );
			self::go( self::here() );
		}

		self::go( add_query_arg( 'cc_sent', '1', self::here() ) );
	}

	/* ---------------------------------------------------------------------
	 * Throttling
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this attempt should be turned away, and what to say if so.
	 *
	 * Counted against the address and against the caller's IP separately, so
	 * one person forgetting their password cannot lock out a whole office,
	 * and somebody working through a list of addresses still gets stopped.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $email The address being tried.
	 * @return string A message to show, or '' to carry on.
	 */
	protected static function locked_out( $kind, $email ) {
		$max   = (int) CardsCo_Settings::get( 'login_max_attempts', self::MAX_ATTEMPTS );
		$mins  = (int) CardsCo_Settings::get( 'login_lockout_mins', (int) ceil( self::LOCKOUT / 60 ) );
		$tries = (int) CardsCo_Settings::get( 'login_max_lockouts', 3 );

		/*
		 * Somebody who has been locked out three times is not a person who
		 * mistyped: either they have genuinely forgotten, or it is not their
		 * account. Offering another timer helps neither. So after the third
		 * the timer stops being the answer and they are sent to a reset or
		 * to us — which is also the point where a patient attacker stops
		 * getting a fresh five guesses every quarter of an hour.
		 */
		if ( self::strikes( $kind, $email ) >= $tries ) {
			return __( 'Too many failed attempts. Waiting will not help now — please reset your password, or get in touch and we will sort it out.', 'cardsco' );
		}

		foreach ( array( self::key( $kind, 'ip', self::ip() ), self::key( $kind, 'email', $email ) ) as $key ) {
			if ( (int) get_transient( $key ) >= $max ) {
				return sprintf(
					/* translators: %d: how many minutes to wait. */
					_n(
						'Too many attempts. Please wait %d minute and try again, or reset your password.',
						'Too many attempts. Please wait %d minutes and try again, or reset your password.',
						$mins,
						'cardsco'
					),
					$mins
				);
			}
		}

		return '';
	}

	/**
	 * How many separate lockouts this person has earned.
	 *
	 * Counted against the address rather than the caller, and kept for a day
	 * — long enough that three lockouts in a row cannot be waited out in an
	 * afternoon, short enough that somebody who genuinely forgot is not shut
	 * out for a week.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $email The address.
	 * @return int
	 */
	protected static function strikes( $kind, $email ) {
		return (int) get_transient( self::key( $kind, 'strikes', $email ) );
	}

	/**
	 * Record that a lockout has just begun.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $email The address.
	 * @return void
	 */
	protected static function note_lockout( $kind, $email ) {
		$key = self::key( $kind, 'strikes', $email );

		set_transient( $key, self::strikes( $kind, $email ) + 1, DAY_IN_SECONDS );
	}

	/**
	 * Count one failure.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $email The address being tried.
	 * @return void
	 */
	protected static function note_failure( $kind, $email ) {
		$max  = (int) CardsCo_Settings::get( 'login_max_attempts', self::MAX_ATTEMPTS );
		$mins = (int) CardsCo_Settings::get( 'login_lockout_mins', (int) ceil( self::LOCKOUT / 60 ) );
		$for  = max( 60, $mins * MINUTE_IN_SECONDS );

		foreach ( array( self::key( $kind, 'ip', self::ip() ), self::key( $kind, 'email', $email ) ) as $key ) {
			$now = (int) get_transient( $key ) + 1;
			set_transient( $key, $now, $for );

			// The failure that tips them over is the one that counts as a
			// lockout, so it is recorded once rather than on every try after.
			if ( $now === $max && str_contains( $key, 'email' ) ) {
				self::note_lockout( $kind, $email );
			}
		}
	}

	/**
	 * Forget the failures against an address once it is used successfully.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $email The address.
	 * @return void
	 */
	protected static function clear_failures( $kind, $email ) {
		delete_transient( self::key( $kind, 'ip', self::ip() ) );
		delete_transient( self::key( $kind, 'email', $email ) );
		delete_transient( self::key( $kind, 'strikes', $email ) );
	}

	/**
	 * A transient name. Hashed, so no address is stored in plain sight in
	 * the options table.
	 *
	 * @param string $kind  'login' or 'reset'.
	 * @param string $by    'ip' or 'email'.
	 * @param string $value The value being counted against.
	 * @return string
	 */
	protected static function key( $kind, $by, $value ) {
		return 'cardsco_try_' . $kind . '_' . $by . '_' . md5( strtolower( (string) $value ) . wp_salt() );
	}

	/**
	 * The caller's address.
	 *
	 * Only REMOTE_ADDR is trusted by default: X-Forwarded-For can be written
	 * by anybody, so believing it hands out a free way round the throttle.
	 * Sites behind a proxy can opt into it with the filter.
	 *
	 * @return string
	 */
	protected static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * The address to count attempts against.
		 *
		 * Behind Cloudflare the real caller is in CF-Connecting-IP; return
		 * that here, and only once you are sure traffic cannot reach the
		 * origin directly.
		 *
		 * @param string $ip The address from REMOTE_ADDR.
		 */
		return (string) apply_filters( 'cardsco_client_ip', $ip );
	}

	/* ---------------------------------------------------------------------
	 * Odds and ends
	 * ------------------------------------------------------------------ */

	/**
	 * Where to send somebody once they are in.
	 *
	 * Public because signing in is not the only way in: somebody who bought
	 * a card as a guest and then took the offer of an account lands in the
	 * same place, and should not land somewhere else by accident.
	 *
	 * @param int $user_id The person signing in.
	 * @return string
	 */
	public static function landing_for( $user_id ) {
		$to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( ! $to ) {
			$to = (string) CardsCo_Settings::get( 'dashboard_url', home_url( '/' ) );
		}

		/**
		 * Where a person lands after signing in.
		 *
		 * @param string $to      The URL.
		 * @param int    $user_id The person.
		 */
		return (string) apply_filters( 'cardsco_auth_landing', $to, $user_id );
	}

	/**
	 * The page being viewed, without any key or message already on it.
	 *
	 * @return string
	 */
	protected static function here() {
		$id = get_queried_object_id();

		if ( $id ) {
			return get_permalink( $id );
		}

		return home_url( add_query_arg( array() ) );
	}

	/**
	 * Send them on, and stop.
	 *
	 * @param string $url Where to.
	 * @return void
	 */
	protected static function go( $url ) {
		wp_safe_redirect( $url ? $url : home_url( '/' ) );
		exit;
	}

	/**
	 * Whatever went wrong, for the view.
	 *
	 * @return string
	 */
	public static function error() {
		return self::$error;
	}

	/**
	 * What was typed, so a failed form comes back filled in.
	 *
	 * @param string $field Which field.
	 * @return string
	 */
	public static function posted( $field ) {
		return isset( self::$posted[ $field ] ) ? (string) self::$posted[ $field ] : '';
	}

	/* ---------------------------------------------------------------------
	 * The screens
	 * ------------------------------------------------------------------ */

	/**
	 * Sign in.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function login_screen( $atts ) {
		$atts = shortcode_atts(
			array(
				'signup'    => '',
				'reset'     => '',
				'dashboard' => '',
				'logo'      => '',
			),
			$atts,
			'cardsco_login'
		);

		if ( is_user_logged_in() ) {
			return $this->already_in( $atts['dashboard'], $atts );
		}

		return $this->screen( 'view-login.php', array( 'links' => $atts ) );
	}

	/**
	 * Sign up.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function signup_screen( $atts ) {
		$atts = shortcode_atts(
			array(
				'login'     => '',
				'terms'     => '',
				'privacy'   => '',
				'dashboard' => '',
				'logo'      => '',
			),
			$atts,
			'cardsco_signup'
		);

		if ( is_user_logged_in() ) {
			return $this->already_in( $atts['dashboard'], $atts );
		}

		return $this->screen( 'view-signup.php', array( 'links' => $atts ) );
	}

	/**
	 * Reset, in its three states: ask, sent, and set a new one.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function reset_screen( $atts ) {
		$atts = shortcode_atts( array( 'login' => '', 'logo' => '' ), $atts, 'cardsco_reset' );

		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$user  = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';
		$stage = 'ask';

		if ( $key && $user ) {
			$stage = 'set';
		} elseif ( ! empty( $_GET['sent'] ) ) {
			$stage = 'sent';
		}

		// A failed save has to stay on the set-a-password screen, or the key
		// in the form is lost and they start the whole thing again.
		if ( self::$error && isset( $_POST['cardsco_auth_action'] ) && 'reset_save' === $_POST['cardsco_auth_action'] ) {
			$stage = 'set';
			$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : $key;
			$user  = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : $user;
		}

		return $this->screen(
			'view-reset.php',
			array(
				'links' => $atts,
				'stage' => $stage,
				'key'   => $key,
				'login' => $user,
			)
		);
	}

	/**
	 * Accept an invitation.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function invite_screen( $atts ) {
		$atts = shortcode_atts(
			array(
				'login'   => '',
				'terms'   => '',
				'privacy' => '',
				'logo'    => '',
			),
			$atts,
			'cardsco_accept_invite'
		);

		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ) ) : '';

		if ( isset( $_POST['cardsco_auth_action'] ) && 'invite_accept' === $_POST['cardsco_auth_action'] ) {
			$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : $key;
			$login = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : $login;
		}

		$user    = ( $key && $login ) ? check_password_reset_key( $key, $login ) : new WP_Error( 'cardsco_no_key', '' );
		$valid   = ! is_wp_error( $user );
		$pending = $valid ? self::pending_invite( $user->ID ) : null;
		$company = $pending ? CardsCo_Company::get( (int) $pending['company_id'] ) : null;

		return $this->screen(
			'view-invite.php',
			array(
				'links'   => $atts,
				'key'     => $key,
				'login'   => $login,
				'valid'   => (bool) ( $valid && $pending && $company ),
				'email'   => $valid ? $user->user_email : '',
				'company' => $company,
			)
		);
	}

	/**
	 * Somebody already signed in has no business on these pages.
	 *
	 * @param string $dashboard Where the dashboard lives.
	 * @return string
	 */
	protected function already_in( $dashboard, array $atts = array() ) {
		$to = $dashboard ? $dashboard : self::landing_for( get_current_user_id() );

		return $this->screen(
			'view-signed-in.php',
			array(
				'dashboard' => $to,
				// The shortcode's own logo wins, exactly as on every other
				// screen. Reading only the setting here meant a logo set per
				// page silently fell back to the drawn mark.
				'links'     => array( 'logo' => ! empty( $atts['logo'] ) ? (string) $atts['logo'] : (string) CardsCo_Settings::get( 'logo_url', '' ) ),
			)
		);
	}

	/**
	 * Render one of the views.
	 *
	 * @param string $file The view file.
	 * @param array  $vars What it needs.
	 * @return string
	 */
	protected function screen( $file, array $vars ) {
		$path = CARDSCO_PATH . 'includes/front/' . $file;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		// Whatever the page is built with, a screen that renders is styled.
		self::enqueue();

		$vars['error']  = self::$error;
		$vars['action'] = self::here();

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- the views are ours and the keys are set just above.
		extract( $vars );

		ob_start();
		include $path;

		return (string) ob_get_clean();
	}
}
