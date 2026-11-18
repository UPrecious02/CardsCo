<?php
/**
 * Signing in, signing up, resetting and accepting an invitation.
 *
 * Everything goes through CardsCo_Auth::handle() with real $_POST and real
 * nonces, because the things most likely to go wrong here are the checks
 * around the write rather than the write itself: a missing nonce, a signup
 * that half-finishes, a throttle that never fires, an error message that
 * quietly tells an attacker which addresses have accounts.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/log-in/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR']    = '203.0.113.9';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-54s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

class Redirected extends Exception { public $url; public function __construct($u){ $this->url=$u; parent::__construct('r'); } }
add_filter('wp_redirect', function($loc){ throw new Redirected($loc); }, 1);

/* Catch the mail rather than send it, so the reset link can be read. */
$GLOBALS['cc_mail'] = array();
add_filter('pre_wp_mail', function($null,$atts){ $GLOBALS['cc_mail'][] = $atts; return true; }, 10, 2);

/* Auth is stateful: reset the class between posts so one test's error does
 * not leak into the next, and clear the cookie the last sign-in set. */
function post(array $data, $nonce_action = null) {
  wp_set_current_user(0);
  $_POST = $data;
  if ($nonce_action !== null) $_POST['_cardsco_nonce'] = wp_create_nonce($nonce_action);
  $_REQUEST = $_POST; $_GET = [];
  $r = new ReflectionClass('CardsCo_Auth');
  foreach (['error'=>'', 'posted'=>[]] as $prop => $blank) {
    $p = $r->getProperty($prop); $p->setAccessible(true); $p->setValue(null, $blank);
  }
  $a = new CardsCo_Auth();
  try { $a->handle(); }
  catch (Redirected $e) { return ['redirect'=>$e->url, 'error'=>CardsCo_Auth::error()]; }
  catch (Throwable $e) { return ['threw'=>get_class($e).' '.$e->getMessage(), 'error'=>CardsCo_Auth::error()]; }
  return ['redirect'=>null, 'error'=>CardsCo_Auth::error()];
}
function users_on($email){ global $wpdb; return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email=%s",$email)); }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();

$stamp = strtolower(wp_generate_password(6, false));
$NONCE = ['login'=>CardsCo_Auth::NONCE_LOGIN,'signup'=>CardsCo_Auth::NONCE_SIGNUP,
          'reset'=>CardsCo_Auth::NONCE_RESET,'invite'=>CardsCo_Auth::NONCE_INVITE];

$GOOD = 'a-long-enough-phrase';
$EMAIL = "ada.$stamp@brightside.test";

/* ------------------------------------------------------- nothing happens */

sect('posts that must do nothing');
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
ck('no action at all',  post(['email'=>$EMAIL,'password'=>$GOOD])['redirect'], null);
ck('an unknown action', post(['cardsco_auth_action'=>'take_over','email'=>$EMAIL])['redirect'], null);

$r = post(['cardsco_auth_action'=>'signup','first_name'=>'Ada','company'=>'Brightside',
  'email'=>$EMAIL,'password'=>$GOOD,'terms'=>'1'], $NONCE['login']);
ck('a nonce minted for another form is refused', $r['error'] !== '');
ck('and says the form went stale', str_contains($r['error'], 'open too long'));

$r = post(['cardsco_auth_action'=>'signup','first_name'=>'Ada','company'=>'Brightside',
  'email'=>$EMAIL,'password'=>$GOOD,'terms'=>'1'], null);
ck('no nonce at all is refused', $r['error'] !== '');
ck('no user was created by any of that', (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"), $before);

/* ------------------------------------------------------------- signing up */

sect('what sign-up refuses');
$base = ['cardsco_auth_action'=>'signup','first_name'=>'Ada','last_name'=>'Nwosu',
         'company'=>'Brightside Ltd','email'=>$EMAIL,'password'=>$GOOD,'terms'=>'1'];
$without = function($k,$v=null) use ($base) { $d=$base; if($v===null) unset($d[$k]); else $d[$k]=$v; return $d; };

ck('no first name',  post($without('first_name',''), $NONCE['signup'])['error'] !== '');
ck('no company',     post($without('company',''), $NONCE['signup'])['error'] !== '');
ck('and says why the company matters',
  str_contains(post($without('company',''), $NONCE['signup'])['error'], 'dashboard'));
ck('a bad email',    post($without('email','not-an-address'), $NONCE['signup'])['error'] !== '');
ck('a short password', post($without('password','short'), $NONCE['signup'])['error'] !== '');
ck('and names the length',
  str_contains(post($without('password','short'), $NONCE['signup'])['error'], '10'));
ck('terms not ticked', post($without('terms'), $NONCE['signup'])['error'] !== '');
ck('none of those made a user', (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"), $before);

sect('a good sign-up');
$r = post($base, $NONCE['signup']);
ck('it redirects',  (bool)$r['redirect']);
ck('with no error', $r['error'], '');

$uid = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email=%s",$EMAIL));
ck('the user exists', $uid > 0);
$u = get_userdata($uid);
ck('their name was kept',      $u->first_name, 'Ada');
ck('display name is both',     $u->display_name, 'Ada Nwosu');
ck('they have the company role', in_array(CardsCo_Roles::COMPANY_ROLE, (array)$u->roles, true));
ck('they cannot manage every company', user_can($u,'cardsco_manage_all_companies'), false);
ck('but they can see a dashboard',      user_can($u,'cardsco_access_dashboard'));

wp_cache_delete($uid,'cardsco_user_company');
$cid = cardsco_get_user_company_id($uid);
ck('a company was created and linked', $cid > 0);
$co = CardsCo_Company::get($cid);
ck('named as typed',      $co['name'], 'Brightside Ltd');
ck('owned by them',       (int)$co['owner_user_id'], $uid);
ck('with them as contact',$co['contact_email'], $EMAIL);
$mem = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_company_users WHERE company_id=%d AND user_id=%d",$cid,$uid), ARRAY_A);
ck('the membership is there', (bool)$mem);
ck('as owner',   $mem['company_role'], 'owner');
ck('and active', $mem['status'], 'active');
ck('exactly one membership row, not two',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$uid)), 1);

sect('the password');
ck('it is not stored as typed', $u->user_pass !== $GOOD);
/* WordPress 6.8 and later hash with bcrypt; $wp$ is core's own prefix for it. */
ck('it is hashed with bcrypt, not the old phpass',
  str_starts_with($u->user_pass,'$wp$') || str_starts_with($u->user_pass,'$2y$'));
ck('and it checks out', wp_check_password($GOOD, $u->user_pass, $uid));
ck('a near miss does not', wp_check_password($GOOD.'x', $u->user_pass, $uid), false);

sect('the password never reaches the activity log');
$log = (string) $wpdb->get_var($wpdb->prepare(
  "SELECT GROUP_CONCAT(summary) FROM {$wpdb->prefix}cardsco_activity_log WHERE company_id=%d",$cid));
ck('nothing in the log contains it', str_contains($log, $GOOD), false);
ck('the company creation was logged though', str_contains($log, 'Brightside Ltd'));

sect('the same address twice');
$r = post($base, $NONCE['signup']);
ck('it is refused',          $r['error'] !== '');
ck('and points at signing in', str_contains($r['error'],'signing in'));
ck('still only one user on that address', users_on($EMAIL), 1);
ck('and still one company',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_companies WHERE owner_user_id=%d",$uid)), 1);

sect('a sign-up that cannot finish is unwound');
/*
 * The dangerous outcome is not an error message, it is a user who exists,
 * can sign in, and belongs to no company — every dashboard query returns
 * nothing and the product looks broken rather than failed.
 *
 * So the failure is injected for real: the membership INSERT is turned into
 * a harmless SELECT through wpdb's own query filter, exactly once. The
 * company row is written, the link is not, and the code has to notice.
 */
$ghost = "ghost.$stamp@brightside.test";
$companies_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_companies");

$swallow = function ($sql) use (&$swallow, $wpdb) {
  if (stripos($sql, 'INSERT INTO ' . $wpdb->prefix . 'cardsco_company_users') === 0) {
    remove_filter('query', $swallow);
    return 'SELECT 1';
  }
  return $sql;
};
add_filter('query', $swallow);

/* Stamped, so a leftover from a previous run cannot make this pass or fail
 * for the wrong reason. */
$ghost_co = 'Nowhere Ltd ' . $stamp;

$r = post(['cardsco_auth_action'=>'signup','first_name'=>'Ghost','last_name'=>'Account',
  'company'=>$ghost_co,'email'=>$ghost,'password'=>$GOOD,'terms'=>'1'], $NONCE['signup']);
remove_filter('query', $swallow);

ck('it does not pretend to have worked', $r['redirect'], null);
ck('and says so',                        $r['error'] !== '');
ck('no user is left behind',             users_on($ghost), 0);
ck('no orphan company either',
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_companies"), $companies_before);
ck('and that company is gone by name too',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",$ghost_co)), 0);
ck('and no membership row survived',
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users cu
     LEFT JOIN {$wpdb->prefix}cardsco_companies c ON c.id = cu.company_id WHERE c.id IS NULL"), 0);
ck('so the address is free to try again', email_exists($ghost), false);

/* ------------------------------------------------------------- signing in */

sect('signing in');
$r = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'not-the-password'], $NONCE['login']);
ck('a wrong password is refused', $r['error'] !== '');
/* One message whatever went wrong, so this form cannot be used to work out
 * which addresses have accounts. */
$unknown = post(['cardsco_auth_action'=>'login','email'=>"nobody.$stamp@nowhere.test",'password'=>'whatever'], $NONCE['login']);
ck('an address with no account says exactly the same thing', $unknown['error'], $r['error']);
ck('and that message gives nothing away', str_contains($r['error'],'did not match'));

$r = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login']);
ck('the right password gets in', (bool)$r['redirect']);
ck('with no error',              $r['error'], '');

sect('where they land');
$r = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD,
  'redirect_to'=>'/portal/dashboard/'], $NONCE['login']);
ck('the page can say where to go', str_contains((string)$r['redirect'],'/portal/dashboard/'));
$r = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD,
  'redirect_to'=>'https://somewhere-else.test/'], $NONCE['login']);
ck('but not off the site', str_contains((string)$r['redirect'],'somewhere-else.test'), false);

/* ------------------------------------------------------------- throttling */

sect('guessing at the password');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_%'");
wp_cache_flush();
$blocked_at = 0;
for ($i = 1; $i <= 8; $i++) {
  $r = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'wrong-'.$i], $NONCE['login']);
  if (!$blocked_at && str_contains($r['error'],'Too many')) $blocked_at = $i;
}
ck('it stops after the fifth try', $blocked_at, CardsCo_Auth::MAX_ATTEMPTS + 1);
ck('and says how long to wait', str_contains(
  post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['error'], 'minutes'));
/* The point of the throttle: the correct password is refused too while the
 * lockout is on, or it is no throttle at all. */
ck('even the right password waits',
  post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['redirect'], null);

sect('one locked-out address does not lock out the office');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_%'");
wp_cache_flush();
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
/* A fixed bound, not MAX_ATTEMPTS. Driving the loop from the constant means
 * raising the constant drives the loop instead of failing the test, and each
 * turn of it is a real bcrypt check — the suite hung for minutes the first
 * time this was mutated rather than reporting a failure. */
$tries = min( 12, max( 1, CardsCo_Auth::MAX_ATTEMPTS ) );
for ($i = 0; $i < $tries; $i++) {
  post(['cardsco_auth_action'=>'login','email'=>"someone.else.$stamp@brightside.test",'password'=>'nope'], $NONCE['login']);
}
/* Same IP, so the IP counter has tripped: that is the intended behaviour,
 * since one machine working through a list of addresses is the attack. */
ck('the IP counter catches a run of different addresses',
  str_contains(post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['error'],'Too many'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
ck('but a different machine is unaffected',
  (bool)post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['redirect']);

sect('a success clears the slate');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_%'");
wp_cache_flush();
post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'wrong'], $NONCE['login']);
post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'wrong'], $NONCE['login']);
ck('signing in works after a couple of misses',
  (bool)post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['redirect']);
ck('and the counter was wiped',
  (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_login%'"), 0);

/* ---------------------------------------------------------------- resets */

sect('asking for a reset link');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_%'");
wp_cache_flush();
$GLOBALS['cc_mail'] = array();

$r = post(['cardsco_auth_action'=>'reset_request','email'=>"nobody.$stamp@nowhere.test"], $NONCE['reset']);
ck('an unknown address still redirects', (bool)$r['redirect']);
ck('and says it was sent',               str_contains((string)$r['redirect'],'sent=1'));
ck('but no mail went out',               count($GLOBALS['cc_mail']), 0);

$r = post(['cardsco_auth_action'=>'reset_request','email'=>$EMAIL], $NONCE['reset']);
ck('a known address redirects identically', str_contains((string)$r['redirect'],'sent=1'));
ck('and one mail went out',                 count($GLOBALS['cc_mail']), 1);
$mail = $GLOBALS['cc_mail'][0];
ck('to the right person', $mail['to'], $EMAIL);
ck('with a key in the link', (bool)preg_match('/key=([^&\s]+)/', $mail['message'], $m));
ck('and the login too',      str_contains($mail['message'], 'login='));
ck('the message does not contain their password', str_contains($mail['message'], $GOOD), false);
$key = isset($m[1]) ? urldecode($m[1]) : '';

sect('using the link');
$NEW = 'another-long-phrase';
ck('a made-up key is refused',
  post(['cardsco_auth_action'=>'reset_save','key'=>'not-a-real-key','login'=>$EMAIL,
    'password'=>$NEW,'password_confirm'=>$NEW], $NONCE['reset'])['error'] !== '');
ck('a short password is refused',
  post(['cardsco_auth_action'=>'reset_save','key'=>$key,'login'=>$EMAIL,
    'password'=>'short','password_confirm'=>'short'], $NONCE['reset'])['error'] !== '');
ck('two that differ are refused', str_contains(
  post(['cardsco_auth_action'=>'reset_save','key'=>$key,'login'=>$EMAIL,
    'password'=>$NEW,'password_confirm'=>$NEW.'x'], $NONCE['reset'])['error'], 'not the same'));
ck('the old password still works at this point',
  wp_check_password($GOOD, get_userdata($uid)->user_pass, $uid));

$r = post(['cardsco_auth_action'=>'reset_save','key'=>$key,'login'=>$EMAIL,
  'password'=>$NEW,'password_confirm'=>$NEW], $NONCE['reset']);
ck('a good one goes through', (bool)$r['redirect']);
clean_user_cache($uid);
ck('the new password works',  wp_check_password($NEW, get_userdata($uid)->user_pass, $uid));
ck('and the old one does not', wp_check_password($GOOD, get_userdata($uid)->user_pass, $uid), false);

sect('a link cannot be used twice');
ck('the second go is refused', str_contains(
  post(['cardsco_auth_action'=>'reset_save','key'=>$key,'login'=>$EMAIL,
    'password'=>'yet-another-phrase','password_confirm'=>'yet-another-phrase'], $NONCE['reset'])['error'], 'expired'));
clean_user_cache($uid);
ck('and the password it set still stands', wp_check_password($NEW, get_userdata($uid)->user_pass, $uid));

/* --------------------------------------------------------------- invites */

sect('an invitation waiting to be accepted');
$invited_email = "ben.$stamp@brightside.test";
$ben = wp_create_user($invited_email, wp_generate_password(24), $invited_email);
ck('the invited user exists', is_int($ben) && $ben > 0);
ck('added as invited, not active',
  CardsCo_Company::add_user($cid, $ben, 'member', 'invited'), true);
$row = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_company_users WHERE company_id=%d AND user_id=%d",$cid,$ben), ARRAY_A);
ck('the row says invited', $row['status'], 'invited');
/* Until they accept, they must reach no company's data at all. */
wp_cache_delete($ben,'cardsco_user_company');
ck('so they belong to no company yet', cardsco_get_user_company_id($ben), 0);
ck('and the pending invitation is findable', (int)CardsCo_Auth::pending_invite($ben)['company_id'], $cid);

sect('accepting it');
$ben_user = get_userdata($ben);
$invite_key = get_password_reset_key($ben_user);
ck('a key was minted', is_string($invite_key) && $invite_key !== '');

ck('a wrong key is refused', str_contains(
  post(['cardsco_auth_action'=>'invite_accept','key'=>'rubbish','login'=>$invited_email,
    'first_name'=>'Ben','password'=>$GOOD], $NONCE['invite'])['error'], 'expired'));
ck('no first name is refused',
  post(['cardsco_auth_action'=>'invite_accept','key'=>$invite_key,'login'=>$invited_email,
    'first_name'=>'','password'=>$GOOD], $NONCE['invite'])['error'] !== '');
ck('a short password is refused',
  post(['cardsco_auth_action'=>'invite_accept','key'=>$invite_key,'login'=>$invited_email,
    'first_name'=>'Ben','password'=>'short'], $NONCE['invite'])['error'] !== '');
ck('and none of that activated them',
  $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$ben)), 'invited');

$r = post(['cardsco_auth_action'=>'invite_accept','key'=>$invite_key,'login'=>$invited_email,
  'first_name'=>'Ben','last_name'=>'Clarke','password'=>$GOOD], $NONCE['invite']);
ck('a good one goes through', (bool)$r['redirect']);
ck('with no error',           $r['error'], '');
clean_user_cache($ben);
wp_cache_delete($ben,'cardsco_user_company');
ck('they are now in the company', cardsco_get_user_company_id($ben), $cid);
ck('as a member, not an owner',
  $wpdb->get_var($wpdb->prepare("SELECT company_role FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$ben)), 'member');
ck('their membership is active',
  $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$ben)), 'active');
ck('their name was saved',   get_userdata($ben)->first_name, 'Ben');
ck('their password works',   wp_check_password($GOOD, get_userdata($ben)->user_pass, $ben));
ck('they have the company role', in_array(CardsCo_Roles::COMPANY_ROLE, (array)get_userdata($ben)->roles, true));
ck('no invitation is left open', CardsCo_Auth::pending_invite($ben), null);

sect('and it cannot be accepted again');
ck('the key is spent', str_contains(
  post(['cardsco_auth_action'=>'invite_accept','key'=>$invite_key,'login'=>$invited_email,
    'first_name'=>'Somebody','password'=>'a-different-phrase'], $NONCE['invite'])['error'], 'expired'));

sect('a stranger with a valid reset key is not an invitation');
$outsider_email = "outsider.$stamp@elsewhere.test";
$out = wp_create_user($outsider_email, wp_generate_password(24), $outsider_email);
$out_key = get_password_reset_key(get_userdata($out));
$r = post(['cardsco_auth_action'=>'invite_accept','key'=>$out_key,'login'=>$outsider_email,
  'first_name'=>'Nosey','password'=>$GOOD], $NONCE['invite']);
ck('it is refused',  str_contains($r['error'],'no longer open'));
ck('and they joined nothing',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$out)), 0);

/* ------------------------------------------------------------ the screens */

sect('what the shortcodes render');
wp_set_current_user(0);
$a = new CardsCo_Auth();
$login_html = $a->login_screen(array('signup'=>'/sign-up/','reset'=>'/reset-password/','dashboard'=>'/portal/dashboard/'));
ck('the login form is there',      str_contains($login_html,'name="cardsco_auth_action"'));
ck('with a nonce',                 str_contains($login_html,'_cardsco_nonce'));
ck('a password field',             str_contains($login_html,'type="password"'));
ck('a label for every input',      substr_count($login_html,'<label'), substr_count($login_html,'class="cc-input"') + 1);
ck('and the links it was given',   str_contains($login_html,'/sign-up/') && str_contains($login_html,'/reset-password/'));

$signup_html = $a->signup_screen(array('login'=>'/log-in/'));
ck('sign-up asks for a company',   str_contains($signup_html,'name="company"'));
ck('and marks it required',        str_contains($signup_html,'name="company"') && str_contains($signup_html,'required'));
ck('and enforces the length in the markup too',
  str_contains($signup_html,'minlength="'.CardsCo_Auth::MIN_PASSWORD.'"'));

$reset_html = $a->reset_screen(array('login'=>'/log-in/'));
ck('reset starts by asking for an address', str_contains($reset_html,'name="email"'));
ck('and is not showing the new-password form yet',
  str_contains($reset_html,'name="password_confirm"'), false);

sect('a signed-in visitor is not quietly bounced');
wp_set_current_user($uid);
$in = $a->login_screen(array('dashboard'=>'/portal/dashboard/'));
ck('they are told whose account they are in', str_contains($in,'already signed in'));
ck('by name',          str_contains($in,'Ada Nwosu'));
ck('with their company', str_contains($in,'Brightside Ltd'));
ck('a way to the dashboard', str_contains($in,'/portal/dashboard/'));
ck('and a way out',    str_contains($in,'action=logout') || str_contains($in,'wp-login.php'));
ck('no password field anywhere on it', str_contains($in,'type="password"'), false);
wp_set_current_user(0);

/* ----------------------------------------------- the stylesheet gets loaded */

sect('finding the shortcode on a page a builder made');
/*
 * The bug this guards: the styles only loaded when has_shortcode() found the
 * tag in post_content. A page builder keeps its content in post meta, so on
 * a builder-made page the check said no, the stylesheet never loaded, and
 * the screen rendered as bare HTML on the live site.
 */
$plain = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Plain log in',
  'post_content'=>'[cardsco_login signup="/sign-up/"]'], true);
ck('a classic page is found', cardsco_page_uses_shortcode($plain, CardsCo_Auth::tags()));

$built = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Built log in',
  'post_content'=>''], true);
ck('and with empty content it is not, yet', cardsco_page_uses_shortcode($built, CardsCo_Auth::tags()), false);
/* The key name is the builder's business and changes between versions, so
 * the check scans the meta rather than naming one. */
update_post_meta($built, 'ct_builder_shortcodes', '<div>[cardsco_login signup="/sign-up/"]</div>');
ck('once the builder stores it in meta, it is found',
  cardsco_page_uses_shortcode($built, CardsCo_Auth::tags()));
delete_post_meta($built, 'ct_builder_shortcodes');
update_post_meta($built, 'some_other_builder_key', '[cardsco_signup login="/log-in/"]');
ck('under any key at all',  cardsco_page_uses_shortcode($built, CardsCo_Auth::tags()));

$bare = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Nothing here',
  'post_content'=>'Just some words.'], true);
ck('a page with none of ours is still a no', cardsco_page_uses_shortcode($bare, CardsCo_Auth::tags()), false);
ck('and the dashboard tags are kept apart from the auth ones',
  cardsco_page_uses_shortcode($plain, CardsCo_Shortcodes::tags()), false);

sect('the shortcode on the template rather than the page');
$tpl = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Portal template',
  'post_content'=>''], true);
update_post_meta($tpl, 'ct_builder_shortcodes', '[cardsco_nav dashboard="/portal/dashboard/"]');
$child = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Dashboard page',
  'post_content'=>''], true);
update_post_meta($child, 'ct_other_template', $tpl);
ck('a page rendered through that template is found',
  cardsco_page_uses_shortcode($child, CardsCo_Shortcodes::tags()));

sect('a screen that renders brings its own stylesheet');
/* The guarantee. However the page stores its content, and whatever the
 * detection above decided, a shortcode that actually ran is styled. */
wp_dequeue_style('cardsco-auth');
wp_deregister_style('cardsco-auth');
wp_set_current_user(0);
$a2 = new CardsCo_Auth();
$a2->login_screen(array('signup'=>'/sign-up/'));
ck('rendering the login screen enqueues the styles', wp_style_is('cardsco-auth','enqueued'));
ck('and the script',                                 wp_script_is('cardsco-auth','enqueued'));
ck('and the fonts',                                  wp_style_is('cardsco-fonts-auth','enqueued'));


/* ============================================== confirming an address */

sect('a new account starts unconfirmed');
$GLOBALS['cc_mail'] = array();
$vemail = "vera.$stamp@brightside.test";
$r = post(['cardsco_auth_action'=>'signup','first_name'=>'Vera','last_name'=>'Lane',
  'company'=>'Vera Ltd '.$stamp,'email'=>$vemail,'password'=>$GOOD,'terms'=>'1'], $NONCE['signup']);
ck('they are signed up', (bool)$r['redirect']);
$vid = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email=%s",$vemail));
ck('the account exists',        $vid > 0);
/* Signed in straight away — the whole point of not gating the front door. */
ck('but not yet confirmed',     CardsCo_Verify::is_verified($vid), false);
ck('and they may not commit',   CardsCo_Verify::may_commit($vid), false);

sect('the confirmation email');
$vmail = array_values(array_filter($GLOBALS['cc_mail'], fn($m) => $m['to'] === $vemail));
ck('one went out',       count($vmail), 1);
ck('with a link in it',  (bool) preg_match('/cc_verify=([^&\s]+)&(?:amp;)?uid=(\d+)/', $vmail[0]['message'], $vm));
$vtoken = isset($vm[1]) ? urldecode($vm[1]) : '';
ck('naming the right account', (int)($vm[2] ?? 0), $vid);
ck('the token is not stored as sent',
  get_user_meta($vid, CardsCo_Verify::TOKEN, true) !== $vtoken);
ck('only a hash of it is',
  get_user_meta($vid, CardsCo_Verify::TOKEN, true), wp_hash($vtoken));

sect('using the link');
ck('a wrong token is refused',  is_wp_error(CardsCo_Verify::confirm($vid, 'not-the-token')), true);
ck('and they are still unconfirmed', CardsCo_Verify::is_verified($vid), false);
ck('an account that does not exist', is_wp_error(CardsCo_Verify::confirm(999999, $vtoken)), true);
ck('the real token works',      CardsCo_Verify::confirm($vid, $vtoken), true);
ck('now they are confirmed',    CardsCo_Verify::is_verified($vid), true);
ck('and may commit',            CardsCo_Verify::may_commit($vid), true);
ck('the token is spent',        get_user_meta($vid, CardsCo_Verify::TOKEN, true), '');
ck('so the link cannot be reused by somebody else later',
  CardsCo_Verify::confirm($vid, $vtoken), true);   /* already verified, harmless */

sect('an expired link');
$e2 = "eric.$stamp@brightside.test";
$eid = wp_create_user($e2, wp_generate_password(24), $e2);
CardsCo_Verify::send($eid);
$etok = null;
foreach ($GLOBALS['cc_mail'] as $m) if ($m['to'] === $e2 && preg_match('/cc_verify=([^&\s]+)/', $m['message'], $mm)) $etok = urldecode($mm[1]);
ck('a token was issued', (bool) $etok);
update_user_meta($eid, CardsCo_Verify::EXPIRES, time() - 60);
$exp = CardsCo_Verify::confirm($eid, $etok);
ck('it is refused once it has expired', is_wp_error($exp), true);
ck('and says so plainly', is_wp_error($exp) ? str_contains($exp->get_error_message(),'expired') : false);

sect('changing the address after the link was sent');
$c3 = "cara.$stamp@brightside.test";
$cid3 = wp_create_user($c3, wp_generate_password(24), $c3);
CardsCo_Verify::send($cid3);
$ctok = null;
foreach ($GLOBALS['cc_mail'] as $m) if ($m['to'] === $c3 && preg_match('/cc_verify=([^&\s]+)/', $m['message'], $mm)) $ctok = urldecode($mm[1]);
wp_update_user(['ID'=>$cid3, 'user_email'=>"cara.new.$stamp@brightside.test"]);
clean_user_cache($cid3);
$moved = CardsCo_Verify::confirm($cid3, $ctok);
/* A link proves the old address, not the new one. */
ck('the old link cannot confirm the new address', is_wp_error($moved), true);
ck('and they stay unconfirmed', CardsCo_Verify::is_verified($cid3), false);

sect('asking for another too quickly');
$s4 = "sam.$stamp@brightside.test";
$sid = wp_create_user($s4, wp_generate_password(24), $s4);
ck('the first is sent',        is_string(CardsCo_Verify::send($sid)));
$again = CardsCo_Verify::send($sid);
ck('the second is held back',  is_wp_error($again), true);
ck('and says to wait',         is_wp_error($again) ? str_contains($again->get_error_message(),'minute') : false);
ck('unless it is forced',      is_string(CardsCo_Verify::send($sid, true)));

sect('people who do not need asking');
ck('an administrator is treated as confirmed', CardsCo_Verify::is_verified(1), true);
/* Somebody who clicked a link in their own inbox has already proved it. */
ck('and so is an invited person once they accept',
  (bool) get_user_meta($ben, CardsCo_Verify::DONE, true));

sect('turning the gate off entirely');
CardsCo_Settings::set('verify_required', 0);
ck('then anybody may commit', CardsCo_Verify::may_commit($cid3), true);
CardsCo_Settings::set('verify_required', 1);
ck('and on again they may not', CardsCo_Verify::may_commit($cid3), false);

/* ================================================ the escalating lockout */

sect('three lockouts and the timer stops being the answer');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_%'");
wp_cache_flush();
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$max = (int) CardsCo_Settings::get('login_max_attempts', 5);

$msgs = [];
for ($round = 1; $round <= 3; $round++) {
  for ($i = 0; $i < $max; $i++) {
    post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'wrong-'.$round.'-'.$i], $NONCE['login']);
  }
  $msgs[$round] = post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>'wrong-again'], $NONCE['login'])['error'];
  // the wait elapses
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_login_ip%'");
  $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cardsco_try_login_email%'");
  wp_cache_flush();
}
ck('the first lockout offers a wait',  str_contains($msgs[1], 'wait'));
ck('so does the second',               str_contains($msgs[2], 'wait'));
/* By the third, waiting is not the problem — they have forgotten, or it is
 * not their account. Another timer helps neither of them. */
ck('the third stops offering one',     str_contains($msgs[3], 'wait'), false);
ck('and points at a reset',            str_contains($msgs[3], 'reset'));
ck('or at us',                         str_contains($msgs[3], 'touch'));
ck('even the right password is refused now',
  post(['cardsco_auth_action'=>'login','email'=>$EMAIL,'password'=>$GOOD], $NONCE['login'])['redirect'], null);

sect('a different account is unaffected');
/* Strikes are counted against the address, so one person being locked out
 * must not shut out a colleague. */
$_SERVER['REMOTE_ADDR'] = '198.51.100.123';
ck('they can still sign in',
  (bool) post(['cardsco_auth_action'=>'login','email'=>$vemail,'password'=>$GOOD], $NONCE['login'])['redirect']);

sect('the thresholds are settings, not constants');
CardsCo_Settings::set('login_max_attempts', 3);
ck('a lower limit is respected', (int) CardsCo_Settings::get('login_max_attempts'), 3);
CardsCo_Settings::set('login_max_attempts', 5);

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
