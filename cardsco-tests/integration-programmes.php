<?php
/**
 * Programmes and the category definitions they are built on.
 *
 * The category layer is where three bugs hid earlier in the build: the
 * programme row stores `category` and not `occasion_type_id`, Category::cards()
 * hands back WP_Post objects rather than rows, and upcoming() was not
 * selecting occasion_type_id at all. None of those three showed up as an error
 * — each one quietly produced an empty screen — so the shapes these methods
 * return are asserted here and not only their truthiness.
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

/* Clear what previous runs of this suite left behind. Cards accumulated run
 * after run until the published library passed for_occasion()'s 200-row
 * window, and an unrelated suite started failing — a test-data problem that
 * read exactly like a code bug. */
foreach ( get_posts( array(
	'post_type'      => array( CardsCo_Card::POST_TYPE, 'product' ),
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => array( array( 'key' => '_cardsco_test_suite', 'value' => 'programmes' ) ),
) ) as $cc_old ) {
	wp_delete_post( $cc_old, true );
}


$cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Programme Test Co'));
if (!$cid) { $c = CardsCo_Company::create(['name'=>'Programme Test Co']); $cid = is_wp_error($c)?0:(int)$c; }
foreach (['occasions','programme_recipients','programmes','recipients','card_queue','boxes','activity_log'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('a clean company', $cid > 0);

/* ----------------------------------------------------------- the categories */

sect('the six categories the designs show');
$all = CardsCo_Category::all();
ck('six of them', count($all), 6);
ck('in the order step 1 lays them out', array_keys($all),
  ['christmas-seasonal','employee-birthdays','thank-you','customer-milestones','work-anniversaries','promotions-offers']);
ck('every one has a label, blurb and format',
  count(array_filter($all, fn($c) => $c['label'] && $c['blurb'] && $c['format'])), 6);
ck('every audience is one we know',
  array_diff(array_column($all,'audience'), ['employee','customer','both']), []);

sect('which can be started');
ck('employee birthdays can',        CardsCo_Category::is_available('employee-birthdays'), true);
ck('work anniversaries cannot yet', CardsCo_Category::is_available('work-anniversaries'), false);
ck('and it is the only one marked so',
  array_keys(array_filter($all, fn($c) => !empty($c['coming_soon']))), ['work-anniversaries']);
ck('an invented slug cannot',        CardsCo_Category::is_available('birthdays-of-pets'), false);
ck('an empty slug cannot',           CardsCo_Category::is_available(''), false);
ck('a missing category is null',     CardsCo_Category::get('nope'), null);

sect('dated categories versus campaign ones');
ck('birthdays come off dates people hold', CardsCo_Category::is_dated('employee-birthdays',$cid), true);
/* promotions-offers deliberately has no occasion types: it is sent as a
 * campaign on a day somebody picks, so there is no date to schedule from. */
ck('promotions are not dated',              CardsCo_Category::is_dated('promotions-offers',$cid), false);
ck('and so it has no types',                CardsCo_Category::types('promotions-offers',$cid), []);
ck('and no primary type',                   CardsCo_Category::primary_type('promotions-offers',$cid), null);

sect('types and the mapping back');
$bday = CardsCo_Occasion_Type::get_by_slug('birthday');
ck('birthday exists as a type', (int)($bday['id'] ?? 0) > 0);
$primary = CardsCo_Category::primary_type('employee-birthdays',$cid);
ck('employee birthdays point at it', (int)$primary['id'], (int)$bday['id']);
ck('christmas & seasonal carries eleven', count(CardsCo_Category::types('christmas-seasonal',$cid)), 11);
ck('birthday maps back to one category', CardsCo_Category::for_type($bday['id'],$cid), ['employee-birthdays']);
ck('an unknown type maps back to nothing', CardsCo_Category::for_type(999999,$cid), []);

sect('the designs and messages behind a category');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Balloons','post_status'=>'publish'],true);
  update_post_meta($card, '_cardsco_test_suite', 'programmes');
CardsCo_Card::set_occasions($card,[(int)$bday['id']]);
$hidden = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Not ready','post_status'=>'draft'],true);
  update_post_meta($hidden, '_cardsco_test_suite', 'programmes');
CardsCo_Card::set_occasions($hidden,[(int)$bday['id']]);
wp_cache_flush();

$cards = CardsCo_Category::cards('employee-birthdays',$cid);
ck('the published card is there', count($cards) >= 1);
/* These come back as WP_Post objects, not rows. The views read ->ID and
 * ->post_title off them; treating one as an array silently printed nothing. */
ck('they are WP_Post objects', $cards && $cards[0] instanceof WP_Post);
ck('a draft card is not offered', !in_array($hidden, array_map(fn($p)=>$p->ID,$cards), true));
ck('the count agrees with the list', CardsCo_Category::design_count('employee-birthdays',$cid), count($cards));
ck('messages come back as an array', is_array(CardsCo_Category::messages('employee-birthdays',$cid)));

/* The rule as of 0.32.0: a card belongs to exactly one collection. It is
 * chosen on the card, and for cards saved before that field existed it is
 * worked out from the occasions ticked on them.
 *
 * This replaces an earlier rule where a card with nothing ticked belonged to
 * every category at once. That was how Promotions & Offers — which has no
 * occasion types of its own, being campaign-driven — came to have any cards:
 * it collected every untagged design in the library by accident, and so did
 * all five other collections. Choosing the collection directly is what makes
 * that category work properly, so the fallback is gone. */
$generic = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Plain stripe','post_status'=>'publish'],true);
  update_post_meta($generic, '_cardsco_test_suite', 'programmes');
wp_cache_flush();
$promo_cards = fn() => array_map(fn($p)=>$p->ID, CardsCo_Category::cards('promotions-offers',$cid));
$bday_cards  = fn() => array_map(fn($p)=>$p->ID, CardsCo_Category::cards('employee-birthdays',$cid));

ck('a card with nothing set has no collection', CardsCo_Card::category($generic), '');
ck('so it is in no collection at all',          !in_array($generic,$promo_cards(),true) && !in_array($generic,$bday_cards(),true));

/* Promotions & Offers covers no occasion type, so choosing it on the card is
 * the only way in — and it has to work, or that collection stays empty. */
CardsCo_Card::set_category($generic, 'promotions-offers');
wp_cache_flush();
ck('choosing the collection puts it there', in_array($generic,$promo_cards(),true));
ck('and only there',                        !in_array($generic,$bday_cards(),true));

ck('a birthday card is not offered for promotions', !in_array($card,$promo_cards(),true));
ck('but it is offered for birthdays',                in_array($card,$bday_cards(),true));

/* The legacy path: ticks alone, no stored field, still resolves. */
ck('an untouched card derives its collection from its ticks', CardsCo_Card::category($card), 'employee-birthdays');
ck('and the stored field wins over the derivation', (function() use ($card) {
  CardsCo_Card::set_category($card, 'christmas-seasonal');
  $got = CardsCo_Card::category($card);
  CardsCo_Card::set_category($card, '');   // put it back
  return $got;
})(), 'christmas-seasonal');
ck('clearing it falls back to the ticks again', CardsCo_Card::category($card), 'employee-birthdays');

/* ------------------------------------------------------- creating programmes */

sect('creating one');
ck('without a company it is refused', is_wp_error(CardsCo_Programme::create(0,'employee-birthdays')), true);
ck('with a company that does not exist it is refused',
  is_wp_error(CardsCo_Programme::create(999999,'employee-birthdays')), true);
ck('with an unknown category it is refused',
  is_wp_error(CardsCo_Programme::create($cid,'not-a-category')), true);
$soon = CardsCo_Programme::create($cid,'work-anniversaries');
ck('a coming-soon category is refused', is_wp_error($soon), true);
ck('and says which one', is_wp_error($soon) ? str_contains($soon->get_error_message(),'Work Anniversaries') : false);

$pid = CardsCo_Programme::create($cid,'employee-birthdays');
ck('a good one is created', is_int($pid) && $pid > 0);
$row = CardsCo_Programme::get($pid);
ck('it starts as a draft',       $row['status'], 'draft');
/* Step 2, not 1: setup_step is the furthest step reached, and a programme
 * only exists once the occasion has been chosen, which is step 1 done. Left
 * at 1, step_on_screen() clamped the wizard back to step 1 and choosing an
 * occasion appeared to do nothing. */
ck('ready for step 2',           (int)$row['setup_step'], 2);
ck('it stores the category slug', $row['category'], 'employee-birthdays');
ck('named from the category',    $row['name'], 'Employee Birthdays');
ck('with a price from settings', $row['unit_price'] !== null && $row['unit_price'] !== '');
ck('a missing programme is null', CardsCo_Programme::get(999999), null);
ck('id 0 is null',                CardsCo_Programme::get(0), null);

/* ------------------------------------------------------------------- people */

sect('putting people in');
$ids = [];
foreach ([['Ada','Nwosu',3,14],['Ben','Clarke',3,2],['Cara','Diaz',7,9],['Dev','Okafor',0,0]] as $p) {
  list($f,$l,$m,$d) = $p;
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$f,'last_name'=>$l,
    'address'=>"1 Mill Lane\nLeeds",'postcode'=>'LS6 3AA']);
  if (is_wp_error($rid)) continue;
  $ids[] = (int) $rid;
  if ($m) CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>$m,'event_day'=>$d]);
}
ck('four people, three with a birthday', count($ids), 4);
ck('added', CardsCo_Programme::add_recipients($pid,$ids), 4);
ck('adding the same people again adds none', CardsCo_Programme::add_recipients($pid,$ids), 0);
ck('they are all listed', count(CardsCo_Programme::recipient_ids($pid)), 4);
ck('an empty list adds nothing', CardsCo_Programme::add_recipients($pid,[]), 0);

$sum = CardsCo_Programme::people_summary(CardsCo_Programme::get($pid));
ck('four in total',            $sum['total'], 4);
ck('three ready to send to',   $sum['ready'], 3);
ck('one still needs a date',   $sum['missing_date'], 1);
ck('addresses do not matter when the company is shipped to', $sum['missing_address'], 0);

sect('addresses when each card is posted to the person');
CardsCo_Programme::update($pid,['shipping_mode'=>'individual']);
$sum = CardsCo_Programme::people_summary(CardsCo_Programme::get($pid));
ck('now they are counted', is_int($sum['missing_address']));
CardsCo_Programme::update($pid,['shipping_mode'=>'company']);

sect('taking people out');
ck('one removed', CardsCo_Programme::remove_recipients($pid,[$ids[3]]), 1);
ck('three left',  count(CardsCo_Programme::recipient_ids($pid)), 3);
ck('removing somebody who is not in it removes none', CardsCo_Programme::remove_recipients($pid,[$ids[3]]), 0);

/* ------------------------------------------------------------- completeness */

sect('step by step completeness');
$P = fn() => CardsCo_Programme::get($pid);
ck('step 1 is done the moment it exists', CardsCo_Programme::step_complete($P(),1), true);
ck('step 2 is done with people who have dates', CardsCo_Programme::step_complete($P(),2), true);
ck('step 3 is not done without a card', CardsCo_Programme::step_complete($P(),3), false);
CardsCo_Programme::update($pid,['card_id'=>$card]);
ck('step 3 is done with one',           CardsCo_Programme::step_complete($P(),3), true);
ck('step 4 is not done without words',  CardsCo_Programme::step_complete($P(),4), false);
CardsCo_Programme::update($pid,['message_override'=>'Happy birthday {first name}']);
ck('step 4 is done with a written message', CardsCo_Programme::step_complete($P(),4), true);
CardsCo_Programme::update($pid,['message_override'=>'   ']);
ck('whitespace is not a message',       CardsCo_Programme::step_complete($P(),4), false);
CardsCo_Programme::update($pid,['message_override'=>'Happy birthday {first name}']);

ck('step 5 is not done without an address', CardsCo_Programme::step_complete($P(),5), false);
CardsCo_Programme::update($pid,['ship_address'=>"Unit 4\nLeeds",'ship_postcode'=>'LS1 4AP']);
ck('step 5 is done with one',           CardsCo_Programme::step_complete($P(),5), true);
CardsCo_Programme::update($pid,['shipping_mode'=>'individual']);
ck('posting to each person needs no company address', CardsCo_Programme::step_complete($P(),5), true);
CardsCo_Programme::update($pid,['shipping_mode'=>'company']);

ck('step 6 is not done without a start date', CardsCo_Programme::step_complete($P(),6), false);
CardsCo_Programme::update($pid,['starts_on'=>'2026-12-01','timing_mode'=>'monthly_box','box_arrive_day'=>25]);
ck('step 6 is done with one',           CardsCo_Programme::step_complete($P(),6), true);

ck('and now it can be turned on', CardsCo_Programme::can_activate($P()), true);
ck('step 7 agrees',               CardsCo_Programme::step_complete($P(),7), true);

sect('what stops it being turned on');
$empty = CardsCo_Programme::create($cid,'employee-birthdays');
$stop  = CardsCo_Programme::can_activate(CardsCo_Programme::get($empty));
ck('an untouched one is refused', is_wp_error($stop), true);
$codes = is_wp_error($stop) ? $stop->get_error_codes() : [];
ck('and every unfinished step is named', count($codes) >= 4);
ck('step 2 among them', in_array('cardsco_step_2',$codes,true));
ck('step 6 among them', in_array('cardsco_step_6',$codes,true));

/* ---------------------------------------------------------------- the dates */

sect('when a box has to arrive');
$timed = ['box_arrive_day'=>25,'starts_on'=>'2026-12-01'];
ck('for December, the 25th of November', CardsCo_Programme::box_arrive_date($timed,'2026-12'), '2026-11-25');
ck('for January, the 25th of December',  CardsCo_Programme::box_arrive_date($timed,'2027-01'), '2026-12-25');
ck('the 1st works',  CardsCo_Programme::box_arrive_date(['box_arrive_day'=>1]+$timed,'2026-12'), '2026-11-01');
ck('the 28th works', CardsCo_Programme::box_arrive_date(['box_arrive_day'=>28]+$timed,'2026-12'), '2026-11-28');
/* Past the 28th there is no such day in February, so it falls back rather
 * than rolling into the next month. */
ck('the 31st falls back to the 25th', CardsCo_Programme::box_arrive_date(['box_arrive_day'=>31]+$timed,'2026-12'), '2026-11-25');
ck('day 0 falls back to the 25th',    CardsCo_Programme::box_arrive_date(['box_arrive_day'=>0]+$timed,'2026-12'), '2026-11-25');
ck('a month of nonsense is no date',  CardsCo_Programme::box_arrive_date($timed,'next Tuesday'), null);
ck('a full date is not a period',     CardsCo_Programme::box_arrive_date($timed,'2026-12-01'), null);
ck('an empty period is no date',      CardsCo_Programme::box_arrive_date($timed,''), null);

sect('which month comes next');
$future = ['starts_on'=>'2027-03-01'];
ck('a start date ahead of us wins',    CardsCo_Programme::next_period($future,'2026-11-07'), '2027-03');
$past = ['starts_on'=>'2026-01-01'];
ck('a start date behind us does not',  CardsCo_Programme::next_period($past,'2026-11-07'), '2026-11');
ck('no start date means this month',   CardsCo_Programme::next_period(['starts_on'=>null],'2026-11-07'), '2026-11');

sect('how many cards fall in a month');
$live = CardsCo_Programme::get($pid);
ck('March has the two March birthdays', CardsCo_Programme::count_in_period($live,'2026-03'), 2);
ck('July has one',                      CardsCo_Programme::count_in_period($live,'2026-07'), 1);
ck('May has none',                      CardsCo_Programme::count_in_period($live,'2026-05'), 0);
/* The count is by month, not by year: a birthday recurs. */
ck('the same month next year still has two', CardsCo_Programme::count_in_period($live,'2027-03'), 2);
ck('a category with no dates counts nothing',
  CardsCo_Programme::count_in_period(['category'=>'promotions-offers','company_id'=>$cid,'id'=>$pid],'2026-03'), 0);

sect('the figures the review step shows');
$est = CardsCo_Programme::estimate($live);
$price = (float) $live['unit_price'];
ck('people counted',       $est['people'], 3);
ck('cards a year counted', $est['per_year'], 3);
ck('the unit price has two decimals', (bool)preg_match('/^\d+\.\d{2}$/',$est['unit_price']));
ck('the yearly total is people times price', $est['yearly_total'], number_format(3*$price,2,'.',''));
ck('the next total is that month times price',
  $est['next_total'], number_format($est['next_count']*$price,2,'.',''));
ck('a currency is set', $est['currency'] !== '');
ck('a next period is set', (bool)preg_match('/^\d{4}-\d{2}$/',(string)$est['next_period']));

/* ------------------------------------------------------- turning it on, and
 * the double-send guard                                                     */

sect('turning it on');
ck('it goes on',  CardsCo_Programme::activate($pid), true);
ck('and is active', CardsCo_Programme::get($pid)['status'], 'active');
ck('one that does not exist cannot', is_wp_error(CardsCo_Programme::activate(999999)), true);
ck('an unfinished one cannot',       is_wp_error(CardsCo_Programme::activate($empty)), true);

sect('a running programme stops the general scan sending a second card');
ck('it reports the programme looking after them',
  CardsCo_Programme::covers($ids[0],$bday['id'],$cid), $pid);
ck('somebody not in it is not covered',
  CardsCo_Programme::covers($ids[3],$bday['id'],$cid), 0);
ck('an occasion type nobody runs is not covered',
  CardsCo_Programme::covers($ids[0],999999,$cid), 0);
ck('no recipient is not covered', CardsCo_Programme::covers(0,$bday['id'],$cid), 0);
CardsCo_Programme::update($pid,['status'=>'paused']);
ck('a paused programme covers nobody',
  CardsCo_Programme::covers($ids[0],$bday['id'],$cid), 0);
CardsCo_Programme::update($pid,['status'=>'active']);

/* ------------------------------------------------------------- the queries */

sect('finding them again');
ck('all of this company\'s', count(CardsCo_Programme::for_company($cid)) >= 2);
ck('only the active one',    count(CardsCo_Programme::for_company($cid,'active')), 1);
ck('newest first',
  (int)CardsCo_Programme::for_company($cid)[0]['id'] > (int)CardsCo_Programme::for_company($cid)[1]['id']);
ck('a company with nothing gets an empty list', CardsCo_Programme::for_company(999999), []);
$act = CardsCo_Programme::active($cid);
ck('the scheduler sees the active one', count($act), 1);
ck('and it is the right one', (int)$act[0]['id'], $pid);
ck('every row the scheduler gets is active',
  array_unique(array_column(CardsCo_Programme::active($cid),'status')), ['active']);

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
