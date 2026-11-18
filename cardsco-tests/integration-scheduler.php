<?php
/**
 * The daily scan and the card queue.
 *
 * Dates here are set relative to today rather than written in, because two
 * tests in an earlier version of this gate were dated 1 November and started
 * failing on the 6th. Anything that must be a fixed date is computed from the
 * event date in the assertion itself.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-52s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();
wp_set_current_user(1);

$today = cardsco_now('date');
/* Far enough out that the write-by date cannot land in the past whatever the
 * company's lead days are, and inside any sane horizon. */
$in    = fn($days) => gmdate('Y-m-d', strtotime($today.' +'.$days.' days'));

$cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Scheduler Test Co'));
if (!$cid) { $c = CardsCo_Company::create(['name'=>'Scheduler Test Co']); $cid = is_wp_error($c)?0:(int)$c; }
foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes','activity_log'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('a clean company', $cid > 0);

$bday   = CardsCo_Occasion_Type::get_by_slug('birthday');
$card   = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Balloons','post_status'=>'publish'],true);
CardsCo_Card::set_occasions($card,[(int)$bday['id']]);
/* A message's words live in _cardsco_text, not post_content, and the class
 * has no set_occasions of its own — the meta key is shared with cards, which
 * is how Message::seed() gets away with calling Card::set_occasions. */
function message($title,$text,$type_id) {
  $id = wp_insert_post(['post_type'=>CardsCo_Message::POST_TYPE,'post_title'=>$title,'post_status'=>'publish'],true);
  if (is_wp_error($id)) return 0;
  update_post_meta($id, CardsCo_Message::TEXT, $text);
  update_post_meta($id, CardsCo_Message::COMPANY, 0);
  add_post_meta($id, CardsCo_Message::OCCASION, (int)$type_id);
  return (int) $id;
}
$msg   = message('Warm one','Happy birthday {first name}!',$bday['id']);
$gappy = message('Needs a company','Thanks from all of us at {company}, {first name}.',$bday['id']);
wp_cache_flush();
ck('a card and two messages', $card>0 && $msg>0 && $gappy>0);
ck('the message text reads back', CardsCo_Message::text($msg), 'Happy birthday {first name}!');
ck('and its occasion reads back', CardsCo_Message::occasions($msg), [(int)$bday['id']]);

/* A birthday a fortnight out, for somebody with a full address. */
function person($cid,$f,$l,$addr,$pc,$type_id,$date) {
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$f,'last_name'=>$l,
    'address'=>$addr,'postcode'=>$pc]);
  if (is_wp_error($rid)) return [0,0];
  $oid = CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$type_id,
    'event_month'=>(int)gmdate('n',strtotime($date)),'event_day'=>(int)gmdate('j',strtotime($date))]);
  return [(int)$rid, is_wp_error($oid)?0:(int)$oid];
}

list($ada,$ada_o) = person($cid,'Ada','Nwosu',"3 Mill Lane\nLeeds",'LS6 3AA',$bday['id'],$in(14));
list($ben,$ben_o) = person($cid,'Ben','Clarke','','',$bday['id'],$in(16));
list($cara,$cara_o)= person($cid,'Cara','Diaz',"9 Kirkgate\nLeeds",'LS1 6BY',$bday['id'],$in(18));
ck('three people with birthdays coming up', $ada_o && $ben_o && $cara_o);

/* ------------------------------------------------------- the statuses */

sect('the statuses a card can be in');
$st = CardsCo_Queue::statuses();
ck('eight of them', count($st), 8);
ck('in the order fulfilment walks', array_keys($st),
  ['queued','on_hold','writing','written','posted','delivered','cancelled','failed']);
ck('every one has a label', count(array_filter($st)), 8);
$hr = CardsCo_Queue::hold_reasons();
ck('five reasons to hold a card', count($hr), 5);
ck('the two the scan uses are there',
  isset($hr['no_address']) && isset($hr['message_gaps']));

/* ------------------------------------------------ what the scan looks at */

sect('what the scan can see');
$up = CardsCo_Occasion::upcoming(['company_id'=>$cid,'days'=>30]);
ck('all three are in the next 30 days', count($up), 3);
/* This column went missing from the SELECT once and every screen that
 * grouped by occasion type quietly emptied. */
ck('each row carries its occasion type', (int)($up[0]['occasion_type_id'] ?? 0), (int)$bday['id']);
ck('and the type label it was joined for', ($up[0]['label'] ?? '') !== '');
ck('and the recipient name', ($up[0]['first_name'] ?? '') !== '');
ck('soonest first', $up[0]['next_occurrence'] <= $up[1]['next_occurrence']);
ck('a tighter window sees fewer', count(CardsCo_Occasion::upcoming(['company_id'=>$cid,'days'=>15])), 1);
ck('another company sees none', CardsCo_Occasion::upcoming(['company_id'=>999999,'days'=>30]), []);

/* --------------------------------------------- nothing chosen, nothing sent */

sect('with no standing choice of card');
CardsCo_Scheduler::set_defaults($cid,$bday['id'],0,0);
$r = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('it looked at all three', $r['looked_at'], 3);
ck('and queued none',        $r['queued'], 0);
ck('all three want setting up', $r['unconfigured'], 3);
ck('and it says which occasion', count($r['needs_setup']), 1);
ck('with a count',           (int)$r['needs_setup'][0]['count'], 3);
ck('and the type',           (int)$r['needs_setup'][0]['type_id'], (int)$bday['id']);
ck('nothing was written',    (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)), 0);
ck('needs_setup() reports the same', count(CardsCo_Scheduler::needs_setup($cid,30)), 1);

sect('a draft card is no choice at all');
$unpublished = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Draft art','post_status'=>'draft'],true);
CardsCo_Scheduler::set_defaults($cid,$bday['id'],$unpublished,$msg);
ck('still unconfigured', CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30,'dry_run'=>true])['unconfigured'], 3);

/* ----------------------------------------------------------- a real scan */

sect('a dry run decides but writes nothing');
CardsCo_Scheduler::set_defaults($cid,$bday['id'],$card,$msg);
$dry = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30,'dry_run'=>true]);
ck('two would be queued', $dry['queued'], 2);
ck('one would be held',   $dry['held'], 1);
ck('but the queue is still empty',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)), 0);

sect('the real scan');
$r = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('two queued',  $r['queued'], 2);
ck('one held',    $r['held'], 1);
ck('none skipped',$r['skipped'], 0);
ck('three rows written',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)), 3);
ck('the horizon is reported back', $r['horizon'], 30);
ck('and when it ran', (bool)$r['ran_at']);
ck('it was remembered', (bool)CardsCo_Scheduler::last_run());

$held = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$ben), ARRAY_A);
ck('Ben, with no address, is the one held', $held['status'], 'on_hold');
ck('and the reason says so',                $held['hold_reason'], 'no_address');

$queued = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$ada), ARRAY_A);
ck('Ada is queued',             $queued['status'], 'queued');
ck('with the chosen card',      (int)$queued['card_id'], $card);
ck('its title kept alongside',  $queued['card_title'], 'Balloons');
ck('the occasion named',        $queued['occasion_label'] !== '');
ck('her address snapshotted',   str_contains($queued['address_snapshot'],'Mill Lane'));
ck('the merge field filled in', str_contains($queued['message_final'],'Ada'));
ck('and no braces left behind', !str_contains($queued['message_final'],'{'));
ck('a price taken from the card', $queued['unit_price'] !== null);

sect('the dates it works out');
$lead    = CardsCo_Scheduler::lead_days($up[0],$cid);
$postage = (int) CardsCo_Settings::get('postage_days',null,$cid);
ck('write-by is the lead days before the event',
  $queued['write_by'], gmdate('Y-m-d', strtotime($queued['event_date'].' -'.$lead.' days')));
ck('post-by is the postage days before it',
  $queued['post_by'], gmdate('Y-m-d', strtotime($queued['event_date'].' -'.$postage.' days')));
ck('and both are before the event', $queued['write_by'] < $queued['event_date']);

sect('which lead time wins');
ck('the occasion beats everything',
  CardsCo_Scheduler::lead_days(['lead_days'=>9,'type_lead_days'=>5],$cid), 9);
ck('the type beats the company default',
  CardsCo_Scheduler::lead_days(['lead_days'=>0,'type_lead_days'=>5],$cid), 5);
ck('and the company default is the floor',
  CardsCo_Scheduler::lead_days(['lead_days'=>0,'type_lead_days'=>0],$cid),
  (int) CardsCo_Settings::get('default_lead_days',null,$cid));

sect('running it twice in a morning');
$again = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('nothing new is queued', $again['queued'], 0);
ck('nothing new is held',   $again['held'], 0);
ck('all three are already done', $again['already_done'], 3);
ck('and the queue has not grown',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)), 3);

sect('a message with a gap is held, not sent half-written');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cardsco_occasions SET last_queued_for=NULL WHERE company_id=%d",$cid));
CardsCo_Scheduler::set_defaults($cid,$bday['id'],$card,$gappy);
$r = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('all three are held', $r['held'], 3);
$reasons = $wpdb->get_col($wpdb->prepare(
  "SELECT DISTINCT hold_reason FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id<>%d",$cid,$ben));
ck('the two with addresses are held for the gap', $reasons, ['message_gaps']);

sect('somebody in a running programme is left to it');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cardsco_occasions SET last_queued_for=NULL WHERE company_id=%d",$cid));
CardsCo_Scheduler::set_defaults($cid,$bday['id'],$card,$msg);
$pid = CardsCo_Programme::create($cid,'employee-birthdays');
CardsCo_Programme::add_recipients($pid,[$ada]);
CardsCo_Programme::update($pid,['card_id'=>$card,'message_override'=>'Happy birthday {first name}',
  'shipping_mode'=>'company','ship_address'=>"Unit 4\nLeeds",'ship_postcode'=>'LS1 4AP',
  'timing_mode'=>'monthly_box','box_arrive_day'=>25,'starts_on'=>$today,'setup_step'=>7]);
ck('it goes on', CardsCo_Programme::activate($pid), true);
$r = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('Ada is skipped', $r['skipped'], 1);
ck('and the other two are still dealt with', $r['queued'] + $r['held'], 2);
ck('no second card for Ada',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$ada)), 0);
CardsCo_Programme::update($pid,['status'=>'draft']);

sect('somebody who has left');
/* The two statuses a recipient can be in are active and archived — update()
 * refuses anything else and falls back to active, so archive() is the path. */
ck('they are archived', CardsCo_Recipient::archive($cara), true);
ck('and the row says so', CardsCo_Recipient::get($cara)['status'], 'archived');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cardsco_occasions SET last_queued_for=NULL WHERE company_id=%d",$cid));
wp_cache_flush();
$r = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
ck('an archived person is not looked at at all', $r['looked_at'], 2);
ck('and gets no card',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$cara)), 0);
ck('a made-up status is refused and read as active',
  CardsCo_Recipient::update($cara,['status'=>'on-the-moon']) && CardsCo_Recipient::get($cara)['status'] === 'active');

/* ------------------------------------------------------- adding by hand */

sect('adding a card to the queue directly');
ck('without a company it is refused', is_wp_error(CardsCo_Queue::add(['recipient_name'=>'X'])), true);
$one = CardsCo_Queue::add(['company_id'=>$cid,'source'=>'manual','recipient_id'=>$ada,
  'recipient_name'=>'Ada Nwosu','occasion_id'=>$ada_o,'event_date'=>$in(90),
  'card_id'=>$card,'card_title'=>'Balloons','message_final'=>'Hello','country'=>'gbr']);
ck('a good one is added', is_int($one) && $one > 0);
$row = CardsCo_Queue::get($one);
ck('the country is two uppercase letters', $row['country'], 'GB');
ck('the source was kept',                  $row['source'], 'manual');
ck('and it defaults to queued',            $row['status'], 'queued');

ck('a second one for the same date is refused',
  CardsCo_Queue::add(['company_id'=>$cid,'occasion_id'=>$ada_o,'event_date'=>$in(90),'recipient_name'=>'Ada again']), 0);
ck('exists_for agrees', CardsCo_Queue::exists_for($ada_o,$in(90)), true);
ck('but not for another date', CardsCo_Queue::exists_for($ada_o,$in(91)), false);
$bad = CardsCo_Queue::add(['company_id'=>$cid,'source'=>'telepathy','status'=>'teleported',
  'recipient_name'=>'Odd','event_date'=>$in(92)]);
ck('an unknown source falls back to occasion', CardsCo_Queue::get($bad)['source'], 'occasion');
ck('an unknown status falls back to queued',   CardsCo_Queue::get($bad)['status'], 'queued');

/* ------------------------------------------------------------- statuses */

sect('moving a card along');
ck('a card that is not there is refused', is_wp_error(CardsCo_Queue::set_status(999999,'posted')), true);
ck('a status we do not have is refused',  is_wp_error(CardsCo_Queue::set_status($one,'sent-ish')), true);
ck('writing',   CardsCo_Queue::set_status($one,'writing'), true);
ck('written',   CardsCo_Queue::set_status($one,'written'), true);
ck('posted',    CardsCo_Queue::set_status($one,'posted'), true);
$row = CardsCo_Queue::get($one);
ck('and posting is timestamped', (bool)$row['posted_at']);
$stamp = $row['posted_at'];
CardsCo_Queue::set_status($one,'posted');
ck('the timestamp is not overwritten', CardsCo_Queue::get($one)['posted_at'], $stamp);
CardsCo_Queue::set_status($one,'delivered');
ck('delivery is timestamped too', (bool)CardsCo_Queue::get($one)['delivered_at']);

sect('a hold reason does not follow a card around');
CardsCo_Queue::set_status($one,'on_hold',['hold_reason'=>'no_address']);
ck('it is held for a reason', CardsCo_Queue::get($one)['hold_reason'], 'no_address');
CardsCo_Queue::set_status($one,'on_hold');
ck('held with no reason given is needs_attention', CardsCo_Queue::get($one)['hold_reason'], 'needs_attention');
CardsCo_Queue::set_status($one,'queued');
ck('and the reason is cleared when it leaves hold', CardsCo_Queue::get($one)['hold_reason'], '');

/* --------------------------------------------------------------- recheck */

sect('filling in an address releases the card');
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cardsco_occasions SET last_queued_for=NULL WHERE company_id=%d",$cid));
CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>30]);
$bens = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$ben));
ck("Ben's card is held", CardsCo_Queue::get($bens)['status'], 'on_hold');
ck('and re-checking before the address is filled in changes nothing',
  CardsCo_Queue::recheck($bens), false);
CardsCo_Recipient::update($ben,['address'=>"12 Briggate\nLeeds",'postcode'=>'LS1 6HD']);
ck('once it is filled in, the card is released', CardsCo_Queue::recheck($bens), true);
$row = CardsCo_Queue::get($bens);
ck('it is queued again',          $row['status'], 'queued');
ck('the reason is gone',          $row['hold_reason'], '');
ck('and the new address was taken', str_contains($row['address_snapshot'],'Briggate'));
ck('re-checking a card that is not held does nothing', CardsCo_Queue::recheck($bens), false);

sect('a card held for something else is not released');
$gap = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d AND recipient_id=%d",$cid,$ada));
if ($gap) { CardsCo_Queue::set_status($gap,'on_hold',['hold_reason'=>'message_gaps']); }
ck('a message gap is not an address', $gap ? CardsCo_Queue::recheck($gap) : false, false);
ck('and it stays held', $gap ? CardsCo_Queue::get($gap)['status'] : 'on_hold', 'on_hold');

sect('the counts the screens read');
$counts = CardsCo_Queue::counts($cid);
ck('every status has a count, even at zero',
  array_diff(array_keys(CardsCo_Queue::statuses()), array_keys($counts)), []);
/* Two derived buckets the screens read straight off: anything past its
 * write-by that has not been written, and everything still in play. */
ck('plus an overdue bucket', isset($counts['overdue']));
ck('plus an open bucket',    isset($counts['open']));
ck('open is the four live statuses added up',
  $counts['open'], $counts['queued']+$counts['on_hold']+$counts['writing']+$counts['written']);
ck('and nothing else is in there',
  array_diff(array_keys($counts), array_merge(array_keys(CardsCo_Queue::statuses()),['overdue','open'])), []);
ck('every value is a number', count(array_filter($counts,'is_int')), count($counts));

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
