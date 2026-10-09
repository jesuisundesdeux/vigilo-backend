# AGENTS.md — vigilo-backend

Informations pour les agents IA (et les humains) qui travaillent sur ce dépôt. La documentation détaillée est dans
`doc/` ; ce fichier résume ce qu'il faut savoir pour modifier le code sans casser les instances en production.

## Le projet

Backend de [Vigilo](https://vigilo.city) : API REST + panneau d'administration des observations citoyennes
(problèmes de déplacement à pied / à vélo). Chaque association installe sa propre **instance** ; les clients sont
l'application web ([vigilo-webapp](https://github.com/jesuisundesdeux/vigilo-webapp), app.vigilo.city), d'anciennes
applications mobiles (non maintenues, mais toujours en circulation) et le site
[vigilo-website](https://github.com/jesuisundesdeux/vigilo-website) (vigilo.city). La liste des instances et la liste
nationale des catégories sont dans [vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf).

Cycle d'une observation : création (`create_issue.php`) → photo (`add_image.php`) → modération dans l'admin ou
`approve.php` (publication, webhooks) → suivi de résolution (comptes citystaff, `create_resolution.php`).
Détails : `doc/FONCTIONNEMENT.md`, vocabulaire : `doc/GLOSSAIRE.md`.

## Organisation

| Chemin | Contenu |
|---|---|
| `app/*.php` | Routes publiques de l'API (une route = un fichier), documentées dans `doc/REST_API.md` |
| `app/includes/` | Code partagé : `common.php` (connexion, config), `functions.php`, `security.php`, `images.php`, `blur.php`, `webhooks.php`, `updater.php`, `migrations.php`, `version.php` |
| `app/admin/` | Panneau d'administration : `index.php` (menu, rôles autorisés par page), `inc/<page>.php`, `assets/` (Bootstrap 5, Leaflet, `admin.js`), `js/` |
| `app/migrations/init-X.Y.Z.sql` | Migrations de la base, appliquées dans l'ordre des versions |
| `config/` | Modèles de configuration (`config.php.docker` lit les variables d'environnement) |
| `blur-server/` | Serveur de floutage optionnel (Python, image séparée `vigilo-blur`) |
| `scripts/` | `vigilo-migrate.php` (migrations en CLI), `build-release.sh`, `release-keygen.php`, `obsolete-paths.txt` |
| `tests/` | Contrat de l'API, tests fonctionnels, smoke test de l'admin, mise à jour, floutage (voir `tests/README.md`) |
| `doc/` | Documentation technique (API, fonctionnement, architecture, webhooks, mises à jour, contribution) |
| `Dockerfile`, `docker-compose*.yml`, `vigilo-entrypoint` | Image PHP 8.3 + Apache, profils `blur` et `watchtower` |

## Règles à respecter

- **Documentation** : toute modification du code s'accompagne, dans la même PR, de la mise à jour de la documentation
  concernée dans `doc/` : `GUIDE_CODE.md` (modules, routes, admin, tables), `REST_API.md` (API), `FONCTIONNEMENT.md`,
  `WEBHOOKS.md`, `ARCHITECTURE.md`, `UPGRADE.md` selon le sujet, ainsi que `CHANGELOG.md` ; et, si besoin, la
  documentation utilisateur et la spécification OpenAPI du site (voir « Documentation à tenir à jour »).
- **PHP 7.3 à 8.3** : PHP procédural, sans framework ni Composer, extension `mysqli`. Pas de syntaxe postérieure à
  PHP 7.3 (pas de types union, `match`, arguments nommés, propriétés typées, fonctions fléchées `fn`, `str_contains`…).
  La CI vérifie la syntaxe sur 7.3 et 8.3 ; des hébergements mutualisés tournent encore en 7.3.
- **Compatibilité de l'API** : les réponses des routes publiques ne doivent pas changer pour les applications
  existantes. Les tests de contrat comparent 72 requêtes à `tests/api/snapshots.json` ; un changement voulu est
  déclaré dans `tests/api/expected_changes.json` et documenté dans `doc/REST_API.md` (colonne « Compatibilité »).
  Nouveaux champs ou routes : ajout seulement, avec repli côté clients pour les instances pas à jour.
- **SQL** : toute valeur passe par `mysqli_real_escape_string` ou un cast (`intval`, `floatval`) ; les noms de
  colonnes viennent de listes blanches, jamais des clés POST. Pas de requête construite avec une valeur brute.
- **Admin** :
  - sortie HTML échappée avec `h()` ;
  - toute action (lien ou formulaire) porte le jeton CSRF (`csrf_field()`, `csrf_query()`), vérifié pour toutes les
    pages ;
  - accès contrôlé par rôle dans `$menu` de `app/admin/index.php` (`admin`, `moderator`, `citystaff`) ; une page
    commence par le contrôle `in_array($_SESSION['role'], $menu[$page_name]['access'])` ;
  - actions privilégiées journalisées avec `audit_log('action', 'cible', $details)` ;
  - création / modification dans une fenêtre Bootstrap : bouton `data-bs-toggle="modal" data-fill='{...}'`,
    réouverture après refus avec `data-reopen-modal` (voir `app/admin/assets/admin.js`) ;
  - pas de ressource externe (CDN) : les bibliothèques sont copiées dans `app/admin/assets/vendor/` ; en-têtes de
    sécurité (CSP, `Referrer-Policy: same-origin`).
- **Photos** : les photos non approuvées sont pixelisées pour le public (`generate_panel.php`, `get_photo.php`) ;
  ne jamais servir une photo non floutée / non pixelisée sans clé admin/modérateur ou lien signé (`exp` + `sig`,
  `photo_signed_valid()`, variable `{{photo_full_url}}` des webhooks).
- **Migrations** (`app/migrations/init-X.Y.Z.sql`) :
  - un fichier par version, **rejouable** (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`…) ;
  - MySQL ne connaît pas `ADD COLUMN IF NOT EXISTS` ni `DROP COLUMN IF EXISTS` : utiliser le motif conditionnel
    `SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE …', 'SELECT 1') FROM information_schema.COLUMNS …);
    PREPARE …; EXECUTE …; DEALLOCATE PREPARE …;` (exemples dans `init-0.0.22.sql` et `init-0.0.23.sql`) ;
  - la dernière ligne met à jour `vigilo_db_version` ;
  - **ne jamais modifier la migration d'une version déjà publiée (tag existant)** : créer la migration de la version
    suivante.
- **Version** : `app/includes/version.php` (`BACKEND_VERSION`) est la seule source. La CI refuse une migration plus
  récente que cette version. Une version sans changement de schéma n'a pas besoin de migration.
- **Code retiré** : lister les fichiers supprimés dans `scripts/obsolete-paths.txt` (la mise à jour depuis l'admin
  les supprime des instances).
- **Langue** : interface, messages, documentation, CHANGELOG et messages de commit en **français** ; commentaires du
  code en anglais, courts.

## Tests

Prérequis : une MariaDB (`MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_ROOT_PASSWORD` ; la base
est recréée), le client `mysql`, PHP CLI, Python 3, Docker.

```sh
docker build -t vigilo-backend:dev .
tests/api/run.sh app vigilo-backend:dev                    # contrat de l'API (72/72 attendu)
ADMIN_SMOKE=1 tests/api/run.sh app vigilo-backend:dev      # + toutes les pages et actions de l'admin
tests/api/run.sh app vigilo-backend:dev --functional       # tests fonctionnels (unittest)
tests/update/run.sh                                        # mise à jour depuis l'admin
php scripts/vigilo-migrate.php [--status|--to=X.Y.Z]       # migrations
```

`<image>` peut aussi être `vigilobs/vigilo-backend:0.0.20` (PHP 7.3) ou `host` (PHP local). Les API externes
(geo.api.gouv.fr, Nominatim, tuiles OSM, Wikidata) ne sont pas appelées par les tests.

Avant de pousser : syntaxe PHP (`php -l`), contrat + smoke + fonctionnels verts, et ajout de tests pour toute
nouvelle route, action d'admin ou règle (`tests/api/functional.py`, `tests/admin/smoke.py`).

## Publier une version

1. `BACKEND_VERSION` dans `app/includes/version.php`, migration éventuelle, section dans `CHANGELOG.md`.
2. Fusionner sur `master` (CI verte), puis pousser le tag **`vX.Y.Z`** sur le commit de fusion. Le workflow
   « Release image » vérifie que le tag correspond à `BACKEND_VERSION` et publie la release GitHub (archive signée
   pour la mise à jour depuis l'admin) et les images Docker (`vigilobs/vigilo-backend` et
   `ghcr.io/jesuisundesdeux/vigilo-backend` : `X.Y.Z`, `X.Y`, `stable`, `latest` ; idem pour `vigilo-blur`).
3. Sans tag, aucune image n'est publiée : `docker compose pull` installe la dernière version taguée.

## Documentation à tenir à jour

- `doc/GUIDE_CODE.md` : fonctionnement détaillé du code (à mettre à jour avec toute évolution d'un module, d'une route
  ou de l'admin).
- `CHANGELOG.md` à chaque changement visible.
- `doc/REST_API.md` pour toute évolution de l'API, et la spécification OpenAPI du site (`vigilo-website/data/openapi.yaml`).
- `doc/FONCTIONNEMENT.md`, `doc/WEBHOOKS.md`, `doc/UPGRADE.md` selon le sujet ; la documentation utilisateur
  (installation, configuration, administration) est sur le site, dans `vigilo-website/content/documentation/`
  (`doc/UPGRADE.md` y est recopié).

## Docker et mises à jour

- `docker-compose.yml` : services `db` (MariaDB), `web` (image `${VIGILO_IMAGE:-vigilobs/vigilo-backend:0.0}`),
  profils `blur` (serveur de floutage) et `watchtower` (image `nickfedor/watchtower:1`, version maintenue ;
  `containrrr/watchtower` est incompatible avec Docker Engine 29). L'admin appelle Watchtower en **POST**
  `/v1/update` avec `Authorization: Bearer $VIGILO_WATCHTOWER_TOKEN`.
- Le code est dans l'image (jamais modifié dans le conteneur) ; la base est migrée au démarrage (`AUTOUPDATE`).
- Variables d'environnement : `doc/ARCHITECTURE.md`.

## Dépôts liés

- [vigilo-webapp](https://github.com/jesuisundesdeux/vigilo-webapp) : client web, doit fonctionner avec les instances
  pas à jour (repli quand une route ou un champ manque).
- [vigilo-website](https://github.com/jesuisundesdeux/vigilo-website) : site vigilo.city, documentation utilisateur,
  page API (OpenAPI).
- [vigilo-conf](https://github.com/jesuisundesdeux/vigilo-conf) : instances (`citylist.json`) et catégories nationales
  (`categorielist.json`).
