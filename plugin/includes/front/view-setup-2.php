<?php
/**
 * Step 2 — Who's celebrating?
 *
 * Frame 253:56964: an upload panel with a template link, three counts, then
 * a table of Name / Birthday / Next card, a link to add somebody by hand,
 * and a "Showing 4 of 42" count.
 *
 * @package CardsCo
 *
 * @var array   $people
 * @var array   $counts
 * @var array   $upload
 * @var array   $links
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" id="ccWizForm" class="cc-wz-form" action="<?php echo esc_url( $links['here'] ); ?>">
	<?php wp_nonce_field( 'cardsco_setup', '_cardsco_nonce' ); ?>
	<input type="hidden" name="cardsco_setup_action" value="step" />
	<input type="hidden" name="step" value="2" />
	<input type="hidden" name="programme_id" value="<?php echo esc_attr( (int) $programme['id'] ); ?>" />
</form>

<?php if ( ! empty( $upload['report'] ) ) : ?>
	<p class="cc-up-report" role="status"><?php echo esc_html( $upload['report'] ); ?></p>
<?php endif; ?>

<section class="cc-up-panel">
	<div class="cc-up-panel-head">
		<div>
			<p class="cc-up-panel-title"><?php esc_html_e( 'Your team', 'cardsco' ); ?></p>
			<p class="cc-up-panel-sub"><?php esc_html_e( 'Columns: first name, last name, birthday (day and month)', 'cardsco' ); ?></p>
		</div>
		<a class="cc-link" href="<?php echo esc_url( $links['template'] ); ?>"><?php esc_html_e( 'Download template', 'cardsco' ); ?></a>
	</div>

	<?php
	/*
	 * A real upload, not a link.
	 *
	 * Both controls here used to be anchors pointing at the page they were
	 * already on, so neither did anything. The file input is the one thing
	 * that cannot be a link: it needs a multipart form of its own, which is
	 * why this is not part of the step's own form.
	 *
	 * The input is hidden behind its label rather than left as a browser
	 * file control, so the row keeps the frame's shape. Choosing a file
	 * submits, which saves a second click; without JavaScript the Upload
	 * button below does the same job.
	 */
	?>
	<form class="cc-up-file" method="post" enctype="multipart/form-data" id="ccUpload"
		action="<?php echo esc_url( $links['here'] ); ?>">

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

		<label class="cc-link cc-up-choose" for="cc-up-input">
			<?php echo esc_html( $upload['action'] ); ?>
			<input type="file" id="cc-up-input" name="people_file" accept=".csv,.tsv,.txt,text/csv"
				class="screen-reader-text" data-cc-up-input />
		</label>

		<button type="submit" class="cc-btn cc-btn-quiet cc-up-go" data-cc-up-go hidden>
			<?php esc_html_e( 'Upload', 'cardsco' ); ?>
		</button>
	</form>
</section>

<div class="cc-counts">
	<?php foreach ( $counts as $cc_count ) : ?>
		<div class="cc-count">
			<span class="cc-count-n"><?php echo esc_html( number_format_i18n( $cc_count['n'] ) ); ?></span>
			<span class="cc-count-label"><?php echo esc_html( $cc_count['label'] ); ?></span>
		</div>
	<?php endforeach; ?>
</div>

<div class="cc-table-wrap">
	<table class="cc-table cc-people">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'cardsco' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Birthday', 'cardsco' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Next card', 'cardsco' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $people['rows'] as $cc_person ) : ?>
				<tr>
					<td class="cc-people-name"><?php echo esc_html( $cc_person['name'] ); ?></td>
					<td class="<?php echo $cc_person['has_date'] ? '' : 'cc-people-missing'; ?>">
						<?php echo esc_html( $cc_person['birthday'] ); ?>
					</td>
					<td>
						<?php if ( $cc_person['has_date'] ) : ?>
							<?php echo esc_html( $cc_person['next'] ); ?>
						<?php else : ?>
							<?php
							/*
							 * Goes to this person, not to a blank form. It
							 * used to point at the same URL as "Add someone
							 * by hand", so clicking it opened an empty panel
							 * headed "Person 4" and made a second, nameless
							 * copy instead of giving this one a date.
							 */
							$cc_fix = add_query_arg( 'cc_person', (int) $cc_person['id'], $links['here_step'] );
							?>
							<a class="cc-link" href="<?php echo esc_url( $cc_fix ); ?>">
								<?php
								printf(
									/* translators: %s: the occasion, e.g. "birthday". */
									esc_html__( 'Add a %s', 'cardsco' ),
									esc_html( cardsco_lcfirst( $people['noun'] ) )
								);
								?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<p class="cc-people-foot">
	<a class="cc-link" href="<?php echo esc_url( $links['people'] ); ?>"><?php esc_html_e( 'Add someone by hand', 'cardsco' ); ?></a>
	<span class="cc-people-showing">
		<?php
		printf(
			/* translators: 1: how many rows are listed, 2: how many there are. */
			esc_html__( 'Showing %1$s of %2$s', 'cardsco' ),
			esc_html( number_format_i18n( count( $people['rows'] ) ) ),
			esc_html( number_format_i18n( $people['total'] ) )
		);
		?>
	</span>
</p>
