<?php
/** Boxes: batching a month of cards, the weekend rule, and the two rhythms. */
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/wp-admin/admin.php'; $_SERVER['REQUEST_METHOD']='GET';
define('WP_ADMIN', true);
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
wp_set_current_user(1); set_current_screen('toplevel_page_cardsco');
global $wpdb;
$fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-48s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();

sect('the weekend rule, on its own');
// 2026-10-25 is a Sunday, 2026-10-24 a Saturday, 2026-10-23 a Friday.
ck('sunday pulled back to friday', CardsCo_Box::working_day('2026-10-25'), '2026-10-23');
ck('saturday pulled back to friday', CardsCo_Box::working_day('2026-10-24'), '2026-10-23');
ck('friday left alone', CardsCo_Box::working_day('2026-10-23'), '2026-10-23');
ck('monday left alone', CardsCo_Box::working_day('2026-10-26'), '2026-10-26');
ck('never moves forward', strtotime(CardsCo_Box::working_day('2026-10-25')) < strtotime('2026-10-25'));
ck('rule off leaves the sunday', CardsCo_Box::working_day('2026-10-25', false), '2026-10-25');

sect('turning a period and a day into a date');
ck('plain date', CardsCo_Box::event_date('2026-11', 14), '2026-11-14');
ck('29 feb in a leap year stays', CardsCo_Box::event_date('2028-02', 29), '2028-02-29');
ck('29 feb otherwise falls back to the 28th', CardsCo_Box::event_date('2027-02', 29), '2027-02-28');
ck('31st of a 30 day month clamps', CardsCo_Box::event_date('2026-11', 31), '2026-11-30');
ck('rubbish period refused', CardsCo_Box::event_date('nonsense', 14), null);

// ---------------------------------------------------------------------------
// This suite owns its own company and wipes it, so run order cannot matter.
$cid = (int) $wpdb->get_var( $wpdb->prepare(
  "SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name = %s", 'Box Test Co' ) );
if ( ! $cid ) {
  $created = CardsCo_Company::create( array( 'name' => 'Box Test Co' ) );
  $cid     = is_wp_error( $created ) ? 0 : (int) $created;
}
foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes'] as $t) {
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cardsco_{$t} WHERE company_id = %d", $cid ) );
}
wp_cache_flush();

$bday = CardsCo_Category::primary_type('employee-birthdays');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Flat Illustration','post_status'=>'publish'],true);
$msg  = wp_insert_post(['post_type'=>'cardsco_message','post_title'=>'Birthday','post_status'=>'publish'],true);

function make_people($cid, $bday, array $spec) {
  $ids = [];
  foreach ($spec as $i => $person) {
    list($first,$last,$m,$d,$addr) = $person;
    $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee',
      'first_name'=>$first,'last_name'=>$last,
      'address'=>$addr ? $addr : '', 'postcode'=>$addr ? 'LS2 7EH' : '']);
    if (is_wp_error($rid)) continue;
    $ids[] = $rid;
    if ($m) CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],
      'event_month'=>$m,'event_day'=>$d]);
  }
  return $ids;
}

sect('a monthly box programme');
$pid = CardsCo_Programme::create($cid,'employee-birthdays');
$ids = make_people($cid, $bday, [
  ['Tunde','Bakare',11,14,"20 Test Road\nLeeds"],
  ['Hannah','Lee',11,3,"21 Test Road\nLeeds"],
  ['Priya','Shah',11,28,''],               // no address of her own: fine for a box
  ['Marcus','Reid',12,16,"22 Test Road\nLeeds"],
  ['Aisha','Rahman',0,0,"23 Test Road\nLeeds"], // no birthday at all
]);
ck('five people created', count($ids), 5);
CardsCo_Programme::add_recipients($pid,$ids);
CardsCo_Programme::update($pid, [
  'card_id'=>$card, 'message_override'=>'Happy birthday {first name}!',
  'handwriting'=>'Arthur','ink'=>'blue','signature'=>'Everyone at Acme',
  'shipping_mode'=>'company','ship_address'=>"Acme House\n1 Office Way\nLeeds",
  'ship_postcode'=>'LS1 4AP','timing_mode'=>'monthly_box','box_arrive_day'=>25,
  'weekend_rule'=>1,'starts_on'=>'2026-11-01','setup_step'=>7,
]);
$p = CardsCo_Programme::get($pid);
ck('activates cleanly', CardsCo_Programme::activate($pid), true);
$p = CardsCo_Programme::get($pid);
ck('now active', $p['status'], 'active');

sect('dates the box has to hit');
// November's box arrives by the 25th of October, which in 2026 is a Sunday.
ck('arrive-by pulled off the sunday', CardsCo_Box::arrive_by($p,'2026-11'), '2026-10-23');
ck('write-by is seven days earlier', CardsCo_Box::write_by($p,'2026-10-23'), '2026-10-16');
$p2 = $p; $p2['weekend_rule'] = 0;
ck('rule off keeps the sunday', CardsCo_Box::arrive_by($p2,'2026-11'), '2026-10-25');

sect('a box planned ahead, but not filled yet');
$run = CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-01']);
$box = CardsCo_Box::find($pid,'2026-11');
ck('november box exists', is_array($box));
ck('it is only planned', $box['status'], 'planned');
ck('arrive-by stored', $box['arrive_by'], '2026-10-23');
ck('write-by stored', $box['write_by'], '2026-10-16');
ck('nothing written into it yet', (int)$box['card_count'], 0);
ck('ships to the office address', trim($box['ship_postcode']), 'LS1 4AP');
ck('no cards queued this early', (int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid)), 0);

sect('writing week arrives');
$run = CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
$box = CardsCo_Box::find($pid,'2026-11');
ck('box moved to writing', $box['status'], 'writing');
ck('three november cards queued', (int)$box['card_count'], 3);
$cards = CardsCo_Box::cards((int)$box['id']);
ck('all three carry the box', count($cards), 3);
ck('in date order', array_map(fn($c)=>$c['event_date'],$cards),
   ['2026-11-03','2026-11-14','2026-11-28']);
ck('tagged with the programme', (int)$cards[0]['programme_id'], $pid);
ck('december person left out', !in_array('2026-12-16',array_map(fn($c)=>$c['event_date'],$cards),true));
ck('person with no birthday left out', count($cards), 3);

sect('what the box says and where it goes');
ck('every card shares the box deadline', array_unique(array_map(fn($c)=>$c['write_by'],$cards)), ['2026-10-16']);
ck('every card posts by the arrive-by', array_unique(array_map(fn($c)=>$c['post_by'],$cards)), ['2026-10-23']);
ck('all addressed to the office', array_unique(array_map(fn($c)=>trim($c['postcode']),$cards)), ['LS1 4AP']);
$priya = null; foreach($cards as $c) if (str_contains($c['recipient_name'],'Priya')) $priya = $c;
ck('the person with no address of her own is still queued', is_array($priya));
ck('and is not held', $priya['status'] ?? '', 'queued');
ck('her name is merged in', str_contains($priya['message_final'] ?? '', 'Priya'));
ck('no merge field left showing', !str_contains($priya['message_final'] ?? '', '{'));
ck('box total adds up', (float)$box['amount'], round(3*(float)CardsCo_Card::price($card,$cid),2));

sect('running it again changes nothing');
$before = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
$again  = CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
$after  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d",$cid));
ck('no new cards', $after, $before);
ck('reported as already done', $again['already'] >= 3);
ck('count still three', (int)CardsCo_Box::find($pid,'2026-11')['card_count'], 3);

sect('somebody joins late and the box tops up');
$late = make_people($cid, $bday, [['Nadia','Okoro',11,20,"24 Test Road\nLeeds"]]);
CardsCo_Programme::add_recipients($pid,$late);
CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
$box = CardsCo_Box::find($pid,'2026-11');
ck('box now holds four', (int)$box['card_count'], 4);
ck('total went up with it', (float)$box['amount'], round(4*(float)CardsCo_Card::price($card,$cid),2));

sect('a posted box is left alone');
CardsCo_Box::set_status((int)$box['id'],'posted');
$more = make_people($cid, $bday, [['Oli','Grant',11,21,"25 Test Road\nLeeds"]]);
CardsCo_Programme::add_recipients($pid,$more);
CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
$box = CardsCo_Box::find($pid,'2026-11');
ck('still four, box has gone', (int)$box['card_count'], 4);
ck('status untouched', $box['status'], 'posted');

sect('the general scan does not double up');
$res = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>400,'dry_run'=>true]);
$queued_for_programme_people = 0;
foreach (CardsCo_Occasion::upcoming(['company_id'=>$cid,'days'=>400,'limit'=>200]) as $occ) {
  if (CardsCo_Programme::covers((int)$occ['recipient_id'],(int)$occ['occasion_type_id'],$cid)) $queued_for_programme_people++;
}
ck('programme people are recognised', $queued_for_programme_people > 0);
ck('scan queues none of them', $res['queued'], 0);
// A programme person the scan meets lands in one of two buckets: skipped,
// because a programme covers them, or already_done, because an earlier run
// has queued their card. Counting only 'skipped' made this pass solely while
// nothing had been queued yet, which stopped being true one section earlier.
ck('it accounts for every one of them',
   $res['skipped'] + $res['already_done'] >= $queued_for_programme_people);
ck('and none of them were queued twice', $res['queued'], 0);
$outside = make_people($cid, $bday, [['Sam','Bright',11,9,"26 Test Road\nLeeds"]]);
$res2 = CardsCo_Scheduler::run(['company_id'=>$cid,'horizon'=>400,'dry_run'=>true]);
ck('somebody outside the programme is still handled', $res2['looked_at'] > $res['looked_at']);

sect('the other rhythm: one card at a time');
foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes'] as $t) {
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cardsco_{$t} WHERE company_id = %d", $cid ) ); }
wp_cache_flush();
$pid2 = CardsCo_Programme::create($cid,'employee-birthdays');
$ids2 = make_people($cid, $bday, [
  ['Rosa','Mint',11,14,"30 Test Road\nLeeds"],
  ['Dev','Patel',11,28,''],   // no address, and this time it matters
]);
CardsCo_Programme::add_recipients($pid2,$ids2);
CardsCo_Programme::update($pid2, [
  'card_id'=>$card,'message_override'=>'Happy birthday {first name}!',
  'handwriting'=>'Arthur','ink'=>'blue','signature'=>'Acme',
  'shipping_mode'=>'individual','timing_mode'=>'days_before','days_before'=>7,
  'weekend_rule'=>1,'starts_on'=>'2026-11-01','setup_step'=>7,
]);
CardsCo_Programme::activate($pid2);
$p2 = CardsCo_Programme::get($pid2);
ck('ships to each person', $p2['shipping_mode'], 'individual');

CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-11-06']);
ck('no box made for this rhythm', CardsCo_Box::find($pid2,'2026-11'), null);
$rows = $wpdb->get_results($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d ORDER BY event_date",$cid),ARRAY_A);
ck('only the card due within seven days is written', count($rows), 1);
ck('and it is the 14th', $rows[0]['event_date'], '2026-11-14');
ck('addressed to her own house', trim($rows[0]['address_snapshot']), "30 Test Road\nLeeds");
ck('no box attached', (int)$rows[0]['box_id'], 0);
ck('its own write-by, seven days before', $rows[0]['write_by'], '2026-11-06');

CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-11-21']);
$rows = $wpdb->get_results($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_card_queue WHERE company_id=%d ORDER BY event_date",$cid),ARRAY_A);
ck('later the second one comes due', count($rows), 2);
$dev = $rows[1];
ck('but it is held', $dev['status'], 'on_hold');
ck('because he has no address', $dev['hold_reason'], 'no_address');

sect('a programme with no card chosen is reported, not crashed');
$pid3 = CardsCo_Programme::create($cid,'employee-birthdays');
CardsCo_Programme::add_recipients($pid3, make_people($cid,$bday,[['Jo','Flint',11,14,"40 Road\nLeeds"]]));
$wpdb->update($wpdb->prefix.'cardsco_programmes',['status'=>'active'],['id'=>$pid3]);
$r3 = CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
ck('run survived', is_array($r3));
ck('it says what is missing', array_key_exists('no_card',$r3['reasons']));

printf("\n%s\n", $fail ? "$fail FAILED" : 'all passed');
exit($fail?1:0);
