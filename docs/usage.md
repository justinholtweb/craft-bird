---
title: Usage
slug: usage
order: 30
summary: Booking orders, the order-screen panel, backfill, refunds, the webhook, Twig and the console.
---

## The normal path

An order is paid. Commerce fires its event, Bird pushes a job onto the queue, and the job builds a
payload and creates a document in Moneybird. A row goes into Bird's document table, a payment is
registered against the invoice, and the Moneybird panel on the order screen fills in.

You do not have to do anything for that to happen, once the plugin is configured.

## The order screen

Every Commerce order gets a **Moneybird** panel:

- what has been booked, with a link into Moneybird
- the **VAT treatment** and the VAT number Bird read off the address
- **Send to Moneybird** — book it now, whatever the trigger says
- **Credit refunds** *(Pro)* — credit any refund transactions that are not credited yet
- **Preview invoice** — the exact JSON, without sending it

Preview runs the same `Invoices::buildPayload()` that the push runs. There is one code path that
turns an order into Moneybird JSON, and the preview is it, so what you are shown before booking is
byte-identical to what gets booked — tax rate ids included, which is where the mistakes actually
live.

## How the VAT engine decides

| Situation | Treatment | Rate used |
|---|---|---|
| Customer in your home country | Domestic | The mapped percentage |
| EU business, VAT number matching its country, no tax charged | Reverse charge | Your *btw verlegd* rate |
| EU consumer, OSS on *(Pro)* | OSS | That country's mapped rate |
| EU consumer, OSS off | Home rate | The mapped percentage |
| Outside the EU | Export | Your export rate |

**Commerce decides what to charge; Bird decides where to book it.** Commerce's tax engine already
knows your zones, your rates and your VAT-number validators. Re-deriving all of that here would
give your shop two answers that drift apart, and the one the customer actually paid is Commerce's.

Note the third column of the reverse-charge row. Reverse charge is a claim about what *was*
charged, not what could have been. A VAT number on an order that still paid 21% is a shop that
never turned Commerce's VAT-number validator on, and booking it as reverse-charged would
understate the return by exactly what the customer paid. Bird books what happened.

## Backfill

Orders that were already paid when you installed Bird are not touched until you ask.

```sh
php craft bird/sync/backfill --since=2026-01-01 --dry-run
php craft bird/sync/backfill --since=2026-01-01 --limit=250
```

`--dry-run` reports every order it would book, and the treatment it would book it under, without
sending anything. Run it first. `--limit` defaults to 100 so a backfill cannot walk into the rate
limit on its own.

## Refunds *(Pro)*

A Commerce refund transaction becomes a Moneybird credit note, one per transaction, at the rate
the VAT went on at. Credit notes are keyed on the refund transaction's hash, so crediting the same
refund twice is not possible — the unique index on `(order, kind, source)` refuses it.

```sh
php craft bird/sync/refunds 1042
```

Or press **Credit refunds** on the order.

## The webhook *(Pro)*

Bird pushes invoices; Moneybird is where they get *paid* — matched against a bank feed, or ticked
off by hand. Without the webhook, your site's idea of "paid" is whatever the payment gateway said
at checkout, which misses every bank transfer.

```sh
php craft bird/webhooks/install
php craft bird/webhooks/info
php craft bird/webhooks/remove
```

**Install webhook** registers it and stores the signing secret, which Moneybird returns exactly
once, on create. Every delivery is then verified against the `Moneybird-Signature` header —
HMAC-SHA256 over `{timestamp}.{raw body}`, compared in constant time, rejected if the timestamp is
more than five minutes out. Several signature values appear during a secret rotation, so any match
counts.

No secret, no signature, or a stale timestamp: no request. It fails closed.

Bird subscribes to the paid, late and uncollectible events for both document types. Set **Paid
order status** if you want a paid invoice to move the Commerce order.

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

`craft.bird.documentsForOrder(order)` returns the invoice and every credit note.
`craft.bird.invoiceUrl(order)` is a shortcut to the public URL, and
`craft.bird.isConfigured()` is there so a template can stay quiet on an install that is not set up
yet.

## Console

```sh
craft bird/sync/status                    # connection, edition, document counts
craft bird/sync/order 1042                # book one order, by reference, number or id
craft bird/sync/order 1042 --force        # book it again even though it is booked
craft bird/sync/backfill --since=2026-01-01 --dry-run --limit=100
craft bird/sync/retry --limit=100         # the pushes that failed and have attempts left
craft bird/sync/refunds 1042              # credit an order's refunds

craft bird/inspect/preview 1042           # the exact JSON, without sending it
craft bird/inspect/tax-rates              # ids for the VAT mapping
craft bird/inspect/ledger-accounts
craft bird/inspect/financial-accounts
craft bird/inspect/workflows
craft bird/inspect/administrations

craft bird/webhooks/install
craft bird/webhooks/list
craft bird/webhooks/info
craft bird/webhooks/remove

craft bird/log/prune --days=30            # worth putting on a cron
craft bird/log/clear
```

## It cannot stop a checkout

Anything that runs during checkout fails **open**. Every trigger swallows its own failures and the
push runs in the queue by default, so a Moneybird outage, an expired token or a rate limit can
never be the reason a customer could not pay. What you get instead is a document row in the
`failed` state, a reason on it, and `bird/sync/retry`.

The one thing that fails **closed** is an unmapped VAT rate: Bird refuses the order and names the
missing percentage. That refusal happens on the Bird side of the line, not the checkout side.

## Booking the same revenue twice

You cannot. `{{%bird_documents}}` is unique on `(orderId, kind, sourceKey)` — `sourceKey` is empty
for the invoice and the refund transaction hash for a credit note — and that index *is* the
idempotency guarantee. A retried job, a double-clicked button and a webhook racing the queue all
collide on it.

If a push dies in the gap between "Moneybird created the invoice" and "Bird wrote the row", the
next attempt looks the invoice up with
`GET /sales_invoices/find_by_reference/{reference}.json` and adopts it rather than booking a
second one.

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
