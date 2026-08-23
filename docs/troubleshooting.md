---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What the common failures mean and what to do about them.
---

Start with `php craft bird/sync/status`. It reports the connection, the edition, whether the
plugin considers itself configured, and the document counts by state — which is usually enough to
tell a configuration problem from a Moneybird problem.

On Pro, **Bird → Log** has the request and the response for every call, with tokens and secrets
redacted.

## "No tax rate is mapped for 21%"

Bird refused the order rather than guess. Go to **Settings → Plugins → Bird → Tax**, press
**Suggest a mapping**, and make sure the percentage in the message has a row.

This is the one failure that is deliberate. An invoice booked against the wrong tax rate is worse
than one that did not get booked, because a missing invoice gets noticed and a wrong one does not.

## Nothing is being pushed

In order of likelihood:

1. **Not configured.** No token, no administration id, or no VAT mapping. `bird/sync/status` says
   so plainly.
2. **The queue is not running.** The push is a job. If Craft's queue is not being run by a daemon
   or a cron, nothing leaves until someone loads the CP. Check **Utilities → Queue Manager**.
3. **The trigger does not match.** *The order is paid* fires on Commerce's paid event. If your
   gateway completes the order without marking it paid, use *completed* or a status instead.
4. **Zero-total orders are skipped**, by default and on purpose.

## An order is in the `failed` state

Open it. The panel shows the last error. Then:

```sh
php craft bird/sync/retry
```

Retry picks up the failed pushes that still have attempts left — **Retry attempts**, 5 by default.
Once a document is out of attempts, retry ignores it and you have to press **Send to Moneybird**
on the order, which starts the attempt count over.

## 422 from Moneybird on create

Almost always a payload Moneybird's schema will not accept. Both create endpoints declare
`unevaluatedProperties: false`, so one unexpected key rejects the whole invoice.

The classic is `amount_decimal`. Moneybird's reference prose mentions it, but it is a *response*
field — sending it 422s the invoice. Bird has a check for exactly this. Run
`php craft bird/inspect/preview 1042` and compare.

The other one is sending a sales invoice's fields to the external endpoint or the reverse. A sales
invoice takes `invoice_date` and `first_due_interval`; an external sales invoice takes `date`,
`due_date`, `source` and `source_url`. They are not interchangeable.

## 429, or pushes going slowly

Moneybird allows 150 requests per 5 minutes, and 50 for `/reports/`. Bird reads `Retry-After` and
waits. A backfill is the usual way to find the ceiling — `--limit` defaults to 100 for that
reason. Lower it and run it more often.

## The invoice total is a cent off what the customer paid

It should not be, and if **Reconcile totals** is on it will not be — the difference goes onto a 0%
rounding line so the total matches the bank feed.

If you have turned reconciliation off, Bird refuses those orders instead, with the discrepancy in
the error. That is the setting doing what it says.

## An order with a VAT number was booked at 21%

That is correct, and it is the most common surprise in the plugin.

Reverse charge is a claim about what *was* charged. If the order paid 21%, it was not
reverse-charged, whatever the address says — and booking it as reverse-charged would understate
your return by exactly what the customer paid. What you are looking at is a shop with Commerce's
VAT-number validator switched off. Turn it on in Commerce's tax settings and future orders will
zero-rate at checkout, at which point Bird will book them as reverse charge.

Bird will not retroactively fix orders that already charged tax. It books what happened.

## Contacts are duplicating

Check **Match customers by**. On *Craft user* — the default — Bird keys the Moneybird contact on
the Craft user id, which Commerce guarantees exists for every order email. On *Order number*, a new
contact every order is the documented behaviour, not a bug.

If contacts existed in Moneybird before Bird did, they have no `customer_id` set, so the first
order from each will create a second one. Setting `customer_id` on the existing Moneybird contacts
to match is the fix.

## Webhook deliveries are rejected *(Pro)*

Verification fails closed, and there are only a few ways in:

- **No signing secret stored.** Moneybird returns it exactly once, on create. If the secret was
  lost, remove the webhook and install it again — `bird/webhooks/remove` then
  `bird/webhooks/install`.
- **Stale timestamp.** Deliveries more than five minutes old are rejected. Check the server clock.
- **The body was modified in transit.** The signature is over the *raw* bytes. A proxy that
  re-serializes JSON breaks it.
- **Accept webhooks is off.** Installing the webhook does not switch it on by itself.

`php craft bird/webhooks/info` shows what is registered and whether a secret is stored.

## A push died halfway and I think it double-booked

It did not. Recovery-by-reference looks the invoice up with
`find_by_reference` before creating anything, and the unique index on
`(orderId, kind, sourceKey)` is behind that. If you are seeing two invoices in Moneybird, one of
them was made by hand or by something other than Bird.

## Getting help

`justin@justinholt.com`, with the output of `bird/sync/status` and — on Pro — the relevant log
entry.

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
