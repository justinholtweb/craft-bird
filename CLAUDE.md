# Bird — Craft CMS 5 Plugin

## Project Overview

Bird books Craft Commerce 5 orders into **Moneybird**, the Dutch accounting service, as sales
invoices or external sales invoices, with EU-correct VAT. Distributed as `justinholtweb/craft-bird`.
**Lite (free) + Pro ($99 one-off, $49/year renewal).**

Bird is **not affiliated with or endorsed by Moneybird**. Every piece of marketing — README,
docs pages, the marketing site bands, and the promo slides — carries that disclaimer, and any
new marketing surface must too.

## Why it exists

Every Moneybird↔webshop bridge on the market is a Zapier-shaped thing that creates a contact and an
invoice and stops there. The parts that actually cost a bookkeeper their afternoon are the ones
nobody builds: reverse charge that is a *different 0% rate* from an export, One Stop Shop rates per
destination country, refunds that have to give the VAT back at the rate it went on at, and invoice
totals that reconcile to the cent against a bank feed. Bird is those parts.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies beyond Craft's own Guzzle

## Architecture

### Namespace & package

- Namespace: `justinholtweb\bird`
- Package: `justinholtweb/craft-bird`
- Handle: `bird`

### The three invariants

1. **`services\Invoices::buildPayload()` is the only place an order becomes Moneybird JSON.** The
   push, the console preview and the CP's Preview button all go through it, so what a merchant is
   shown before booking is byte-identical to what gets booked — tax rate ids included, which is
   where mistakes actually live.
2. **`services\Sync::pushOrder()` is the only place a document is created.** Event handler, queue
   job, CP button, console command and backfill all arrive there, so "is this already booked",
   "should this be skipped" and "is this worth retrying" are decided once.
3. **`services\Api::request()` is the only place an HTTP call goes out.** One token, one logger,
   one error flattener, one rate-limit handler.

### Data model

- `{{%bird_documents}}` — unique on `(orderId, kind, sourceKey)`; that index *is* the idempotency
  guarantee. `kind` is `invoice` or `credit`; `sourceKey` is empty for the invoice and the Commerce
  refund transaction hash for a credit note.
- `{{%bird_contacts}}` — Craft user / email → Moneybird contact id, with a fingerprint of the last
  payload pushed so an unchanged customer costs no API call.
- `{{%bird_log}}` — the connection log. No foreign key on `orderId`: a log row has to outlive the
  order it describes, which is the whole point of keeping one.

### The VAT position

**Commerce decides what to charge; Bird decides where to book it.** Re-deriving tax here would give
a shop two answers that drift apart, and the one on the customer's card is Commerce's. What
Moneybird needs on top of the number is the *reason*, because 0% is three different rates over
there (`btw verlegd`, export, genuinely zero-rated) that land in three different boxes on a return.

Reverse charge is a claim about what *was* charged, not what could have been: a VAT number on an
order that still paid 21% is a merchant who never enabled Commerce's VAT-number validator, and
booking it as reverse-charged would understate the return by exactly what the customer paid.

### Rates are matched on money, not on percentages

`Vat::matchRate($treatment, $tax, $net, $country)` picks the mapped rate whose arithmetic lands
within a cent (or half a percent of the tax on bigger lines). This is not a nicety — Commerce
rounds tax per line, so €10.10 at 21% records €2.12, which divides back out as **20.99%**. A
percentage look-up would refuse ordinary orders. Whatever is left over after matching becomes the
rounding line.

### Rounding line

The invoice total must equal what the customer paid, because that is what the bank will show. The
difference is booked at **0%**: a cent of VAT-rounding drift belongs on a line that does not
pretend to be revenue. `reconcileTotals: false` refuses the order instead, for shops that would
rather hear about it.

## Protocol notes (verified against the docs, not guessed)

Read out of `developer.moneybird.com`'s embedded OpenAPI schema, not its prose — the two disagree.

- Base is `https://moneybird.com/api/v2/{administration_id}/…`, `.json` on the end of every path,
  `Authorization: Bearer …`. `GET /administrations.json` is the only unscoped endpoint.
- **Details use `amount` (quantity) and `price` (unit price).** The reference prose mentions
  `amount_decimal`; that is a *response* field. Both create endpoints declare
  `unevaluatedProperties: false`, so sending it 422s the invoice. There is a check for this.
- Sales invoice create takes `invoice_date` + `first_due_interval`. External sales invoice create
  takes `date` + `due_date`, plus `source`/`source_url`. They are not interchangeable.
- Sending is `PATCH /sales_invoices/{id}/send_invoice.json` with a `sales_invoice_sending` wrapper
  (`delivery_method`, `email_address`, `email_message`).
- Payments are `POST /sales_invoices/{id}/payments.json` with a `payment` wrapper.
  `PATCH …/register_payment` still works but Moneybird has **deprecated** it.
- `GET /sales_invoices/find_by_reference/{reference}.json` is the recovery path after a crash
  between "invoice created" and "row written".
- Rate limit is 150 requests per 5 minutes (50 for `/reports/`), 429 with `Retry-After`.
  Pagination is `page`/`per_page` (max 100); `ledger_accounts` is explicitly not paginated.
- Webhooks: `Moneybird-Signature: t=…,v1=…`, HMAC-SHA256 over `"{t}.{raw body}"` — the *raw* bytes,
  hex, constant-time compared, rejected beyond five minutes. Several `v1` values appear during a
  secret rotation, so any match counts. The secret is returned exactly once, on create.
- The event names Bird subscribes to are real: `sales_invoice_state_changed_to_paid`, `…_to_late`,
  `…_to_uncollectible`, and the two `external_sales_invoice_…` equivalents.

## Traps found while building this

- **`craft\elements\Address::$organizationTaxId`** is where Commerce's own VAT validators read
  from, so it is Bird's default VAT-number source. Craft 5 dropped `phone` from addresses entirely.
- **Commerce ensures a Craft user for every order email** (`Order::setEmail()` calls
  `Users::ensureUserByEmail()`), so even a guest checkout has a user id to key a contact on.
- **`LineItem::getTotal()` is gross**: subtotal plus the line's non-included adjustments, with any
  *included* tax already inside the subtotal. Net is `getTotal() - getTax() - getTaxIncluded()`.
- **An order-level adjustment is net when the tax was added and gross when it was included.** Only
  one of those needs unwinding; unwinding both double-counts shipping.
- **A private property is not a Yii attribute**, so the three settings maps are invisible to
  Craft's settings save unless `attributes()` names them.
- **Plugin settings templates are namespaced by Craft itself** — a field named `settings[foo]` here
  posts as `settings[settings][foo]`, saves nothing, and still says "Plugin settings saved".
- **`_includes/statuses` does not exist in Craft 5**; order-status options come from
  `craft.bird.orderStatusOptions()` instead.
- **A model hydrated from `SELECT *` needs every column as a property** — `LogEntry::$dateUpdated`
  exists only because `getEntryById()` reads the whole row and Yii throws on an unknown key.
- **Never mark plugin settings `required`**: Craft validates them wholesale, so one required
  attribute means a fresh install cannot save the screen that would set it.

See `[[craft-plugin-gotchas]]` for family-wide traps, `[[project_craft_shipper]]` for the sibling
Commerce integration whose conventions this follows, and `[[project_craft_freshh]]` for the
FreshBooks equivalent.

## Languages

Moneybird publishes its own product in Dutch, Belgian Dutch, German and Belgian French, so Bird
ships the same four plus English. There are **two separate locale namespaces**, and they
deliberately do not match:

| | Where | Ids | Why |
|---|---|---|---|
| CP strings | `src/translations/<id>/bird.php` | `en`, `nl`, `nl-BE`, `de`, `fr-BE` | Craft locale ids. Yii's `PhpMessageSource` merges `nl-BE` over `nl`, so **`nl-BE` is an overlay** of the 12 strings that actually differ. `fr-BE` has no `fr` to fall back to, so it is complete. |
| Marketing site | `docs/<locale>/*.md` | `nl`, `nl-BE`, `de-DE`, `fr-BE` | URL segments on justinholt.com. **`de-DE`, not `de`** — the site maps a bare `de` to `hreflang="de-CH"` for the Swiss plugin next door. |

A translated doc keeps the **English `slug`** (`installation`), so the URL is
`/plugins/craft-bird/nl/docs/installation`. Only `title` and `summary` are translated.

`tests/integration/checks.php` does not assert on translated copy. What guards the catalogues is
that they are generated against the English key list, with placeholder survival (`{label}`,
`{percentage}`, …) verified at emit time.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-bird/tests/integration/checks.php   # 161 checks
ddev exec bash -c 'find /var/www/craft-bird/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The suite runs as Pro for the bulk of the run, exercises Lite in its own section, and restores the
edition, the settings and every fixture in a `finally`. Nothing in it talks to Moneybird: every
path that would is exercised up to the point the request goes out, which is where the decisions
worth testing are made. The webhook endpoint *is* tested over real HTTP with `curl` and `openssl
dgst` — unsigned, stale and correctly-signed all behave.

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so **every element save in the harness fatals**
while it is enabled. `checks.php` detaches that handler in-process (never persisted). That is a bug
in Penny, not in Bird.

## Coding conventions

- `Craft::t('bird', '…')` for user-facing strings; `src/translations/en/bird.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Anything that runs during checkout fails **open**: a Moneybird outage must never be able to stop
  a customer paying
- An unmapped rate fails **closed**: refuse the order and name the missing percentage
