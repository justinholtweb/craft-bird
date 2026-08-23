---
title: FAQ
slug: faq
order: 50
summary: Les questions qui valent la peine d'être tranchées avant d'installer.
---

## Bird est-il affilié à Moneybird ?

Non. Bird est un plugin indépendant développé par Justin Holt. Il n'est ni affilié à Moneybird, ni
approuvé, ni sponsorisé par Moneybird, et il dialogue avec l'API publique de Moneybird comme le
ferait n'importe quel autre client. « Moneybird » est une marque de son titulaire respectif.

## Bird est-il gratuit ?

Lite l'est, et ce n'est pas une version d'essai. Les factures, les contacts, les paiements, la TVA
intérieure, l'autoliquidation, l'exportation et le rapprochement des arrondis sont tous gratuits
dans Lite. Pro coûte 99 $ une fois, avec 49 $ de renouvellement annuel, et ajoute le One Stop Shop,
les notes de crédit pour les remboursements, l'envoi depuis Moneybird, le webhook à signature
vérifiée, les comptes comptables par type de produit et le journal de connexion.

## Facture de vente ou facture de vente externe — laquelle me faut-il ?

Si vos factures viennent de Moneybird, il vous faut des **factures de vente** : Moneybird les
numérote, produit le PDF et peut les envoyer par courriel.

Si votre boutique émet déjà ses propres factures et que seule la comptabilité doit être juste, il
vous faut des **factures de vente externes** : la référence de commande est le numéro de facture, et
Moneybird se contente de comptabiliser le chiffre d'affaires et la TVA.

C'est un seul réglage, et tout le reste fonctionne de la même manière dans les deux cas.

## Bird calcule-t-il la TVA ?

Non, et c'est le principe. Le moteur fiscal de Commerce connaît déjà vos zones, vos taux et vos
contrôles de numéro de TVA. Le redériver ici donnerait à votre boutique deux réponses qui finiraient
par ne plus concorder — et celle que le client a réellement payée est celle de Commerce.

Ce que Bird ajoute, c'est la *raison*. Moneybird doit savoir de quel 0 % il s'agit, parce que
l'autoliquidation, l'exportation et le taux zéro véritable sont trois taux différents qui
atterrissent dans trois grilles différentes d'une déclaration de TVA.

## Mon client a un numéro de TVA mais la facture affiche 21 %. Pourquoi ?

Parce que la commande a facturé 21 %. L'autoliquidation est une affirmation sur ce qui a été
facturé, pas sur ce qui aurait pu l'être — et la comptabiliser ainsi minorerait votre déclaration de
TVA exactement du montant payé par le client.

La cause sous-jacente est presque toujours le contrôle du numéro de TVA de Commerce laissé
désactivé. Activez-le et les commandes suivantes passeront à 0 % dès la commande, après quoi Bird
les comptabilisera en autoliquidation.

## Cela va-t-il ralentir ou casser mon checkout ?

Non. Tout ce qui s'exécute pendant la commande échoue en mode ouvert — les déclencheurs avalent
leurs propres erreurs, et l'envoi passe par la file d'attente par défaut. Une panne chez Moneybird,
un jeton expiré ou une limite de débit peuvent produire une ligne de document en échec et une
nouvelle tentative. Ils ne peuvent pas produire un client qui n'a pas pu payer.

## Peut-il comptabiliser deux fois la même commande ?

Non. La table des documents est unique sur `(commande, type, source)`, et cet index est la garantie
— une tâche relancée, un bouton cliqué deux fois et un webhook qui double la file d'attente butent
tous dessus. Si un envoi meurt entre la création de la facture par Moneybird et son enregistrement
par Bird, la tentative suivante retrouve la facture par la référence et la reprend.

## Qu'advient-il des commandes payées avant l'installation ?

Rien, tant que vous ne le demandez pas. `bird/sync/backfill` les rattrape, et `--dry-run` signale
chaque commande qui serait comptabilisée, sous quel traitement TVA, sans rien envoyer.

## Ai-je besoin du webhook ?

Seulement si les factures sont payées ailleurs qu'à votre checkout. Bird envoie les factures ; c'est
dans Moneybird qu'un virement est rapproché de l'une d'elles. Sans le webhook, l'idée que votre site
se fait de « payé » est ce qu'a dit la passerelle à la commande. Avec lui, Moneybird vous le dit —
par une requête signée, vérifiée en temps constant et rejetée au-delà de cinq minutes.

## Gère-t-il les remboursements ?

En Pro. Une transaction de remboursement Commerce devient une note de crédit Moneybird, au taux
auquel la TVA a été appliquée, indexée sur le hash du remboursement lui-même pour qu'il ne puisse
pas être crédité deux fois.

## Qu'est-ce que la ligne d'arrondi ?

Commerce arrondit la TVA au centime ligne par ligne : la somme des lignes peut donc manquer le total
de la commande d'un centime. Le total de la facture doit être égal à ce que le client a payé, parce
que c'est ce qu'affiche le flux bancaire — l'écart est donc comptabilisé sur une ligne à 0 %. Un
centime d'écart d'arrondi de TVA n'est pas du chiffre d'affaires et ne doit pas être taxé comme s'il
en était.

Si vous préférez être informé de ces commandes plutôt que de les voir rapprochées, désactivez
**Rapprocher les totaux** et Bird les refusera.

## Puis-je l'utiliser avec plusieurs administrations Moneybird ?

Une administration par installation Craft. Si vous exploitez plusieurs boutiques dans une seule
installation et qu'elles comptabilisent vers des administrations différentes, ce n'est pas quelque
chose que Bird fait aujourd'hui.

## Quelles versions sont prises en charge ?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

## Où obtenir de l'aide ?

`justin@justinholt.com`.

---

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
