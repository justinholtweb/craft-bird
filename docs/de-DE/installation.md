---
title: Installation
slug: installation
order: 10
summary: Voraussetzungen, Installation, eine Verwaltung verbinden und die Steuersätze zuordnen.
---

## Voraussetzungen

- Craft CMS 5.3 oder neuer
- Craft Commerce 5.0 oder neuer
- PHP 8.2 oder neuer
- Eine Moneybird-Verwaltung und ein API-Token dafür

## Installieren

```sh
composer require justinholtweb/craft-bird
php craft plugin/install bird
```

Oder **Bird** im Craft Plugin Store suchen und von dort installieren.

## Es wird nichts gebucht, bis es eingerichtet ist

Bird installiert sich untätig. Solange es kein Token, keine Verwaltungs-ID und keine
Steuersatzzuordnung gibt, geht keine Bestellung irgendwohin — die Trigger prüfen `isConfigured()`
und kehren zurück. Ein Plugin zu installieren darf niemals von selbst anfangen, in Ihre Buchhaltung
zu schreiben.

## Die Verwaltung verbinden

Öffnen Sie **Einstellungen → Plugins → Bird**.

1. **API-Token.** Erstellen Sie eines in Moneybird unter Ihrem Profil → **Anwendungen** →
   *API-Tokens*. Ein Token ist so mächtig wie Ihr Passwort — legen Sie es in eine
   Umgebungsvariable und verweisen Sie hier als `$MONEYBIRD_TOKEN` darauf, statt den Wert in die
   Datenbank zu schreiben.
2. **Verwaltung.** Die Nummer, die in den URLs von Moneybird selbst steht. Klicken Sie auf
   **Verwaltungen auflisten**, wenn Sie lieber aus einer Liste wählen als abtippen.
3. Klicken Sie auf **Verbindung testen**. Das ruft `GET /administrations.json` auf — den einzigen
   Endpunkt, der nicht an eine Verwaltung gebunden ist — und sagt Ihnen deshalb, ob das *Token*
   funktioniert, auch wenn die ID falsch ist.

## Die Steuersätze zuordnen

Das ist der Schritt, auf den es ankommt, und der einzige, bei dem Bird nicht raten wird.

1. Gehen Sie zum Abschnitt **Steuer** und setzen Sie Ihr **Heimatland** — das Land, dessen
   Umsatzsteuer Sie standardmäßig berechnen. Voreingestellt ist `NL`.
2. Klicken Sie auf **Zuordnung vorschlagen**. Bird liest die Steuersätze, die es in Ihrer Verwaltung
   bereits gibt, und schlägt eine Tabelle Prozentsatz → Steuersatz-ID vor. Übernehmen Sie sie in
   **Steuersätze**.
3. Setzen Sie den **Reverse-Charge-Steuersatz** auf den 0%-Satz in Ihrer Verwaltung, der *btw
   verlegd* ausweist, und den **Ausfuhrsteuersatz** auf den 0%-Satz für Verkäufe außerhalb der EU.

Diese beiden sind getrennte Einstellungen, weil 0% in Moneybird nicht eine Sache ist. Reverse
Charge, Ausfuhr und echter Nullsatz sind drei verschiedene Sätze, die in drei verschiedenen Feldern
einer Umsatzsteuererklärung landen, und den falschen zu wählen ergibt eine Erklärung, die in der
Summe aufgeht und trotzdem falsch ist.

**Ein nicht zugeordneter Satz weist die Bestellung ab** und nennt den fehlenden Prozentsatz. Das ist
Absicht: eine Rechnung, die unter dem falschen Steuersatz gebucht wurde, ist schlimmer als eine, die
gar nicht gebucht wurde — denn nach der zweiten sucht jemand.

## Von der Kommandozeile prüfen

```sh
php craft bird/sync/status
```

Verbindung, Edition, Konfiguration und Belegzahlen, ohne das Control Panel zu öffnen.

## Editionen

Lite ist kostenlos und bucht Rechnungen mit korrekter inländischer Umsatzsteuer, Reverse Charge und
Ausfuhr. Pro kostet einmalig 99 $ mit 49 $ Verlängerung pro Jahr und ergänzt die Teile, die
interessant werden, sobald Sie über eine Grenze verkaufen oder Rückerstattungen leisten.

| | Lite | Pro |
|---|---|---|
| **Preis** | **Kostenlos** | **99 $**, 49 $/Jahr Verlängerung |
| Verkaufsrechnungen oder externe Verkaufsrechnungen | ✅ | ✅ |
| Senden bei bezahlt, bei abgeschlossen oder bei einem Bestellstatus | ✅ | ✅ |
| Kontakte anlegen, zuordnen und aktualisieren | ✅ | ✅ |
| Inländische Umsatzsteuer, Reverse Charge und Ausfuhr | ✅ | ✅ |
| Sätze nach dem Betrag zugeordnet, nicht nach dem Prozentsatz | ✅ | ✅ |
| Abgleich der Rundungsdifferenzen | ✅ | ✅ |
| Zahlungen erfassen | ✅ | ✅ |
| Panel im Bestellbildschirm von Commerce, mit exakter Vorschau | ✅ | ✅ |
| Belegübersicht, `craft.bird.*` Twig-API, Konsolenbefehle | ✅ | ✅ |
| **One Stop Shop** — Verbrauchersätze je EU-Land | — | ✅ |
| **Gutschriften** für Rückerstattungen aus Commerce | — | ✅ |
| **Die Rechnung versenden** aus Moneybird | — | ✅ |
| **Webhook für bezahlte Rechnungen**, signaturgeprüft | — | ✅ |
| **Sachkonten je Produkttyp** | — | ✅ |
| **Verbindungsprotokoll** mit Request- und Response-Payloads | — | ✅ |

## Bestehende Bestellungen

Bird zu installieren rührt bereits bezahlte Bestellungen nicht an. Um sie nachzuholen, nutzen Sie
den [Backfill](usage#backfill); der hat ein `--dry-run`, das meldet, was gebucht würde, ohne etwas
davon zu buchen.

---

*Bird ist ein unabhängiges Plugin. Es steht in keiner Verbindung zu Moneybird und wird von Moneybird
weder unterstützt noch gesponsert. „Moneybird“ ist eine Marke des jeweiligen Rechteinhabers.*
