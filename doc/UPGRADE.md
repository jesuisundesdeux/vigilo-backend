## Mise à jour à partir de la version 0.0.22

Depuis la 0.0.22, la base de données est migrée automatiquement :

* **Docker** : au démarrage du conteneur (sauf si `AUTOUPDATE=false`). Le conteneur refuse de démarrer
  si la base est plus récente que le code (retour à une version antérieure).
* **Hébergement mutualisé / serveur dédié** : après avoir mis à jour le code, aller dans l'admin,
  menu « Mises à jour », puis cliquer sur « Appliquer les migrations ».
  Sur un serveur dédié, on peut aussi lancer `php scripts/vigilo-migrate.php`.

Pensez à sauvegarder la base avant chaque mise à jour.

La version du code est définie à un seul endroit : `app/includes/version.php`.
Les migrations sont dans `app/migrations/` (anciennement `mysql/init/`).

### Mise à jour vers 0.0.22 (correctifs de sécurité)

1. Mettre à jour le code (voir ci-dessous) ou l'image Docker.
2. Appliquer les migrations (automatique en Docker, sinon depuis l'admin).
3. **Vider le répertoire `caches/`** : les versions précédentes pouvaient y écrire des
   panneaux non floutés. Les nouveaux fichiers de cache ont un nom différent (`_p2_`).
4. Vérifier que les répertoires `images/` et `caches/` ne sont **pas** accessibles
   depuis le web (`https://VOTRE_URL/images/` doit répondre 403). Sous nginx, les
   `.htaccess` ne sont pas lus, il faut ajouter :

   ```nginx
   location ~ ^/(images|caches|migrations)/ { deny all; return 403; }
   location = /install.php { deny all; return 403; }
   ```

5. Vérifier qu'il ne reste pas de fichier `install.php` à la racine.

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

