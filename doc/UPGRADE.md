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

Mise à jour depuis l'admin, avec Watchtower (optionnel) : dans `.env`,

```
WATCHTOWER_TOKEN=une-longue-chaine-aleatoire
VIGILO_WATCHTOWER_URL=http://watchtower:8080
```

puis `docker compose --profile watchtower up -d`. Le bouton « Mettre à jour l'image avec Watchtower »
de la page « Mises à jour » télécharge la nouvelle image et redémarre le conteneur. Watchtower n'agit
que sur le conteneur `web` (label `com.centurylinklabs.watchtower.enable`) et seulement à la demande.
Il a accès au socket Docker : ne l'activez que si vous en avez besoin.

Watchtower est l'image `nickfedor/watchtower`, version maintenue de `containrrr/watchtower` (archivée). Si
Watchtower ne démarre pas avec `client version 1.25 is too old. Minimum supported API version is 1.44` (Docker
Engine 29 et suivants), c'est que l'ancienne image est encore utilisée : retirez `WATCHTOWER_IMAGE` de `.env` (ou
mettez `WATCHTOWER_IMAGE=nickfedor/watchtower:1`), reprenez le `docker-compose.yml` de cette version, puis
`docker compose --profile watchtower up -d`. Le bouton de l'admin appelle Watchtower en POST, comme le demande la
nouvelle image (backend 0.0.24 et suivants) ; avec un backend plus ancien, gardez `containrrr/watchtower:1.7.1` en
ajoutant `DOCKER_API_VERSION=1.44` à l'environnement du service `watchtower`.

Pour passer à une nouvelle série (0.1, 1.0…), changez `VIGILO_IMAGE` dans `.env`.

Watchtower et `docker compose pull` ne mettent à jour que les images : le fichier `docker-compose.yml` du serveur
n'est jamais modifié. Quand une version le fait évoluer (signalé dans le
[CHANGELOG](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/CHANGELOG.md), comme l'image de Watchtower
en 0.0.24), reprenez-le depuis le dépôt en gardant votre `.env`.

Pour développer avec le code du dépôt :
`docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build`.

### Passage à la 0.0.22 (dernière mise à jour manuelle)

1. Sauvegardez la base et les répertoires `images/` et `caches/`.
2. Mettez à jour le code :
   - Docker : remplacez `docker-compose.yml` par celui de la 0.0.22, puis `docker compose pull && docker compose up -d` ;
   - installation classique : remplacez le contenu de `app` (sans écraser `config/config.php`, `images`, `caches`).
3. Appliquez les migrations (automatique avec Docker, sinon depuis l'admin « Mises à jour »).
4. **Videz les répertoires `caches/` et `maps/`** : les versions précédentes pouvaient y écrire des panneaux non floutés
   (les nouveaux fichiers de cache ont un nom différent) ; `maps/` (cartes MapQuest) et `panels/` ne servent plus et peuvent être supprimés.
5. Ouvrez la page « Mises à jour » : la rubrique « Vérifications de sécurité » doit être au vert. En particulier,
   `images/` et `caches/` ne doivent pas être accessibles depuis le web. Sous nginx, les `.htaccess` ne
   sont pas lus, ajoutez :

   ```nginx
   location ~ ^/(images|caches|migrations)/ { deny all; return 403; }
   location = /install.php { deny all; return 403; }
   ```

6. Vérifiez qu'il ne reste pas de fichier `install.php` à la racine.
7. Fonctionnalités retirées : publication sur Twitter (les comptes Twitter enregistrés sont supprimés de la base),
   génération des panneaux et cartes MapQuest. `generate_panel.php` reste disponible et renvoie la photo de l'observation
   (pixelisée tant qu'elle n'est pas approuvée) : les applications existantes continuent de fonctionner.
8. Nouveaux réglages (admin « Configuration ») : limite anti-spam,
   masquage des observations résolues anciennes, serveur de floutage.
9. Floutage des photos : le réglage SGBlur devient « Serveur de floutage » (valeur reprise). Un serveur
   de floutage est fourni (`blur-server/`) : visages et plaques masqués avant publication. Avec Docker :
   `VIGILO_BLUR_URL=http://blur:8000/blur` dans `.env` et `docker compose --profile blur up -d` ;
   sinon voir `blur-server/README.md`. Aucun serveur configuré : les photos sont publiées telles qu'envoyées.
10. Docker : les répertoires `images/` et `caches/` des volumes sont rendus accessibles en écriture à Apache
   au démarrage (installation neuve avec docker-compose).

### Passage à la 0.0.23 et à la 0.0.24

Aucune action manuelle pour l'instance : la migration `init-0.0.23.sql` est appliquée automatiquement (Docker) ou
depuis la page « Mises à jour ». Elle ajoute la page **Catégories** de l'admin et la correspondance des catégories des
webhooks. Le champ « Texte de partage par défaut » des scopes est retiré (il servait à Twitter). En 0.0.24, avec Docker
et Watchtower, changez l'image de Watchtower comme indiqué ci-dessus ; la migration `init-0.0.24.sql` (automatique)
permet d'abonner un webhook à plusieurs événements, les webhooks existants restant abonnés à la publication.

### Passage à la 0.0.26

Aucune action manuelle ni migration de base :

- nouvelles variables de webhook `{{photo_full_url}}` (lien signé vers la photo d'origine pour les modérateurs),
  `{{event_label}}` et `{{event_description}}` (description de l'action), voir le CHANGELOG ;
- `{{photo_url}}` change à l'approbation (paramètre `v`) : Slack n'affiche plus la photo pixelisée gardée en cache ;
- une observation liée à plusieurs résolutions n'apparaît plus qu'une fois dans `get_issues.php`, avec le statut le
  plus avancé (une observation résolue n'est plus affichée « en résolution ») ;
- `mosaic.php` est supprimée (la mise à jour depuis l'admin retire le fichier) : l'application web affiche elle-même
  les observations similaires.

Mise à jour depuis le bouton de l'admin.

### Passage à la 0.0.25

Aucune action manuelle ni migration de base : correctif des webhooks (lien de la photo `{{photo_url}}` accessible
avant modération, voir le CHANGELOG). Depuis la 0.0.24, le bouton de mise à jour de l'admin fonctionne avec Docker et
Watchtower.

### Publier une version (mainteneurs)

Voir le [guide du contributeur](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/doc/GUIDE_CONTRIBUTION.md#publier-une-version).

## Mise à jour des versions antérieures à 0.0.22

Avant de mettre à jour désactiver INNODB STRICT MODE en lancant MySQL CLI:

```
SET SESSION innodb_strict_mode=OFF;
```

### Pour chaque mise à jour

#### Mise à jour du code

##### Versions < 0.0.17

* Récupérer et choisir la dernière branche

```
$ git fetch origin
$ git checkout X.X.X
```

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

Exemple : Si votre version est 0.0.12, lancer init-0.0.13.sql puis init-0.0.14.sql puis init-0.0.15.sql ...


### Actions spécifiques

Certaines mises à jour de version necessitent des actions supplémentaires 

#### Mise à jour vers 0.0.13

* Aller sur l'admin https://URL/admin/ puis sur "Observations" et suivre les instructions

