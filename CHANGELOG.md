# Release Notes for Bird

## 5.0.0

Initial release.

### Added

- Commerce orders booked into Moneybird as **sales invoices** or **external sales invoices**, one
  setting apart.
- Triggers on order paid, order completed, or an order status — pushed through the queue by
  default so a slow Moneybird cannot slow down a checkout.
- A VAT engine that reads what Commerce charged and books it under the right Moneybird rate:
  domestic, EU reverse charge, export, and (Pro) One Stop Shop per country.
- Rates matched on the money rather than on a derived percentage, so a cent of per-line rounding
  does not turn 21% into an unmapped 20.99%.
- Rounding reconciliation: the invoice total always equals what the customer paid, with the
  difference booked to a 0% rounding line.
- Contacts created, matched by Moneybird's `customer_id`, and updated only when something actually
  changed — a returning customer normally costs no API call at all.
- Payment registration against the booked document, with the gateway's own reference attached for
  the bank feed to match on.
- Idempotency on `(order, kind, source)`, plus recovery-by-reference for a push that died between
  creating the invoice and recording it.
- A panel on Commerce's order screen with the VAT treatment, the booked documents, and a preview
  built by the same code path as the push.
- Documents index, `craft.bird.*` Twig API, and console commands for pushing, backfilling,
  retrying, previewing and reading Moneybird's own lists.
- **Pro:** One Stop Shop tax rates per country.
- **Pro:** Commerce refunds credited as Moneybird credit notes, one per refund transaction.
- **Pro:** invoices sent from Moneybird by email, Simplerinvoicing, post or manually.
- **Pro:** a signature-verified paid-invoice webhook that can move the Commerce order's status.
- **Pro:** per-product-type ledger accounts.
- **Pro:** a connection log with request and response payloads, redacted and pruned.
- Control panel translated into Dutch, Belgian Dutch, German and Belgian French — the languages
  Moneybird publishes in — alongside English.
- Documentation published in the same five languages at justinholt.com/plugins/craft-bird.
