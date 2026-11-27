<?php
/**
 * Settings → Team access.
 *
 * No frame for this one, so it follows the Company profile panel's shape:
 * a heading and a line of explanation on the left, the thing itself on the
 * right.
 *
 * Each person's row carries only the buttons the viewer may actually press
 * — worked out in CardsCo_Dashboard::settings_team(), not here. A row that
 * shows a Remove button to somebody who cannot remove anyone is the dead
 * control that has caused most of this project's bug reports.
 *
 * @package CardsCo
 *
 * @var array  $settings From CardsCo_Dashboard::settings_screen().
 * @var string $cc_base  This page.
 */

defined( 'ABSPATH' ) || exit;

$cc_team = $settings['team'];
?>
<form class="cc-set-form" method="post" action="<?php echo esc_url( $cc_base ); ?>">
	<?php cardsco_nonce_field( 'cardsco_settings' ); ?>
	<input type="hidden" name="cardsco_settings_action" value="team" />

	<section class="cc-set-sec">
		<div class="cc-set-side">
			<h2><?php esc_html_e( 'People', 'cardsco' ); ?></h2>
			<p><?php esc_html_e( 'Everyone who can sign in to your account.', 'cardsco' ); ?></p>
		</div>

		<div class="cc-set-panel">
			<ul class="cc-team">
				<?php foreach ( $cc_team['people'] as $cc_person ) : ?>
					<li class="cc-team-row<?php echo $cc_person['pending'] ? ' is-pending' : ''; ?>">

						<span class="cc-team-who">
							<span class="cc-team-name">
								<?php echo esc_html( $cc_person['name'] ); ?>
								<?php if ( $cc_person['is_you'] ) : ?>
									<span class="cc-team-you"><?php esc_html_e( 'you', 'cardsco' ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( $cc_person['named'] ) : ?>
								<span class="cc-team-email"><?php echo esc_html( $cc_person['email'] ); ?></span>
							<?php endif; ?>
						</span>

						<span class="cc-team-role">
							<?php if ( $cc_person['may_manage'] && $cc_team['may_manage'] ) : ?>
								<label class="cc-sr" for="cc-team-role-<?php echo esc_attr( $cc_person['user_id'] ); ?>">
									<?php
									printf(
										/* translators: %s: somebody's name. */
										esc_html__( 'What %s can do', 'cardsco' ),
										esc_html( $cc_person['name'] )
									);
									?>
								</label>
								<?php /* Keyed by person: one name repeated down the list would
								         leave PHP holding only the last row's value. */ ?>
								<select id="cc-team-role-<?php echo esc_attr( $cc_person['user_id'] ); ?>"
									name="role_for[<?php echo esc_attr( $cc_person['user_id'] ); ?>]">
									<?php foreach ( $cc_team['roles'] as $cc_key => $cc_label ) : ?>
										<option value="<?php echo esc_attr( $cc_key ); ?>" <?php selected( $cc_person['role'], $cc_key ); ?>>
											<?php echo esc_html( $cc_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<span class="cc-pill is-neutral"><?php echo esc_html( $cc_person['role_label'] ); ?></span>
							<?php endif; ?>

							<span class="cc-team-can">
								<?php echo esc_html( CardsCo_Dashboard::team_role_blurb( $cc_person['role'] ) ); ?>
							</span>
						</span>

						<span class="cc-team-state">
							<?php if ( $cc_person['pending'] ) : ?>
								<span class="cc-pill is-draft"><?php esc_html_e( 'Invited', 'cardsco' ); ?></span>
							<?php endif; ?>
						</span>

						<span class="cc-team-do">
							<?php if ( $cc_person['may_manage'] && $cc_team['may_manage'] ) : ?>
								<button type="submit" class="cc-team-btn" name="team_set_role" value="<?php echo esc_attr( $cc_person['user_id'] ); ?>">
									<?php esc_html_e( 'Save role', 'cardsco' ); ?>
								</button>

								<?php if ( $cc_person['pending'] ) : ?>
									<button type="submit" class="cc-team-btn" name="team_resend" value="<?php echo esc_attr( $cc_person['user_id'] ); ?>">
										<?php esc_html_e( 'Send again', 'cardsco' ); ?>
									</button>
									<button type="submit" class="cc-team-btn is-quiet" name="team_revoke" value="<?php echo esc_attr( $cc_person['user_id'] ); ?>">
										<?php esc_html_e( 'Withdraw', 'cardsco' ); ?>
									</button>
								<?php else : ?>
									<button type="submit" class="cc-team-btn is-quiet" name="team_remove" value="<?php echo esc_attr( $cc_person['user_id'] ); ?>">
										<?php esc_html_e( 'Remove', 'cardsco' ); ?>
									</button>
								<?php endif; ?>
							<?php endif; ?>
						</span>

						<?php /* Each button carries the person it acts on as its value. */ ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! $cc_team['may_manage'] ) : ?>
				<p class="cc-team-note">
					<?php esc_html_e( 'Only an owner or admin can invite people or change what they can do.', 'cardsco' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $cc_team['may_manage'] ) : ?>
		<section class="cc-set-sec">
			<div class="cc-set-side">
				<h2><?php esc_html_e( 'Invite someone', 'cardsco' ); ?></h2>
				<p><?php esc_html_e( 'They’ll get an email with a link to set a password. Nothing of yours is shared until they accept.', 'cardsco' ); ?></p>
			</div>

			<div class="cc-set-panel">
				<div class="cc-team-invite">
					<p class="cc-field">
						<label for="cc-team-email"><?php esc_html_e( 'Their email', 'cardsco' ); ?></label>
						<input type="email" id="cc-team-email" name="team_email" autocomplete="off" />
					</p>

					<p class="cc-field">
						<label for="cc-team-newrole"><?php esc_html_e( 'What they can do', 'cardsco' ); ?></label>
						<select id="cc-team-newrole" name="team_role">
							<?php foreach ( $cc_team['roles'] as $cc_key => $cc_label ) : ?>
								<option value="<?php echo esc_attr( $cc_key ); ?>" <?php selected( 'member', $cc_key ); ?>>
									<?php echo esc_html( $cc_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="cc-field-help"><?php echo esc_html( CardsCo_Dashboard::team_role_blurb( 'member' ) ); ?></span>
					</p>

					<button type="submit" class="cc-btn cc-btn-primary cc-btn-r8" name="team_invite" value="1">
						<span><?php esc_html_e( 'Send invitation', 'cardsco' ); ?></span>
					</button>
				</div>
			</div>
		</section>
	<?php endif; ?>
</form>
