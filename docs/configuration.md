---
title: Configuration
slug: configuration
order: 20
summary: Every setting on the Bird screen, and why the defaults are what they are.
---

The settings screen is **Settings → Plugins → Bird**. It is one page in eight sections.

## Connection

| Setting | Notes |
|---|---|
| **API token** | Accepts a `$ENV_VAR` reference. Use one. |
| **Administration** | The number in Moneybird's own URLs. |

## Documents

**Document type** decides which of Moneybird's two objects Bird creates, and it is the only
difference between the two ways of working:

- **Sales invoice** — Moneybird owns the invoice number, renders the PDF, and can email it. Use
  this when Moneybird is where your invoices come from.
- **External sales invoice** — the order reference *is* the invoice number and Moneybird only
  books the revenue and the VAT. No PDF, no sending. Use this when your shop already issues its
  own invoices.

They are not interchangeable at the API level — a sales invoice takes `invoice_date` and a payment
term, an external one takes `date`, `due_date` and a source URL back to the order — but everything
else in Bird behaves the same either way.

| Setting | Default | Notes |
|---|---|---|
| **Send to Moneybird when** | The order is paid | Or on completion, on reaching a status, or never. |
| **Trigger status** | — | Only when the trigger is *reaches a status*. |
| **Push through the queue** | On | Leave it on. See [Safety](usage#it-cannot-stop-a-checkout). |
| **Reference** | Order reference | What goes on the invoice: reference, order number, short number or element id. |
| **Invoice date** | Date ordered | Or the date paid, or today. |
| **Payment term** | 14 days | Sales invoices only — Moneybird derives the due date from it. |
| **Retry attempts** | 5 | How many times a failed push is worth retrying. |
| **Workflow**, **Document style** | — | Moneybird's own ids, if you use more than one. |
| **Skip zero-total orders** | On | A €0 order is usually a test or a fully-discounted comp. |

## Tax

| Setting | Notes |
|---|---|
| **Home country** | Two-letter code. The country whose VAT you charge by default. |
| **VAT rates** | Percentage → Moneybird tax rate id. **Suggest a mapping** fills it from your administration. |
| **Reverse-charge tax rate** | The 0% rate that prints *btw verlegd*. |
| **Export tax rate** | The 0% rate for sales outside the EU. |
| **One Stop Shop** *(Pro)* | Switches on per-country consumer rates. |
| **OSS rates** *(Pro)* | Country → percentage → rate id. |
| **VAT number field** | Defaults to the address's `organizationTaxId`, which is where Commerce's own validators read from. |
| **Reconcile totals** | On. Off refuses an order that will not reconcile instead of adding a rounding line. |

### Why rates are matched on money

Bird does not look a rate up by its percentage. Commerce rounds tax to the cent per line, so a
€10.10 line at 21% records €2.12 of tax — which divides back out as **20.99%**, a rate no shop has
ever configured. A percentage look-up would refuse ordinary orders every day.

Instead Bird takes the net and the tax as recorded and looks for the mapped rate whose arithmetic
lands within a cent (or within half a percent of the tax on larger lines). Whatever is left over
after every line is matched becomes the rounding line.

### Reconcile totals

The invoice total has to equal what the customer paid, because that is what the bank feed will
show. When per-line rounding leaves a cent of drift, Bird books it as a line at **0%** — a cent of
VAT-rounding drift is not revenue and should not be taxed as if it were.

Turn **Reconcile totals** off and Bird refuses the order instead, with the discrepancy in the
error. Some bookkeepers would rather hear about it than have it quietly papered over.

## Ledger accounts

| Setting | Notes |
|---|---|
| **Default revenue account** | Where line revenue is booked. Blank leaves it to Moneybird's own default. |
| **Shipping account** | Shipping is usually its own account. |
| **Discount account** | So is a discount. |
| **Per product type** *(Pro)* | Commerce product type handle → ledger account id. Falls back to the default. |

## Contacts

| Setting | Default | Notes |
|---|---|---|
| **Sync contacts** | On | Off books every invoice against the fallback contact. |
| **Match customers by** | Craft user | Or email, or a new contact per order, or don't set one. |
| **Address** | Billing | Which Commerce address becomes the contact's. |
| **Update existing contacts** | On | Bird fingerprints the last payload it pushed, so an unchanged customer costs no API call. |
| **Fallback contact** | — | Used when there is no usable customer data. |

Commerce ensures a Craft user for every order email — `Order::setEmail()` calls
`Users::ensureUserByEmail()` — so even a guest checkout has a user id to key a contact on. That is
why **Craft user** is the default rather than email.

## Payments

| Setting | Default | Notes |
|---|---|---|
| **Register payments** | On | Posts a payment against the booked document. |
| **Financial account** | — | Which Moneybird account the payment lands in. |
| **Credit refunds** *(Pro)* | On | Commerce refund transactions become credit notes. |

Payments are posted to `POST /sales_invoices/{id}/payments.json`. Moneybird has deprecated the
older `register_payment` endpoint; Bird does not use it.

## Webhook *(Pro)*

| Setting | Notes |
|---|---|
| **Webhook URL** | Read-only. This is the URL to register. |
| **Accept webhooks** | Off until you install one. |
| **Signing secret** | Moneybird returns it exactly once, on create. **Install webhook** stores it for you. |
| **Paid order status** | Optionally move the Commerce order to this status when Moneybird says the invoice is paid. |

See [the webhook](usage#the-webhook) for what it is for and how it is verified.

## Logging *(Pro)*

| Setting | Default | Notes |
|---|---|---|
| **Log connections** | On | One row per request. |
| **Keep payloads** | On | Request and response bodies, with tokens and secrets redacted. |
| **Keep entries for** | 30 days | `bird/log/prune` is worth putting on a cron. |

## Config file

Everything above can be set in `config/bird.php`, which wins over the database and is what you
want if the settings differ between environments:

```php
<?php
return [
    'apiToken' => App::env('MONEYBIRD_TOKEN'),
    'administrationId' => App::env('MONEYBIRD_ADMINISTRATION'),
    'documentType' => 'sales_invoice',
    'trigger' => 'paid',
    'homeCountry' => 'NL',
];
```

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
