# Webhooks : exemples

Les webhooks appellent un service externe aux événements choisis : nouvelle observation, publication ou refus d'une
observation, nouvelle résolution, changement d'état d'une résolution. Leur fonctionnement (événements, variables,
échappement, envoi) est décrit dans [FONCTIONNEMENT.md](FONCTIONNEMENT.md#webhooks). Cette page donne des réglages
prêts à l'emploi pour la page **Webhooks** de l'admin. Ils sont aussi proposés dans le menu **Modèle** du formulaire,
qui préremplit les champs : il reste à remplacer les valeurs en `MAJUSCULES`, puis à cliquer sur **Enregistrer et
tester**.

Rappels :

- dans un corps **JSON**, les variables de texte s'écrivent entre guillemets (`"{{comment}}"`) : leur valeur est
  échappée automatiquement ; `{{lat}}` et `{{lon}}` peuvent s'écrire sans guillemets (nombres) ;
- dans un corps **formulaire**, les valeurs sont encodées automatiquement ;
- la **Correspondance des catégories** du formulaire associe à chaque catégorie un code de l'outil appelé
  (`{{categorie_code}}`) ; avec « N'envoyer que les observations des catégories qui ont un code », le webhook ne part
  que pour ces catégories (exemple : [Open311](#ticketing-de-collectivité-open311)) ;
- un webhook fait **une seule requête** : un service qui demande d'abord d'ouvrir une session (Bluesky, GLPI…) passe
  par un relais (voir [Bluesky](#bluesky)) ;
- les jetons et mots de passe sont enregistrés en clair dans la base : utiliser des jetons dédiés, limités au strict
  nécessaire (publication seule), et les révoquer s'ils fuitent.

Les exemples ci-dessous sont prévus pour l'événement « Observation publiée ». Pour prévenir les modérateurs d'une
observation à modérer, cocher « Nouvelle observation » sur un webhook Slack, Mattermost ou Discord (voir
[l'exemple](#modérateurs-observation-à-modérer)) ; pour suivre les résolutions, cocher les événements de résolution et
utiliser les variables `resolution_*`.

Sommaire : [Mastodon](#mastodon) · [Slack, Mattermost, Discord](#slack-mattermost-discord) · [Bluesky](#bluesky) ·
[Ticketing de collectivité (Open311)](#ticketing-de-collectivité-open311) · [Redmine](#redmine) ·
[Modérateurs : observation à modérer](#modérateurs-observation-à-modérer) · [Suivi des résolutions](#suivi-des-résolutions)

## Mastodon

Publier un message (pouet) avec le lien vers l'observation.

Jeton : sur le compte Mastodon de l'association, **Préférences > Développement > Nouvelle application**, avec le seul
droit `write:statuses`, puis copier « Votre jeton d'accès ».

| Champ | Valeur |
|---|---|
| Méthode | `POST` |
| URL | `https://MASTODON.EXEMPLE/api/v1/statuses` |
| Format | JSON |

En-têtes :

```
Authorization: Bearer JETON_D_ACCES
Idempotency-Key: vigilo-{{token}}
```

Corps :

```json
{
  "status": "📍 Nouvelle observation à {{cityname}} : {{categorie_name}}\n« {{comment}} »\n{{address}}\n\n{{observation_url}}\n\n#Vigilo #vélo",
  "visibility": "public",
  "language": "fr"
}
```

- `Idempotency-Key` évite un doublon si la même observation est envoyée deux fois.
- `"visibility": "unlisted"` publie sans apparaître dans les fils publics.
- La photo n'est pas jointe (Mastodon demande un envoi préalable de l'image) : le lien de l'observation l'affiche, et
  `{{photo_url}}` peut être ajouté au texte.

## Slack, Mattermost, Discord

Poster dans un canal de l'association, avec la photo.

Slack : créer une application avec **Incoming Webhooks** (api.slack.com/apps), l'activer pour le canal et copier
l'URL `https://hooks.slack.com/services/…`. Mattermost : **Intégrations > Webhooks entrants** ; le même corps
fonctionne (format compatible Slack).

| Champ | Valeur |
|---|---|
| Méthode | `POST` |
| URL | `https://hooks.slack.com/services/T000/B000/XXXX` |
| Format | JSON |
| En-têtes | (aucun) |

Corps :

```json
{
  "text": "Nouvelle observation à {{cityname}} : {{categorie_name}}",
  "blocks": [
    {"type": "section", "text": {"type": "mrkdwn",
      "text": "*{{categorie_name}}* à {{cityname}}\n{{comment}}\n_{{address}}_\n<{{observation_url}}|Voir l'observation {{token}}>"}},
    {"type": "image", "image_url": "{{photo_url}}", "alt_text": "Photo de l'observation {{token}}"}
  ]
}
```

- `text` sert aux notifications ; `blocks` à l'affichage dans le canal.
- La photo est récupérée par Slack depuis l'instance : l'instance doit être accessible publiquement en HTTPS.
- Slack interprète `<`, `>` et `&` dans le texte : un commentaire qui en contient peut s'afficher tronqué.

Discord : **Paramètres du salon > Intégrations > Webhooks**, copier l'URL, et le corps JSON :

```json
{"content": "📍 {{categorie_name}} à {{cityname}} : {{comment}}\n{{observation_url}}"}
```

## Bluesky

Bluesky n'accepte pas de jeton permanent : chaque publication demande d'ouvrir une session puis de créer le message.
Le webhook passe donc par un **relais** qui fait ces deux appels :

- le script [`webhooks/bluesky_relay.py`](webhooks/bluesky_relay.py) (Python 3, sans dépendance), à lancer sur le
  serveur de l'instance ou un autre serveur ;
- ou un outil d'automatisation (n8n, Make, Zapier…) : webhook entrant puis action Bluesky.

Compte : sur Bluesky, **Paramètres > Confidentialité et sécurité > Mots de passe d'application**, créer un mot de passe
dédié (jamais le mot de passe principal).

Lancer le relais :

```sh
BSKY_HANDLE=vigilo-maville.bsky.social BSKY_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx \
RELAY_SECRET=UNE_LONGUE_CHAINE_ALEATOIRE RELAY_PORT=8080 python3 doc/webhooks/bluesky_relay.py
```

Webhook Vigilo :

| Champ | Valeur |
|---|---|
| Méthode | `POST` |
| URL | `http://ADRESSE_DU_RELAIS:8080/` (HTTPS si le relais est sur un autre serveur) |
| Format | JSON |

En-têtes :

```
X-Relay-Secret: UNE_LONGUE_CHAINE_ALEATOIRE
```

Corps :

```json
{
  "text": "📍 {{categorie_name}} à {{cityname}} : « {{comment}} » #Vigilo",
  "url": "{{observation_url}}",
  "title": "Observation {{token}} – {{categorie_name}}",
  "description": "{{address}}"
}
```

Le message (300 caractères au plus) est publié avec une carte de lien vers l'observation. En cas d'erreur Bluesky, le
relais répond 502 avec le message d'erreur, visible dans le journal des webhooks.

## Ticketing de collectivité (Open311)

[Open311 GeoReport v2](https://wiki.open311.org/GeoReport_v2/) est le standard ouvert des outils de signalement et de
ticketing des collectivités (voirie, propreté…). Si la collectivité en expose un, chaque observation publiée peut y
créer une demande d'intervention, avec sa position et sa photo.

À demander à la collectivité : l'adresse de l'API (`ENDPOINT`), une clé d'API (`api_key`) et le code du service
destinataire de chaque catégorie (`service_code`, liste sur `ENDPOINT/services.json`), à saisir dans la
**Correspondance des catégories** du webhook.

| Champ | Valeur |
|---|---|
| Méthode | `POST` |
| URL | `https://ENDPOINT/requests.json` |
| Format | Formulaire |
| En-têtes | (aucun) |

Corps (une seule ligne) :

```
api_key=CLE_API&service_code={{categorie_code}}&lat={{lat}}&long={{lon}}&address_string={{address}}&description=Vigilo {{token}} - {{categorie_name}} : {{comment}} {{explanation}}&media_url={{photo_url}}
```

- Les valeurs des variables sont encodées automatiquement ; les textes fixes du modèle doivent rester simples (lettres,
  chiffres, espaces).
- La réponse contient le numéro de la demande (`service_request_id`), visible dans le journal des webhooks.
- `{{categorie_code}}` prend le code saisi pour la catégorie de l'observation. Pour n'envoyer que certaines catégories
  (par exemple le stationnement gênant), ne renseigner que celles-ci et cocher « N'envoyer que les observations des
  catégories qui ont un code ».

## Redmine

Créer un ticket dans un projet Redmine (outil de suivi utilisé par certaines collectivités et associations).

Clé : **Mon compte > Clé d'accès API** d'un compte ayant le droit de créer des demandes dans le projet (API REST
activée dans **Administration > Paramètres > API**).

| Champ | Valeur |
|---|---|
| Méthode | `POST` |
| URL | `https://REDMINE.EXEMPLE/issues.json` |
| Format | JSON |

En-têtes :

```
X-Redmine-API-Key: CLE_API
```

Corps :

```json
{
  "issue": {
    "project_id": "IDENTIFIANT_DU_PROJET",
    "subject": "Vigilo {{token}} : {{categorie_name}} - {{address}}",
    "description": "{{comment}}\n\n{{explanation}}\n\nAdresse : {{address}}, {{cityname}}\nPosition : {{lat}}, {{lon}}\nDate : {{date}}\n\nObservation : {{observation_url}}\nPhoto : {{photo_url}}"
  }
}
```

GLPI demande d'ouvrir une session avant de créer un ticket (`initSession`) : comme pour Bluesky, passer par un relais
ou un outil d'automatisation.

## Modérateurs : observation à modérer

Prévenir les modérateurs dans leur canal (Slack, Mattermost, Discord) dès qu'une observation arrive. Événement :
**Nouvelle observation** (`observation.created`). La photo n'est pas encore modérée : `{{photo_url}}` donne la version
pixelisée ; le lien vers l'admin permet de la voir.

| Champ | Valeur |
|---|---|
| Événements | Nouvelle observation |
| Méthode | `POST` |
| URL | URL du webhook entrant du canal des modérateurs |
| Format | JSON |

Corps (Slack / Mattermost ; pour Discord, remplacer `text` par `content`) :

```json
{"text": "🕵️ Observation {{token}} à modérer : {{categorie_name}} à {{cityname}}\n« {{comment}} »\n{{instance_url}}/admin/index.php?page=observations&approved=0"}
```

## Suivi des résolutions

Informer un canal ou mettre à jour un ticket quand une résolution est déclarée ou change d'état. Événements :
**Nouvelle résolution** et **Changement d'état d'une résolution**.

```json
{
  "event": "{{event}}",
  "resolution": "{{resolution_token}}",
  "status": {{resolution_status}},
  "status_name": "{{resolution_status_name}}",
  "previous_status": "{{resolution_previous_status}}",
  "comment": "{{resolution_comment}}",
  "photo": "{{resolution_photo_url}}",
  "observations": "{{resolution_observations}}",
  "address": "{{address}}",
  "city": "{{cityname}}"
}
```

Les variables de l'observation (`{{token}}`, `{{address}}`, `{{categorie_code}}`…) sont celles de la première
observation liée ; `{{resolution_observations}}` donne la liste de toutes. Une résolution déclarée dans l'application
est envoyée à sa création, avant sa photo (`{{resolution_photo_url}}` vide).
