---
title: Installatie
slug: installation
order: 10
summary: Vereisten, installatie, een administratie koppelen en je btw-tarieven toewijzen.
---

## Vereisten

- Craft CMS 5.3 of hoger
- Craft Commerce 5.0 of hoger
- PHP 8.2 of hoger
- Een Moneybird-administratie en een API-token daarvoor

## Installeren

```sh
composer require justinholtweb/craft-bird
php craft plugin/install bird
```

Of zoek **Bird** in de Craft Plugin Store en installeer het daar.

## Er wordt niets geboekt tot het ingesteld is

Bird installeert inactief. Zolang er geen token, geen administratie-id en geen btw-toewijzing is,
gaat er geen enkele bestelling ergens heen — de triggers controleren `isConfigured()` en keren
terug. Een plugin installeren hoort nooit uit zichzelf in je boekhouding te gaan schrijven.

## De administratie koppelen

Open **Instellingen → Plugins → Bird**.

1. **API-token.** Maak er een aan in Moneybird onder je profiel → **Applicaties** → *API-tokens*.
   Een token is net zo krachtig als je wachtwoord, dus zet hem in een omgevingsvariabele en verwijs
   er hier naar als `$MONEYBIRD_TOKEN` in plaats van de waarde in de database te plakken.
2. **Administratie.** Het nummer dat in de URL's van Moneybird zelf staat. Druk op **Administraties
   tonen** als je liever uit een lijst kiest dan overtypt.
3. Druk op **Verbinding testen**. Dat roept `GET /administrations.json` aan — het enige endpoint dat
   niet aan een administratie gebonden is — en vertelt je dus of het *token* werkt, ook als het id
   verkeerd is.

## Je btw-tarieven toewijzen

Dit is de stap die ertoe doet, en de enige waar Bird niet naar gaat raden.

1. Ga naar het onderdeel **Btw** en stel je **Thuisland** in — het land waarvan je standaard de btw
   in rekening brengt. Standaard is dat `NL`.
2. Druk op **Toewijzing voorstellen**. Bird leest de btw-tarieven die al in je administratie staan
   en stelt een tabel percentage → tarief-id voor. Neem die over in **Btw-tarieven**.
3. Zet het **Tarief voor btw verlegd** op het 0%-tarief in je administratie dat *btw verlegd*
   afdrukt, en het **Exporttarief** op het 0%-tarief dat je gebruikt voor verkopen buiten de EU.

Die laatste twee zijn aparte instellingen omdat 0% in Moneybird niet één ding is. Verlegd,
geëxporteerd en werkelijk nultarief zijn drie verschillende tarieven die in drie verschillende
rubrieken van een btw-aangifte terechtkomen, en het verkeerde kiezen levert een aangifte op die
klopt in de optelling en toch fout is.

**Een niet-toegewezen tarief weigert de bestelling** en noemt het percentage dat ontbreekt. Dat is
opzet: een factuur die tegen het verkeerde btw-tarief geboekt is, is erger dan een factuur die niet
geboekt is — want naar die laatste gaat iemand zoeken.

## Vanaf de opdrachtregel controleren

```sh
php craft bird/sync/status
```

Verbinding, editie, configuratie en aantallen documenten, zonder het controlepaneel te openen.

## Edities

Lite is gratis en boekt facturen met correcte binnenlandse btw, verlegging en export. Pro kost
eenmalig $ 99 met $ 49 per jaar verlenging, en voegt de onderdelen toe die pas gaan spelen zodra je
over de grens verkoopt of terugbetalingen doet.

| | Lite | Pro |
|---|---|---|
| **Prijs** | **Gratis** | **$ 99**, $ 49/jaar verlenging |
| Verkoopfacturen of externe verkoopfacturen | ✅ | ✅ |
| Versturen bij betaald, bij afgerond of bij een bestelstatus | ✅ | ✅ |
| Contacten aanmaken, koppelen en bijwerken | ✅ | ✅ |
| Binnenlandse btw, verlegging en export | ✅ | ✅ |
| Tarieven gematcht op het bedrag, niet op een percentage | ✅ | ✅ |
| Aansluiting van afrondingsverschillen | ✅ | ✅ |
| Betalingen registreren | ✅ | ✅ |
| Paneel op het bestelscherm van Commerce, met exact voorbeeld | ✅ | ✅ |
| Documentenoverzicht, `craft.bird.*` Twig-API, console-commando's | ✅ | ✅ |
| **One Stop Shop** — consumententarieven per EU-land | — | ✅ |
| **Creditnota's** voor terugbetalingen uit Commerce | — | ✅ |
| **De factuur versturen** vanuit Moneybird | — | ✅ |
| **Webhook voor betaalde facturen**, met handtekeningcontrole | — | ✅ |
| **Grootboekrekeningen per producttype** | — | ✅ |
| **Verbindingslogboek** met request- en response-payloads | — | ✅ |

## Bestaande bestellingen

Bird installeren raakt bestellingen die al betaald waren niet aan. Gebruik
[backfill](usage#backfill) om ze alsnog op te halen; die heeft een `--dry-run` die meldt wat er
geboekt zou worden zonder er iets van te boeken.

---

*Bird is een onafhankelijke plugin. De plugin is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
