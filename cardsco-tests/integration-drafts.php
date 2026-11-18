<?php
// Resuming a half-finished occasion.
$_SERVER['HTTP_HOST']='cardsco.test'; $_SERVER['REQUEST_URI']='/'; $_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/core/wp-load.php';
global $wpdb; $fail=0;
function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-52s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(string)$got); }

$uid=0;
foreach ( get_users(array('number'=>50)) as $u ) {
  if ( user_can($u,'cardsco_access_dashboard') && cardsco_get_user_company_id($u->ID) ) { $uid=$u->ID; break; }
}
wp_set_current_user($uid);
$cid = cardsco_get_user_company_id($uid);
// Clear every programme for this occasion, not just drafts: an active one
// legitimately hides the draft row, so leaving one behind would make this
// test depend on whatever ran before it.
$wpdb->query( $wpdb->prepare('DELETE FROM '.cardsco_table('programmes')." WHERE company_id = %d AND category = 'employee-birthdays'", $cid) );

ck('no draft to begin with', CardsCo_Programme::draft_for($cid,'employee-birthdays'), null);

$a = CardsCo_Programme::create($cid,'employee-birthdays');
ck('a draft is created', is_int($a) && $a > 0);

$d = CardsCo_Programme::draft_for($cid,'employee-birthdays');
ck('and is found again', $d ? (int)$d['id'] : 0, (int)$a);

// Push it to step 4 and check we resume there rather than at the start.
$wpdb->update( cardsco_table('programmes'), array('setup_step'=>4), array('id'=>$a) );
$d = CardsCo_Programme::draft_for($cid,'employee-birthdays');
ck('resumes at the step it reached', CardsCo_Setup::step_on_screen($d,0), 4);

// An active programme is not a draft and must not be resumed into.
$wpdb->update( cardsco_table('programmes'), array('status'=>'active'), array('id'=>$a) );
ck('an active programme is not offered as a draft', CardsCo_Programme::draft_for($cid,'employee-birthdays'), null);

// Another company's draft is invisible.
$wpdb->update( cardsco_table('programmes'), array('status'=>'draft'), array('id'=>$a) );
ck('another company sees nothing', CardsCo_Programme::draft_for($cid+9999,'employee-birthdays'), null);

// The occasions screen has to show it, or the draft is still invisible.
$wpdb->update( cardsco_table('programmes'), array('status'=>'draft','setup_step'=>4), array('id'=>$a) );
$rows = CardsCo_Dashboard::occasion_cards();
$row  = null;
foreach ($rows as $r) { if ($r['slug'] === 'employee-birthdays') { $row = $r; break; } }
ck('the occasion row knows it is a draft', $row && $row['draft']);
ck('and which step it reached', $row ? (int) $row['draft_step'] : 0, 4);
ck('the stat line says so', $row && str_contains($row['stat'], 'step 4 of 7'));
ck('it is not shown as active', $row && ! $row['active']);

$html = do_shortcode('[cardsco_occasions]');
ck('a Draft pill renders', str_contains($html, 'cc-pill is-draft'));
ck('and the button says Continue', str_contains($html, '>Continue<'));

/*
 * Where the button GOES, which is the part this suite used to leave out.
 * It said Continue and linked to ?occasion=<slug> — the address a NEW
 * occasion starts at — so somebody who saved and came back landed on step
 * one with their draft sitting untouched behind them. The label was right
 * and the link was wrong, and checking only the label hid it completely.
 */
preg_match_all('/<a class="cc-btn[^"]*cc-occ-btn"\s*href="([^"]*)"/s', $html, $cc_links);
$cc_hrefs = array_map('html_entity_decode', $cc_links[1]);
$cc_resume = '';

foreach ($cc_hrefs as $cc_href) {
	if (str_contains($cc_href, 'setup=' . (int) $a)) { $cc_resume = $cc_href; }
}

ck('the button carries the draft', $cc_resume !== '');
ck('and the step it reached', str_contains($cc_resume, 'step=4'));
ck('rather than starting a new one', str_contains($cc_resume, 'occasion=employee-birthdays'), false);

/* Categories with no draft still start a new one. */
$cc_fresh = '';
foreach ($cc_hrefs as $cc_href) {
	if (str_contains($cc_href, 'occasion=thank-you')) { $cc_fresh = $cc_href; }
}
ck('an untouched category still offers a fresh start', $cc_fresh !== '');
ck('and carries no draft id', str_contains($cc_fresh, 'setup='), false);

$wpdb->query( $wpdb->prepare('DELETE FROM '.cardsco_table('programmes').' WHERE id = %d', $a) );
printf("\n%s (%d failed)\n", $fail ? 'FAILED' : 'all good', $fail);
exit($fail ? 1 : 0);
