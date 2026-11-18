<?php
/**
 * The company logo, uploaded from the portal.
 *
 * The Settings screen used to send people to /wp-admin/upload.php to set
 * this, which portal customers cannot reach — so nobody outside the office
 * could ever put a logo on their own cards.
 *
 * WordPress's own media_handle_upload() is not usable here: it requires
 * the upload_files capability, which these customers do not have and
 * should not be given, since it is the right to put files anywhere in the
 * media library. What they have is cardsco_manage_company, the right to
 * change their own company's details, and this accepts exactly one kind of
 * file on the strength of it.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CardsCo_Logo
 */
class CardsCo_Logo {

	/** The largest file worth taking for something printed 20 mm wide. */
	const MAX_BYTES = 2097152;

	/** Dimensions a real logo falls between. */
	const MAX_PIXELS = 5000;

	/**
	 * What a logo may be.
	 *
	 * PNG and JPEG only. SVG is the obvious omission and the omission is
	 * deliberate: an SVG is a document that can carry script, and
	 * accepting one from a customer means either sanitising it properly or
	 * hosting whatever they send. Neither belongs in a logo field.
	 *
	 * @return array
	 */
	protected static function allowed() {
		return array(
			'png'          => 'image/png',
			'jpg|jpeg|jpe' => 'image/jpeg',
		);
	}

	/**
	 * Whether a file is allowed to become a logo.
	 *
	 * Separate from storing it so the rules can be tested on their own:
	 * moving a file requires a genuine HTTP upload, which a test fixture
	 * is not, and the rules are the part worth checking exhaustively.
	 *
	 * @param array $file One entry from $_FILES.
	 * @return true|WP_Error
	 */
	public static function check( $file ) {
		if ( ! current_user_can( 'cardsco_manage_company' ) ) {
			return new WP_Error( 'cardsco_logo_denied', __( 'You cannot change this company’s logo.', 'cardsco' ) );
		}

		if ( ! is_array( $file ) ) {
			return new WP_Error( 'cardsco_logo_missing', __( 'No file arrived. Try again.', 'cardsco' ) );
		}

		/*
		 * The error code comes first. A file too big for the server arrives
		 * with no temporary name at all, so checking for that first told
		 * people nothing had been sent when in fact too much had.
		 */
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error(
				'cardsco_logo_upload',
				UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error']
					? __( 'That file is too big for the server to accept.', 'cardsco' )
					: __( 'That file did not upload properly. Try again.', 'cardsco' )
			);
		}

		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'cardsco_logo_missing', __( 'No file arrived. Try again.', 'cardsco' ) );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error(
				'cardsco_logo_big',
				sprintf(
					/* translators: %s: the largest allowed size, e.g. 2 MB. */
					__( 'Logos have to be under %s.', 'cardsco' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		/*
		 * The name and the browser's word for the type are both the
		 * customer's to choose, so neither decides anything. This reads
		 * the file itself.
		 */
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed() );

		if ( empty( $checked['type'] ) || ! in_array( $checked['type'], array( 'image/png', 'image/jpeg' ), true ) ) {
			return new WP_Error( 'cardsco_logo_type', __( 'Logos have to be a PNG or a JPG.', 'cardsco' ) );
		}

		/* And it has to actually be an image, not merely named like one. */
		$size = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that is not an image returns false here, which is the answer we want.

		if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
			return new WP_Error( 'cardsco_logo_type', __( 'That file is not an image we can read.', 'cardsco' ) );
		}

		/*
		 * A PNG header followed by anything at all passes the checks above:
		 * getimagesize reads whatever bytes sit where the dimensions
		 * belong, so a file of "0000" is an image 808,464,432 pixels wide.
		 * Nothing that size is a logo, and nothing that size can be
		 * resized either — it would be asked for 2 TB of memory.
		 */
		if ( $size[0] > self::MAX_PIXELS || $size[1] > self::MAX_PIXELS ) {
			return new WP_Error(
				'cardsco_logo_type',
				sprintf(
					/* translators: %s: the largest allowed edge, in pixels. */
					__( 'That image is larger than %s pixels on a side, so it is not a logo we can print.', 'cardsco' ),
					number_format_i18n( self::MAX_PIXELS )
				)
			);
		}

		return true;
	}

	/**
	 * Take an uploaded file and keep it.
	 *
	 * @param array $file       One entry from $_FILES.
	 * @param int   $company_id Whose logo it is.
	 * @return int|WP_Error Attachment ID, or why not.
	 */
	public static function store( $file, $company_id ) {
		$allowed = self::check( $file );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::allowed(),
			)
		);

		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) ) {
			return new WP_Error( 'cardsco_logo_move', is_array( $moved ) ? $moved['error'] : __( 'The upload could not be saved.', 'cardsco' ) );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_text_field( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$moved['file']
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'cardsco_logo_save', __( 'The logo could not be saved.', 'cardsco' ) );
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $moved['file'] ) );

		/*
		 * Marked with the company that owns it, so a logo can always be
		 * traced back and so one company's upload is never served as
		 * another's.
		 */
		update_post_meta( $id, '_cardsco_company_id', (int) $company_id );

		return (int) $id;
	}
}
