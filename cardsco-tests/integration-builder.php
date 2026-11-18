<?php
/** The campaign builder, driven through its real handlers and screens. */
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/wp-admin/admin.php'; $_SERVER['REQUEST_METHOD']='POST';
define('WP_ADMIN', true);
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
require_once ABSPATH.'wp-content/plugins/cardsco/includes/admin/class-cardsco-campaign-builder.php';
wp_set_current_user(1); set_current_screen('toplevel_page_cardsco');
global $wpdb;
$fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-50s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

class Redirected extends Exception { public $url; public function __construct($u){ $this->url=$u; parent::__construct('redirect'); } }
add_filter('wp_redirect', function($loc){ throw new Redirected($loc); }, 1);
function post(callable $run, array $data){
  $_POST = $data; $_POST['_wpnonce'] = wp_create_nonce( CardsCo_Admin::NONCE );
  $_REQUEST = $_POST; $_GET = [];
  try { $run(); } catch (Redirected $r) { return $r->url; }
  catch (Throwable $e) { return 'THREW: '.get_class($e).' '.$e->getMessage(); }
  return 'no redirect';
}
function arg($url,$key){ $q=[]; parse_str((string)parse_url($url,PHP_URL_QUERY)??'',$q); return $q[$key] ?? null; }
function screen(callable $run, array $get){
  $_GET = $get; $_REQUEST = $get; $_POST = [];
  ob_start(); try { $run(); } catch (Throwable $e) { ob_end_clean(); return 'THREW: '.$e->getMessage(); }
  return (string) ob_get_clean();
}
function balanced($h){ return substr_count($h,'<div') === substr_count($h,'</div>'); }

CardsCo_Schema::maybe_install(true); CardsCo_Category::backfill();

$cid = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Builder Test Co'));
if(!$cid){ $c=CardsCo_Company::create(['name'=>'Builder Test Co']); $cid=is_wp_error($c)?0:(int)$c; }
foreach(['occasions','recipients','card_queue','campaigns','recipient_segments','segments'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('clean company', $cid>0);

$type = CardsCo_Occasion_Type::get_by_slug('christmas');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Winter Scene','post_status'=>'publish'],true);
CardsCo_Card::set_occasions($card, [ (int)$type['id'] ]);

$people = [];
foreach ([['Ada','Nwosu'],['Ben','Clarke'],['Cara','Diaz']] as $i=>$p) {
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'customer','first_name'=>$p[0],'last_name'=>$p[1],
    'address'=>(5+$i)." Mill Lane\nLeeds",'postcode'=>'LS6 3AA']);
  if (!is_wp_error($rid)) $people[] = $rid;
}
$seg = CardsCo_Segment::create(['company_id'=>$cid,'name'=>'Newsletter list','applies_to'=>'customer']);
CardsCo_Segment::add_recipients($seg,$people);
ck('three people in a group', count($people), 3);

$b = new CardsCo_Campaign_Builder();

sect('the list screen');
$html = screen(function() use ($b) { $b->render(new CardsCo_Admin()); }, ['page'=>'cardsco-campaigns','company'=>$cid]);
ck('renders', strlen($html) > 500);
ck('divs balanced', balanced($html));
ck('explains what a campaign is for', str_contains($html,'one send on a day you choose'));
ck('offers to start one', str_contains($html,'Start a campaign'));
ck('no php warnings', !str_contains($html,'Warning:') && !str_contains($html,'Notice:'));

sect('starting one');
$url = post([$b,'handle_start'], ['company_id'=>$cid,'name'=>'Christmas 2026']);
$camp = (int) arg($url,'campaign');
ck('created and sent to step 1', $camp > 0 && (int)arg($url,'step') === 1);
ck('a missing company is refused', str_contains((string)arg(post([$b,'handle_start'],['company_id'=>0]),'cardsco_error'),'company'));

sect('walking the steps through the handlers');
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>1,'name'=>'Christmas 2026','occasion_type_id'=>$type['id']]);
ck('step 1 saved, on to 2', (int)arg($url,'step'), 2);
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>2,'segments'=>[$seg]]);
ck('step 2 saved, on to 3', (int)arg($url,'step'), 3);
ck('audience counted', (int)CardsCo_Campaign::get($camp)['recipient_count'], 3);
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>3,'card_id'=>$card]);
ck('step 3 saved, on to 4', (int)arg($url,'step'), 4);
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>4,'message_body'=>'Season\'s greetings, {first name}.']);
ck('step 4 saved, on to 5', (int)arg($url,'step'), 5);
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>5,'offer_enabled'=>'1','offer_code'=>'XMAS10','offer_expires'=>'2027-01-31']);
ck('step 5 saved, on to 6', (int)arg($url,'step'), 6);
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>6,'send_date'=>'2027-12-18']);
ck('step 6 saved, on to 7', (int)arg($url,'step'), 7);

$c = CardsCo_Campaign::get($camp);
ck('occasion stored', (int)$c['occasion_type_id'], (int)$type['id']);
ck('card stored', (int)$c['card_id'], (int)$card);
ck('wording stored', str_contains($c['message_body'],'Season'));
ck('offer stored', $c['offer_code'], 'XMAS10');
ck('send date stored', $c['send_date'], '2027-12-18');
ck('lock date derived', $c['lock_date'], '2027-12-15');
ck('progress remembered', (int)$c['builder_step'], 7);
ck('still a draft', $c['status'], 'draft');

sect('going back does not lose progress');
post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>3,'card_id'=>$card]);
ck('still reached step 7', (int)CardsCo_Campaign::get($camp)['builder_step'], 7);

sect('every screen renders');
foreach ([1,2,3,4,5,6,7] as $s) {
  $html = screen(function() use ($b) { $b->render(new CardsCo_Admin()); },
    ['page'=>'cardsco-campaigns','campaign'=>$camp,'step'=>$s]);
  ck("step $s", sprintf('%5d bytes, divs %s', strlen($html), balanced($html)?'balanced':'UNBALANCED'),
     sprintf('%5d bytes, divs balanced', strlen($html)));
}
$html = screen(function() use ($b) { $b->render(new CardsCo_Admin()); },
  ['page'=>'cardsco-campaigns','campaign'=>$camp,'step'=>7]);
ck('review shows the merged wording', str_contains($html,'Season'));
ck('review shows the offer code', str_contains($html,'XMAS10'));
ck('review offers to schedule', str_contains($html,'Schedule it'));
ck('side panel shows the cost', str_contains($html,'each)'));

sect('scheduling it through the handler');
$url = post([$b,'handle_schedule'], ['campaign_id'=>$camp]);
ck('said how many and when', str_contains((string)arg($url,'cardsco_message'),'3 cards will arrive on 18 December 2027'));
ck('status scheduled', CardsCo_Campaign::get($camp)['status'], 'scheduled');

sect('an unfinished campaign cannot be scheduled');
$half = CardsCo_Campaign::create($cid,'Half done');
post([$b,'handle_step'], ['campaign_id'=>$half,'step'=>1,'name'=>'Half done','occasion_type_id'=>$type['id']]);
$url = post([$b,'handle_schedule'], ['campaign_id'=>$half]);
$err = (string) arg($url,'cardsco_error');
ck('refused', $err !== '');
ck('and lists what is missing', str_contains($err,'is not finished'));
ck('still a draft', CardsCo_Campaign::get($half)['status'], 'draft');
$html = screen(function() use ($b) { $b->render(new CardsCo_Admin()); },
  ['page'=>'cardsco-campaigns','campaign'=>$half,'step'=>7]);
ck('the review screen says so too', str_contains($html,'Not quite ready'));
ck('and the button is disabled', str_contains($html,'disabled'));

sect('once it locks the builder refuses edits');
CardsCo_Campaign::run(['company_id'=>$cid,'today'=>'2027-12-15']);
ck('it locked', CardsCo_Campaign::get($camp)['status'], 'locked');
$url = post([$b,'handle_step'], ['campaign_id'=>$camp,'step'=>4,'message_body'=>'Different now']);
ck('edit refused', str_contains((string)arg($url,'cardsco_error'),'with the writers'));
ck('wording unchanged', str_contains(CardsCo_Campaign::get($camp)['message_body'],'Season'));
$html = screen(function() use ($b) { $b->render(new CardsCo_Admin()); },
  ['page'=>'cardsco-campaigns','campaign'=>$camp,'step'=>4]);
ck('the screen explains the freeze', str_contains($html,'with the writers'));
ck('and offers no save button', !str_contains($html,'Save and carry on'));

sect('cancelling from the list');
$url = post([$b,'handle_cancel'], ['campaign_id'=>$half]);
ck('cancelled', str_contains((string)arg($url,'cardsco_message'),'cancelled'));
ck('status cancelled', CardsCo_Campaign::get($half)['status'], 'cancelled');

printf("\n%s\n", $fail ? "$fail FAILED" : 'all passed');
exit($fail?1:0);
