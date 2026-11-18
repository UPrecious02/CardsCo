<?php
/**
 * The front-end occasion set-up wizard, driven through its real handler.
 *
 * This suite exists because the wizard's screens are the part of the build I
 * cannot currently check against Figma, so the behaviour underneath them has
 * to be pinned down by something other than looking at it. Everything here
 * goes through CardsCo_Setup::handle() with real $_POST and a real nonce
 * rather than calling the model directly, so the gate covers the nonce check,
 * the company check, the field whitelist and the redirect, not just the save.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/portal/occasions/';
$_SERVER['REQUEST_METHOD'] = 'POST';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-52s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

/* A redirect ends in exit(), so it is turned into an exception the suite can
 * catch. wp_safe_redirect() routes through the wp_redirect filter too, so one
 * filter covers both. */
class Redirected extends Exception { public $url; public function __construct($u){ $this->url=$u; parent::__construct('redirect'); } }
add_filter('wp_redirect', function($loc){ throw new Redirected($loc); }, 1);

function submit(array $data, $nonce = 'cardsco_setup') {
  $_POST = $data;
  if ($nonce !== null) $_POST['_cardsco_nonce'] = wp_create_nonce($nonce);
  $_REQUEST = $_POST; $_GET = [];
  $w = new CardsCo_Setup();
  try { $w->handle(); }
  catch (Redirected $r) { return $r->url; }
  catch (Throwable $e) { return 'THREW: '.get_class($e).' '.$e->getMessage(); }
  return 'no redirect';
}
function arg($url,$key){ $q=[]; parse_str((string)(parse_url($url,PHP_URL_QUERY)??''),$q); return $q[$key] ?? null; }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();

/* ---------------------------------------------------------------- fixtures */

function company_with_owner($name, $login) {
  global $wpdb;
  $cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",$name));
  if (!$cid) { $c = CardsCo_Company::create(['name'=>$name]); $cid = is_wp_error($c)?0:(int)$c; }
  foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes','activity_log'] as $t)
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
  $uid = username_exists($login);
  if (!$uid) $uid = wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(),
    'user_email'=>$login.'@example.test','role'=>CardsCo_Roles::COMPANY_ROLE]);
  CardsCo_Company::add_user($cid,$uid,'owner');
  return [$cid,(int)$uid];
}

list($cid,$uid)   = company_with_owner('Wizard Test Co','wizowner');
list($other,$ouid)= company_with_owner('Wizard Other Co','wizother');
wp_cache_flush();
wp_set_current_user($uid);

/* Turning a programme on now needs a confirmed address, which is the right
 * behaviour and nothing to do with the wizard's own mechanics. The gate
 * itself is asserted at the end of this suite. */
update_user_meta($uid, CardsCo_Verify::DONE, cardsco_now());

ck('company and owner', $cid>0 && $uid>0);
ck('the owner is scoped to that company', CardsCo_Access::company_id(), $cid);

$bday = CardsCo_Occasion_Type::get_by_slug('birthday');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Balloons','post_status'=>'publish'],true);
CardsCo_Card::set_occasions($card,[(int)$bday['id']]);
$draft_card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Unfinished','post_status'=>'draft'],true);

$people = [];
foreach ([['Ada','Nwosu',3,14],['Ben','Clarke',7,2]] as $i=>$p) {
  list($f,$l,$m,$d) = $p;
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$f,'last_name'=>$l,
    'address'=>(2+$i)." Mill Lane\nLeeds",'postcode'=>'LS6 3AA']);
  if (is_wp_error($rid)) continue;
  $people[] = $rid;
  CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>$m,'event_day'=>$d]);
}
ck('two people with birthdays', count($people), 2);

/* --------------------------------------------------- the first screen is usable */

sect('a cold start can actually get past step 1');
$fresh = array('id'=>0,'company_id'=>0,'category'=>'','setup_step'=>1,'card_id'=>0);
$rail  = CardsCo_Dashboard::setup_rail($fresh, 1);
ck('Continue is live before anything is chosen', $rail['can_continue']);
ck('and the footer says nothing is chosen yet', $rail['footer'], 'Nothing selected yet');

/* ------------------------------------------------------------- the step list */

sect('the step list');
$steps = CardsCo_Setup::steps();
ck('seven steps', count($steps), 7);
ck('one list, shared with the model', $steps === CardsCo_Programme::steps());
ck('in order, 1 to 7', array_keys($steps), [1,2,3,4,5,6,7]);
ck('keys are the ones the views ask for',
  array_column($steps,'key'), ['occasion','people','card','message','shipping','timing','review']);
ck('every step has a heading', count(array_filter(array_column($steps,'heading'))), 7);
/* The frame writes these two with apostrophes; a straight rewrite would be a
 * copy deviation, so the strings are pinned here. */
ck("step 2's heading is the frame's wording", $steps[2]['heading'], "Who's celebrating?");
ck("step 1's heading is the frame's wording", $steps[1]['heading'], 'Which occasion?');

/* ------------------------------------------------------- forward-jump gating */

sect('which step is allowed on screen');
$at = function($furthest){ return ['setup_step'=>$furthest]; };
ck('cannot skip from 2 to 5',          CardsCo_Setup::step_on_screen($at(2),5), 2);
ck('can go back from 5 to 2',          CardsCo_Setup::step_on_screen($at(5),2), 2);
ck('can sit on the furthest reached',  CardsCo_Setup::step_on_screen($at(4),4), 4);
ck('no step asked shows the furthest', CardsCo_Setup::step_on_screen($at(3),0), 3);
ck('junk in the query string is not step 0', CardsCo_Setup::step_on_screen($at(3),'nonsense'), 3);
ck('a negative step does not escape',  CardsCo_Setup::step_on_screen($at(3),-9), 3);
ck('never past 7',                     CardsCo_Setup::step_on_screen($at(99),99), 7);
ck('a fresh programme sits on 1',      CardsCo_Setup::step_on_screen($at(1),1), 1);
ck('a 0 in the row still shows 1',     CardsCo_Setup::step_on_screen($at(0),0), 1);

ck('step 2 is done once 3 is reached', CardsCo_Setup::done($at(3),2));
ck('the step on screen is not done',   CardsCo_Setup::done($at(3),3), false);
ck('a later step is not done',         CardsCo_Setup::done($at(3),5), false);

/* -------------------------------------------------------- the field whitelist */

sect('what each step accepts');
$noise = ['status'=>'active','company_id'=>999,'id'=>7,'setup_step'=>7,'unit_price'=>'0.01'];
ck('step 1 takes no fields from the form', CardsCo_Setup::fields(1,$noise+['category'=>'x']), []);
ck('step 2 takes no fields from the form', CardsCo_Setup::fields(2,$noise), []);
ck('an unknown step takes nothing',        CardsCo_Setup::fields(9,$noise), []);

$f3 = CardsCo_Setup::fields(3,$noise+['card_id'=>'42abc']);
ck('step 3 keeps only the card',  array_keys($f3), ['card_id']);
ck('and reads it as a number',    $f3['card_id'], 42);

$f4 = CardsCo_Setup::fields(4,$noise+['message_id'=>'5','message_override'=>'Hi {first name}! <b>x</b>',
  'handwriting'=>'Arthur','ink'=>'blue','signature'=>'The team','extra'=>'no']);
ck('step 4 keeps its five fields', count($f4), 5);
ck('and drops anything else',      isset($f4['extra']), false);
/* The braces have to survive the handler: sanitising here would strip the
 * merge field before the model's validator ever sees it. */
ck('merge-field braces survive',   str_contains($f4['message_override'],'{first name}'));

/*
 * A field the screen did not send is left out entirely, rather than
 * returned as a default. These used to assert the opposite, and that is
 * what blanked a saved message when somebody pressed Continue on step 4:
 * the screen has no message box, so nothing about the message was posted,
 * and the step saved empty over all of it.
 *
 * Nothing is lost by dropping them. Every one of these columns carries the
 * same value as its own default in the schema — shipping_mode 'company',
 * ship_country 'GB', timing_mode 'monthly_box', box_arrive_day 25,
 * recurrence 'annual' — so a new row still starts where it should.
 */
$f5 = CardsCo_Setup::fields(5,$noise);
ck('step 5 sends back nothing it was not given', $f5, []);

$f5_sent = CardsCo_Setup::fields(5,$noise+['shipping_mode'=>'company','ship_postcode'=>'LS2 9NZ']);
ck('but what it was given it keeps',   $f5_sent['shipping_mode'], 'company');
ck('and only that',                    array_keys($f5_sent), ['shipping_mode','ship_postcode']);

$f6_off = CardsCo_Setup::fields(6,$noise);
ck('an unticked checkbox is false, not absent', $f6_off['weekend_rule'], false);
ck('and the tickboxes are all step 6 assumes',  array_keys($f6_off), ['weekend_rule','notify_before_box']);

$f6_on = CardsCo_Setup::fields(6,$noise+['timing_mode'=>'monthly_box','box_arrive_day'=>'25','recurrence'=>'annual']);
ck('a posted mode is kept',            $f6_on['timing_mode'], 'monthly_box');
ck('a posted arrival day is kept',     $f6_on['box_arrive_day'], 25);
ck('a posted recurrence is kept',      $f6_on['recurrence'], 'annual');
$f6_on = CardsCo_Setup::fields(6,['weekend_rule'=>'1','notify_before_box'=>'on','box_arrive_day'=>'20']);
ck('a ticked checkbox is true',                 $f6_on['weekend_rule'], true);
ck('both checkboxes read independently',        $f6_on['notify_before_box'], true);

/* ------------------------------------------------------------ nothing happens */

sect('submissions that should do nothing at all');
$before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_programmes WHERE company_id=%d",$cid));
ck('no action, no redirect',   submit(['category'=>'employee-birthdays']), 'no redirect');
ck('a wrong nonce is ignored', submit(['cardsco_setup_action'=>'start','category'=>'employee-birthdays'],'some_other_action'), 'no redirect');
ck('a missing nonce is ignored', submit(['cardsco_setup_action'=>'start','category'=>'employee-birthdays'],null), 'no redirect');
wp_set_current_user(0);
ck('a logged-out visitor is ignored', submit(['cardsco_setup_action'=>'start','category'=>'employee-birthdays']), 'no redirect');
wp_set_current_user($uid);
$after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_programmes WHERE company_id=%d",$cid));
ck('none of those wrote a row', $after, $before);

/* ----------------------------------------------------------------- starting */

sect('starting a programme');
/* work-anniversaries is the one the designs mark "coming soon": shown on
 * step 1 but not startable. */
$url = submit(['cardsco_setup_action'=>'start','category'=>'work-anniversaries']);
ck('a coming-soon category is refused', str_contains((string)arg($url,'cardsco_error') ?: '', 'not available')
  || str_contains((string)arg($url,'cardsco_error') ?: '', 'not ready'));
ck('and nothing was started', (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_programmes WHERE company_id=%d",$cid)), $before);

$url = submit(['cardsco_setup_action'=>'start','category'=>'made-up-occasion']);
ck('an invented category is refused', (string)arg($url,'cardsco_error') !== '');

$url  = submit(['cardsco_setup_action'=>'start','category'=>'employee-birthdays']);
$prog = (int) arg($url, CardsCo_Setup::ARG);
ck('a real category starts one', $prog > 0);
ck('and lands on step 2',        (int)arg($url,'step'), 2);
$row = CardsCo_Programme::get($prog);
ck('saved as a draft',           $row['status'], 'draft');
ck('scoped to the right company',(int)$row['company_id'], $cid);
ck('named after the category',   $row['name'] !== '');

/* --------------------------------------------------------- somebody else's */

sect("somebody else's programme");
$theirs = CardsCo_Programme::create($other,'employee-birthdays');
ck('they have one', $theirs > 0);
$url = submit(['cardsco_setup_action'=>'step','step'=>3,'programme_id'=>$theirs,'card_id'=>$card]);
ck('is simply not found', $url, 'no redirect');
ck('and was not touched', (int)CardsCo_Programme::get($theirs)['setup_step'], 2);
$url = submit(['cardsco_setup_action'=>'activate','programme_id'=>$theirs]);
ck('cannot be activated either', $url, 'no redirect');
ck('still a draft', CardsCo_Programme::get($theirs)['status'], 'draft');
$url = submit(['cardsco_setup_action'=>'step','step'=>3,'programme_id'=>999999,'card_id'=>$card]);
ck('a programme that does not exist is not found', $url, 'no redirect');

/* ------------------------------------------------------- walking the steps */

sect('walking the steps through the handler');
CardsCo_Programme::add_recipients($prog,$people);

$url = submit(['cardsco_setup_action'=>'step','step'=>2,'programme_id'=>$prog]);
ck('step 2 moves on to 3', (int)arg($url,'step'), 3);

$url = submit(['cardsco_setup_action'=>'step','step'=>3,'programme_id'=>$prog,'card_id'=>$card,'status'=>'active']);
ck('step 3 moves on to 4', (int)arg($url,'step'), 4);
$row = CardsCo_Programme::get($prog);
ck('the card was saved',   (int)$row['card_id'], $card);
ck('a status smuggled in through the form was ignored', $row['status'], 'draft');

$url = submit(['cardsco_setup_action'=>'step','step'=>4,'programme_id'=>$prog,
  'message_override'=>'Happy birthday {first name}','handwriting'=>'Arthur','ink'=>'blue','signature'=>'The team']);
ck('step 4 moves on to 5', (int)arg($url,'step'), 5);
$row = CardsCo_Programme::get($prog);
ck('the merge field reached the model intact', str_contains($row['message_override'],'{first name}'));
ck('the handwriting was saved', $row['handwriting'], 'Arthur');

$url = submit(['cardsco_setup_action'=>'step','step'=>5,'programme_id'=>$prog,
  'shipping_mode'=>'company','ship_address'=>"Unit 4\nLeeds",'ship_postcode'=>'LS1 4AP','ship_country'=>'gb']);
ck('step 5 moves on to 6', (int)arg($url,'step'), 6);
ck('the country is stored uppercase', CardsCo_Programme::get($prog)['ship_country'], 'GB');

$url = submit(['cardsco_setup_action'=>'step','step'=>6,'programme_id'=>$prog,
  'timing_mode'=>'monthly_box','box_arrive_day'=>'25','starts_on'=>'2026-12-01','recurrence'=>'annual','weekend_rule'=>'1']);
ck('step 6 moves on to 7', (int)arg($url,'step'), 7);
$row = CardsCo_Programme::get($prog);
ck('the start date was saved', $row['starts_on'], '2026-12-01');
ck('the weekend rule was saved', (int)$row['weekend_rule'], 1);

ck('step 7 is as far as it goes', (int)arg(submit(['cardsco_setup_action'=>'step','step'=>7,'programme_id'=>$prog]),'step'), 7);

sect('going back does not undo progress');
$url = submit(['cardsco_setup_action'=>'step','step'=>3,'programme_id'=>$prog,'card_id'=>$card]);
ck('re-saving step 3 sends you to 4', (int)arg($url,'step'), 4);
ck('but the furthest reached is still 7', (int)CardsCo_Programme::get($prog)['setup_step'], 7);
ck('so step 7 is still reachable', CardsCo_Setup::step_on_screen(CardsCo_Programme::get($prog),7), 7);

sect('a bad start date');
$url = submit(['cardsco_setup_action'=>'step','step'=>6,'programme_id'=>$prog,
  'timing_mode'=>'monthly_box','box_arrive_day'=>'25','starts_on'=>'the thirty-first of Octember','recurrence'=>'annual']);
ck('it still redirects rather than erroring', str_contains((string)$url,'step'));
ck('and is stored as no date at all', CardsCo_Programme::get($prog)['starts_on'], null);
/* Put it back so the activation section below starts from a complete row. */
submit(['cardsco_setup_action'=>'step','step'=>6,'programme_id'=>$prog,
  'timing_mode'=>'monthly_box','box_arrive_day'=>'25','starts_on'=>'2026-12-01','recurrence'=>'annual']);

/* ------------------------------------------------------------- turning it on */

sect('turning it on');
$url = submit(['cardsco_setup_action'=>'activate','programme_id'=>$prog]);
ck('a finished programme goes on', (int)arg($url,'done'), 1);
ck('and is active', CardsCo_Programme::get($prog)['status'], 'active');

sect('turning on one that is not finished');
$half = CardsCo_Programme::create($cid,'employee-birthdays');
CardsCo_Programme::add_recipients($half,$people);
CardsCo_Programme::update($half,['setup_step'=>7]);
$url = submit(['cardsco_setup_action'=>'activate','programme_id'=>$half]);
$err = (string) arg($url,'cardsco_error');
ck('it is refused with a reason', $err !== '');
ck('and the reason names a step', str_contains($err,'Step') || str_contains($err,'step'));
ck('it stays a draft', CardsCo_Programme::get($half)['status'], 'draft');
ck('and you are sent back to step 7', (int)arg($url,'step'), 7);

sect('a category with no dates belongs under Campaigns');
/* promotions-offers has no occasion types, so there is no date for the
 * scheduler to act on. Switching it on here would look like it worked and
 * then never send anything, so it has to be refused outright. */
$promo = CardsCo_Programme::create($cid,'promotions-offers');
CardsCo_Programme::update($promo,['card_id'=>$card,'message_override'=>'Hello',
  'shipping_mode'=>'company','ship_address'=>"Unit 4\nLeeds",'ship_postcode'=>'LS1 4AP',
  'timing_mode'=>'monthly_box','box_arrive_day'=>25,'starts_on'=>'2026-12-01','setup_step'=>7]);
CardsCo_Programme::add_recipients($promo,$people);
$url = submit(['cardsco_setup_action'=>'activate','programme_id'=>$promo]);
$perr = (string) arg($url,'cardsco_error');
ck('it is refused even though every step is filled in', $perr !== '');
ck('and the reason sends them to Campaigns', str_contains($perr,'Campaigns'));
ck('it stays a draft', CardsCo_Programme::get($promo)['status'], 'draft');

sect('a card that is not published cannot be used');
CardsCo_Programme::update($half,['card_id'=>$draft_card]);
ck('step 3 is not complete with a draft card',
  CardsCo_Programme::step_complete(CardsCo_Programme::get($half),3), false);
CardsCo_Programme::update($half,['card_id'=>$card]);
ck('and is complete with a published one',
  CardsCo_Programme::step_complete(CardsCo_Programme::get($half),3), true);

sect('choosing an occasion actually moves you on');
/* The assertion that was missing. go() was checked for the arguments it
 * sends and step_on_screen() for the number it returns, separately, and both
 * passed — while the two together pinned the wizard to step 1 for ever,
 * because a new programme was created on setup_step 1 and step_on_screen()
 * clamps whatever is asked for to the furthest step reached.
 *
 * This asks the question a person asks: I picked an occasion, what do I see? */
$startedAt = submit(['cardsco_setup_action'=>'start','category'=>'christmas-seasonal']);
$newId     = (int) arg($startedAt, 'setup');
ck('a programme is started',        $newId > 0);
ck('and the url says step 2',       (int) arg($startedAt, 'step'), 2);
ck('the programme agrees',          (int) CardsCo_Programme::get($newId)['setup_step'], 2);
/* The number in the url means nothing on its own: the screen is whatever
 * step_on_screen() allows, and that is what used to drag it back. */
ck('and step 2 is what renders',
   CardsCo_Setup::step_on_screen(CardsCo_Programme::get($newId), 2), 2);
ck('step 3 is still out of reach',
   CardsCo_Setup::step_on_screen(CardsCo_Programme::get($newId), 3), 2);

/* Picking the same occasion again resumes rather than duplicating, and must
 * not drop you back on step 1 either. */
$again = submit(['cardsco_setup_action'=>'start','category'=>'christmas-seasonal']);
ck('picking it again resumes the same draft', (int) arg($again,'setup'), $newId);
ck('and does not send you back to step 1',    (int) arg($again,'step') >= 2);

$wpdb->query($wpdb->prepare('DELETE FROM '.cardsco_table('programmes').' WHERE id = %d', $newId));

sect('everything redirects, so a refresh cannot save twice');
$paths = [
  ['cardsco_setup_action'=>'start','category'=>'employee-birthdays'],
  ['cardsco_setup_action'=>'step','step'=>3,'programme_id'=>$prog,'card_id'=>$card],
  ['cardsco_setup_action'=>'activate','programme_id'=>$prog],
];
/* A redirect, not "no redirect" and not a throw. It used to be enough to
 * look for "://", because go() built every destination from home_url() —
 * which was the bug: it sent people to the home page after every save. The
 * destination is now the wizard page itself, taken from the current request,
 * and that is a path rather than an absolute URL. wp_safe_redirect() accepts
 * either. */
$redirected = 0;
$dests      = [];
foreach ($paths as $p) {
  $url = (string) submit($p);
  $dests[] = $url;
  if (str_contains($url,'://') || str_starts_with($url,'/')) $redirected++;
}
ck('each of the three actions redirects', $redirected, 3);
ck('and none of them to the home page',
   count(array_filter($dests, fn($u) => rtrim((string)parse_url($u,PHP_URL_PATH),'/') === rtrim((string)parse_url(home_url('/'),PHP_URL_PATH),'/'))), 0);


sect('an unconfirmed address cannot turn a programme on');
/* Everything up to here is open to somebody who has not confirmed. This is
 * the point where real cards start going to real addresses, so it is not. */
$fresh = CardsCo_Programme::create($cid,'employee-birthdays');
CardsCo_Programme::add_recipients($fresh,$people);
CardsCo_Programme::update($fresh,['card_id'=>$card,'message_override'=>'Happy birthday {first name}',
  'shipping_mode'=>'company','ship_address'=>"Unit 4\nLeeds",'ship_postcode'=>'LS1 4AP',
  'timing_mode'=>'monthly_box','box_arrive_day'=>25,'starts_on'=>'2026-12-01','setup_step'=>7]);
ck('it is otherwise ready', CardsCo_Programme::can_activate(CardsCo_Programme::get($fresh)), true);

delete_user_meta($uid, CardsCo_Verify::DONE);
$url = submit(['cardsco_setup_action'=>'activate','programme_id'=>$fresh]);
ck('activation is refused',      (string) arg($url,'cardsco_error') !== '');
ck('and asks them to confirm',   str_contains((string) arg($url,'cardsco_error'), 'confirm your email'));
ck('it stays a draft',           CardsCo_Programme::get($fresh)['status'], 'draft');

update_user_meta($uid, CardsCo_Verify::DONE, cardsco_now());
ck('and goes on once confirmed',
  (int) arg(submit(['cardsco_setup_action'=>'activate','programme_id'=>$fresh]),'done'), 1);
ck('now it is active', CardsCo_Programme::get($fresh)['status'], 'active');

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
