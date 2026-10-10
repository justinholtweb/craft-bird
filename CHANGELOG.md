# Release Notes for Bird

## Unreleased

### Added

- **Failure alerts.** One email — and optionally a Slack, Teams or signed JSON webhook — when booking into Moneybird gets into trouble, and one when it recovers, never one per order. Three incidents: orders that failed to book (Moneybird refused them, or a VAT rate or contact is missing), Moneybird refusing the API token (a 401 or 403), and booking that has stalled — completed orders still not in Moneybird hours after the trigger said they were due, which is what a queue that is not running looks like. Set them up under **Settings → Plugins → Bird → Alerts**; nothing is sent until you add recipients or a webhook. In Lite and Pro.
- A **Moneybird bookings** Dashboard widget: documents by state, each linked to the Documents screen filtered to it, plus any open incident.
- A banner across the control panel while an incident is open, for people who can view Moneybird documents.
- `php craft bird/alerts/check` (put it, or `bird/sync/retry`, on cron to catch stalls) and `php craft bird/alerts/test`, and a **Send a test alert** button on the settings screen.
- `Alerts::EVENT_BEFORE_NOTIFY`, to reword or swallow an alert.

### Changed

- The German and Dutch wording of "Moneybird rejected the API token" and "No Moneybird API token is configured" is reworded. Both reach failure alerts, and alert redaction masks whatever follows the word "Token" — "API-Token abgelehnt" arrived as "API-Token ••••".

## 5.0.2 - 2026-10-04

Not released on its own; these changes ship in 5.1.0.

> {warning} Bird now keeps the Moneybird webhook's signing secret in its own database table, encrypted, instead of in its settings — which are project config, committed with your site. Upgrading copies an existing secret across so the webhook keeps working; then open **Settings → Bird**, tick **Remove it from project config** and save. If the repository has been shared, remove the webhook and install it again to get a new secret. Clearing and pruning the connection log is now admin-only.

### Security

- **The webhook's signing secret was stored in project config.** Installing the webhook saved the secret Moneybird returns as a plugin setting, so it went into `config/project/project.yaml` and from there into git — where anyone with the repository could sign a webhook telling the shop an invoice was paid. It now lives in a `bird_webhook` table, encrypted with the site's security key, and is never rendered, returned to the browser or printed by the console. The **Signing secret** setting remains for a webhook made by hand, as an environment variable; a literal secret is refused there. A secret stored by an earlier version keeps working, is never shown, and the settings screen asks for it to be removed.
- **"View the connection log" could wipe it.** Clearing and pruning the log — the audit trail of everything sent to Moneybird — now needs an admin, as in Freshh. The **Prune old entries** button is only shown to admins; `bird/log/prune` on the console is unchanged.

### Fixed

- **Installing the webhook failed on a live site with admin changes off**, because it wrote the plugin settings. It now writes only Bird's table, so `php craft bird/webhooks/install` works where it should be run, and an installed webhook is accepted without switching **Accept webhooks** on — that setting is now only for a webhook made by hand.
- **Every button on the settings screen did nothing** — Test connection, the administration picker, the option lists, the tax-rate suggestions, Install and Remove webhook. Craft namespaces a plugin's settings HTML, so the ids became `settings-bird-…` while the script looked for `bird-…`.
- `bird/sync/order`, `bird/sync/refunds` and `bird/inspect/preview` printed a PHP warning instead of the order reference when no order matched: `"“$reference”"` interpolates a variable called `reference”`.

### Changed

- PHPStan and ECS configuration, and the findings they raised tidied.

## 5.0.1 - 2026-08-26

### Fixed

- **Every Bird console command, and `php craft` itself, died with a PHP fatal error.** `InspectController` declared a private `table()` helper, but Craft 5.10 added a public `table()` to `craft\console\Controller`. Lowering a base class method's visibility is a compile-time error, so the whole console application failed to boot — `php craft help` included, on any install with Bird enabled. The helper is now called `renderRows()`.

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
