---
title: Configuratie
slug: configuration
order: 20
summary: Elke instelling op het Bird-scherm, en waarom de standaardwaarden zijn wat ze zijn.
---

Het instellingenscherm is **Instellingen → Plugins → Bird**. Eén pagina in acht onderdelen.

## Verbinding

| Instelling | Toelichting |
|---|---|
| **API-token** | Accepteert een verwijzing als `$ENV_VAR`. Gebruik die. |
| **Administratie** | Het nummer uit de URL's van Moneybird zelf. |

## Documenten

**Documenttype** bepaalt welk van de twee Moneybird-objecten Bird aanmaakt, en dat is het enige
verschil tussen de twee manieren van werken:

- **Verkoopfactuur** — Moneybird bezit het factuurnummer, maakt de pdf en kan die mailen. Kies dit
  als je facturen uit Moneybird komen.
- **Externe verkoopfactuur** — de bestelreferentie *is* het factuurnummer en Moneybird boekt alleen
  de omzet en de btw. Geen pdf, geen verzending. Kies dit als je webshop zijn eigen facturen al
  uitgeeft.

Ze zijn op API-niveau niet uitwisselbaar — een verkoopfactuur neemt `invoice_date` en een
betaaltermijn, een externe neemt `date`, `due_date` en een bron-URL terug naar de bestelling — maar
al het overige in Bird werkt in beide gevallen hetzelfde.

| Instelling | Standaard | Toelichting |
|---|---|---|
| **Naar Moneybird sturen wanneer** | De bestelling is betaald | Of bij afronden, bij het bereiken van een status, of nooit. |
| **Triggerstatus** | — | Alleen als de trigger *bereikt een status* is. |
| **Via de wachtrij versturen** | Aan | Laat het aan staan. Zie [Veiligheid](usage#het-kan-een-checkout-niet-tegenhouden). |
| **Referentie** | Bestelreferentie | Wat er op de factuur komt: referentie, bestelnummer, kort nummer of element-id. |
| **Factuurdatum** | Datum besteld | Of de datum betaald, of vandaag. |
| **Betaaltermijn** | 14 dagen | Alleen verkoopfacturen — Moneybird leidt de vervaldatum eruit af. |
| **Nieuwe pogingen** | 5 | Hoe vaak een mislukte push het proberen waard is. |
| **Workflow**, **Documentstijl** | — | Moneybirds eigen id's, als je er meer dan één gebruikt. |
| **Bestellingen van € 0 overslaan** | Aan | Een bestelling van € 0 is meestal een test of een volledig weggegeven order. |

## Btw

| Instelling | Toelichting |
|---|---|
| **Thuisland** | Landcode van twee letters. Het land waarvan je standaard btw rekent. |
| **Btw-tarieven** | Percentage → Moneybird tarief-id. **Toewijzing voorstellen** vult dit uit je administratie. |
| **Tarief voor btw verlegd** | Het 0%-tarief dat *btw verlegd* afdrukt. |
| **Exporttarief** | Het 0%-tarief voor verkopen buiten de EU. |
| **One Stop Shop** *(Pro)* | Zet consumententarieven per land aan. |
| **OSS-tarieven** *(Pro)* | Land → percentage → tarief-id. |
| **Veld met btw-nummer** | Standaard `organizationTaxId` van het adres — daar lezen de validators van Commerce zelf ook uit. |
| **Totalen aansluiten** | Aan. Uit weigert een bestelling die niet aansluit in plaats van er een afrondingsregel bij te zetten. |

### Waarom tarieven op het bedrag gematcht worden

Bird zoekt een tarief niet op via het percentage. Commerce rondt de btw per regel af op de cent,
dus een regel van € 10,10 tegen 21% legt € 2,12 aan btw vast — wat teruggerekend **20,99%**
oplevert, een tarief dat geen enkele webshop ooit heeft ingesteld. Een opzoeking op percentage zou
doodgewone bestellingen dagelijks weigeren.

In plaats daarvan neemt Bird het netto- en het btw-bedrag zoals ze zijn vastgelegd, en zoekt het
toegewezen tarief waarvan de rekensom binnen een cent uitkomt (of binnen een halve procent van de
btw op grotere regels). Wat er na het matchen van alle regels overblijft, wordt de afrondingsregel.

### Totalen aansluiten

Het factuurtotaal moet gelijk zijn aan wat de klant betaald heeft, want dat is wat de bankkoppeling
zal tonen. Als de afronding per regel een cent verschil oplevert, boekt Bird dat als een regel van
**0%** — een cent btw-afrondingsverschil is geen omzet en hoort niet belast te worden alsof het dat
wel is.

Zet **Totalen aansluiten** uit en Bird weigert de bestelling in plaats daarvan, met het verschil in
de foutmelding. Sommige boekhouders horen dat liever dan dat het stilletjes wordt weggewerkt.

## Grootboekrekeningen

| Instelling | Toelichting |
|---|---|
| **Standaard omzetrekening** | Waar de omzet van de regels op geboekt wordt. Leeg laat het aan Moneybirds eigen standaard. |
| **Rekening verzendkosten** | Verzendkosten staan meestal op een eigen rekening. |
| **Rekening kortingen** | Een korting ook. |
| **Per producttype** *(Pro)* | Handle van het Commerce-producttype → grootboekrekening-id. Valt terug op de standaard. |

## Contacten

| Instelling | Standaard | Toelichting |
|---|---|---|
| **Contacten synchroniseren** | Aan | Uit boekt elke factuur op het terugvalcontact. |
| **Klanten koppelen op** | Craft-gebruiker | Of e-mail, of een nieuw contact per bestelling, of helemaal niet. |
| **Adres** | Factuuradres | Welk Commerce-adres het adres van het contact wordt. |
| **Bestaande contacten bijwerken** | Aan | Bird bewaart een vingerafdruk van de laatst verstuurde payload, dus een ongewijzigde klant kost geen API-aanroep. |
| **Terugvalcontact** | — | Gebruikt als er geen bruikbare klantgegevens zijn. |

Commerce zorgt voor een Craft-gebruiker bij elk bestel-e-mailadres — `Order::setEmail()` roept
`Users::ensureUserByEmail()` aan — dus zelfs een gastbestelling heeft een gebruikers-id om een
contact aan op te hangen. Daarom is **Craft-gebruiker** de standaard en niet e-mail.

## Betalingen

| Instelling | Standaard | Toelichting |
|---|---|---|
| **Betalingen registreren** | Aan | Boekt een betaling op het aangemaakte document. |
| **Financiële rekening** | — | Op welke Moneybird-rekening de betaling landt. |
| **Terugbetalingen crediteren** *(Pro)* | Aan | Terugbetalingstransacties uit Commerce worden creditnota's. |

Betalingen gaan naar `POST /sales_invoices/{id}/payments.json`. Moneybird heeft het oudere endpoint
`register_payment` afgeschaft; Bird gebruikt het niet.

## Webhook *(Pro)*

| Instelling | Toelichting |
|---|---|
| **Webhook-URL** | Alleen-lezen. Dit is de URL die je registreert. |
| **Webhooks accepteren** | Uit tot je er een installeert. |
| **Ondertekeningsgeheim** | Moneybird geeft dit precies één keer terug, bij het aanmaken. **Webhook installeren** bewaart het voor je. |
| **Status bij betaald** | Verzet de Commerce-bestelling desgewenst naar deze status zodra Moneybird zegt dat de factuur betaald is. |

Zie [de webhook](usage#de-webhook) voor waar hij voor is en hoe hij gecontroleerd wordt.

## Logboek *(Pro)*

| Instelling | Standaard | Toelichting |
|---|---|---|
| **Verbindingen loggen** | Aan | Eén regel per verzoek. |
| **Payloads bewaren** | Aan | Request- en response-bodies, met tokens en geheimen weggelakt. |
| **Regels bewaren gedurende** | 30 dagen | `bird/log/prune` is het waard om in een cron te zetten. |

## Configuratiebestand

Alles hierboven kan in `config/bird.php`, dat het wint van de database en is wat je wilt als de
instellingen per omgeving verschillen:

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

*Bird is een onafhankelijke plugin. De plugin is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
