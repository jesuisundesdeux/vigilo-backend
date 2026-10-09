# Fonctionnement

Un citoyen signale un problème (vélo sur une piste cyclable, trottoir encombré…) depuis l'application :
une **observation** avec une photo, une position, une adresse, une **catégorie** et un commentaire.

1. **Création** : `create_issue.php` enregistre l'observation et renvoie un `token` (identifiant public)
   et un `secretid` (secret de l'auteur, pour ajouter la photo ou supprimer l'observation).
2. **Photo** : `add_image.php` reçoit la photo, la valide, la redimensionne (1024 px max) et, si un
   serveur de floutage est configuré, la fait flouter (visages, plaques). L'observation est alors complète.
3. **Modération** : un modérateur approuve (`approved = 1`) ou refuse (`approved = 2`) l'observation
   depuis l'admin ou l'API. Tant qu'elle n'est pas approuvée, elle n'est pas listée (sauf réglage
   « Afficher les observations non modérées ») et sa photo n'est visible que pixelisée.
4. **Suivi** : l'observation passe par des **statuts** (0 nouvelle, 2 prise en compte, 3 en cours de
   résolution, 4 indiquée comme résolue, 1 résolue). Les citoyens déclarent une **résolution**
   (`create_resolution.php`, avec photo) que l'admin valide ; les comptes **citystaff** (services d'une
   ville) mettent à jour les observations de leurs villes.

Une instance peut couvrir plusieurs zones géographiques : les **scopes** (une agglomération, un département…),
chacun avec ses limites (rectangle hors duquel les observations sont refusées), son centre de carte et son contact.
Les catégories nationales sont communes à toutes les instances ([vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf)) ;
chaque instance peut en désactiver et ajouter les siennes (voir [Catégories](#catégories)).

## Rôles

| Rôle | Accès |
|---|---|
| `admin` | Tout : observations, résolutions, villes, comptes, scopes, catégories, réglages, webhooks, journal, mises à jour. Clé API pour l'approbation et la modification par l'API. |
| `moderator` | Modération (approbation, modification, suppression) depuis l'application web (mode admin) et par l'API, avec sa clé ; pas d'accès au panneau d'administration. |
| `citystaff` | Panneau d'administration limité à l'accueil, aux observations et aux résolutions des villes associées au compte (statuts « prise en compte », « en cours »). |
| `guest` | Compte sans droit (en attente d'attribution). |

Chaque compte a une **clé API**, générée à sa création et régénérable dans l'admin (page **Comptes**), passée en
paramètre `key` aux appels qui le demandent : c'est la clé que le modérateur saisit dans l'application web. Un compte
sans login ne sert qu'avec sa clé.
Panneau d'administration et ajout de modérateurs : [guide d'administration](https://vigilo.city/fr/documentation/administration/) sur vigilo.city.

## Catégories

Les catégories nationales sont communes à toutes les instances ([vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf)).
Dans l'admin (page **Catégories**), chaque instance peut en désactiver (elles ne sont plus proposées, les observations
existantes gardent leur catégorie) et ajouter les siennes (numéros à partir de 1000, nom, nom anglais, couleur,
résolvable, active) ; une catégorie de l'instance utilisée par des observations ne peut pas être supprimée, seulement
désactivée. La liste de l'instance est publiée sur `get_categories.php` (format de `categorielist.json`), que
l'application web utilise ; les applications qui ne la lisent pas utilisent la liste nationale.

## Photos, pixelisation et floutage

- Les photos sont dans `images/<token>.jpg` et `images/resolutions/<token>.jpg`, jamais servies directement
  (`.htaccess`) : elles passent par `get_photo.php`, `generate_panel.php` ou `admin/photo.php`.
- Une photo non approuvée n'est servie au public que **pixelisée** ; le cache (`caches/`) ne contient que des
  images pixelisées. Une photo approuvée ne peut plus être remplacée par son auteur.
- Une observation sans photo répond 404 (`PHOTONOTFOUND`) sur `get_photo.php` et `generate_panel.php` ; les
  applications et l'admin affichent alors une image par défaut. `generate_panel.php` ne compose plus de panneau
  depuis la 0.0.22 : il renvoie la photo, comme `get_photo.php`.
- **Serveur de floutage** (optionnel, [`blur-server/`](../blur-server/README.md)) : s'il est configuré, chaque
  photo reçue lui est envoyée et remplacée par sa version où visages et plaques d'immatriculation sont
  masqués (modèle de détection de Panoramax, sur CPU). S'il échoue, la photo est gardée telle qu'envoyée (journalisé, en-tête `X-Vigilo-Blur: failed`) :
  la modération manuelle reste le garde-fou. Tout serveur compatible (champ multipart `picture`), comme
  [SGBlur](https://github.com/cquest/sgblur), convient.

Voir aussi le [glossaire](GLOSSAIRE.md) et l'[API REST](REST_API.md).

## Webhooks

Le backend appelle les webhooks actifs définis dans l'admin (page **Webhooks**, réservée aux administrateurs) à chaque
**événement** auquel ils sont abonnés (un webhook peut en cocher plusieurs ; `{{event}}` indique lequel) :

| Événement | Quand | Variables |
|---|---|---|
| `observation.created` | Nouvelle observation, à la réception de sa photo (`add_image.php`), avant modération : par exemple pour prévenir les modérateurs | observation (`approved` = 0 ; `photo_url` pixelisée) |
| `observation.approved` | Publication (passage à `approved = 1`, admin ou `approve.php`) | observation |
| `observation.disapproved` | Refus (passage à `approved = 2`, admin ou `approve.php`) | observation |
| `resolution.created` | Résolution déclarée dans l'application (`create_resolution.php`, état 4 ; la photo arrive ensuite) ou créée dans l'admin (état 2) | résolution + sa première observation |
| `resolution.status_changed` | Changement d'état d'une résolution dans l'admin (unitaire, groupé ou formulaire) | résolution + sa première observation, `resolution_previous_status` |

Chaque événement n'est envoyé qu'au changement d'état (pas de nouvel appel si l'observation est déjà publiée ou
refusée, ni pour une nouvelle photo de la même observation). Pour une résolution, `resolution_token`,
`resolution_status` (et `resolution_status_name`), `resolution_comment`, `resolution_date`, `resolution_photo_url` et
`resolution_observations` (identifiants des observations liées) s'ajoutent aux variables de l'observation, qui sont
celles de la première observation liée ; elles sont vides pour les événements d'observation.

- **appel** : méthode (POST, PUT, PATCH, GET), URL, en-têtes (`Nom: valeur`, un par ligne) et corps ;
- **variables** `{{nom}}` utilisables partout, remplacées par les champs de l'observation : `event`, `token`,
  `observation_url` (lien vers l'application web), `photo_url` (`generate_panel.php`, public, pixelisée tant que
  l'observation n'est pas approuvée), `comment`, `explanation`, `categorie`,
  `categorie_name`, `categorie_code` (code de la catégorie dans l'outil appelé, défini dans la **correspondance des
  catégories** du webhook), `address`, `cityname`, `scope`, `lat`, `lon`, `time` (timestamp), `date` (ISO 8601), `status`,
  `approved`, `instance_name`, `instance_url`, et les variables `resolution_*` ci-dessus ;
- **échappement** selon l'emplacement : encodées dans l'URL et un corps « formulaire », échappées JSON dans un corps
  JSON (écrire `"{{comment}}"` entre guillemets ; le corps est vérifié à l'enregistrement), sans saut de ligne dans
  les en-têtes, telles quelles dans un corps texte ;
- **envoi** : en parallèle, après la réponse à l'application (dans l'admin, les échecs sont affichés), avec un délai maximal de 5 secondes (`VIGILO_WEBHOOK_TIMEOUT`) ; pas de nouvel essai
  automatique. Les 500 derniers envois (code HTTP, erreur, durée, début de la réponse) sont visibles dans l'admin ;
  « Enregistrer et tester » envoie la requête du premier événement coché avec la dernière observation publiée (ou la
  dernière résolution) ;
- **correspondance des catégories** : pour chaque catégorie, le code attendu par l'outil appelé (par exemple le
  `service_code` Open311), disponible dans `{{categorie_code}}` ; avec l'option « N'envoyer que les observations des
  catégories qui ont un code », les autres observations ne déclenchent pas ce webhook.

Le menu **Modèle** du formulaire préremplit les champs pour Mastodon, Slack / Mattermost, Discord, Bluesky, Open311 et
Redmine (détails dans [WEBHOOKS.md](WEBHOOKS.md)), ou les vide.

Exemple de corps JSON :

```json
{"token": "{{token}}", "url": "{{observation_url}}", "photo": "{{photo_url}}",
 "categorie": "{{categorie_name}}", "comment": "{{comment}}", "lat": {{lat}}, "lon": {{lon}}}
```
