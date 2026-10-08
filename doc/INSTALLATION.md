# Installation

Prérequis : PHP 7.3 à 8.3 avec `mysqli`, `gd`, `curl`, `fileinfo` (et `zip`, `sodium` pour la mise à jour
depuis l'admin), MySQL ou MariaDB (testé avec MariaDB 10.11 et 11.4, MySQL 8), un serveur web (Apache conseillé : les `.htaccess`
protègent `images/`, `caches/` et `migrations/`), et HTTPS (les applications refusent le HTTP).

## Avec Docker (recommandé)

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

## Hébergement classique (mutualisé, serveur dédié)

1. Télécharger l'archive `vigilo-backend-X.Y.Z.zip` de la [dernière release](https://github.com/jesuisundesdeux/vigilo-backend/releases)
   et copier le contenu de `app/` à la racine du site.
2. Créer une base MySQL/MariaDB et un utilisateur, puis `config/config.php` à partir de
   `config/config.php.tpl` (hôte, utilisateur, mot de passe, base ; ou variables d'environnement `MYSQL_*`).
3. Créer le schéma : `php scripts/vigilo-migrate.php --app=<racine du site>`, ou depuis l'admin
   (« Mises à jour » > « Appliquer les migrations »).
4. Copier `install_app/install.php` à la racine, l'ouvrir dans le navigateur pour créer l'administrateur.
5. Avec nginx (pas de `.htaccess`) : interdire `images/`, `caches/`, `migrations/` et `install.php`
   (voir [UPGRADE.md](UPGRADE.md)).

## Après l'installation

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

Mises à jour : [UPGRADE.md](UPGRADE.md).
