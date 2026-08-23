---
title: Veelgestelde vragen
slug: faq
order: 50
summary: De vragen die het waard zijn beantwoord te worden vóór u installeert.
---

## Is Bird gelieerd aan Moneybird?

Neen. Bird is een onafhankelijke plug-in, gebouwd door Justin Holt. De plug-in is niet gelieerd aan
Moneybird, wordt door Moneybird niet onderschreven of gesponsord, en spreekt de publieke API van
Moneybird aan zoals elke andere client dat zou doen. "Moneybird" is een handelsmerk van de
rechthebbende.

## Is Bird gratis?

Lite wel, en het is geen proefversie. Facturen, contacten, betalingen, binnenlandse btw, verlegging,
export en de afrondingsaansluiting zitten allemaal gratis in Lite. Pro kost eenmalig $ 99 met $ 49
per jaar verlenging, en voegt One Stop Shop toe, creditnota's voor terugbetalingen, versturen vanuit
Moneybird, de webhook met handtekeningcontrole, grootboekrekeningen per producttype en het
verbindingslogboek.

## Verkoopfactuur of externe verkoopfactuur — wat wil ik?

Komen uw facturen uit Moneybird, dan wilt u **verkoopfacturen**: Moneybird nummert ze, maakt de pdf
en kan ze mailen.

Geeft uw webshop zijn eigen facturen al uit en moet enkel de boekhouding kloppen, dan wilt u
**externe verkoopfacturen**: de bestelreferentie is het factuurnummer en Moneybird boekt enkel de
omzet en de btw.

Het is één instelling, en al het overige werkt in beide gevallen hetzelfde.

## Berekent Bird de btw?

Neen, en dat is het ontwerp. De belastingmotor van Commerce kent uw zones, uw tarieven en uw
btw-nummercontroles al. Dat hier opnieuw afleiden zou uw webshop twee antwoorden geven die
uiteindelijk niet meer overeenkomen, en het antwoord dat de klant werkelijk betaald heeft is dat van
Commerce.

Wat Bird toevoegt is de *reden*. Moneybird moet weten naar welke 0% het kijkt, want verlegd,
geëxporteerd en werkelijk nultarief zijn drie verschillende tarieven die in drie verschillende
roosters van een btw-aangifte terechtkomen.

## Mijn klant heeft een btw-nummer maar de factuur toont 21%. Hoe kan dat?

Omdat er op de bestelling 21% berekend is. Verlegging is een uitspraak over wat er berekend is, niet
over wat er berekend had kunnen worden — en het als verlegd boeken zou uw btw-aangifte precies
verlagen met het bedrag dat de klant betaald heeft.

De onderliggende oorzaak is vrijwel altijd dat de btw-nummercontrole van Commerce uit staat. Zet die
aan, dan komen volgende bestellingen bij het afrekenen op nul uit, waarna Bird ze als verlegd boekt.

## Vertraagt of breekt het mijn checkout?

Neen. Alles wat tijdens het afrekenen draait faalt open — de triggers slikken hun eigen fouten in,
en de push loopt standaard via de wachtrij. Een storing bij Moneybird, een verlopen token of een
ratelimiet kan een mislukte documentregel en een nieuwe poging opleveren. Het kan geen klant
opleveren die niet kon betalen.

## Kan het dezelfde bestelling twee keer boeken?

Neen. De documententabel is uniek op `(bestelling, soort, bron)`, en die index is de garantie — een
opnieuw uitgevoerde taak, een dubbelgeklikte knop en een webhook die de wachtrij inhaalt botsen er
allemaal op. Sneuvelt een push tussen het aanmaken van de factuur in Moneybird en het vastleggen
ervan door Bird, dan vindt de volgende poging de factuur op referentie terug en neemt die over.

## Wat gebeurt er met bestellingen die betaald waren vóór ik het installeerde?

Niets, tot u erom vraagt. `bird/sync/backfill` haalt ze alsnog op, en `--dry-run` meldt elke
bestelling die geboekt zou worden, onder welke btw-behandeling, zonder iets te versturen.

## Heb ik de webhook nodig?

Enkel als facturen ergens anders betaald worden dan bij uw checkout. Bird stuurt facturen; Moneybird
is waar een overschrijving eraan gekoppeld wordt. Zonder de webhook is het idee van uw site over
"betaald" wat de betaalprovider bij het afrekenen zei. Mét de webhook vertelt Moneybird het u — over
een ondertekend verzoek, in constante tijd gecontroleerd en geweigerd na vijf minuten.

## Verwerkt het terugbetalingen?

Op Pro. Een terugbetalingstransactie uit Commerce wordt een Moneybird-creditnota, tegen het tarief
waarmee de btw erop kwam, gesleuteld op de eigen hash van de terugbetaling zodat er niet twee keer
gecrediteerd kan worden.

## Wat is de afrondingsregel?

Commerce rondt de btw per regel af op de cent, dus de som van de regels kan een cent afwijken van
het bestellingstotaal. Het factuurtotaal moet gelijk zijn aan wat de klant betaald heeft, want dat
is wat de bankkoppeling toont — dus het verschil wordt geboekt als een regel van 0%. Een cent
btw-afrondingsverschil is geen omzet en hoort niet belast te worden alsof het dat wel is.

Hoort u liever van die bestellingen dan dat ze aangesloten worden, zet **Totalen aansluiten** dan
uit; Bird weigert ze dan.

## Kan ik het met meerdere Moneybird-administraties gebruiken?

Eén administratie per Craft-installatie. Draait u meerdere webshops in één installatie die naar
verschillende administraties boeken, dan is dat iets wat Bird vandaag niet doet.

## Welke versies worden ondersteund?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Waar krijg ik hulp?

`justin@justinholt.com`.

---

*Bird is een onafhankelijke plug-in. De plug-in is niet gelieerd aan Moneybird en wordt door Moneybird
niet onderschreven of gesponsord. “Moneybird” is een handelsmerk van de rechthebbende.*
