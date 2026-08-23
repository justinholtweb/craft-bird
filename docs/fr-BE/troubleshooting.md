---
title: Dépannage
slug: troubleshooting
order: 40
summary: Ce que signifient les pannes courantes et ce qu'il faut faire.
---

Commencez par `php craft bird/sync/status`. Il indique la connexion, l'édition, si le plugin se
considère comme configuré, et le nombre de documents par état — en général assez pour distinguer un
problème de configuration d'un problème Moneybird.

En Pro, **Bird → Journal** contient la requête et la réponse de chaque appel, jetons et secrets
caviardés.

## « Aucun taux de TVA associé pour 21 % »

Bird a refusé la commande plutôt que de deviner. Allez dans **Réglages → Plugins → Bird → TVA**,
cliquez sur **Proposer une association**, et assurez-vous que le pourcentage indiqué dans le message
a bien une ligne.

C'est la seule panne qui soit délibérée. Une facture comptabilisée sous le mauvais taux de TVA est
pire qu'une facture non comptabilisée : une facture manquante finit par se remarquer, une facture
fausse non.

## Rien n'est envoyé

Par ordre de probabilité :

1. **Pas configuré.** Pas de jeton, pas d'identifiant d'administration, ou pas d'association de
   taux. `bird/sync/status` le dit sans détour.
2. **La file d'attente ne tourne pas.** L'envoi est une tâche. Si la file de Craft n'est pas
   exécutée par un démon ou une tâche cron, rien ne part tant que personne n'ouvre le panneau de
   configuration. Vérifiez **Utilitaires → Gestionnaire de file d'attente**.
3. **Le déclencheur ne correspond pas.** *La commande est payée* réagit à l'événement paid de
   Commerce. Si votre passerelle clôture la commande sans la marquer payée, utilisez *clôturée* ou
   un statut.
4. **Les commandes à 0 sont ignorées**, par défaut et à dessein.

## Une commande est à l'état `failed`

Ouvrez-la. Le panneau affiche la dernière erreur. Ensuite :

```sh
php craft bird/sync/retry
```

Retry reprend les envois échoués auxquels il reste des tentatives — **Nouvelles tentatives**, 5 par
défaut. Une fois qu'un document n'a plus de tentatives, retry l'ignore et vous devez cliquer sur
**Envoyer à Moneybird** sur la commande, ce qui remet le compteur à zéro.

## 422 de Moneybird à la création

Presque toujours une charge utile que le schéma de Moneybird n'accepte pas. Les deux points de
terminaison de création déclarent `unevaluatedProperties: false` : une seule clé inattendue rejette
donc la facture entière.

Le classique est `amount_decimal`. Le texte de référence de Moneybird le mentionne, mais c'est un
champ de *réponse* — l'envoyer produit un 422. Bird dispose d'un contrôle pour exactement ce cas.
Exécutez `php craft bird/inspect/preview 1042` et comparez.

L'autre consiste à envoyer les champs d'une facture de vente au point de terminaison externe, ou
l'inverse. Une facture de vente prend `invoice_date` et `first_due_interval` ; une facture de vente
externe prend `date`, `due_date`, `source` et `source_url`. Elles ne sont pas interchangeables.

## 429, ou des envois qui traînent

Moneybird autorise 150 requêtes par tranche de 5 minutes, et 50 pour `/reports/`. Bird lit
`Retry-After` et attend. Un backfill est la façon habituelle de trouver le plafond — c'est pour cela
que `--limit` vaut 100 par défaut. Baissez-le et exécutez-le plus souvent.

## Le total de la facture s'écarte d'un centime du montant payé

Il ne devrait pas, et avec **Rapprocher les totaux** activé il ne le fait pas : l'écart va sur une
ligne d'arrondi à 0 % pour que le total corresponde au flux bancaire.

Si vous avez désactivé le rapprochement, Bird refuse ces commandes à la place, avec l'écart dans
l'erreur. C'est le réglage qui fait ce qu'il annonce.

## Une commande avec numéro de TVA a été comptabilisée à 21 %

C'est correct, et c'est la surprise la plus fréquente du plugin.

L'autoliquidation est une affirmation sur ce qui *a été facturé*. Si la commande a payé 21 %, ce
n'était pas de l'autoliquidation, quoi qu'en dise l'adresse — et la comptabiliser ainsi minorerait
votre déclaration exactement du montant payé par le client. Ce que vous regardez, c'est une boutique
dont le contrôle du numéro de TVA de Commerce est désactivé. Activez-le dans les réglages fiscaux de
Commerce et les commandes suivantes passeront à 0 % dès la commande, après quoi Bird les
comptabilisera en autoliquidation.

Bird ne corrige pas rétroactivement les commandes qui ont déjà facturé de la taxe. Il comptabilise
ce qui s'est passé.

## Les contacts se dédoublent

Vérifiez **Apparier les clients par**. Sur *Utilisateur Craft* — la valeur par défaut — Bird indexe
le contact Moneybird sur l'identifiant d'utilisateur Craft, que Commerce garantit pour chaque
adresse de courriel de commande. Sur *Numéro de commande*, un nouveau contact par commande est le
comportement documenté, pas un bug.

Si des contacts existaient dans Moneybird avant Bird, ils n'ont pas de `customer_id` : la première
commande de chacun en créera donc un second. La solution est de renseigner `customer_id` sur les
contacts Moneybird existants pour qu'il corresponde.

## Les livraisons du webhook sont rejetées *(Pro)*

La vérification échoue en mode fermé, et il n'y a que quelques façons d'entrer :

- **Aucun secret de signature enregistré.** Moneybird ne le renvoie qu'une seule fois, à la
  création. Si le secret est perdu, supprimez le webhook et réinstallez-le —
  `bird/webhooks/remove` puis `bird/webhooks/install`.
- **Horodatage périmé.** Les livraisons de plus de cinq minutes sont rejetées. Vérifiez l'horloge du
  serveur.
- **Le corps a été modifié en transit.** La signature porte sur les octets *bruts*. Un proxy qui
  resérialise le JSON la casse.
- **Accepter les webhooks est désactivé.** Installer le webhook ne l'active pas tout seul.

`php craft bird/webhooks/info` montre ce qui est enregistré et si un secret est conservé.

## Un envoi est mort en route et je crois qu'il a comptabilisé deux fois

Ce n'est pas le cas. La récupération par la référence cherche la facture avec `find_by_reference`
avant de créer quoi que ce soit, et l'index unique sur `(orderId, kind, sourceKey)` se trouve
derrière. Si vous voyez deux factures dans Moneybird, l'une a été créée à la main ou par autre chose
que Bird.

## Obtenir de l'aide

`justin@justinholt.com`, avec la sortie de `bird/sync/status` et — en Pro — l'entrée de journal
correspondante.

---

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
