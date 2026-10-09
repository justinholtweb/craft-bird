---
title: Benachrichtigungen
slug: alerts
order: 35
summary: Eine E-Mail (und auf Wunsch eine Slack- oder Teams-Nachricht), wenn das Buchen in Moneybird hakt, und eine, wenn es wieder läuft, dazu ein Dashboard-Widget und ein Banner im Control Panel.
---

Die Seite Belege weiß alles, was schiefgegangen ist. Nur öffnet sie niemand an einem Tag, an dem der
Shop zu funktionieren scheint, und eine Bestellung, die Moneybird nie erreicht hat, ist Umsatz, der
Ihnen erst bei der Vorbereitung der Umsatzsteuervoranmeldung fehlt. Deshalb sagt Bird Ihnen
Bescheid, wenn eines von drei Dingen passiert:

| Vorfall | Öffnet, wenn | Schließt, wenn |
|---|---|---|
| **Bestellungen konnten nicht in Moneybird gebucht werden** | `alertDocumentThreshold` Rechnungen oder Gutschriften (standardmäßig 1) sind **fehlgeschlagen**: Moneybird hat sie abgelehnt, oder Bird wollte sie nicht senden (ein nicht zugeordneter Steuersatz, kein Kontakt für die Rechnung) | jede davon gebucht ist oder auf der Seite Belege vergessen wurde |
| **Moneybird lehnt das API-Token ab** | Moneybird antwortet mit 401 oder 403: Das Token wurde widerrufen, ist abgelaufen oder es fehlt ihm ein Scope | die nächste Anfrage, die Moneybird akzeptiert |
| **Das Buchen in Moneybird stockt** | eine abgeschlossene Bestellung, die Bird hätte buchen sollen, ist es nach `alertStallHours` (standardmäßig 6) immer noch nicht, oder ein Push steht schon so lange auf *Ausstehend* | die Bestellungen gebucht sind, durch die Warteschlange, einen Button oder `bird/sync/backfill` |

Sie erhalten **eine Nachricht, wenn ein Vorfall beginnt, und eine, wenn er endet**, nie eine pro
Bestellung. Jeder Vorfall hat eine einzige Sperrzeile in der Datenbank: Ihn hundertmal zu prüfen,
solange er offen ist, sendet nichts Neues. Weitere Fehler, die eintreffen, während er offen ist,
erscheinen im Dashboard-Widget und im Banner des Control Panels, nicht in Ihrem Postfach, und der
Vorfall schließt erst, wenn der letzte davon erledigt ist. Ein Vorfall, der ständig hin- und
herspringt, wird von `alertCooldownMinutes` (standardmäßig 60) zurückgehalten: Öffnet er sich
innerhalb dieser Zeit nach seiner Entwarnung erneut, erfahren Sie davon, wenn die Ruhezeit abläuft,
und nur, wenn er dann noch besteht.

Ein Timeout, ein 429 oder ein 500 ist kein Authentifizierungsfehler, und ein 422 auch nicht — das
ist Moneybird, das eine einzelne Rechnung ablehnt, und damit der erste Vorfall.

Eine stockende Bestellung ist eine, die Bird von sich aus gebucht hätte, unter dem Trigger, den Sie
gewählt haben. Mit **Nie — nur wenn ich es sage** kann nichts stocken. Mit **Die Bestellung ist
bezahlt** beginnt die Uhr bei der Zahlung; mit **Die Bestellung ist abgeschlossen** bei der
Bestellung; mit **Die Bestellung erreicht einen Status** bei der letzten Änderung, während sie in
diesem Status war. Eine Bestellung mit Summe 0 zählt nicht, wenn **Bestellungen mit Summe 0
überspringen** an ist. Bestellungen, die älter als eine Woche sind oder vor der Installation von
Bird aufgegeben wurden, bleiben `bird/sync/backfill` überlassen. Eine Bestellung mit einem
fehlgeschlagenen Beleg gehört zum ersten Vorfall, nicht zu diesem.

## Einrichtung

**Einstellungen → Plugins → Bird → Benachrichtigungen.**

| Einstellung | Standard | |
|---|---|---|
| `alertRecipients` | leer | Durch Kommas getrennte Adressen oder ein `$ENV`-Verweis. Leer heißt: keine E-Mail |
| `alertWebhookUrl` | leer | Eine Incoming-Webhook-URL von Slack oder Teams oder ein `$ENV`-Verweis. Legen Sie sie in eine Umgebungsvariable: Die URL ist selbst der Zugangsschlüssel |
| `alertWebhookFormat` | `slack` | `slack`, `teams` oder `json` für Ihren eigenen Empfänger |
| `alertWebhookSecret` | leer | Wenn gesetzt, trägt jeder Benachrichtigungs-Webhook `X-Bird-Timestamp` und `X-Bird-Signature: sha256=<hmac>` über `timestamp.body` |
| `alertOnDocuments` | an | |
| `alertDocumentThreshold` | 1 | |
| `alertOnAuthFailure` | an | |
| `alertStallHours` | 6 | 0 schaltet Benachrichtigungen bei Stillstand ab |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | aus | Nur in der Konfigurationsdatei — siehe unten |

All das ist in Bird Lite enthalten. Mails gehen über Crafts eigenen Mailer, nutzen also, was unter
**Einstellungen → E-Mail** eingestellt ist. Klicken Sie nach dem Speichern auf
**Testbenachrichtigung senden** im Einstellungsbildschirm (oder führen Sie
`php craft bird/alerts/test` aus), um zu prüfen, dass beide Kanäle ankommen.

Nichts davon ist Pflicht. Eine Website ohne Empfänger und ohne Webhook erfasst Vorfälle trotzdem und
zeigt sie im Dashboard-Widget und im Banner des Control Panels. Fügen Sie später einen Empfänger
hinzu, wird jeder noch offene Vorfall bei der nächsten Prüfung gesendet. Eine Website ohne API-Token
oder Verwaltung wird gar nicht geprüft.

## Wann Benachrichtigungen geprüft werden

- **Immer wenn sich ein Beleg ändert**: fehlgeschlagen, gebucht, bezahlt, vergessen. Ganz gleich,
  was es ausgelöst hat — die Warteschlange, ein Button, die Konsole, der Webhook —, fehlgeschlagene
  Buchungen brauchen also keinen Cron.
- **In dem Moment, in dem Moneybird das Token ablehnt**: Authentifizierung.
- **`php craft bird/alerts/check`** sowie am Ende von `bird/sync/retry` und `bird/sync/backfill`:
  alles.

Ein Stillstand ist das Ausbleiben von Aktivität, also kann nur der letzte Punkt ihn bemerken. Legen
Sie entweder die Prüfung oder den Retry in einen Cron:

```sh
*/15 * * * * cd /path/to/site && php craft bird/alerts/check >> /dev/null 2>&1
```

## Was eine Benachrichtigung sagt

Eine E-Mail im Klartext: die Website, der Vorfall, was beobachtet wurde, was zu tun ist, und ein
Link direkt zur richtigen Seite — Birds Einstellungen bei einem abgelehnten Token, die Seite Belege
gefiltert auf Fehlschläge bei fehlgeschlagenen Buchungen und die Seite Belege bei einem Stillstand.
Die Slack-Nachricht, die Teams-Karte und das JSON-Event enthalten dasselbe.

Was beobachtet wurde, wird geschwärzt, bevor es die Website verlässt: das API-Token, das
Signaturgeheimnis des Moneybird-Webhooks und das Geheimnis des Benachrichtigungs-Webhooks werden
nach Wert entfernt, alles, was wie ein Zugangsschlüssel aussieht (`Bearer …`, `password=…`,
`"api_key": …`), nach Muster, Markup wird entfernt und die Zeile auf 500 Zeichen gekürzt.
Benachrichtigungen nennen Bestellungen bei ihrer Referenz und enthalten nie eine Adresse, eine
Position oder einen Payload.

## Der Webhook

Incoming Webhooks von Slack und Teams funktionieren so, wie sie sind. Das Format `json` sendet:

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

`event` ist `bird.alert.recovered`, wenn der Vorfall endet; `incident` ist `documents`, `auth` oder
`stalled`.

Das ist ein anderer Webhook als der, den Moneybird an Bird sendet, wenn eine Rechnung bezahlt ist.
Die URL wird bei jeder Verwendung geprüft, nicht nur beim Speichern. Sie muss `http` oder `https`
sein, ohne Benutzername oder Passwort darin, jede Adresse, in die der Host aufgelöst wird, muss
öffentlich sein — nicht privat, Loopback, Link-Local (der Metadatendienst der Cloud) oder
Carrier-Grade-NAT —, und die Anfrage wird auf diese Adressen festgelegt, damit DNS zwischen Prüfung
und Versand nicht umgestellt werden kann. Weiterleitungen werden nie befolgt. Für ein selbst
gehostetes Mattermost im eigenen Netz setzen Sie `allowPrivateAlertWebhookHosts` in
`config/bird.php`. Die Regeln für Schema und Weiterleitungen gelten weiterhin.

Schlagen alle Kanäle fehl, wird die Benachrichtigung nicht als gesendet markiert, und die nächste
Prüfung versucht es erneut.

## Das Dashboard-Widget und das Banner

**Dashboard → Neues Widget → Moneybird-Buchungen** zeigt, wie viele Belege fehlgeschlagen,
ausstehend, Entwurf, offen, überfällig, bezahlt und gebucht sind — jede Zahl verlinkt auf die Seite
Belege, darauf gefiltert —, jeden offenen Vorfall mit dem, was beobachtet wurde, und wann zuletzt
ein Beleg in Moneybird gebucht oder von dort zurückgelesen wurde. Es liest dieselben Sperrzeilen,
aus denen die Benachrichtigungen kommen, Widget und Postfach können sich also nicht widersprechen.

Solange ein Vorfall offen ist, sagt ein Banner oben im Control Panel das, mit einem Link zur Seite
Belege. Beides sehen nur Personen mit der Berechtigung *Moneybird-Belege ansehen*.

## Eine Benachrichtigung ändern oder unterdrücken

```php
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail, $e->subject, $e->body, $e->payload
    $e->subject = '[Boekhouding] ' . $e->subject;

    // Verschlucken. Die Sperrzeile zählt sie trotzdem als gesendet.
    if ($e->incident === Alerts::INCIDENT_STALLED && !$e->recovered) {
        $e->isValid = false;
    }
});
```

---

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
