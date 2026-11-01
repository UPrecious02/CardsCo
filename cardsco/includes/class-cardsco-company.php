<?php
/**
 * Companies.
 *
 * A company is the account that buys cards. Its people sign in as WordPress
 * users linked through the company_users table, so a company can have more
 * than one seat without any change to the schema.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

class CardsCo_Company {

	/**
	 * Create a company.
	 *
	 * @param array $data {
	 *     @type string $name Required.
	 *     @type int    $owner_user_id Optional, linked as the owner.
	 *     @type string $contact_name
	 *     @type string $contact_email
	 *     @type string $contact_phone
	 *     @type string $billing_email
	 *     @type string $billing_address
	 * }
	 * @return int|WP_Error Company ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$name = trim( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'cardsco_company_name', __( 'A company name is required.', 'cardsco' ) );
		}

		$owner_id = absint( $data['owner_user_id'] ?? 0 );

		$row = array(
			'owner_user_id'      => $owner_id,
			'name'               => sanitize_text_field( $name ),
			'slug'               => self::unique_slug( $name ),
			'contact_name'       => sanitize_text_field( $data['contact_name'] ?? '' ),
			'contact_email'      => sanitize_email( $data['contact_email'] ?? '' ),
			'contact_phone'      => sanitize_text_field( $data['contact_phone'] ?? '' ),
			'billing_name'       => sanitize_text_field( $data['billing_name'] ?? $name ),
			'billing_email'      => sanitize_email( $data['billing_email'] ?? ( $data['contact_email'] ?? '' ) ),
			'billing_address'    => sanitize_textarea_field( $data['billing_address'] ?? '' ),
			'billing_reference'  => sanitize_text_field( $data['billing_reference'] ?? '' ),
			'logo_attachment_id' => absint( $data['logo_attachment_id'] ?? 0 ),
			'status'             => 'active',
			'created_at'         => cardsco_now(),
			'updated_at'         => cardsco_now(),
		);

		$inserted = $wpdb->insert(
			cardsco_table( 'companies' ),
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'cardsco_company_insert', __( 'The company could not be saved.', 'cardsco' ) );
		}

		$company_id = (int) $wpdb->insert_id;

		if ( $owner_id ) {
			self::add_user( $company_id, $owner_id, 'owner' );
		}

		cardsco_log(
			array(
				'company_id'  => $company_id,
				'object_type' => 'company',
				'object_id'   => $company_id,
				'action'      => 'created',
				/* translators: %s: company name. */
				'summary'     => sprintf( __( 'Company %s created', 'cardsco' ), $row['name'] ),
			)
		);

		return $company_id;
	}

	/**
	 * Update a company.
	 *
	 * @param int   $company_id Company ID.
	 * @param array $data Fields to change.
	 * @return bool|WP_Error
	 */
	public static function update( $company_id, array $data ) {
		global $wpdb;

		$company_id = absint( $company_id );

		if ( ! $company_id || ! self::get( $company_id ) ) {
			return new WP_Error( 'cardsco_company_missing', __( 'That company does not exist.', 'cardsco' ) );
		}

		$fields = array();
		$format = array();

		$text = array( 'name', 'contact_name', 'contact_phone', 'billing_name', 'billing_reference', 'status' );

		foreach ( $text as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = sanitize_text_field( $data[ $field ] );
				$format[]         = '%s';
			}
		}

		foreach ( array( 'contact_email', 'billing_email' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = sanitize_email( $data[ $field ] );
				$format[]         = '%s';
			}
		}

		if ( array_key_exists( 'billing_address', $data ) ) {
			$fields['billing_address'] = sanitize_textarea_field( $data['billing_address'] );
			$format[]                  = '%s';
		}

		if ( array_key_exists( 'logo_attachment_id', $data ) ) {
			$fields['logo_attachment_id'] = absint( $data['logo_attachment_id'] );
			$format[]                     = '%d';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = cardsco_now();
		$format[]             = '%s';

		$wpdb->update( cardsco_table( 'companies' ), $fields, array( 'id' => $company_id ), $format, array( '%d' ) );

		wp_cache_delete( $company_id, 'cardsco_companies' );

		return true;
	}

	/**
	 * Fetch a company.
	 *
	 * @param int $company_id Company ID.
	 * @return array|null
	 */
	public static function get( $company_id ) {
		global $wpdb;

		$company_id = absint( $company_id );

		if ( ! $company_id ) {
			return null;
		}

		$cached = wp_cache_get( $company_id, 'cardsco_companies' );

		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'companies' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id
			),
			ARRAY_A
		);

		wp_cache_set( $company_id, $row ? $row : 0, 'cardsco_companies', HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * All companies, newest first.
	 *
	 * @param array $args Optional status filter and limit.
	 * @return array[]
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => 'active',
				'limit'  => 200,
			)
		);

		$sql    = 'SELECT * FROM ' . cardsco_table( 'companies' );
		$params = array();

		if ( $args['status'] && 'any' !== $args['status'] ) {
			$sql     .= ' WHERE status = %s';
			$params[] = $args['status'];
		}

		$sql     .= ' ORDER BY name ASC LIMIT %d';
		$params[] = absint( $args['limit'] );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Link a WordPress user to a company.
	 *
	 * @param int    $company_id Company ID.
	 * @param int    $user_id User ID.
	 * @param string $role owner | admin | member.
	 * @return bool
	 */
	public static function add_user( $company_id, $user_id, $role = 'member' ) {
		global $wpdb;

		$company_id = absint( $company_id );
		$user_id    = absint( $user_id );

		if ( ! $company_id || ! $user_id ) {
			return false;
		}

		$role = in_array( $role, array( 'owner', 'admin', 'member' ), true ) ? $role : 'member';

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . cardsco_table( 'company_users' ) . ' (company_id, user_id, company_role, status, invited_by, created_at)
				 VALUES (%d, %d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE company_role = VALUES(company_role), status = VALUES(status)', // phpcs:ignore WordPress.DB.PreparedSQL
				$company_id,
				$user_id,
				$role,
				'active',
				get_current_user_id(),
				cardsco_now()
			)
		);

		wp_cache_delete( $user_id, 'cardsco_user_company' );

		$user = get_userdata( $user_id );

		if ( $user && ! user_can( $user, 'manage_options' ) && ! in_array( CardsCo_Roles::COMPANY_ROLE, (array) $user->roles, true ) ) {
			$user->add_role( CardsCo_Roles::COMPANY_ROLE );
		}

		return true;
	}

	/**
	 * Unlink a user from a company.
	 *
	 * @param int $company_id Company ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove_user( $company_id, $user_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			cardsco_table( 'company_users' ),
			array(
				'company_id' => absint( $company_id ),
				'user_id'    => absint( $user_id ),
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( absint( $user_id ), 'cardsco_user_company' );

		return (bool) $deleted;
	}

	/**
	 * Users linked to a company.
	 *
	 * @param int $company_id Company ID.
	 * @return array[]
	 */
	public static function users( $company_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . cardsco_table( 'company_users' ) . ' WHERE company_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				absint( $company_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Build a slug that is not already taken.
	 *
	 * @param string $name Company name.
	 * @return string
	 */
	protected static function unique_slug( $name ) {
		global $wpdb;

		$base = sanitize_title( $name );
		$base = '' === $base ? 'company' : $base;
		$slug = $base;
		$i    = 1;

		$table = cardsco_table( 'companies' );

		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$i++;
			$slug = $base . '-' . $i;
		}

		return $slug;
	}
}
