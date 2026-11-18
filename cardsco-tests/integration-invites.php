<?php
/**
 * Inviting people, and the settings model underneath the Account Settings
 * screen that has not been designed yet.
 *
 * The invite half matters because it creates real WordPress accounts. The
 * thing to prove is not that an invitation can be sent — it is that an
 * invitation which has not been accepted reaches nothing, that one company
 * cannot pull somebody out of another, and that withdrawing one does not
 * delete an account that was being used for something else.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/settings/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-54s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

$GLOBALS['cc_mail'] = array();
add_filter('pre_wp_mail', function($n,$a){ $GLOBALS['cc_mail'][] = $a; return true; }, 10, 2);

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();
wp_set_current_user(1);

$stamp = strtolower(wp_generate_password(6, false));
function mk_company($name){ global $wpdb; $c = CardsCo_Company::create(['name'=>$name]); return is_wp_error($c)?0:(int)$c; }

$acme  = mk_company('Acme '.$stamp);
$other = mk_company('Other '.$stamp);
ck('two companies', $acme > 0 && $other > 0);

/* ======================================================= the settings model */

sect('every setting is declared');
$schema = CardsCo_Settings::schema();
ck('the schema has entries', count($schema) > 15);
ck('every one has a label, group, type and scope',
  count(array_filter($schema, fn($d) => !empty($d['label']) && !empty($d['group']) && !empty($d['type']) && !empty($d['scope']))),
  count($schema));
ck('every scope is one we know',
  array_diff(array_column($schema,'scope'), ['company','platform']), []);
/* These were read in code but never declared — the thing this model fixes. */
foreach (['logo_url','logo_alt','marketing_url','support_email','dashboard_url','invite_url'] as $k) {
  ck("$k is on the record", (bool) CardsCo_Settings::definition($k));
}
ck('defaults come from the schema, not a second list',
  array_diff(array_keys(CardsCo_Settings::defaults()), array_keys($schema)), []);
ck('an unknown key has no definition', CardsCo_Settings::definition('not_a_setting'), null);

sect('who is allowed to change what');
ck('a company can set its own logo',      CardsCo_Settings::company_may_set('logo_url'), true);
/* Pricing and the scan window belong to CardsCo. If the settings screen
 * offered these, a customer could set their own card price. */
ck('a company cannot set the card price', CardsCo_Settings::company_may_set('price_default'), false);
ck('nor the scan horizon',                CardsCo_Settings::company_may_set('queue_horizon_days'), false);
ck('nor something that does not exist',   CardsCo_Settings::company_may_set('free_cards_forever'), false);
$company_fields = CardsCo_Settings::fields('', 'company');
ck('the company list excludes pricing', isset($company_fields['price_default']), false);
ck('and includes branding',             isset($company_fields['logo_url']));
ck('grouping works',  array_keys(CardsCo_Settings::fields('billing','')), ['billing_mode','invoice_terms_days','require_total_approval']);

sect('values are cleaned by their declared type');
ck('a number stays a number',      CardsCo_Settings::clean('default_lead_days','9'), 9);
ck('below the minimum is refused', is_wp_error(CardsCo_Settings::clean('default_lead_days', 0)), true);
ck('above the maximum is refused', is_wp_error(CardsCo_Settings::clean('default_lead_days', 900)), true);
ck('money gets two decimals',      CardsCo_Settings::clean('price_default','4.5'), '4.50');
ck('money that is not a number is refused', is_wp_error(CardsCo_Settings::clean('price_default','free')), true);
ck('a tick becomes 1',             CardsCo_Settings::clean('duplicate_detection','on'), 1);
ck('an untick becomes 0',          CardsCo_Settings::clean('duplicate_detection',''), 0);
ck('"0" is off, not on',           CardsCo_Settings::clean('duplicate_detection','0'), 0);
ck('a real email passes',          CardsCo_Settings::clean('support_email','help@cardsco.uk'), 'help@cardsco.uk');
ck('a bad email is refused',       is_wp_error(CardsCo_Settings::clean('support_email','not-an-address')), true);
ck('a real url passes',            CardsCo_Settings::clean('logo_url','https://cardsco.uk/a.png'), 'https://cardsco.uk/a.png');
ck('an option off the list is refused', is_wp_error(CardsCo_Settings::clean('currency','DOGE')), true);
ck('an option on it passes',       CardsCo_Settings::clean('currency','EUR'), 'EUR');
ck('a list comes back as an array', CardsCo_Settings::clean('shipping_countries','GB, IE'), ['GB','IE']);
ck('an unknown key is refused outright', is_wp_error(CardsCo_Settings::clean('free_cards_forever','yes')), true);

sect('and set() enforces the same rules');
/* A settings form posts straight into set(), so this is the gate that
 * matters, not clean() on its own. */
/* Cleared first: a row left behind by an earlier run — or by a mutation
 * check that deliberately disabled validation — would otherwise decide this,
 * and it is meant to be testing this run's write. */
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_settings WHERE setting_key=%s",'free_cards_forever'));
ck('an undeclared key cannot be written', CardsCo_Settings::set('free_cards_forever','yes',$acme), false);
ck('and nothing was stored',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_settings WHERE setting_key=%s",'free_cards_forever')), 0);
ck('an out-of-range number cannot be written', CardsCo_Settings::set('default_lead_days',999,$acme), false);
ck('a nonsense price cannot be written',       CardsCo_Settings::set('price_default','free',$acme), false);
ck('a good value can',                         CardsCo_Settings::set('default_lead_days','9',$acme), true);
ck('and reads back cleaned',                   (int)CardsCo_Settings::get('default_lead_days',null,$acme), 9);
ck('a bad write leaves the old value alone',
  CardsCo_Settings::set('default_lead_days','nonsense-1000',$acme) === false
    && (int)CardsCo_Settings::get('default_lead_days',null,$acme) === 9);
ck('the platform default is untouched by a company value',
  (int)CardsCo_Settings::get('default_lead_days'), (int)CardsCo_Settings::defaults()['default_lead_days']);

/* ============================================================== invitations */

sect('what an invitation refuses');
ck('a company that does not exist', is_wp_error(CardsCo_Invite::send(999999,"a.$stamp@x.test")), true);
ck('an address that is not one',    is_wp_error(CardsCo_Invite::send($acme,'not-an-address')), true);

sect('inviting somebody new');
$GLOBALS['cc_mail'] = array();
$email  = "ben.$stamp@acme.test";
$invite = CardsCo_Invite::send($acme, $email, 'member', ['first_name'=>'Ben','last_name'=>'Clarke']);
ck('it works',            !is_wp_error($invite));
ck('an account was made', (bool) email_exists($email));
ck('their name was kept', get_userdata($invite['user_id'])->first_name, 'Ben');
ck('the role is recorded',$invite['role'], 'member');
ck('a link was made',     str_contains($invite['link'],'key=') && str_contains($invite['link'],'login='));
ck('pointing at the join page', str_contains($invite['link'],'/join/'));

$row = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_company_users WHERE company_id=%d AND user_id=%d",$acme,$invite['user_id']), ARRAY_A);
ck('the membership is waiting, not active', $row['status'], 'invited');
ck('with who invited them',                 (int)$row['invited_by'], get_current_user_id());

sect('what an unaccepted invitation can reach');
/* The whole reason it is safe to create the account up front. */
wp_cache_delete($invite['user_id'],'cardsco_user_company');
ck('no company at all', cardsco_get_user_company_id($invite['user_id']), 0);
ck('so the dashboard would refuse them',
  CardsCo_Access::company_id($invite['user_id'] ?? 0) ?: 0, 0);
ck('and there is no password that works',
  wp_check_password('password', get_userdata($invite['user_id'])->user_pass, $invite['user_id']), false);

sect('the email');
ck('one went out',        count($GLOBALS['cc_mail']), 1);
$mail = $GLOBALS['cc_mail'][0];
ck('to them',             $mail['to'], $email);
ck('naming the company',  str_contains($mail['subject'],'Acme'));
ck('with the link in it', str_contains($mail['message'],'key='));
/* The window in the email has to be the one core actually enforces. */
ck('and the window core really uses', str_contains($mail['message'],'24 hours'));
ck('no password anywhere in it',
  (bool) preg_match('/password/i', $mail['message']) && !str_contains($mail['message'], get_userdata($invite['user_id'])->user_pass));

sect('inviting the same person twice');
ck('while they are still pending, it is allowed and re-links',
  !is_wp_error(CardsCo_Invite::send($acme, $email, 'member')));
ck('and there is still only one membership row',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE company_id=%d AND user_id=%d",$acme,$invite['user_id'])), 1);

sect("somebody who belongs to another company");
$theirs = "carol.$stamp@other.test";
$cu = wp_create_user($theirs, wp_generate_password(24), $theirs);
CardsCo_Company::add_user($other, $cu, 'owner');
wp_cache_delete($cu,'cardsco_user_company');
$grab = CardsCo_Invite::send($acme, $theirs);
ck('cannot be pulled across', is_wp_error($grab), true);
ck('and is told why',         is_wp_error($grab) ? str_contains($grab->get_error_message(),'another company') : false);
ck('their own company is untouched', cardsco_get_user_company_id($cu), $other);
ck('and Acme gained nobody',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE company_id=%d AND user_id=%d",$acme,$cu)), 0);

sect('somebody already on the company');
$owner = "dee.$stamp@acme.test";
$ou = wp_create_user($owner, wp_generate_password(24), $owner);
CardsCo_Company::add_user($acme, $ou, 'owner');
wp_cache_delete($ou,'cardsco_user_company');
$again = CardsCo_Invite::send($acme, $owner);
ck('is refused', is_wp_error($again), true);
ck('and told they are already on it',
  is_wp_error($again) ? str_contains($again->get_error_message(),'already') : false);

sect('resending');
$GLOBALS['cc_mail'] = array();
$first_link = $invite['link'];
$re = CardsCo_Invite::resend($acme, $invite['user_id']);
ck('it works',             !is_wp_error($re));
ck('another email went',   count($GLOBALS['cc_mail']), 1);
ck('with a different link', $re['link'] !== $first_link);
/* A link forwarded to the wrong person has to stop working once resent. */
parse_str((string)parse_url($first_link, PHP_URL_QUERY), $q1);
ck('and the old link is dead',
  is_wp_error(check_password_reset_key(urldecode($q1['key']), urldecode($q1['login']))), true);
parse_str((string)parse_url($re['link'], PHP_URL_QUERY), $q2);
ck('while the new one works',
  !is_wp_error(check_password_reset_key(urldecode($q2['key']), urldecode($q2['login']))));
ck('resending to somebody with no invitation is refused',
  is_wp_error(CardsCo_Invite::resend($acme, $ou)), true);

sect('listing people');
$people = CardsCo_Invite::people($acme);
ck('both are listed',          count($people), 2);
ck('one is still pending',     count(CardsCo_Invite::pending($acme)), 1);
ck('and it is the right one',  CardsCo_Invite::pending($acme)[0]['email'], $email);
ck('each row carries a role',  count(array_filter(array_column($people,'role'))), 2);
ck('another company sees none', CardsCo_Invite::people(999999), []);

sect('accepting it, through the real screen');
parse_str((string)parse_url($re['link'], PHP_URL_QUERY), $q);
/* The person accepting is logged out, and a nonce is tied to whoever was
 * current when it was minted — so the user has to be switched BEFORE the
 * token is made, not after. Minting it as the admin and then switching made
 * the handler reject its own nonce. */
wp_set_current_user(0);
$_POST = ['cardsco_auth_action'=>'invite_accept','key'=>urldecode($q['key']),'login'=>urldecode($q['login']),
  'first_name'=>'Ben','last_name'=>'Clarke','password'=>'a-long-enough-phrase',
  '_cardsco_nonce'=>wp_create_nonce(CardsCo_Auth::NONCE_INVITE)];
$_REQUEST = $_POST; $_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
add_filter('wp_redirect', function(){ throw new Exception('redirected'); }, 1);
$landed = false;
try { (new CardsCo_Auth())->handle(); } catch (Throwable $e) { $landed = ('redirected' === $e->getMessage()); }
ck('they are sent on', $landed);
clean_user_cache($invite['user_id']);
wp_cache_delete($invite['user_id'],'cardsco_user_company');
ck('now they belong to Acme', cardsco_get_user_company_id($invite['user_id']), $acme);
ck('their membership is active',
  $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$invite['user_id'])), 'active');
ck('nothing is pending any more', CardsCo_Invite::pending($acme), []);
ck('their password works', wp_check_password('a-long-enough-phrase', get_userdata($invite['user_id'])->user_pass, $invite['user_id']));
$_SERVER['REQUEST_METHOD'] = 'GET';
wp_set_current_user(1);

sect('withdrawing an invitation');
$spare = "eve.$stamp@acme.test";
$inv2  = CardsCo_Invite::send($acme, $spare, 'member');
ck('sent',            !is_wp_error($inv2));
ck('withdrawn',       CardsCo_Invite::revoke($acme, $inv2['user_id']), true);
ck('the membership is gone',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$inv2['user_id'])), 0);
/* The account existed only for this invitation, so it goes too. */
ck('and the unused account was cleaned up', (bool) email_exists($spare), false);
ck('withdrawing it twice is refused', is_wp_error(CardsCo_Invite::revoke($acme, $inv2['user_id'])), true);
ck('withdrawing from somebody who accepted is refused',
  is_wp_error(CardsCo_Invite::revoke($acme, $invite['user_id'])), true);
ck('and they are still on the company', cardsco_get_user_company_id($invite['user_id']), $acme);

sect('withdrawing must not delete an account being used elsewhere');
/* The dangerous case: revoke() deleting a real person because one of their
 * invitations was withdrawn. */
$admin_email = "frank.$stamp@acme.test";
$fu = wp_create_user($admin_email, wp_generate_password(24), $admin_email);
(new WP_User($fu))->set_role('administrator');
$wpdb->insert($wpdb->prefix.'cardsco_company_users',
  ['company_id'=>$acme,'user_id'=>$fu,'company_role'=>'member','status'=>'invited','created_at'=>cardsco_now()]);
ck('their invitation is withdrawn', CardsCo_Invite::revoke($acme, $fu), true);
ck('but the administrator still exists', (bool) email_exists($admin_email));

sect('the log records it, without anything secret');
$log = (string) $wpdb->get_var($wpdb->prepare(
  "SELECT GROUP_CONCAT(summary) FROM {$wpdb->prefix}cardsco_activity_log WHERE company_id=%d",$acme));
ck('the invitation is on the record', str_contains($log,'Invited'));
ck('and the withdrawal',              str_contains($log,'withdrawn'));
parse_str((string)parse_url($re['link'], PHP_URL_QUERY), $qq);
ck('but no invitation key is in it',  str_contains($log, urldecode($qq['key'])), false);

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
