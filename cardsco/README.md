# CardsCo

Corporate greeting card platform. Recipient management, occasion scheduling, campaigns and fulfilment.

Built by PT Digital Studio.

## Install

1. Upload the zip via **Plugins → Add New → Upload Plugin**.
2. Activate. On activation the plugin creates its 13 tables, adds two roles, seeds 24 occasion types and schedules the daily scan.
3. Add the server cron (below). Everything else works without it, but cards will be queued late.

## Server cron — required before launch

Card scheduling cannot depend on WP-Cron, which only fires when somebody visits the site. On a quiet morning that means a birthday card gets queued after it should already have been written.

In `wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define( 'DISABLE_WP_CRON', true );
```

Then in Hostinger → Advanced → Cron Jobs, every 15 minutes:

```
wget -q -O - https://cardsco.co.uk/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

The plugin shows a warning on the Plugins screen until `DISABLE_WP_CRON` is set.

## Roles

| Role | Where they work | What they can do |
|---|---|---|
| CardsCo Company | Front-end dashboard only, wp-admin blocked | Recipients, segments, campaigns, their own company settings |
| CardsCo Staff | wp-admin | Fulfilment queue, card catalogue, all companies |

Administrators hold every capability.

## Tables

All prefixed `wp_cardsco_`.

| Table | Holds |
|---|---|
| `companies` | The account that buys cards |
| `company_users` | WP users linked to a company, with a role inside it |
| `recipients` | Customers and employees, separated by `type` |
| `occasion_types` | The occasion library. Company 0 rows are global |
| `occasions` | One row per recipient per occasion |
| `segments` | Named audience groups |
| `recipient_segments` | Segment membership |
| `campaigns` | A send to a segment, built in the seven-step builder |
| `campaign_recipients` | The resolved audience, with exclusion reasons |
| `card_queue` | One row per physical card, whatever created it |
| `settings` | Per company, company 0 for platform defaults |
| `import_batches` | Backs the four-step import |
| `activity_log` | Audit trail and dashboard activity feed |

Orders and payments tables are deliberately absent until the invoice-versus-card billing question is answered. `campaigns` already carries the pricing and billing columns either answer needs.

### Design notes

- **One recipients table**, not two. Customers and employees differ by `type`, which is what the dashboard's toggle switches on.
- **`occasions.next_occurrence`** is a cached date the scheduler maintains, so the daily scan is one indexed range query rather than date arithmetic over every row.
- **Unknown birth years are normal.** Month and day are the source of truth; the year is optional.
- **29 February** is marked on the 28th in non-leap years.
- **Moveable dates** (Easter, Eid, Diwali, Mothering Sunday, Father's Day, Thanksgiving) are flagged `requires_annual_date` rather than given a fixed date, so staff confirm them each year instead of the plugin calculating them wrongly.
- **`card_queue` snapshots** the name, address, card title and message at queue time, so editing a recipient cannot change a card already with the writers.
- **`UNIQUE KEY (occasion_id, event_date)`** on `card_queue` is what stops the daily scan queueing the same birthday twice.
- **`recipients.last_order_date`** is nullable and unused. It costs nothing now and makes the lapsed-customer filter work without a migration if the client confirms companies will supply order dates.

## Staff screens

A **CardsCo** menu appears in wp-admin for administrators and CardsCo Staff.

- **Companies** — create a company and see how many recipients it holds. Everything else hangs off a company, so this comes first.
- **Recipients** — filter by type, segment, address state and status; search by name, email or company; add, edit, archive, restore and delete. Counts across the top show how many people are missing a usable address, which is the number that decides whether a send goes out clean.
- **Segments** — create and rename segments, see live member counts, jump to the people in one.

These are internal screens. The company-facing dashboard is a separate front-end build in segment 9; this is where staff look at a company's data, and where the build gets tested before that exists.

- **Occasions** — two tabs. *Upcoming* lists what falls due in the next 7 to 365 days, with the write-by date and whether the address will hold the card back. *Occasion library* is the 25 shared occasions plus anything the company adds, with per-occasion lead times and the yearly confirmation for moveable dates.

### Occasions

Dated occasions (birthday, work anniversary) get a row per person and are set on the recipient form. Seasonal occasions (Christmas, Valentine's) never do — a date that is the same for everybody belongs on the type, not on thousands of identical rows. They drive campaigns instead, where the audience is a segment.

- **Birth years are optional.** Month and day are the source of truth. One-off occasions (wedding, new baby) do need a year, and are refused without one.
- **29 February** is marked on the 28th in non-leap years.
- **Moveable dates** — Easter, Eid, Diwali, Mothering Sunday, Father's Day, Thanksgiving — are confirmed per year on the library tab. Until a year is confirmed nothing is scheduled for it, and the Occasions screen says so. That is deliberate: a guessed Easter sends cards in the wrong week and nobody finds out until they land.
- **`next_occurrence`** is recalculated on every save, and the hourly maintenance job rolls it forward once a date has passed. One-off occasions switch themselves off once they have happened rather than being rescanned forever.
- **Lead time** is per occasion type, defaulting to the company's `default_lead_days`. The Upcoming tab flags anything already past its write-by date as Overdue.

### Import

Four steps: upload, match columns, set occasions, check what we found. Nothing is written until the last step, so the review is a real dry run of the same code that does the work.

- **Columns are matched automatically** from their names, including date columns, which are offered as occasions. Anything it gets wrong is changed on screen.
- **Date order is worked out from the file.** A column holding 25/12/1990 can only be day-first; one holding 12/25/1990 can only be month-first. When every date in a column reads either way the screen says so and asks, rather than guessing — read wrongly, a birthday lands in the wrong month and nobody finds out until the card arrives.
- **Formats read correctly**: ISO, 20200214, 14/02/2020, 14.02.20, 14 Feb 2020, Feb 14 2020, 3rd March 1985, bare 14/02 with no year, Excel serial numbers, and values with a time on the end. Anything unreadable is reported against its line number rather than guessed at.
- **Two-digit years go backwards**: '65 is 1965, not 2065, because every date in a file like this is in the past.
- **Duplicates are caught twice** — against people already on the list, by email then by name and address, and within the file itself, reported with the line number of the earlier copy.
- **Updating never blanks a stored value.** A column the spreadsheet does not have leaves what is already known untouched.
- **Addresses are composed** from whichever of line 1, line 2, town, county and postcode exist, as they should appear on the envelope.
- **Full names are split** on the last space, and "Watson, John" is understood.
- **Segments** can come from a column, created as needed, and everyone in the file can be added to existing segments.
- **Imports run in chunks of 200** with the page continuing itself between them, so a large file cannot hit the PHP time limit and leave the list half imported.
- **Uploaded files hold names and addresses**, so they go in a directory closed off from the web with a random filename, and are deleted once the rows are saved.

### Cards and messages

Two post types under the CardsCo menu, not custom tables: the catalogue holds tens of items rather than tens of thousands, and it wants an image library and an editing screen that WordPress already provides.

- **Cards** carry a reference, price, orientation, size and a featured image, which is the front of the card. Published means offered when building a campaign; a draft is not.
- **Messages** are plain text in post meta, not the editor. A handwritten card has no paragraphs or bold, and running it through the block editor would only add markup that has to be stripped again before writing.
- **Occasions are stored one meta row per occasion**, not as a serialised list, so the campaign builder can query them properly. Tick none and the item is offered for every occasion.
- **Price is per card**, falling back to the standard price in settings. Currency symbols and thousands separators typed into the box are cleaned off.
- **Company ownership** is stored on every item as 0, meaning the shared catalogue. Per-company artwork is still an open question with the client; storing the column now means switching it on later needs no migration.

### Merge fields

`{first name}` `{last name}` `{full name}` `{company}` `{last order}` — written however you like, so `{First Name}`, `{first_name}` and `{first-name}` all work.

The rule that matters: a field with no value for a particular person is **never** replaced with an empty string. The token stays visible and the card is held back, because a card reading "Thanks for your custom, " is worse than one that was never written. Unknown tokens behave the same way.

The editing screen shows how a message reads with example names filled in, and counts the characters **after** filling in, so a 395-character message that overflows once a long name is substituted is caught while it is being written rather than when a campaign refuses to send. Saving a message with a problem gives a warning listing exactly what is wrong.

`{last order}` is flagged on save as needing data CardsCo does not hold yet — one of the open questions. The field works; the data behind it does not exist.

### Scheduling and the card queue

The daily scan looks ahead `queue_horizon_days` (30) and puts a card in the queue for each occasion coming up, in time to be written and posted.

- **It never chooses artwork.** A company sets a standing card and wording per personal occasion, on Occasions → Occasion library. Until both are set nothing is queued for that occasion, and the queue screen names which occasions are waiting. Guessing which design to send is not a guess worth making.
- **It cannot send the same card twice.** A unique key on (occasion, event date) enforces that in the database rather than trusting the scan to run exactly once. Running it five times in a row produces one card.
- **Everything is snapshotted at queue time** — name, address, card title, finished wording, price. Editing a recipient afterwards never changes a card already with the writers.
- **Cards are held, not skipped, when something is missing.** An incomplete address holds the card with reason `no_address`; a message with a field the recipient has no value for holds it with `message_gaps` and leaves the token visible rather than writing a gap.
- **A held card releases itself.** Hourly maintenance re-checks anything held for an address and puts it back in the queue once the address is filled in, updating the snapshot as it goes.
- **Lead time resolves** occasion override, then occasion type, then the company default. `write_by` is the event minus lead days; `post_by` is the event minus `postage_days` (2).
- **Seasonal occasions are never queued automatically** — they go out as campaigns, where a person chooses the card.
- **Long runs continue themselves.** A scan handles 500 occasions, then schedules itself to pick up the rest a couple of minutes later rather than timing out.

### Behaviour worth knowing

- **Archive is the normal way to remove somebody.** It keeps their card history. Delete is permanent and also removes their occasions and segment membership, but cards already queued are kept, because they carry their own snapshot and may already be written.
- **Duplicate detection** compares name plus a normalised address hash, so "12 High St." and "12 high street" are the same address. Adding a duplicate is blocked unless the tick box on the form is used. It is skipped entirely when there is no address to compare.
- **Postcodes** are uppercased and spaced on save, but only when they actually look like UK postcodes. Anything else is stored exactly as typed.
- **Segment membership is company-scoped.** A recipient can never be put into another company's segment, even if an ID is forced.

## Settings

Platform defaults live against company 0 and are overridable per company. Values waiting on a client decision are stored as settings rather than hard-coded, so changing them is a database update and not a code change:

- `billing_mode` — `invoice` (provisional) or `card`
- `default_lead_days` — 7 (the brief says 5, the designs say 7)
- `bulk_mode` — `quote` (provisional) or `checkout`
- `allow_custom_artwork` — 0 (provisional)
- `price_employee_birthday` — 3.60

## Uninstall

Deleting the plugin leaves all data in place. To wipe it, add to `wp-config.php` before deleting:

```php
define( 'CARDSCO_REMOVE_DATA_ON_UNINSTALL', true );
```

## Build order

Segment numbers are the plan; the plugin's own version number is separate and
simply climbs with each release. They are not the same counter.

1. ~~Scaffold, roles, settings, schema~~ — done
2. ~~Recipients and segments~~ — done
3. ~~Occasion types and occasions~~ — done
4. ~~Import pipeline~~ — done
5. ~~Card catalogue and message library~~ — done
6. ~~Scheduling cron and card queue~~ — done
7. ~~Occasion setup wizard and the monthly box engine~~ — done (0.8.0).
   Replaced the original "campaigns and the builder" plan after the approved
   designs landed on 31 Oct.
8. ~~Admin fulfilment screens~~ — done (0.9.0)
9. Front-end dashboard rendering — needs brand tokens from the client
10. Orders and payment — card payment, charged monthly before each box
11. Campaigns and the builder — displaced from slot 7 by the redesign and not
    re-slotted at the time. The approved designs carry a Campaigns item in the
    sidebar with a count badge, and the Promotions & Offers category depends on
    it. Until it is built, a campaign-driven occasion can be drafted but
    activation is refused, so nothing can be switched on that would silently
    never send.

Then: handover document, and two weeks of bug-fix support after launch.
