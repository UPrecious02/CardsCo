<?php
/**
 * Inviting somebody to help look after a company's cards.
 *
 * The accept side already exists in CardsCo_Auth; this is what feeds it.
 *
 * How an invitation works, and why:
 *
 * The invited person is created as a real WordPress user straight away, with
 * a password nobody knows, and a membership row marked 'invited'. The link
 * they are sent carries core's own password-reset key.
 *
 * The alternative — a pending_invites table with a token column of our own —
 * means writing token generation, hashing and expiry from scratch, which is
 * exactly the sort of thing that is quietly got wrong. Core already hashes
 * the key before storing it and expires it on its own.
 *
 * The cost is a user record for somebody who has not accepted yet. That is
 * safe because cardsco_get_user_company_id() counts only 'active'
 * memberships, so an unaccepted invitation reaches no company's data at all,
 * and the account has no usable password to sign in with. Both are asserted
 * in the tests.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sending, resending and withdrawing invitations.
 */
class CardsCo_Invite {

	/**
	 * How long an invitation is good for, in hours.
	 *
	 * This is core's own figure, not ours: check_password_reset_key() runs
	 * DAY_IN_SECONDS through the password_reset_expiration filter, and the
	 * invitation rides on that key. Writing 72 in the email while the link
	 * died after 24 would be worse than a short window, so the number is
	 * taken from the same place the expiry comes from. Anybody who misses it
	 * gets a fresh link from resend().
	 */
	const EXPIRES_HOURS = 24;

	/**
	 * Invite somebody to a company.
	 *
	 * @param int    $company_id Company they are being invited to.
	 * @param string $email      Their address.
	 * @param string $role       owner | admin | member.
	 * @param array  $args       Optional 'first_name', 'last_name', 'send' (default true).
	 * @return array|WP_Error    The invitation, or why it could not be made.
	 */
	public static function send( $company_id, $email, $role = 'member', array $args = array() ) {
		$company_id = absint( $company_id );
		$email      = sanitize_email( $email );
		$company    = CardsCo_Company::get( $company_id );

		if ( ! $company ) {
			return new WP_Error( 'cardsco_no_company', __( 'That company does not exist.', 'cardsco' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'cardsco_bad_email', __( 'That does not look like an email address.', 'cardsco' ) );
		}

		$role = in_array( $role, array( 'owner', 'admin', 'member' ), true ) ? $role : 'member';

		$user_id = email_exists( $email );

		if ( $user_id ) {
			$existing = self::membership( $company_id, (int) $user_id );

			if ( $existing && 'active' === $existing['status'] ) {
				return new WP_Error( 'cardsco_already_in', __( 'They are already on this company.', 'cardsco' ) );
			}

			// Somebody who belongs to another company cannot be pulled into
			// this one: the dashboard resolves to one company per person, so
			// a second membership would silently do nothing and look broken.
			$theirs = cardsco_get_user_company_id( (int) $user_id );

			if ( $theirs && $theirs !== $company_id ) {
				return new WP_Error( 'cardsco_elsewhere', __( 'That address already belongs to another company.', 'cardsco' ) );
			}
		} else {
			// A password nobody has. They set a real one when they accept.
			$user_id = wp_create_user( $email, wp_generate_password( 32, true, true ), $email );

			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'cardsco_user_failed', __( 'We could not set up that account.', 'cardsco' ) );
			}

			$first = isset( $args['first_name'] ) ? sanitize_text_field( $args['first_name'] ) : '';
			$last  = isset( $args['last_name'] ) ? sanitize_text_field( $args['last_name'] ) : '';

			if ( $first || $last ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'first_name'   => $first,
						'last_name'    => $last,
						'display_name' => trim( $first . ' ' . $last ),
					)
				);
			}
		}

		$user_id = (int) $user_id;

		if ( ! CardsCo_Company::add_user( $company_id, $user_id, $role, 'invited' ) ) {
			return new WP_Error( 'cardsco_invite_failed', __( 'We could not record that invitation.', 'cardsco' ) );
		}

		wp_cache_delete( $user_id, 'cardsco_user_company' );

		$link = self::link( $user_id );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$sent = false;

		if ( ! isset( $args['send'] ) || $args['send'] ) {
			$sent = self::mail( $user_id, $company, $link );
		}

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'action'      => 'invited',
				/* translators: 1: the invited address, 2: their role. */
				'summary'     => sprintf( __( 'Invited %1$s as %2$s', 'cardsco' ), $email, $role ),
			)
		);

		return array(
			'user_id'    => $user_id,
			'company_id' => $company_id,
			'email'      => $email,
			'role'       => $role,
			'link'       => $link,
			'sent'       => (bool) $sent,
		);
	}

	/**
	 * Send the same invitation again with a fresh link.
	 *
	 * Minting a new key voids the old one, which is what you want: a link
	 * forwarded to the wrong person stops working the moment it is resent.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    The invited person.
	 * @return array|WP_Error
	 */
	public static function resend( $company_id, $user_id ) {
		$company_id = absint( $company_id );
		$user_id    = absint( $user_id );
		$row        = self::membership( $company_id, $user_id );

		if ( ! $row || 'invited' !== $row['status'] ) {
			return new WP_Error( 'cardsco_no_invite', __( 'There is no invitation waiting for them.', 'cardsco' ) );
		}

		$company = CardsCo_Company::get( $company_id );
		$user    = get_userdata( $user_id );

		if ( ! $company || ! $user ) {
			return new WP_Error( 'cardsco_no_invite', __( 'There is no invitation waiting for them.', 'cardsco' ) );
		}

		$link = self::link( $user_id );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return array(
			'user_id' => $user_id,
			'email'   => $user->user_email,
			'link'    => $link,
			'sent'    => (bool) self::mail( $user_id, $company, $link ),
		);
	}

	/**
	 * Withdraw an invitation that has not been accepted.
	 *
	 * The membership row goes. The user record goes too, but only if this
	 * was the only thing it was for — deleting a WordPress user who has
	 * since joined another company, or who is an administrator, would be a
	 * far worse outcome than leaving a stray account behind.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    The invited person.
	 * @return true|WP_Error
	 */
	public static function revoke( $company_id, $user_id ) {
		global $wpdb;

		$company_id = absint( $company_id );
		$user_id    = absint( $user_id );
		$row        = self::membership( $company_id, $user_id );

		if ( ! $row || 'invited' !== $row['status'] ) {
			return new WP_Error( 'cardsco_no_invite', __( 'There is no invitation waiting for them.', 'cardsco' ) );
		}

		$wpdb->delete(
			cardsco_table( 'company_users' ),
			array(
				'company_id' => $company_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( $user_id, 'cardsco_user_company' );

		$others = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . cardsco_table( 'company_users' ) . ' WHERE user_id = %d', $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$user = get_userdata( $user_id );

		// count_user_posts() hands back a numeric string, not an int — core's
		// own docblock says so — so a strict comparison against 0 is never
		// true and this branch silently never ran.
		$wrote_nothing = 0 === (int) count_user_posts( $user_id );

		if ( ! $others && $user && ! user_can( $user, 'manage_options' ) && $wrote_nothing ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'user',
				'object_id'   => $user_id,
				'action'      => 'invite_revoked',
				'summary'     => __( 'Invitation withdrawn', 'cardsco' ),
			)
		);

		return true;
	}

	/**
	 * Everyone on a company, accepted or not.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	public static function people( $company_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'company_users' ) . ' WHERE company_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$user = get_userdata( (int) $row['user_id'] );

			if ( ! $user ) {
				continue;
			}

			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'name'    => $user->display_name ? $user->display_name : $user->user_email,
				'email'   => $user->user_email,
				'role'    => $row['company_role'],
				'status'  => $row['status'],
				'pending' => 'invited' === $row['status'],
			);
		}

		return $out;
	}

	/**
	 * Only the ones still waiting.
	 *
	 * @param int $company_id Company.
	 * @return array
	 */
	public static function pending( $company_id ) {
		return array_values(
			array_filter(
				self::people( $company_id ),
				static function ( $person ) {
					return $person['pending'];
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * One membership row.
	 *
	 * @param int $company_id Company.
	 * @param int $user_id    User.
	 * @return array|null
	 */
	protected static function membership( $company_id, $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'company_users' ) . ' WHERE company_id = %d AND user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id ),
				absint( $user_id )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The link that accepts the invitation.
	 *
	 * @param int $user_id The invited person.
	 * @return string|WP_Error
	 */
	protected static function link( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error( 'cardsco_no_user', __( 'That person does not exist.', 'cardsco' ) );
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return new WP_Error( 'cardsco_no_key', __( 'We could not make an invitation link.', 'cardsco' ) );
		}

		$base = (string) CardsCo_Settings::get( 'invite_url', '' );

		if ( ! $base ) {
			$base = home_url( '/join/' );
		}

		return add_query_arg(
			array(
				'key'   => rawurlencode( $key ),
				'login' => rawurlencode( $user->user_login ),
			),
			$base
		);
	}

	/**
	 * The invitation email.
	 *
	 * @param int    $user_id The invited person.
	 * @param array  $company The company.
	 * @param string $link    Where they accept.
	 * @return bool
	 */
	protected static function mail( $user_id, array $company, $link ) {
		$user   = get_userdata( $user_id );
		$by     = wp_get_current_user();
		$who    = ( $by && $by->ID ) ? $by->display_name : '';
		$name   = $company['name'];

		if ( ! $user ) {
			return false;
		}

		$body  = $user->first_name
			? sprintf( /* translators: %s: their first name. */ __( 'Hello %s,', 'cardsco' ), $user->first_name ) . "\n\n"
			: __( 'Hello,', 'cardsco' ) . "\n\n";

		$body .= $who
			/* translators: 1: who invited them, 2: the company. */
			? sprintf( __( '%1$s has asked you to help look after %2$s\'s cards on CardsCo.', 'cardsco' ), $who, $name ) . "\n\n"
			/* translators: %s: the company. */
			: sprintf( __( 'You have been asked to help look after %s\'s cards on CardsCo.', 'cardsco' ), $name ) . "\n\n";

		$body .= __( 'Open this link to pick a password and get started:', 'cardsco' ) . "\n\n";
		$body .= $link . "\n\n";
		$body .= sprintf(
			/* translators: %d: how many hours the link lasts. */
			__( 'The link works for %d hours. If you were not expecting this, ignore this email and nothing will happen.', 'cardsco' ),
			self::EXPIRES_HOURS
		) . "\n";

		return (bool) wp_mail(
			$user->user_email,
			/* translators: %s: the company name. */
			sprintf( __( 'Join %s on CardsCo', 'cardsco' ), $name ),
			$body
		);
	}
}
