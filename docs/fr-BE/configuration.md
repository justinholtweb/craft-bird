---
title: Configuration
slug: configuration
order: 20
summary: Chaque réglage de l'écran Bird, et pourquoi les valeurs par défaut sont ce qu'elles sont.
---

L'écran de réglages est **Réglages → Plugins → Bird**. Une page en huit sections.

## Connexion

| Réglage | Notes |
|---|---|
| **Jeton d'API** | Accepte une référence de la forme `$ENV_VAR`. Utilisez-en une. |
| **Administration** | Le numéro figurant dans les URL de Moneybird lui-même. |

## Documents

**Type de document** décide lequel des deux objets Moneybird Bird crée, et c'est la seule différence
entre les deux façons de travailler :

- **Facture de vente** — Moneybird possède le numéro de facture, produit le PDF et peut l'envoyer
  par courriel. Choisissez ceci si vos factures viennent de Moneybird.
- **Facture de vente externe** — la référence de commande *est* le numéro de facture, et Moneybird
  se contente de comptabiliser le chiffre d'affaires et la TVA. Pas de PDF, pas d'envoi.
  Choisissez ceci si votre boutique émet déjà ses propres factures.

Au niveau de l'API elles ne sont pas interchangeables — une facture de vente prend `invoice_date` et
un délai de paiement, une externe prend `date`, `due_date` et une URL source vers la commande — mais
tout le reste de Bird se comporte de la même manière dans les deux cas.

| Réglage | Défaut | Notes |
|---|---|---|
| **Envoyer à Moneybird quand** | La commande est payée | Ou à la clôture, à l'atteinte d'un statut, ou jamais. |
| **Statut déclencheur** | — | Uniquement si le déclencheur est *atteint un statut*. |
| **Envoyer via la file d'attente** | Activé | Laissez-le activé. Voir [Sécurité](usage#il-ne-peut-pas-bloquer-une-commande). |
| **Référence** | Référence de commande | Ce qui figure sur la facture : référence, numéro de commande, numéro court ou identifiant d'élément. |
| **Date de facture** | Date de la commande | Ou la date de paiement, ou aujourd'hui. |
| **Délai de paiement** | 14 jours | Factures de vente uniquement — Moneybird en déduit l'échéance. |
| **Nouvelles tentatives** | 5 | Combien de fois un envoi échoué mérite d'être retenté. |
| **Workflow**, **Style de document** | — | Les identifiants propres à Moneybird, si vous en utilisez plusieurs. |
| **Ignorer les commandes à 0** | Activé | Une commande à 0 € est en général un test ou une commande entièrement offerte. |

## TVA

| Réglage | Notes |
|---|---|
| **Pays d'origine** | Code à deux lettres. Le pays dont vous facturez la TVA par défaut. |
| **Taux de TVA** | Pourcentage → identifiant de taux Moneybird. **Proposer une association** le remplit depuis votre administration. |
| **Taux d'autoliquidation** | Le taux 0 % qui imprime *btw verlegd*. |
| **Taux d'exportation** | Le taux 0 % pour les ventes hors UE. |
| **One Stop Shop** *(Pro)* | Active les taux consommateurs par pays. |
| **Taux OSS** *(Pro)* | Pays → pourcentage → identifiant de taux. |
| **Champ du numéro de TVA** | Par défaut le `organizationTaxId` de l'adresse — c'est là que lisent aussi les contrôles de Commerce. |
| **Rapprocher les totaux** | Activé. Désactivé refuse une commande qui ne tombe pas juste au lieu d'ajouter une ligne d'arrondi. |

### Pourquoi les taux sont appariés sur le montant

Bird ne cherche pas un taux par son pourcentage. Commerce arrondit la TVA au centime ligne par
ligne : une ligne de 10,10 € à 21 % enregistre donc 2,12 € de TVA, ce qui redonne **20,99 %** une
fois recalculé, un taux qu'aucune boutique n'a jamais configuré. Une recherche par pourcentage
refuserait chaque jour des commandes parfaitement ordinaires.

Bird prend plutôt le net et la TVA tels qu'ils ont été enregistrés et cherche le taux associé dont
le calcul tombe à moins d'un centime près (ou à moins d'un demi pour cent de la TVA sur les lignes
plus importantes). Ce qui reste une fois toutes les lignes appariées devient la ligne d'arrondi.

### Rapprocher les totaux

Le total de la facture doit être égal à ce que le client a payé, parce que c'est ce que le flux
bancaire affichera. Quand l'arrondi ligne par ligne laisse un centime d'écart, Bird le comptabilise
sur une ligne à **0 %** : un centime d'écart d'arrondi de TVA n'est pas du chiffre d'affaires et ne
doit pas être taxé comme s'il en était.

Désactivez **Rapprocher les totaux** et Bird refuse la commande à la place, avec l'écart dans
l'erreur. Certains comptables préfèrent l'apprendre plutôt que de le voir discrètement masqué.

## Comptes comptables

| Réglage | Notes |
|---|---|
| **Compte de produits par défaut** | Où le chiffre d'affaires des lignes est comptabilisé. Vide laisse faire la valeur par défaut de Moneybird. |
| **Compte de frais de port** | Les frais de port ont en général leur propre compte. |
| **Compte de remises** | Une remise aussi. |
| **Par type de produit** *(Pro)* | Handle du type de produit Commerce → identifiant de compte comptable. Retombe sur le compte par défaut. |

## Contacts

| Réglage | Défaut | Notes |
|---|---|---|
| **Synchroniser les contacts** | Activé | Désactivé comptabilise chaque facture sur le contact de repli. |
| **Apparier les clients par** | Utilisateur Craft | Ou courriel, ou un nouveau contact par commande, ou aucun. |
| **Adresse** | Adresse de facturation | Quelle adresse Commerce devient celle du contact. |
| **Mettre à jour les contacts existants** | Activé | Bird garde une empreinte de la dernière charge utile envoyée : un client inchangé ne coûte donc aucun appel d'API. |
| **Contact de repli** | — | Utilisé lorsqu'il n'y a pas de données client exploitables. |

Commerce garantit un utilisateur Craft pour chaque adresse de courriel de commande —
`Order::setEmail()` appelle `Users::ensureUserByEmail()` — donc même une commande en tant qu'invité
a un identifiant d'utilisateur auquel rattacher un contact. C'est pourquoi **Utilisateur Craft** est
la valeur par défaut, et non le courriel.

## Paiements

| Réglage | Défaut | Notes |
|---|---|---|
| **Enregistrer les paiements** | Activé | Comptabilise un paiement sur le document créé. |
| **Compte financier** | — | Sur quel compte Moneybird le paiement atterrit. |
| **Créditer les remboursements** *(Pro)* | Activé | Les transactions de remboursement Commerce deviennent des notes de crédit. |

Les paiements passent par `POST /sales_invoices/{id}/payments.json`. Moneybird a déprécié l'ancien
point de terminaison `register_payment` ; Bird ne l'utilise pas.

## Webhook *(Pro)*

| Réglage | Notes |
|---|---|
| **URL du webhook** | En lecture seule. C'est l'URL à enregistrer. |
| **Accepter les webhooks** | Désactivé jusqu'à ce que vous en installiez un. |
| **Secret de signature** | Moneybird ne le renvoie qu'une seule fois, à la création. **Installer le webhook** le conserve pour vous. |
| **Statut à la facture payée** | Fait éventuellement passer la commande Commerce à ce statut dès que Moneybird annonce la facture payée. |

Voir [le webhook](usage#le-webhook) pour ce à quoi il sert et comment il est vérifié.

## Journal *(Pro)*

| Réglage | Défaut | Notes |
|---|---|---|
| **Journaliser les connexions** | Activé | Une ligne par requête. |
| **Conserver les charges utiles** | Activé | Corps des requêtes et des réponses, jetons et secrets caviardés. |
| **Conserver les entrées pendant** | 30 jours | `bird/log/prune` mérite une tâche cron. |

## Fichier de configuration

Tout ce qui précède peut être défini dans `config/bird.php`, qui l'emporte sur la base de données et
qui est ce que vous voulez si les réglages diffèrent d'un environnement à l'autre :

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

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
