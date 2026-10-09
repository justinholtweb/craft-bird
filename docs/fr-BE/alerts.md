---
title: Alertes
slug: alerts
order: 35
summary: Un courriel (et, si vous le souhaitez, un message Slack ou Teams) quand la comptabilisation dans Moneybird rencontre un problème et un autre quand tout est rentré dans l'ordre, plus un widget de tableau de bord et une bannière dans le panneau de configuration.
---

L'écran Documents sait tout ce qui a mal tourné. Mais personne ne l'ouvre un jour où la boutique
semble fonctionner, et une commande qui n'est jamais arrivée chez Moneybird, c'est du chiffre
d'affaires que vous découvrez manquant en préparant la déclaration de TVA. Bird vous prévient donc
quand l'une de ces trois choses se produit :

| Incident | S'ouvre quand | Se ferme quand |
|---|---|---|
| **Des commandes n’ont pas pu être comptabilisées dans Moneybird** | `alertDocumentThreshold` factures ou notes de crédit (1 par défaut) sont **en échec** : Moneybird les a refusées, ou Bird n'a pas voulu les envoyer (un taux de TVA non associé, aucun contact à facturer) | toutes sont comptabilisées, ou oubliées sur l'écran Documents |
| **Moneybird refuse le jeton d’API** | Moneybird répond 401 ou 403 : le jeton a été révoqué, a expiré ou n'a pas la portée nécessaire | la prochaine requête que Moneybird accepte |
| **La comptabilisation dans Moneybird est bloquée** | une commande terminée que Bird aurait dû comptabiliser ne l'est toujours pas après `alertStallHours` (6 par défaut), ou un envoi est resté *En attente* aussi longtemps | les commandes sont comptabilisées, par la file d'attente, un bouton ou `bird/sync/backfill` |

Vous recevez **un message quand un incident commence et un quand il se termine**, jamais un par
commande. Chaque incident a une seule ligne de verrou en base de données : le vérifier cent fois
tant qu'il est ouvert n'envoie rien de nouveau. D'autres échecs qui surviennent pendant qu'il est
ouvert apparaissent dans le widget du tableau de bord et dans la bannière du panneau de
configuration, pas dans votre boîte de réception, et l'incident ne se ferme qu'une fois le dernier
d'entre eux traité. Un incident qui ne cesse d'osciller est retenu par `alertCooldownMinutes` (60
par défaut) : s'il se rouvre dans ce délai après son message de rétablissement, vous en êtes informé
à la fin de la période de calme, et seulement s'il est toujours là.

Un timeout, un 429 ou un 500 n'est pas un échec d'authentification, et un 422 non plus — c'est
Moneybird qui refuse une facture, et donc le premier incident.

Une commande bloquée est une commande que Bird aurait comptabilisée de lui-même, selon le
déclencheur que vous avez choisi. Avec **Jamais — seulement quand je le dis**, rien ne peut se
bloquer. Avec **La commande est payée**, l'horloge démarre au paiement ; avec **La commande est
clôturée**, au moment où elle a été passée ; avec **La commande atteint un statut**, à sa dernière
modification alors qu'elle était dans ce statut. Une commande à 0 n'est pas comptée quand **Ignorer
les commandes à 0** est activé. Les commandes de plus d'une semaine, ou passées avant l'installation
de Bird, sont laissées à `bird/sync/backfill`. Une commande avec un document en échec relève du
premier incident, pas de celui-ci.

## Mise en place

**Réglages → Plugins → Bird → Alertes.**

| Réglage | Défaut | |
|---|---|---|
| `alertRecipients` | vide | Adresses séparées par des virgules, ou une référence `$ENV`. Vide signifie aucun courriel |
| `alertWebhookUrl` | vide | Une URL de webhook entrant Slack ou Teams, ou une référence `$ENV`. Gardez-la dans une variable d'environnement : l'URL est elle-même l'identifiant |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, ou `json` pour votre propre récepteur |
| `alertWebhookSecret` | vide | S'il est défini, chaque webhook d'alerte porte `X-Bird-Timestamp` et `X-Bird-Signature: sha256=<hmac>` calculé sur `timestamp.body` |
| `alertOnDocuments` | activé | |
| `alertDocumentThreshold` | 1 | |
| `alertOnAuthFailure` | activé | |
| `alertStallHours` | 6 | 0 désactive les alertes de blocage |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | désactivé | Fichier de configuration uniquement — voir plus bas |

Tout cela fait partie de Bird Lite. Le courriel passe par le mailer de Craft lui-même, il utilise
donc ce qui est configuré dans **Réglages → Courriel**. Cliquez sur **Envoyer une alerte de test**
sur l'écran de réglages (ou exécutez `php craft bird/alerts/test`) après avoir enregistré, pour
vérifier que les deux canaux arrivent bien.

Rien de tout cela n'est obligatoire. Un site sans destinataires et sans webhook enregistre quand même
les incidents et les affiche dans le widget du tableau de bord et dans la bannière du panneau de
configuration. Si vous ajoutez un destinataire plus tard, tout incident encore ouvert est envoyé à
la vérification suivante. Un site sans jeton d'API ni administration n'est pas vérifié du tout.

## Quand les alertes sont vérifiées

- **Chaque fois qu'un document change** : en échec, comptabilisé, payé, oublié. Quelle qu'en soit
  la cause — la file d'attente, un bouton, la console, le webhook —, les comptabilisations en échec
  n'ont donc besoin d'aucune tâche cron.
- **Dès que Moneybird refuse le jeton** : l'authentification.
- **`php craft bird/alerts/check`**, et à la fin de `bird/sync/retry` et de `bird/sync/backfill` :
  tout.

Un blocage est une absence d'activité : seul le dernier de ces points peut donc le remarquer. Mettez
soit la vérification, soit le retry dans une tâche cron :

```sh
*/15 * * * * cd /path/to/site && php craft bird/alerts/check >> /dev/null 2>&1
```

## Ce que dit une alerte

Un courriel en texte brut : le site, l'incident, ce qui a été constaté, ce qu'il faut faire, et un
lien direct vers le bon écran — les réglages de Bird pour un jeton refusé, l'écran Documents filtré
sur les échecs pour des comptabilisations en échec, et l'écran Documents pour un blocage. Le message
Slack, la carte Teams et l'événement JSON contiennent la même chose.

Ce qui a été constaté est caviardé avant de quitter le site : le jeton d'API, le secret de signature
du webhook Moneybird et le secret du webhook d'alerte sont retirés par valeur, tout ce qui ressemble
à un identifiant (`Bearer …`, `password=…`, `"api_key": …`) par motif, le balisage est supprimé et
la ligne est limitée à 500 caractères. Les alertes désignent les commandes par leur référence et
n'incluent jamais d'adresse, de ligne de commande ni de charge utile.

## Le webhook

Les webhooks entrants de Slack et de Teams fonctionnent tels quels. Le format `json` envoie :

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

`event` vaut `bird.alert.recovered` quand l'incident se termine ; `incident` vaut `documents`,
`auth` ou `stalled`.

Ce n'est pas le même webhook que celui que Moneybird envoie à Bird quand une facture est payée.
L'URL est vérifiée à chaque utilisation, pas seulement à l'enregistrement. Elle doit être en `http`
ou `https`, sans nom d'utilisateur ni mot de passe, chaque adresse vers laquelle l'hôte se résout
doit être publique — ni privée, ni loopback, ni link-local (le service de métadonnées du cloud), ni
NAT de niveau opérateur — et la requête est épinglée sur ces adresses pour que le DNS ne puisse pas
être modifié entre la vérification et l'envoi. Les redirections ne sont jamais suivies. Pour un
Mattermost auto-hébergé sur votre propre réseau, définissez `allowPrivateAlertWebhookHosts` dans
`config/bird.php`. Les règles sur le schéma et les redirections s'appliquent toujours.

Si tous les canaux échouent, l'alerte n'est pas marquée comme envoyée, et la vérification suivante
réessaie.

## Le widget du tableau de bord et la bannière

**Tableau de bord → Nouveau widget → Comptabilisations Moneybird** indique combien de documents
sont en échec, en attente, en brouillon, ouverts, en retard, payés et comptabilisés — chaque nombre
renvoyant vers l'écran Documents filtré sur cet état —, tout incident ouvert avec ce qui a été
constaté, et quand un document a été comptabilisé dans Moneybird ou relu depuis Moneybird pour la
dernière fois. Il lit les mêmes lignes de verrou que celles d'où viennent les alertes : le widget et
votre boîte de réception ne peuvent donc pas se contredire.

Tant qu'un incident est ouvert, une bannière en haut du panneau de configuration le signale, avec
un lien vers l'écran Documents. Les deux ne sont affichés qu'aux personnes disposant de *Voir les
documents Moneybird*.

## Modifier ou intercepter une alerte

```php
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail, $e->subject, $e->body, $e->payload
    $e->subject = '[Boekhouding] ' . $e->subject;

    // L'avaler. Le verrou la compte quand même comme envoyée.
    if ($e->incident === Alerts::INCIDENT_STALLED && !$e->recovered) {
        $e->isValid = false;
    }
});
```

---

*Bird est un plugin indépendant. Il n'est ni affilié à Moneybird, ni approuvé, ni sponsorisé par
Moneybird. « Moneybird » est une marque de son titulaire respectif.*
