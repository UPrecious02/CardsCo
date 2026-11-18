<?php
/**
 * The set-up wizard's controller: step gating and field whitelisting.
 * Pure logic, so it needs no WordPress.
 */
// The runner invokes unit suites from the plugin directory, so the
// bootstrap is found by absolute path rather than relative to this file.
$cc_wp = getenv( 'CC_WP' ) ?: '/home/claude/wp';
require $cc_wp . '/core/wp-load.php';

$pass = 0; $fail = 0;
function ck($label, $got, $want = true) {
  global $pass, $fail;
  $ok = ($want === true) ? (bool) $got : ($got === $want);
  if ($ok) { $pass++; printf("  ok    %-50s\n", $label); }
  else { $fail++; printf("  FAIL  %-50s %s\n", $label, var_export($got, true)); }
}
function sect($s) { echo "\n--- $s ---\n"; }

sect('you cannot skip ahead of where you have got to');
$p = ['setup_step' => 3];
ck('asking for the step you are on', CardsCo_Setup::step_on_screen($p, 3), 3);
ck('going back is allowed', CardsCo_Setup::step_on_screen($p, 1), 1);
ck('skipping forward is not', CardsCo_Setup::step_on_screen($p, 7), 3);
ck('no step asked for lands on the furthest', CardsCo_Setup::step_on_screen($p, 0), 3);
ck('nonsense lands on the furthest', CardsCo_Setup::step_on_screen($p, -4), 3);
ck('never past the last step', CardsCo_Setup::step_on_screen(['setup_step'=>99], 99), 7);
ck('never before the first', CardsCo_Setup::step_on_screen(['setup_step'=>0], 1), 1);

sect('a finished step is marked finished');
ck('earlier steps are done', CardsCo_Setup::done(['setup_step'=>4], 3), true);
ck('the step you are on is not', CardsCo_Setup::done(['setup_step'=>4], 4), false);
ck('nor are later ones', CardsCo_Setup::done(['setup_step'=>4], 5), false);

sect('each step takes only its own fields');
$noise = ['card_id'=>'7','status'=>'active','company_id'=>'99','setup_step'=>'7','id'=>'123'];
$three = CardsCo_Setup::fields(3, $noise);
ck('step 3 keeps the card', $three['card_id'], 7);
ck('  and nothing else', array_keys($three), ['card_id']);
foreach (['status','company_id','id'] as $k) {
  ck("  refuses to pass through $k", array_key_exists($k, $three), false);
}
$five = CardsCo_Setup::fields(5, ['shipping_mode'=>'individual','ship_postcode'=>' LS1 4AP ','evil'=>'x']);
ck('step 5 keeps shipping', $five['shipping_mode'], 'individual');
ck('  trims the postcode', $five['ship_postcode'], 'LS1 4AP');
ck('  and drops the rest', array_key_exists('evil', $five), false);
$six = CardsCo_Setup::fields(6, ['timing_mode'=>'days_before','days_before'=>'10']);
ck('step 6 keeps timing', $six['timing_mode'], 'days_before');
ck('  casts the lead to a number', $six['days_before'], 10);
ck('  and an absent checkbox is false', $six['weekend_rule'], false);
ck('  a present one is true', CardsCo_Setup::fields(6, ['weekend_rule'=>'1'])['weekend_rule'], true);

sect('a message keeps its merge fields through sanitising');
$four = CardsCo_Setup::fields(4, ['message_override'=>'Happy birthday {first name}!']);
ck('braces survive', str_contains($four['message_override'], '{first name}'));

sect('steps with no fields of their own save nothing');
foreach ([1, 2, 7] as $s) {
  ck("  step $s", CardsCo_Setup::fields($s, ['card_id'=>'9']), []);
}

sect('the step list is the model\'s, not a second copy');
ck('seven steps', count(CardsCo_Setup::steps()), 7);
ck('same list as the model', CardsCo_Setup::steps() === CardsCo_Programme::steps());
ck('in the order the design shows',
   array_column(CardsCo_Setup::steps(), 'label'),
   ['Occasion','People','Card','Message','Shipping','Timing','Review']);

echo "\n", $fail ? "$fail FAILED\n" : "all passed\n";
exit($fail ? 1 : 0);
