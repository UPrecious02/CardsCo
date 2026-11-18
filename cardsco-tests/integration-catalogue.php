<?php
/**
 * Mirroring card designs into shop products.
 *
 * Read stub-woo.php before trusting any of this: WooCommerce cannot be
 * installed in this container, so these run against a stand-in built from
 * its documented API. That makes the suite a real test of OUR decisions —
 * when a product appears, when it is hidden rather than deleted, that the
 * two records stay linked — and no test at all of whether WooCommerce
 * accepts the calls. That one check has to happen on a site that has it.
 */
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$fail = 0;

function ck($l,$got,$want=true){ global $fail; $ok=($want===true)?(bool)$got:($got===$want); if(!$ok)$fail++;
  printf("  %-5s %-54s %s\n",$ok?'ok':'FAIL',$l,is_bool($got)?var_export($got,true):(is_array($got)?json_encode($got):(string)$got));
  if(!$ok&&$want!==true) printf("        want %s\n", is_array($want)?json_encode($want):var_export($want,true)); }
function sect($t){ echo "\n=== $t ===\n"; }

CardsCo_Schema::maybe_install(true);
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
	'meta_query'     => array( array( 'key' => '_cardsco_test_suite', 'value' => 'catalogue' ) ),
) ) as $cc_old ) {
	wp_delete_post( $cc_old, true );
}

$stamp = strtolower(wp_generate_password(6,false));

function card($title, $args = array()) {
  $id = wp_insert_post([
    'post_type'   => CardsCo_Card::POST_TYPE,
    'post_title'  => $title,
    'post_status' => $args['status'] ?? 'publish',
    'post_excerpt'=> $args['excerpt'] ?? '',
  ], true);
  if (is_wp_error($id)) return 0;
  if (isset($args['price'])) update_post_meta($id, '_cardsco_price', $args['price']);
  if (isset($args['code']))  update_post_meta($id, '_cardsco_code', $args['code']);
  if (!empty($args['sell'])) update_post_meta($id, CardsCo_Catalogue::SELLABLE, 1);
  return (int) $id;
}

/* ---------------------------------------------- with no shop installed */

sect('a site with no shop');
ck('the plugin notices there is none', CardsCo_Catalogue::shop_available(), false);
$lonely = card('No shop here '.$stamp, ['sell'=>1, 'price'=>'4.00']);
ck('syncing does nothing',        CardsCo_Catalogue::sync($lonely), 0);
ck('and writes no product link',  CardsCo_Catalogue::product_for_card($lonely), 0);
ck('sync_all is a no-op too',     CardsCo_Catalogue::sync_all(), ['listed'=>0,'hidden'=>0,'failed'=>0]);
/* The point of the guard: the dashboard has to keep working on a site that
 * has never had WooCommerce. */
ck('and the card itself is untouched', get_post_status($lonely), 'publish');

/* ------------------------------------------------------- shop switched on */

require __DIR__ . '/stub-woo.php';
ck('the stand-in shop is now visible', CardsCo_Catalogue::shop_available(), true);

sect('a card nobody wants sold on its own');
$internal = card('Programme only '.$stamp, ['price'=>'3.20']);
ck('is not sellable',        CardsCo_Catalogue::is_sellable($internal), false);
ck('gets no product',        CardsCo_Catalogue::sync($internal), 0);
ck('and no link',            CardsCo_Catalogue::product_for_card($internal), 0);

sect('ticking the box');
$sellable = card('Balloons '.$stamp, ['sell'=>1, 'price'=>'4.25', 'code'=>'BAL-'.$stamp, 'excerpt'=>'A bright one.']);
$pid = CardsCo_Catalogue::sync($sellable);
ck('a product is made',      is_int($pid) && $pid > 0);
ck('linked from the card',   CardsCo_Catalogue::product_for_card($sellable), $pid);
ck('and back from the product', CardsCo_Catalogue::card_for_product($pid), $sellable);
$p = wc_get_product($pid);
ck('named after the card',   $p->get_name(), 'Balloons '.$stamp);
ck('at the card\'s price',   $p->get_regular_price(), '4.25');
ck('published',              $p->get_status(), 'publish');
ck('and visible in the shop', $p->get_catalog_visibility(), 'visible');
ck('with the reference as its SKU', $p->get_sku(), 'BAL-'.$stamp);

sect('saving the card again');
$again = CardsCo_Catalogue::sync($sellable);
ck('updates the same product, does not make a second', $again, $pid);
ck('still only one product for this card',
  (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%d", CardsCo_Catalogue::CARD, $sellable)), 1);
wp_update_post(['ID'=>$sellable, 'post_title'=>'Balloons renamed '.$stamp]);
update_post_meta($sellable, '_cardsco_price', '5.00');
CardsCo_Catalogue::sync($sellable);
$p = wc_get_product($pid);
ck('the new title carried over', $p->get_name(), 'Balloons renamed '.$stamp);
ck('and the new price',          $p->get_regular_price(), '5.00');

sect('a card with no price of its own');
$default = card('Standard price '.$stamp, ['sell'=>1]);
$dpid = CardsCo_Catalogue::sync($default);
ck('still gets a product', $dpid > 0);
ck('priced at the standard rate',
  wc_get_product($dpid)->get_regular_price(), (string) CardsCo_Card::price($default));

sect('unticking it');
delete_post_meta($sellable, CardsCo_Catalogue::SELLABLE);
ck('it is no longer sellable', CardsCo_Catalogue::is_sellable($sellable), false);
ck('sync reports no product',  CardsCo_Catalogue::sync($sellable), 0);
$p = wc_get_product($pid);
/* Hidden, never deleted: the product may be on orders already placed, and
 * deleting it would leave those pointing at nothing. */
ck('the product still exists', (bool) $p);
ck('but is a draft',           $p->get_status(), 'draft');
ck('and hidden from the shop', $p->get_catalog_visibility(), 'hidden');
ck('the link is kept, not thrown away', CardsCo_Catalogue::product_for_card($sellable), $pid);

sect('ticking it again');
update_post_meta($sellable, CardsCo_Catalogue::SELLABLE, 1);
ck('the same product comes back', CardsCo_Catalogue::sync($sellable), $pid);
ck('published again',             wc_get_product($pid)->get_status(), 'publish');
ck('and visible',                 wc_get_product($pid)->get_catalog_visibility(), 'visible');

sect('unpublishing the card');
wp_update_post(['ID'=>$sellable, 'post_status'=>'draft']);
ck('a draft card is not sellable whatever the tick says',
  CardsCo_Catalogue::is_sellable($sellable), false);
CardsCo_Catalogue::sync($sellable);
ck('so the product is hidden', wc_get_product($pid)->get_status(), 'draft');
wp_update_post(['ID'=>$sellable, 'post_status'=>'publish']);
CardsCo_Catalogue::sync($sellable);
ck('and comes back when it is published again', wc_get_product($pid)->get_status(), 'publish');

sect('somebody deletes the product in the shop');
wp_delete_post($pid, true);
ck('it really is gone', wc_get_product($pid), false);
$new_pid = CardsCo_Catalogue::sync($sellable);
ck('a new one is made rather than an error', is_int($new_pid) && $new_pid > 0);
ck('and it is a different product',          $new_pid !== $pid);
ck('the card now points at the new one',     CardsCo_Catalogue::product_for_card($sellable), $new_pid);

sect('two cards sharing a reference');
/* WooCommerce refuses the whole save on a duplicate SKU, which would lose
 * the title, price and image for the sake of a code. */
$clash = card('Same code '.$stamp, ['sell'=>1, 'price'=>'2.50', 'code'=>'BAL-'.$stamp]);
$cpid  = CardsCo_Catalogue::sync($clash);
ck('the card is still listed', is_int($cpid) && $cpid > 0);
ck('with its title',           wc_get_product($cpid)->get_name(), 'Same code '.$stamp);
ck('and its price',            wc_get_product($cpid)->get_regular_price(), '2.50');
ck('it just goes without the reference', wc_get_product($cpid)->get_sku(), '');
ck('and the first card keeps it',        wc_get_product($new_pid)->get_sku(), 'BAL-'.$stamp);

sect('binning a card');
CardsCo_Catalogue::withdraw( $clash );
ck('hides its product', wc_get_product($cpid)->get_status(), 'draft');
ck('without deleting it', (bool) wc_get_product($cpid));

sect('switching the shop on with cards already here');
$counts = CardsCo_Catalogue::sync_all();
ck('it reports what it did', isset($counts['listed'], $counts['hidden'], $counts['failed']));
ck('nothing failed',         $counts['failed'], 0);
ck('the sellable ones are listed', $counts['listed'] >= 2);
ck('and the rest are not',         $counts['hidden'] >= 1);

sect('the one-way rule');
/* Editing the product in WooCommerce is not a second source of truth. */
$edited = wc_get_product($new_pid);
$edited->set_name('Edited in the shop');
$edited->set_regular_price('99.00');
$edited->save();
ck('the edit sticks until the card is saved', wc_get_product($new_pid)->get_name(), 'Edited in the shop');
CardsCo_Catalogue::sync($sellable);
ck('then the card wins on the title', wc_get_product($new_pid)->get_name(), get_post($sellable)->post_title);
ck('and on the price',                wc_get_product($new_pid)->get_regular_price(), '5.00');


/* ============================================= the public catalogue page */

sect('what the public may browse');
/* Start from a known set: other suites leave sellable cards behind. */
global $wpdb;
foreach ($wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",
  CardsCo_Catalogue::SELLABLE, '1')) as $old) delete_post_meta($old, CardsCo_Catalogue::SELLABLE);

$bday = (int) (CardsCo_Occasion_Type::get_by_slug('birthday')['id'] ?? 0);
$xmas = (int) (CardsCo_Occasion_Type::get_by_slug('christmas')['id'] ?? 0);
$ny   = (int) (CardsCo_Occasion_Type::get_by_slug('new-year')['id'] ?? 0);
ck('three occasion types to work with', $bday && $xmas && $ny);

function catcard($title, $pattern, $types, $sell = true, $status = 'publish') {
  $id = wp_insert_post(['post_type'=>CardsCo_Card::POST_TYPE,'post_title'=>$title,'post_status'=>$status], true);
  if (!is_wp_error($id)) update_post_meta($id, '_cardsco_test_suite', 'catalogue');
  if (is_wp_error($id)) return 0;
  update_post_meta($id, '_cardsco_pattern', $pattern);
  if ($sell) update_post_meta($id, CardsCo_Catalogue::SELLABLE, 1);
  if ($types) CardsCo_Card::set_occasions($id, $types);
  CardsCo_Catalogue::sync($id);
  return (int) $id;
}

$c1 = catcard('Birthday one '.$stamp,  'Confetti',        [$bday]);
$c2 = catcard('Birthday two '.$stamp,  'Confetti',        [$bday]);
$c3 = catcard('Festive '.$stamp,       'String of lights',[$xmas, $ny]);
$hidden   = catcard('Programme only '.$stamp, 'Plain',    [$bday], false);
$unpubbed = catcard('Not ready '.$stamp,      'Plain',    [$bday], true, 'draft');
wp_cache_flush();

$browsable = CardsCo_Catalogue::browsable();
ck('the three sellable ones are there', count($browsable), 3);
ck('a programme-only card is not',  in_array($hidden, $browsable, true), false);
ck('nor an unpublished one',        in_array($unpubbed, $browsable, true), false);

sect('the filter row builds itself');
/* One pill per collection, not per occasion. The row used to be built from
 * occasion types — about twenty — and cut to the nine with the most cards,
 * which meant the cards behind the other eleven could not be found by
 * browsing at all. Six collections need no cap. */
$filters = CardsCo_Catalogue::filters($browsable);
$by_slug = array_column($filters, 'count', 'id');

ck('one pill per collection with a card', count($filters), 2);
ck('the two birthday cards are counted', $by_slug['employee-birthdays'] ?? 0, 2);
ck('and the festive one, once, not twice for its two occasions',
   $by_slug['christmas-seasonal'] ?? 0, 1);
ck('each pill carries its collection slug', $filters[0]['id'], 'christmas-seasonal');
ck('and its label',  $filters[0]['label'], CardsCo_Category::all()['christmas-seasonal']['label']);

/* Declared order, not biggest first: with a fixed set of six the row should
 * not reshuffle every time a card is added, and this is the order the set-up
 * wizard shows them in. */
$want = array_values(array_intersect(array_keys(CardsCo_Category::all()), array_keys($by_slug)));
ck('in the order the collections are declared', array_column($filters,'id'), $want);

ck('a collection with no cards is left out',
   in_array('work-anniversaries', array_column($filters,'id'), true), false);
ck('nothing is capped', count($filters) <= count(CardsCo_Category::all()));
ck('an empty catalogue has no pills', CardsCo_Catalogue::filters([]), []);

sect('one tile');
$tile = CardsCo_Catalogue::tile($c3);
ck('the card name',   $tile['title'], 'Festive '.$stamp);
ck('the artwork name',$tile['pattern'], 'String of lights');
/* Several occasions join with a middle dot, as the frame has them. */
ck('both occasions, joined', str_contains($tile['occasions'], ' · '));
ck('it links to its product', $tile['product'] > 0 && $tile['url'] !== '');

sect('the page');
$page = CardsCo_Catalogue::page([]);
ck('counts everything browsable', $page['all'], 3);
ck('and shows them all',          $page['shown'], 3);
ck('with nothing more to load',   $page['more'], false);

sect('filtering');
$page = CardsCo_Catalogue::page(['filter'=>'employee-birthdays']);
ck('only that collection',      $page['total'], 2);
ck('the filter is remembered',  $page['filter'], 'employee-birthdays');
ck('but the full count stands', $page['all'], 3);

/* A real collection that happens to hold nothing shows nothing. */
ck('an empty collection shows nothing', CardsCo_Catalogue::page(['filter'=>'work-anniversaries'])['total'], 0);

/* A slug that is not a collection at all is a stale or mistyped link, and
 * falls back to the whole catalogue rather than an empty page. */
ck('a slug that is not a collection falls back to all', CardsCo_Catalogue::page(['filter'=>'nonsense'])['total'], 3);
ck('and clears the filter so All reads as current', CardsCo_Catalogue::page(['filter'=>'nonsense'])['filter'], '');
ck('and that is not an error',  is_array(CardsCo_Catalogue::page(['filter'=>'nonsense'])['tiles']));

sect('sorting');
$by_name = array_column(CardsCo_Catalogue::page(['sort'=>'name'])['tiles'], 'title');
$sorted  = $by_name; usort($sorted, 'strcasecmp');
ck('by name is actually sorted', $by_name, $sorted);
ck('an unknown sort does not break it', count(CardsCo_Catalogue::page(['sort'=>'sideways'])['tiles']), 3);

sect('showing more');
for ($i = 0; $i < 12; $i++) { catcard('Filler '.$i.' '.$stamp, 'Confetti', [$bday]); }
wp_cache_flush();
$page = CardsCo_Catalogue::page([]);
ck('fifteen cards in total',    $page['total'], 15);
ck('twelve on the first view',  $page['shown'], CardsCo_Catalogue::PER_PAGE);
ck('and there is more',         $page['more'], true);
ck('the next step asks for 24', $page['next'], 24);
$more = CardsCo_Catalogue::page(['show'=>24]);
ck('which shows the rest',      $more['shown'], 15);
ck('and then stops offering',   $more['more'], false);
/* A hand-typed ?cc_show=1 must not show a single card. */
ck('a silly show value falls back to a full page', CardsCo_Catalogue::page(['show'=>1])['shown'], CardsCo_Catalogue::PER_PAGE);

sect('what the shortcode renders');
wp_set_current_user(0);
$html = do_shortcode('[cardsco_catalogue]');
ck('the heading',        str_contains($html, 'Card catalogue'));
ck('the design count',   str_contains($html, '15 designs'));
ck('the filter row',     str_contains($html, 'cc-pill-filter'));
ck('twelve tiles',       substr_count($html, 'cc-cat-tile'), CardsCo_Catalogue::PER_PAGE);
ck('the showing line',   str_contains($html, 'Showing 12 of 15'));
ck('and the more button',str_contains($html, 'Show more cards'));
/* No prices anywhere: the frame has none, and this is a browse page. */
ck('no price is printed', (bool) preg_match('/[£$]\s?\d/', $html), false);
ck('it brings its own stylesheet', wp_style_is('cardsco-catalogue', 'enqueued'));
ck('a programme-only card is absent', str_contains($html, 'Programme only '.$stamp), false);
ck('and an unpublished one too',      str_contains($html, 'Not ready '.$stamp), false);

sect('the preview panel');
$phtml = do_shortcode('[cardsco_catalogue use="/cart/"]');

ck('the dialog is on the page',      str_contains($phtml, 'id="cc-pv"'));
ck('and its data block',             str_contains($phtml, 'id="cc-pv-data"'));
ck('one entry per tile',             count((array) json_decode(
       (string) (preg_match('#id="cc-pv-data">(.*?)</script>#s', $phtml, $m) ? $m[1] : '{}'), true
   )), CardsCo_Catalogue::PER_PAGE);
ck('every preview link is wired',    substr_count($phtml, 'data-cc-pv='), CardsCo_Catalogue::PER_PAGE);
ck('the script is enqueued',         wp_script_is('cardsco-preview', 'enqueued'));

/* Progressive enhancement: the Preview control is still a link to the
 * product page, and still works as one. preview.js intercepts the click; with
 * no JavaScript, or no <dialog>, the click navigates as it always did. */
$pv = preg_match('#<a class="cc-cat-preview" href="([^"]+)"#', $phtml, $hm) ? $hm[1] : '';
ck('it is still a real link to the product page', str_contains($pv, 'p=') || str_contains($pv, '/product/'));
ck('not a dead href',                             $pv !== '' && $pv !== '#');

/* The panel shows more than the tile, so tile() carries extra fields. They
 * must not leak onto the tile itself, which the frame keeps bare. */
$t = CardsCo_Catalogue::tile($c3);
ck('the tile carries the big image key', array_key_exists('image_lg', $t));
ck('the words on the front',             array_key_exists('front', $t));
ck('the size',                           array_key_exists('format', $t));
ck('and the collection label',           $t['collection'], CardsCo_Category::all()['christmas-seasonal']['label']);

/* No prices on the browse page — the frame has none anywhere on it, and the
 * panel is part of that page. Easy to leak now the panel carries card detail
 * in a JSON blob, so this checks the whole rendered page, blob included. */
ck('no price anywhere on the page, panel data included',
   (bool) preg_match('/[£$]\s?\d/', $phtml), false);

sect('the collections screen');
/* Fresh, not the $browsable captured near the top: the pagination section
 * above adds a dozen filler cards after it. */
$live   = CardsCo_Catalogue::browsable();
$colls  = CardsCo_Catalogue::collections();
$byslug = array_column($colls, null, 'slug');

ck('all six are listed, empty ones included', count($colls), count(CardsCo_Category::all()));
ck('in the declared order', array_column($colls,'slug'), array_keys(CardsCo_Category::all()));
ck('a filled collection has a count', $byslug['employee-birthdays']['count'] > 0);
ck('which matches the catalogue page for the same slug',
   $byslug['employee-birthdays']['count'], CardsCo_Catalogue::page(['filter'=>'employee-birthdays'])['total']);
ck('and matches the filter row',
   $byslug['employee-birthdays']['count'],
   (int) (array_column(CardsCo_Catalogue::filters($live), 'count', 'id')['employee-birthdays'] ?? 0));
ck('an empty collection counts zero', $byslug['work-anniversaries']['count'], 0);
ck('the coming-soon flag is carried', $byslug['work-anniversaries']['coming_soon'], true);
ck('and is not set on a live one',    $byslug['employee-birthdays']['coming_soon'], false);
ck('the blurb comes along',           $byslug['thank-you']['blurb'], CardsCo_Category::all()['thank-you']['blurb']);

/* Counts come from the browsable catalogue, not CardsCo_Category::cards(),
 * which also returns programme-only designs a visitor cannot buy. If the two
 * ever diverge the number beside a collection stops matching what clicking
 * it shows, which is the kind of thing nobody notices for months. */
$sum = array_sum(array_column($colls, 'count'));
ck('no browsable card is counted twice', $sum <= count($live));
ck('every placed card is counted exactly once',
   $sum, count(array_filter($live, fn($id) => CardsCo_Card::category($id) !== '')));
ck('a programme-only card is not counted', in_array($hidden, $live, true), false);

sect('what the collections shortcode renders');
$chtml = do_shortcode('[cardsco_collections catalogue="/catalogue/"]');
ck('the heading',            str_contains($chtml, 'Collections'));
ck('six tiles',              substr_count($chtml, 'cc-coll-tile'), 6);
ck('a filled one links through', str_contains($chtml, 'cc_filter=employee-birthdays'));
ck('an empty one does not',      str_contains($chtml, 'cc_filter=work-anniversaries'), false);
ck('the coming-soon chip',   str_contains($chtml, 'Coming soon'));
ck('and the browse-all link',str_contains($chtml, 'Or browse every card'));
ck('it brings the catalogue stylesheet', wp_style_is('cardsco-catalogue', 'enqueued'));

/* Without the attribute there is no catalogue page to point at. The tiles
 * must still render rather than linking to nowhere. */
$bare = do_shortcode('[cardsco_collections]');
ck('with no catalogue set it still renders', substr_count($bare, 'cc-coll-tile'), 6);
ck('but links nowhere',                      str_contains($bare, 'cc_filter='), false);
ck('and drops the browse-all link',          str_contains($bare, 'Or browse every card'), false);

sect('the shop stylesheet');

/* The WooCommerce conditional tags do not exist in the stub, so is_shop_page()
   falls through all four and returns false. That is the ordinary portal
   request, and it must stay clean. */
CardsCo_Shop::assets();
ck('nothing on a non-shop request', wp_style_is('cardsco-shop', 'enqueued'), false);

/* Now stand one of the tags up and ask again. This is the single-product
   page: the one that was rendering with browser defaults. */
if ( ! function_exists('is_woocommerce') ) {
	function is_woocommerce() { return ! empty($GLOBALS['cc_test_is_woo']); }
}
$GLOBALS['cc_test_is_woo'] = true;
CardsCo_Shop::assets();
ck('the stylesheet lands on a shop page', wp_style_is('cardsco-shop', 'enqueued'));
ck('and it brings its fonts',             wp_style_is('cardsco-fonts-shop', 'enqueued'));
$GLOBALS['cc_test_is_woo'] = false;

/* The sheet is scoped to body.woocommerce so it can never reach the portal,
   which is .cc-main and .cc-wz. A bare `.button` or `.product` rule would
   collide with both. */
$css = file_get_contents(CARDSCO_PATH . 'assets/shop.css');
ck('the stylesheet ships', (bool) $css);
$bare  = preg_replace('/\/\*.*?\*\//s', '', (string) $css);
$leaks = [];
preg_match_all('/([^{}]+)\{/', $bare, $m);
foreach ($m[1] as $sel) {
	foreach (explode(',', $sel) as $one) {
		$one = trim($one);
		/* Skip blanks and at-rule preludes such as @media. */
		if ($one === '' || str_starts_with($one, '@')) { continue; }
		if (!str_starts_with($one, 'body.woocommerce') && !str_starts_with($one, 'body.single-product')) {
			$leaks[] = $one;
		}
	}
}
ck('every selector is scoped to the shop: ' . implode(' | ', array_slice($leaks, 0, 3)), count($leaks), 0);

/* The gallery override is the fix for the invisible card image: Woo ships the
   gallery at opacity 0 and the script that fades it in is never enqueued. */
ck('the gallery is forced visible', (bool) preg_match('/woocommerce-product-gallery\s*\{[^}]*opacity:\s*1\s*!important/s', $css));

printf("\n%s  (%d failed)\n", $fail ? 'FAIL' : 'all good', $fail);
exit($fail ? 1 : 0);
