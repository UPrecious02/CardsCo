<?php
/**
 * Who is looking, and what they are allowed to see.
 *
 * Every front-end query goes through here. The rule is simple and absolute:
 * the company is decided by who is logged in, never by anything in the
 * request. A URL cannot widen what somebody can see.
 *
 * There is one exception, and it is capability-gated: CardsCo's own staff,
 * who hold cardsco_manage_all_companies, can look at a named company in order
 * to help them. Nobody else can, and a company user passing the same
 * parameter is ignored entirely rather than refused, because refusing tells
 * them the parameter means something.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Access {

	/**
	 * The company whose data this request may touch.
	 *
	 * Deliberately does NOT use cardsco_current_company_id(): that one runs
	 * through a filter the admin sets while staff browse, and a filter is
	 * exactly the kind of thing that could be hooked from elsewhere and widen
	 * a front-end page without anybody noticing.
	 *
	 * @return int Company ID, or 0 when the viewer has no business here.
	 */
	public static function company_id() {
		if ( ! is_user_logged_in() ) {
			return 0;
		}

		// The capability is checked here, not only in can_view(), so that
		// revoking somebody's access shuts the data off at the source rather
		// than relying on every screen to ask first. Somebody whose role was
		// taken away keeps their row in company_users, and for a while this
		// returned their old company to anything that asked.
		if ( ! current_user_can( 'cardsco_access_dashboard' ) ) {
			return 0;
		}

		$own = cardsco_get_user_company_id();

		// Staff looking at a company to help them. Capability first, and the
		// company has to be real.
		if ( current_user_can( 'cardsco_manage_all_companies' ) ) {
			$asked = isset( $_GET['company'] ) ? absint( $_GET['company'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			if ( $asked && CardsCo_Company::get( $asked ) ) {
				return $asked;
			}
		}

		return $own;
	}

	/**
	 * Can this viewer see a dashboard at all?
	 *
	 * @return bool
	 */
	public static function can_view() {
		if ( ! is_user_logged_in() || ! current_user_can( 'cardsco_access_dashboard' ) ) {
			return false;
		}

		return self::company_id() > 0;
	}

	/**
	 * Why somebody cannot see the dashboard, in words they can act on.
	 *
	 * @return string Empty when they can.
	 */
	public static function refusal() {
		if ( ! is_user_logged_in() ) {
			return __( 'Please sign in to see your dashboard.', 'cardsco' );
		}

		if ( ! current_user_can( 'cardsco_access_dashboard' ) ) {
			return __( 'This account does not have access to the dashboard. Ask whoever set it up to give you access.', 'cardsco' );
		}

		if ( ! self::company_id() ) {
			return __( 'This account is not linked to a company yet. Get in touch and we will sort it out.', 'cardsco' );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Ownership
	 * ------------------------------------------------------------------ */

	/**
	 * Tables this class will look in, and nothing else.
	 *
	 * A whitelist rather than a free table name, so a caller can never reach
	 * a table by passing a clever string.
	 *
	 * @return string[]
	 */
	protected static function tables() {
		// 'orders' joins this list in segment 10, when the table exists. A
		// name here with no table behind it would fail as a silent empty
		// result rather than an error, which is the worst of both.
		return array( 'programmes', 'campaigns', 'boxes', 'card_queue', 'recipients', 'segments', 'occasions' );
	}

	/**
	 * Fetch a row, but only if this viewer's company owns it.
	 *
	 * Returns null both when the row does not exist and when it belongs to
	 * somebody else, so a caller cannot tell the two apart and neither can
	 * anybody probing IDs.
	 *
	 * @param string $table One of tables().
	 * @param int    $id Row ID.
	 * @return array|null
	 */
	public static function owned( $table, $id ) {
		global $wpdb;

		$id         = absint( $id );
		$company_id = self::company_id();

		if ( ! $id || ! $company_id || ! in_array( $table, self::tables(), true ) ) {
			return null;
		}

		// Occasions hang off a recipient rather than carrying a company of
		// their own, so they are checked through the person they belong to.
		if ( 'occasions' === $table ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT o.* FROM ' . cardsco_table( 'occasions' ) . ' o
					 INNER JOIN ' . cardsco_table( 'recipients' ) . ' r ON r.id = o.recipient_id
					 WHERE o.id = %d AND r.company_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
					$id,
					$company_id
				),
				ARRAY_A
			);

			return $row ? $row : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( $table ) . ' WHERE id = %d AND company_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$id,
				$company_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Does this viewer's company own that row?
	 *
	 * @param string $table One of tables().
	 * @param int    $id Row ID.
	 * @return bool
	 */
	public static function owns( $table, $id ) {
		return null !== self::owned( $table, $id );
	}

	/**
	 * What this person is within their company.
	 *
	 * Three roles, in order of what they may do:
	 *
	 *   owner   the account that signed the company up. Everything, and
	 *           cannot be removed or demoted — a company with nobody able
	 *           to pay its bills or add a colleague is a support ticket.
	 *   admin   may manage people and company settings, but may not touch
	 *           the owner.
	 *   member  may set up occasions and write cards. Cannot change the
	 *           company's details or its people.
	 *
	 * Capabilities alone cannot express this: every company account holds
	 * the same WordPress capabilities, and which of them applies depends on
	 * the row joining that person to that company.
	 *
	 * @param int $user_id    Whose role, or 0 for the person viewing.
	 * @param int $company_id Which company, or 0 for theirs.
	 * @return string owner, admin, member, or '' when they are not in it.
	 */
	public static function company_role( $user_id = 0, $company_id = 0 ) {
		global $wpdb;

		$user_id    = $user_id ? absint( $user_id ) : get_current_user_id();
		$company_id = $company_id ? absint( $company_id ) : self::company_id();

		if ( ! $user_id || ! $company_id ) {
			return '';
		}

		$role = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT company_role FROM ' . cardsco_table( 'company_users' ) . ' WHERE company_id = %d AND user_id = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				$user_id,
				'active'
			)
		);

		return in_array( $role, array( 'owner', 'admin', 'member' ), true ) ? (string) $role : '';
	}

	/**
	 * May this person change the company — its details, and who is in it?
	 *
	 * @param int $user_id Whose, or 0 for the person viewing.
	 * @return bool
	 */
	public static function may_manage_company( $user_id = 0 ) {
		if ( ! user_can( $user_id ? $user_id : get_current_user_id(), 'cardsco_manage_company' ) ) {
			return false;
		}

		return in_array( self::company_role( $user_id ), array( 'owner', 'admin' ), true );
	}

	/**
	 * May this person act on that one?
	 *
	 * Nobody may act on the owner, including the owner: there is always
	 * exactly one, and the way to change who it is belongs in a hand-over
	 * of its own rather than in a remove button.
	 *
	 * @param int $target_id The person being acted on.
	 * @return bool
	 */
	public static function may_manage_person( $target_id ) {
		$target_id = absint( $target_id );

		if ( ! self::may_manage_company() ) {
			return false;
		}

		if ( $target_id === get_current_user_id() ) {
			return false;
		}

		return 'owner' !== self::company_role( $target_id );
	}
}
