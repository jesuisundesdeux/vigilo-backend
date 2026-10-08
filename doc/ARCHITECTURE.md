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

Le schéma évolue uniquement par les migrations `app/migrations/init-X.Y.Z.sql` (voir [UPGRADE.md](UPGRADE.md)).
