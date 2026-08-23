---
title: Utilisation
slug: usage
order: 30
summary: Comptabiliser des commandes, l'écran de commande, le backfill, les remboursements, le webhook, Twig et la console.
---

## Le chemin normal

Une commande est payée. Commerce déclenche son événement, Bird place une tâche dans la file
d'attente, et la tâche construit une charge utile et crée un document dans Moneybird. Une ligne va
dans la table des documents de Bird, un paiement est enregistré sur la facture, et le panneau
Moneybird de l'écran de commande se remplit.

Une fois le plugin configuré, vous n'avez rien à faire pour que cela se produise.

## L'écran de commande

Chaque commande Commerce reçoit un panneau **Moneybird** :

- ce qui a été comptabilisé, avec un lien vers Moneybird
- le **traitement TVA** et le numéro de TVA que Bird a lu sur l'adresse
- **Envoyer à Moneybird** — comptabiliser maintenant, quoi que dise le déclencheur
- **Créditer les remboursements** *(Pro)* — créditer les transactions de remboursement qui ne le
  sont pas encore
- **Aperçu de la facture** — le JSON exact, sans l'envoyer

L'aperçu exécute la même `Invoices::buildPayload()` que l'envoi. Il existe un seul endroit dans le
code où une commande devient du JSON Moneybird, et l'aperçu est cet endroit : ce qui vous est montré
avant la comptabilisation est donc octet pour octet ce qui sera comptabilisé, identifiants de taux
de TVA compris, et c'est précisément là que se logent les erreurs.

## Comment le moteur TVA décide

| Situation | Traitement | Taux utilisé |
|---|---|---|
| Client dans votre pays d'origine | Intérieur | Le pourcentage associé |
| Entreprise de l'UE, numéro de TVA correspondant à son pays, aucune taxe facturée | Autoliquidation | Votre taux *btw verlegd* |
| Consommateur de l'UE, OSS activé *(Pro)* | OSS | Le taux de ce pays |
| Consommateur de l'UE, OSS désactivé | Taux d'origine | Le pourcentage associé |
| Hors UE | Exportation | Votre taux d'exportation |

**Commerce décide de ce qui est facturé ; Bird décide où c'est comptabilisé.** Le moteur fiscal de
Commerce connaît déjà vos zones, vos taux et vos contrôles de numéro de TVA. Le redériver ici
donnerait à votre boutique deux réponses qui finiraient par diverger — et celle que le client a
réellement payée est celle de Commerce.

Notez la troisième colonne de la ligne d'autoliquidation. L'autoliquidation est une affirmation sur
ce qui *a été facturé*, pas sur ce qui aurait pu l'être. Un numéro de TVA sur une commande qui a
tout de même payé 21 % désigne une boutique où le contrôle du numéro de TVA de Commerce n'a jamais
été activé, et la comptabiliser en autoliquidation minorerait la déclaration exactement du montant
payé par le client. Bird comptabilise ce qui s'est passé.

## Backfill

Les commandes déjà payées au moment où vous avez installé Bird ne sont pas touchées tant que vous ne
le demandez pas.

```sh
php craft bird/sync/backfill --since=2026-01-01 --dry-run
php craft bird/sync/backfill --since=2026-01-01 --limit=250
```

`--dry-run` signale chaque commande qui serait comptabilisée, et sous quel traitement, sans rien
envoyer. Exécutez-le d'abord. `--limit` vaut 100 par défaut pour qu'un backfill ne se heurte pas
tout seul à la limite de débit.

## Remboursements *(Pro)*

Une transaction de remboursement Commerce devient une note de crédit Moneybird, une par
transaction, au taux auquel la TVA a été appliquée. Les notes de crédit sont indexées sur le hash de
la transaction de remboursement : créditer deux fois le même remboursement est donc impossible —
l'index unique sur `(commande, type, source)` le refuse.

```sh
php craft bird/sync/refunds 1042
```

Ou cliquez sur **Créditer les remboursements** sur la commande.

## Le webhook *(Pro)*

Bird envoie les factures ; c'est dans Moneybird qu'elles sont *payées* — rapprochées d'un flux
bancaire, ou pointées à la main. Sans le webhook, l'idée que votre site se fait de « payé » est ce
qu'a dit la passerelle de paiement à la commande, ce qui manque chaque virement.

```sh
php craft bird/webhooks/install
php craft bird/webhooks/info
php craft bird/webhooks/remove
```

**Installer le webhook** l'enregistre et conserve le secret de signature, que Moneybird ne renvoie
qu'une seule fois, à la création. Chaque livraison est ensuite vérifiée par rapport à l'en-tête
`Moneybird-Signature` — HMAC-SHA256 sur `{timestamp}.{corps brut}`, comparé en temps constant, et
rejeté si l'horodatage s'écarte de plus de cinq minutes. Plusieurs valeurs de signature apparaissent
pendant une rotation de secret : toute correspondance compte donc.

Pas de secret, pas de signature ou un horodatage périmé : pas de requête. Cela échoue en mode fermé.

Bird s'abonne aux événements payé, en retard et irrécouvrable, pour les deux types de documents.
Réglez **Statut à la facture payée** si vous voulez qu'une facture payée fasse changer la commande
Commerce.

## Twig

```twig
{% set document = craft.bird.documentForOrder(order) %}

{% if document and document.getIsBooked() %}
    <p>Facture {{ document.getLabel() }} — {{ document.getStateLabel() }}</p>

    {# Une URL de capacité : ne l'affichez qu'au client dont c'est la commande. #}
    {% if document.publicUrl %}
        <a href="{{ document.publicUrl }}">Voir votre facture</a>
    {% endif %}
{% endif %}

{% set vat = craft.bird.vatTreatment(order) %}
{% if vat.reverseCharge %}
    <p>TVA autoliquidée — {{ vat.vatNumber }}</p>
{% endif %}
```

`craft.bird.documentsForOrder(order)` renvoie la facture et chaque note de crédit.
`craft.bird.invoiceUrl(order)` est un raccourci vers l'URL publique, et
`craft.bird.isConfigured()` existe pour qu'un template puisse rester silencieux sur une installation
qui n'est pas encore configurée.

## Console

```sh
craft bird/sync/status                    # connexion, édition, nombre de documents
craft bird/sync/order 1042                # comptabiliser une commande, par référence, numéro ou id
craft bird/sync/order 1042 --force        # la comptabiliser à nouveau, même si elle l'est déjà
craft bird/sync/backfill --since=2026-01-01 --dry-run --limit=100
craft bird/sync/retry --limit=100         # les envois échoués auxquels il reste des tentatives
craft bird/sync/refunds 1042              # créditer les remboursements d'une commande

craft bird/inspect/preview 1042           # le JSON exact, sans l'envoyer
craft bird/inspect/tax-rates              # les identifiants pour l'association des taux
craft bird/inspect/ledger-accounts
craft bird/inspect/financial-accounts
craft bird/inspect/workflows
craft bird/inspect/administrations

craft bird/webhooks/install
craft bird/webhooks/list
craft bird/webhooks/info
craft bird/webhooks/remove

craft bird/log/prune --days=30            # mérite une tâche cron
craft bird/log/clear
```

## Il ne peut pas bloquer une commande

Tout ce qui s'exécute pendant la commande échoue en mode **ouvert**. Chaque déclencheur avale ses
propres erreurs et l'envoi passe par la file d'attente par défaut : une panne chez Moneybird, un
jeton expiré ou une limite de débit ne peuvent donc jamais être la raison pour laquelle un client
n'a pas pu payer. Ce que vous obtenez à la place, c'est une ligne de document à l'état `failed`, un
motif dessus, et `bird/sync/retry`.

La seule chose qui échoue en mode **fermé** est un taux de TVA non associé : Bird refuse la commande
et nomme le pourcentage manquant. Ce refus se produit du côté de Bird, pas du côté de la commande.

## Comptabiliser deux fois le même chiffre d'affaires

Impossible. `{{%bird_documents}}` est unique sur `(orderId, kind, sourceKey)` — `sourceKey` est vide
pour la facture et vaut le hash de la transaction de remboursement pour une note de crédit — et cet
index *est* la garantie. Une tâche relancée, un bouton cliqué deux fois et un webhook qui double la
file d'attente butent tous dessus.

Si un envoi meurt dans l'intervalle entre « Moneybird a créé la facture » et « Bird a écrit la
ligne », la tentative suivante cherche la facture avec
`GET /sales_invoices/find_by_reference/{reference}.json` et la reprend, au lieu d'en comptabiliser
une seconde.

---

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
