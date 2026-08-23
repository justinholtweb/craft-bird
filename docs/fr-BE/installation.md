---
title: Installation
slug: installation
order: 10
summary: Prérequis, installation, connexion d'une administration et association de vos taux de TVA.
---

## Prérequis

- Craft CMS 5.3 ou plus récent
- Craft Commerce 5.0 ou plus récent
- PHP 8.2 ou plus récent
- Une administration Moneybird et un jeton d'API pour celle-ci

## Installer

```sh
composer require justinholtweb/craft-bird
php craft plugin/install bird
```

Ou cherchez **Bird** dans le Craft Plugin Store et installez-le depuis là.

## Rien n'est comptabilisé tant que ce n'est pas configuré

Bird s'installe inerte. Tant qu'il n'y a pas de jeton, pas d'identifiant d'administration et pas
d'association de taux de TVA, aucune commande ne part nulle part — les déclencheurs vérifient
`isConfigured()` et s'arrêtent là. Installer un plugin ne doit jamais se mettre à écrire dans votre
comptabilité de son propre chef.

## Connecter l'administration

Ouvrez **Réglages → Plugins → Bird**.

1. **Jeton d'API.** Créez-en un dans Moneybird sous votre profil → **Applications** → *jetons
   d'API*. Un jeton est aussi puissant que votre mot de passe : placez-le dans une variable
   d'environnement et référencez-le ici par `$MONEYBIRD_TOKEN` plutôt que d'en coller la valeur dans
   la base de données.
2. **Administration.** Le numéro qui figure dans les URL de Moneybird lui-même. Cliquez sur **Lister
   les administrations** si vous préférez choisir dans une liste plutôt que recopier.
3. Cliquez sur **Tester la connexion**. Cela appelle `GET /administrations.json` — le seul point de
   terminaison qui ne soit pas rattaché à une administration — et vous dit donc si le *jeton*
   fonctionne, même quand l'identifiant est faux.

## Associer vos taux de TVA

C'est l'étape qui compte, et la seule que Bird ne devinera pas.

1. Allez dans la section **TVA** et définissez votre **Pays d'origine** — le pays dont vous facturez
   la TVA par défaut. La valeur par défaut est `NL`.
2. Cliquez sur **Proposer une association**. Bird lit les taux de TVA qui existent déjà dans votre
   administration et propose un tableau pourcentage → identifiant de taux. Reportez-le dans **Taux
   de TVA**.
3. Réglez le **Taux d'autoliquidation** sur le taux 0 % de votre administration qui imprime *btw
   verlegd*, et le **Taux d'exportation** sur le taux 0 % que vous utilisez pour les ventes hors UE.

Ces deux-là sont des réglages distincts parce que 0 %, dans Moneybird, n'est pas une seule chose.
L'autoliquidation, l'exportation et le taux zéro véritable sont trois taux différents qui
atterrissent dans trois grilles différentes d'une déclaration de TVA, et choisir le mauvais produit
une déclaration qui s'équilibre et reste fausse.

**Un taux non associé refuse la commande** et nomme le pourcentage manquant. C'est délibéré : une
facture comptabilisée sous le mauvais taux de TVA est pire qu'une facture non comptabilisée, parce
que la seconde, quelqu'un finit par la chercher.

## Vérifier depuis un terminal

```sh
php craft bird/sync/status
```

Connexion, édition, configuration et nombre de documents, sans ouvrir le panneau de configuration.

## Éditions

Lite est gratuit et comptabilise les factures avec une TVA intérieure, une autoliquidation et une
exportation correctes. Pro coûte 99 $ une fois, avec 49 $ de renouvellement annuel, et ajoute les
parties qui deviennent intéressantes dès que vous vendez au-delà d'une frontière ou que vous
remboursez.

| | Lite | Pro |
|---|---|---|
| **Prix** | **Gratuit** | **99 $**, 49 $/an de renouvellement |
| Factures de vente ou factures de vente externes | ✅ | ✅ |
| Envoi à la commande payée, clôturée ou à un statut | ✅ | ✅ |
| Contacts créés, appariés et mis à jour | ✅ | ✅ |
| TVA intérieure, autoliquidation et exportation | ✅ | ✅ |
| Taux appariés sur le montant, pas sur un pourcentage | ✅ | ✅ |
| Rapprochement des arrondis | ✅ | ✅ |
| Enregistrement des paiements | ✅ | ✅ |
| Panneau sur l'écran de commande de Commerce, avec aperçu exact | ✅ | ✅ |
| Index des documents, API Twig `craft.bird.*`, commandes console | ✅ | ✅ |
| **One Stop Shop** — taux consommateurs par pays de l'UE | — | ✅ |
| **Notes de crédit** pour les remboursements Commerce | — | ✅ |
| **Envoyer la facture** depuis Moneybird | — | ✅ |
| **Webhook de facture payée**, à signature vérifiée | — | ✅ |
| **Comptes comptables par type de produit** | — | ✅ |
| **Journal de connexion** avec les charges utiles des requêtes et réponses | — | ✅ |

## Commandes existantes

Installer Bird ne touche pas aux commandes déjà payées. Pour les rattraper, utilisez le
[backfill](usage#backfill), qui dispose d'un `--dry-run` signalant ce qui serait comptabilisé sans
en comptabiliser quoi que ce soit.

---

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
