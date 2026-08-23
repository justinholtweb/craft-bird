---
title: FAQ
slug: faq
order: 50
summary: Die Fragen, die sich vor der Installation zu beantworten lohnen.
---

## Steht Bird in Verbindung zu Moneybird?

Nein. Bird ist ein unabhängiges Plugin von Justin Holt. Es steht in keiner Verbindung zu Moneybird,
wird von Moneybird weder unterstützt noch gesponsert, und spricht Moneybirds öffentliche API an wie
jeder andere Client auch. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.

## Ist Bird kostenlos?

Lite schon, und es ist keine Testversion. Rechnungen, Kontakte, Zahlungen, inländische
Umsatzsteuer, Reverse Charge, Ausfuhr und der Rundungsabgleich sind alle kostenlos in Lite
enthalten. Pro kostet einmalig 99 $ mit 49 $ Verlängerung pro Jahr und ergänzt One Stop Shop,
Gutschriften für Rückerstattungen, den Versand aus Moneybird, den signaturgeprüften Webhook,
Sachkonten je Produkttyp und das Verbindungsprotokoll.

## Verkaufsrechnung oder externe Verkaufsrechnung — was will ich?

Kommen Ihre Rechnungen aus Moneybird, wollen Sie **Verkaufsrechnungen**: Moneybird nummeriert sie,
erzeugt das PDF und kann sie versenden.

Stellt Ihr Shop seine Rechnungen bereits selbst aus und muss nur die Buchhaltung stimmen, wollen Sie
**externe Verkaufsrechnungen**: die Bestellreferenz ist die Rechnungsnummer, und Moneybird bucht nur
den Umsatz und die Umsatzsteuer.

Es ist eine Einstellung, und alles Übrige funktioniert in beiden Fällen gleich.

## Berechnet Bird die Umsatzsteuer?

Nein, und das ist so gewollt. Die Steuer-Engine von Commerce kennt Ihre Zonen, Ihre Sätze und Ihre
USt-IdNr.-Prüfungen bereits. Das hier noch einmal herzuleiten gäbe Ihrem Shop zwei Antworten, die
irgendwann nicht mehr übereinstimmen — und die, die der Kunde tatsächlich gezahlt hat, ist die von
Commerce.

Was Bird ergänzt, ist der *Grund*. Moneybird muss wissen, welche 0% gemeint sind, denn Reverse
Charge, Ausfuhr und echter Nullsatz sind drei verschiedene Sätze, die in drei verschiedenen Feldern
einer Umsatzsteuererklärung landen.

## Mein Kunde hat eine USt-IdNr., die Rechnung zeigt aber 21%. Warum?

Weil die Bestellung 21% berechnet hat. Reverse Charge ist eine Aussage darüber, was berechnet wurde,
nicht darüber, was hätte berechnet werden können — und es so zu buchen würde Ihre
Umsatzsteuererklärung um genau den Betrag mindern, den der Kunde gezahlt hat.

Die zugrunde liegende Ursache ist nahezu immer die abgeschaltete USt-IdNr.-Prüfung in Commerce.
Schalten Sie sie ein, und künftige Bestellungen werden im Checkout mit 0% berechnet — woraufhin Bird
sie als Reverse Charge bucht.

## Verlangsamt oder bricht es meinen Checkout?

Nein. Alles, was während des Checkouts läuft, scheitert offen — die Trigger schlucken ihre eigenen
Fehler, und der Push läuft standardmäßig über die Warteschlange. Eine Störung bei Moneybird, ein
abgelaufenes Token oder ein Rate Limit können eine fehlgeschlagene Belegzeile und einen erneuten
Versuch erzeugen. Sie können keinen Kunden erzeugen, der nicht bezahlen konnte.

## Kann es dieselbe Bestellung zweimal buchen?

Nein. Die Belegtabelle ist eindeutig über `(Bestellung, Art, Quelle)`, und dieser Index ist die
Garantie — ein wiederholter Job, ein doppelt geklickter Button und ein Webhook, der die
Warteschlange überholt, stoßen alle darauf. Stirbt ein Push zwischen dem Anlegen der Rechnung in
Moneybird und dem Festhalten durch Bird, findet der nächste Versuch die Rechnung über die Referenz
und übernimmt sie.

## Was passiert mit Bestellungen, die vor der Installation bezahlt wurden?

Nichts, bis Sie danach fragen. `bird/sync/backfill` holt sie nach, und `--dry-run` meldet jede
Bestellung, die gebucht würde, unter welcher umsatzsteuerlichen Behandlung, ohne etwas zu senden.

## Brauche ich den Webhook?

Nur wenn Rechnungen anderswo als in Ihrem Checkout bezahlt werden. Bird sendet Rechnungen; in
Moneybird wird eine Überweisung einer davon zugeordnet. Ohne den Webhook ist die Vorstellung Ihrer
Website von „bezahlt“ das, was der Zahlungsanbieter beim Checkout gesagt hat. Mit ihm sagt Moneybird
es Ihnen — über eine signierte Anfrage, in konstanter Zeit geprüft und nach fünf Minuten abgelehnt.

## Behandelt es Rückerstattungen?

In Pro. Eine Rückerstattungstransaktion aus Commerce wird zu einer Moneybird-Gutschrift, zu dem
Satz, mit dem die Steuer draufkam, über den eigenen Hash der Rückerstattung geschlüsselt, damit sie
nicht zweimal gutgeschrieben werden kann.

## Was ist die Rundungsposition?

Commerce rundet die Steuer je Position auf den Cent, die Summe der Positionen kann die
Bestellsumme also um einen Cent verfehlen. Die Rechnungssumme muss dem entsprechen, was der Kunde
gezahlt hat, denn das zeigt der Bankfeed — also wird die Differenz als Position mit 0% gebucht. Ein
Cent Rundungsdifferenz ist kein Umsatz und soll nicht besteuert werden, als wäre er einer.

Hören Sie von diesen Bestellungen lieber, als dass sie abgeglichen werden, schalten Sie **Summen
abgleichen** ab; dann weist Bird sie ab.

## Kann ich es mit mehreren Moneybird-Verwaltungen nutzen?

Eine Verwaltung je Craft-Installation. Betreiben Sie mehrere Shops in einer Installation, die in
verschiedene Verwaltungen buchen, ist das nichts, was Bird heute tut.

## Welche Versionen werden unterstützt?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Wo bekomme ich Hilfe?

`justin@justinholt.com`.

---

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
