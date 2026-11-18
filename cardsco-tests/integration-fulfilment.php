<?php
/** Fulfilment: moving a box along, and the screens the team work from. */
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/wp-admin/admin.php'; $_SERVER['REQUEST_METHOD']='POST';
define('WP_ADMIN', true);
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/admin.php';
require_once ABSPATH.'wp-content/plugins/cardsco/includes/admin/class-cardsco-fulfilment.php';
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
/** Render a screen and hand back the HTML. */
function screen(callable $run, array $get){
  $_GET = $get; $_REQUEST = $get; $_POST = [];
  ob_start(); try { $run(); } catch (Throwable $e) { ob_end_clean(); return 'THREW: '.$e->getMessage(); }
  return (string) ob_get_clean();
}
function balanced($html){ return substr_count($html,'<div') === substr_count($html,'</div>'); }

CardsCo_Schema::maybe_install(true);
CardsCo_Category::backfill();

$cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cardsco_companies WHERE name=%s",'Fulfilment Test Co'));
if(!$cid){ $c = CardsCo_Company::create(['name'=>'Fulfilment Test Co']); $cid = is_wp_error($c)?0:(int)$c; }
foreach(['occasions','programme_recipients','programmes','recipients','card_queue','boxes'] as $t)
  $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cardsco_$t WHERE company_id=%d",$cid));
wp_cache_flush();
ck('clean company', $cid>0);

$bday = CardsCo_Category::primary_type('employee-birthdays');
$card = wp_insert_post(['post_type'=>'cardsco_card','post_title'=>'Flat Illustration','post_status'=>'publish'],true);

// four people with November birthdays; one of them has no address, but the box
// ships to the office so that must not matter
$pid = CardsCo_Programme::create($cid,'employee-birthdays');
$ids = [];
foreach ([['Tunde','Bakare',14],['Hannah','Lee',3],['Priya','Shah',28],['Marcus','Reid',9]] as $i=>$p) {
  list($first,$last,$d) = $p;
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$first,
    'last_name'=>$last,'address'=>(20+$i)." Test Road\nLeeds",'postcode'=>'LS2 7EH']);
  if (is_wp_error($rid)) continue;
  $ids[] = $rid;
  CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>11,'event_day'=>$d]);
}
CardsCo_Programme::add_recipients($pid,$ids);
CardsCo_Programme::update($pid,[
  'card_id'=>$card,'message_override'=>'Happy birthday {first name}!','handwriting'=>'Arthur',
  'ink'=>'blue','signature'=>'Everyone at Acme','shipping_mode'=>'company',
  'ship_address'=>"Acme House\n1 Office Way\nLeeds",'ship_postcode'=>'LS1 4AP',
  'timing_mode'=>'monthly_box','box_arrive_day'=>25,'weekend_rule'=>1,
  'starts_on'=>'2026-11-01','setup_step'=>7]);
CardsCo_Programme::activate($pid);
CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-10-16']);
$box = CardsCo_Box::find($pid,'2026-11');
ck('a filled box to work with', (int)$box['card_count'], 4);

$f = new CardsCo_Fulfilment();

sect('the boxes screen');
$html = screen(function() use ($f) { $f->render(new CardsCo_Admin()); }, ['page'=>'cardsco-boxes','company'=>$cid]);
ck('renders', strlen($html) > 500);
ck('divs balanced', balanced($html));
ck('names the company', str_contains($html,'Fulfilment Test Co'));
ck('shows the month', str_contains($html,'November 2026'));
ck('shows the write-by date', str_contains($html,'Fri 16 Oct'));
ck('no raw php warnings', !str_contains($html,'Warning:') && !str_contains($html,'Notice:'));

sect('outstanding is ordered by what is due first');
$out = CardsCo_Box::outstanding(['company_id'=>$cid]);
ck('our box is listed', count($out) >= 1);
ck('a posted box would not be', in_array($out[0]['status'],['planned','writing','packed'],true));

sect('one box');
$html = screen(function() use ($f) { $f->render(new CardsCo_Admin()); }, ['page'=>'cardsco-boxes','box'=>(int)$box['id']]);
ck('renders', strlen($html) > 800);
ck('divs balanced', balanced($html));
ck('shows where it goes', str_contains($html,'LS1 4AP'));
ck('lists everybody', substr_count($html,'class="cardsco-pick"'), 4);
ck('offers the next step', str_contains($html,'Mark written and packed'));
ck('offers the write list', str_contains($html,'Open write list'));

sect('the write list');
$html = screen(function() use ($f) { $f->render(new CardsCo_Admin()); },
  ['page'=>'cardsco-boxes','box'=>(int)$box['id'],'view'=>'write']);
ck('renders', strlen($html) > 500);
ck('divs balanced', balanced($html));
ck('every card has a block', substr_count($html,'cardsco-write-card">'), 4);
ck('names are merged into the message', str_contains($html,'Happy birthday Tunde!'));
ck('no merge field left showing', !str_contains($html,'{first name}'));
ck('carries the signature', str_contains($html,'Everyone at Acme'));
ck('says the handwriting and ink', str_contains($html,'Arthur') && str_contains($html,'blue'));

sect('moving the box along');
$url = post([$f,'handle_advance'], ['box_id'=>(int)$box['id'],'status'=>'packed']);
ck('redirected back to the box', (int)arg($url,'box'), (int)$box['id']);
ck('said what happened', str_contains((string)arg($url,'cardsco_message'),'4 cards marked written'));
$box = CardsCo_Box::get((int)$box['id']);
ck('box is packed', $box['status'], 'packed');
$cards = CardsCo_Box::cards((int)$box['id']);
ck('all four written', count(array_filter($cards,fn($c)=>$c['status']==='written')), 4);

$url = post([$f,'handle_advance'], ['box_id'=>(int)$box['id'],'status'=>'posted']);
$box = CardsCo_Box::get((int)$box['id']);
ck('box is posted', $box['status'], 'posted');
ck('posted date stamped', !empty($box['posted_at']));
$cards = CardsCo_Box::cards((int)$box['id']);
ck('all four posted', count(array_filter($cards,fn($c)=>$c['status']==='posted')), 4);
ck('and each carries its own posted date', count(array_filter($cards,fn($c)=>!empty($c['posted_at']))), 4);

sect('a held card is never swept along');
// start a second box and hold one card in it
$pid2 = CardsCo_Programme::create($cid,'employee-birthdays');
$ids2 = [];
foreach ([['Rosa','Mint',14],['Dev','Patel',20]] as $p) {
  list($first,$last,$d) = $p;
  $rid = CardsCo_Recipient::create(['company_id'=>$cid,'type'=>'employee','first_name'=>$first,
    'last_name'=>$last,'address'=>"9 Other Road\nLeeds",'postcode'=>'LS3 1AA']);
  $ids2[] = $rid;
  CardsCo_Occasion::save(['recipient_id'=>$rid,'occasion_type_id'=>$bday['id'],'event_month'=>12,'event_day'=>$d]);
}
CardsCo_Programme::add_recipients($pid2,$ids2);
CardsCo_Programme::update($pid2,[
  'card_id'=>$card,'message_override'=>'Happy birthday {first name}!','handwriting'=>'Arthur',
  'ink'=>'blue','signature'=>'Acme','shipping_mode'=>'company',
  'ship_address'=>"Acme House\n1 Office Way\nLeeds",'ship_postcode'=>'LS1 4AP',
  'timing_mode'=>'monthly_box','box_arrive_day'=>25,'weekend_rule'=>1,
  'starts_on'=>'2026-12-01','setup_step'=>7]);
CardsCo_Programme::activate($pid2);
CardsCo_Box::run(['company_id'=>$cid,'today'=>'2026-11-18']);
$box2 = CardsCo_Box::find($pid2,'2026-12');
ck('second box filled', (int)$box2['card_count'], 2);
$two = CardsCo_Box::cards((int)$box2['id']);
CardsCo_Queue::set_status((int)$two[0]['id'],'on_hold',['hold_reason'=>'no_address']);

$url = post([$f,'handle_advance'], ['box_id'=>(int)$box2['id'],'status'=>'packed']);
$msg = (string) arg($url,'cardsco_message');
ck('only the other one moved', str_contains($msg,'1 card marked written'));
ck('and it says one was left behind', str_contains($msg,'1 card was left on hold'));
$two = CardsCo_Box::cards((int)$box2['id']);
ck('held card untouched', count(array_filter($two,fn($c)=>$c['status']==='on_hold')), 1);
ck('the other is written', count(array_filter($two,fn($c)=>$c['status']==='written')), 1);

sect('the warning shows on screen');
$html = screen(function() use ($f) { $f->render(new CardsCo_Admin()); }, ['page'=>'cardsco-boxes','box'=>(int)$box2['id']]);
ck('says one is on hold', str_contains($html,'is on hold'));
ck('names the reason', str_contains(strtolower($html),'address'));
$html = screen(function() use ($f) { $f->render(new CardsCo_Admin()); },
  ['page'=>'cardsco-boxes','box'=>(int)$box2['id'],'view'=>'write']);
ck('held card is kept off the write list', substr_count($html,'cardsco-write-card">'), 1);

sect('changing cards by hand');
$free = CardsCo_Box::cards((int)$box2['id']);
$one  = $free[0];
$url = post([$f,'handle_card_status'], ['card_id'=>(int)$one['id'],'box_id'=>(int)$box2['id'],'status'=>'posted']);
ck('updated', str_contains((string)arg($url,'cardsco_message'),'Card updated'));
ck('it took', CardsCo_Queue::get((int)$one['id'])['status'], 'posted');

$url = post([$f,'handle_bulk'], ['box_id'=>(int)$box2['id'],
  'cards'=>array_map(fn($c)=>(int)$c['id'],$free), 'bulk_status'=>'cancelled']);
ck('bulk reported', str_contains((string)arg($url,'cardsco_message'),'marked cancelled'));
ck('both cancelled', count(array_filter(CardsCo_Box::cards((int)$box2['id']),
  fn($c)=>$c['status']==='cancelled')), 2);

sect('refusing nonsense');
$url = post([$f,'handle_bulk'], ['box_id'=>(int)$box2['id'],'cards'=>[],'bulk_status'=>'posted']);
ck('empty selection refused', str_contains((string)arg($url,'cardsco_error'),'Pick some cards'));
$url = post([$f,'handle_advance'], ['box_id'=>999999,'status'=>'packed']);
ck('missing box refused', (string)arg($url,'cardsco_error') !== '');
$url = post([$f,'handle_advance'], ['box_id'=>(int)$box2['id'],'status'=>'teleported']);
ck('made-up status refused', (string)arg($url,'cardsco_error') !== '');
ck('box unchanged by the bad status', CardsCo_Box::get((int)$box2['id'])['status'], 'packed');

printf("\n%s\n", $fail ? "$fail FAILED" : 'all passed');
exit($fail?1:0);
