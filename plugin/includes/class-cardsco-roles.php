<?php
/**
 * Roles and capabilities.
 *
 * Two kinds of account:
 *
 *   cardsco_company - a company buying cards. Front-end dashboard only, no
 *                     wp-admin access. What they can do inside the dashboard is
 *                     narrowed further by company_role on the company_users row.
 *   cardsco_staff   - CardsCo's own fulfilment team. wp-admin access to the
 *                     queue and catalogue, no site administration.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Roles {

	const COMPANY_ROLE = 'cardsco_company';
	const STAFF_ROLE   = 'cardsco_staff';

	/**
	 * Capabilities a company account holds.
	 *
	 * @return array<string, bool>
	 */
	public static function company_caps() {
		return array(
			'read'                      => true,
			'cardsco_access_dashboard'  => true,
			'cardsco_manage_recipients' => true,
			'cardsco_manage_segments'   => true,
			'cardsco_manage_campaigns'  => true,
			'cardsco_manage_company'    => true,
			'cardsco_view_billing'      => true,
		);
	}

	/**
	 * Capabilities a CardsCo staff account holds.
	 *
	 * @return array<string, bool>
	 */
	public static function staff_caps() {
		return array(
			'read'                        => true,
			'upload_files'                => true,
			'cardsco_access_dashboard'    => true,
			'cardsco_manage_fulfilment'   => true,
			'cardsco_manage_catalogue'    => true,
			'cardsco_manage_all_companies' => true,
			'cardsco_view_billing'        => true,
			'edit_posts'                  => true,
			'edit_published_posts'        => true,
			'publish_posts'               => true,
			'delete_posts'                => true,
		);
	}

	/**
	 * Every capability this plugin introduces.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		$caps = array_merge(
			array_keys( self::company_caps() ),
			array_keys( self::staff_caps() )
		);

		return array_values(
			array_unique(
				array_filter(
					$caps,
					static function ( $cap ) {
						return 0 === strpos( $cap, 'cardsco_' );
					}
				)
			)
		);
	}

	/**
	 * Create the roles and give administrators every capability.
	 *
	 * @return void
	 */
	public static function install() {
		remove_role( self::COMPANY_ROLE );
		remove_role( self::STAFF_ROLE );

		add_role( self::COMPANY_ROLE, __( 'CardsCo Company', 'cardsco' ), self::company_caps() );
		add_role( self::STAFF_ROLE, __( 'CardsCo Staff', 'cardsco' ), self::staff_caps() );

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the roles and strip the capabilities from administrators.
	 * Only called from uninstall.
	 *
	 * @return void
	 */
	public static function remove() {
		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		remove_role( self::COMPANY_ROLE );
		remove_role( self::STAFF_ROLE );
	}

	/**
	 * Keep company accounts out of wp-admin.
	 *
	 * @return void
	 */
	public static function block_admin_access() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! in_array( self::COMPANY_ROLE, (array) $user->roles, true ) ) {
			return;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/dashboard/' ) );
		exit;
	}

	/**
	 * Hide the admin bar from company accounts.
	 *
	 * @param bool $show Current value.
	 * @return bool
	 */
	public static function filter_admin_bar( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		$user = wp_get_current_user();

		if ( in_array( self::COMPANY_ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' ) ) {
			return false;
		}

		return $show;
	}
}
