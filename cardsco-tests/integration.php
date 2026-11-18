<?php
/**
 * The core objects everything else is built on: companies, people, their
 * dates, the address rules, the merge fields, the date parser and the
 * settings cascade.
 *
 * These are the pieces with no screen of their own, so a mistake in one of
 * them surfaces somewhere else entirely — a blank card, an empty dashboard
 * panel, a birthday a year out. Each one is pinned down here on its own.
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

/* ---------------------------------------------------------------- the tables */

sect('the tables');
$tables = ['activity_log','boxes','campaign_recipients','campaigns','card_queue','companies',
  'company_users','import_batches','occasion_types','occasions','programme_recipients',
  'programmes','recipient_segments','recipients','segments','settings'];
$found = 0;
foreach ($tables as $t) {
  $name = $wpdb->prefix.'cardsco_'.$t;
  if ((string)$wpdb->get_var($wpdb->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=%s",$name)) === $name
      || (string)$wpdb->get_var("SHOW TABLES LIKE '$name'") === $name) $found++;
}
ck('all sixteen are there', $found, 16);
ck('cardsco_table() names them the same way', cardsco_table('recipients'), $wpdb->prefix.'cardsco_recipients');

/* ------------------------------------------------------------------ companies */

sect('companies');
ck('a company needs a name', is_wp_error(CardsCo_Company::create(['name'=>''])), true);
ck('whitespace is not a name',is_wp_error(CardsCo_Company::create(['name'=>'   '])), true);
$cid = CardsCo_Company::create(['name'=>'Core Test Co '.wp_generate_password(6,false)]);
ck('a named one is created', is_int($cid) && $cid > 0);
$co = CardsCo_Company::get($cid);
ck('and reads back',          (int)$co['id'], $cid);
ck('a missing one is null',   CardsCo_Company::get(999999), null);
ck('id 0 is null',            CardsCo_Company::get(0), null);
ck('renaming works',          CardsCo_Company::update($cid,['name'=>'Core Test Renamed']), true);
ck('and sticks',              CardsCo_Company::get($cid)['name'], 'Core Test Renamed');
ck('renaming one that does not exist is refused',
  is_wp_error(CardsCo_Company::update(999999,['name'=>'Nope'])), true);

sect('who belongs to a company');
/* Fresh logins each run. Re-using a fixed login carried a membership over
 * from the previous run's company, and the lookup below then resolved to that
 * older one — which looked like a bug in the lookup and was not. */
$stamp = wp_generate_password(6,false);
$mk = fn($login) => (int) wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(),
  'user_email'=>$login.'@example.test','role'=>CardsCo_Roles::COMPANY_ROLE]);
$u1 = $mk('coreowner'.$stamp);
$u2 = $mk('coremember'.$stamp);
ck('an owner is added',  CardsCo_Company::add_user($cid,$u1,'owner'), true);
ck('a member is added',  CardsCo_Company::add_user($cid,$u2,'member'), true);
ck('both are listed',    count(CardsCo_Company::users($cid)), 2);
ck('the company is found from the user', cardsco_get_user_company_id($u1), $cid);
ck('adding the same person twice does not duplicate them',
  CardsCo_Company::add_user($cid,$u1,'owner') && count(CardsCo_Company::users($cid)) === 2);
ck('one can be removed', CardsCo_Company::remove_user($cid,$u2), true);
ck('leaving one',        count(CardsCo_Company::users($cid)), 1);
ck('a user with no company gets 0', cardsco_get_user_company_id(999999), 0);

sect('somebody who ends up in two companies');
/* The lookup takes the earliest membership, so adding a second one never
 * silently moves a person's dashboard onto another company's data. */
$second = CardsCo_Company::create(['name'=>'Core Second Co '.$stamp]);
CardsCo_Company::add_user($second,$u1,'member');
wp_cache_delete($u1,'cardsco_user_company');
ck('they are still read as belonging to the first', cardsco_get_user_company_id($u1), $cid);
ck('and both memberships are on record',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d",$u1)), 2);
CardsCo_Company::remove_user($second,$u1);
wp_cache_delete($u1,'cardsco_user_company');

/* ------------------------------------------------------------------- people */

sect('people');
ck('somebody needs a company',
  is_wp_error(CardsCo_Recipient::create(['first_name'=>'Ada','last_name'=>'Nwosu'])), true);
ck('and a name',
  is_wp_error(CardsCo_Recipient::create(['company_id'=>$cid,'first_name'=>'','last_name'=>''])), true);
$rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>'Ada','last_name'=>'Nwosu',
  'address'=>"3 Mill Lane\nLeeds",'postcode'=>'ls6 3aa','email'=>'ada@example.test']);
ck('a good one is created', is_int($rid) && $rid > 0);
$r = CardsCo_Recipient::get($rid);
ck('the name is kept',            $r['first_name'], 'Ada');
ck('the postcode is tidied up',   $r['postcode'], 'LS6 3AA');
ck('the address is judged complete', $r['address_status'], 'complete');
ck('and hashed so a change can be spotted', (bool)$r['address_hash']);
ck('a missing person is null',    CardsCo_Recipient::get(999999), null);

sect('somebody stored with one name only');
$single = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'customer','first_name'=>'Prince',
  'address'=>"1 High Street\nLeeds",'postcode'=>'LS1 1AA']);
ck('is allowed', is_int($single) && $single > 0);
ck('with an empty last name', CardsCo_Recipient::get($single)['last_name'], '');

sect('there are only two kinds of person');
$odd = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'penguin','first_name'=>'Odd',
  'address'=>"2 High Street\nLeeds",'postcode'=>'LS1 1AB']);
ck('anything else is refused outright', is_wp_error($odd), true);
ck('and said plainly', is_wp_error($odd)
  ? str_contains($odd->get_error_message(),'customer or employee') : false);
ck('an employee is fine',
  is_int(CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>'Emp',
    'address'=>"4 High Street\nLeeds",'postcode'=>'LS1 1AC'])));

/* -------------------------------------------------------- the address rules */

sect('whether an address is good enough to post to');
ck('nothing at all is missing',        cardsco_address_status('',''), 'missing');
ck('whitespace is missing',            cardsco_address_status('   ','  '), 'missing');
ck('a street with no postcode is incomplete',
  cardsco_address_status("3 Mill Lane\nLeeds",''), 'incomplete');
/* A box holding nothing but a postcode used to pass, because the postcode
 * itself was long enough to count as an address. */
ck('a postcode on its own is incomplete', cardsco_address_status('LS6 3AA','LS6 3AA'), 'incomplete');
ck('a postcode on its own in the address box is incomplete too',
  cardsco_address_status('LS6 3AA',''), 'incomplete');
ck('both together are complete',       cardsco_address_status("3 Mill Lane\nLeeds",'LS6 3AA'), 'complete');
ck('a postcode written into the address is found',
  cardsco_address_status("3 Mill Lane, Leeds, LS6 3AA",''), 'complete');
ck('a postcode with no space is found', cardsco_extract_postcode("3 Mill Lane, Leeds LS63AA") !== '');

sect('what to tell somebody about a bad address');
ck('nothing to say when it is fine', cardsco_address_issue("3 Mill Lane\nLeeds",'LS6 3AA'), '');
ck('no address at all',              cardsco_address_issue('',''), 'No address');
ck('no postcode',                    cardsco_address_issue("3 Mill Lane\nLeeds",''), 'No postcode');
ck('no street',                      cardsco_address_issue('LS6 3AA','LS6 3AA'), 'Needs a street address');

sect('spotting a changed address');
$h1 = cardsco_address_hash("3 Mill Lane\nLeeds",'LS6 3AA');
ck('the same address hashes the same',  cardsco_address_hash("3 Mill Lane\nLeeds",'LS6 3AA'), $h1);
ck('spacing and case do not matter',    cardsco_address_hash("3 mill lane\n  Leeds  ",'ls6 3aa'), $h1);
ck('a different address does not',      cardsco_address_hash("4 Mill Lane\nLeeds",'LS6 3AA') !== $h1);

/* ------------------------------------------------------------- the postcodes */

sect('postcodes');
ck('a real one passes',        CardsCo_Validator::postcode('LS6 3AA'), true);
ck('without the space too',    CardsCo_Validator::postcode('LS63AA'), true);
ck('lower case too',           CardsCo_Validator::postcode('ls6 3aa'), true);
ck('a London one passes',      CardsCo_Validator::postcode('EC1A 1BB'), true);
ck('nonsense does not',        CardsCo_Validator::postcode('BANANA'), false);
/* An empty postcode is absent, not wrong: whether an address is good enough
 * to post to is cardsco_address_status()'s job, and this only judges shape.
 * Rejecting empty here would make every half-filled form an error. */
ck('an empty one is absent, not invalid', CardsCo_Validator::postcode(''), true);
ck('and the address rules are the ones that catch it',
  cardsco_address_status("3 Mill Lane\nLeeds",''), 'incomplete');
ck('it is formatted with one space', CardsCo_Validator::format_postcode('ls63aa'), 'LS6 3AA');
ck('an already tidy one is left alone', CardsCo_Validator::format_postcode('LS6 3AA'), 'LS6 3AA');
/* Outside the UK we hold the postcode but do not judge its shape. */
ck('a foreign one is not rejected', CardsCo_Validator::postcode('75008','FR'), true);

sect('dates');
ck('an ISO date passes',        CardsCo_Validator::date('2026-11-07'), true);
ck('the 31st of February does not', CardsCo_Validator::date('2026-02-31'), false);
ck('a worded date is not an ISO date', CardsCo_Validator::date('7 November 2026'), false);
ck('empty is not a date',       CardsCo_Validator::date(''), false);

/* --------------------------------------------------- reading dates from a CSV */

sect('reading whatever a spreadsheet gives us');
/* The parser hands back the parts, not a date string, because a birthday
 * often arrives with no year at all and a Y-m-d would have to invent one. */
$p    = fn($v,$o='day') => CardsCo_Date_Parser::parse($v,$o);
$mar14 = ['day'=>14,'month'=>3,'year'=>1990];
ck('an ISO date',                $p('1990-03-14'), $mar14);
ck('day first with slashes',     $p('14/03/1990'), $mar14);
ck('month first when told so',   $p('03/14/1990','month'), $mar14);
/* A number above 12 can only be a day, whatever the column order says, so one
 * odd row is not thrown away because the rest of the column pointed the other
 * way. 03/14 read day-first would be month 14, which cannot exist. */
ck('an impossible month is read the only way it works',
  $p('03/14/1990','day'), $mar14);
ck('and a genuinely ambiguous one follows the order given',
  $p('03/07/1990','day'), ['day'=>3,'month'=>7,'year'=>1990]);
ck('the other way when told month first',
  $p('03/07/1990','month'), ['day'=>7,'month'=>3,'year'=>1990]);
ck('dots as separators',         $p('14.03.1990'), $mar14);
ck('dashes as separators',       $p('14-03-1990'), $mar14);
ck('a worded month',             $p('14 March 1990'), $mar14);
ck('a short worded month',       $p('14 Mar 1990'), $mar14);
ck('the month written first',    $p('March 14, 1990'), $mar14);
ck('an ordinal suffix',          $p('14th March 1990'), $mar14);
ck('a time on the end is dropped', $p('1990-03-14 09:30'), $mar14);
ck('an ISO timestamp too',       $p('1990-03-14T09:30:00'), $mar14);
ck('a twelve-hour time too',     $p('14/03/1990 9:30 am'), $mar14);
ck('a compact date',             $p('19900314'), $mar14);
ck('a date with no year at all', $p('14 March'), ['day'=>14,'month'=>3,'year'=>null]);
ck('empty is nothing',           $p(''), null);
ck('a sentence is nothing',      $p('sometime in the spring'), null);
ck('the 31st of February is nothing', $p('31/02/1990'), null);
ck('a day of 40 is nothing',     $p('40/03/1990'), null);

sect('working out whether a column is day first or month first');
$d = CardsCo_Date_Parser::detect_order(['14/03/1990','25/12/1988','03/07/1992']);
ck('a day above twelve settles it', $d['order'], 'day');
ck('and it says it is sure',        $d['confident'], true);
$m = CardsCo_Date_Parser::detect_order(['03/14/1990','12/25/1988']);
ck('a month above twelve settles it the other way', $m['order'], 'month');
ck('and it is sure',                $m['confident'], true);
$a = CardsCo_Date_Parser::detect_order(['03/07/1990','05/06/1988']);
ck('when every value works both ways it says so', $a['confident'], false);
ck('and counts them as ambiguous',  $a['ambiguous'], 2);
/* An ISO date or a worded month carries its own order, so it is no evidence
 * either way and must not be counted. */
$i = CardsCo_Date_Parser::detect_order(['1990-03-14','14 March 1990']);
ck('ISO and worded dates are no evidence', $i['ambiguous'] + $i['day_evidence'] + $i['month_evidence'], 0);
$b = CardsCo_Date_Parser::detect_order(['14/03/1990','03/14/1990']);
ck('evidence both ways picks the UK default', $b['order'], 'day');
ck('but does not claim to be sure',           $b['confident'], false);
/* With no ambiguous values there is nothing for the screen to ask about, so
 * a column of ISO dates — or an empty one — reports confident. Nothing reads
 * the order in that case, which is why this is not a warning. */
ck('a column with nothing to decide reports confident',
  CardsCo_Date_Parser::detect_order([])['confident'], true);
ck('and defaults to day first',  CardsCo_Date_Parser::detect_order([])['order'], 'day');

/* ---------------------------------------------------------- when it next falls */

sect('when a date next comes round');
$n = fn($o,$from) => cardsco_next_occurrence($o,$from);
ck('later this year',  $n(['event_month'=>12,'event_day'=>25],'2026-11-07'), '2026-12-25');
ck('already gone, so next year', $n(['event_month'=>3,'event_day'=>14],'2026-11-07'), '2027-03-14');
ck('today counts as still to come', $n(['event_month'=>11,'event_day'=>7],'2026-11-07'), '2026-11-07');
ck('yesterday does not',           $n(['event_month'=>11,'event_day'=>6],'2026-11-07'), '2027-11-06');
ck('no month is no date',          $n(['event_month'=>0,'event_day'=>14],'2026-11-07'), null);
ck('a thirteenth month is no date',$n(['event_month'=>13,'event_day'=>1],'2026-11-07'), null);
ck('a thirty-second day is no date',$n(['event_month'=>1,'event_day'=>32],'2026-11-07'), null);

sect('the 29th of February');
ck('falls on the 29th in a leap year',
  $n(['event_month'=>2,'event_day'=>29],'2028-01-01'), '2028-02-29');
/* Marked on the 28th rather than skipped, so the person still gets a card. */
ck('is marked on the 28th when there is no 29th',
  $n(['event_month'=>2,'event_day'=>29],'2027-01-01'), '2027-02-28');
ck('2028 is a leap year',  cardsco_is_leap_year(2028), true);
ck('2027 is not',          cardsco_is_leap_year(2027), false);
ck('1900 was not',         cardsco_is_leap_year(1900), false);
ck('2000 was',             cardsco_is_leap_year(2000), true);

sect('a one-off date');
ck('still ahead of us',
  $n(['event_month'=>12,'event_day'=>25,'event_year'=>2026,'recurrence'=>'once'],'2026-11-07'), '2026-12-25');
ck('already gone, and it does not come back',
  $n(['event_month'=>1,'event_day'=>5,'event_year'=>2026,'recurrence'=>'once'],'2026-11-07'), null);
ck('with no year it is no date',
  $n(['event_month'=>12,'event_day'=>25,'recurrence'=>'once'],'2026-11-07'), null);

sect('how far away a date is, in words for a screen');
ck('today',      cardsco_days_away('2026-11-07','2026-11-07'), 'Today');
ck('tomorrow',   cardsco_days_away('2026-11-08','2026-11-07'), 'Tomorrow');
ck('a date gone by', cardsco_days_away('2026-11-06','2026-11-07'), 'Passed');
ck('a month off',    cardsco_days_away('2026-12-07','2026-11-07'), 'In 30 days');

/* ------------------------------------------------------------ an occasion row */

sect('saving somebody a date');
$bday = CardsCo_Occasion_Type::get_by_slug('birthday');
$oid  = CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],
  'event_month'=>3,'event_day'=>14]);
ck('it is saved', is_int($oid) && $oid > 0);
$o = CardsCo_Occasion::get($oid);
ck('the month is kept',   (int)$o['event_month'], 3);
ck('the day is kept',     (int)$o['event_day'], 14);
ck('it is active',        (int)$o['is_active'], 1);
ck('the company came from the person', (int)$o['company_id'], $cid);
ck('and the next date was worked out', (bool)$o['next_occurrence']);
ck('saving the same date again does not make a second row',
  (int)CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],
    'event_month'=>3,'event_day'=>14]), $oid);
ck('changing it moves the date',
  (int)$wpdb->get_var($wpdb->prepare("SELECT event_day FROM {$wpdb->prefix}cardsco_occasions WHERE id=%d",
    CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>3,'event_day'=>15]))), 15);
ck('a nonsense date is refused',
  is_wp_error(CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],
    'event_month'=>13,'event_day'=>40])), true);
ck('reading it back by type',
  (int)CardsCo_Occasion::get_by_type($rid,$bday['id'])['event_day'], 15);
ck('one person\'s dates are listed', count(CardsCo_Occasion::for_recipient($rid)) >= 1);
ck('and can be removed',   CardsCo_Occasion::delete_by_type($rid,$bday['id']), true);
ck('leaving none of that type', CardsCo_Occasion::get_by_type($rid,$bday['id']), null);

sect('formatting a date for a screen');
ck('with no year',   cardsco_format_occasion_date(3,14), '14 March');
ck('with a year',    cardsco_format_occasion_date(3,14,1990), '14 March 1990');
ck('a bad month is empty', cardsco_format_occasion_date(0,14), '');
ck('twelve months are offered', count(cardsco_month_choices()), 12);

/* ------------------------------------------------------------- merge fields */

sect('the merge fields on offer');
$fields = CardsCo_Merge::fields();
ck('eight of them', count($fields), 8);
ck('and they are the ones the help text lists', array_keys($fields),
  ['first_name','last_name','full_name','company','our_company','last_order','offer_code','offer_expires']);
ck('every one has a label and a sample',
  count(array_filter($fields, fn($f) => $f['label'] && $f['sample'])), 8);
/* company is the recipient's, our_company is the sender's — "from all of us
 * at ...". Swapping the two would sign a card with the name of the person
 * receiving it, so they are deliberately two fields. */
ck('the recipient’s company and the sender’s are both there',
  array_diff(['company','our_company'], array_keys($fields)), []);
/* The two offer fields only make sense on a campaign: used in an occasion's
 * wording they would come out blank, and a blank must never reach a card. */
ck('an occasion message is offered six',   count(CardsCo_Merge::fields_for(false)), 6);
ck('a campaign is offered all eight',      count(CardsCo_Merge::fields_for(true)), 8);
ck('the offer fields are the two held back',
  array_values(array_diff(array_keys(CardsCo_Merge::fields_for(true)),array_keys(CardsCo_Merge::fields_for(false)))),
  ['offer_code','offer_expires']);
ck('one field is still waiting on data from the client',
  array_keys(array_filter($fields, fn($f) => !empty($f['needs_data']))), ['last_order']);

sect('reading the tokens out of a message');
ck('one token',        CardsCo_Merge::tokens('Hello {first name}'), ['first_name']);
ck('spaces, dashes and case all normalise to the same key',
  CardsCo_Merge::tokens('{First Name} {first-name} {FIRST_NAME}'), ['first_name']);
ck('several tokens',   CardsCo_Merge::tokens('{first name} at {company}'), ['first_name','company']);
ck('no tokens',        CardsCo_Merge::tokens('Just a plain message'), []);
ck('a token we do not have is picked out',
  CardsCo_Merge::unknown_tokens('Hello {first name}, your {favourite colour}'), ['favourite_colour']);
ck('and a message of known tokens has none', CardsCo_Merge::unknown_tokens('Hi {full name}'), []);

sect('filling a message in');
$done = CardsCo_Merge::render('Happy birthday {first name}!',['first_name'=>'Ada']);
ck('the name goes in',     $done['text'], 'Happy birthday Ada!');
ck('nothing missing',      $done['missing'], []);
ck('nothing unknown',      $done['unknown'], []);
ck('and the length is counted', $done['length'], mb_strlen('Happy birthday Ada!'));

sect('a value we do not have');
$gap = CardsCo_Merge::render('Thanks from {company}, {first name}',['first_name'=>'Ada','company'=>'']);
/* The token is left exactly as written, so the card is held back rather than
 * written with a hole in the middle of a sentence. */
ck('the token stays visible', str_contains($gap['text'],'{company}'));
ck('and is reported missing', $gap['missing'], ['company']);
ck('a value of only spaces counts as missing',
  CardsCo_Merge::render('Hi {first name}',['first_name'=>'   '])['missing'], ['first_name']);

sect('a token that is not a field at all');
$un = CardsCo_Merge::render('Your {favourite colour} is here',[]);
ck('it is left alone',       str_contains($un['text'],'{favourite colour}'));
ck('and reported unknown',   $un['unknown'], ['favourite_colour']);

sect('whether a filled-in message may go on a card');
ck('a clean one may', CardsCo_Merge::check($done), true);
ck('one with a gap may not', is_wp_error(CardsCo_Merge::check($gap)), true);
ck('and says which field', is_wp_error(CardsCo_Merge::check($gap))
  ? str_contains(CardsCo_Merge::check($gap)->get_error_message(),'company') : false);
ck('one with an unknown token may not', is_wp_error(CardsCo_Merge::check($un)), true);
ck('too long for the card may not',
  is_wp_error(CardsCo_Merge::check(CardsCo_Merge::render(str_repeat('a',400),[]),200)), true);
ck('inside the limit may',
  CardsCo_Merge::check(CardsCo_Merge::render('Short and sweet',[]),200), true);

sect('what a person supplies to a message');
$ctx = CardsCo_Merge::context_for_recipient(CardsCo_Recipient::get($rid));
ck('their first name',   $ctx['first_name'], 'Ada');
ck('their last name',    $ctx['last_name'], 'Nwosu');
ck('and both together',  $ctx['full_name'], 'Ada Nwosu');
$one_name = CardsCo_Merge::context_for_recipient(CardsCo_Recipient::get($single));
ck('somebody with one name has no last name', $one_name['last_name'], '');
ck('and their full name is just the one',     $one_name['full_name'], 'Prince');
ck('every field has a sample to preview with',
  count(array_filter(CardsCo_Merge::sample_context(),'strlen')), count(CardsCo_Merge::fields()));

/* -------------------------------------------------------------- the settings */

sect('the settings cascade');
$plat = CardsCo_Settings::get('price_default');
ck('there is a platform default', $plat !== null);
ck('a company with nothing set inherits it', CardsCo_Settings::get('price_default',null,$cid), $plat);
ck('a company value can be set', CardsCo_Settings::set('price_default','4.25',$cid), true);
ck('and overrides the platform',  CardsCo_Settings::get('price_default',null,$cid), '4.25');
ck('without changing the platform',CardsCo_Settings::get('price_default'), $plat);
ck('deleting it goes back to inheriting', CardsCo_Settings::delete('price_default',$cid)
  && CardsCo_Settings::get('price_default',null,$cid) === $plat);
ck('a key nobody has set returns the fallback given',
  CardsCo_Settings::get('a_key_nobody_set','fallback',$cid), 'fallback');
ck('and null when there is no fallback and no default',
  CardsCo_Settings::get('a_key_nobody_set',null,$cid), null);
ck('every default has a key', count(array_filter(array_keys(CardsCo_Settings::defaults()),'strlen')),
  count(CardsCo_Settings::defaults()));

sect('an array setting survives the round trip');
/* Uses a declared list setting. An invented key used to be accepted here;
 * settings are now validated against a schema, so a key nobody declared is
 * refused — which is the point, since a settings form posts into set(). */
ck('it is stored',  CardsCo_Settings::set('shipping_countries',['GB','IE'],$cid), true);
ck('and comes back as an array', CardsCo_Settings::get('shipping_countries',null,$cid), ['GB','IE']);
CardsCo_Settings::delete('shipping_countries',$cid);

sect('a key nobody declared');
ck('cannot be written', CardsCo_Settings::set('a_list',['one','two'],$cid), false);
ck('and reading it still falls back', CardsCo_Settings::get('a_list','nothing',$cid), 'nothing');
/* Keys that depend on database rows are matched by shape instead, so a
 * company's standing card per occasion type still works. */
ck('but a per-occasion default is allowed',
  CardsCo_Settings::set('default_card_7','42',$cid), true);
ck('and reads back as a number', (int)CardsCo_Settings::get('default_card_7',0,$cid), 42);
CardsCo_Settings::delete('default_card_7',$cid);

/* ----------------------------------------------------------------- the roles */

sect('the roles');
ck('the company role exists', (bool)get_role(CardsCo_Roles::COMPANY_ROLE));
ck('and can see a dashboard',
  (bool)get_role(CardsCo_Roles::COMPANY_ROLE)->has_cap('cardsco_access_dashboard'));
ck('but cannot look at other companies',
  (bool)get_role(CardsCo_Roles::COMPANY_ROLE)->has_cap('cardsco_manage_all_companies'), false);
ck('an administrator can',
  (bool)get_role('administrator')->has_cap('cardsco_manage_all_companies'));
ck('a plain subscriber cannot see a dashboard',
  (bool)get_role('subscriber')->has_cap('cardsco_access_dashboard'), false);

/* ---------------------------------------------------------------- the log */

sect('the activity log');
$before = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_activity_log WHERE company_id=%d",$cid));
cardsco_log(['company_id'=>$cid,'object_type'=>'recipient','object_id'=>$rid,
  'action'=>'tested','summary'=>'A line for the test']);
ck('a line is written',
  (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cardsco_activity_log WHERE company_id=%d",$cid)), $before+1);
$line = $wpdb->get_row($wpdb->prepare(
  "SELECT * FROM {$wpdb->prefix}cardsco_activity_log WHERE company_id=%d ORDER BY id DESC LIMIT 1",$cid), ARRAY_A);
ck('with what happened', $line['action'], 'tested');
ck('and who did it',     (int)$line['user_id'], get_current_user_id());
ck('and when',           (bool)$line['created_at']);

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
