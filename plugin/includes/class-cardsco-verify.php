<?php
/**
 * Confirming somebody's email address.
 *
 * The shape of this was a product decision rather than a technical one.
 * Making people find a code before they can see anything costs you the
 * person at the moment they were most interested, so they are signed in
 * straight away and a banner follows them around until they confirm.
 *
 * But an unconfirmed address matters more here than on most products:
 * password resets, renewal reminders at one month, two weeks and five days,
 * and invoices all go to it. A typo means they lose the account and nobody
 * finds out for a year. So the two actions that commit something real —
 * turning a programme on, and paying — wait for confirmation. Everything
 * else is open.
 *
 * A link rather than a code, because a code means leaving the page, reading
 * six digits and typing them back. Codes earn that when the inbox is on a
 * different device, which is not the case for somebody signing up at a desk.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email confirmation.
 */
class CardsCo_Verify {

	/**
	 * On the user: a hash of the outstanding token.
	 */
	const TOKEN = '_cardsco_verify_token';

	/**
	 * On the user: when that token stops working.
	 */
	const EXPIRES = '_cardsco_verify_expires';

	/**
	 * On the user: when they confirmed, if they have.
	 */
	const DONE = '_cardsco_verified_at';

	/**
	 * On the user: the address the token was sent to.
	 *
	 * Recorded so that changing the address later cannot be confirmed by a
	 * link that was issued for the old one.
	 */
	const SENT_TO = '_cardsco_verify_email';

	/**
	 * How long a link lasts.
	 */
	const HOURS = 48;

	/**
	 * The shortest gap between one email and the next.
	 */
	const RESEND_SECONDS = 60;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'catch_link' ), 5 );
	}

	/**
	 * Whether somebody has confirmed their address.
	 *
	 * @param int $user_id User, or 0 for whoever is signed in.
	 * @return bool
	 */
	public static function is_verified( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		// Anybody who can run the site is not asked to prove their address.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return (bool) get_user_meta( $user_id, self::DONE, true );
	}

	/**
	 * Whether confirmation is being enforced at all.
	 *
	 * @return bool
	 */
	public static function required() {
		return (bool) CardsCo_Settings::get( 'verify_required', 1 );
	}

	/**
	 * Whether somebody may do something that commits money or post.
	 *
	 * @param int $user_id User, or 0 for whoever is signed in.
	 * @return bool
	 */
	public static function may_commit( $user_id = 0 ) {
		return ! self::required() || self::is_verified( $user_id );
	}

	/**
	 * Make a link and send it.
	 *
	 * @param int  $user_id User.
	 * @param bool $force   Skip the resend throttle.
	 * @return string|WP_Error The link, or why not.
	 */
	public static function send( $user_id, $force = false ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user ) {
			return new WP_Error( 'cardsco_no_user', __( 'That account does not exist.', 'cardsco' ) );
		}

		if ( self::is_verified( $user->ID ) ) {
			return new WP_Error( 'cardsco_already', __( 'That address is already confirmed.', 'cardsco' ) );
		}

		if ( ! $force ) {
			$last = (int) get_user_meta( $user->ID, self::TOKEN . '_at', true );

			if ( $last && ( time() - $last ) < self::RESEND_SECONDS ) {
				return new WP_Error(
					'cardsco_too_soon',
					__( 'We have just sent one. Give it a minute before asking again.', 'cardsco' )
				);
			}
		}

		$token = wp_generate_password( 32, false );

		// Only the hash is stored, so the database never holds a working
		// link — the same reason a password is not kept in plain text.
		update_user_meta( $user->ID, self::TOKEN, wp_hash( $token ) );
		update_user_meta( $user->ID, self::EXPIRES, time() + ( self::HOURS * HOUR_IN_SECONDS ) );
		update_user_meta( $user->ID, self::SENT_TO, $user->user_email );
		update_user_meta( $user->ID, self::TOKEN . '_at', time() );

		$link = add_query_arg(
			array(
				'cc_verify' => rawurlencode( $token ),
				'uid'       => (int) $user->ID,
			),
			self::landing()
		);

		self::mail( $user, $link );

		return $link;
	}

	/**
	 * Check a token and mark the address confirmed.
	 *
	 * @param int    $user_id User.
	 * @param string $token   The token from the link.
	 * @return true|WP_Error
	 */
	public static function confirm( $user_id, $token ) {
		$user = get_userdata( absint( $user_id ) );

		if ( ! $user || '' === (string) $token ) {
			return new WP_Error( 'cardsco_bad_link', __( 'That link is not valid.', 'cardsco' ) );
		}

		if ( self::is_verified( $user->ID ) ) {
			return true;
		}

		$stored = (string) get_user_meta( $user->ID, self::TOKEN, true );
		$until  = (int) get_user_meta( $user->ID, self::EXPIRES, true );

		if ( ! $stored || ! $until ) {
			return new WP_Error( 'cardsco_bad_link', __( 'That link has already been used. Ask for another.', 'cardsco' ) );
		}

		if ( time() > $until ) {
			return new WP_Error( 'cardsco_expired', __( 'That link has expired. Ask for another and we will send a fresh one.', 'cardsco' ) );
		}

		// Constant-time, so the comparison gives nothing away by how long
		// it takes to fail.
		if ( ! hash_equals( $stored, wp_hash( (string) $token ) ) ) {
			return new WP_Error( 'cardsco_bad_link', __( 'That link is not valid. Ask for another.', 'cardsco' ) );
		}

		// A link issued for one address must not confirm a different one.
		$sent_to = (string) get_user_meta( $user->ID, self::SENT_TO, true );

		if ( $sent_to && ! hash_equals( strtolower( $sent_to ), strtolower( $user->user_email ) ) ) {
			self::clear( $user->ID );

			return new WP_Error( 'cardsco_changed', __( 'Your address changed after that link was sent. Ask for a new one.', 'cardsco' ) );
		}

		update_user_meta( $user->ID, self::DONE, cardsco_now() );
		self::clear( $user->ID );

		cardsco_log(
			array(
				'company_id'  => cardsco_get_user_company_id( $user->ID ),
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'action'      => 'email_verified',
				'summary'     => __( 'Email address confirmed', 'cardsco' ),
			)
		);

		return true;
	}

	/**
	 * Forget the outstanding token.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function clear( $user_id ) {
		delete_user_meta( $user_id, self::TOKEN );
		delete_user_meta( $user_id, self::EXPIRES );
		delete_user_meta( $user_id, self::SENT_TO );
	}

	/**
	 * Deal with somebody arriving on a confirmation link.
	 *
	 * @return void
	 */
	public static function catch_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token in the link is the proof.
		if ( empty( $_GET['cc_verify'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$token   = sanitize_text_field( wp_unslash( $_GET['cc_verify'] ) );
		$user_id = absint( wp_unslash( $_GET['uid'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$done = self::confirm( $user_id, $token );

		$to = add_query_arg(
			is_wp_error( $done ) ? array( 'cc_verified' => '0' ) : array( 'cc_verified' => '1' ),
			remove_query_arg( array( 'cc_verify', 'uid' ) )
		);

		if ( is_wp_error( $done ) ) {
			set_transient( 'cardsco_verify_said_' . $user_id, $done->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Whatever the last attempt had to say.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function last_message( $user_id ) {
		$key  = 'cardsco_verify_said_' . absint( $user_id );
		$said = (string) get_transient( $key );

		if ( $said ) {
			delete_transient( $key );
		}

		return $said;
	}

	/**
	 * Where a confirmation link lands.
	 *
	 * @return string
	 */
	protected static function landing() {
		$to = (string) CardsCo_Settings::get( 'dashboard_url', '' );

		return $to ? $to : home_url( '/' );
	}

	/**
	 * The email.
	 *
	 * @param WP_User $user The person.
	 * @param string  $link Where they confirm.
	 * @return bool
	 */
	protected static function mail( $user, $link ) {
		$name = $user->first_name ? $user->first_name : $user->display_name;

		$body  = sprintf( /* translators: %s: their first name. */ __( 'Hello %s,', 'cardsco' ), $name ) . "\n\n";
		$body .= __( 'Please confirm this is your address so we can send you reminders before your cards go out, and reach you if anything needs sorting.', 'cardsco' ) . "\n\n";
		$body .= $link . "\n\n";
		$body .= sprintf(
			/* translators: %d: how many hours the link lasts. */
			__( 'The link works for %d hours. If you did not sign up to CardsCo, ignore this email.', 'cardsco' ),
			self::HOURS
		) . "\n";

		return (bool) wp_mail(
			$user->user_email,
			__( 'Confirm your email address', 'cardsco' ),
			$body
		);
	}
}
