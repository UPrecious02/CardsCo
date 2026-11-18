<?php
/**
 * Files the portal hands back — served from the front end.
 *
 * These used to go through admin-post.php, on the reasoning that it is
 * simply WordPress's endpoint for a logged-in request that is not a page,
 * and that nothing about a CSV makes it an admin screen. That reasoning is
 * right about WordPress and wrong about this site: portal customers are
 * blocked from /wp-admin/ altogether, so every one of those links bounced
 * them to the dashboard instead of sending a file. The admin-post route
 * only ever worked for whoever was testing it as an administrator.
 *
 * So downloads live out here now, on the portal page the person is already
 * standing on, where the people who need them can actually reach them.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Download
 */
class CardsCo_Download {

	/** The query argument naming which file is wanted. */
	const ARG = 'cardsco_dl';

	/**
	 * What may be asked for, and who builds it.
	 *
	 * An allowlist rather than a callable from the query string, so a
	 * crafted address cannot reach anything that was not meant to be
	 * downloadable.
	 *
	 * @return array
	 */
	protected static function handlers() {
		return array(
			'people' => array( 'CardsCo_Setup', 'send_template' ),
			'orders' => array( 'CardsCo_Orders_Export', 'send' ),
		);
	}

	/**
	 * Listen on the front end.
	 *
	 * Early on template_redirect: before the theme starts rendering a page
	 * we are not going to show.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_send' ), 1 );
	}

	/**
	 * A signed address for one of the files above.
	 *
	 * Built from the page being viewed, so the download stays inside the
	 * portal and carries whatever the person is looking at.
	 *
	 * @param string $what One of the keys in handlers().
	 * @param array  $args Extra query arguments to carry along.
	 * @return string
	 */
	public static function url( $what, array $args = array() ) {
		$base = remove_query_arg( array_merge( array( self::ARG, '_wpnonce' ), array_keys( $args ) ) );

		return wp_nonce_url(
			add_query_arg( array_merge( array( self::ARG => $what ), $args ), $base ),
			self::ARG . '_' . $what
		);
	}

	/**
	 * Send the file, if one was asked for.
	 *
	 * @return void
	 */
	public static function maybe_send() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked immediately below.
		$what = isset( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';

		if ( '' === $what ) {
			return;
		}

		$handlers = self::handlers();

		if ( ! isset( $handlers[ $what ] ) ) {
			return;
		}

		/*
		 * Despite the name this only verifies the nonce; it has nothing to
		 * do with the admin and works perfectly well out here.
		 */
		check_admin_referer( self::ARG . '_' . $what );

		if ( CardsCo_Access::refusal() ) {
			wp_die( esc_html__( 'You do not have access to that.', 'cardsco' ) );
		}

		call_user_func( $handlers[ $what ] );
		exit;
	}
}
