<?php
/**
 * The spreadsheet upload, wherever people need it.
 *
 * This used to live only on the step 2 list, and every other screen
 * that wanted it got a link pointing at that one. Links were the whole
 * problem: from the add-by-hand screen you were sent somewhere else to
 * do a thing you were ready to do where you stood, and when the link
 * was wrong it looked like the button did nothing. So the upload is a
 * partial now, and any screen that offers it carries the real control.
 *
 * A file input needs a multipart form of its own, which is why this is
 * never part of the step's own form. Choosing a file submits straight
 * away; the button is there for anyone without JavaScript, and is what
 * people look for, so it is a button and not a quiet link.
 *
 * @package CardsCo
 *
 * @var array  $programme  Programme row.
 * @var array  $links      Wizard links.
 * @var array  $upload     name, meta and action label for the file row.
 * @var string $cc_up_to   Where the form posts. Optional.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Any screen may carry this, so it does not rely on each one having
 * bound the file row first. Missing, it reads as an empty list, which
 * is the truthful thing to say when nobody has said otherwise — rather
 * than a button with no words on it.
 */
if ( ! isset( $upload ) || ! is_array( $upload ) ) {
	$upload = CardsCo_Dashboard::upload_row( array() );
}

$cc_up_to = isset( $cc_up_to ) && $cc_up_to
	? $cc_up_to
	: add_query_arg(
		array( CardsCo_Setup::ARG => (int) $programme['id'], 'step' => 2 ),
		remove_query_arg( array( 'cc_add', 'cc_added', 'cc_joined', 'cc_person', 'cardsco_error' ), $links['here'] )
	);
?>
<form class="cc-up-file" method="post" enctype="multipart/form-data" id="ccUpload"
	action="<?php echo esc_url( $cc_up_to ); ?>">

	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="upload_people" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />

	<span class="cc-up-file-icon" aria-hidden="true">
		<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'box', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
	</span>

	<span class="cc-up-file-text">
		<span class="cc-up-file-name" data-cc-up-name><?php echo esc_html( $upload['name'] ); ?></span>
		<span class="cc-up-file-meta" data-cc-up-meta><?php echo esc_html( $upload['meta'] ); ?></span>
	</span>

	<?php
	/*
	 * The input sits inside its label, so there is no id to collide with
	 * when a page carries this more than once, and no `for` to go stale.
	 */
	?>
	<label class="cc-btn cc-btn-primary cc-up-choose">
		<?php echo call_user_func( array( 'CardsCo_Shortcodes', 'icon' ), 'upload', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		<?php echo esc_html( $upload['action'] ); ?>
		<input type="file" name="people_file" accept=".csv,.tsv,.txt,text/csv"
			class="screen-reader-text" data-cc-up-input />
	</label>

	<?php
	/*
	 * Only where there is no JavaScript to submit for us.
	 *
	 * Shown always, it is a second button next to the first that does
	 * nothing until a file has been chosen — press it first, as anyone
	 * reading left to right would, and the page comes back asking you
	 * to choose a file. Hidden with the hidden attribute it was worse:
	 * no JavaScript meant no way to upload at all. <noscript> puts it
	 * exactly where it is needed and nowhere else.
	 */
	?>
	<noscript>
		<button type="submit" class="cc-btn cc-btn-quiet cc-up-go" data-cc-up-go>
			<?php esc_html_e( 'Upload', 'cardsco' ); ?>
		</button>
	</noscript>
</form>
