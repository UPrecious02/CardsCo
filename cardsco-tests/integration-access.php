<?php
/**
 * Access scoping: can one company ever see another's data?
 *
 * This suite is written adversarially. It is not enough that the right data
 * comes back for the right person; it has to be impossible to get somebody
 * else's by asking for it.
 */
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/dashboard/'; $_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
global $wpdb;
$fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-52s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();
CardsCo_Roles::install();

/** Build a company with a user, people, an occasion, a campaign and a box. */
function build($name, $suffix, $bday, $card) {
  global $wpdb;
  $cid = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",$name));
  if(!$cid){ $c=CardsCo_Company::create(['name'=>$name]); $cid=is_wp_error($c)?0:(int)$c; }
  foreach(['occasions','programme_recipients','programmes','recipients','card_queue','boxes','campaigns','recipient_segments','segments','activity_log'] as $t)
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));

  $login = 'owner'.$suffix;
  $uid = username_exists($login);
  if(!$uid) $uid = wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(),
    'user_email'=>$login.'@example.test','role'=>CardsCo_Roles::COMPANY_ROLE]);
  CardsCo_Company::add_user($cid,$uid,'owner');

  $ids=[];
  foreach ([['Ann','Alpha',11,12],['Bo','Beta',11,20]] as $i=>$p) {
    list($f,$l,$m,$d)=$p;
    $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$f.$suffix,
      'last_name'=>$l,'address'=>(1+$i)." Road\nLeeds",'postcode'=>'LS1 1AA']);
    if(is_wp_error($rid)) continue;
    $ids[]=$rid;
    CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>$m,'event_day'=>$d]);
  }
  $pid = CardsCo_Programme::create($cid,'employee-birthdays');
  CardsCo_Programme::add_recipients($pid,$ids);
  CardsCo_Programme::update($pid,['card_id'=>$card,'message_override'=>'Hi {first name}',
    'handwriting'=>'Arthur','ink'=>'blue','signature'=>'Us','shipping_mode'=>'company',
    'ship_address'=>"Office\nLeeds",'ship_postcode'=>'LS1 4AP','timing_mode'=>'monthly_box',
    'box_arrive_day'=>25,'starts_on'=>'2026-11-01','setup_step'=>7]);
  CardsCo_Programme::activate($pid);
  CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);

  $seg = CardsCo_Segment::create(['company_id'=>$cid,'name'=>'All'.$suffix,'applies_to'=>'both']);
  CardsCo_Segment::add_recipients($seg,$ids);
  $camp = CardsCo_Campaign::create($cid,'Camp'.$suffix);
  CardsCo_Campaign::update($camp,['occasion_type_id'=>$bday['id'],'card_id'=>$card,
    'message_body'=>'Hi {first name}','audience'=>['segments'=>[$seg]],'send_date'=>'2027-05-20']);

  return ['cid'=>$cid,'uid'=>$uid,'people'=>$ids,'programme'=>$pid,'campaign'=>$camp,
          'segment'=>$seg,'box'=>CardsCo_Box::find($pid,'2026-11')];
}

$bday = CardsCo_Category::primary_type('employee-birthdays');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Shared Card','post_status'=>'publish'],true);

$A = build('Alpha Ltd','A',$bday,$card);
$B = build('Beta Ltd','B',$bday,$card);
ck('two separate companies', $A['cid'] !== $B['cid']);
ck('each has a box', is_array($A['box']) && is_array($B['box']));
ck("A's box has cards", (int)$A['box']['card_count'], 2);

sect('signed out');
wp_set_current_user(0);
$_GET = [];
ck('no company', CardsCo_Access::company_id(), 0);
ck('cannot view', CardsCo_Access::can_view(), false);
ck('told to sign in', str_contains(CardsCo_Access::refusal(),'sign in'));
ck('summary is empty', CardsCo_Dashboard::summary()['people'], 0);
ck('no upcoming', CardsCo_Dashboard::upcoming(), []);
ck('no programmes', CardsCo_Dashboard::programmes(), []);
ck('no campaigns', CardsCo_Dashboard::campaigns(), []);
ck('no boxes', CardsCo_Dashboard::boxes(), []);
ck('no held cards', CardsCo_Dashboard::held(), []);
ck('no activity', CardsCo_Dashboard::activity(), []);
ck('no spend', CardsCo_Dashboard::spend_this_year(), '0.00');
ck("cannot reach A's programme", CardsCo_Access::owned('programmes',$A['programme']), null);

sect("signed in as Alpha's owner");
wp_set_current_user($A['uid']);
wp_cache_flush();
$_GET = [];
ck('resolves to Alpha', CardsCo_Access::company_id(), $A['cid']);
ck('can view', CardsCo_Access::can_view(), true);
ck('nothing to refuse', CardsCo_Access::refusal(), '');
ck('sees own people', CardsCo_Dashboard::summary()['people'], 2);
ck('sees own programme', count(CardsCo_Dashboard::programmes()), 1);
ck('sees own campaign', count(CardsCo_Dashboard::campaigns()), 1);
$mine = CardsCo_Dashboard::boxes();
ck('sees its boxes, three months planned ahead', count($mine), 3);
ck('every one belongs to Alpha',
   count(array_filter($mine, fn($b)=>(int)$b['company_id'] === $A['cid'])), 3);
ck('the November box is among them',
   in_array((int)$A['box']['id'], array_map(fn($b)=>(int)$b['id'], $mine), true));

sect('and cannot reach Beta by any route');
ck("owned() refuses Beta's programme", CardsCo_Access::owned('programmes',$B['programme']), null);
ck("owned() refuses Beta's campaign", CardsCo_Access::owned('campaigns',$B['campaign']), null);
ck("owned() refuses Beta's box", CardsCo_Access::owned('boxes',(int)$B['box']['id']), null);
ck("owned() refuses Beta's person", CardsCo_Access::owned('recipients',$B['people'][0]), null);
ck("owned() refuses Beta's segment", CardsCo_Access::owned('segments',$B['segment']), null);
ck('owns() agrees', CardsCo_Access::owns('programmes',$B['programme']), false);
ck("but allows Alpha's own", is_array(CardsCo_Access::owned('programmes',$A['programme'])));

// occasions have no company of their own; they are checked through the person
$aocc = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_occasions WHERE recipient_id=%d",$A['people'][0]));
$bocc = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_occasions WHERE recipient_id=%d",$B['people'][0]));
ck('own occasion reachable', is_array(CardsCo_Access::owned('occasions',$aocc)));
ck("other company's occasion is not", CardsCo_Access::owned('occasions',$bocc), null);

sect('and cannot widen it with a URL');
$_GET = ['company'=>$B['cid']];
ck('?company is ignored', CardsCo_Access::company_id(), $A['cid']);
ck('still only own people', CardsCo_Dashboard::summary()['people'], 2);
ck('still only own campaigns', count(CardsCo_Dashboard::campaigns()), 1);
ck("Beta's box still unreachable", CardsCo_Access::owned('boxes',(int)$B['box']['id']), null);
$_GET = ['company'=>'99999'];
ck('a made-up company is ignored', CardsCo_Access::company_id(), $A['cid']);
$_GET = ['company'=>"1 OR 1=1"];
ck('rubbish in the parameter is ignored', CardsCo_Access::company_id(), $A['cid']);
$_GET = [];

sect('the table whitelist holds');
ck('an unknown table is refused', CardsCo_Access::owned('wp_users',1), null);
ck('a settings table is not reachable', CardsCo_Access::owned('settings',1), null);
ck('company_users is not reachable', CardsCo_Access::owned('company_users',1), null);
ck('an empty name is refused', CardsCo_Access::owned('',1), null);
ck('id zero is refused', CardsCo_Access::owned('programmes',0), null);
ck('a missing row is refused', CardsCo_Access::owned('programmes',8888888), null);

sect('upcoming only ever lists your own people');
$up = CardsCo_Dashboard::upcoming(400);
ck('some upcoming', count($up) > 0);
$names = implode(' ', array_map(fn($u)=>$u['name'], $up));
ck('every name is ours', !str_contains($names,'AnnB') && !str_contains($names,'BoB'));
ck('and they are ours', str_contains($names,'AnnA') || str_contains($names,'BoA'));
ck('each carries how far off it is', isset($up[0]['days_away']));

sect('spend counts only cards that went out');
$cards = CardsCo_Box::cards((int)$A['box']['id']);
ck('two cards queued', count($cards), 2);
ck('queued cards cost nothing yet', CardsCo_Dashboard::spend_this_year(), '0.00');
CardsCo_Queue::set_status((int)$cards[0]['id'],'posted');
$wpdb->update($wpdb->prefix.'cardsco_card_queue',['event_date'=>gmdate('Y').'-06-01'],['id'=>(int)$cards[0]['id']]);
$spend = (float) CardsCo_Dashboard::spend_this_year();
ck('a posted card counts', $spend > 0);
CardsCo_Queue::set_status((int)$cards[1]['id'],'cancelled');
$wpdb->update($wpdb->prefix.'cardsco_card_queue',['event_date'=>gmdate('Y').'-06-01'],['id'=>(int)$cards[1]['id']]);
ck('a cancelled card does not', (float)CardsCo_Dashboard::spend_this_year(), $spend);

sect('held cards are listed with a reason');
$held = CardsCo_Recipient::create(['company_id'=>$A['cid'],'type'=>'employee','first_name'=>'NoAddr',
  'last_name'=>'Person','address'=>'','postcode'=>'']);
CardsCo_Queue::add(['company_id'=>$A['cid'],'source'=>'occasion','recipient_id'=>$held,
  'recipient_name'=>'NoAddr Person','card_id'=>$card,'message_final'=>'x',
  'event_date'=>'2026-12-01','status'=>'on_hold','hold_reason'=>'no_address']);
ck('one held card', count(CardsCo_Dashboard::held()), 1);
ck('with the reason', CardsCo_Dashboard::held()[0]['hold_reason'], 'no_address');
ck('and counted in the summary', CardsCo_Dashboard::summary()['cards_on_hold'], 1);

sect("signed in as Beta's owner");
wp_set_current_user($B['uid']);
wp_cache_flush();
$_GET = [];
ck('resolves to Beta', CardsCo_Access::company_id(), $B['cid']);
ck('sees own people only', CardsCo_Dashboard::summary()['people'], 2);
ck('Beta sees no held cards', count(CardsCo_Dashboard::held()), 0);
ck('Beta sees no spend', CardsCo_Dashboard::spend_this_year(), '0.00');
ck("cannot reach Alpha's programme", CardsCo_Access::owned('programmes',$A['programme']), null);
$theirs = CardsCo_Dashboard::boxes();
ck('every box belongs to Beta',
   count(array_filter($theirs, fn($b)=>(int)$b['company_id'] === $B['cid'])), count($theirs));
ck("and none of Alpha's are in it",
   count(array_filter($theirs, fn($b)=>(int)$b['company_id'] === $A['cid'])), 0);

sect('a user with no company');
$lonely = username_exists('lonely');
if(!$lonely) $lonely = wp_insert_user(['user_login'=>'lonely','user_pass'=>wp_generate_password(),
  'user_email'=>'lonely@example.test','role'=>CardsCo_Roles::COMPANY_ROLE]);
wp_set_current_user($lonely); wp_cache_flush(); $_GET=[];
ck('no company', CardsCo_Access::company_id(), 0);
ck('cannot view', CardsCo_Access::can_view(), false);
ck('told their account is not linked', str_contains(CardsCo_Access::refusal(),'not linked'));
ck('sees nothing', CardsCo_Dashboard::summary()['people'], 0);

sect('access granted, then taken away');
$sub = username_exists('plainsub');
if(!$sub) $sub = wp_insert_user(['user_login'=>'plainsub','user_pass'=>wp_generate_password(),
  'user_email'=>'plainsub@example.test','role'=>'subscriber']);
CardsCo_Company::add_user($A['cid'],$sub,'member');
wp_set_current_user($sub); wp_cache_flush(); $_GET=[];
ck('linking somebody grants them the dashboard', CardsCo_Access::can_view(), true);

// now revoke: the link stays, the role goes
$u = get_userdata($sub);
$u->remove_role(CardsCo_Roles::COMPANY_ROLE);
wp_set_current_user(0); wp_set_current_user($sub); wp_cache_flush();
ck('the company link is still there', (int)$wpdb->get_var($wpdb->prepare(
   "SELECT company_id FROM {$wpdb->prefix}cardsco_company_users WHERE user_id=%d AND status='active'",$sub)), $A['cid']);
ck('but access resolves to nothing', CardsCo_Access::company_id(), 0);
ck('cannot view', CardsCo_Access::can_view(), false);
ck('told they lack access, not that they are unlinked',
   str_contains(CardsCo_Access::refusal(),'does not have access'));
// the point of gating at the source: the data layer refuses on its own,
// without every screen having to remember to ask first
ck('the data layer returns nothing', CardsCo_Dashboard::summary()['people'], 0);
ck('no programmes', CardsCo_Dashboard::programmes(), []);
ck('no campaigns', CardsCo_Dashboard::campaigns(), []);
ck('no boxes', CardsCo_Dashboard::boxes(), []);
ck('no upcoming', CardsCo_Dashboard::upcoming(400), []);
ck('no held cards', CardsCo_Dashboard::held(), []);
ck('no spend', CardsCo_Dashboard::spend_this_year(), '0.00');
ck('no activity', CardsCo_Dashboard::activity(), []);
ck('and nothing is reachable by id', CardsCo_Access::owned('programmes',$A['programme']), null);

sect('CardsCo staff may look at a named company');
$staff = username_exists('cardscostaff');
if(!$staff) $staff = wp_insert_user(['user_login'=>'cardscostaff','user_pass'=>wp_generate_password(),
  'user_email'=>'staff@example.test','role'=>CardsCo_Roles::STAFF_ROLE]);
wp_set_current_user($staff); wp_cache_flush();
$_GET = ['company'=>$B['cid']];
ck('staff can name a company', CardsCo_Access::company_id(), $B['cid']);
ck("and sees that company's people", CardsCo_Dashboard::summary()['people'], 2);
$_GET = ['company'=>$A['cid']];
ck('and can switch', CardsCo_Access::company_id(), $A['cid']);
$_GET = ['company'=>999999];
ck('a company that does not exist falls back', CardsCo_Access::company_id(), cardsco_get_user_company_id($staff));
$_GET = [];

sect('the shortcodes themselves');
// a PHP warning in a view goes to the log, not the page, so catch them here
$GLOBALS['vp'] = [];
function render($tag){
  $GLOBALS['vp'] = [];
  set_error_handler(function($n,$str,$f,$l){ $GLOBALS['vp'][] = sprintf('%s (%s:%d)',$str,basename($f),$l); return true; });
  $out = do_shortcode('['.$tag.']');
  restore_error_handler();
  return $out;
}
function vclean(){ return empty($GLOBALS['vp']) ? 'no warnings' : implode('; ',$GLOBALS['vp']); }
function vbalanced($h){ return substr_count($h,'<div') === substr_count($h,'</div>'); }

wp_set_current_user($A['uid']); wp_cache_flush(); $_GET=[];
foreach (['cardsco_dashboard','cardsco_occasions','cardsco_campaigns'] as $tag) {
  $html = render($tag);
  ck("$tag renders", sprintf('%s, divs %s, %s',
       strlen($html) > 200 ? 'has content' : 'TOO SHORT',
       vbalanced($html) ? 'balanced' : 'UNBALANCED', vclean()),
     'has content, divs balanced, no warnings');
}

$html = render('cardsco_dashboard');
ck('shows our own person', str_contains($html,'AnnA') || str_contains($html,'BoA'));
ck("never shows Beta's people", !str_contains($html,'AnnB') && !str_contains($html,'BoB'));
ck('shows the held card', str_contains($html,'NoAddr Person'));
ck('explains why it is held', str_contains($html,'Address incomplete'));
// The dashboard frame carries no money figure — its subtitle counts cards,
// not spend — so money is asserted where it actually appears instead.
ck('no money on the dashboard', !str_contains($html,'£'));
ck('money still formats with a symbol', str_contains(CardsCo_Shortcodes::money('1284.50','GBP'),'£'));

/* -------------------------------------------------------------------------
 * The dashboard is a grid of card tiles, per the measured frames: a page
 * header, a "Get started" band, a toolbar, then the tiles.
 * ---------------------------------------------------------------------- */

sect('the dashboard matches the frame it was built from');
ck('1120 content column via cc-main', str_contains($html,'cc-main'));
ck('page header', str_contains($html,'cc-page-title'));
ck('  titled as the frame titles it', str_contains($html,'Your Dashboard'));
// Singular and plural both, since the fixture company has sent exactly one.
ck('  subtitle counts cards', (bool)preg_match('/\d+ cards? sent this year/', $html));
ck('  and pluralises it', (bool)preg_match('/1 card sent this year/', $html));
ck('get started band', str_contains($html,'cc-band-head'));
ck('  with both promo cards', substr_count($html,'cc-promo"'), 2);
ck('  the envelope drawing is inlined', str_contains($html,'envelope') || str_contains($html,'<svg'));
ck('  and its text stays live, not outlined', str_contains($html,'EB Garamond'));
ck('toolbar', str_contains($html,'cc-toolbar'));
ck('  segmented control', str_contains($html,'cc-seg'));
foreach (['Drafts','Scheduled','Sent'] as $w) ck("    offers $w", str_contains($html,'>'.$w.'<'));
ck('  occasion filter', str_contains($html,'All occasions'));
ck('  sort control', str_contains($html,'Newest first'));
ck('divs balanced', vbalanced($html));
ck('no php warnings', vclean(), 'no warnings');

sect('the segmented control filters, and says which view is on');
$_GET = ['view'=>'drafts'];
$d = render('cardsco_dashboard');
ck('one view marked current', substr_count($d,'aria-current="true"'), 1);
ck('and it is Drafts', (bool)preg_match('/aria-current="true"[^>]*>\s*<span>Drafts/s', $d)
   || (bool)preg_match('/is-on[^>]*aria-current="true"[\s\S]{0,120}Drafts/', $d));
$_GET = ['view'=>'nonsense'];
$d2 = render('cardsco_dashboard');
ck('an unknown view falls back to All', substr_count($d2,'aria-current="true"'), 1);
ck('no php warnings', vclean(), 'no warnings');
$_GET = [];

sect('a card tile carries what the frame shows');
$tiles = render('cardsco_dashboard');
if (str_contains($tiles,'cc-ctile ')) {
  ck('the paper', str_contains($tiles,'cc-ctile-paper'));
  ck('a status badge', str_contains($tiles,'cc-badge'));
  ck('the name', str_contains($tiles,'cc-ctile-name'));
  ck('the card count', str_contains($tiles,'cc-ctile-count'));
}
ck('and a create-a-card tile', str_contains($tiles,'cc-ctile-new') || str_contains($tiles,'cc-empty'));

sect('the message is split into body and signature');
$m = CardsCo_Shortcodes::card_message(['message'=>"Happy birthday Tunde!\n— Ngozi"]);
ck('body', $m['body'], 'Happy birthday Tunde!');
ck('signature', $m['sign'], '— Ngozi');
$m2 = CardsCo_Shortcodes::card_message(['message'=>'']);
ck('nothing written gives nothing', $m2['body'], '');
$m3 = CardsCo_Shortcodes::card_message(['message'=>str_repeat('word ',40)]);
ck('a long message is clamped', mb_strlen($m3['body']) <= 90);

sect('badges speak both vocabularies');
ck('a draft campaign', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'draft'])['label'], 'Draft');
ck('a sent campaign is success', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'sent'])['tone'], 'success');
ck('a queued card is info', CardsCo_Shortcodes::badge(['kind'=>'card','status'=>'queued'])['tone'], 'info');
ck('a held card warns', CardsCo_Shortcodes::badge(['kind'=>'card','status'=>'on_hold'])['tone'], 'warning');
ck('an unknown status still renders', CardsCo_Shortcodes::badge(['kind'=>'card','status'=>'weird'])['tone'], 'neutral');

sect('art() will not read anything it was not given');
ck('a known drawing comes back', str_contains(CardsCo_Shortcodes::art('envelope-open'),'<svg'));
ck('a path traversal does not', CardsCo_Shortcodes::art('../../../wp-config'), '');
ck('nor an unknown name', CardsCo_Shortcodes::art('nope'), '');

/* -------------------------------------------------------------------------
 * Occasions, built against frame 253:56252. The strings below are taken
 * verbatim from the frame's TEXT nodes — a mechanical completeness check,
 * so an element I simply failed to notice shows up as a failure rather than
 * as something nobody looks for.
 * ---------------------------------------------------------------------- */

sect('the occasions screen carries every string the frame has');
$html = render('cardsco_occasions');
foreach ([
  'Occasions',
  'Cards that go out automatically, every time the date comes round.',
  'Set up an occasion',
  'Christmas &amp; Seasonal',
  'Stand out from the inbox when it matters most.',
  'Employee Birthdays',
  'Upload your team once. Cards go out on the day.',
  'Thank-You',
  'Say thanks after a purchase or a referral, and invite them back.',
  'Customer Milestones',
  'New customer welcomes and client anniversaries.',
  'Work Anniversaries',
  'Mark every year someone has been with you.',
  'Use a milestone card for now',
  'Promotions &amp; Offers',
  'Prospect follow-ups, product launches and offer codes.',
  'Coming soon',
  'Coming up',
  'Next 30 days',
  'See all dates',
] as $needle) {
  ck('  "'.mb_strimwidth($needle,0,40,'…').'"', str_contains($html,$needle));
}

sect('and the structure the frame gives it');
ck('six occasion rows', substr_count($html,'<article class="cc-occ'), 6);
ck('two columns', str_contains($html,'cc-occ-cols'));
ck('the coming-up panel', str_contains($html,'cc-coming'));
ck('art wells', substr_count($html,'cc-occ-art"'), 6);
ck('a coming-soon row is muted', str_contains($html,'cc-occ is-soon'));
ck('  and has no button', substr_count($html,'cc-occ-btn'), 5);
ck('pills carry no dot, unlike status badges', !str_contains($html,'cc-pill"><span class="cc-badge-dot"'));
ck('divs balanced', vbalanced($html));
ck('no php warnings', vclean(), 'no warnings');
ck("still no sign of Beta", !str_contains($html,'AnnB') && !str_contains($html,'BoB'));

sect('badge labels are darker than their dots, as measured');
$css = file_get_contents('/home/claude/cardsco/assets/dashboard.css');
ck('info label', str_contains($css,'.cc-badge.is-info    { background: var(--cc-status-info-bg);    color: var(--cc-blue-700); }'));
ck('info dot keeps the brighter solid', str_contains($css,'.cc-badge.is-info .cc-badge-dot    { background: var(--cc-status-info-solid); }'));
$tok = file_get_contents('/home/claude/cardsco/assets/tokens.css');
ck('the measured label blue', str_contains($tok,'#1D4FA3'));
ck('the measured label green', str_contains($tok,'#17663A'));

/* -------------------------------------------------------------------------
 * Campaigns, rebuilt against frames 7:4419 and 14:14055: a tabbed list of
 * rows with cover art, a progress bar and a per-state button.
 * ---------------------------------------------------------------------- */

sect('the campaigns screen matches the frame it was built from');
$html = render('cardsco_campaigns');
ck('page header', str_contains($html,'cc-page-title'));
ck('  titled as the frame titles it', str_contains($html,'Campaigns'));
// 'class="cc-tab' also matches cc-tab-count, so count the anchors.
ck('three tabs', substr_count($html,'<a class="cc-tab'), 3);
foreach (['In progress','Drafts','Past'] as $t) ck("  offers $t", str_contains($html,'>'.$t.'<'));
ck('one tab is current', substr_count($html,'aria-current="page"'), 1);
ck('a New campaign button', str_contains($html,'New campaign'));
ck('divs balanced', vbalanced($html));
ck('no php warnings', vclean(), 'no warnings');

sect('a draft sits under Drafts, not under In progress');
// CampA has not been scheduled yet, so the default tab should not show it.
ck('not in the default tab', !str_contains($html,'CampA'));
$_GET = ['tab'=>'drafts'];
$drafts = render('cardsco_campaigns');
$_GET = [];
ck('lists our campaign', str_contains($drafts,'CampA'));
ck('with a draft badge', str_contains($drafts,'is-outline'));
ck('and the review button', str_contains($drafts,'Review and schedule'));
ck('still nothing of Beta', !str_contains($drafts,'CampB'));
ck('divs balanced', vbalanced($drafts));
ck('no php warnings', vclean(), 'no warnings');

sect('a campaign row carries what the frame shows');
ck('the cover well', str_contains($drafts,'cc-camp-cover'));
ck('the name', str_contains($drafts,'cc-camp-name'));
ck('the meta line', str_contains($drafts,'cc-camp-meta'));
ck('a progress block', str_contains($drafts,'cc-camp-progress'));
ck('  as a real progressbar', str_contains($drafts,'role="progressbar"'));
ck('  with bounds', str_contains($drafts,'aria-valuemin="0"') && str_contains($drafts,'aria-valuemax="100"'));
ck('a draft needs review', str_contains($drafts,'Needs your review'));
ck('  and the row is marked', str_contains($drafts,'needs-review'));
// Things the frame has that the first build left out.
ck('the "more" control the frame puts on every row',
   substr_count($drafts,'cc-camp-more'), substr_count($drafts,'<article class="cc-camp'));
ck('  and it is labelled, not a bare glyph', str_contains($drafts,'aria-label="Open '));
ck('the tab count is a pill, not loose text', str_contains($drafts,'class="cc-tab-count"'));
ck('the cover names the card when there is no artwork', str_contains($drafts,'cc-camp-cover-blank'));

sect('the empty state uses the measured drawing');
ck('art() will serve it', str_contains(CardsCo_Shortcodes::art('empty-no-content'),'<svg'));
ck('  at the size the frame draws it', str_contains(CardsCo_Shortcodes::art('empty-no-content'),'width="204" height="153"'));

sect('an unknown tab falls back rather than emptying the screen');
$_GET = ['tab'=>'nonsense'];
$odd = render('cardsco_campaigns');
$_GET = [];
ck('still one current tab', substr_count($odd,'aria-current="page"'), 1);
ck('no php warnings', vclean(), 'no warnings');

sect('the progress column says the right thing per state');
$p_draft = CardsCo_Shortcodes::campaign_progress(['status'=>'draft','total'=>10,'written'=>0,'holds'=>3,'send_date'=>'','lock_date'=>'']);
ck('a draft asks for review', $p_draft['label'], 'Needs your review');
ck('  counts who has no address', str_contains($p_draft['note'],'3 recipients have no address'));
ck('  and has no bar', $p_draft['percent'], '');
ck('  and is flagged', $p_draft['attention'], true);
$p_sched = CardsCo_Shortcodes::campaign_progress(['status'=>'scheduled','total'=>10,'written'=>0,'holds'=>0,'send_date'=>'2026-12-01','lock_date'=>'2026-11-24']);
ck('a scheduled one gives both dates', str_contains($p_sched['note'],'Posts') && str_contains($p_sched['note'],'edit until'));
ck('  and still no bar', $p_sched['percent'], '');
$p_live = CardsCo_Shortcodes::campaign_progress(['status'=>'locked','total'=>250,'written'=>180,'holds'=>0,'send_date'=>'2026-10-20','lock_date'=>'']);
ck('a live one is a percentage', $p_live['percent'], '72');
ck('  and counts the cards', str_contains($p_live['note'],'180 of 250 written'));
$p_zero = CardsCo_Shortcodes::campaign_progress(['status'=>'locked','total'=>0,'written'=>0,'holds'=>0,'send_date'=>'','lock_date'=>'']);
ck('an empty audience does not divide by zero', $p_zero['percent'], '0');

sect('the offer line reads as the frame writes it');
ck('both parts', CardsCo_Shortcodes::offer_words(['description'=>'15% off','code'=>'ACME15']), '15% off with code ACME15');
ck('description only', CardsCo_Shortcodes::offer_words(['description'=>'20% off for 48 hours','code'=>'']), '20% off for 48 hours');
ck('code only', CardsCo_Shortcodes::offer_words(['description'=>'','code'=>'ACME15']), 'Code ACME15');
ck('neither still says something', CardsCo_Shortcodes::offer_words([]), 'Offer included');

sect('the button follows the state');
$L = ['one'=>'/portal/campaigns'];
ck('a draft is reviewed', CardsCo_Shortcodes::campaign_action(['id'=>1,'status'=>'draft'],$L)['label'], 'Review and schedule');
ck('  and it is the primary action', CardsCo_Shortcodes::campaign_action(['id'=>1,'status'=>'draft'],$L)['primary'], true);
ck('a scheduled one is edited', CardsCo_Shortcodes::campaign_action(['id'=>1,'status'=>'scheduled'],$L)['label'], 'Edit campaign');
ck('a live one is watched', CardsCo_Shortcodes::campaign_action(['id'=>1,'status'=>'locked'],$L)['label'], 'View progress');
ck('and the link carries the id', str_contains(CardsCo_Shortcodes::campaign_action(['id'=>7,'status'=>'draft'],$L)['url'],'campaign=7'));

sect('badge tones are the ones the frame uses');
ck('draft is an outline', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'draft'])['tone'], 'outline');
ck('scheduled is neutral, not blue', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'scheduled'])['tone'], 'neutral');
ck('locked reads Queued', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'locked'])['label'], 'Queued');
ck('sent reads Delivered', CardsCo_Shortcodes::badge(['kind'=>'campaign','status'=>'sent'])['label'], 'Delivered');
ck("not Beta's", !str_contains($html,'CampB'));

sect('what a stranger sees');
wp_set_current_user(0); wp_cache_flush(); $_GET=[];
foreach (['cardsco_dashboard','cardsco_occasions','cardsco_campaigns'] as $tag) {
  $html = render($tag);
  ck("$tag is locked", str_contains($html,'cc-locked'));
  ck("  and leaks no names", !str_contains($html,'AnnA') && !str_contains($html,'AnnB')
     && !str_contains($html,'CampA') && !str_contains($html,'NoAddr'));
  ck("  and says to sign in", str_contains($html,'sign in'));
}

sect('a stranger cannot conjure data with a url');
$_GET = ['company'=>$A['cid']];
$html = render('cardsco_dashboard');
ck('still locked', str_contains($html,'cc-locked'));
ck('still no names', !str_contains($html,'AnnA'));
$_GET = [];

sect('the sidebar');
wp_set_current_user($A['uid']); wp_cache_flush(); $_GET=[];
$_SERVER['REQUEST_URI'] = '/dashboard/';
$html = render('cardsco_nav');
ck('renders', strlen($html) > 400);
ck('divs balanced', vbalanced($html));
ck('no php warnings', vclean(), 'no warnings');
ck('all four items', substr_count($html,'cc-nav-link'), 4);
foreach (['Dashboard','Occasions','Campaigns','Orders'] as $w) ck("  lists $w", str_contains($html,'>'.$w.'<'));
ck('the current page is marked', str_contains($html,'aria-current="page"'));
ck('and only once', substr_count($html,'aria-current="page"'), 1);
ck('it is the dashboard link', (bool)preg_match('/Dashboard.*?aria-current|aria-current[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<span class="cc-nav-label">Dashboard/s', $html));

sect('the badge counts campaigns needing review, not all of them');
// Alpha has one campaign, still a draft
ck('one draft', CardsCo_Dashboard::campaigns_needing_review(), 1);
ck('badge shown', str_contains($html,'cc-nav-count'));
ck('and reads 1', (bool)preg_match('/cc-nav-count">\s*1/', $html));
// schedule it: it is no longer waiting on anybody
$c = CardsCo_Campaign::get($A['campaign']);
CardsCo_Campaign::update($A['campaign'], ['send_date'=>gmdate('Y-m-d', strtotime('+40 days'))]);
$ok = CardsCo_Campaign::schedule($A['campaign']);
ck('scheduled it', $ok, true);
ck('nothing needs review now', CardsCo_Dashboard::campaigns_needing_review(), 0);
$html2 = render('cardsco_nav');
ck('badge gone', str_contains($html2,'cc-nav-count'), false);

sect('recent campaigns, with what each is doing');
ck('lists the campaign', str_contains($html2,'CampA'));
ck('says it is scheduled', str_contains($html2,'Scheduled · posts'));
ck("never shows Beta's", !str_contains($html2,'CampB'));

sect('the active mark follows the page');
$_SERVER['REQUEST_URI'] = '/campaigns/';
$html3 = render('cardsco_nav');
ck('still one current page', substr_count($html3,'aria-current="page"'), 1);
ck('and it moved to Campaigns',
   (bool)preg_match('/aria-current="page"[^>]*>.*?Campaigns/s', $html3));
$_SERVER['REQUEST_URI'] = '/something-else/';
$html4 = render('cardsco_nav');
ck('nothing marked on an unrelated page', substr_count($html4,'aria-current="page"'), 0);
$_SERVER['REQUEST_URI'] = '/dashboard/';

sect('the logo');
wp_set_current_user($A['uid']); wp_cache_flush(); $_GET=[]; $_SERVER['REQUEST_URI']='/dashboard/';
$plain = render('cardsco_nav');
ck('the masthead is there', str_contains($plain,'cc-nav-logo'));
/* With nothing passed, the sidebar now falls back to the logo that ships
 * with the plugin rather than to a text wordmark, so a site that has set
 * nothing up is still branded. A text fallback was the old behaviour. */
ck('with no attribute it uses the bundled logo', str_contains($plain,'assets/img/logo.svg'));
ck('and it is a real image, not a broken one', str_contains($plain,'cc-nav-wordmark'));
/* This passed before purely because the class name was present; it has to
 * actually name the link for somebody who cannot see it. */
ck('still named for a screen reader with no attribute',
   (bool) preg_match('/cc-nav-logo"[^>]*aria-label="[^"]+"/', $plain));

$withlogo = do_shortcode('[cardsco_nav logo="https://example.test/their-logo.png" logo_alt="CardsCo"]');
ck('a logo of their own wins', str_contains($withlogo,'their-logo.png'));
ck('and the bundled one is then not used', str_contains($withlogo,'assets/img/logo.svg'), false);
// The name is on the link, not the image, because the rail swaps the image out.
ck('named for a screen reader', str_contains($withlogo,'aria-label="CardsCo"'));
ck('and it links to the dashboard', (bool)preg_match('/cc-nav-logo" href="[^"]*dashboard/', $withlogo));
ck('a script tag in the url cannot get through',
   !str_contains(do_shortcode('[cardsco_nav logo="javascript:alert(1)"]'), 'javascript:'));

sect('the square mark, which is what the rail shows');
ck('not there unless given', !str_contains($withlogo,'cc-nav-mark'));
ck('and the nav is not flagged as having one', !str_contains($withlogo,'has-mark'));
$marked = do_shortcode('[cardsco_nav logo="https://example.test/logo.svg" logo_mark="https://example.test/c.svg" logo_alt="CardsCo"]');
ck('there when given', str_contains($marked,'cc-nav-mark'));
ck('nav flagged so the rail can show it', str_contains($marked,'cc-nav has-mark'));
ck('both images present', str_contains($marked,'logo.svg') && str_contains($marked,'c.svg'));
ck('neither duplicates the name', substr_count($marked,'alt=""'), 2);
ck('a script url cannot get through here either',
   !str_contains(do_shortcode('[cardsco_nav logo_mark="javascript:alert(1)"]'), 'javascript:'));

sect('the new campaign button');
ck('shown beside the heading', (bool)preg_match('/cc-nav-heading-actions/', $plain));
ck('points at campaigns', (bool)preg_match('/href="[^"]*campaigns[^"]*new=1"/', $plain));
ck('has a label for screen readers', str_contains($plain,'Start a campaign'));

/* -------------------------------------------------------------------------
 * The header, section and footer actions, straight off the Side Bar frame:
 *   header   logo · search · collapse
 *   section  "Recent campaigns" · search · + · …
 *   footer   notifications · settings · help   (+ upgrade link)
 * ---------------------------------------------------------------------- */

sect('the header actions');
ck('a search button', str_contains($plain,'data-cc-find-toggle'));
ck('a collapse button', str_contains($plain,'data-cc-collapse'));
ck('the search box it opens', str_contains($plain,'id="ccNavFind"'));
ck('  which starts closed', (bool)preg_match('/id="ccNavFind" hidden/', $plain));
ck('  and is wired to the buttons',
   (bool)preg_match('/data-cc-find-toggle\s+aria-controls="ccNavFind"/', $plain));
ck('collapse says the sidebar is open', (bool)preg_match('/data-cc-collapse[^>]*aria-expanded="true"/', $plain));

sect('and they can be turned off');
$bare = do_shortcode('[cardsco_nav search="no" collapsible="no"]');
ck('no search', !str_contains($bare,'data-cc-find-toggle'));
ck('no search box either', !str_contains($bare,'id="ccNavFind"'));
ck('no collapse', !str_contains($bare,'data-cc-collapse'));
ck('the nav itself is untouched', substr_count($bare,'cc-nav-link'), 4);
ck('divs balanced', vbalanced($bare));
ck('no php warnings', vclean(), 'no warnings');

sect('the "..." menu');
ck('a button for it', str_contains($plain,'data-cc-more-toggle'));
ck('it claims a menu', (bool)preg_match('/data-cc-more-toggle[^>]*aria-haspopup="true"/', $plain));
ck('the menu exists', str_contains($plain,'id="ccNavMore"'));
ck('  and starts closed', (bool)preg_match('/id="ccNavMore" hidden/', $plain));
ck('  with somewhere to go', str_contains($plain,'View all campaigns'));

sect('the footer icons only appear when given an address');
ck('no footer by default', !str_contains($plain,'cc-nav-foot'));
$full = do_shortcode('[cardsco_nav settings="/portal/settings" help="/help" notifications="/portal/alerts" upgrade="/pricing"]');
ck('footer now there', str_contains($full,'cc-nav-foot'));
ck('settings linked', str_contains($full,'/portal/settings'));
ck('help linked', str_contains($full,'/help'));
ck('notifications linked', str_contains($full,'/portal/alerts'));
ck('upgrade linked', str_contains($full,'/pricing'));
ck('each is labelled', substr_count($full,'aria-label="Notifications"'), 1);
ck('divs balanced', vbalanced($full));
ck('no php warnings', vclean(), 'no warnings');

sect('one icon on its own does not drag the others in');
$only = do_shortcode('[cardsco_nav help="/help"]');
ck('footer there', str_contains($only,'cc-nav-foot'));
ck('help there', str_contains($only,'aria-label="Help"'));
ck('no settings', !str_contains($only,'aria-label="Settings"'));
ck('no bell', !str_contains($only,'aria-label="Notifications"'));
ck('no upgrade', !str_contains($only,'cc-nav-upgrade'));

sect('settings is marked when you are on it');
$_SERVER['REQUEST_URI'] = '/portal/settings/';
$onset = do_shortcode('[cardsco_nav settings="/portal/settings"]');
ck('marked current', (bool)preg_match('/cc-nav-ibtn is-here/', $onset));
$_SERVER['REQUEST_URI'] = '/dashboard/';
$offset = do_shortcode('[cardsco_nav settings="/portal/settings"]');
ck('and not when you are not', !str_contains($offset,'cc-nav-ibtn is-here'));

sect('the icons are the ones the design uses');
// Lucide, drawn inline so they take the hover colour. The nav items are
// layout-dashboard / calendar / megaphone / package, not my earlier guesses.
ck('layout-dashboard', str_contains($plain,'<rect width="7" height="9" x="3" y="3" rx="1"/>'));
ck('calendar', str_contains($plain,'<path d="M8 2v4"/>'));
ck('megaphone', str_contains($plain,'<path d="M8 6v8"/>'));
ck('package', str_contains($plain,'<path d="M12 22V12"/>'));
ck('search', str_contains($plain,'<circle cx="11" cy="11" r="8"/>'));
ck('panel-left', str_contains($plain,'<path d="M9 3v18"/>'));
ck('settings', str_contains($full,'<circle cx="12" cy="12" r="3"/>'));
ck('bell', str_contains($full,'M10.268 21a2 2 0 0 0 3.464 0'));
ck('circle-help', str_contains($full,'M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3'));
ck('all on the same 24 grid', substr_count($plain,'viewBox="0 0 24 24"') >= 8);
ck('none of them announced to a screen reader',
   substr_count($plain,'<svg'), substr_count($plain,'aria-hidden="true"'));

sect('everything the search filters is findable');
// The filter reads data-cc-findable, so anything it should match needs one.
ck('the four nav items', substr_count($plain,'data-cc-findable') >= 4);
ck('and the recent campaigns', (bool)preg_match('/cc-nav-recent[\s\S]*?data-cc-findable/', $plain));
ck('with an empty state to show', str_contains($plain,'data-cc-find-empty'));
ck('  which starts hidden', (bool)preg_match('/data-cc-find-empty hidden/', $plain));

sect('a yes/no attribute takes what an Oxygen user would type');
foreach (['no','No','FALSE','0','off',''] as $v) {
	ck("  '$v' means no", CardsCo_Shortcodes::yes($v), false);
}
foreach (['yes','true','1','on','anything'] as $v) {
	ck("  '$v' means yes", CardsCo_Shortcodes::yes($v), true);
}

sect('custom page addresses');
$_GET=[];
$custom = do_shortcode('[cardsco_nav dashboard="/portal" occasions="/portal/occasions" campaigns="/portal/sends" orders="/portal/bills"]');
ck('uses the addresses given', str_contains($custom,'/portal/sends'));
ck('and not the defaults', !str_contains($custom,'/campaigns/'));

sect('a stranger gets no menu at all');
wp_set_current_user(0); wp_cache_flush();
$out = render('cardsco_nav');
ck('no menu rendered', !str_contains($out,'cc-nav-link'));
ck('no names leaked', !str_contains($out,'CampA') && !str_contains($out,'Dashboard'));
// a page builder shows blank either way, so the source says which it was
ck('but the source says why', str_contains($out,'sidebar hidden'));
ck('and it is only a comment', trim($out)[0], '<');
ck('nothing visible in it', strip_tags($out), '');

printf("\n%s\n", $fail ? "$fail FAILED" : 'all passed');
exit($fail?1:0);


/* -------------------------------------------------------------------------
 * The set-up wizard, frames 253:56833 (step 1) and 253:56964 (step 2).
 * Strings taken verbatim from the frames.
 * ---------------------------------------------------------------------- */

sect('the wizard shell');
$wz = render('cardsco_setup');
foreach (['Save and exit','Step 1 of 7','Which occasion?','Your occasion','Price per card','Back','Continue'] as $n) {
  ck('  "'.$n.'"', str_contains($wz,$n));
}
ck('seven steps in the bar', substr_count($wz,'cc-wz-step '), 7);
ck('one marked current', substr_count($wz,'aria-current="step"'), 1);
ck('seven rows in the rail', substr_count($wz,'cc-wz-rail-row'), 7);
ck('divs balanced', vbalanced($wz));
ck('no php warnings', vclean(), 'no warnings');

sect('step 1 offers the catalogue');
foreach (['Christmas &amp; Seasonal','Employee Birthdays','Thank-You','Customer Milestones','Work Anniversaries','Promotions &amp; Offers'] as $n) {
  ck('  '.strip_tags($n), str_contains($wz,$n));
}
ck('six choices', substr_count($wz,'class="cc-pick'), 6);
ck('the coming-soon one cannot be picked', str_contains($wz,'disabled'));
ck('nothing chosen yet, so Continue is off', str_contains($wz,'<button type="submit" form="ccWizForm" class="cc-btn cc-btn-primary cc-btn-r8" disabled>'));

sect('a step cannot be reached before it is unlocked');
$_GET = ['step'=>'5'];
$ahead = render('cardsco_setup');
$_GET = [];
ck('asking for step 5 lands on step 1', str_contains($ahead,'Step 1 of 7'));
ck('no php warnings', vclean(), 'no warnings');

sect('the copy matches the frame, contractions included');
ck('the wizard says "they\u2019re written" not "they are"',
   !str_contains($wz,'as they are written'));
