<?php
/**
 * The campaign builder's shell.
 *
 * Deliberately the same furniture as the occasion wizard — the same step
 * rail, the same footer bar, the same cc-wz- classes — because the two are
 * the same kind of task and somebody who has done one should recognise the
 * other. It is a separate file rather than a shared one so that changing
 * the campaign screens cannot break the occasion wizard, which is live and
 * being used.
 *
 * @package CardsCo
 *
 * @var array   $campaign
 * @var int     $step
 * @var array[] $steps
 * @var string  $error
 * @var array   $links
 * @var string  $body  The step's own view file.
 * @var bool    $finished
 */

defined( 'ABSPATH' ) || exit;

$cc      = 'CardsCo_Shortcodes';
$cc_spec = $steps[ $step ] ?? array( 'heading' => '', 'label' => '' );
?>
<div class="cc cc-wz cc-bd">

	<header class="cc-wz-top">
		<div class="cc-wz-who">
			<span class="cc-wz-name">
				<?php echo esc_html( '' !== trim( (string) $campaign['name'] ) ? $campaign['name'] : __( 'New campaign', 'cardsco' ) ); ?>
			</span>
			<span class="cc-wz-saved">
				<?php echo esc_html( $finished ? __( 'Campaign · scheduled', 'cardsco' ) : __( 'Draft · saved', 'cardsco' ) ); ?>
			</span>
		</div>

		<?php require __DIR__ . '/part-wz-progress.php'; ?>

		<ol class="cc-wz-steps" id="ccWzSteps">
			<?php foreach ( $steps as $cc_n => $cc_step ) : ?>
				<?php
				/*
				 * Ticked only once it has actually been passed, not merely
				 * because nothing is outstanding on it.
				 *
				 * The offer step counts as complete with no offer on it,
				 * which is right — an offer is optional — but it meant step
				 * 5 wore a tick from the moment the campaign was created,
				 * while steps 3 and 4 in front of it sat undone. The
				 * stepper read as though the thing had been filled in out
				 * of order, and the tick was a link into a step the person
				 * had never seen.
				 */
				$cc_passed = $cc_n < (int) $campaign['builder_step'];
				$cc_done   = $finished || ( $cc_passed && CardsCo_Campaign::step_complete( $campaign, $cc_n ) );
				$cc_state = ( ! $finished && $cc_n === $step ) ? 'is-on' : ( $cc_done ? 'is-done' : '' );
				?>
				<li class="cc-wz-step <?php echo esc_attr( $cc_state ); ?>">
					<?php if ( $cc_done && ! $finished ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'step', $cc_n, $links['here'] ) ); ?>">
							<span class="cc-wz-num">
								<?php echo call_user_func( array( $cc, 'icon' ), 'check', 'cc-wz-num-tick' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
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

	<div class="cc-wz-body is-wide">
		<div class="cc-wz-content">
			<?php if ( $error ) : ?>
				<div class="cc-wz-error" role="alert"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<p class="cc-wz-of<?php echo $finished ? ' is-done' : ''; ?>">
				<?php
				if ( $finished ) {
					esc_html_e( 'Scheduled', 'cardsco' );
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

			<h1 class="cc-wz-head"><?php echo esc_html( $cc_spec['heading'] ); ?></h1>

			<?php include $body; ?>
		</div>
	</div>
</div>
