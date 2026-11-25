<?php
/**
 * Sending one card.
 *
 * Built from the Sending Card frames, minus the signed-in shell around
 * them: those frames sit inside the app, with Drafts and Sent cards down
 * the side, and this page has to work for somebody who has never signed
 * in. What is kept is the centre of each frame — the 500-wide panel the
 * work actually happens in — on a plain page.
 *
 * @package CardsCo
 *
 * @var array  $draft From CardsCo_Send::draft().
 * @var int    $step  Which step is on screen.
 * @var array  $cards From CardsCo_Catalogue::page().
 * @var array  $order Card price and total. Postage is inside the price.
 * @var string $here  This page.
 * @var string $error Anything to tell them.
 */

defined( 'ABSPATH' ) || exit;

$cc = 'CardsCo_Shortcodes';

$cc_names = array(
	1 => __( 'Who it’s for', 'cardsco' ),
	2 => __( 'Your message', 'cardsco' ),
	3 => __( 'The card', 'cardsco' ),
	4 => __( 'Your order', 'cardsco' ),
);

/*
 * Only the steps this visitor walks through. Somebody who came from the
 * catalogue has already chosen a card, so the card step is not one of them —
 * it opened with their own card selected and asked them to press Continue.
 * It is still reachable from "Change card" on the last step.
 *
 * The keys are the real step numbers, which never move; the row is numbered
 * by position, so a three-step walk counts one, two, three.
 */
$cc_steps = array();

foreach ( CardsCo_Send::visible_steps( $draft ) as $cc_at ) {
	if ( isset( $cc_names[ $cc_at ] ) ) {
		$cc_steps[ $cc_at ] = $cc_names[ $cc_at ];
	}
}

/* The step on screen may be one left out of the row, which is then drawn
   with none of its own marked current — so it is added back in place. */
if ( ! isset( $cc_steps[ $step ] ) && isset( $cc_names[ $step ] ) ) {
	$cc_steps[ $step ] = $cc_names[ $step ];
	ksort( $cc_steps );
}

$cc_shown = 0;
?>
<div class="cc cc-send">

	<?php
	/*
	 * Divs carrying list roles rather than ol and li.
	 *
	 * A page builder emits rules like "#inner_content li { display:
	 * inline-block }", which outranks any single class this component can
	 * write, and the steps fell out of their row onto different baselines
	 * on the live site while looking perfect everywhere else. Elements a
	 * theme does not style cannot be taken over that way, and the roles
	 * keep the meaning for anybody listening to the page.
	 */
	?>
	<div class="cc-send-steps" role="list">
		<?php foreach ( $cc_steps as $cc_n => $cc_label ) : ?>
			<?php
			$cc_shown++;
			$cc_state = '';

			if ( $cc_n === $step ) {
				$cc_state = ' is-on';
			} elseif ( $cc_n < $step || $cc_n <= (int) $draft['reached'] ) {
				$cc_state = ' is-done';
			}
			?>
			<div class="cc-send-step<?php echo esc_attr( $cc_state ); ?>" role="listitem">
				<?php if ( $cc_n < $step ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'step', $cc_n, $here ) ); ?>">
						<span class="cc-send-step-n"><?php echo esc_html( number_format_i18n( $cc_shown ) ); ?></span>
						<span><?php echo esc_html( $cc_label ); ?></span>
					</a>
				<?php else : ?>
					<span class="cc-send-step-n"><?php echo esc_html( number_format_i18n( $cc_shown ) ); ?></span>
					<span><?php echo esc_html( $cc_label ); ?></span>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $error ) : ?>
		<p class="cc-send-error" role="alert"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<?php
	/*
	 * autocomplete="off" on the form as well as on every field in it. The
	 * fields say "this is not the visitor's own address"; this says "do not
	 * put back what was typed here before", which is the other half and a
	 * different mechanism.
	 */
	?>
	<form class="cc-send-panel" method="post" action="<?php echo esc_url( $here ); ?>" id="ccSendForm" enctype="multipart/form-data"
		autocomplete="off"
		data-cc-max="<?php echo esc_attr( (int) CardsCo_Send::max_chars() ); ?>">
		<?php wp_nonce_field( 'cardsco_send', '_cardsco_nonce' ); ?>
		<input type="hidden" name="cardsco_send_action" value="step" />
		<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />

		<?php if ( 1 === $step ) : ?>

			<?php
			/*
			 * One card to one person was all this ever did. It can now be
			 * a card each for several named people, or a stack of the same
			 * card to one address — the bulk order the proposal describes
			 * for a company that does not want an account.
			 */
			$cc_batch = 'batch' === (string) $draft['mode'];
			$cc_list  = CardsCo_Send::cards_in( $draft );
			$cc_cap   = CardsCo_Send::cap();
			$cc_full  = ! $cc_batch && count( (array) $draft['people'] ) >= $cc_cap;
			?>

			<h1 class="cc-send-title">
				<?php echo esc_html( $cc_batch ? __( 'How many, and where to?', 'cardsco' ) : __( 'Who’s it for?', 'cardsco' ) ); ?>
			</h1>
			<p class="cc-send-sub">
				<?php
				echo esc_html(
					$cc_batch
						? __( 'We’ll write them all and post them to you in one parcel.', 'cardsco' )
						: __( 'We’ll post a card straight to each person’s address.', 'cardsco' )
				);
				?>
			</p>

			<?php if ( ! $cc_batch && ! empty( $draft['people'] ) ) : ?>
				<div class="cc-send-people" role="list">
					<?php foreach ( (array) $draft['people'] as $cc_at => $cc_person ) : ?>
						<div class="cc-send-person" role="listitem">
							<span class="cc-send-person-who">
								<strong><?php echo esc_html( $cc_person['name'] ); ?></strong>
								<?php if ( ! empty( $cc_person['company'] ) ) : ?>
									<span class="cc-send-person-co"><?php echo esc_html( $cc_person['company'] ); ?></span>
								<?php endif; ?>
								<span class="cc-send-person-at">
									<?php
									echo esc_html(
										implode(
											', ',
											array_filter(
												array(
													$cc_person['line1'],
													$cc_person['line2'],
													$cc_person['town'],
													$cc_person['postcode'],
												)
											)
										)
									);
									?>
								</span>
							</span>

							<?php
							/*
							 * The index travels in the address rather than
							 * in a field, because a form cannot hold
							 * another form and every row needs its own.
							 */
							?>
							<button type="submit" class="cc-btn cc-btn-quiet cc-send-person-off"
								formaction="<?php echo esc_url( add_query_arg( 'cc_person', (int) $cc_at, $here ) ); ?>"
								name="cardsco_send_action" value="remove_person">
								<span><?php esc_html_e( 'Remove', 'cardsco' ); ?></span>
							</button>
						</div>
					<?php endforeach; ?>
				</div>

				<p class="cc-send-count">
					<?php
					printf(
						/* translators: 1: how many people so far, 2: the most allowed. */
						esc_html__( '%1$s of %2$s', 'cardsco' ),
						esc_html( number_format_i18n( count( (array) $draft['people'] ) ) ),
						esc_html( number_format_i18n( $cc_cap ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $cc_full ) : ?>
				<section class="cc-send-more">
					<p class="cc-send-more-head">
						<?php
						printf(
							/* translators: %s: the most people allowed without an account. */
							esc_html__( 'That’s %s, which is as many as we can take without an account.', 'cardsco' ),
							esc_html( number_format_i18n( $cc_cap ) )
						);
						?>
					</p>
					<p class="cc-send-more-blurb">
						<?php esc_html_e( 'With an account you can keep a list, upload as many people as you like, and have cards go out on their birthdays and anniversaries year after year.', 'cardsco' ); ?>
					</p>
					<a class="cc-btn cc-btn-primary cc-btn-r8" href="<?php echo esc_url( CardsCo_Send::signup_link() ); ?>">
						<span><?php esc_html_e( 'Create an account', 'cardsco' ); ?></span>
					</a>
					<p class="cc-send-more-or">
						<?php esc_html_e( 'Or carry on and pay for these now.', 'cardsco' ); ?>
					</p>
				</section>
			<?php endif; ?>

			<?php if ( $cc_batch ) : ?>
				<p class="cc-field cc-field-wide">
					<label for="cc-send-copies"><?php esc_html_e( 'How many cards', 'cardsco' ); ?></label>
					<input type="number" id="cc-send-copies" name="copies" min="1" max="2000" inputmode="numeric"
						value="<?php echo esc_attr( (int) $draft['copies'] > 0 ? (int) $draft['copies'] : '' ); ?>" required />
				</p>
			<?php endif; ?>

			<?php if ( ! $cc_full || $cc_batch ) : ?>
				<?php
				/*
				 * The browser is told these are nobody's own details.
				 *
				 * They carried autocomplete="name", "address-line1" and the
				 * rest, which is what those tokens mean: the person filling
				 * the form in. These are not — they are whoever the card is
				 * going to, a different one each time round. Chrome read the
				 * tokens, saw a freshly emptied address form after each
				 * person was added, and filled it back in with an address it
				 * had seen before. Somebody adding a third person got the
				 * second one's details back, and a near-duplicate went onto
				 * the order.
				 *
				 * An unrecognised token is the only thing browsers act on
				 * here; autocomplete="off" on an address form is widely
				 * ignored.
				 */
				?>
				<div class="cc-send-fields">
					<p class="cc-field">
						<label for="cc-send-name"><?php echo esc_html( $cc_batch ? __( 'Full name', 'cardsco' ) : __( 'Full name', 'cardsco' ) ); ?></label>
						<input type="text" id="cc-send-name" name="name" value="<?php echo esc_attr( $draft['name'] ); ?>" autocomplete="cc-recipient"<?php echo $cc_batch ? ' required' : ''; ?> />
					</p>
					<p class="cc-field">
						<label for="cc-send-company"><?php esc_html_e( 'Company', 'cardsco' ); ?> <span class="cc-send-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
						<input type="text" id="cc-send-company" name="company" value="<?php echo esc_attr( $draft['company'] ); ?>" autocomplete="cc-recipient" />
					</p>
					<p class="cc-field cc-field-wide">
						<label for="cc-send-line1"><?php esc_html_e( 'Address line 1', 'cardsco' ); ?></label>
						<input type="text" id="cc-send-line1" name="line1" value="<?php echo esc_attr( $draft['line1'] ); ?>" autocomplete="cc-recipient"<?php echo $cc_batch ? ' required' : ''; ?> />
					</p>
					<p class="cc-field cc-field-wide">
						<label for="cc-send-line2"><?php esc_html_e( 'Address line 2', 'cardsco' ); ?> <span class="cc-send-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
						<input type="text" id="cc-send-line2" name="line2" value="<?php echo esc_attr( $draft['line2'] ); ?>" autocomplete="cc-recipient" />
					</p>
					<p class="cc-field">
						<label for="cc-send-town"><?php esc_html_e( 'Town or city', 'cardsco' ); ?></label>
						<input type="text" id="cc-send-town" name="town" value="<?php echo esc_attr( $draft['town'] ); ?>" autocomplete="cc-recipient"<?php echo $cc_batch ? ' required' : ''; ?> />
					</p>
					<p class="cc-field">
						<label for="cc-send-postcode"><?php esc_html_e( 'Postcode', 'cardsco' ); ?></label>
						<input type="text" id="cc-send-postcode" name="postcode" value="<?php echo esc_attr( $draft['postcode'] ); ?>" autocomplete="cc-recipient"<?php echo $cc_batch ? ' required' : ''; ?> />
					</p>
				</div>

				<?php if ( ! $cc_batch ) : ?>
					<?php
					/*
					 * Two different things, which used to sit on one row:
					 * a button, a bare file input and a second button, in a
					 * line, with nothing saying which went with which.
					 *
					 * Adding the person in front of you is the thing to do
					 * here, so it is on its own under the fields it belongs
					 * to. Uploading a list instead is an alternative, so it
					 * is below a rule, with its own heading and its own
					 * button beside the file it acts on.
					 */
					?>
					<div class="cc-send-adds">
						<button type="submit" class="cc-btn cc-btn-ghost" name="cardsco_send_action" value="add_person">
							<span><?php esc_html_e( 'Add this person', 'cardsco' ); ?></span>
						</button>
						<span class="cc-send-adds-note">
							<?php esc_html_e( 'Add everyone first, then carry on.', 'cardsco' ); ?>
						</span>
					</div>

					<div class="cc-send-upload">
						<p class="cc-send-upload-head"><?php esc_html_e( 'Or upload a list', 'cardsco' ); ?></p>
						<p class="cc-send-upload-sub">
							<?php esc_html_e( 'A CSV with a name, address, town and postcode in each row.', 'cardsco' ); ?>
						</p>

						<div class="cc-send-upload-row">
							<input type="file" id="cc-send-file" class="cc-send-file" name="people_file" accept=".csv,text/csv,text/plain" />
							<button type="submit" class="cc-btn cc-btn-ghost" name="cardsco_send_action" value="upload_people">
								<span><?php esc_html_e( 'Upload', 'cardsco' ); ?></span>
							</button>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<p class="cc-send-swap">
				<?php if ( $cc_batch ) : ?>
					<button type="submit" class="cc-link-btn"
						formaction="<?php echo esc_url( add_query_arg( 'cc_mode', 'people', $here ) ); ?>"
						name="cardsco_send_action" value="mode">
						<?php esc_html_e( 'Post to people at their own addresses instead', 'cardsco' ); ?>
					</button>
				<?php else : ?>
					<button type="submit" class="cc-link-btn"
						formaction="<?php echo esc_url( add_query_arg( 'cc_mode', 'batch', $here ) ); ?>"
						name="cardsco_send_action" value="mode">
						<?php esc_html_e( 'Or send a batch to one address, for you to hand out', 'cardsco' ); ?>
					</button>
				<?php endif; ?>
			</p>

		<?php elseif ( 2 === $step ) : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'What would you like to say?', 'cardsco' ); ?></h1>
			<p class="cc-send-sub">
				<?php
				$cc_to_list = CardsCo_Send::cards_in( $draft );

				if ( count( $cc_to_list ) > 1 ) {
					printf(
						/* translators: %s: how many people. */
						esc_html( _n( 'Write it in your own words. A pen writes it in real ink on %s card.', 'Write it in your own words. The same message is written by hand on all %s cards.', count( $cc_to_list ), 'cardsco' ) ),
						esc_html( number_format_i18n( count( $cc_to_list ) ) )
					);
				} else {
					printf(
						/* translators: %s: the recipient's first name. */
						esc_html__( 'Write it in your own words. A pen writes it in real ink and we post it to %s.', 'cardsco' ),
						esc_html( CardsCo_Send::first_name( $cc_to_list ? $cc_to_list[0]['name'] : $draft['name'] ) )
					);
				}
				?>
			</p>

			<?php
			$cc_starters = CardsCo_Send::starters( 6, (int) $draft['card_id'] );

			if ( $cc_starters ) :
				?>
				<div class="cc-send-starters">
					<p class="cc-send-starters-head"><?php esc_html_e( 'Write your message, or start from one of ours', 'cardsco' ); ?></p>
					<div class="cc-send-chips">
						<?php foreach ( $cc_starters as $cc_one ) : ?>
							<button type="button" class="cc-send-chip" data-cc-fill="<?php echo esc_attr( $cc_one['text'] ); ?>">
								<?php echo esc_html( $cc_one['label'] ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<p class="cc-field cc-field-wide">
				<label class="cc-sr" for="cc-send-message"><?php esc_html_e( 'Your message', 'cardsco' ); ?></label>
				<textarea id="cc-send-message" name="message" rows="6" data-cc-text required><?php echo esc_textarea( $draft['message'] ); ?></textarea>
				<span class="cc-send-count" data-cc-count aria-live="polite"></span>
				<?php if ( CardsCo_Send::max_chars() > 0 ) : ?>
					<span class="cc-send-limit">
						<?php
						printf(
							/* translators: %d: how many characters fit on a card. */
							esc_html__( 'Up to %d characters', 'cardsco' ),
							(int) CardsCo_Send::max_chars()
						);
						?>
					</span>
				<?php endif; ?>
			</p>

			<p class="cc-field">
				<label for="cc-send-signature"><?php esc_html_e( 'Sign it', 'cardsco' ); ?> <span class="cc-send-opt"><?php esc_html_e( 'Optional', 'cardsco' ); ?></span></label>
				<input type="text" id="cc-send-signature" name="signature" value="<?php echo esc_attr( $draft['signature'] ); ?>" placeholder="<?php esc_attr_e( 'The Acme team', 'cardsco' ); ?>" />
			</p>

		<?php elseif ( 3 === $step ) : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'Pick a card', 'cardsco' ); ?></h1>
			<p class="cc-send-sub"><?php esc_html_e( 'Every one is printed on heavy card and written by hand.', 'cardsco' ); ?></p>

			<?php if ( empty( $cards['tiles'] ) ) : ?>
				<p class="cc-send-none"><?php esc_html_e( 'There are no cards to choose from yet.', 'cardsco' ); ?></p>
			<?php else : ?>
				<div class="cc-send-cards">
					<?php foreach ( $cards['tiles'] as $cc_tile ) : ?>
						<label class="cc-send-card<?php echo (int) $draft['card_id'] === (int) $cc_tile['id'] ? ' is-on' : ''; ?>">
							<input type="radio" name="card_id" value="<?php echo esc_attr( (int) $cc_tile['id'] ); ?>"
								<?php checked( (int) $draft['card_id'], (int) $cc_tile['id'] ); ?> required />
							<?php if ( ! empty( $cc_tile['image'] ) ) : ?>
								<img src="<?php echo esc_url( $cc_tile['image'] ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
							<span class="cc-send-card-name"><?php echo esc_html( $cc_tile['title'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		<?php else : ?>

			<h1 class="cc-send-title"><?php esc_html_e( 'Your order', 'cardsco' ); ?></h1>
			<p class="cc-send-sub"><?php esc_html_e( 'Check the total, then pay on our secure payment page.', 'cardsco' ); ?></p>

			<dl class="cc-send-sum">
				<div class="cc-send-sum-row">
					<dt><?php esc_html_e( 'To', 'cardsco' ); ?></dt>
					<dd>
						<?php if ( 'batch' === (string) $draft['mode'] ) : ?>
							<?php
							printf(
								/* translators: %s: how many cards. */
								esc_html( _n( '%s card, all to you', '%s cards, all to you', (int) $order['cards'], 'cardsco' ) ),
								esc_html( number_format_i18n( (int) $order['cards'] ) )
							);
							?>
							<span class="cc-send-sum-quiet">
								<?php echo esc_html( $draft['name'] ); ?> ·
								<?php echo esc_html( CardsCo_Send::address_line( $draft ) ); ?>
							</span>
						<?php else : ?>
							<?php foreach ( CardsCo_Send::cards_in( $draft ) as $cc_to ) : ?>
								<span class="cc-send-sum-to">
									<?php echo esc_html( $cc_to['name'] ); ?>
									<span class="cc-send-sum-quiet"><?php echo esc_html( CardsCo_Send::address_line( $cc_to ) ); ?></span>
								</span>
							<?php endforeach; ?>
						<?php endif; ?>
					</dd>
				</div>
				<div class="cc-send-sum-row">
					<dt><?php esc_html_e( 'Message', 'cardsco' ); ?></dt>
					<dd class="cc-send-sum-msg"><?php echo esc_html( $draft['message'] ); ?></dd>
				</div>

				<?php
				/*
				 * The card itself, which this summary never showed. It did
				 * not matter much while the step before was the card picker;
				 * with that step skipped for somebody who arrived holding a
				 * card, this is the only place they see which one they are
				 * paying for, and the only way back to a different one.
				 */
				if ( CardsCo_Send::card_chosen( $draft ) ) :
					$cc_card_id  = (int) $draft['card_id'];
					$cc_card_art = get_the_post_thumbnail_url( $cc_card_id, 'thumbnail' );
					?>
					<div class="cc-send-sum-row">
						<dt><?php esc_html_e( 'Card', 'cardsco' ); ?></dt>
						<dd class="cc-send-sum-card">
							<?php if ( $cc_card_art ) : ?>
								<img class="cc-send-sum-art" src="<?php echo esc_url( $cc_card_art ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
							<span class="cc-send-sum-card-name"><?php echo esc_html( get_the_title( $cc_card_id ) ); ?></span>
							<a class="cc-send-sum-change" href="<?php echo esc_url( add_query_arg( 'step', 3, $here ) ); ?>">
								<?php esc_html_e( 'Change card', 'cardsco' ); ?>
							</a>
						</dd>
					</div>
				<?php endif; ?>
			</dl>

			<div class="cc-send-lines">
				<p class="cc-send-line">
					<span>
						<?php
						printf(
							/* translators: 1: how many cards, 2: the price of one. */
							esc_html( _n( '%1$s card at %2$s', '%1$s cards at %2$s', (int) $order['cards'], 'cardsco' ) ),
							esc_html( number_format_i18n( (int) $order['cards'] ) ),
							esc_html( call_user_func( array( $cc, 'money' ), $order['each'], 'GBP' ) )
						);
						?>
					</span>
					<span><?php echo esc_html( call_user_func( array( $cc, 'money' ), $order['total'], 'GBP' ) ); ?></span>
				</p>

				<?php if ( ! empty( $order['bulk'] ) ) : ?>
					<p class="cc-send-note cc-send-saved">
						<?php esc_html_e( 'Bulk price applied — every card on this order is at the lower rate.', 'cardsco' ); ?>
					</p>
				<?php endif; ?>

				<p class="cc-send-note">
					<?php esc_html_e( 'Postage and envelope included — Royal Mail 1st class.', 'cardsco' ); ?>
				</p>

				<p class="cc-send-line cc-send-total">
					<span><?php esc_html_e( 'Total to pay', 'cardsco' ); ?></span>
					<span><?php echo esc_html( call_user_func( array( $cc, 'money' ), $order['total'], 'GBP' ) ); ?></span>
				</p>
			</div>

			<p class="cc-send-note">
				<?php esc_html_e( 'You’ll leave CardsCo to pay securely. Once payment goes through, we bring you back here and start writing.', 'cardsco' ); ?>
			</p>

		<?php endif; ?>

		<footer class="cc-send-foot">
			<?php if ( $step > 1 ) : ?>
				<a class="cc-btn cc-btn-ghost" href="<?php echo esc_url( add_query_arg( 'step', CardsCo_Send::step_along( $draft, $step, -1 ), $here ) ); ?>">
					<span><?php esc_html_e( 'Back', 'cardsco' ); ?></span>
				</a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>

			<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8">
				<span>
					<?php
					echo esc_html(
						CardsCo_Send::STEPS === $step
							? __( 'Go to payment', 'cardsco' )
							: __( 'Continue', 'cardsco' )
					);
					?>
				</span>
			</button>
		</footer>
	</form>
</div>
