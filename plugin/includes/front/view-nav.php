<?php
/**
 * The dashboard sidebar.
 *
 * 240px and fixed on a desktop, a drawer behind a menu button on a phone,
 * which is what the component in the design says it should do.
 *
 * The structure follows the Side Bar frame in the design system exactly:
 *
 *   Top
 *     Header     logo · search · collapse
 *     Nav        Dashboard / Occasions / Campaigns (count) / Orders
 *     Section    "Recent campaigns" · search · + · …
 *     Recent     the latest campaigns
 *   Footer
 *     Upgrade link (optional, hidden unless an address is given)
 *     Actions    notifications · settings · help
 *
 * @package CardsCo
 *
 * @var array[] $items
 * @var array[] $recent
 * @var array   $atts
 * @var bool    $search
 * @var bool    $collapsible
 * @var bool    $has_foot
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';

$cc_new = add_query_arg( 'new', '1', $atts['campaigns'] );
?>
<?php
/*
 * On a phone the sidebar becomes two pieces: a bar across the top that
 * carries the logo, and a row of tabs along the bottom.
 *
 * The bar used to be a bare "Menu" button and nothing else — no logo, no
 * sign of which product you were in, and every destination hidden behind
 * a tap. Tabs put the three screens people actually use in the thumb's
 * reach and leave them visible, which is the point: a drawer hides
 * navigation, and people stop exploring what they cannot see.
 *
 * The right-hand side of the bar is deliberately empty. A bell sat there
 * in the drawing, and there is nothing yet for it to report; a button
 * that does nothing is worse than a gap.
 *
 * Both pieces are hidden on a desktop, where the sidebar itself is right.
 */
$cc_tabs = array();

foreach ( $items as $cc_item ) {
	if ( __( 'Orders', 'cardsco' ) !== $cc_item['label'] ) {
		$cc_tabs[] = $cc_item;
	}
}
?>
<header class="cc-mbar" role="banner">
	<a class="cc-mbar-logo" href="<?php echo esc_url( $atts['dashboard'] ); ?>"
		aria-label="<?php esc_attr_e( 'CardsCo — go to your dashboard', 'cardsco' ); ?>">
		<img src="<?php echo esc_url( $atts['logo'] ? $atts['logo'] : CARDSCO_URL . 'assets/img/logo.svg' ); ?>" alt="CardsCo" />
	</a>

	<?php
	/*
	 * The bell reports nothing yet, and says so rather than sitting there
	 * inert. There is real material for it — recipients with no address, a
	 * campaign waiting on review, an occasion abandoned halfway — but
	 * gathering that is its own piece of work, so until then it tells the
	 * truth: there is nothing waiting.
	 */
	?>
	<div class="cc-mbell">
		<button type="button" class="cc-mbell-btn" data-cc-bell
			aria-controls="ccBell" aria-expanded="false"
			aria-label="<?php esc_attr_e( 'Anything needing attention', 'cardsco' ); ?>">
			<?php echo call_user_func( array( $cc, 'icon' ), 'bell', '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</button>

		<div class="cc-mbell-panel" id="ccBell" role="status" hidden>
			<p class="cc-mbell-head"><?php esc_html_e( 'You’re all caught up', 'cardsco' ); ?></p>
			<p class="cc-mbell-sub"><?php esc_html_e( 'Anything needing you — a missing address, a campaign waiting — will show up here.', 'cardsco' ); ?></p>
		</div>
	</div>
</header>

<nav class="cc-mtabs" aria-label="<?php esc_attr_e( 'Sections', 'cardsco' ); ?>">
	<?php foreach ( $cc_tabs as $cc_tab ) : ?>
		<?php $cc_on = call_user_func( array( $cc, 'is_here' ), $cc_tab['url'] ); ?>
		<a class="cc-mtab<?php echo $cc_on ? ' is-on' : ''; ?>" href="<?php echo esc_url( $cc_tab['url'] ); ?>"
			<?php echo $cc_on ? 'aria-current="page"' : ''; ?>>
			<span class="cc-mtab-glyph">
				<?php echo call_user_func( array( $cc, 'icon' ), $cc_tab['icon'], '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<?php if ( ! empty( $cc_tab['count'] ) ) : ?>
					<span class="cc-mtab-count"><?php echo esc_html( number_format_i18n( (int) $cc_tab['count'] ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="cc-mtab-label"><?php echo esc_html( $cc_tab['label'] ); ?></span>
		</a>
	<?php endforeach; ?>

	<button type="button" class="cc-mtab" data-cc-nav-open aria-controls="ccNav" aria-expanded="false">
		<span class="cc-mtab-glyph">
			<?php echo call_user_func( array( $cc, 'icon' ), 'menu', '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
		</span>
		<span class="cc-mtab-label"><?php esc_html_e( 'More', 'cardsco' ); ?></span>
	</button>
</nav>

<div class="cc-nav-veil" data-cc-nav-close hidden></div>

<nav class="cc cc-nav<?php echo $atts['logo_mark'] ? ' has-mark' : ''; ?>" id="ccNav" aria-label="<?php esc_attr_e( 'Dashboard', 'cardsco' ); ?>">
	<div class="cc-nav-top">

		<div class="cc-nav-header">
			<?php
			/*
			 * The name sits on the link rather than on an image, because the
			 * rail hides the wordmark and shows the square mark instead. Put it
			 * on either image and the link loses its name in one of the two
			 * states; put it here and it reads the same in both.
			 */
			?>
			<a class="cc-nav-logo" href="<?php echo esc_url( $atts['dashboard'] ); ?>"
				aria-label="<?php echo esc_attr( $atts['logo_alt'] ); ?>">
				<?php
				// Falls back to the logo bundled with the plugin rather than
				// to a text wordmark, so the sidebar is branded with no
				// upload and no attribute. A logo= attribute still wins.
				$cc_wordmark = $atts['logo'] ? $atts['logo'] : CARDSCO_URL . 'assets/img/logo.svg';
				?>
				<img class="cc-nav-wordmark" src="<?php echo esc_url( $cc_wordmark ); ?>" alt="" />

				<?php if ( $atts['logo_mark'] ) : ?>
					<img class="cc-nav-mark" src="<?php echo esc_url( $atts['logo_mark'] ); ?>" alt="" />
				<?php endif; ?>
			</a>

			<div class="cc-nav-actions">
				<?php if ( $search ) : ?>
					<button type="button" class="cc-nav-ibtn" data-cc-find-toggle
						aria-controls="ccNavFind" aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Search the menu', 'cardsco' ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'search', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</button>
				<?php endif; ?>

				<?php if ( $collapsible ) : ?>
					<button type="button" class="cc-nav-ibtn cc-nav-collapse" data-cc-collapse
						aria-controls="ccNav" aria-expanded="true"
						aria-label="<?php esc_attr_e( 'Collapse the sidebar', 'cardsco' ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'collapse', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</button>
				<?php endif; ?>

				<button type="button" class="cc-nav-shut" data-cc-nav-close
					aria-label="<?php esc_attr_e( 'Close menu', 'cardsco' ); ?>">
					<?php echo call_user_func( array( $cc, 'icon' ), 'close', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</button>
			</div>
		</div>

		<?php if ( $search ) : ?>
			<div class="cc-nav-find" id="ccNavFind" hidden>
				<?php echo call_user_func( array( $cc, 'icon' ), 'search', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<input type="search" data-cc-find
					placeholder="<?php esc_attr_e( 'Search', 'cardsco' ); ?>"
					aria-label="<?php esc_attr_e( 'Search the menu and recent campaigns', 'cardsco' ); ?>" />
			</div>
		<?php endif; ?>

		<ul class="cc-nav-list">
			<?php foreach ( $items as $cc_item ) : ?>
				<?php $cc_here = call_user_func( array( $cc, 'is_here' ), $cc_item['url'] ); ?>
				<li data-cc-findable="<?php echo esc_attr( $cc_item['label'] ); ?>">
					<a class="cc-nav-link<?php echo $cc_here ? ' is-here' : ''; ?>"
						href="<?php echo esc_url( $cc_item['url'] ); ?>"
						<?php echo $cc_here ? ' aria-current="page"' : ''; ?>>
						<?php echo call_user_func( array( $cc, 'icon' ), $cc_item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<span class="cc-nav-label"><?php echo esc_html( $cc_item['label'] ); ?></span>
						<?php if ( (int) $cc_item['count'] > 0 ) : ?>
							<span class="cc-nav-count">
								<?php echo esc_html( number_format_i18n( (int) $cc_item['count'] ) ); ?>
								<span class="screen-reader-text"><?php esc_html_e( 'needing review', 'cardsco' ); ?></span>
							</span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $recent ) : ?>
			<div class="cc-nav-section">
				<p class="cc-nav-heading">
					<span><?php esc_html_e( 'Recent campaigns', 'cardsco' ); ?></span>
					<span class="cc-nav-heading-actions">
						<?php if ( $search ) : ?>
							<button type="button" class="cc-nav-ibtn is-small" data-cc-find-toggle
								aria-controls="ccNavFind" aria-expanded="false"
								aria-label="<?php esc_attr_e( 'Search recent campaigns', 'cardsco' ); ?>">
								<?php echo call_user_func( array( $cc, 'icon' ), 'search', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
							</button>
						<?php endif; ?>

						<a class="cc-nav-ibtn is-small" href="<?php echo esc_url( $cc_new ); ?>"
							aria-label="<?php esc_attr_e( 'Start a campaign', 'cardsco' ); ?>">
							<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						</a>

						<button type="button" class="cc-nav-ibtn is-small" data-cc-more-toggle
							aria-controls="ccNavMore" aria-expanded="false" aria-haspopup="true"
							aria-label="<?php esc_attr_e( 'More campaign actions', 'cardsco' ); ?>">
							<?php echo call_user_func( array( $cc, 'icon' ), 'dots', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						</button>
					</span>
				</p>

				<div class="cc-nav-more" id="ccNavMore" hidden>
					<a href="<?php echo esc_url( $atts['campaigns'] ); ?>"><?php esc_html_e( 'View all campaigns', 'cardsco' ); ?></a>
					<a href="<?php echo esc_url( $cc_new ); ?>"><?php esc_html_e( 'Start a campaign', 'cardsco' ); ?></a>
				</div>
			</div>

			<ul class="cc-nav-recent">
				<?php foreach ( $recent as $cc_c ) : ?>
					<?php $cc_line = call_user_func( array( $cc, 'campaign_line' ), $cc_c ); ?>
					<li data-cc-findable="<?php echo esc_attr( $cc_c['name'] ); ?>">
						<?php
						/*
						 * To the campaign, not to the list it is on. Every
						 * one of these pointed at the bare campaigns page,
						 * so a list headed "Recent campaigns", with each
						 * one named and its state beside it, took you to
						 * the same screen whichever you picked — and left
						 * you to find it again yourself.
						 */
						?>
						<a href="<?php echo esc_url( add_query_arg( CardsCo_Builder::ARG, (int) $cc_c['id'], $atts['campaigns'] ) ); ?>">
							<span class="cc-nav-recent-name"><?php echo esc_html( $cc_c['name'] ); ?></span>
							<span class="cc-nav-recent-line<?php echo $cc_line['tone'] ? ' is-' . esc_attr( $cc_line['tone'] ) : ''; ?>">
								<?php echo esc_html( $cc_line['text'] ); ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $search ) : ?>
			<p class="cc-nav-nothing" data-cc-find-empty hidden><?php esc_html_e( 'Nothing matches that.', 'cardsco' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $has_foot ) : ?>
		<div class="cc-nav-foot">
			<?php if ( $atts['upgrade'] ) : ?>
				<a class="cc-nav-upgrade" href="<?php echo esc_url( $atts['upgrade'] ); ?>">
					<?php echo call_user_func( array( $cc, 'icon' ), 'spark', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					<span><?php esc_html_e( 'Upgrade plan', 'cardsco' ); ?></span>
				</a>
			<?php endif; ?>

			<div class="cc-nav-actions">
				<?php
				/*
				 * These are icons with no words in them, so the search had
				 * nothing to read and "settings" found nothing at all. The
				 * label they already carry for screen readers is the same
				 * word somebody types, so the search reads that.
				 */
				?>
				<?php if ( $atts['notifications'] ) : ?>
					<a class="cc-nav-ibtn" href="<?php echo esc_url( $atts['notifications'] ); ?>"
						data-cc-findable="<?php esc_attr_e( 'Notifications', 'cardsco' ); ?>"
						aria-label="<?php esc_attr_e( 'Notifications', 'cardsco' ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'bell', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</a>
				<?php endif; ?>

				<?php if ( $atts['settings'] ) : ?>
					<?php $cc_here = call_user_func( array( $cc, 'is_here' ), $atts['settings'] ); ?>
					<a class="cc-nav-ibtn<?php echo $cc_here ? ' is-here' : ''; ?>"
						href="<?php echo esc_url( $atts['settings'] ); ?>"
						<?php echo $cc_here ? ' aria-current="page"' : ''; ?>
						data-cc-findable="<?php esc_attr_e( 'Settings', 'cardsco' ); ?>"
						aria-label="<?php esc_attr_e( 'Settings', 'cardsco' ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'gear', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</a>
				<?php endif; ?>

				<?php if ( $atts['help'] ) : ?>
					<a class="cc-nav-ibtn" href="<?php echo esc_url( $atts['help'] ); ?>"
						data-cc-findable="<?php esc_attr_e( 'Help', 'cardsco' ); ?>"
						aria-label="<?php esc_attr_e( 'Help', 'cardsco' ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'help', 'cc-nav-glyph' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</nav>
