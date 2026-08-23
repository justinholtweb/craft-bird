---
title: FAQ
slug: faq
order: 50
summary: The questions worth answering before you install it.
---

## Is Bird affiliated with Moneybird?

No. Bird is an independent plugin built by Justin Holt. It is not affiliated with, endorsed by, or
sponsored by Moneybird, and it talks to Moneybird's public API the same way any other client
would. "Moneybird" is a trademark of its respective owner.

## Is Bird free?

Lite is, and it is not a trial. Invoices, contacts, payments, domestic VAT, reverse charge, export
and the rounding reconciliation are all in Lite, for nothing. Pro is a one-off $99 with a $49/year
renewal, and adds One Stop Shop, credit notes for refunds, sending from Moneybird, the
signature-verified webhook, per-product-type ledger accounts, and the connection log.

## Sales invoice or external sales invoice — which do I want?

If Moneybird is where your invoices come from, you want **sales invoices**: Moneybird numbers them,
renders the PDF, and can email them.

If your shop already issues its own invoices and you only need the bookkeeping to be right, you
want **external sales invoices**: the order reference is the invoice number, and Moneybird just
books the revenue and the VAT.

It is one setting, and everything else works the same either way.

## Does Bird calculate VAT?

No, and that is the design. Commerce's tax engine already knows your zones, your rates and your
VAT-number validators. Re-deriving that here would give your shop two answers that eventually
disagree, and the one the customer actually paid is Commerce's.

What Bird adds is the *reason*. Moneybird needs to know which 0% it is looking at, because
reverse-charged, exported and genuinely zero-rated are three different rates that land in three
different boxes on a VAT return.

## My customer has a VAT number but the invoice shows 21%. Why?

Because the order charged 21%. Reverse charge is a claim about what was charged, not what could
have been — and booking it as reverse-charged would understate your VAT return by exactly the
amount the customer paid.

The underlying cause is nearly always Commerce's VAT-number validator being switched off. Turn it
on and future orders zero-rate at checkout, at which point Bird books them as reverse charge.

## Will it slow down or break my checkout?

No. Everything that runs during checkout fails open — the triggers swallow their own failures, and
the push goes through the queue by default. A Moneybird outage, an expired token or a rate limit
can produce a failed document row and a retry. It cannot produce a customer who could not pay.

## Can it book the same order twice?

No. The document table is unique on `(order, kind, source)`, and that index is the guarantee — a
retried job, a double-clicked button and a webhook racing the queue all collide on it. If a push
dies between Moneybird creating the invoice and Bird recording it, the next attempt finds the
invoice by reference and adopts it.

## What happens to orders that were paid before I installed it?

Nothing, until you ask. `bird/sync/backfill` brings them across, and `--dry-run` reports every
order it would book, under which VAT treatment, without sending anything.

## Do I need the webhook?

Only if invoices get paid somewhere other than your checkout. Bird pushes invoices; Moneybird is
where a bank transfer gets matched against one. Without the webhook, your site's idea of "paid" is
whatever the gateway said at checkout. With it, Moneybird tells you — over a signed request,
verified in constant time and rejected beyond five minutes.

## Does it handle refunds?

On Pro. A Commerce refund transaction becomes a Moneybird credit note, at the rate the VAT went on
at, keyed on the refund's own hash so it cannot be credited twice.

## What is the rounding line?

Commerce rounds tax to the cent per line, so the sum of the lines can miss the order total by a
cent. The invoice total has to equal what the customer paid, because that is what the bank feed
shows — so the difference is booked as a line at 0%. A cent of VAT-rounding drift is not revenue
and should not be taxed as if it were.

If you would rather hear about those orders than have them reconciled, turn **Reconcile totals**
off and Bird refuses them instead.

## Can I use it with multiple Moneybird administrations?

One administration per Craft install. If you run several stores in one install and they book to
different administrations, that is not something Bird does today.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Where do I get support?

`justin@justinholt.com`.

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
