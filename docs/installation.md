---
title: Installation
slug: installation
order: 10
summary: Requirements, install, connecting an administration, and mapping your VAT rates.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A Moneybird administration, and an API token for it

## Install

```sh
composer require justinholtweb/craft-bird
php craft plugin/install bird
```

Or find **Bird** in the Craft Plugin Store and install it from there.

## Nothing is booked until it is configured

Bird installs inert. Until there is a token, an administration id and a VAT mapping, no order goes
anywhere — the triggers check `isConfigured()` and return. Installing a plugin should never start
posting to your books on its own.

## Connect the administration

Open **Settings → Plugins → Bird**.

1. **API token.** Create one in Moneybird under your profile → **Applications** → *API tokens*. A
   token is as powerful as your password, so put it in an environment variable and reference it
   here as `$MONEYBIRD_TOKEN` rather than pasting the value into the database.
2. **Administration.** The number that appears in Moneybird's own URLs. Press **List
   administrations** if you would rather pick it off a list than copy it.
3. Press **Test connection**. It calls `GET /administrations.json` — the one endpoint that is not
   scoped to an administration — so it tells you whether the *token* works even when the id is
   wrong.

## Map your VAT rates

This is the step that matters, and the one Bird will not guess at.

1. Go to the **Tax** section and set your **Home country** — the country whose VAT you charge by
   default. It defaults to `NL`.
2. Press **Suggest a mapping**. Bird reads the tax rates that already exist in your administration
   and proposes a percentage → rate id table. Copy it into **VAT rates**.
3. Set the **Reverse-charge tax rate** to the 0% rate in your administration that prints
   *btw verlegd*, and the **Export tax rate** to the 0% rate you use for sales outside the EU.

Those last two are separate settings because 0% is not one thing in Moneybird. Reverse-charged,
exported and genuinely zero-rated are three different rates that land in three different boxes on
a VAT return, and picking the wrong one produces a return that balances and is still wrong.

**An unmapped rate refuses the order** and names the percentage that is missing. That is
deliberate: an invoice booked against the wrong tax rate is worse than one that did not get booked,
because nobody goes looking for it.

## Check it from a terminal

```sh
php craft bird/sync/status
```

Connection, edition, configuration and document counts, without opening the CP.

## Editions

Lite is free and books invoices with correct domestic, reverse-charge and export VAT. Pro is a
one-off $99 with a $49/year renewal, and adds the parts that come up once you sell across borders
or take refunds.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$99**, $49/year renewal |
| Sales invoices or external sales invoices | ✅ | ✅ |
| Push on paid, on completed, or on an order status | ✅ | ✅ |
| Contacts created, matched and updated | ✅ | ✅ |
| Domestic, reverse-charge and export VAT | ✅ | ✅ |
| Rates matched on the money, not on a percentage | ✅ | ✅ |
| Rounding reconciliation | ✅ | ✅ |
| Payment registration | ✅ | ✅ |
| Panel on Commerce's order screen, with a byte-exact preview | ✅ | ✅ |
| Documents index, `craft.bird.*` Twig API, console commands | ✅ | ✅ |
| **One Stop Shop** — per-country EU consumer rates | — | ✅ |
| **Credit notes** for Commerce refunds | — | ✅ |
| **Send the invoice** from Moneybird | — | ✅ |
| **Paid-invoice webhook**, signature-verified | — | ✅ |
| **Per-product-type ledger accounts** | — | ✅ |
| **Connection log** with request and response payloads | — | ✅ |

## Existing orders

Installing Bird does not touch orders that were already paid. To bring them across, use
[backfill](usage#backfill), which has a `--dry-run` that reports what it would book without
booking any of it.

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
