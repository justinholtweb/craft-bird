---
title: Verwendung
slug: usage
order: 30
summary: Bestellungen buchen, der Bestellbildschirm, Backfill, Rückerstattungen, der Webhook, Twig und die Konsole.
---

## Der normale Weg

Eine Bestellung wird bezahlt. Commerce feuert sein Event, Bird stellt einen Job in die
Warteschlange, und der Job baut ein Payload und legt einen Beleg in Moneybird an. Eine Zeile geht in
Birds Belegtabelle, eine Zahlung wird auf der Rechnung erfasst, und das Moneybird-Panel im
Bestellbildschirm füllt sich.

Sobald das Plugin eingerichtet ist, müssen Sie dafür nichts tun.

## Der Bestellbildschirm

Jede Commerce-Bestellung bekommt ein **Moneybird**-Panel:

- was gebucht wurde, mit einem Link nach Moneybird
- die **umsatzsteuerliche Behandlung** und die USt-IdNr., die Bird aus der Adresse gelesen hat
- **An Moneybird senden** — jetzt buchen, was der Trigger auch sagt
- **Rückerstattungen gutschreiben** *(Pro)* — noch nicht gutgeschriebene
  Rückerstattungstransaktionen gutschreiben
- **Rechnungsvorschau** — das exakte JSON, ohne es zu senden

Die Vorschau führt dieselbe `Invoices::buildPayload()` aus wie der Push. Es gibt genau eine Stelle
im Code, an der aus einer Bestellung Moneybird-JSON wird, und die Vorschau ist sie — was Ihnen vor
dem Buchen angezeigt wird, ist also Byte für Byte das, was gebucht wird, samt Steuersatz-IDs, und
genau dort sitzen die Fehler.

## Wie die Steuerlogik entscheidet

| Situation | Behandlung | Verwendeter Satz |
|---|---|---|
| Kunde in Ihrem Heimatland | Inland | Der zugeordnete Prozentsatz |
| EU-Unternehmen, zum Land passende USt-IdNr., keine Steuer berechnet | Reverse Charge | Ihr Satz für *btw verlegd* |
| EU-Verbraucher, OSS an *(Pro)* | OSS | Der Satz dieses Landes |
| EU-Verbraucher, OSS aus | Heimatsatz | Der zugeordnete Prozentsatz |
| Außerhalb der EU | Ausfuhr | Ihr Ausfuhrsatz |

**Commerce entscheidet, was berechnet wird; Bird entscheidet, wo es gebucht wird.** Die
Steuer-Engine von Commerce kennt Ihre Zonen, Ihre Sätze und Ihre USt-IdNr.-Prüfungen bereits. Das
hier noch einmal herzuleiten gäbe Ihrem Shop zwei Antworten, die auseinanderdriften — und die, die
der Kunde tatsächlich gezahlt hat, ist die von Commerce.

Beachten Sie die dritte Spalte der Reverse-Charge-Zeile. Reverse Charge ist eine Aussage darüber,
was *berechnet wurde*, nicht darüber, was hätte berechnet werden können. Eine USt-IdNr. auf einer
Bestellung, die trotzdem 21% gezahlt hat, ist ein Shop, in dem die USt-IdNr.-Prüfung von Commerce
nie eingeschaltet wurde — und es als Reverse Charge zu buchen würde die Erklärung um genau das
mindern, was der Kunde gezahlt hat. Bird bucht, was passiert ist.

## Backfill

Bestellungen, die bei der Installation von Bird bereits bezahlt waren, bleiben unangetastet, bis Sie
danach fragen.

```sh
php craft bird/sync/backfill --since=2026-01-01 --dry-run
php craft bird/sync/backfill --since=2026-01-01 --limit=250
```

`--dry-run` meldet jede Bestellung, die gebucht würde, und unter welcher Behandlung, ohne etwas zu
senden. Führen Sie das zuerst aus. `--limit` steht standardmäßig auf 100, damit ein Backfill nicht
von selbst in das Rate Limit läuft.

## Rückerstattungen *(Pro)*

Eine Rückerstattungstransaktion aus Commerce wird zu einer Moneybird-Gutschrift, eine je
Transaktion, zu dem Satz, mit dem die Steuer draufkam. Gutschriften werden über den Hash der
Rückerstattungstransaktion geschlüsselt, dieselbe Rückerstattung zweimal gutzuschreiben ist also
nicht möglich — der eindeutige Index auf `(Bestellung, Art, Quelle)` weist es ab.

```sh
php craft bird/sync/refunds 1042
```

Oder klicken Sie bei der Bestellung auf **Rückerstattungen gutschreiben**.

## Der Webhook *(Pro)*

Bird sendet Rechnungen; in Moneybird werden sie *bezahlt* — einem Bankfeed zugeordnet oder von Hand
abgehakt. Ohne den Webhook ist die Vorstellung Ihrer Website von „bezahlt“ das, was der
Zahlungsanbieter beim Checkout gesagt hat, und das verfehlt jede Überweisung.

```sh
php craft bird/webhooks/install
php craft bird/webhooks/info
php craft bird/webhooks/remove
```

**Webhook installieren** registriert ihn und hinterlegt das Signaturgeheimnis, das Moneybird genau
einmal zurückgibt, beim Anlegen. Jede Zustellung wird danach gegen den Header
`Moneybird-Signature` geprüft — HMAC-SHA256 über `{timestamp}.{roher Body}`, in konstanter Zeit
verglichen und abgelehnt, wenn der Zeitstempel mehr als fünf Minuten abweicht. Während einer
Geheimnisrotation treten mehrere Signaturwerte auf, jeder Treffer zählt also.

Kein Geheimnis, keine Signatur oder ein veralteter Zeitstempel: keine Anfrage. Es scheitert
geschlossen.

Bird abonniert die Events für bezahlt, überfällig und uneinbringlich, für beide Belegtypen. Setzen
Sie **Status bei bezahlt**, wenn eine bezahlte Rechnung die Commerce-Bestellung umsetzen soll.

## Twig

```twig
{% set document = craft.bird.documentForOrder(order) %}

{% if document and document.getIsBooked() %}
    <p>Rechnung {{ document.getLabel() }} — {{ document.getStateLabel() }}</p>

    {# Eine Capability-URL: nur dem Kunden zeigen, dem die Bestellung gehört. #}
    {% if document.publicUrl %}
        <a href="{{ document.publicUrl }}">Ihre Rechnung ansehen</a>
    {% endif %}
{% endif %}

{% set vat = craft.bird.vatTreatment(order) %}
{% if vat.reverseCharge %}
    <p>Steuerschuldnerschaft des Leistungsempfängers — {{ vat.vatNumber }}</p>
{% endif %}
```

`craft.bird.documentsForOrder(order)` gibt die Rechnung und jede Gutschrift zurück.
`craft.bird.invoiceUrl(order)` ist eine Abkürzung zur öffentlichen URL, und
`craft.bird.isConfigured()` gibt es, damit ein Template auf einer noch nicht eingerichteten
Installation still bleiben kann.

## Konsole

```sh
craft bird/sync/status                    # Verbindung, Edition, Belegzahlen
craft bird/sync/order 1042                # eine Bestellung buchen, per Referenz, Nummer oder ID
craft bird/sync/order 1042 --force        # erneut buchen, obwohl sie gebucht ist
craft bird/sync/backfill --since=2026-01-01 --dry-run --limit=100
craft bird/sync/retry --limit=100         # die fehlgeschlagenen Pushes mit verbleibenden Versuchen
craft bird/sync/refunds 1042              # die Rückerstattungen einer Bestellung gutschreiben

craft bird/inspect/preview 1042           # das exakte JSON, ohne zu senden
craft bird/inspect/tax-rates              # IDs für die Steuersatzzuordnung
craft bird/inspect/ledger-accounts
craft bird/inspect/financial-accounts
craft bird/inspect/workflows
craft bird/inspect/administrations

craft bird/webhooks/install
craft bird/webhooks/list
craft bird/webhooks/info
craft bird/webhooks/remove

craft bird/log/prune --days=30            # gehört in einen Cron
craft bird/log/clear
```

## Es kann keinen Checkout aufhalten

Alles, was während des Checkouts läuft, scheitert **offen**. Jeder Trigger schluckt seine eigenen
Fehler, und der Push läuft standardmäßig über die Warteschlange — eine Störung bei Moneybird, ein
abgelaufenes Token oder ein Rate Limit können also nie der Grund sein, warum ein Kunde nicht zahlen
konnte. Was Sie stattdessen bekommen, ist eine Belegzeile im Zustand `failed`, ein Grund dazu und
`bird/sync/retry`.

Das Einzige, was **geschlossen** scheitert, ist ein nicht zugeordneter Steuersatz: Bird weist die
Bestellung ab und nennt den fehlenden Prozentsatz. Diese Ablehnung passiert auf Birds Seite der
Linie, nicht auf der des Checkouts.

## Denselben Umsatz zweimal buchen

Geht nicht. `{{%bird_documents}}` ist eindeutig über `(orderId, kind, sourceKey)` — `sourceKey` ist
leer für die Rechnung und der Hash der Rückerstattungstransaktion für eine Gutschrift — und dieser
Index *ist* die Garantie. Ein wiederholter Job, ein doppelt geklickter Button und ein Webhook, der
die Warteschlange überholt, stoßen alle darauf.

Stirbt ein Push in der Lücke zwischen „Moneybird hat die Rechnung angelegt“ und „Bird hat die Zeile
geschrieben“, sucht der nächste Versuch die Rechnung mit
`GET /sales_invoices/find_by_reference/{reference}.json` und übernimmt sie, statt eine zweite zu
buchen.

---

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
