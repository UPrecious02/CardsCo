<?php
/**
 * Step 1: which occasion.
 *
 * @package CardsCo
 *
 * @var CardsCo_Admin $admin
 * @var int           $company_id
 * @var array         $categories
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Set up an occasion', 'cardsco' ); ?></h1>

	<?php $admin->print_notices(); ?>

	<?php if ( ! $company_id ) : ?>
		<div class="cardsco-empty">
			<p><strong><?php esc_html_e( 'Pick a company first.', 'cardsco' ); ?></strong></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cardsco-companies' ) ); ?>"><?php esc_html_e( 'Go to Companies', 'cardsco' ); ?></a>
		</div>
	</div>
		<?php return; ?>
	<?php endif; ?>

	<div class="cardsco-bar">
		<?php $admin->company_switcher( $company_id, CardsCo_Wizard::PAGE ); ?>
	</div>

	<p class="cardsco-stepline"><?php esc_html_e( 'Step 1 of 7', 'cardsco' ); ?></p>
	<h2 class="cardsco-stephead"><?php esc_html_e( 'Which occasion?', 'cardsco' ); ?></h2>
	<p class="description" style="margin-bottom:18px"><?php esc_html_e( 'Pick one. You can set up the others any time.', 'cardsco' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( CardsCo_Admin::NONCE ); ?>
		<input type="hidden" name="action" value="cardsco_programme_start" />
		<input type="hidden" name="company_id" value="<?php echo esc_attr( $company_id ); ?>" />

		<div class="cardsco-cards">
			<?php foreach ( $categories as $cardsco_slug => $cardsco_cat ) : ?>
				<?php
				$cardsco_soon    = ! empty( $cardsco_cat['coming_soon'] );
				$cardsco_designs = CardsCo_Category::design_count( $cardsco_slug, $company_id );
				?>
				<label class="cardsco-choice <?php echo $cardsco_soon ? 'is-soon' : ''; ?>">
					<input type="radio" name="category" value="<?php echo esc_attr( $cardsco_slug ); ?>" <?php disabled( $cardsco_soon ); ?> required />
					<span class="cardsco-choice-body">
						<span class="cardsco-choice-title">
							<?php echo esc_html( $cardsco_cat['label'] ); ?>
							<?php if ( $cardsco_soon ) : ?>
								<span class="cardsco-pill is-warn"><?php esc_html_e( 'Coming soon', 'cardsco' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="cardsco-choice-blurb"><?php echo esc_html( $cardsco_cat['blurb'] ); ?></span>
						<span class="cardsco-choice-meta">
							<?php
							printf(
								/* translators: 1: number of designs, 2: card format. */
								esc_html( _n( '%1$d design · %2$s', '%1$d designs · %2$s', $cardsco_designs, 'cardsco' ) ),
								(int) $cardsco_designs,
								esc_html( $cardsco_cat['format'] )
							);
							?>
						</span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:20px">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'cardsco' ); ?></button>
			<a class="button" href="<?php echo esc_url( CardsCo_Wizard::url( array( 'company' => $company_id ) ) ); ?>"><?php esc_html_e( 'Cancel', 'cardsco' ); ?></a>
		</p>
	</form>
</div>
