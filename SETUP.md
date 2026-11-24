# CardsCo — setup and operations

What this plugin needs around it to work, and why. Written because the
answers were living in a chat log, which is not a place anyone can look
things up six months from now.

Anything marked **CONFIRM** has not been verified against the live site.
Check it before relying on it.

---

## What this is

A WordPress plugin for cardsco.uk: a service that posts handwritten cards
on a company's behalf. It runs three things that look similar and are not:

| | What it is | Who uses it |
|---|---|---|
| **Occasions** | Recurring. Driven by dates each person holds — birthdays, work anniversaries. Set up once and it keeps running. | Signed-in company customers |
| **Campaigns** | One-off. One date you choose, to a group you choose. | Signed-in company customers |
| **Send a Card** | One card to one recipient. The only flow that works without an account. | Visitors off the website |

Everything bills through WooCommerce and takes payment through Stripe.

---

## The pieces

**Plugin:** `wp-content/plugins/cardsco/` — version 0.57.0 at the time of
writing. Database schema version is tracked separately in the option
`cardsco_db_version`; the plugin upgrades its own tables on activation.

**Its own tables**, all prefixed `cardsco_`: companies, company_users,
occasions, occasion_types, campaigns, campaign_recipients, boxes,
card_queue, import_batches, activity_log, and others. These are not posts.
Cards and message templates *are* posts (`cardsco_card`, `cardsco_message`).

**WooCommerce** provides the basket, the checkout and the orders. Each
card has a matching WooCommerce product, created and kept in step by the
plugin. Those products exist to carry a price — they are not a shop front.
The catalogue shortcode is the shop front.

**Stripe** takes the payment, through WooCommerce.

---

## Pages the portal needs

Each of these is a WordPress page carrying one shortcode. On this site the
shortcodes live in **Oxygen templates**, not in the page content — which
matters, because anything that searches page content will not find them.

| Shortcode | What it is |
|---|---|
| `[cardsco_dashboard]` | The portal's front page |
| `[cardsco_occasions]` | Occasions, and the seven-step set-up wizard |
| `[cardsco_campaigns]` | Campaigns, and the seven-step builder |
| `[cardsco_orders]` | Orders |
| `[cardsco_settings]` | Company settings |
| `[cardsco_send]` | The Send a Card flow, for visitors with no account |
| `[cardsco_nav]` | The portal's navigation |

`[cardsco_nav]` takes the portal URLs as attributes. Keep them pointing at
portal pages: an address under `/wp-admin/` is a button that answers
"Sorry, you are not allowed to access this page", because portal customers
are deliberately blocked from wp-admin. The plugin now ignores any such
attribute, but the attribute is still worth removing.

**How the plugin finds these pages:** each screen records its own address
as it renders, in an option named `cardsco_page_url_<shortcode>`. So the
"back to your campaigns" button on an order confirmation only appears
after somebody has opened that page at least once. Visit each portal page
once after an update and they fill themselves in.

---

## Scheduling — the part that breaks quietly

Cards are queued, written and posted on dates. WordPress has no clock: its
scheduled work only runs when somebody loads a page. On a quiet site, a
day's cards sit unqueued until the next visitor arrives, and nothing says
so.

**A server cron job is what makes this reliable.** On Hostinger, under
Advanced → Cron Jobs:

```
*/15 * * * *   /usr/bin/php /home/<account>/domains/cardsco.uk/public_html/wp-cron.php
```

This is already in place. Running wp-cron.php directly through PHP is
better than fetching it over HTTP — no request to time out.

**CONFIRM:** that `wp-config.php` contains
`define( 'DISABLE_WP_CRON', true );` above the "stop editing" line.
Without it WordPress also fires cron on page loads, which is not broken,
just duplicated.

**To force it:** CardsCo → Boxes → **Run the scan now**. It does what the
daily scan does and reports what it did. Useful for finding out whether
scheduling works without waiting until the date to learn that it does not.

**What the scan does:** makes boxes for occasions whose writing week has
come round, queues cards for campaigns that have reached their lock date,
and queues individual cards. The lock date is three days before
arrival — `campaign_lock_days` in settings — because the list has to settle before
anyone starts writing.

---

## Fulfilment

Cards are written by hand, so nothing can know when that happened except
the person who did it. The system queues; a person marks.

Card statuses: Queued → Being written → Written → Posted → Delivered, plus
On hold, Cancelled and Failed.

- **CardsCo → Campaigns**: "Mark written" and "Mark posted" do a whole
  campaign at once. Cards on hold are left alone — a card is held because
  it cannot go out, usually a missing address, and marking it posted would
  tell the customer something untrue.
- **CardsCo → Boxes**: the same for an occasion's box.

The customer's progress bar counts written, posted and delivered cards and
reads them live, so marking in admin shows in the portal immediately.

Marking cards posted before they are actually in the post is the one
reliable way to make the portal lie to a customer. Worth saying out loud
to whoever ends up doing it.

---

## Pricing

Set under CardsCo → Settings. The ones that decide what people pay:

- `price_default` — the standard price for a card (currently £3.60)
- `price_bulk` (£1.99) and `bulk_from` (25) — the bulk rate, and the
  quantity it starts **at**. **Whole-order, not tiered**: once an order
  reaches the limit every card in it is at the bulk rate. Twenty-five cards
  is 25 × £1.99 = £49.75, thirty is £59.70, a hundred is £199.
  - This is what the published terms promise, so the two have to move
    together. It was tiered until 19 Nov 2026 (first 25 at standard, only
    the rest at bulk, so thirty came to £99.95); the CEO confirmed the terms
    were what he meant and the code was changed to match.
  - There is a **deliberate cliff** at the limit: 24 cards cost £86.40 and
    25 cost £49.75, so an order just under the limit is worse value than one
    at it. That is the shape of a whole-order discount, not a bug — don't
    "fix" it without changing the terms page too.
- `currency` (GBP), `message_max_chars` (400), `campaign_lock_days` (3),
  `postage_days`, `box_production_days`

**Postage is included in the card price.** There is deliberately no
postage charge and no setting for one: adding one would charge twice.

---

## Things that have bitten, and why they are set the way they are

**Card products must not be "sold individually".** WooCommerce reads that
flag as a hard cap of one per basket line and silently clamps the
quantity, so an occasion for forty people reached the checkout as a single
card. The plugin clears the flag on the product at the moment it goes into
the basket, so this cannot depend on a migration having run.

**The block checkout does not fire `woocommerce_checkout_create_order`.**
WooCommerce's Store API builds the order itself. The line-level hook fires
and the order-level one does not, so anything written only at order level
is lost. The plugin reads its ids from the order *and* the lines.

**Guest checkout is forced on for single-card baskets.** The Send a Card
flow is built for people without accounts, and WooCommerce's "customers
must register" setting would otherwise turn it into a dead end. Only that
basket is affected.

**Card products are not buyable directly.** `?add-to-cart=` on any URL and
the product pages both redirect into the Send a Card flow, and a guard
behind that refuses anything else. A bare card has no recipient, no
address and no message, so an order for one cannot be fulfilled.

---

## Still to confirm

These were set up outside the plugin and have not been verified here:

- **CONFIRM** `DISABLE_WP_CRON` in `wp-config.php`
- **CONFIRM** Stripe: whether live keys are in place, and whether test mode
  is still on
- **CONFIRM** WooCommerce → Settings → Accounts & Privacy: guest checkout,
  and which page is set as My Account
- **CONFIRM** DNS and Cloudflare settings for cardsco.uk
- **CONFIRM** SMTP, and which address transactional email sends from

---

## Still to build

- **Settings → Billing** is an empty shell. Until it exists, no company can
  save its own billing address, and the checkout falls back to the address
  the cards are posted to.
- **Handwriting styles** have no options to choose from, so that field
  shows a dash. Waiting on the list from the client.
- **"Send this campaign now"** — no way to queue a campaign's cards ahead
  of its lock date.
- Several occasions have no fixed date set: Easter, Eid al-Fitr, Diwali,
  Thanksgiving, Mothering Sunday, Father's Day.

---

## Tests

The suite lives outside the plugin and is worth keeping beside it in the
repository. `./run.sh /path/to/wordpress` runs everything.

It runs against a model of WooCommerce, not WooCommerce itself. That model
has been wrong four times, and each time it hid a real bug that reached the
live site. **If a test passes and the live site disagrees, suspect the
model first.**
