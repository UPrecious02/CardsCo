<?php
/**
 * The dashboard overview.
 *
 * Built against the measured Dashboard frames (9:5965 empty, 11:9058 filled),
 * not from the brief: a page header, a "Get started" band, a toolbar, and
 * then a grid of card tiles. The card tiles are the screen — each one is a
 * miniature of the card itself.
 *
 * @package CardsCo
 *
 * @var array   $summary
 * @var array[] $cards
 * @var array[] $held
 * @var string[] $occasions
 * @var array   $toolbar
 * @var int     $sent_year
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';
?>
<div class="cc cc-main">

	<?php
	/*
	 * Inside the main column, not above it. As a sibling of .cc-main the band
	 * became a second item in the portal's row flex, so it sat alongside the
	 * heading instead of over it. In here it is a column item like everything
	 * else, full width, first thing on the page.
	 */
	require __DIR__ . '/part-verify-banner.php';
	?>

	<header class="cc-page-head">
		<?php
		/*
		 * Greets whoever is reading rather than naming the screen they are
		 * already looking at. Falls back through what is actually known:
		 * their first name, then the company's, then a plain welcome — so
		 * it never renders "Welcome back," with a gap after the comma.
		 */
		$cc_who  = trim( (string) wp_get_current_user()->first_name );
		$cc_firm = CardsCo_Company::get( (int) CardsCo_Access::company_id() );
		$cc_firm = $cc_firm ? trim( (string) $cc_firm['name'] ) : '';
		$cc_name = $cc_who ? $cc_who : $cc_firm;

		$cc_hour  = (int) current_time( 'G' );
		$cc_greet = __( 'Good evening', 'cardsco' );

		if ( $cc_hour < 12 ) {
			$cc_greet = __( 'Good morning', 'cardsco' );
		} elseif ( $cc_hour < 18 ) {
			$cc_greet = __( 'Good afternoon', 'cardsco' );
		}
		?>
		<h1 class="cc-page-title">
			<?php
			if ( $cc_name ) {
				printf(
					/* translators: 1: Good morning/afternoon/evening, 2: the person or company's name. */
					esc_html__( '%1$s, %2$s', 'cardsco' ),
					esc_html( $cc_greet ),
					esc_html( $cc_name )
				);
			} else {
				echo esc_html( $cc_greet );
			}
			?>
		</h1>
		<p class="cc-page-sub">
			<?php
			printf(
				/* translators: 1: cards sent this year, 2: cards posting in the next 30 days. */
				esc_html( _n( '%1$s card sent this year · %2$s posting in the next 30 days', '%1$s cards sent this year · %2$s posting in the next 30 days', $sent_year, 'cardsco' ) ),
				esc_html( number_format_i18n( $sent_year ) ),
				esc_html( number_format_i18n( (int) $summary['upcoming'] ) )
			);
			?>
		</p>
	</header>

	<?php if ( $held ) : ?>
		<?php
		/*
		 * Not in the frame, but a held card never posts, and the frame has
		 * nowhere else to say so. Kept to the width of the band above the
		 * toolbar so it does not disturb the grid.
		 */
		?>
		<div class="cc-notice cc-notice-warn">
			<p class="cc-notice-title">
				<?php
				printf(
					/* translators: %d: number of cards. */
					esc_html( _n( '%d card needs your attention', '%d cards need your attention', count( $held ), 'cardsco' ) ),
					count( $held )
				);
				?>
			</p>
			<p class="cc-notice-body"><?php esc_html_e( 'These will not go out until they are sorted.', 'cardsco' ); ?></p>
			<ul class="cc-notice-list">
				<?php foreach ( $held as $cc_card ) : ?>
					<?php
					/*
					 * The queue keeps the recipient's name as it was when the
					 * card was lined up, and a row can reach here without one
					 * — somebody uploaded with an email and no name, or the
					 * card was queued by a path that did not carry it. The
					 * row then rendered as an empty span above the reason,
					 * so the panel said five cards needed attention and gave
					 * no way to tell which five.
					 *
					 * The occasion and its date are already on the row, so
					 * they stand in: it still identifies the card.
					 */
					$cc_who = trim( (string) $cc_card['recipient_name'] );

					if ( '' === $cc_who ) {
						$cc_when = $cc_card['event_date'] ? mysql2date( get_option( 'date_format' ), $cc_card['event_date'] ) : '';
						$cc_who  = trim( (string) $cc_card['occasion_label'] );
						$cc_who  = $cc_when ? trim( $cc_who . ' · ' . $cc_when, ' ·' ) : $cc_who;
					}

					if ( '' === $cc_who ) {
						/* translators: %d: the card's own number. */
						$cc_who = sprintf( __( 'Card %d', 'cardsco' ), (int) $cc_card['id'] );
					}
					?>
					<li>
						<span class="cc-notice-who"><?php echo esc_html( $cc_who ); ?></span>
						<span class="cc-notice-why"><?php echo esc_html( call_user_func( array( $cc, 'hold_reason' ), $cc_card['hold_reason'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<section class="cc-band">
		<h2 class="cc-band-head"><?php esc_html_e( 'Get started', 'cardsco' ); ?></h2>

		<div class="cc-band-row">
			<article class="cc-promo">
				<div class="cc-promo-body">
					<div class="cc-promo-text">
						<h3 class="cc-promo-title"><?php esc_html_e( 'Start a campaign', 'cardsco' ); ?></h3>
						<p class="cc-promo-sub"><?php esc_html_e( 'Send a card to many people at once', 'cardsco' ); ?></p>
					</div>
					<a class="cc-btn cc-btn-primary" href="<?php echo esc_url( $toolbar['new_campaign'] ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'megaphone', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<span><?php esc_html_e( 'Start a campaign', 'cardsco' ); ?></span>
					</a>
				</div>
				<div class="cc-promo-art" aria-hidden="true">
					<?php echo call_user_func( array( $cc, 'art' ), 'envelope-open' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg read from the plugin. ?>
				</div>
			</article>

			<article class="cc-promo">
				<div class="cc-promo-body">
					<div class="cc-promo-text">
						<h3 class="cc-promo-title"><?php esc_html_e( 'Send a Card', 'cardsco' ); ?></h3>
						<p class="cc-promo-sub"><?php esc_html_e( 'One card, written and posted for you', 'cardsco' ); ?></p>
					</div>
					<a class="cc-btn cc-btn-secondary" href="<?php echo esc_url( $toolbar['new_card'] ); ?>">
						<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
						<span><?php esc_html_e( 'Create card', 'cardsco' ); ?></span>
					</a>
				</div>
				<div class="cc-promo-art" aria-hidden="true">
					<?php echo call_user_func( array( $cc, 'art' ), 'card-stack' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg read from the plugin. ?>
				</div>
			</article>
		</div>
	</section>

	<div class="cc-toolbar">
		<div class="cc-seg" role="group" aria-label="<?php esc_attr_e( 'Filter cards', 'cardsco' ); ?>">
			<?php foreach ( $toolbar['views'] as $cc_key => $cc_view ) : ?>
				<a class="cc-seg-item<?php echo $cc_view['on'] ? ' is-on' : ''; ?>"
					href="<?php echo esc_url( $cc_view['url'] ); ?>"
					<?php echo $cc_view['on'] ? ' aria-current="true"' : ''; ?>
					<?php echo $cc_view['label'] ? '' : ' aria-label="' . esc_attr( $cc_view['title'] ) . '"'; ?>>
					<?php if ( $cc_view['icon'] ) : ?>
						<?php echo call_user_func( array( $cc, 'icon' ), $cc_view['icon'], 'cc-seg-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
					<?php endif; ?>
					<?php if ( $cc_view['label'] ) : ?>
						<span><?php echo esc_html( $cc_view['label'] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="cc-filters">
			<div class="cc-drop">
				<button type="button" class="cc-btn cc-btn-quiet" data-cc-drop aria-expanded="false" aria-controls="ccOccasions">
					<span><?php echo esc_html( $toolbar['occasion_label'] ); ?></span>
					<?php echo call_user_func( array( $cc, 'icon' ), 'chevron-down', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</button>
				<div class="cc-drop-menu" id="ccOccasions" hidden>
					<a href="<?php echo esc_url( $toolbar['all_occasions'] ); ?>"><?php esc_html_e( 'All occasions', 'cardsco' ); ?></a>
					<?php foreach ( $occasions as $cc_label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'occasion', rawurlencode( $cc_label ), $toolbar['base'] ) ); ?>">
							<?php echo esc_html( $cc_label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<a class="cc-btn cc-btn-quiet" href="<?php echo esc_url( $toolbar['sort_url'] ); ?>">
				<span><?php echo esc_html( $toolbar['sort_label'] ); ?></span>
				<?php echo call_user_func( array( $cc, 'icon' ), 'chevrons', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</a>
		</div>
	</div>

	<?php if ( ! $cards ) : ?>
		<?php /* The empty state is its own full-width tile in the frame, 440 tall. */ ?>
		<div class="cc-empty">
			<div class="cc-empty-art" aria-hidden="true">
				<?php echo call_user_func( array( $cc, 'icon' ), 'megaphone', 'cc-empty-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
			</div>
			<p class="cc-empty-title"><?php esc_html_e( 'No campaigns yet', 'cardsco' ); ?></p>
			<p class="cc-empty-body"><?php esc_html_e( 'Send a card to every customer on your list. We’ll write and post each one.', 'cardsco' ); ?></p>
			<a class="cc-btn cc-btn-secondary" href="<?php echo esc_url( $toolbar['new_campaign'] ); ?>">
				<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-btn-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				<span><?php esc_html_e( 'Start a campaign', 'cardsco' ); ?></span>
			</a>
		</div>
	<?php else : ?>
		<div class="cc-grid">
			<a class="cc-ctile cc-ctile-new" href="<?php echo esc_url( $toolbar['new_card'] ); ?>">
				<span class="cc-ctile-plus" aria-hidden="true">
					<?php echo call_user_func( array( $cc, 'icon' ), 'plus', 'cc-ctile-plus-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- inline svg built in PHP. ?>
				</span>
				<span class="cc-ctile-new-title"><?php esc_html_e( 'Create a card', 'cardsco' ); ?></span>
				<span class="cc-ctile-new-body"><?php esc_html_e( 'One card, a bulk order for the office, or a campaign to many customers', 'cardsco' ); ?></span>
			</a>

			<?php foreach ( $cards as $cc_send ) : ?>
				<?php
				$cc_badge = call_user_func( array( $cc, 'badge' ), $cc_send );
				$cc_msg   = call_user_func( array( $cc, 'card_message' ), $cc_send );
				?>
				<article class="cc-ctile">
					<div class="cc-ctile-preview">
						<div class="cc-ctile-paper">
							<?php if ( $cc_msg['body'] ) : ?>
								<p class="cc-ctile-msg"><?php echo esc_html( $cc_msg['body'] ); ?></p>
							<?php else : ?>
								<?php /* No message written yet; the paper still has to look like paper. */ ?>
								<p class="cc-ctile-msg is-blank" aria-hidden="true"><span></span><span></span><span></span></p>
								<span class="screen-reader-text"><?php esc_html_e( 'No message written yet', 'cardsco' ); ?></span>
							<?php endif; ?>
							<?php if ( $cc_msg['sign'] ) : ?>
								<p class="cc-ctile-sign"><?php echo esc_html( $cc_msg['sign'] ); ?></p>
							<?php endif; ?>
						</div>
						<span class="cc-badge is-<?php echo esc_attr( $cc_badge['tone'] ); ?> cc-ctile-badge">
							<span class="cc-badge-dot" aria-hidden="true"></span>
							<?php echo esc_html( $cc_badge['label'] ); ?>
						</span>
					</div>

					<?php
					/*
					 * Name, then occasion and date, then the card count. The
					 * frame's Details strip is 70 tall with padding 12/16 and
					 * an 8 gap, which is exactly a 20 row plus an 18 row — the
					 * badge row inside it overflows the tile in Figma, and the
					 * badge is already on the preview above, so it is not
					 * repeated here. Flagged for the designer.
					 */
					?>
					<div class="cc-ctile-details">
						<p class="cc-ctile-row">
							<span class="cc-ctile-name"><?php echo esc_html( $cc_send['title'] ); ?></span>
						</p>
						<p class="cc-ctile-row cc-ctile-meta-row">
							<span class="cc-ctile-meta">
								<?php echo esc_html( call_user_func( array( $cc, 'send_meta' ), $cc_send ) ); ?>
							</span>
							<span class="cc-ctile-count">
								<?php
								printf(
									/* translators: %s: number of cards. */
									esc_html( _n( '%s card', '%s cards', (int) $cc_send['count'], 'cardsco' ) ),
									esc_html( number_format_i18n( (int) $cc_send['count'] ) )
								);
								?>
							</span>
						</p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
