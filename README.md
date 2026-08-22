# Bird

Moneybird bookkeeping for Craft Commerce 5. Paid orders become invoices with the right VAT on
them, contacts stay in sync, payments land against the invoice, and refunds come back off as
credit notes.

Bird takes the position that **Commerce decides what to charge and Bird decides where to book
it**. Commerce's tax engine already knows your zones, your rates and your VAT-number validators;
re-deriving all of that would give your shop two answers that drift apart, and the one the
customer actually paid is Commerce's. What Moneybird needs on top of the number is the *reason* —
0% reverse-charged, 0% exported and 0% zero-rated are three different rates over there, and they
land in three different boxes on a VAT return.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+
- A Moneybird administration and an API token

## Installation

```sh
composer require justinholtweb/craft-bird
php craft plugin/install bird
```

Then open **Settings → Plugins → Bird**:

1. Paste an API token (create one at moneybird.com → your profile → **Applications**) and the
   administration id — the number in Moneybird's own URLs. Both accept `$ENV_VAR` references, and
   a token is as powerful as your password, so use one.
2. **Test connection**, then **Suggest a mapping** to fill in the VAT table from the rates your
   administration already has.
3. Pick the 0% rate that prints *btw verlegd* as the reverse-charge rate, and the 0% export rate.

`php craft bird/sync/status` says the same things from a terminal.

## Editions

| | Lite (free) | Pro |
|---|---|---|
| Sales invoices or external sales invoices | ✅ | ✅ |
| Automatic push on paid / completed / a status | ✅ | ✅ |
| Contact create, match and update | ✅ | ✅ |
| Domestic, reverse-charge and export VAT | ✅ | ✅ |
| Payment registration | ✅ | ✅ |
| Rounding reconciliation | ✅ | ✅ |
| Panel on Commerce's order screen, with a byte-exact preview | ✅ | ✅ |
| Documents index, `craft.bird.*` Twig API, console commands | ✅ | ✅ |
| **One Stop Shop** — per-country EU consumer rates | — | ✅ |
| **Credit notes** for Commerce refunds | — | ✅ |
| **Send the invoice** from Moneybird | — | ✅ |
| **Paid-invoice webhook**, signature-verified | — | ✅ |
| **Per-product-type ledger accounts** | — | ✅ |
| **Connection log** with request and response payloads | — | ✅ |

## Two ways to book an order

**Sales invoice.** Moneybird owns the invoice number, produces the PDF, and can email it. Use this
when Moneybird is where your invoices come from.

**External sales invoice.** The order number *is* the invoice number, and Moneybird just books the
revenue and the VAT — no PDF, no sending. Use this when your shop already issues its own invoices
and you only need the bookkeeping.

Both are one setting apart, and everything else in Bird works the same either way.

## How the VAT engine decides

| Situation | Treatment | Rate used |
|---|---|---|
| Customer in your home country | Domestic | The mapped percentage |
| EU business, VAT number matching its country, no tax charged | Reverse charge | Your *btw verlegd* rate |
| EU consumer, OSS on | OSS | That country's mapped rate |
| EU consumer, OSS off | Home rate | The mapped percentage |
| Outside the EU | Export | Your export rate |

A VAT number on an order that still paid 21% is **not** reverse charge — it is a shop that never
turned Commerce's VAT-number validator on, and booking it as reverse-charged would understate the
return by exactly what the customer paid. Bird books what happened.

Rates are matched on the **money**, not on a derived percentage. Commerce rounds tax to the cent
per line, so €10.10 at 21% records €2.12 — which divides back out as 20.99%, a rate no shop has
ever configured. Bird looks for the mapped rate whose arithmetic lands within a cent, and puts the
remainder on a rounding line so the invoice total always equals what the customer paid.

## The order screen

Every Commerce order gets a Moneybird panel: what has been booked, the VAT treatment and number
Bird read off the address, **Send to Moneybird**, **Credit refunds**, and **Preview invoice** —
which runs the same `Invoices::buildPayload()` the push does, so the JSON you are shown is the JSON
that gets sent, tax rate ids and all.

## Twig

```twig
{% set document = craft.bird.documentForOrder(order) %}

{% if document and document.getIsBooked() %}
    <p>Invoice {{ document.getLabel() }} — {{ document.getStateLabel() }}</p>

    {# A capability URL: only ever render it to the customer whose order it is. #}
    {% if document.publicUrl %}
        <a href="{{ document.publicUrl }}">View your invoice</a>
    {% endif %}
{% endif %}

{% set vat = craft.bird.vatTreatment(order) %}
{% if vat.reverseCharge %}
    <p>VAT reverse-charged — {{ vat.vatNumber }}</p>
{% endif %}
```

## Console

```sh
craft bird/sync/status                    # connection, edition, document counts
craft bird/sync/order 1042                # book one order, by reference, number or id
craft bird/sync/backfill --since=2026-01-01 --dry-run
craft bird/sync/retry                     # the pushes that failed and have attempts left
craft bird/sync/refunds 1042              # credit an order's refunds

craft bird/inspect/preview 1042           # the exact JSON, without sending it
craft bird/inspect/tax-rates              # ids for the VAT mapping
craft bird/inspect/ledger-accounts
craft bird/inspect/financial-accounts
craft bird/inspect/administrations

craft bird/webhooks/install
craft bird/webhooks/info
craft bird/webhooks/remove

craft bird/log/prune --days=30
```

## The webhook

Bird pushes invoices; Moneybird is where they get *paid* — matched against a bank feed, or marked
paid by hand. Without the webhook, your site's idea of "paid" is whatever the payment gateway said
at checkout, which misses every bank transfer.

**Install webhook** registers it and stores the signing secret Moneybird shows exactly once. Every
delivery is verified against `Moneybird-Signature` — HMAC-SHA256 over `{timestamp}.{raw body}`,
rejected if the timestamp is more than five minutes out. No secret, no signature, no stale
timestamp, no request: it fails closed.

## Safety

- Nothing Bird does can stop a customer paying. Every trigger swallows its own failures; the push
  runs in the queue by default.
- The document table is unique on `(order, kind, source)`. A retried job, a double-clicked button
  and a webhook racing the queue cannot book the same revenue twice.
- If a push dies between "Moneybird created the invoice" and "Bird wrote the row", the next
  attempt finds the invoice by reference and adopts it rather than booking a second one.
- An unmapped VAT rate refuses the order and says which percentage is missing. An invoice booked
  against the wrong tax rate is worse than one that did not get booked, because nobody goes
  looking for it.
- Tokens and webhook secrets are redacted out of stored payloads.

## Support

justin@justinholt.com
