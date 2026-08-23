---
title: Problemen oplossen
slug: troubleshooting
order: 40
summary: Wat de gebruikelijke storingen betekenen en wat u eraan doet.
---

Begin met `php craft bird/sync/status`. Dat meldt de verbinding, de editie, of de plug-in zichzelf
ingesteld vindt, en de aantallen documenten per staat — meestal genoeg om een configuratieprobleem
van een Moneybird-probleem te onderscheiden.

Op Pro staat in **Bird → Logboek** het verzoek en het antwoord van elke aanroep, met tokens en
geheimen weggelakt.

## "Geen btw-tarief toegewezen voor 21%"

Bird heeft de bestelling geweigerd in plaats van te gokken. Ga naar **Instellingen → Plugins → Bird
→ Btw**, druk op **Toewijzing voorstellen**, en zorg dat het percentage uit de melding een regel
heeft.

Dit is de enige storing die opzet is. Een factuur die tegen het verkeerde btw-tarief geboekt is, is
erger dan een factuur die niet geboekt is — want een ontbrekende factuur valt op en een verkeerde
niet.

## Er wordt niets verstuurd

Op volgorde van waarschijnlijkheid:

1. **Niet ingesteld.** Geen token, geen administratie-id, of geen btw-toewijzing.
`bird/sync/status` zegt dat gewoon.
2. **De wachtrij draait niet.** De push is een taak. Als de wachtrij van Craft niet door een daemon
of een cron gedraaid wordt, gaat er niets weg tot iemand het controlepaneel opent. Kijk bij
**Hulpmiddelen → Wachtrijbeheer**.
3. **De trigger past niet.** *De bestelling is betaald* reageert op het paid-event van Commerce. Als
uw provider de bestelling afrondt zonder hem op betaald te zetten, gebruik dan *afgerond* of een
status.
4. **Bestellingen van € 0 worden overgeslagen**, standaard en met opzet.

## Een bestelling staat op `failed`

Open hem. Het paneel toont de laatste fout. Daarna:

```sh
php craft bird/sync/retry
```

Retry pakt de mislukte pushes op die nog pogingen over hebben — **Nieuwe pogingen**, standaard 5.
Zodra een document geen pogingen meer heeft, negeert retry het en moet u bij de bestelling op
**Naar Moneybird sturen** drukken, wat de teller opnieuw laat beginnen.

## 422 van Moneybird bij het aanmaken

Bijna altijd een payload die Moneybirds schema niet accepteert. Beide create-endpoints verklaren
`unevaluatedProperties: false`, dus één onverwachte sleutel wijst de hele factuur af.

De klassieker is `amount_decimal`. De naslagtekst van Moneybird noemt dat veld, maar het is een
*response*-veld — meesturen levert een 422 op. Bird heeft daar een controle voor. Draai
`php craft bird/inspect/preview 1042` en vergelijk.

De andere is de velden van een verkoopfactuur naar het externe endpoint sturen of andersom. Een
verkoopfactuur neemt `invoice_date` en `first_due_interval`; een externe verkoopfactuur neemt
`date`, `due_date`, `source` en `source_url`. Ze zijn niet uitwisselbaar.

## 429, of pushes die traag gaan

Moneybird staat 150 verzoeken per 5 minuten toe, en 50 voor `/reports/`. Bird leest `Retry-After` en
wacht. Een backfill is de gebruikelijke manier om het plafond te vinden — daarom staat `--limit`
standaard op 100. Zet hem lager en draai vaker.

## Het factuurtotaal wijkt een cent af van wat de klant betaalde

Dat hoort niet, en met **Totalen aansluiten** aan gebeurt het niet — het verschil gaat op een
afrondingsregel van 0% zodat het totaal aansluit op de bankkoppeling.

Hebt u de aansluiting uitgezet, dan weigert Bird die bestellingen in plaats daarvan, met het
verschil in de foutmelding. Dat is de instelling die doet wat hij zegt.

## Een bestelling met een btw-nummer is op 21% geboekt

Dat klopt, en het is de meest voorkomende verrassing in de plug-in.

Verlegging is een uitspraak over wat er *berekend is*. Als de bestelling 21% betaalde, was het geen
verlegging, wat het adres ook zegt — en het als verlegd boeken zou uw aangifte precies verlagen met
wat de klant betaald heeft. Wat u ziet is een webshop met de btw-nummercontrole van Commerce uit.
Zet die aan in de belastinginstellingen van Commerce en volgende bestellingen komen bij het
afrekenen op nul uit, waarna Bird ze als verlegd boekt.

Bird repareert bestellingen die al btw berekend hebben niet met terugwerkende kracht. Het boekt wat
er gebeurd is.

## Contacten worden dubbel aangemaakt

Kijk bij **Klanten koppelen op**. Op *Craft-gebruiker* — de standaard — sleutelt Bird het
Moneybird-contact op het Craft-gebruikers-id, dat Commerce voor elk bestel-e-mailadres garandeert.
Op *Bestelnummer* is een nieuw contact per bestelling het gedocumenteerde gedrag, geen fout.

Als er al contacten in Moneybird stonden voordat Bird er was, hebben die geen `customer_id`, dus de
eerste bestelling van elk maakt er een tweede aan. De oplossing is `customer_id` op de bestaande
Moneybird-contacten passend te zetten.

## Webhook-leveringen worden geweigerd *(Pro)*

De controle faalt gesloten, en er zijn maar een paar manieren naar binnen:

- **Geen ondertekeningsgeheim opgeslagen.** Moneybird geeft het precies één keer terug, bij het
aanmaken. Is het geheim kwijt, verwijder de webhook en installeer hem opnieuw —
`bird/webhooks/remove` en dan `bird/webhooks/install`.
- **Verlopen timestamp.** Leveringen van meer dan vijf minuten oud worden geweigerd. Controleer de
klok van de server.
- **De body is onderweg gewijzigd.** De handtekening gaat over de *ruwe* bytes. Een proxy die de
JSON opnieuw serialiseert breekt hem.
- **Webhooks accepteren staat uit.** De webhook installeren zet dat niet vanzelf aan.

`php craft bird/webhooks/info` toont wat er geregistreerd is en of er een geheim is opgeslagen.

## Een push is halverwege gesneuveld en ik denk dat er dubbel geboekt is

Dat is niet zo. Herstel-op-referentie zoekt de factuur op met `find_by_reference` voordat er iets
aangemaakt wordt, en de unieke index op `(orderId, kind, sourceKey)` zit daarachter. Ziet u twee
facturen in Moneybird, dan is er één met de hand of door iets anders dan Bird gemaakt.

## Hulp

`justin@justinholt.com`, met de uitvoer van `bird/sync/status` en — op Pro — de betreffende
logboekregel.

---

*Bird is een onafhankelijke plug-in. De plug-in is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
