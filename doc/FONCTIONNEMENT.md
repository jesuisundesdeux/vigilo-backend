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
chacun avec ses limites, son centre de carte et son contact. Les catégories sont communes à toutes les
instances ([vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf)).

## Rôles

| Rôle | Accès |
|---|---|
| `admin` | Tout : observations, résolutions, villes, comptes, scopes, réglages, journal, mises à jour. Clé API pour l'approbation et la modification par l'API. |
| `moderator` | Modération (approbation, modification, suppression) depuis l'application web (mode admin) et par l'API, avec sa clé ; pas d'accès au panneau d'administration. |
| `citystaff` | Observations et résolutions des villes associées au compte uniquement (statuts « prise en compte », « en cours »). |
| `guest` | Compte sans droit (en attente d'attribution). |

Chaque compte a une **clé API** (régénérable dans l'admin) passée en paramètre `key` aux appels qui le demandent.
Panneau d'administration et ajout de modérateurs : [guide d'administration](https://vigilo.city/fr/documentation/administration/) sur vigilo.city.

## Photos, pixelisation et floutage

- Les photos sont dans `images/<token>.jpg` et `images/resolutions/<token>.jpg`, jamais servies directement
  (`.htaccess`) : elles passent par `get_photo.php`, `generate_panel.php` ou `admin/photo.php`.
- Une photo non approuvée n'est servie au public que **pixelisée** ; le cache (`caches/`) ne contient que des
  images pixelisées. Une photo approuvée ne peut plus être remplacée par son auteur.
- **Serveur de floutage** (optionnel, [`blur-server/`](../blur-server/README.md)) : s'il est configuré, chaque
  photo reçue lui est envoyée et remplacée par sa version où visages et plaques d'immatriculation sont
  masqués. S'il échoue, la photo est gardée telle qu'envoyée (journalisé, en-tête `X-Vigilo-Blur: failed`) :
  la modération manuelle reste le garde-fou. Tout serveur compatible (champ multipart `picture`), comme
  [SGBlur](https://github.com/cquest/sgblur), convient.

Voir aussi le [glossaire](GLOSSAIRE.md) et l'[API REST](REST_API.md).

## Webhooks

À la publication d'une observation (passage à `approved = 1`, depuis l'admin ou `approve.php`), le backend appelle
les webhooks actifs définis dans l'admin (page **Webhooks**, réservée aux administrateurs) :

- **appel** : méthode (POST, PUT, PATCH, GET), URL, en-têtes (`Nom: valeur`, un par ligne) et corps ;
- **variables** `{{nom}}` utilisables partout, remplacées par les champs de l'observation : `event`, `token`,
  `observation_url` (lien vers l'application web), `photo_url`, `comment`, `explanation`, `categorie`,
  `categorie_name`, `address`, `cityname`, `scope`, `lat`, `lon`, `time` (timestamp), `date` (ISO 8601), `status`,
  `instance_name`, `instance_url` ;
- **échappement** selon l'emplacement : encodées dans l'URL et un corps « formulaire », échappées JSON dans un corps
  JSON (écrire `"{{comment}}"` entre guillemets ; le corps est vérifié à l'enregistrement), sans saut de ligne dans
  les en-têtes, telles quelles dans un corps texte ;
- **envoi** : une seule fois par observation (pas de nouvel appel si elle est déjà publiée), en parallèle, après la
  réponse à l'application, avec un délai maximal de 5 secondes (`VIGILO_WEBHOOK_TIMEOUT`) ; pas de nouvel essai
  automatique. Les 500 derniers envois (code HTTP, erreur, durée, début de la réponse) sont visibles dans l'admin ;
  « Enregistrer et tester » envoie la requête avec la dernière observation publiée.

Le menu **Modèle** du formulaire préremplit les champs pour Mastodon, Slack / Mattermost, Discord, Bluesky, Open311 et
Redmine (détails dans [WEBHOOKS.md](WEBHOOKS.md)), ou les vide.

Exemple de corps JSON :

```json
{"token": "{{token}}", "url": "{{observation_url}}", "photo": "{{photo_url}}",
 "categorie": "{{categorie_name}}", "comment": "{{comment}}", "lat": {{lat}}, "lon": {{lon}}}
```
