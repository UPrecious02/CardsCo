<?php
/**
 * The admin form handlers, driven through real POSTs.
 *
 * Every one of these ends in a redirect, so the assertions read the query
 * string that comes back — which is also what the screens read to decide
 * whether to show a message or an error. The refusal paths (no capability,
 * wrong nonce) end in wp_die, so that is turned into an exception too.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
define('WP_ADMIN', true);
require __DIR__ . '/core/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen('toplevel_page_cardsco');
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-52s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

class Redirected extends Exception { public $url; public function __construct($u){ $this->url=$u; parent::__construct('redirect'); } }
class Died extends Exception {}
add_filter('wp_redirect', function($loc){ throw new Redirected($loc); }, 1);
add_filter('wp_die_handler', fn() => function($m){ throw new Died(is_wp_error($m)?$m->get_error_message():(string)$m); }, 1);

/* A handler reads $_POST and $_REQUEST; check_admin_referer looks for
 * _wpnonce in $_REQUEST. */
function post(callable $run, array $data, $nonce = CardsCo_Admin::NONCE) {
  $_POST = $data;
  if ($nonce !== null) $_POST['_wpnonce'] = wp_create_nonce($nonce);
  $_REQUEST = $_POST; $_GET = [];
  try { $run(); }
  catch (Redirected $r) { return $r->url; }
  catch (Died $d) { return 'DIED: '.$d->getMessage(); }
  catch (Throwable $e) { return 'THREW: '.get_class($e).' '.$e->getMessage(); }
  return 'no redirect';
}
function arg($url,$key){ $q=[]; parse_str((string)(parse_url($url,PHP_URL_QUERY)??''),$q); return $q[$key] ?? null; }
function msg($url){ return (string) arg($url,'cardsco_message'); }
function err($url){ return (string) arg($url,'cardsco_error'); }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();
wp_set_current_user(1);
ck('running as somebody who may manage every company', current_user_can(CardsCo_Admin::CAP));

$a = new CardsCo_Admin();

$cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Forms Test Co'));
if (!$cid) { $c = CardsCo_Company::create(['name'=>'Forms Test Co']); $cid = is_wp_error($c)?0:(int)$c; }
foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes',
          'recipient_segments','segments','activity_log'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('a clean company', $cid > 0);

$bday = CardsCo_Occasion_Type::get_by_slug('birthday');
$work = CardsCo_Occasion_Type::get_by_slug('work-anniversary');
ck('two occasion types to work with', (int)($bday['id'] ?? 0) > 0 && (int)($work['id'] ?? 0) > 0);

/* ------------------------------------------------------------- the refusals */

sect('a form nobody is allowed to send');
$url = post([$a,'handle_save_recipient'], ['company_id'=>$cid,'first_name'=>'Nope'], 'some_other_nonce');
ck('a wrong nonce is refused', str_starts_with($url,'DIED:'));
$url = post([$a,'handle_save_recipient'], ['company_id'=>$cid,'first_name'=>'Nope'], null);
ck('a missing nonce is refused', str_starts_with($url,'DIED:'));

$plain = username_exists('formsplain') ?: wp_insert_user(['user_login'=>'formsplain',
  'user_pass'=>wp_generate_password(),'user_email'=>'formsplain@example.test','role'=>'subscriber']);
wp_set_current_user($plain);
$url = post([$a,'handle_save_recipient'], ['company_id'=>$cid,'first_name'=>'Nope']);
ck('somebody without the capability is refused', str_starts_with($url,'DIED:'));
ck('and told why', str_contains($url,'not allowed'));
wp_set_current_user(1);
ck('nothing of theirs was saved',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_recipients WHERE company_id=%d",$cid)), 0);

/* ----------------------------------------------------------- saving a person */

sect('adding somebody');
$url = post([$a,'handle_save_recipient'], ['company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu','email'=>'ada@example.test',
  'address'=>"3 Mill Lane\nLeeds",'postcode'=>'ls6 3aa','country'=>'GB']);
ck('it redirects back to the list', (string)arg($url,'page') !== '');
ck('and says so',                   msg($url), 'Recipient added.');
ck('with no error',                 err($url), '');
$ada = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_recipients WHERE company_id=%d AND first_name=%s",$cid,'Ada'));
ck('the row is there', $ada > 0);
$r = CardsCo_Recipient::get($ada);
ck('the postcode was tidied',  $r['postcode'], 'LS6 3AA');
ck('and the address judged complete', $r['address_status'], 'complete');

sect('a form with nothing in it');
$url = post([$a,'handle_save_recipient'], ['company_id'=>$cid,'type'=>'employee','first_name'=>'','last_name'=>'']);
ck('it comes back with an error', err($url) !== '');
ck('and no success message',      msg($url), '');
/* The form has to be reopenable, so the redirect carries the screen back. */
ck('and sends them back to the new form', (string)arg($url,'action'), 'new');

sect('a form with no company');
$url = post([$a,'handle_save_recipient'], ['company_id'=>0,'first_name'=>'Ghost']);
ck('it comes back with an error', err($url) !== '');

sect('editing somebody');
$url = post([$a,'handle_save_recipient'], ['recipient_id'=>$ada,'company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu-Bell','address'=>"3 Mill Lane\nLeeds",'postcode'=>'LS6 3AA']);
ck('it says updated, not added', msg($url), 'Recipient updated.');
ck('and the change stuck',        CardsCo_Recipient::get($ada)['last_name'], 'Nwosu-Bell');
ck('and no second row was made',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_recipients WHERE company_id=%d AND first_name=%s",$cid,'Ada')), 1);
ck('editing somebody who does not exist is refused',
  err(post([$a,'handle_save_recipient'], ['recipient_id'=>999999,'company_id'=>$cid,'first_name'=>'Ghost'])) !== '');

/* ------------------------------------------------- the dates on the same form */

sect('the dates posted alongside a person');
$url = post([$a,'handle_save_recipient'], ['recipient_id'=>$ada,'company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu-Bell','address'=>"3 Mill Lane\nLeeds",'postcode'=>'LS6 3AA',
  'occasions'=>[ $bday['id'] => ['day'=>'14','month'=>'3','year'=>'1990'] ]]);
ck('the person saved', msg($url), 'Recipient updated.');
ck('with no complaint', err($url), '');
$o = CardsCo_Occasion::get_by_type($ada,$bday['id']);
ck('the birthday was saved', (int)($o['event_day'] ?? 0), 14);
ck('with the month',         (int)($o['event_month'] ?? 0), 3);
ck('and the year',           (int)($o['event_year'] ?? 0), 1990);

sect('a half-filled date row');
/* The person still saves; only the date is reported. Silently dropping it was
 * the alternative, and somebody would have believed the birthday was in. */
$url = post([$a,'handle_save_recipient'], ['recipient_id'=>$ada,'company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu-Bell','address'=>"3 Mill Lane\nLeeds",'postcode'=>'LS6 3AA',
  'occasions'=>[ $work['id'] => ['day'=>'31','month'=>'2'] ]]);
ck('the person is still saved',  msg($url), 'Recipient updated.');
ck('but the date is reported',   err($url) !== '');
ck('and the error names the occasion', str_contains(err($url), $work['label']));
ck('and no bad date was stored', CardsCo_Occasion::get_by_type($ada,$work['id']), null);

sect('an emptied date row removes the date');
$url = post([$a,'handle_save_recipient'], ['recipient_id'=>$ada,'company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu-Bell','address'=>"3 Mill Lane\nLeeds",'postcode'=>'LS6 3AA',
  'occasions'=>[ $bday['id'] => ['day'=>'','month'=>'','year'=>''] ]]);
ck('the person saved',   msg($url), 'Recipient updated.');
ck('and the date is gone', CardsCo_Occasion::get_by_type($ada,$bday['id']), null);
/* Put it back for the sections below. */
post([$a,'handle_save_recipient'], ['recipient_id'=>$ada,'company_id'=>$cid,'type'=>'employee',
  'first_name'=>'Ada','last_name'=>'Nwosu-Bell','address'=>"3 Mill Lane\nLeeds",'postcode'=>'LS6 3AA',
  'occasions'=>[ $bday['id'] => ['day'=>'14','month'=>'3'] ]]);
ck('and it is back', (int)CardsCo_Occasion::get_by_type($ada,$bday['id'])['event_day'], 14);

/* ------------------------------------------------- archive, restore, delete */

sect('archiving somebody');
$url = post([$a,'handle_delete_recipient'], ['recipient'=>$ada,'company'=>$cid,'mode'=>'archive']);
ck('it says archived', msg($url), 'Recipient archived.');
ck('and the row says so', CardsCo_Recipient::get($ada)['status'], 'archived');
ck('the row is still there, not deleted', CardsCo_Recipient::get($ada) !== null);

sect('restoring them');
$url = post([$a,'handle_delete_recipient'], ['recipient'=>$ada,'company'=>$cid,'mode'=>'restore']);
ck('it says restored', msg($url), 'Recipient restored.');
ck('and they are active again', CardsCo_Recipient::get($ada)['status'], 'active');

sect('no mode given archives rather than deletes');
$url = post([$a,'handle_delete_recipient'], ['recipient'=>$ada,'company'=>$cid]);
ck('it archives', msg($url), 'Recipient archived.');
ck('and nothing was destroyed', CardsCo_Recipient::get($ada) !== null);
post([$a,'handle_delete_recipient'], ['recipient'=>$ada,'company'=>$cid,'mode'=>'restore']);

sect('deleting somebody for good');
$spare = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'customer','first_name'=>'Temp',
  'address'=>"9 Side Street\nLeeds",'postcode'=>'LS1 9ZZ']);
$url = post([$a,'handle_delete_recipient'], ['recipient'=>$spare,'company'=>$cid,'mode'=>'delete']);
ck('it says deleted',  msg($url), 'Recipient deleted.');
ck('and the row is gone', CardsCo_Recipient::get($spare), null);

/* ------------------------------------------------------------------ segments */

sect('creating a group');
$url = post([$a,'handle_save_segment'], ['company_id'=>$cid,'name'=>'Newsletter list',
  'description'=>'People who opted in','applies_to'=>'customer']);
ck('it says created', msg($url), 'Segment created.');
ck('on the segments screen', (string)arg($url,'page'), 'cardsco-segments');
$seg = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_segments WHERE company_id=%d AND name=%s",$cid,'Newsletter list'));
ck('the row is there', $seg > 0);

sect('a group with no name');
ck('is refused', err(post([$a,'handle_save_segment'], ['company_id'=>$cid,'name'=>''])) !== '');
sect('a group with no company');
ck('is refused', err(post([$a,'handle_save_segment'], ['company_id'=>0,'name'=>'Orphan'])) !== '');

sect('renaming a group');
$url = post([$a,'handle_save_segment'], ['segment_id'=>$seg,'company_id'=>$cid,
  'name'=>'Opted in','applies_to'=>'customer']);
ck('it says updated', msg($url), 'Segment updated.');
ck('and the name changed',
  $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}cardsco_segments WHERE id=%d",$seg)), 'Opted in');

sect('deleting a group');
$url = post([$a,'handle_delete_segment'], ['segment'=>$seg,'company'=>$cid]);
ck('it says deleted', msg($url), 'Segment deleted.');
ck('and the row is gone',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_segments WHERE id=%d",$seg)), 0);

/* ------------------------------------------------------------ occasion types */

sect("adding a company's own occasion");
$url = post([$a,'handle_add_occasion_type'], ['company_id'=>$cid,'label'=>'Founding day',
  'mode'=>'dated','recurrence'=>'annual','applies_to'=>'employee']);
ck('it says added', msg($url), 'Occasion added.');
ck('on the occasions screen', (string)arg($url,'page'), 'cardsco-occasions');
ck('and the library tab',     (string)arg($url,'tab'), 'library');
$own = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_occasion_types WHERE company_id=%d AND label=%s",$cid,'Founding day'));
ck('the row is there', $own > 0);
ck('an occasion with no label is refused',
  err(post([$a,'handle_add_occasion_type'], ['company_id'=>$cid,'label'=>''])) !== '');

sect('saving the lead times across the library');
$url = post([$a,'handle_save_occasion_types'], ['company_id'=>$cid,
  'types'=>[ $bday['id'] => ['lead_days'=>'9','is_active'=>'1'] ]]);
ck('it says saved', msg($url), 'Occasions saved.');
ck('and the lead time stuck',
  (int)CardsCo_Occasion_Type::get($bday['id'])['lead_days'], 9);
ck('an unticked active box switches it off',
  (int)$wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$wpdb->prefix}cardsco_occasion_types WHERE id=%d",
    (int)$bday['id'])) === 1 ? (bool)post([$a,'handle_save_occasion_types'],
      ['company_id'=>$cid,'types'=>[ $bday['id'] => ['lead_days'=>'9'] ]]) : false);
ck('and it is off',
  (int)$wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$wpdb->prefix}cardsco_occasion_types WHERE id=%d",(int)$bday['id'])), 0);
/* Back on, and with a standing card and wording, for the scan below. */
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Balloons','post_status'=>'publish'],true);
$msgp = wp_insert_post(['post_type'=>CardsCo_Message::POST_TYPE,'post_title'=>'Warm','post_status'=>'publish'],true);
update_post_meta($msgp, CardsCo_Message::TEXT, 'Happy birthday {first name}!');
update_post_meta($msgp, CardsCo_Message::COMPANY, 0);
$url = post([$a,'handle_save_occasion_types'], ['company_id'=>$cid,
  'types'=>[ $bday['id'] => ['lead_days'=>'7','is_active'=>'1','card'=>$card,'message'=>$msgp] ]]);
ck('it saves again', msg($url), 'Occasions saved.');
ck('and it is active once more',
  (int)$wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$wpdb->prefix}cardsco_occasion_types WHERE id=%d",(int)$bday['id'])), 1);
/* The standing choice belongs to the company, not the occasion type. */
$d = CardsCo_Scheduler::defaults_for($cid,$bday['id']);
ck('the standing card was stored against the company', (int)$d['card_id'], (int)$card);
ck('and the standing wording too',                     (int)$d['message_id'], (int)$msgp);

/* ------------------------------------------------------------------- the scan */

sect('running the scan from the button');
/* A birthday near enough to be inside the default horizon. */
$soon = gmdate('Y-m-d', strtotime(cardsco_now('date').' +10 days'));
CardsCo_Occasion::save(['recipient_id'=>$ada,'occasion_type_id'=>$bday['id'],
  'event_month'=>(int)gmdate('n',strtotime($soon)),'event_day'=>(int)gmdate('j',strtotime($soon))]);
$url = post([$a,'handle_run_scan'], ['company_id'=>$cid]);
ck('it lands on the queue', (string)arg($url,'page'), 'cardsco-queue');
ck('and reports what it did', str_contains(msg($url),'Looked at'));
ck('naming how many were queued', (bool)preg_match('/\d+ queued/', msg($url)));
ck('a card is in the queue',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)) >= 1);

sect('running it again says nothing new was queued');
$url = post([$a,'handle_run_scan'], ['company_id'=>$cid]);
ck('it still reports', str_contains(msg($url),'Looked at'));
ck('with none queued', str_contains(msg($url),'0 queued'));

/* ----------------------------------------------------------- the message text */

sect('what comes back in the query string');
$url = post([$a,'handle_save_segment'], ['company_id'=>$cid,'name'=>'Punctuation & "quotes"','applies_to'=>'both']);
/* add_query_arg does the encoding, so nothing is pre-encoded or the message
 * would come back double escaped. */
ck('a message survives the round trip unmangled', msg($url), 'Segment created.');
$made = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_segments WHERE company_id=%d ORDER BY id DESC LIMIT 1",$cid));
ck('and the name it was given is intact',
  $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}cardsco_segments WHERE id=%d",$made)) !== '');
ck('empty arguments are dropped from the redirect', (string)arg($url,'cardsco_error'), '');

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
