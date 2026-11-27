<?php
/**
 * The occasion set-up wizard's shell.
 *
 * Measured from frames 253:56833 (step 1) and 253:56964 (step 2). Unlike the
 * dashboard screens this one has no sidebar: it is a full 1440 page with its
 * own top bar, a body with a 281-wide summary rail on the right, and a
 * footer action bar.
 *
 *   header   61 tall   name + "draft saved" · stepper · Save and exit
 *   body    768 tall   content from x=64, rail at x=1105
 *   footer   71 tall   Back · what is selected · Continue
 *
 * @package CardsCo
 *
 * @var array   $programme
 * @var int     $step
 * @var array[] $steps
 * @var array   $rail
 * @var string  $error
 * @var array   $links
 * @var string  $body   The step's own view file.
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';
?>
<div class="cc cc-wz">

	<header class="cc-wz-top">
		<div class="cc-wz-who">
			<span class="cc-wz-name"><?php echo esc_html( $rail['title'] ); ?></span>
			<span class="cc-wz-saved"><?php echo esc_html( $finished ? __( 'Occasion · on', 'cardsco' ) : $rail['saved'] ); ?></span>
		</div>

		<?php require __DIR__ . '/part-wz-progress.php'; ?>

		<ol class="cc-wz-steps" id="ccWzSteps">
			<?php foreach ( $steps as $cc_n => $cc_step ) : ?>
				<?php
				$cc_done  = $finished || CardsCo_Setup::done( $programme, $cc_n );
				$cc_state = ( ! $finished && $cc_n === $step ) ? 'is-on' : ( $cc_done ? 'is-done' : '' );
				?>
				<li class="cc-wz-step <?php echo esc_attr( $cc_state ); ?>">
					<?php if ( $cc_done ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'step', $cc_n, $links['here'] ) ); ?>">
							<span class="cc-wz-num">
								<?php
								/* A finished step shows a tick where its number was, which
								   is how the frame tells done from still to do. */
								echo call_user_func( array( $cc, 'icon' ), 'check', 'cc-wz-num-tick' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP.
								?>
								<span class="cc-sr"><?php echo esc_html( $cc_n ); ?></span>
							</span>
							<span class="cc-wz-label"><?php echo esc_html( $cc_step['label'] ); ?></span>
						</a>
					<?php else : ?>
						<span<?php echo $cc_n === $step ? ' aria-current="step"' : ''; ?>>
							<span class="cc-wz-num"><?php echo esc_html( $cc_n ); ?></span>
							<span class="cc-wz-label"><?php echo esc_html( $cc_step['label'] ); ?></span>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<a class="cc-wz-exit" href="<?php echo esc_url( $links['exit'] ); ?>"><?php esc_html_e( 'Save and exit', 'cardsco' ); ?></a>
	</header>

	<div class="cc-wz-body<?php echo empty( $rail['hide_rail'] ) ? '' : ' is-wide'; ?>">
		<div class="cc-wz-content">
			<?php if ( $error ) : ?>
				<div class="cc-wz-error" role="alert"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<p class="cc-wz-of<?php echo $finished ? ' is-done' : ''; ?>">
				<?php
				if ( $finished ) {
					esc_html_e( 'Done', 'cardsco' );
				} else {
					printf(
						/* translators: 1: this step's number, 2: how many steps there are. */
						esc_html__( 'Step %1$s of %2$s', 'cardsco' ),
						esc_html( number_format_i18n( $step ) ),
						esc_html( number_format_i18n( count( $steps ) ) )
					);
				}
				?>
			</p>
			<h1 class="cc-wz-head">
				<?php
				if ( $finished ) {
					printf(
						/* translators: %s: the occasion's name. */
						esc_html__( '%s is on', 'cardsco' ),
						esc_html( $rail['title'] )
					);
				} else {
					echo esc_html( $rail['heading'] );
				}
				?>
			</h1>
			<p class="cc-wz-sub">
				<?php
				echo esc_html(
					$finished
						? __( 'We’ll write and ship every card from now on. You can change anything later.', 'cardsco' )
						: $rail['blurb']
				);
				?>
			</p>

			<?php include $body; ?>
		</div>

		<?php
		/*
		 * The card writer takes the whole width — its preview card is the
		 * point of the screen and the rail would halve it.
		 */
		?>
		<?php if ( empty( $rail['hide_rail'] ) ) : ?>
		<aside class="cc-wz-rail">
			<p class="cc-wz-rail-head"><?php esc_html_e( 'Your occasion', 'cardsco' ); ?></p>

			<ul class="cc-wz-rail-list">
				<?php foreach ( $steps as $cc_n => $cc_step ) : ?>
					<?php
					/*
					 * Three states, as the frame draws them: a filled disc with
					 * a tick for a finished step, a hollow green ring for the
					 * one being worked on, a hollow grey ring for the rest.
					 */
					if ( ! $finished && $cc_n === $step ) {
						$cc_state = 'is-on';
					} elseif ( $finished || CardsCo_Setup::done( $programme, $cc_n ) ) {
						$cc_state = 'is-done';
					} else {
						$cc_state = '';
					}
					?>
					<li class="cc-wz-rail-row <?php echo esc_attr( $cc_state ); ?>">
						<span class="cc-wz-rail-dot" aria-hidden="true">
							<?php if ( 'is-done' === $cc_state ) : ?>
								<?php echo call_user_func( array( $cc, 'icon' ), 'check', 'cc-wz-rail-tick' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
							<?php endif; ?>
						</span>
						<span class="cc-wz-rail-text">
							<span class="cc-wz-rail-label"><?php echo esc_html( $cc_step['label'] ); ?></span>
							<span class="cc-wz-rail-value"><?php echo esc_html( $rail['values'][ $cc_n ] ); ?></span>
						</span>
						<?php if ( 'card' === $cc_step['key'] && ! empty( $rail['thumb'] ) ) : ?>
							<img class="cc-wz-rail-thumb" src="<?php echo esc_url( $rail['thumb'] ); ?>" alt="" />
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="cc-wz-price">
				<span><?php esc_html_e( 'Price per card', 'cardsco' ); ?></span>
				<span class="cc-wz-price-value"><?php echo esc_html( $rail['price'] ); ?></span>
			</p>
			<p class="cc-wz-price-note"><?php echo esc_html( $rail['price_note'] ); ?></p>
		</aside>
		<?php endif; ?>
	</div>

	<footer class="cc-wz-foot">
		<?php if ( $finished ) : ?>

			<?php /* Nothing to continue to: the only way on is out. */ ?>
			<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( $links['exit'] ); ?>">
				<span><?php esc_html_e( 'Back to occasions', 'cardsco' ); ?></span>
			</a>

		<?php elseif ( $step > 1 ) : ?>
			<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( add_query_arg( 'step', $step - 1, $links['here'] ) ); ?>">
				<?php echo call_user_func( array( $cc, 'icon' ), 'arrow-left', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<span><?php esc_html_e( 'Back', 'cardsco' ); ?></span>
			</a>
		<?php else : ?>
			<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( $links['exit'] ); ?>">
				<?php echo call_user_func( array( $cc, 'icon' ), 'arrow-left', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<span><?php esc_html_e( 'Back', 'cardsco' ); ?></span>
			</a>
		<?php endif; ?>

		<?php if ( ! $finished ) : ?>
		<span class="cc-wz-state" data-cc-chosen="<?php echo esc_attr_x( '%s selected', 'what is chosen in the wizard footer', 'cardsco' ); ?>"><?php echo esc_html( $rail['footer'] ); ?></span>
		<?php endif; ?>

		<?php
		/*
		 * Continue submits the step's own form, which lives in the body — the
		 * footer is outside it, so the button reaches it by form attribute.
		 */
		?>
		<?php
		/*
		 * Hidden on step 2's add-a-person form, where the frame's footer
		 * carries only Back and the running count: the panel has its own
		 * Save person, and a second primary button beside it would be two
		 * ways to do one thing. It comes back on the saved state.
		 */
		?>
		<?php if ( ! $finished && empty( $rail['hide_continue'] ) ) : ?>
		<button type="submit" form="ccWizForm" class="cc-btn cc-btn-primary cc-btn-r8"<?php echo $rail['can_continue'] ? '' : ' disabled'; ?>>
			<span><?php echo esc_html( $rail['continue'] ); ?></span>
		</button>
		<?php endif; ?>

		<?php if ( ! $finished && ! empty( $rail['writer_save'] ) ) : ?>
			<?php
			/*
			 * The writer's save, where the Continue button is on every
			 * other screen. It submits the writer's own form from out here
			 * by its form attribute, and is enabled and disabled with the
			 * tick inside the box, by the same script.
			 */
			?>
			<button type="submit" form="ccWriter" class="cc-btn cc-btn-primary cc-btn-r8" data-cc-go>
				<span><?php echo esc_html( $rail['writer_save'] ); ?></span>
			</button>
		<?php endif; ?>
	</footer>
</div>
