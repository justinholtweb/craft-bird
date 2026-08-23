---
title: Fehlerbehebung
slug: troubleshooting
order: 40
summary: Was die üblichen Fehler bedeuten und was dagegen zu tun ist.
---

Beginnen Sie mit `php craft bird/sync/status`. Das meldet die Verbindung, die Edition, ob das Plugin
sich für eingerichtet hält, und die Belegzahlen je Zustand — meist genug, um ein
Konfigurationsproblem von einem Moneybird-Problem zu unterscheiden.

In Pro stehen unter **Bird → Protokoll** die Anfrage und die Antwort jedes Aufrufs, mit geschwärzten
Tokens und Geheimnissen.

## „Kein Steuersatz für 21% zugeordnet“

Bird hat die Bestellung abgewiesen, statt zu raten. Gehen Sie zu **Einstellungen → Plugins → Bird →
Steuer**, klicken Sie auf **Zuordnung vorschlagen** und stellen Sie sicher, dass der Prozentsatz aus
der Meldung eine Zeile hat.

Das ist der einzige Fehler, der Absicht ist. Eine Rechnung unter dem falschen Steuersatz ist
schlimmer als eine, die gar nicht gebucht wurde — eine fehlende Rechnung fällt auf, eine falsche
nicht.

## Es wird nichts gesendet

Nach Wahrscheinlichkeit:

1. **Nicht eingerichtet.** Kein Token, keine Verwaltungs-ID oder keine Steuersatzzuordnung.
   `bird/sync/status` sagt das unumwunden.
2. **Die Warteschlange läuft nicht.** Der Push ist ein Job. Wird Crafts Warteschlange nicht von
   einem Daemon oder einem Cron ausgeführt, geht nichts raus, bis jemand das Control Panel öffnet.
   Prüfen Sie **Werkzeuge → Queue Manager**.
3. **Der Trigger passt nicht.** *Die Bestellung ist bezahlt* reagiert auf das paid-Event von
   Commerce. Schließt Ihr Anbieter die Bestellung ab, ohne sie als bezahlt zu markieren, nehmen Sie
   *abgeschlossen* oder einen Status.
4. **Bestellungen mit Summe 0 werden übersprungen**, standardmäßig und mit Absicht.

## Eine Bestellung steht auf `failed`

Öffnen Sie sie. Das Panel zeigt den letzten Fehler. Dann:

```sh
php craft bird/sync/retry
```

Retry greift die fehlgeschlagenen Pushes auf, die noch Versuche übrig haben —
**Wiederholungsversuche**, standardmäßig 5. Hat ein Beleg keine Versuche mehr, übergeht Retry ihn
und Sie müssen bei der Bestellung auf **An Moneybird senden** klicken, was den Zähler neu startet.

## 422 von Moneybird beim Anlegen

Fast immer ein Payload, das Moneybirds Schema nicht akzeptiert. Beide Create-Endpunkte erklären
`unevaluatedProperties: false`, ein unerwarteter Schlüssel weist also die ganze Rechnung ab.

Der Klassiker ist `amount_decimal`. Moneybirds Referenztext erwähnt es, aber es ist ein
*Response*-Feld — es mitzusenden ergibt einen 422. Bird hat dafür eine Prüfung. Führen Sie
`php craft bird/inspect/preview 1042` aus und vergleichen Sie.

Der andere ist, die Felder einer Verkaufsrechnung an den externen Endpunkt zu senden oder umgekehrt.
Eine Verkaufsrechnung nimmt `invoice_date` und `first_due_interval`; eine externe Verkaufsrechnung
nimmt `date`, `due_date`, `source` und `source_url`. Sie sind nicht austauschbar.

## 429, oder Pushes, die langsam gehen

Moneybird erlaubt 150 Anfragen je 5 Minuten und 50 für `/reports/`. Bird liest `Retry-After` und
wartet. Ein Backfill ist der übliche Weg, die Decke zu finden — deshalb steht `--limit`
standardmäßig auf 100. Setzen Sie es niedriger und führen Sie es öfter aus.

## Die Rechnungssumme weicht einen Cent von der Zahlung ab

Das sollte sie nicht, und mit **Summen abgleichen** an tut sie es nicht — die Differenz geht auf eine
Rundungsposition mit 0%, damit die Summe zum Bankfeed passt.

Haben Sie den Abgleich abgeschaltet, weist Bird diese Bestellungen stattdessen ab, mit der Differenz
in der Fehlermeldung. Das ist die Einstellung, die tut, was sie ankündigt.

## Eine Bestellung mit USt-IdNr. wurde mit 21% gebucht

Das ist richtig, und es ist die häufigste Überraschung im Plugin.

Reverse Charge ist eine Aussage darüber, was *berechnet wurde*. Hat die Bestellung 21% gezahlt, war
es kein Reverse Charge, was die Adresse auch sagt — und es so zu buchen würde Ihre Erklärung um
genau das mindern, was der Kunde gezahlt hat. Was Sie sehen, ist ein Shop mit abgeschalteter
USt-IdNr.-Prüfung in Commerce. Schalten Sie sie in Commerce' Steuereinstellungen ein, und künftige
Bestellungen werden im Checkout mit 0% berechnet — woraufhin Bird sie als Reverse Charge bucht.

Bird repariert Bestellungen, die bereits Steuer berechnet haben, nicht rückwirkend. Es bucht, was
passiert ist.

## Kontakte werden doppelt angelegt

Prüfen Sie **Kunden zuordnen über**. Auf *Craft-Benutzer* — der Voreinstellung — schlüsselt Bird den
Moneybird-Kontakt über die Craft-Benutzer-ID, die Commerce für jede Bestell-E-Mail-Adresse
sicherstellt. Auf *Bestellnummer* ist ein neuer Kontakt je Bestellung das dokumentierte Verhalten,
kein Fehler.

Gab es Kontakte in Moneybird schon vor Bird, haben sie keine `customer_id`, also legt die erste
Bestellung von jedem einen zweiten an. Die Lösung ist, `customer_id` auf den bestehenden
Moneybird-Kontakten passend zu setzen.

## Webhook-Zustellungen werden abgelehnt *(Pro)*

Die Prüfung scheitert geschlossen, und es gibt nur wenige Wege hinein:

- **Kein Signaturgeheimnis hinterlegt.** Moneybird gibt es genau einmal zurück, beim Anlegen. Ist
  das Geheimnis verloren, entfernen Sie den Webhook und installieren ihn erneut —
  `bird/webhooks/remove`, dann `bird/webhooks/install`.
- **Veralteter Zeitstempel.** Zustellungen, die älter als fünf Minuten sind, werden abgelehnt.
  Prüfen Sie die Uhr des Servers.
- **Der Body wurde unterwegs verändert.** Die Signatur geht über die *rohen* Bytes. Ein Proxy, der
  das JSON neu serialisiert, bricht sie.
- **Webhooks annehmen ist aus.** Den Webhook zu installieren schaltet das nicht von selbst ein.

`php craft bird/webhooks/info` zeigt, was registriert ist und ob ein Geheimnis hinterlegt ist.

## Ein Push ist auf halbem Weg gestorben und ich glaube, es wurde doppelt gebucht

Wurde es nicht. Die Wiederherstellung über die Referenz sucht die Rechnung mit `find_by_reference`,
bevor irgendetwas angelegt wird, und der eindeutige Index auf `(orderId, kind, sourceKey)` steht
dahinter. Sehen Sie zwei Rechnungen in Moneybird, wurde eine von Hand oder von etwas anderem als
Bird angelegt.

## Hilfe

`justin@justinholt.com`, mit der Ausgabe von `bird/sync/status` und — in Pro — dem betreffenden
Protokolleintrag.

---

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
