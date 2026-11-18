<?php
// The Settings save: does it write, validate, and refuse what it should?
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/settings/'; $_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/core/wp-load.php';
$fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-50s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(string)$got); }

$uid=0;
foreach ( get_users(array('number'=>50)) as $u ) {
  if ( user_can($u,'cardsco_manage_company') && cardsco_get_user_company_id($u->ID) ) { $uid=$u->ID; break; }
}
wp_set_current_user($uid);
$cid = cardsco_get_user_company_id($uid);

CardsCo_Settings::set('return_mode','billing',$cid);
ck('return_mode round-trips', CardsCo_Settings::get('return_mode','',$cid), 'billing');
ck('a label is not a valid value', is_wp_error(CardsCo_Settings::clean('return_mode','Same as billing address')));
ck('junk is refused',             is_wp_error(CardsCo_Settings::clean('return_mode','nonsense')));
ck('card_max_chars defaults to 400', (int) CardsCo_Settings::get('card_max_chars',400,$cid), 400);
ck('company scope is allowed for trading_name', CardsCo_Settings::company_may_set('trading_name'));
ck('platform-only key is not company-settable', ! CardsCo_Settings::company_may_set('login_max_attempts'));

$s = CardsCo_Dashboard::settings_screen('profile');
ck('three tabs', count($s['tabs']), 3);
ck('profile is the default tab', $s['tab'], 'profile');
$b = CardsCo_Dashboard::settings_screen('billing');
ck('billing tab selectable', $b['tab'], 'billing');
$j = CardsCo_Dashboard::settings_screen('nonsense');
ck('an unknown tab falls back to profile', $j['tab'], 'profile');

$html = do_shortcode('[cardsco_settings]');
ck('renders the three section headings', substr_count($html,'cc-set-sec'), 3);
ck('has a nonce', str_contains($html,'_cardsco_nonce'));
ck('save button present', str_contains($html,'Save changes'));

printf("\n%s (%d failed)\n", $fail ? 'FAILED' : 'all good', $fail);
exit($fail ? 1 : 0);
