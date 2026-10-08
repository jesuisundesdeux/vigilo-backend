# Vigilo Backend

API REST et interface d'administration des observations de l'application [Vigilo](https://vigilo.city/fr/).
Chaque association installe et gère sa propre instance ; les applications mobiles et la
[web app](https://github.com/jesuisundesdeux/vigilo-webapp) s'y connectent.

- Images Docker : [`vigilobs/vigilo-backend`](https://hub.docker.com/r/vigilobs/vigilo-backend) et `ghcr.io/jesuisundesdeux/vigilo-backend`
- Documentation détaillée : [`doc/`](doc/) — [API REST](doc/REST_API.md), [mises à jour](doc/UPGRADE.md),
  [glossaire](doc/GLOSSAIRE.md), [contribution](doc/GUIDE_CONTRIBUTION.md), [historique](CHANGELOG.md)
- Serveur de floutage optionnel : [`blur-server/`](blur-server/README.md)

## Sommaire

1. [Fonctionnement](#fonctionnement)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Administration](#administration)
5. [API](#api)
6. [Photos, pixelisation et floutage](#photos-pixelisation-et-floutage)
7. [Mises à jour](#mises-à-jour)
8. [Architecture du code](#architecture-du-code)
9. [Base de données](#base-de-données)
10. [Développement et tests](#développement-et-tests)
11. [Publier une version](#publier-une-version)

## Fonctionnement

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

### Rôles

| Rôle | Accès |
|---|---|
| `admin` | Tout : observations, résolutions, villes, comptes, scopes, réglages, journal, mises à jour. Clé API pour l'approbation et la modification par l'API. |
| `moderator` | Modération (approbation, modification, suppression) dans l'admin et par l'API. |
| `citystaff` | Observations et résolutions des villes associées au compte uniquement (statuts « prise en compte », « en cours »). |
| `guest` | Compte sans droit (en attente d'attribution). |

Chaque compte a une **clé API** (régénérable dans l'admin) passée en paramètre `key` aux appels qui le demandent.

## Installation

Prérequis : PHP 7.3 à 8.3 avec `mysqli`, `gd`, `curl`, `fileinfo` (et `zip`, `sodium` pour la mise à jour
depuis l'admin), MySQL ou MariaDB (testé avec MariaDB 10.11 et 11.4, MySQL 8), un serveur web (Apache conseillé : les `.htaccess`
protègent `images/`, `caches/` et `migrations/`), et HTTPS (les applications refusent le HTTP).

### Avec Docker (recommandé)

```sh
git clone https://github.com/jesuisundesdeux/vigilo-backend.git && cd vigilo-backend
cp .env_sample .env          # mots de passe, VOLUME_PATH, BIND
docker compose up -d
```

- `db` : MariaDB (`mariadb:lts`) ; `web` : l'image versionnée `vigilobs/vigilo-backend:0.0`, qui suit les
  correctifs de la série 0.0. Le code est dans l'image ; seuls `images/`, `caches/` et les logs sont des volumes.
- Au premier démarrage, la base est créée et `install.php` est mis en place : ouvrir
  `https://<instance>/install.php` pour créer le premier administrateur (le fichier se supprime ensuite).
- Profils optionnels : `--profile watchtower` (mise à jour depuis l'admin), `--profile blur` (serveur de
  floutage, avec `VIGILO_BLUR_URL=http://blur:8000/blur` dans `.env`).
- Mettre un reverse proxy HTTPS devant le port `BIND`.

Variables de `.env` : `MYSQL_*` (base), `VOLUME_PATH` (données), `BIND` (adresse:port exposé), `AUTOUPDATE`
(migrer la base au démarrage, `true` par défaut), `VIGILO_IMAGE`, `WATCHTOWER_TOKEN` et `VIGILO_WATCHTOWER_URL`,
`VIGILO_BLUR_URL`. Développement avec le code du dépôt monté : `docker-compose.dev.yml`.

### Hébergement classique (mutualisé, serveur dédié)

1. Télécharger l'archive `vigilo-backend-X.Y.Z.zip` de la [dernière release](https://github.com/jesuisundesdeux/vigilo-backend/releases)
   et copier le contenu de `app/` à la racine du site.
2. Créer une base MySQL/MariaDB et un utilisateur, puis `config/config.php` à partir de
   `config/config.php.tpl` (hôte, utilisateur, mot de passe, base ; ou variables d'environnement `MYSQL_*`).
3. Créer le schéma : `php scripts/vigilo-migrate.php --app=<racine du site>`, ou depuis l'admin
   (« Mises à jour » > « Appliquer les migrations »).
4. Copier `install_app/install.php` à la racine, l'ouvrir dans le navigateur pour créer l'administrateur.
5. Avec nginx (pas de `.htaccess`) : interdire `images/`, `caches/`, `migrations/` et `install.php`
   (voir [UPGRADE.md](doc/UPGRADE.md)).

### Après l'installation

Dans l'admin : renseigner la **configuration** (nom, URL de l'instance, langue, fuseau), créer les **scopes**
et les **villes**, puis faire référencer l'instance dans
[vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf) pour qu'elle apparaisse dans les applications.

## Configuration

Les réglages sont dans la table `obs_config` et se modifient dans l'admin (**Configuration**) :

| Réglage | Rôle |
|---|---|
| `vigilo_name`, `vigilo_urlbase`, `vigilo_http_proto` | Nom de l'instance, domaine (sans `http://`) et protocole des URL générées |
| `vigilo_language`, `vigilo_timezone`, `mysql_charset` | Langue, fuseau horaire, jeu de caractères de la connexion |
| `vigilo_shownonapproved` | Publier les observations sans attendre la modération |
| `vigilo_resolved_hide_days` | Ne plus lister les observations résolues depuis plus de N jours (0 : jamais) |
| `vigilo_blur_url` | Serveur de floutage (vide : `VIGILO_BLUR_URL`, sinon pas de floutage) |
| `vigilo_ratelimit_create` | Créations max. par IP et par 10 minutes (anti-spam, 0 : sans limite) |

Variables d'environnement lues par le backend : `MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`
(`config/config.php.docker`), `VIGILO_BLUR_URL`, `VIGILO_BLUR_TIMEOUT`, `VIGILO_WATCHTOWER_URL`,
`VIGILO_WATCHTOWER_TOKEN`, `VIGILO_RELEASE_API` (tests).

## Administration

Interface web `https://<instance>/admin/` (Bootstrap 5 servi localement, thème sombre, responsive) :

- **Tableau de bord** : observations à modérer, résolutions à valider, chiffres clés.
- **Observations** : modération (approuver, refuser), modification, suppression, filtres (statut, ville,
  catégorie, adresse, doublons proches), notes privées des modérateurs, photos non pixelisées.
- **Résolutions** : validation des résolutions déclarées et de leurs photos.
- **Villes**, **Scopes**, **Comptes** (rôles, villes des citystaff, clés API).
- **Configuration** : les réglages ci-dessus, validés à l'enregistrement.
- **Journal** : actions privilégiées (connexions, modérations, réglages, mises à jour).
- **Mises à jour** : versions du code et de la base, mise à jour en un clic, vérifications de sécurité.

Sécurité : sessions durcies, jeton CSRF sur toutes les actions, limitation des tentatives de connexion,
contrôle d'accès par rôle, mots de passe hachés (`password_hash`).

## API

Toutes les routes sont à la racine de l'instance, répondent en JSON (sauf images) avec
`Access-Control-Allow-Origin: *` et l'en-tête `BACKEND_VERSION`. Erreurs :
`{"error": {"status", "code", "message"}}` avec le code HTTP correspondant. Détail des paramètres et des
réponses : **[doc/REST_API.md](doc/REST_API.md)**.

| Route | Rôle | Authentification |
|---|---|---|
| `GET get_version.php` | Version du backend | — |
| `GET get_scope.php?scope=` | Informations d'un scope (carte, contact, partage) | — |
| `GET get_issues.php` | Liste des observations ; filtres `scope`, `c` (catégories), `status`, `approved`, `token`, `tokenfilters`, `lat`/`lon`/`radius`, `cityid`, `since`/`since_unit`, `count`, `t` ; formats `json`, `csv`, `geojson` | `key` pour les non approuvées |
| `GET get_photo.php?token=` | Photo d'une observation (`type=resolution` pour une résolution) | approuvée, ou `key` admin/modérateur |
| `GET generate_panel.php?token=` | Ancien « panneau » : la photo, pixelisée tant qu'elle n'est pas approuvée ; largeur `s` | `secretid` de l'auteur ou `key` pour la version nette |
| `GET mosaic.php` | Page HTML en mosaïque des photos d'un scope | — |
| `GET acl.php?key=` | Rôle associé à une clé | `key` |
| `POST create_issue.php` | Crée une observation (ou la modifie avec `token` + `key`) | anti-spam par IP |
| `POST add_image.php?token=&secretid=` | Photo de l'observation (corps brut, ou `method=base64` en formulaire/JSON) ; `type=resolution` pour une résolution | `secretid` |
| `GET approve.php?token=&key=` | Approuve (`approved=1`) ou refuse (`approved=2`) | `key` admin/modérateur |
| `GET delete.php?token=` | Supprime une observation | `secretid` ou `key` |
| `POST create_resolution.php` | Déclare la résolution d'observations (`tokenlist`) | anti-spam par IP |

Workflow type d'une application : `create_issue.php` → `add_image.php` → (modération) → `get_issues.php`
et `get_photo.php`. La compatibilité de ces réponses avec les applications existantes est vérifiée en CI
(tests de contrat, voir plus bas).

## Photos, pixelisation et floutage

- Les photos sont dans `images/<token>.jpg` et `images/resolutions/<token>.jpg`, jamais servies directement
  (`.htaccess`) : elles passent par `get_photo.php`, `generate_panel.php` ou `admin/photo.php`.
- Une photo non approuvée n'est servie au public que **pixelisée** ; le cache (`caches/`) ne contient que des
  images pixelisées. Une photo approuvée ne peut plus être remplacée par son auteur.
- **Serveur de floutage** (optionnel, [`blur-server/`](blur-server/README.md)) : s'il est configuré, chaque
  photo reçue lui est envoyée et remplacée par sa version où visages et plaques d'immatriculation sont
  masqués. S'il échoue, la photo est gardée telle qu'envoyée (journalisé, en-tête `X-Vigilo-Blur: failed`) :
  la modération manuelle reste le garde-fou. Tout serveur compatible (champ multipart `picture`), comme
  [SGBlur](https://github.com/cquest/sgblur), convient.

## Mises à jour

Détail : **[doc/UPGRADE.md](doc/UPGRADE.md)**.

- **Version** : unique, dans `app/includes/version.php`. Le schéma suit les migrations
  `app/migrations/init-X.Y.Z.sql`, appliquées dans l'ordre et rejouables (`scripts/vigilo-migrate.php`,
  `--status`, `--to=X.Y.Z`). Le code refuse une base plus récente que lui.
- **Installation classique** : depuis l'admin (« Mises à jour »), téléchargement de l'archive de la release
  GitHub, vérification SHA-256 et signature ed25519, sauvegarde du code et de la base, installation,
  migrations, contrôle de l'instance et retour arrière automatique en cas d'échec. Le code retiré d'une
  version (`scripts/obsolete-paths.txt`) est supprimé.
- **Docker** : le code n'est jamais modifié dans le conteneur. `docker compose pull && docker compose up -d`,
  ou le bouton de l'admin avec Watchtower ; la base est migrée au démarrage (`AUTOUPDATE`).

## Architecture du code

PHP procédural sans framework ni dépendance Composer, compatible PHP 7.3 à 8.3.

```
app/                    code servi (racine du site)
  *.php                 routes de l'API (une route = un fichier)
  includes/
    common.php          configuration, connexion à la base, rôles (ACL), statuts
    functions.php       fonctions communes (jsonError, rôles, groupes, catégories, cache)
    handle.php          accès aux observations et résolutions (tokens, secretid)
    images.php          validation, redimensionnement, pixelisation des photos
    blur.php            client du serveur de floutage
    security.php        CSRF, échappement, journal, anti-spam, limitation des connexions
    migrations.php      exécution des migrations
    updater.php         mise à jour depuis l'admin (releases, sauvegarde, retour arrière, Watchtower)
    version.php         version du code
  admin/                interface d'administration (index.php + pages inc/*.php)
  migrations/           init-X.Y.Z.sql, une par version
  config/               config.php (instance, non versionné)
  images/, caches/      données de l'instance (non servies directement)
blur-server/            serveur de floutage (Python/OpenCV, image Docker séparée)
install_app/install.php création du premier administrateur
scripts/                vigilo-migrate.php, build-release.sh, release-keygen.php, obsolete-paths.txt
config/                 Apache, config.php pour Docker
tests/                  tests de contrat, fonctionnels, admin, mise à jour, floutage
Dockerfile, vigilo-entrypoint, docker-compose*.yml
```

`vigilo-entrypoint` (Docker) attend la base, crée le schéma ou applique les migrations, met en place
`install.php` sur une base vide et rend `images/` et `caches/` accessibles à Apache.

## Base de données

| Table | Contenu |
|---|---|
| `obs_list` | Observations : token, secretid, position, adresse, ville, catégorie, commentaire, explication, date, statut, `obs_approved`, `obs_complete` (photo reçue) |
| `obs_resolutions`, `obs_resolutions_tokens` | Résolutions déclarées et observations qu'elles concernent |
| `obs_status_update` | Historique des changements de statut |
| `obs_scopes` | Zones géographiques de l'instance |
| `obs_cities` | Villes (rattachement des observations, villes des citystaff) |
| `obs_roles` | Comptes de l'admin : login, mot de passe, rôle, clé API, villes |
| `obs_config` | Réglages et version de la base (`vigilo_db_version`) |
| `obs_notes` | Notes privées des modérateurs |
| `obs_audit_log` | Journal des actions privilégiées |
| `obs_login_attempts`, `obs_rate_limit` | Limitation des connexions et anti-spam |

## Développement et tests

```sh
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build   # code de ./app monté
```

Les tests tournent en CI (`.github/workflows/ci.yml`) et en local ([tests/README.md](tests/README.md)) :

| Suite | Vérifie |
|---|---|
| Lint | Syntaxe PHP 7.3 et 8.3 |
| Migrations | Installation neuve, rejeu, montée depuis chaque version, refus du retour arrière |
| Contrat de l'API (`tests/api/run.sh`) | 72 requêtes : réponses identiques à la version précédente (PHP 8.3 et 7.3) |
| Fonctionnels (`--functional`) | Comportement de chaque appel, modération, pixelisation, floutage, anti-spam, injections SQL |
| Admin (`ADMIN_SMOKE=1`) | Toutes les pages et actions, CSRF, rôles, aucune erreur PHP |
| Mise à jour (`tests/update/run.sh`) | Version signée installée, archive altérée refusée, migration cassée annulée |
| Floutage (`blur-server/tests`, `tests/blur/compose.sh`) | Visages et plaques masqués, docker-compose de bout en bout |

Contributions : branche depuis `master` et pull request, CI verte ; une évolution du schéma = une migration
`init-X.Y.Z.sql` et la version dans `app/includes/version.php`. Voir [doc/GUIDE_CONTRIBUTION.md](doc/GUIDE_CONTRIBUTION.md).

## Publier une version

1. Mettre à jour `app/includes/version.php`, ajouter `app/migrations/init-X.Y.Z.sql` et le `CHANGELOG.md`.
2. Pousser le tag `vX.Y.Z` : le workflow « Release image » publie la release GitHub (archive de mise à jour,
   `SHA256SUMS`, signature si le secret `VIGILO_RELEASE_SIGNING_KEY` existe) et les images `vigilo-backend`
   (`X.Y.Z`, `X.Y`, `stable`, `latest`) et `vigilo-blur`, signées avec cosign, sur ghcr.io et sur Docker Hub
   si les secrets `DOCKERHUB_USERNAME` / `DOCKERHUB_TOKEN` existent.

## Licence

GPL-3.0 ([COPYING](COPYING)). © Vélocité Montpellier et les contributeurs.
