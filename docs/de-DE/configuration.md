---
title: Konfiguration
slug: configuration
order: 20
summary: Jede Einstellung im Bird-Bildschirm, und warum die Voreinstellungen so sind, wie sie sind.
---

Der Einstellungsbildschirm ist **Einstellungen → Plugins → Bird**. Eine Seite in acht Abschnitten.

## Verbindung

| Einstellung | Hinweise |
|---|---|
| **API-Token** | Akzeptiert einen Verweis der Form `$ENV_VAR`. Nutzen Sie einen. |
| **Verwaltung** | Die Nummer aus den URLs von Moneybird selbst. |

## Belege

**Belegtyp** entscheidet, welches der beiden Moneybird-Objekte Bird anlegt, und das ist der einzige
Unterschied zwischen den beiden Arbeitsweisen:

- **Verkaufsrechnung** — Moneybird besitzt die Rechnungsnummer, erzeugt das PDF und kann es
  versenden. Wählen Sie das, wenn Ihre Rechnungen aus Moneybird kommen.
- **Externe Verkaufsrechnung** — die Bestellreferenz *ist* die Rechnungsnummer, und Moneybird bucht
  nur den Umsatz und die Umsatzsteuer. Kein PDF, kein Versand. Wählen Sie das, wenn Ihr Shop seine
  Rechnungen bereits selbst ausstellt.

Auf API-Ebene sind sie nicht austauschbar — eine Verkaufsrechnung nimmt `invoice_date` und eine
Zahlungsfrist, eine externe nimmt `date`, `due_date` und eine Quell-URL zurück zur Bestellung —
aber alles Übrige in Bird verhält sich in beiden Fällen gleich.

| Einstellung | Standard | Hinweise |
|---|---|---|
| **An Moneybird senden, wenn** | Die Bestellung ist bezahlt | Oder beim Abschluss, beim Erreichen eines Status, oder nie. |
| **Trigger-Status** | — | Nur wenn der Trigger *erreicht einen Status* ist. |
| **Über die Warteschlange senden** | An | Lassen Sie es an. Siehe [Sicherheit](usage#es-kann-keinen-checkout-aufhalten). |
| **Referenz** | Bestellreferenz | Was auf die Rechnung kommt: Referenz, Bestellnummer, Kurznummer oder Element-ID. |
| **Rechnungsdatum** | Datum der Bestellung | Oder das Zahlungsdatum, oder heute. |
| **Zahlungsfrist** | 14 Tage | Nur Verkaufsrechnungen — Moneybird leitet das Fälligkeitsdatum daraus ab. |
| **Wiederholungsversuche** | 5 | Wie oft ein fehlgeschlagener Push einen erneuten Versuch wert ist. |
| **Workflow**, **Belegstil** | — | Moneybirds eigene IDs, falls Sie mehr als eine nutzen. |
| **Bestellungen mit Summe 0 überspringen** | An | Eine 0-€-Bestellung ist meist ein Test oder eine voll rabattierte Gutbestellung. |

## Steuer

| Einstellung | Hinweise |
|---|---|
| **Heimatland** | Zweibuchstabiger Code. Das Land, dessen Umsatzsteuer Sie standardmäßig berechnen. |
| **Steuersätze** | Prozentsatz → Moneybird-Steuersatz-ID. **Zuordnung vorschlagen** füllt das aus Ihrer Verwaltung. |
| **Reverse-Charge-Steuersatz** | Der 0%-Satz, der *btw verlegd* ausweist. |
| **Ausfuhrsteuersatz** | Der 0%-Satz für Verkäufe außerhalb der EU. |
| **One Stop Shop** *(Pro)* | Schaltet Verbrauchersätze je Land ein. |
| **OSS-Sätze** *(Pro)* | Land → Prozentsatz → Steuersatz-ID. |
| **Feld mit der USt-IdNr.** | Voreingestellt `organizationTaxId` der Adresse — daraus lesen auch die Prüfungen von Commerce selbst. |
| **Summen abgleichen** | An. Aus weist eine Bestellung ab, die nicht aufgeht, statt eine Rundungsposition zu ergänzen. |

### Warum Sätze nach dem Betrag zugeordnet werden

Bird schlägt einen Satz nicht über den Prozentsatz nach. Commerce rundet die Steuer je Position auf
den Cent, also erfasst eine Position von 10,10 € bei 21% genau 2,12 € Steuer — zurückgerechnet
**20,99%**, ein Satz, den kein Shop je konfiguriert hat. Eine Suche nach dem Prozentsatz würde
täglich ganz gewöhnliche Bestellungen abweisen.

Stattdessen nimmt Bird Netto und Steuer so, wie sie erfasst wurden, und sucht den zugeordneten Satz,
dessen Rechnung auf einen Cent genau aufgeht (oder auf ein halbes Prozent der Steuer bei größeren
Positionen). Was nach dem Zuordnen aller Positionen übrig bleibt, wird die Rundungsposition.

### Summen abgleichen

Die Rechnungssumme muss dem entsprechen, was der Kunde gezahlt hat, denn das ist es, was der
Bankfeed zeigen wird. Wenn die Rundung je Position einen Cent Differenz lässt, bucht Bird ihn als
Position mit **0%** — ein Cent Rundungsdifferenz ist kein Umsatz und soll nicht besteuert werden,
als wäre er einer.

Schalten Sie **Summen abgleichen** ab, und Bird weist die Bestellung stattdessen ab, mit der
Differenz in der Fehlermeldung. Manche Buchhalter hören das lieber, als dass es stillschweigend
überdeckt wird.

## Sachkonten

| Einstellung | Hinweise |
|---|---|
| **Standard-Erlöskonto** | Worauf der Positionsumsatz gebucht wird. Leer überlässt es Moneybirds eigener Voreinstellung. |
| **Versandkonto** | Versandkosten stehen üblicherweise auf einem eigenen Konto. |
| **Rabattkonto** | Ein Rabatt auch. |
| **Je Produkttyp** *(Pro)* | Handle des Commerce-Produkttyps → Sachkonto-ID. Fällt auf den Standard zurück. |

## Kontakte

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Kontakte synchronisieren** | An | Aus bucht jede Rechnung auf den Ersatzkontakt. |
| **Kunden zuordnen über** | Craft-Benutzer | Oder E-Mail, oder ein neuer Kontakt je Bestellung, oder gar keiner. |
| **Adresse** | Rechnungsadresse | Welche Commerce-Adresse zur Adresse des Kontakts wird. |
| **Bestehende Kontakte aktualisieren** | An | Bird merkt sich einen Fingerabdruck des zuletzt gesendeten Payloads, also kostet ein unveränderter Kunde keinen API-Aufruf. |
| **Ersatzkontakt** | — | Wird genutzt, wenn es keine brauchbaren Kundendaten gibt. |

Commerce stellt für jede Bestell-E-Mail-Adresse einen Craft-Benutzer sicher — `Order::setEmail()`
ruft `Users::ensureUserByEmail()` auf — also hat selbst ein Gast-Checkout eine Benutzer-ID, an der
ein Kontakt hängen kann. Deshalb ist **Craft-Benutzer** die Voreinstellung und nicht E-Mail.

## Zahlungen

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Zahlungen erfassen** | An | Bucht eine Zahlung auf den angelegten Beleg. |
| **Finanzkonto** | — | Auf welchem Moneybird-Konto die Zahlung landet. |
| **Rückerstattungen gutschreiben** *(Pro)* | An | Rückerstattungstransaktionen aus Commerce werden zu Gutschriften. |

Zahlungen gehen an `POST /sales_invoices/{id}/payments.json`. Moneybird hat den älteren Endpunkt
`register_payment` abgekündigt; Bird nutzt ihn nicht.

## Webhook *(Pro)*

| Einstellung | Hinweise |
|---|---|
| **Webhook-URL** | Nur lesbar. Das ist die URL, die Sie registrieren. |
| **Webhooks annehmen** | Aus, bis Sie einen installieren. |
| **Signaturgeheimnis** | Moneybird gibt es genau einmal zurück, beim Anlegen. **Webhook installieren** hinterlegt es für Sie. |
| **Status bei bezahlt** | Setzt die Commerce-Bestellung auf Wunsch auf diesen Status, sobald Moneybird die Rechnung als bezahlt meldet. |

Siehe [den Webhook](usage#der-webhook) dazu, wofür er da ist und wie er geprüft wird.

## Protokoll *(Pro)*

| Einstellung | Standard | Hinweise |
|---|---|---|
| **Verbindungen protokollieren** | An | Eine Zeile je Anfrage. |
| **Payloads aufbewahren** | An | Request- und Response-Bodies, mit geschwärzten Tokens und Geheimnissen. |
| **Einträge aufbewahren für** | 30 Tage | `bird/log/prune` gehört in einen Cron. |

## Konfigurationsdatei

Alles oben lässt sich in `config/bird.php` setzen, die Vorrang vor der Datenbank hat und das ist,
was Sie wollen, wenn die Einstellungen je Umgebung abweichen:

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

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
