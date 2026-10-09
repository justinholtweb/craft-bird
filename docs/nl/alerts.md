---
title: Meldingen
slug: alerts
order: 35
summary: Eén e-mail (en desgewenst een Slack- of Teams-bericht) als het boeken in Moneybird misloopt en één als het weer goed is, plus een dashboardwidget en een banner in het controlepaneel.
---

Het scherm Documenten weet alles wat er misgegaan is. Alleen opent niemand het op een dag dat de
webshop lijkt te werken, en een bestelling die Moneybird nooit bereikt heeft is omzet die je pas
mist bij het voorbereiden van de btw-aangifte. Daarom laat Bird het je weten als een van drie dingen
gebeurt:

| Incident | Opent als | Sluit als |
|---|---|---|
| **Bestellingen niet geboekt in Moneybird** | `alertDocumentThreshold` facturen of creditnota's (standaard 1) zijn **mislukt**: Moneybird weigerde ze, of Bird wilde ze niet versturen (een niet-toegewezen btw-tarief, geen contact om te factureren) | ze allemaal geboekt zijn, of vergeten op het scherm Documenten |
| **Moneybird weigert het API-token** | Moneybird antwoordt met 401 of 403: het token is ingetrokken, verlopen of mist een scope | het volgende verzoek dat Moneybird accepteert |
| **Het boeken in Moneybird ligt stil** | een afgeronde bestelling die Bird had moeten boeken is dat na `alertStallHours` (standaard 6) nog steeds niet, of een push staat al zo lang op *In afwachting* | de bestellingen geboekt zijn, door de wachtrij, een knop of `bird/sync/backfill` |

Je krijgt **één bericht als een incident begint en één als het voorbij is**, nooit één per
bestelling. Elk incident heeft één vergrendelregel in de database: honderd keer controleren terwijl
het nog openstaat verstuurt niets nieuws. Nieuwe mislukkingen die binnenkomen terwijl het openstaat
verschijnen op de dashboardwidget en in de banner van het controlepaneel, niet in je inbox, en het
incident sluit pas als de laatste ervan is opgelost. Een incident dat blijft haperen wordt
tegengehouden door `alertCooldownMinutes` (standaard 60): gaat het binnen die tijd na het
herstelbericht opnieuw open, dan hoor je het als de rustperiode voorbij is, en alleen als het dan
nog speelt.

Een timeout, een 429 of een 500 is geen authenticatiefout, en een 422 evenmin — dat is Moneybird
die één factuur weigert, en dat is het eerste incident.

Een stilliggende bestelling is er een die Bird uit zichzelf geboekt zou hebben, onder de trigger die
je gekozen hebt. Met **Nooit — alleen als ik het zeg** kan er niets stilliggen. Met **De bestelling
is betaald** begint de klok bij de betaling; met **De bestelling is afgerond** bij het plaatsen; met
**De bestelling bereikt een status** bij de laatste wijziging terwijl hij in die status stond. Een
bestelling van € 0 telt niet mee als **Bestellingen van € 0 overslaan** aan staat. Bestellingen
ouder dan een week, of geplaatst voordat Bird geïnstalleerd was, zijn voor `bird/sync/backfill`. Een
bestelling met een mislukt document hoort bij het eerste incident, niet bij dit.

## Instellen

**Instellingen → Plugins → Bird → Meldingen.**

| Instelling | Standaard | |
|---|---|---|
| `alertRecipients` | leeg | Adressen gescheiden door komma's, of een `$ENV`-verwijzing. Leeg betekent geen e-mail |
| `alertWebhookUrl` | leeg | Een incoming-webhook-URL van Slack of Teams, of een `$ENV`-verwijzing. Zet hem in een omgevingsvariabele: de URL is zelf de sleutel |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, of `json` voor je eigen ontvanger |
| `alertWebhookSecret` | leeg | Indien ingesteld draagt elke meldingswebhook `X-Bird-Timestamp` en `X-Bird-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnDocuments` | aan | |
| `alertDocumentThreshold` | 1 | |
| `alertOnAuthFailure` | aan | |
| `alertStallHours` | 6 | 0 zet meldingen over stilstand uit |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | uit | Alleen in het configuratiebestand — zie hieronder |

Dit zit allemaal in Bird Lite. Mail gaat via de eigen mailer van Craft, dus die gebruikt wat er
onder **Instellingen → E-mail** staat. Druk na het opslaan op **Testmelding versturen** op het
instellingenscherm (of draai `php craft bird/alerts/test`) om te controleren dat beide kanalen
aankomen.

Niets hiervan is verplicht. Een site zonder ontvangers en zonder webhook legt incidenten nog steeds
vast en toont ze op de dashboardwidget en in de banner van het controlepaneel. Voeg je later een
ontvanger toe, dan wordt elk incident dat nog openstaat bij de volgende controle verstuurd. Een site
zonder API-token of administratie wordt helemaal niet gecontroleerd.

## Wanneer meldingen gecontroleerd worden

- **Telkens als een document verandert**: mislukt, geboekt, betaald, vergeten. Wat het ook
  veroorzaakte — de wachtrij, een knop, de console, de webhook — dus mislukte boekingen hebben geen
  cron nodig.
- **Op het moment dat Moneybird het token weigert**: authenticatie.
- **`php craft bird/alerts/check`**, en aan het eind van `bird/sync/retry` en `bird/sync/backfill`:
  alles.

Stilstand is de afwezigheid van activiteit, dus alleen de laatste hiervan kan die opmerken. Zet de
controle of de retry in een cron:

```sh
*/15 * * * * cd /path/to/site && php craft bird/alerts/check >> /dev/null 2>&1
```

## Wat een melding zegt

Een e-mail in platte tekst: de site, het incident, wat er gezien is, wat je moet doen, en een link
rechtstreeks naar het juiste scherm — de instellingen van Bird bij een geweigerd token, het scherm
Documenten gefilterd op mislukte documenten bij mislukte boekingen, en het scherm Documenten bij
stilstand. Het Slack-bericht, de Teams-kaart en het JSON-event bevatten hetzelfde.

Wat er gezien is wordt weggelakt voordat het de site verlaat: het API-token, het
ondertekeningsgeheim van de Moneybird-webhook en het geheim van de meldingswebhook worden op waarde
verwijderd, alles wat op een sleutel lijkt (`Bearer …`, `password=…`, `"api_key": …`) op patroon,
markup wordt gestript en de regel wordt afgekapt op 500 tekens. Meldingen noemen bestellingen bij
hun referentie en bevatten nooit een adres, een orderregel of een payload.

## De webhook

Incoming webhooks van Slack en Teams werken zoals ze zijn. Het `json`-formaat post:

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

`event` is `bird.alert.recovered` als het voorbij is; `incident` is `documents`, `auth` of
`stalled`.

Dit is een andere webhook dan die welke Moneybird naar Bird stuurt als een factuur betaald is. De URL
wordt gecontroleerd elke keer dat hij gebruikt wordt, niet alleen bij het opslaan. Hij moet `http`
of `https` zijn zonder gebruikersnaam of wachtwoord erin, elk adres waar de host naar verwijst moet
publiek zijn — niet privé, loopback, link-local (de metadatadienst van de cloud) of carrier-grade
NAT — en het verzoek wordt vastgepind op die adressen, zodat DNS niet omgezet kan worden tussen de
controle en het versturen. Doorverwijzingen worden nooit gevolgd. Voor een zelfgehoste Mattermost op
je eigen netwerk zet je `allowPrivateAlertWebhookHosts` in `config/bird.php`. De regels voor het
schema en doorverwijzingen blijven gelden.

Als elk kanaal faalt, wordt de melding niet als verstuurd gemarkeerd en probeert de volgende controle
het opnieuw.

## De dashboardwidget en de banner

**Dashboard → Nieuwe widget → Moneybird-boekingen** toont hoeveel documenten mislukt, in
afwachting, concept, openstaand, te laat, betaald en geboekt zijn — elk aantal gelinkt naar het
scherm Documenten, daarop gefilterd — elk openstaand incident met wat er gezien is, en wanneer er
voor het laatst een document in Moneybird geboekt of eruit teruggelezen is. Hij leest dezelfde
vergrendelregels waar de meldingen uit komen, dus de widget en je inbox kunnen het niet oneens zijn.

Zolang een incident openstaat, staat er een banner boven in het controlepaneel die dat zegt, met een
link naar het scherm Documenten. Beide worden alleen getoond aan wie *Moneybird-documenten bekijken*
mag.

## Een melding aanpassen of onderdrukken

```php
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail, $e->subject, $e->body, $e->payload
    $e->subject = '[Boekhouding] ' . $e->subject;

    // Inslikken. De vergrendelregel telt hem toch als verstuurd.
    if ($e->incident === Alerts::INCIDENT_STALLED && !$e->recovered) {
        $e->isValid = false;
    }
});
```

---

*Bird is een onafhankelijke plugin. De plugin is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
