## Mise à jour à partir de la version 0.0.22

À partir de la 0.0.22, les mises à jour se font depuis l'admin, menu **« Mises à jour »**.
La page indique la version du code, celle de la base, la dernière version publiée et
des vérifications de sécurité.

### Installation classique (hébergement mutualisé, serveur dédié)

Quand une nouvelle version est publiée, le bouton **« Installer la version X »** (mot de passe demandé) :

1. télécharge l'archive de la version publiée sur GitHub et vérifie sa somme SHA-256
   (et sa signature ed25519 si une clé publique est définie dans `app/includes/release_key.php`) ;
2. vérifie la version de PHP et les extensions demandées par la nouvelle version ;
3. sauvegarde le code et la base dans `caches/updates/` (les 3 dernières sauvegardes sont gardées) ;
4. installe les fichiers (sans toucher à `config/config.php`, `images/` et `caches/`) ;
5. applique les migrations de la base ;
6. vérifie que l'instance répond avec la nouvelle version ;
7. en cas d'échec, restaure le code et la base.

Prérequis : extensions PHP `zip` et `curl`, et le serveur web doit pouvoir écrire dans le
répertoire du code. Sinon la page explique comment faire la mise à jour à la main : remplacer
le contenu de `app` par celui de l'archive, puis cliquer sur « Appliquer les migrations ».

Sur un serveur dédié, les migrations peuvent aussi être lancées en ligne de commande :
`php scripts/vigilo-migrate.php` (`--status` pour voir les versions).

### Docker

Le code fait partie de l'image : il n'est jamais modifié dans le conteneur. Le
`docker-compose.yml` fourni utilise l'image `vigilobs/vigilo-backend:0.0`, qui suit les
correctifs de la série 0.0 (tags publiés : la version exacte, par exemple `0.0.24`, et `0.0`, `stable`, `latest` ; l'image est aussi
publiée sur `ghcr.io/jesuisundesdeux/vigilo-backend`). La base est migrée au démarrage du conteneur
(sauf si `AUTOUPDATE=false`) ; le conteneur refuse de démarrer si la base est plus récente que le code.

Mise à jour en ligne de commande :

```
docker compose pull
docker compose up -d
```

Mise à jour depuis l'admin, avec Watchtower (service du `docker-compose.yml`, activé par défaut depuis la 0.0.32) :
`WATCHTOWER_TOKEN` dans `.env` (chaîne aléatoire, par exemple `openssl rand -hex 32`), partagé par l'admin et
Watchtower. Le bouton « Mettre à jour l'image avec Watchtower » de la page « Mises à jour » télécharge la nouvelle
image et redémarre le conteneur. Watchtower n'agit que sur le conteneur `web` (label
`com.centurylinklabs.watchtower.enable`) et seulement à la demande ; son API n'est pas publiée sur l'hôte. Il a accès
au socket Docker : pour vous en passer, retirez le service `watchtower` de votre `docker-compose.yml`.

Watchtower est l'image `nickfedor/watchtower`, version maintenue de `containrrr/watchtower` (archivée). Si
Watchtower ne démarre pas avec `client version 1.25 is too old. Minimum supported API version is 1.44` (Docker
Engine 29 et suivants), c'est que l'ancienne image est encore utilisée : retirez `WATCHTOWER_IMAGE` de `.env` (ou
mettez `WATCHTOWER_IMAGE=nickfedor/watchtower:1`), reprenez le `docker-compose.yml` de cette version, puis
`docker compose up -d`. Le bouton de l'admin appelle Watchtower en POST, comme le demande la
nouvelle image (backend 0.0.24 et suivants) ; avec un backend plus ancien, gardez `containrrr/watchtower:1.7.1` en
ajoutant `DOCKER_API_VERSION=1.44` à l'environnement du service `watchtower`.

Pour passer à une nouvelle série (0.1, 1.0…), changez `VIGILO_IMAGE` dans `.env`.

Watchtower et `docker compose pull` ne mettent à jour que les images : le fichier `docker-compose.yml` du serveur
n'est jamais modifié. Quand une version le fait évoluer (signalé dans le
[CHANGELOG](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/CHANGELOG.md), comme l'image de Watchtower
en 0.0.24), reprenez-le depuis le dépôt en gardant votre `.env`.

Pour développer avec le code du dépôt :
`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`.

### Actions nécessaires selon la version de départ

Les migrations de la base sont appliquées automatiquement (bouton de l'admin, ou démarrage du conteneur Docker) : une
mise à jour ne demande une action que dans les cas ci-dessous. Les nouveautés de chaque version sont dans le
[CHANGELOG](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/CHANGELOG.md).

| Version de départ | Vers | Actions |
|---|---|---|
| 0.0.17 à 0.0.21 | 0.0.22 | Mise à jour manuelle (ci-dessous) |
| 0.0.22 à 0.0.31 | 0.0.32 et suivantes | Webhooks à plusieurs événements ; Docker : `WATCHTOWER_TOKEN` (ci-dessous) |
| 0.0.32, 0.0.33 | 0.0.34 et suivantes | Vérifier l'adresse de l'instance (ci-dessous) |
| 0.0.34 et suivantes | dernière version | Aucune |

Une mise à jour qui saute plusieurs versions cumule leurs actions.

#### Depuis une version antérieure à 0.0.22 (dernière mise à jour manuelle)

1. Sauvegardez la base et les répertoires `images/` et `caches/`.
2. Mettez à jour le code :
   - Docker : reprenez le `docker-compose.yml` du dépôt, puis `docker compose pull && docker compose up -d` ;
   - installation classique : remplacez le contenu de `app` (sans écraser `config/config.php`, `images`, `caches`).
3. Appliquez les migrations (automatique avec Docker, sinon depuis l'admin « Mises à jour »).
4. **Videz les répertoires `caches/` et `maps/`** : les versions précédentes pouvaient y écrire des panneaux non floutés.
   `maps/` et `panels/` ne servent plus et peuvent être supprimés.
5. Ouvrez la page « Mises à jour » : la rubrique « Vérifications de sécurité » doit être au vert. En particulier,
   `images/` et `caches/` ne doivent pas être accessibles depuis le web. Sous nginx, les `.htaccess` ne
   sont pas lus, ajoutez :

   ```nginx
   location ~ ^/(images|caches|migrations)/ { deny all; return 403; }
   ```

6. Vérifiez qu'il ne reste pas de fichier `install.php` à la racine.

#### Vers 0.0.32 et suivantes

- **Webhooks** : un webhook ne suit plus qu'un événement. Ceux abonnés à plusieurs événements sont signalés dans la
  page Webhooks de l'admin (ils continuent d'être envoyés) : n'en garder qu'un, et créer un webhook pour chacun des
  autres.
- **Docker** : si vous reprenez le nouveau `docker-compose.yml` (Watchtower actif par défaut), définissez
  `WATCHTOWER_TOKEN` dans `.env` (`docker compose` refuse de démarrer sans). L'ancien fichier continue de fonctionner.

#### Vers 0.0.34 et suivantes

- Vérifiez que l'**adresse de l'instance** est renseignée (Configuration, `vigilo_urlbase`) : les liens pour choisir
  un mot de passe l'utilisent.

### Publier une version (mainteneurs)

Voir le [guide du contributeur](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/doc/GUIDE_CONTRIBUTION.md#publier-une-version).

## Mise à jour des versions antérieures à 0.0.22

Versions prises en charge : **0.0.17 et suivantes**. Une instance plus ancienne (aucune n'est en service) doit d'abord
passer en 0.0.17 avec le code et les migrations du tag `v0.0.31`, le dernier qui les contient ; à partir de la 0.0.32,
une base plus ancienne que la 0.0.17 est refusée, sans être modifiée.

Avant de mettre à jour désactiver INNODB STRICT MODE en lancant MySQL CLI:

```
SET SESSION innodb_strict_mode=OFF;
```

### Pour chaque mise à jour

#### Mise à jour du code

##### Versions >= 0.0.17

Depuis la version 0.0.17, les versions ont été fixées via les tags plutôt que les branches

**Pour un serveur dédié :**

Mettre à jour le repo et changer le tag :

```
$ git fetch --all --tags --prune
$ git checkout vX.X.X
```

**Pour un hebergement mutualisé :**

* Télécharger le package en provenance de https://github.com/jesuisundesdeux/vigilo-backend/tags avec la dernière version
* Sauvegarder le contenu des repertoires maps, cache et images.
* Extraire le package et copier le contenu de app sur le serveur dédié (écraser les fichiers si besoin)


#### Mettre à jour la base de données

Lancer dans l'ordre les fichiers SQL de app/migrations/ (mysql/init/ avant la 0.0.22) correspondant aux versions supérieures à la votre 

Exemple : si votre version est 0.0.18, lancer init-0.0.19.sql puis init-0.0.20.sql puis init-0.0.21.sql…


