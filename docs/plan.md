# Bird — build plan

## Scope of 5.0.0

Everything below is built and covered by `tests/integration/checks.php` (161 checks).

### Shipped

- **Connection** — token + administration, both env-parseable; test-connection, administration
  list, tax-rate/ledger/financial-account/workflow/document-style pickers.
- **Documents** — sales invoices and external sales invoices; triggers on paid / completed /
  status / manual; queue by default; reference and invoice-date sources; workflow and document
  style; zero-total skip.
- **VAT** — domestic, EU reverse charge, EU home rate, EU OSS (Pro), export; money-based rate
  matching; rounding reconciliation; a suggest-a-mapping button that reads Commerce's own rates and
  matches them against Moneybird's.
- **Contacts** — create, match by `customer_id`, match by email as a fallback, fingerprinted
  updates, fallback contact for guest checkouts.
- **Payments** — registered against the document with the gateway reference attached.
- **Credit notes (Pro)** — one per successful Commerce refund transaction, settled by a negative
  payment.
- **Webhook (Pro)** — install/remove, HMAC-SHA256 signature verification with replay tolerance,
  paid-state write-back and an optional Commerce status move.
- **CP** — settings screen, Documents index and detail, connection log (Pro), and a panel on
  Commerce's order screen with a byte-exact preview.
- **Console** — `bird/sync/*`, `bird/inspect/*`, `bird/webhooks/*`, `bird/log/*`.
- **Twig** — `craft.bird.documentForOrder()`, `documentsForOrder()`, `invoiceUrl()`,
  `vatTreatment()`, `isConfigured()`.

## Deliberately not in 5.0.0

- **Products.** Moneybird has a `products` resource and details accept a `product_id`. Mapping
  Commerce variants onto it would let Moneybird own descriptions and default rates — but it also
  means a sync in a third direction, and a shop with 5,000 SKUs would spend its whole rate limit on
  it. Revisit if anyone asks.
- **Time entries, estimates, subscriptions.** Not what a webshop needs.
- **Purchase invoices.** Bird books revenue; supplier invoices are a different plugin.
- **VIES validation.** Moneybird runs the check itself and reports back on the contact as
  `tax_number_valid`. Duplicating it would mean two sources of truth that can disagree.
- **PDF storage.** `GET /sales_invoices/{id}/download_pdf` exists, and pulling every invoice PDF
  into Craft's asset volumes is a lot of storage for a file Moneybird already keeps and links to.

## Known limits, stated rather than hidden

- **Sub-national VAT territories.** The Canary Islands, Ceuta and Melilla, the French overseas
  departments, Büsingen, Heligoland, Livigno, the Åland Islands and Mount Athos are outside the EU
  VAT area but sit inside member states. A country code cannot see them, so Bird cannot either —
  it books whatever Commerce charged. A shop shipping there needs a postcode rule in Commerce's tax
  engine.
- **Mixed included/added tax on one order.** Bird decides `prices_are_incl_tax` per order from
  whether *any* included tax exists. An order that mixes both would reconcile through the rounding
  line rather than line by line.
- **Order-level tax attribution.** Tax the adjuster hung on the order rather than a line is spread
  across the order-level lines at one blended rate. In practice only shipping carries it, which
  makes the blend exact.
- **External sales invoice recovery.** The find-by-reference recovery path is sales-invoice only;
  Moneybird has no equivalent lookup for external invoices.

## Release checklist

- [x] `php -l` clean across `src/`
- [x] 161 integration checks green, twice in a row (the suite is idempotent)
- [x] CP screens render on Lite and Pro; the log screen 403s on Lite
- [x] Webhook verified over real HTTP: unsigned 401, stale 401, signed 200
- [x] Fresh install from a clean database (the harness install ran the migration)
- [ ] Tag 5.0.0 and push
