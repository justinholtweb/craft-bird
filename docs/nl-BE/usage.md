---
title: Gebruik
slug: usage
order: 30
summary: Bestellingen boeken, het bestelscherm, backfill, terugbetalingen, de webhook, Twig en de console.
---

## Het normale pad

Een bestelling wordt betaald. Commerce vuurt zijn event af, Bird zet een taak in de wachtrij, en die
taak bouwt een payload en maakt een document aan in Moneybird. Er komt een regel in de
documententabel van Bird, er wordt een betaling op de factuur geregistreerd, en het Moneybird-paneel
op het bestelscherm vult zich.

Zodra de plug-in is ingesteld hoeft u daar niets voor te doen.

## Het bestelscherm

Elke Commerce-bestelling krijgt een **Moneybird**-paneel:

- wat er geboekt is, met een link naar Moneybird
- de **btw-behandeling** en het btw-nummer dat Bird van het adres heeft gelezen
- **Naar Moneybird sturen** — boek het nu, wat de trigger ook zegt
- **Terugbetalingen crediteren** *(Pro)* — crediteer terugbetalingstransacties die nog niet
gecrediteerd zijn
- **Factuur voorbeeld** — de exacte JSON, zonder te versturen

Voorbeeld draait dezelfde `Invoices::buildPayload()` die de push draait. Er is één plek in de code
waar een bestelling Moneybird-JSON wordt, en het voorbeeld ís die plek — dus wat u vóór het boeken
te zien krijgt is byte voor byte wat er geboekt wordt, inclusief de btw-tarief-id's, en daar zitten
de fouten.

## Hoe de btw-motor beslist

| Situatie | Behandeling | Gebruikt tarief |
|---|---|---|
| Klant in uw thuisland | Binnenlands | Het toegewezen percentage |
| EU-onderneming, btw-nummer passend bij het land, geen btw berekend | Verlegd | Uw tarief voor *btw verlegd* |
| EU-consument, OSS aan *(Pro)* | OSS | Het tarief van dat land |
| EU-consument, OSS uit | Thuistarief | Het toegewezen percentage |
| Buiten de EU | Export | Uw exporttarief |

**Commerce bepaalt wat er berekend wordt; Bird bepaalt waar het geboekt wordt.** De belastingmotor
van Commerce kent uw zones, uw tarieven en uw btw-nummercontroles al. Dat hier opnieuw afleiden zou
uw webshop twee antwoorden geven die uit elkaar gaan lopen, en het antwoord dat de klant werkelijk
betaald heeft is dat van Commerce.

Let op de derde kolom van de regel over verlegging. Verlegging is een uitspraak over wat er
*berekend is*, niet over wat er berekend had kunnen worden. Een btw-nummer op een bestelling die
toch 21% betaalde is een webshop waar de btw-nummercontrole van Commerce nooit is aangezet, en het
als verlegd boeken zou de aangifte precies verlagen met wat de klant betaald heeft. Bird boekt wat
er gebeurd is.

## Backfill

Bestellingen die al betaald waren toen u Bird installeerde blijven ongemoeid tot u erom vraagt.

```sh
php craft bird/sync/backfill --since=2026-01-01 --dry-run
php craft bird/sync/backfill --since=2026-01-01 --limit=250
```

`--dry-run` meldt elke bestelling die geboekt zou worden, en onder welke behandeling, zonder iets te
versturen. Draai dat eerst. `--limit` staat standaard op 100 zodat een backfill niet uit zichzelf
tegen de ratelimiet aan loopt.

## Terugbetalingen *(Pro)*

Een terugbetalingstransactie uit Commerce wordt een Moneybird-creditnota, één per transactie, tegen
het tarief waarmee de btw erop kwam. Creditnota's worden gesleuteld op de hash van de
terugbetalingstransactie, dus dezelfde terugbetaling twee keer crediteren kan niet — de unieke index
op `(bestelling, soort, bron)` weigert het.

```sh
php craft bird/sync/refunds 1042
```

Of druk op **Terugbetalingen crediteren** bij de bestelling.

## De webhook *(Pro)*

Bird stuurt facturen; Moneybird is waar ze *betaald* worden — gekoppeld aan een banktransactie, of
met de hand afgevinkt. Zonder de webhook is het idee van uw site over "betaald" wat de
betaalprovider bij het afrekenen zei, en dat mist elke overschrijving.

```sh
php craft bird/webhooks/install
php craft bird/webhooks/info
php craft bird/webhooks/remove
```

**Webhook installeren** registreert hem en bewaart het ondertekeningsgeheim, dat Moneybird precies
één keer teruggeeft, bij het aanmaken. Elke levering wordt daarna gecontroleerd tegen de header
`Moneybird-Signature` — HMAC-SHA256 over `{timestamp}.{ruwe body}`, vergeleken in constante tijd, en
geweigerd als de timestamp meer dan vijf minuten afwijkt. Tijdens het roteren van een geheim komen
er meerdere handtekeningwaarden voorbij, dus elke match telt.

Geen geheim, geen handtekening, of een verlopen timestamp: geen verzoek. Het faalt gesloten.

Bird abonneert zich op de events voor betaald, te laat en oninbaar, voor beide documenttypen. Stel
**Status bij betaald** in als u wilt dat een betaalde factuur de Commerce-bestelling verzet.

## Twig

```twig
{% set document = craft.bird.documentForOrder(order) %}

{% if document and document.getIsBooked() %}
    <p>Factuur {{ document.getLabel() }} — {{ document.getStateLabel() }}</p>

    {# Een capability-URL: toon hem alleen aan de klant van wie de bestelling is. #}
    {% if document.publicUrl %}
        <a href="{{ document.publicUrl }}">Bekijk uw factuur</a>
    {% endif %}
{% endif %}

{% set vat = craft.bird.vatTreatment(order) %}
{% if vat.reverseCharge %}
    <p>Btw verlegd — {{ vat.vatNumber }}</p>
{% endif %}
```

`craft.bird.documentsForOrder(order)` geeft de factuur en elke creditnota terug.
`craft.bird.invoiceUrl(order)` is een snelkoppeling naar de publieke URL, en
`craft.bird.isConfigured()` bestaat zodat een template stil kan blijven op een installatie die nog
niet is ingesteld.

## Console

```sh
craft bird/sync/status                    # verbinding, editie, aantallen documenten
craft bird/sync/order 1042                # boek één bestelling, op referentie, nummer of id
craft bird/sync/order 1042 --force        # boek opnieuw, ook al is het al geboekt
craft bird/sync/backfill --since=2026-01-01 --dry-run --limit=100
craft bird/sync/retry --limit=100         # de mislukte pushes die nog pogingen over hebben
craft bird/sync/refunds 1042              # crediteer de terugbetalingen van een bestelling

craft bird/inspect/preview 1042           # de exacte JSON, zonder te versturen
craft bird/inspect/tax-rates              # id's voor de btw-toewijzing
craft bird/inspect/ledger-accounts
craft bird/inspect/financial-accounts
craft bird/inspect/workflows
craft bird/inspect/administrations

craft bird/webhooks/install
craft bird/webhooks/list
craft bird/webhooks/info
craft bird/webhooks/remove

craft bird/log/prune --days=30            # het waard om in een cron te zetten
craft bird/log/clear
```

## Het kan een checkout niet tegenhouden

Alles wat tijdens het afrekenen draait faalt **open**. Elke trigger slikt zijn eigen fouten in en de
push loopt standaard via de wachtrij, dus een storing bij Moneybird, een verlopen token of een
ratelimiet kan nooit de reden zijn dat een klant niet kon betalen. Wat u in plaats daarvan krijgt is
een documentregel in de staat `failed`, een reden erbij, en `bird/sync/retry`.

Het enige dat **gesloten** faalt is een niet-toegewezen btw-tarief: Bird weigert de bestelling en
noemt het ontbrekende percentage. Die weigering gebeurt aan de kant van Bird, niet aan de kant van
de checkout.

## Dezelfde omzet twee keer boeken

Dat kan niet. `{{%bird_documents}}` is uniek op `(orderId, kind, sourceKey)` — `sourceKey` is leeg
voor de factuur en de hash van de terugbetalingstransactie voor een creditnota — en die index ís de
garantie. Een opnieuw uitgevoerde taak, een dubbelgeklikte knop en een webhook die de wachtrij
inhaalt botsen er allemaal op.

Als een push sneuvelt in het gat tussen "Moneybird heeft de factuur aangemaakt" en "Bird heeft de
regel geschreven", zoekt de volgende poging de factuur op met `GET
/sales_invoices/find_by_reference/{reference}.json` en neemt die over in plaats van er een tweede te
boeken.

---

*Bird is een onafhankelijke plug-in. De plug-in is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
