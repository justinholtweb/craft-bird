---
title: Alerts
slug: alerts
order: 35
summary: One email (and optionally a Slack or Teams message) when booking into Moneybird gets into trouble and one when it recovers, plus a Dashboard widget and a CP banner.
---

The Documents screen knows everything that has gone wrong. Nobody opens it on a day the shop seems
to be working, though, and an order that never reached Moneybird is revenue you find missing while
preparing the VAT return. So Bird tells you when one of three things happens:

| Incident | Opens when | Clears when |
|---|---|---|
| **Orders failed to book in Moneybird** | `alertDocumentThreshold` invoices or credit notes (default 1) have **failed**: Moneybird refused them, or Bird would not send them (an unmapped VAT rate, no contact to invoice) | every one of them is booked, or forgotten on the Documents screen |
| **Moneybird refused the API token** | Moneybird answers 401 or 403: the token was revoked, expired or lacks a scope | the next request Moneybird accepts |
| **Booking into Moneybird has stalled** | a completed order Bird should have booked still is not after `alertStallHours` (default 6), or a push has sat in *Pending* that long | the orders are booked, by the queue, a button or `bird/sync/backfill` |

You get **one message when an incident starts and one when it clears**, never one per order. Each
incident has a single latch row in the database: checking it a hundred times while it is still
open sends nothing new. More failures arriving while it is open show up on the Dashboard widget and
in the CP banner, not in your inbox, and the incident only clears once the last of them is dealt
with. An incident that keeps flapping is held by `alertCooldownMinutes` (default 60): if it reopens
within that long of its recovery message, you hear about it when the quiet period ends, and only if
it is still happening.

A timeout, a 429 or a 500 is not an authentication failure, and neither is a 422 — that is
Moneybird refusing one invoice, which is the first incident.

A stalled order is one Bird would have booked on its own, under the trigger you chose. With
**Never — only when I say so** nothing can stall. With **The order is paid**, the clock starts at
payment; with **The order is completed**, when it was placed; with **The order reaches a status**,
at its last change while in that status. A zero-total order is not counted when **Skip zero-total
orders** is on. Orders older than a week, or placed before Bird was installed, are left to
`bird/sync/backfill`. An order with a failed document is the first incident, not this one.

## Setting it up

**Settings → Plugins → Bird → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each alert webhook carries `X-Bird-Timestamp` and `X-Bird-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnDocuments` | on | |
| `alertDocumentThreshold` | 1 | |
| `alertOnAuthFailure` | on | |
| `alertStallHours` | 6 | 0 switches stall alerts off |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

All of this is in Bird Lite. Mail goes through Craft's own mailer, so it uses whatever
**Settings → Email** is set to. Press **Send a test alert** on the settings screen (or run
`php craft bird/alerts/test`) after saving to check that both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents and
shows them on the Dashboard widget and the CP banner. If you add a recipient later, any incident
still open is sent at the next check. A site with no API token or administration is not checked at
all.

## When alerts are checked

- **Whenever a document changes**: failed, booked, paid, forgotten. Whatever caused it — the queue,
  a button, the console, the webhook — so failed bookings need no cron.
- **The moment Moneybird refuses the token**: authentication.
- **`php craft bird/alerts/check`**, and at the end of `bird/sync/retry` and `bird/sync/backfill`:
  everything.

A stall is the absence of activity, so only the last of these can notice one. Put either the check
or the retry on cron:

```sh
*/15 * * * * cd /path/to/site && php craft bird/alerts/check >> /dev/null 2>&1
```

## What an alert says

A plain-text email: the site, the incident, what was seen, what to do, and a link straight to the
right screen — Bird's settings for a refused token, the Documents screen filtered to failures for
failed bookings, and the Documents screen for a stall. The Slack message, Teams card and JSON event
carry the same.

What was seen is redacted before it leaves the site: the API token, the Moneybird webhook's signing
secret and the alert webhook's secret are removed by value, anything shaped like a credential
(`Bearer …`, `password=…`, `"api_key": …`) by pattern, markup is stripped and the line is capped at
500 characters. Alerts name orders by their reference and never include an address, a line item or
a payload.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "bird.alert.opened",
  "incident": "documents",
  "site": "My Store",
  "title": "Orders failed to book in Moneybird",
  "detail": "1 invoices and 0 credit notes failed to book. Latest: order 1042: No Moneybird tax rate is mapped for 9%.",
  "url": "https://example.com/admin/bird/documents?state=failed",
  "documentsUrl": "https://example.com/admin/bird/documents",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `bird.alert.recovered` when it clears; `incident` is `documents`, `auth` or `stalled`.

This is a different webhook from the one Moneybird sends Bird when an invoice is paid. The URL is
checked every time it is used, not only when it is saved. It must be `http` or `https` with no
username or password in it, every address the host resolves to must be public — not private,
loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the request is pinned
to those addresses so DNS cannot be switched between the check and the send. Redirects are never
followed. For a self-hosted Mattermost on your own network, set `allowPrivateAlertWebhookHosts` in
`config/bird.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The Dashboard widget and the CP banner

**Dashboard → New widget → Moneybird bookings** shows how many documents are failed, pending,
draft, open, late, paid and booked — each count linked to the Documents screen filtered to it — any
open incident with what was seen, and when a document was last booked in or read back from
Moneybird. It reads the same latch rows the alerts come from, so the widget and your inbox cannot
disagree.

While an incident is open, a banner across the top of the control panel says so, with a link to
the Documents screen. Both are shown only to people with *View Moneybird documents*.

## Changing or suppressing an alert

```php
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail, $e->subject, $e->body, $e->payload
    $e->subject = '[Boekhouding] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->incident === Alerts::INCIDENT_STALLED && !$e->recovered) {
        $e->isValid = false;
    }
});
```

---

*Bird is an independent plugin. It is not affiliated with, endorsed by, or sponsored by Moneybird.
"Moneybird" is a trademark of its respective owner.*
