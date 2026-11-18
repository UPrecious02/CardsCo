<?php
/** Campaigns: one send on a chosen day, the lock, and the offer code. */
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/wp-admin/admin.php'; $_SERVER['REQUEST_METHOD']='GET';
define('WP_ADMIN', true);
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
wp_set_current_user(1); set_current_screen('toplevel_page_cardsco');
global $wpdb;
$fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-50s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install(true); CardsCo_Category::backfill();

$cid = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Campaign Test Co'));
if(!$cid){ $c=CardsCo_Company::create(['name'=>'Campaign Test Co']); $cid=is_wp_error($c)?0:(int)$c; }
foreach(['occasions','programme_recipients','programmes','recipients','card_queue','boxes','campaigns','recipient_segments','segments'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('clean company', $cid>0);

$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Spring Postcard','post_status'=>'publish'],true);
$draftcard = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Not Ready','post_status'=>'draft'],true);
$type = CardsCo_Occasion_Type::get_by_slug('christmas');

// five customers; one has no address, one is archived
$people = [];
foreach ([['Ada','Nwosu',1],['Ben','Clarke',1],['Cara','Diaz',1],['Dan','Ellis',0],['Eve','Frost',1]] as $i=>$p) {
  list($first,$last,$hasaddr) = $p;
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'customer','first_name'=>$first,'last_name'=>$last,
    'recipient_company'=>'Northgate','address'=>$hasaddr ? (10+$i)." High Street\nLeeds" : '',
    'postcode'=>$hasaddr ? 'LS1 2AB' : '']);
  if (!is_wp_error($rid)) $people[] = $rid;
}
ck('five people', count($people), 5);
CardsCo_Recipient::update($people[4], ['status'=>'archived']);

$seg = CardsCo_Segment::create(['company_id'=>$cid,'name'=>'Top customers','applies_to'=>'customer']);
ck('segment made', is_int($seg) && $seg>0);
CardsCo_Segment::add_recipients($seg, $people);

sect('starting one');
ck('a company is required', is_wp_error(CardsCo_Campaign::create(0)));
$camp = CardsCo_Campaign::create($cid, 'Spring offer');
ck('created', is_int($camp) && $camp>0);
$c = CardsCo_Campaign::get($camp);
ck('starts as a draft', $c['status'], 'draft');
ck('on step 1', (int)$c['builder_step'], 1);
ck('named', $c['name'], 'Spring offer');
$unnamed = CardsCo_Campaign::create($cid);
ck('an unnamed one still gets a name', CardsCo_Campaign::get($unnamed)['name'], 'Untitled campaign');

sect('the audience comes from segments and hand-picked people');
CardsCo_Campaign::update($camp, ['audience'=>['segments'=>[$seg]]]);
$c = CardsCo_Campaign::get($camp);
ck('archived person is not counted', (int)$c['recipient_count'], 4);
CardsCo_Campaign::update($camp, ['audience'=>['segments'=>[$seg],'exclude'=>[$people[1]]]]);
$c = CardsCo_Campaign::get($camp);
ck('an exclusion takes effect', (int)$c['recipient_count'], 3);
ck('and is counted separately', (int)$c['excluded_count'], 1);
$aud = CardsCo_Campaign::audience($c);
ck('the excluded person is really out', !in_array($people[1], $aud['ids'], true));

// another company's segment must not widen this audience
$other = CardsCo_Company::create(['name'=>'Someone Else Ltd '.wp_rand(1000,9999)]);
$oseg  = CardsCo_Segment::create(['company_id'=>(int)$other,'name'=>'Theirs','applies_to'=>'customer']);
$orid  = CardsCo_Recipient::create(['company_id'=>(int)$other,'type'=>'customer','first_name'=>'Not','last_name'=>'Ours',
  'address'=>"1 Elsewhere\nYork",'postcode'=>'YO1 1AA']);
CardsCo_Segment::add_recipients($oseg,[$orid]);
CardsCo_Campaign::update($camp, ['audience'=>['segments'=>[$seg,$oseg],'exclude'=>[$people[1]]]]);
$c = CardsCo_Campaign::get($camp);
ck("another company's segment is ignored", (int)$c['recipient_count'], 3);

sect('steps block until they are satisfied');
ck('step 1 not done without an occasion', CardsCo_Campaign::step_complete($c,1), false);
CardsCo_Campaign::update($camp, ['occasion_type_id'=>$type['id']]);
$c = CardsCo_Campaign::get($camp);
ck('step 1 done', CardsCo_Campaign::step_complete($c,1), true);
ck('step 2 done, people chosen', CardsCo_Campaign::step_complete($c,2), true);
CardsCo_Campaign::update($camp, ['card_id'=>$draftcard]);
$c = CardsCo_Campaign::get($camp);
ck('an unpublished card does not count', CardsCo_Campaign::step_complete($c,3), false);
CardsCo_Campaign::update($camp, ['card_id'=>$card]);
$c = CardsCo_Campaign::get($camp);
ck('step 3 done', CardsCo_Campaign::step_complete($c,3), true);
ck('step 4 not done without wording', CardsCo_Campaign::step_complete($c,4), false);
CardsCo_Campaign::update($camp, ['message_body'=>'Hello {first name}, use {offer code} before {offer expires}.']);
$c = CardsCo_Campaign::get($camp);
ck('step 4 done', CardsCo_Campaign::step_complete($c,4), true);
ck('step 5 done with no offer at all', CardsCo_Campaign::step_complete($c,5), true);
CardsCo_Campaign::update($camp, ['offer_enabled'=>1]);
$c = CardsCo_Campaign::get($camp);
ck('an offer with no code is not done', CardsCo_Campaign::step_complete($c,5), false);
CardsCo_Campaign::update($camp, ['offer_enabled'=>1,'offer_code'=>'SPRING20','offer_expires'=>'2027-03-31']);
$c = CardsCo_Campaign::get($camp);
ck('step 5 done', CardsCo_Campaign::step_complete($c,5), true);
ck('step 6 not done without a date', CardsCo_Campaign::step_complete($c,6), false);

sect('the offer reaches the wording');
$pv = CardsCo_Campaign::preview($c, CardsCo_Recipient::get($people[0]));
ck('no gaps', $pv['problem'], '');
ck('name merged', str_contains($pv['text'],'Ada'));
ck('code merged', str_contains($pv['text'],'SPRING20'));
ck('expiry merged as a date', str_contains($pv['text'],'31 March'));
ck('nothing left in braces', !str_contains($pv['text'],'{'));

sect('wording that would leave a gap is caught');
CardsCo_Campaign::update($camp, ['offer_enabled'=>0]);
$c = CardsCo_Campaign::get($camp);
$pv = CardsCo_Campaign::preview($c, CardsCo_Recipient::get($people[0]));
ck('switching the offer off makes the code a gap', str_contains($pv['problem'],'offer code'));
ck('and scheduling is refused for it', is_wp_error(CardsCo_Campaign::can_schedule($c)));
CardsCo_Campaign::update($camp, ['offer_enabled'=>1,'offer_code'=>'SPRING20','offer_expires'=>'2027-03-31']);
CardsCo_Campaign::update($camp, ['message_body'=>'Hello {first name}, here is {a made up thing}.']);
$c = CardsCo_Campaign::get($camp);
ck('an unknown field is caught', str_contains(CardsCo_Campaign::preview($c)['problem'],'does not recognise'));
CardsCo_Campaign::update($camp, ['message_body'=>'Hello {first name}, use {offer code} before {offer expires}.']);

sect('dates, and the lock that follows from them');
$today = cardsco_now('date');
ck('lock is three days before the send', CardsCo_Campaign::lock_date('2027-03-20',$cid), '2027-03-17');
CardsCo_Campaign::update($camp, ['send_date'=>'2027-03-20']);
$c = CardsCo_Campaign::get($camp);
ck('send date saved', $c['send_date'], '2027-03-20');
ck('lock date derived automatically', $c['lock_date'], '2027-03-17');
ck('step 6 done', CardsCo_Campaign::step_complete($c,6), true);
CardsCo_Campaign::update($camp, ['send_date'=>'2020-01-01']);
ck('a date in the past is refused', is_wp_error(CardsCo_Campaign::can_schedule(CardsCo_Campaign::get($camp))));
CardsCo_Campaign::update($camp, ['send_date'=>$today]);
ck('today is too soon to write them', is_wp_error(CardsCo_Campaign::can_schedule(CardsCo_Campaign::get($camp))));
CardsCo_Campaign::update($camp, ['send_date'=>'2027-03-20']);

sect('scheduling it');
$ok = CardsCo_Campaign::schedule($camp);
ck('scheduled', $ok, true);
$c = CardsCo_Campaign::get($camp);
ck('status moved', $c['status'], 'scheduled');
ck('who approved it is recorded', (int)$c['approved_by'] > 0);
ck('priced per card', (float)$c['unit_price'] > 0);
ck('total is the count times the price',
   (float)$c['total_amount'], round(3 * (float)$c['unit_price'], 2));
ck('cannot be scheduled twice', is_wp_error(CardsCo_Campaign::schedule($camp)));

sect('nothing happens before the lock date');
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-10']);
ck('looked at it', $run['looked_at'] >= 1);
ck('but queued nothing', $run['queued'], 0);
ck('no cards exist yet', count(CardsCo_Campaign::cards($camp)), 0);
ck('still only scheduled', CardsCo_Campaign::get($camp)['status'], 'scheduled');

sect('the lock date arrives');
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-17']);
ck('two queued', $run['queued'], 2);
ck('one held', $run['held'], 1);
ck('because of a missing address', ($run['reasons']['no_address'] ?? 0), 1);
ck('it locked', $run['locked'], 1);
$c = CardsCo_Campaign::get($camp);
ck('status is locked', $c['status'], 'locked');
$cards = CardsCo_Campaign::cards($camp);
ck('three cards in the queue', count($cards), 3);
ck('all tagged to the campaign', count(array_filter($cards,fn($x)=>(int)$x['campaign_id']===$camp)), 3);
ck('source says campaign', $cards[0]['source'], 'campaign');
ck('the offer code is on the card', $cards[0]['offer_code'], 'SPRING20');
ck('event date is the send date', $cards[0]['event_date'], '2027-03-20');
ck('write by the lock date', $cards[0]['write_by'], '2027-03-17');

sect('a locked campaign cannot be edited');
$edit = CardsCo_Campaign::update($camp, ['message_body'=>'Changed my mind']);
ck('editing refused', is_wp_error($edit));
ck('and it says why', str_contains($edit->get_error_message(),'with the writers'));
ck('wording unchanged', str_contains(CardsCo_Campaign::get($camp)['message_body'],'use {offer code}'));

sect('running again does not send twice');
$before = count(CardsCo_Campaign::cards($camp));
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-17']);
ck('no new cards', count(CardsCo_Campaign::cards($camp)), $before);
ck('reported as already done', $run['already'], 3);

sect('somebody added late still gets one');
$late = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'customer','first_name'=>'Femi','last_name'=>'Okon',
  'address'=>"99 New Road\nLeeds",'postcode'=>'LS9 9ZZ']);
CardsCo_Segment::add_recipients($seg,[$late]);
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-18']);
ck('one more queued', $run['queued'], 1);
ck('four cards now', count(CardsCo_Campaign::cards($camp)), 4);
ck('and the others were left alone', $run['already'], 3);

sect('the send day passes');
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-20']);
ck('marked sent', $run['sent'], 1);
ck('status is sent', CardsCo_Campaign::get($camp)['status'], 'sent');
$run = CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-03-25']);
ck('a sent campaign is not picked up again', $run['looked_at'], 0);

sect('cancelling');
$c2 = CardsCo_Campaign::create($cid,'To be cancelled');
CardsCo_Campaign::update($c2, ['occasion_type_id'=>$type['id'],'card_id'=>$card,
  'message_body'=>'Hello {first name}','audience'=>['segments'=>[$seg]],'send_date'=>'2027-06-10']);
ck('scheduled fine', CardsCo_Campaign::schedule($c2), true);
CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-06-07']);
$queued = count(CardsCo_Campaign::cards($c2));
ck('it queued some cards', $queued > 0);
ck('cancelled', CardsCo_Campaign::cancel($c2), true);
ck('status cancelled', CardsCo_Campaign::get($c2)['status'], 'cancelled');
ck('its unwritten cards were pulled back',
   count(array_filter(CardsCo_Campaign::cards($c2),fn($x)=>$x['status']==='cancelled')), $queued);
ck('a sent campaign cannot be cancelled', is_wp_error(CardsCo_Campaign::cancel($camp)));

sect('offer fields are kept out of occasion wording');
$plain = CardsCo_Merge::fields_for(false);
$all   = CardsCo_Merge::fields_for(true);
ck('occasions are not offered the offer code', array_key_exists('offer_code',$plain), false);
ck('nor the expiry', array_key_exists('offer_expires',$plain), false);
ck('campaigns are offered both', array_key_exists('offer_code',$all) && array_key_exists('offer_expires',$all));
ck('everything else is in both', count($all) - count($plain), 2);

printf("\n%s\n", $fail ? "$fail FAILED" : 'all passed');
exit($fail?1:0);
