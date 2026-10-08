# Architecture du code

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
    webhooks.php        webhooks à la publication d'une observation
    security.php        CSRF, échappement, journal, anti-spam, limitation des connexions
    migrations.php      exécution des migrations
    updater.php         mise à jour depuis l'admin (releases, sauvegarde, retour arrière, Watchtower)
    version.php         version du code
  admin/                interface d'administration (index.php + pages inc/*.php)
    assets/             admin.js, admin.css, vendor/ (Bootstrap, Bootstrap Icons, Leaflet servis localement)
    js/                 carte des scopes, import des communes, complément Wikidata
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

L'admin ne charge aucune ressource externe. Seules les cartes et outils de saisie appellent, depuis le navigateur,
des services publics : tuiles OpenStreetMap (avec l'origine du site dans le `Referer`), Nominatim (recherche d'un lieu
sur la carte des scopes), [geo.api.gouv.fr](https://geo.api.gouv.fr) (import des communes) et Wikidata (complément
d'une ville).

## Variables d'environnement

Lues par le backend : `MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`
(`config/config.php.docker`), `VIGILO_BLUR_URL`, `VIGILO_BLUR_TIMEOUT`, `VIGILO_WEBHOOK_TIMEOUT`, `VIGILO_WATCHTOWER_URL`,
`VIGILO_WATCHTOWER_TOKEN`, `VIGILO_RELEASE_API` (tests).
Les réglages de l'instance (table `obs_config`) sont décrits sur [vigilo.city](https://vigilo.city/fr/documentation/configuration/global/).

## Base de données

| Table | Contenu |
|---|---|
| `obs_list` | Observations : token, secretid, position, adresse, ville, catégorie, commentaire, explication, date, statut, `obs_approved`, `obs_complete` (photo reçue) |
| `obs_resolutions`, `obs_resolutions_tokens` | Résolutions déclarées et observations qu'elles concernent |
| `obs_status_update` | Historique des changements de statut |
| `obs_scopes` | Zones géographiques de l'instance (`scope_sharing_content_text` et `scope_twitter` ne sont plus modifiables, toujours renvoyés par `get_scope.php`) |
| `obs_cities` | Villes (rattachement des observations, villes des citystaff) |
| `obs_roles` | Comptes de l'admin : login, mot de passe, rôle, clé API, villes |
| `obs_config` | Réglages et version de la base (`vigilo_db_version`) |
| `obs_categories` | Catégories nationales désactivées sur l'instance et catégories propres à l'instance (`cat_custom = 1`, numéros à partir de 1000) |
| `obs_notes` | Notes privées des modérateurs |
| `obs_webhooks`, `obs_webhook_deliveries` | Webhooks (dont la correspondance des catégories) et journal de leurs envois |
| `obs_audit_log` | Journal des actions privilégiées |
| `obs_login_attempts`, `obs_rate_limit` | Limitation des connexions et anti-spam |

Le schéma évolue uniquement par les migrations `app/migrations/init-X.Y.Z.sql` (voir [UPGRADE.md](UPGRADE.md)).
