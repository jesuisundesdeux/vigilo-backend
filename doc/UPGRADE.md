## Mise à jour
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

Lancer dans l'ordre les fichiers SQL de mysql/init/ correspondant aux versions supérieures à la votre 

Exemple : Si votre version est 0.0.12, lancer init-0.0.13.sql puis init-0.0.14.sql puis init-0.0.15.sql ...


### Actions spécifiques

Certaines mises à jour de version necessitent des actions supplémentaires 

#### Mise à jour vers 0.0.13

* Aller sur l'admin https://URL/admin/ puis sur "Observations" et suivre les instructions

