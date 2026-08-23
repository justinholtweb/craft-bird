<?php

/**
 * Bird's user-facing strings, Belgian Dutch.
 *
 * An overlay, not a full catalogue: Yii's PhpMessageSource merges nl-BE over nl,
 * so only the strings that actually differ belong here. What differs is register —
 * Flemish business copy takes u/uw rather than je/jouw — plus a handful of words
 * (enkel for alleen, roosters for rubrieken, neen for nee). Everything else falls
 * through to nl next door.
 */
return [
    'A **sales invoice** lets Moneybird number, style and send the invoice. An **external sales invoice** books an invoice your shop already issued under its own number — no PDF, no sending, just the revenue and the VAT.' => 'Een **verkoopfactuur** laat Moneybird de factuur nummeren, vormgeven en versturen. Een **externe verkoopfactuur** boekt een factuur die uw webshop al onder zijn eigen nummer heeft uitgegeven — geen pdf, geen verzending, enkel de omzet en de btw.',
    'Bird never re-decides what to charge — Commerce already did that, and it is what the customer paid. What Moneybird needs on top of the number is the *reason*: 0% reverse-charged, 0% exported and 0% zero-rated are three different rates over there, and they land in three different boxes on a VAT return.' => 'Bird beslist nooit opnieuw wat er berekend moet worden — dat heeft Commerce al gedaan, en dat is wat de klant betaald heeft. Wat Moneybird bovenop het bedrag nodig heeft is de *reden*: 0% verlegd, 0% geëxporteerd en 0% nultarief zijn daar drie verschillende tarieven, en ze komen in drie verschillende roosteren van een btw-aangifte terecht.',
    'Create a token at moneybird.com under your profile → “Applications”, then pick the administration to book into. A token is as powerful as your password, so keep it in an environment variable rather than in project config.' => 'Maak een token aan op moneybird.com onder uw profiel → “Applicaties”, en kies daarna de administratie om in te boeken. Een token is net zo krachtig als uw wachtwoord, dus houd het in een omgevingsvariabele in plaats van in project config.',
    'Moneybird financial account id. Blank leaves the payment unassigned, which is what you want when the bank feed will match it later.' => 'Moneybird-financiële-rekening-id. Leeg laat de betaling ongekoppeld, wat u wilt als de bankkoppeling hem later matcht.',
    'Moneybird only posts to HTTPS URLs. This site resolves to {url}.' => 'Moneybird post enkel naar HTTPS-URL’s. Deze site komt uit op {url}.',
    'Moneybird shows this once, when the webhook is created. Bird stores it here; move it to an environment variable if you would rather it did not sit in project config.' => 'Moneybird toont dit één keer, bij het aanmaken van de webhook. Bird bewaart het hier; zet het in een omgevingsvariabele als u liever niet hebt dat het in project config staat.',
    'Moneybird workflow id. Blank uses the administration default. Sales invoices only.' => 'Moneybird-workflow-id. Leeg gebruikt de standaard van de administratie. Enkel verkoopfacturen.',
    'Never — only when I say so' => 'Nooit — enkel als ik het zeg',
    'Pro. Ask Moneybird to send the invoice once it has been created. Sales invoices only.' => 'Pro. Vraag Moneybird de factuur te versturen zodra hij is aangemaakt. Enkel verkoopfacturen.',
    'The connection log, One Stop Shop tax rates, per-product-type ledger accounts, credit notes for refunds, sending invoices from Moneybird and the paid-invoice webhook are Pro features. Their settings are shown below but have no effect until you upgrade.' => 'Het verbindingslogboek, One Stop Shop-btw-tarieven, grootboekrekeningen per producttype, creditnota’s voor terugbetalingen, facturen versturen vanuit Moneybird en de webhook voor betaalde facturen zijn Pro-functies. Hun instellingen staan hieronder maar hebben geen effect tot u upgradet.',
    'The country you file VAT in, as a two-letter code. Everything the VAT engine decides is relative to it.' => 'Het land waar uw btw-aangifte doet, als code van twee letters. Alles wat de btw-motor beslist is daaraan relatief.',
    'You’re running Bird Lite.' => 'U draait Bird Lite.',
];
