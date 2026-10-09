Présentation API Vigilo
============

## Vue d'ensemble

Toutes les routes sont à la racine de l'instance et répondent en JSON (sauf images et `mosaic.php`) avec l'en-tête
`BACKEND_VERSION` et `Access-Control-Allow-Origin: *` (sauf `get_version.php`, `generate_panel.php` et `mosaic.php`).
Erreurs : `{"error": {"status", "code", "message"}}` avec le code HTTP correspondant. Le détail de chaque route suit.

Authentification :
- `key` : clé privée d'un compte (`admin`, `moderator` ou `citystaff`, voir [acl.php](#vérification-acl)). Les droits
  supplémentaires de l'API publique sont réservés aux clés admin et modérateur ; une clé citystaff n'en donne aucun
  (les comptes citystaff travaillent dans l'admin, limités aux observations de leurs villes depuis 0.0.22).
- `secretid` : clé secrète d'une observation (ou d'une résolution), retournée à sa création ; elle identifie son auteur.

| Route | Rôle | Authentification |
|---|---|---|
| `GET get_version.php` | Version du backend | — |
| `GET get_scope.php?scope=` | Informations d'un scope (carte, contact, villes) | — |
| `GET get_categories.php` | Catégories de l'instance (depuis 0.0.23, voir [Catégories](#catégories)) | — |
| `GET get_issues.php` | Liste des observations ; filtres `scope`, `c` (catégories), `status`, `approved`, `token`, `tokenfilters`/`fdistance`, `lat`/`lon`/`radius`, `cityid`, `since`/`since_unit`, `count`, `t` ; formats `json`, `csv`, `geojson` | `key` admin/modérateur pour les non approuvées et les résolues masquées |
| `GET get_photo.php?token=` | Photo d'une observation (`type=resolution` pour une résolution) | approuvée, ou `key` admin/modérateur, ou lien signé `exp` + `sig` (0.0.25) |
| `GET generate_panel.php?token=` | Ancien « panneau » : la photo, pixelisée tant qu'elle n'est pas approuvée ; largeur `s` (1024 max.) | `secretid` de l'auteur ou `key` admin/modérateur pour la version nette |
| `GET mosaic.php` | Page HTML en mosaïque des photos (`scope`, `c`, `t`) | — |
| `GET acl.php?key=` | Rôle associé à une clé | `key` |
| `POST create_issue.php` | Crée une observation (ou la modifie avec `token` + `key`) | anti-spam par IP, sauf `key` admin/modérateur |
| `POST add_image.php?token=&secretid=` | Photo de l'observation (corps brut, ou `method=base64` en formulaire/JSON) ; `type=resolution` pour une résolution | `secretid` (+ `key` admin/modérateur si approuvée) |
| `GET approve.php?token=&key=` | Approuve (`approved=1`, par défaut), remet à modérer (`approved=0`) ou refuse (`approved=2`) ; déclenche les [webhooks](WEBHOOKS.md) | `key` admin/modérateur |
| `GET delete.php?token=` | Supprime une observation | `secretid` ou `key` admin/modérateur |
| `POST create_resolution.php` | Déclare la résolution d'observations (`tokenlist`) | anti-spam par IP, sauf `key` admin |

Workflow type d'une application : `create_issue.php` → `add_image.php` → (modération) → `get_issues.php`
et `get_photo.php`. La compatibilité de ces réponses avec les applications existantes est vérifiée en CI
(tests de contrat, voir `tests/api/`).

## Sommaire

 - Workflows
   - [Ajout d'une observation](#ajout-dune-observation)
 - Méthodes
   - Récupération d'informations
     - Configurations
       - [Vérification Acl](#vérification-acl)
       - [Récupération catégories](#récupération-catégories)
       - [Récupération informations scope](#récupération-informations-scope)
       - [Récupération version backend](#récupération-version-backend)
     - Observations
       - [Récupération image de l'observation (ex-panel)](#récupération-panel)
       - [Récupération liste observations](#récupération-liste-observations)
       - [Récupération photo originale](#récupération-photo-originale)
       - [Mosaïque](#mosaïque)
   - Ajout/modifications informations
     - Observations
       - [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation)
       - [Approuver observation](#approuver-observation)
       - [Création observation](#création-observation)
       - [Créer résolution](#créer-résolution)
       - [Suppression observation](#suppression-observation)
     - [Routes supprimées](#routes-supprimées)
 - Données
   - [Catégories](#catégories)
   - [Observations](#observations-2)
   - [Scope](#scope)
   - [Status des observations](#status-des-observations)
 - [Limitation des créations](#limitation-des-créations-depuis-0022)
 - [Observations résolues anciennes](#observations-résolues-anciennes-depuis-0022)

___
## Workflows 

### Ajout d'une observation

- [Création observation](#création-observation) : Création de l'entrée et récupération des informations d'identification (`token`, `secretid`)
- [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation) : Ajout de l'image ; l'observation n'est listée par `get_issues.php` qu'une fois sa photo reçue
- [Récupération panel](#récupération-panel) : Image de l'observation (nette pour l'auteur avec `secretid`)

## Méthodes 

### Récupération d'informations

#### Configurations

___
##### Vérification ACL

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /acl.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | X |  Clé privé de l'utilisateur | >= 0.0.1 |

###### Retour

JSON : Retourne les informations suivantes :

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str / bool | role | Rôle correspondant à la clé (`admin`, `moderator`, `citystaff`), `false` si la clé est inconnue | >= 0.0.1 |

Erreur : `KEYNOTPROVIDED` (400) sans `key`.

___

##### Récupération catégories

###### Compatibilité

Version backend >= 0.0.23

######  Requête

    GET /get_categories.php

###### Arguments

Aucun.

###### Retour

JSON : Retourne la liste des [Catégories](#catégories) de l'instance (tableau). En-têtes
`Cache-Control: public, max-age=300` et `Access-Control-Allow-Origin: *`.

Sur une instance antérieure à 0.0.23, la route n'existe pas (404) : utiliser la liste nationale (voir
[Catégories](#catégories)).

___

##### Récupération informations scope

###### Compatibilité

Version backend >= 0.0.4

######  Requête

    GET /get_scope.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | scope | X |  Nom du scope | >= 0.0.4 |

###### Retour

JSON : Retourne les informations du [Scope](#scope).

Erreurs : `PARAMNOTDEFINED` (400) sans `scope`, `SCOPENOTEXIST` (404) si le scope n'existe pas.

___

##### Récupération version backend

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /get_version.php

###### Arguments

Aucun.

###### Retour

JSON : Retourne la version du backend (sans en-tête `Access-Control-Allow-Origin` ; la version est aussi dans
`backend_version` de `get_scope.php` et dans l'en-tête `BACKEND_VERSION` de toutes les routes).

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | version | Version du backend, par exemple `0.0.25` | >= 0.0.1 |

___

#### Observations

##### Récupération panel

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /generate_panel.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | int | s | | Largeur maximale de l'image (1 à 1024, 1024 par défaut ou si la valeur est invalide) | >= 0.0.1 |
| URL | str | key | | Clé admin/modérateur pour visualisation non pixelisée | >= 0.0.1 |
| URL | str | secretid | | Clé secrète de l'observation pour visualisation non pixelisée | >= 0.0.1 |

Depuis la 0.0.22, le « panel » (photo, carte, textes) n'est plus généré : la route renvoie la **photo de
l'observation**, pixelisée tant qu'elle n'est pas approuvée (sauf avec le bon `secretid` ou une clé admin/modérateur),
réduite pour tenir dans `s` × 1024 pixels. Mêmes paramètres, toujours en `image/jpeg`. Le rendu public est mis en
cache ; le rendu net (auteur, modérateur) ne l'est jamais.

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/png | / | Image | <= 0.0.4 |
| image/jpeg | / | Image | >= 0.0.5 |

Erreurs (JSON, envoyé avec `Content-Type: image/jpeg` sauf `PHOTONOTFOUND`) :

| HTTP | Code | Cas |
| ---- | ---- | --- |
| 400 | TOKENNOTPROVIDED | `token` absent |
| 404 | TOKENNOTFOUND | Observation inconnue |
| 404 | PHOTONOTFOUND | Observation sans photo (>= 0.0.23) : les applications affichent une image par défaut |
| 500 | IMAGENOTREADABLE | Photo illisible |

___

##### Récupération liste observations

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /get_issues.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | scope | | filtre selon scope (sans `scope`, ou avec `34_montpellier`, toutes les observations de l'instance) | >= 0.0.1 |
| URL | str | c | | filtre selon catégorie ; plusieurs identifiants séparés par des virgules | >= 0.0.1 |
| URL | int | t | | observations postérieures à ce timestamp Unix (secondes) | Changé en timestamp à partir de >= 0.0.5 |
| URL | int | status |  | filtre selon statut de l'observation (voir [Status des observations](#status-des-observations)) | >= 0.0.1 |
| URL | str | token |  | filtre selon token de l'observation | >= 0.0.1 |
| URL | float / int | lat / lon / radius |  | observations à moins de `radius` mètres du point `lat`,`lon` (les trois sont nécessaires) ; ajoute le champ `distance` | >= 0.0.1 |
| URL | str / int | tokenfilters / fdistance | | Avec `token` : observations similaires selon les filtres `distance`, `categorie` et/ou `address` séparés par des virgules ; `fdistance` (mètres) est obligatoire avec `distance`. Ignoré avec `lat`/`lon`/`radius` | >= 0.0.9 |
| URL | int | count |  | limite le nombre d'occurences (appliqué avant les filtres `status`, `lat`/`lon`/`radius` et `tokenfilters`) | >= 0.0.1 |
| URL | int | offset |  | Ignoré : le paramètre n'est pas lu (pas de pagination) | — |
| URL | str | format |  | format (json,csv,geojson), `json` par défaut | >= 0.0.3 |
| URL | int | approved |  | filtre selon approbation (0, 1, 2). Sans clé admin/modérateur, seul `1` est accepté, et `0` si l'instance publie les observations non modérées ; sinon la liste est vide | >= 0.0.10 |
| URL | int | cityfield | | `1` : la ville n'est plus ajoutée à l'adresse (elle reste dans `cityname`) | >= 0.0.13 |
| URL | int | cityid | | filtre selon id de la ville | >= 0.0.13 |
| URL | str | key | | Clé admin/modérateur : observations non approuvées (0) en plus des approuvées, et observations résolues masquées | >= 0.0.13 |
| URL | int | since | | nombre d'unités pour le filtre relatif sur la date (avec `since_unit`) | >= 0.0.19 |
| URL | str | since_unit | | unité pour le filtre relatif sur la date, valeur parmi : day, week, month, year  | >= 0.0.19 |

Sans `approved`, la liste contient les observations approuvées, plus celles à modérer si le réglage « Afficher les
observations non modérées » est activé ou avec une clé admin/modérateur ; les observations refusées (2) ne sont listées
qu'avec `approved=2` et une clé. Seules les observations dont la photo a été envoyée sont listées, les plus récentes
d'abord. Voir aussi [Observations résolues anciennes](#observations-résolues-anciennes-depuis-0022).

Un paramètre invalide (`format` inconnu, valeur non numérique pour `count`, `status`, `t`, `since`, `approved`,
`cityid`, ou `since_unit` inconnue) donne une erreur HTTP 500 sans corps JSON.

###### Retour

- `json` : tableau d'[observations](#observations-2).
- `geojson` : `FeatureCollection` de points (`[lon, lat]`), propriétés `name` (token + commentaire), `description`
  (URL de l'image `generate_panel.php` entre `{{ }}` + explications), `token`, `address`, `comment`, `explanation`,
  `time`, `group`, `categorie`, `approved`.
- `csv` (`text/csv`) : une ligne d'en-tête (champs de la première observation) puis une ligne par observation ; les
  virgules des valeurs sont remplacées par `_` et les retours à la ligne par des espaces. Réponse vide s'il n'y a
  aucune observation.

___

##### Récupération photo originale

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /get_photo.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation (ou de la résolution avec `type=resolution`) | >= 0.0.1 |
| URL | str | key |  | Clé admin/modérateur, nécessaire si l'observation n'est pas approuvée | >= 0.0.1 |
| URL | str | type |  | Type d'image (`obs` par défaut, `resolution`) | >= 0.0.14 |
| URL | int | exp |  | Lien signé (variable `{{photo_full_url}}` des webhooks) : date d'expiration (timestamp Unix) | >= 0.0.25 |
| URL | str | sig |  | Lien signé : signature HMAC-SHA256 de `token\|exp` par un secret de l'instance ; photo d'origine servie même non approuvée tant que le lien n'a pas expiré | >= 0.0.25 |

Les photos de résolution et des observations approuvées sont publiques ; une observation non approuvée n'est
servie qu'avec une clé admin/modérateur (pas de version pixelisée : utiliser `generate_panel.php`).

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/jpeg | / | Image (l'en-tête envoyé est `Content-Type: image/png`) | >= 0.0.1 |

Erreurs (JSON) :

| HTTP | Code | Cas |
| ---- | ---- | --- |
| 400 | MISSINGARGUMENT | `token` absent |
| 404 | TOKENNOTFOUND | Observation ou résolution inconnue |
| 404 | PHOTONOTFOUND | Pas de photo (>= 0.0.23, comme `generate_panel.php`), ou `type` inconnu : les applications affichent une image par défaut |
| 403 | NOTALLOWED | Observation non approuvée, sans clé admin/modérateur |

___

##### Mosaïque

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /mosaic.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | scope | | Scope : si l'instance est dans `citylist.json`, chaque photo renvoie vers l'application web, sinon vers l'image | |
| URL | int | c | | Catégorie à afficher | |
| URL | str | t | | Token : affiche les observations similaires (300 m, même catégorie, même adresse) | |

###### Retour

Page HTML : images `generate_panel.php` (`s=400`) des observations publiques (mêmes règles que `get_issues.php` sans
paramètre, toutes les observations de l'instance).

___

### Ajout/modifications informations

#### Observations

##### Ajout d'une image à l'observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    POST /add_image.php?
    
###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation (ou de la résolution) | >= 0.0.1 |
| URL | str | secretid | X | Clé secrète de l'observation (ou de la résolution) | >= 0.0.1 |
| URL | str | type |  | Type d'image (`obs` par défaut, `resolution`) | >= 0.0.14 |
| RAW | image/jpeg | / | X | Flux de l'image en JPEG si method=stdin | >= 0.0.1 |
| URL | str | method | | Methode d'upload d'image (par defaut stdin pour upload en RAW / base64 pour upload en base64 dans le champs imagebin64) | >= 0.0.16 |
| POST / JSON | JPEG base64 |  imagebin64 | | Image encodée en base64 (formulaire, ou corps JSON `{"imagebin64": "..."}` depuis 0.0.22, préfixe `data:image/jpeg;base64,` accepté) | >= 0.0.16 |
| URL | str | key | | Clé admin/modérateur : nécessaire (avec `secretid`) pour remplacer la photo d'une observation déjà approuvée | >= 0.0.22 |

Seul le JPEG est accepté. La photo est réduite à 1024 × 1024 pixels au plus ; elle remplace la précédente. Une
requête `OPTIONS` (pré-vérification CORS) reçoit une réponse vide.

###### Exemples (#267)

Envoi brut (méthode par défaut) :

    curl -X POST --data-binary @photo.jpg \
      "https://INSTANCE/add_image.php?token=TOKEN&secretid=SECRETID"

Envoi en base64 (formulaire) :

    curl -X POST --data-urlencode "imagebin64=$(base64 -w0 photo.jpg)" \
      "https://INSTANCE/add_image.php?token=TOKEN&secretid=SECRETID&method=base64"

Envoi en base64 (JSON, depuis 0.0.22) :

    curl -X POST -H "Content-Type: application/json" \
      -d "{\"imagebin64\": \"$(base64 -w0 photo.jpg)\"}" \
      "https://INSTANCE/add_image.php?token=TOKEN&secretid=SECRETID&method=base64"

Si un serveur de floutage est configuré (réglage « Serveur de floutage » ou variable `VIGILO_BLUR_URL`, voir `blur-server/`), la photo enregistrée est celle renvoyée par ce serveur, visages et plaques d'immatriculation masqués (en-tête de réponse `X-Vigilo-Blur: done`). Si le serveur échoue, la photo est enregistrée telle qu'envoyée (`X-Vigilo-Blur: failed`) et la réponse reste un succès : la modération manuelle s'en charge.

###### Retour

JSON : `{"status": 0}`

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| int | status | Toujours 0, conservé pour les anciennes applications : le code HTTP fait foi | >= 0.0.1 |

Erreurs :

| HTTP | Code | Cas |
| ---- | ---- | --- |
| 400 | MISSINGARGUMENT | `token` ou `secretid` absent, ou `type` inconnu |
| 400 | TOKENNOTEXIST | `token` / `secretid` d'observation incorrects |
| 400 | RESOLTOKENNOTEXIST | `token` / `secretid` de résolution incorrects |
| 403 | ALREADYAPPROVED | Observation approuvée : sa photo ne peut plus être remplacée sans clé admin/modérateur (>= 0.0.22) |
| 400 | FILETYPENOTSUPPORTED | L'image n'est pas un JPEG |
| 500 | FILECORRUPTED | Image corrompue ou plus petite que 50 × 50 pixels |
| 500 | IMAGEUPLOADFAILED | Corps vide ou image non enregistrée |

___

##### Approuver observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /approve.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | X | Clé admin ou modérateur | >= 0.0.1 |
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | int | approved | | 0 => A approuver / 1 => Approuvé (par défaut) / 2 => Désapprouvé | >= 0.0.1 |

Quand l'observation passe à l'état approuvé, les webhooks configurés dans l'admin sont appelés après la réponse
(depuis 0.0.22, voir [WEBHOOKS.md](WEBHOOKS.md)).

###### Retour

JSON : `{"status": "0"}` (chaîne)

Erreurs : `ACCESSDENIED` (403) sans clé admin/modérateur, `TOKENNOTEXISTS` (400) si l'observation n'existe pas.

___

##### Création observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    POST /create_issue.php?

###### Arguments

Champs `POST` en formulaire (`application/x-www-form-urlencoded` ou `multipart/form-data`).

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé admin/modérateur (modification, pas de limitation anti-spam) | >= 0.0.1 |
| POST | str | token | Uniquement en cas de modif | Token de l'observation à modifier (avec `key`), ignoré sinon | >= 0.0.1 |
| POST | str | coordinates_lat | X | Latitude de l'observation, dans le rectangle du scope | >= 0.0.1 |
| POST | str | coordinates_lon | X | Longitude de l'observation, dans le rectangle du scope | >= 0.0.1 |
| POST | str | comment | | Remarque de l'observation (tronquée à 50 caractères, emojis retirés) | >= 0.0.1 |
| POST | str | explanation | | Explications observation (emojis retirés) | >= 0.0.1 |
| POST | int | categorie | X | ID de catégorie | >= 0.0.1 |
| POST | str | address | X | Adresse de l'observation (`rue, ville` : la ville est extraite si `cityid`/`cityname` sont absents) | >= 0.0.1 |
| POST | int | time | X | Timestamp Unix de l'observation, en ms (13 chiffres) ou en secondes | >= 0.0.1 |
| POST | str | version | | Version de l'application cliente | >= 0.0.1 |
| POST | str | scope | X | Identifiant du scope | >= 0.0.1 |
| POST | int | cityid | | Identifiant de la ville (ignoré s'il est inconnu) | >= 0.0.13 |
| POST | str | cityname | | Nom de la ville, si `cityid` est absent (rattaché à une ville connue du même nom) | >= 0.0.13 |

En modification (clé admin/modérateur et `token` existant), tous les champs obligatoires sont renvoyés ; le scope et
la version ne changent pas.

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Retourne le token généré | >= 0.0.1 |
| str | secretid | Retourne la clé secrete de l'observation | >= 0.0.1 |
| int | status | Toujours 0 | LEGACY |
| int | group | Toujours 0 | LEGACY |

Erreurs :

| HTTP | Code | Cas |
| ---- | ---- | --- |
| 400 | PARAMNOTDEFINED | Champ obligatoire absent |
| 400 | PARAMEMPTY | Champ obligatoire vide (sauf `scope`) |
| 400 | UNKNOWSCOPE | Scope inconnu |
| 403 | COORDINATESNOTALLOWED | Coordonnées hors du rectangle du scope |
| 429 | RATELIMITED | Trop de créations depuis cette IP (>= 0.0.22, voir [Limitation des créations](#limitation-des-créations-depuis-0022)) |
| 500 | MYSQLERROR | Erreur d'enregistrement |

___

##### Créer résolution

###### Compatibilité

Version backend >= 0.0.14

######  Requête

    POST /create_resolution.php
    
###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé admin : pas de limitation anti-spam | >= 0.0.22 |
| POST | str | tokenlist | X | Liste, séparée par une virgule des tokens d'observations (tokens inconnus ignorés) | >= 0.0.14 |
| POST | int | time | X | Timestamp Unix, en ms (13 chiffres) ou en secondes | >= 0.0.14 |
| POST | str | comment | | Commentaire de résolution (tronqué à 50 caractères, emojis retirés) | >= 0.0.14 |
| POST | str | version | | Version du client | >= 0.0.14 |

La résolution est créée avec le statut 4 (« indiquée comme résolue »), à valider dans l'admin. Sa photo s'envoie
ensuite avec `add_image.php?type=resolution` et le `token` / `secretid` de la résolution.

###### Retour

JSON : Retourne les informations d'identification de la résolution

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Retourne le token généré (`R_` + 8 caractères) | >= 0.0.14 |
| str | secretid | Retourne la clé secrete de la résolution | >= 0.0.14 |

Erreurs : `PARAMNOTDEFINED` (400) si `tokenlist` ou `time` manque, `RATELIMITED` (429, >= 0.0.22),
`FUNCTIONERROR` (500) si aucun token de `tokenlist` n'existe, `MYSQLERROR` (500).

___

##### Suppression observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /delete.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | str | secretid | Si key non fourni | Clé secrète de l'observation | >= 0.0.1 |
| URL | str | key | Si secretid non fourni | Clé admin ou modérateur | >= 0.0.1 |

L'observation et sa photo sont supprimées.

###### Retour

JSON : `{"status": 0}`

Erreur : `TOKENNOTPROVIDED` (400) si l'observation n'existe pas ou si `secretid` est incorrect.

___

##### Routes supprimées

| Route | Supprimée en | Remplacée par |
| ----- | ------------ | ------------- |
| `update_status.php` | 0.0.14 | `create_resolution.php` et l'admin |
| `get_categories_list.php` | 0.0.16 | Liste nationale de vigilo-conf, puis `get_categories.php` (>= 0.0.23) |
| `to_csv.php` | 0.0.16 | `get_issues.php?format=csv` |

___



## Données


### Catégories

La liste nationale, commune à toutes les instances, est publiée dans
[vigilo-conf](https://raw.githubusercontent.com/jesuisundesdeux/vigilo-conf/main/main/categorielist.json).

Depuis la 0.0.23, chaque instance publie sa propre liste sur `GET /get_categories.php`, au même format : la liste
nationale, où les catégories désactivées par l'instance ont `"catdisable": true` (elles restent listées pour nommer les
observations existantes), suivie des catégories ajoutées par l'instance (`"catcustom": true`, numéros à partir de 1000).
L'instance garde la liste nationale en cache une heure (dernière copie conservée si GitHub ne répond pas).
Une application qui reçoit une erreur sur cette adresse (instance antérieure à 0.0.23) utilise la liste nationale.

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| int | catid | Identifiant unique de catégorie | >= 0.0.1 |
| str | catname | Nom affiché de la catégorie | >= 0.0.1 |
| str | catname_en_US | Nom en anglais (facultatif) | |
| str | catcolor | Couleur (nom CSS ou #rrggbb) | |
| bool | catresolvable | Les citoyens peuvent déclarer l'observation résolue | |
| bool | catdisable | Catégorie à ne plus proposer (facultatif, absent sinon) | |
| bool | catcustom | Catégorie propre à l'instance (facultatif, absent sinon) | >= 0.0.23 |

### Observations

Valeurs de la base renvoyées en chaînes, sauf `status`, `group` et `distance`.

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Token de l'observation | >= 0.0.1 |
| str | coordinates_lat | Latitude de l'observation en dégré décimal | >= 0.0.1 |
| str | coordinates_lon | Longitude de l'observation en dégré décimal  | >= 0.0.1 |
| str | address | Adresse de l'observation (suivie de `, ville` sauf avec `cityfield=1`) | >= 0.0.1 |
| str | comment | Remarque de l'observation | >= 0.0.1 |
| str | explanation | Explications de l'observation  | >= 0.0.1 |
| str | time | Timestamp (en secondes) de l'observation | >= 0.0.1 |
| int | status | Statut de résolution de l'observation (voir "Status des observations") | >= 0.0.6 |
| int | group | Toujours 0 | LEGACY |
| str | categorie | Identifiant de catégorie de l'obseration | >= 0.0.1 |
| str | approved | Etat d'approbation : 0 à modérer, 1 approuvée, 2 refusée | >= 0.0.1 |
| str | cityname | Nom de la ville (absent si inconnu) | >= 0.0.13 |
| float | distance | Distance en mètres au point `lat`/`lon` (uniquement avec `lat`/`lon`/`radius`) | >= 0.0.1 |

### Scope

Valeurs de la base renvoyées en chaînes.

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | display_name | Nom affiché du scope dans Vigilo | >= 0.0.5 |
| str | department | Numéro de département | >= 0.0.8 |
| str | coordinate_lat_min| Latitude minimum de la zone en dégré décimal | >= 0.0.5 |
| str | coordinate_lat_max | Latitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_min | Longitude minimum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_max | Longitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | map_center_string | Latitude + "," + Longitude du centre de la carte qui doit être affichée | >= 0.0.5 |
| str | map_zoom | Zoom de la carte à afficher | >= 0.0.5 |
| str | contact_email | Adresse mail de contact du scope  | >= 0.0.5 |
| str | tweet_content | Texte de partage par défaut (valeur existante, plus modifiable depuis 0.0.23) | >= 0.0.5 |
| str | twitter | Compte Twitter (valeur existante, plus modifiable depuis 0.0.22) | >= 0.0.8 |
| str | map_url | Adresse de la carte où sont affichées les observations| >= 0.0.5 |
| str | nominatim_urlbase | URL base du service nominatim | >= 0.0.14 |
| str | backend_version | Version du backend| >= 0.0.5 |
| array | cities | Villes du scope, triées par nom : `id`, `name`, `postcode`, `area`, `population`, `website` | >= 0.0.8 |

### Status des observations

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| int | status | 0 => Nouvelle observation <br> 1 => Observation résolue <br> 2 => Prise en compte <br> 3 => En cours de résolution <br> 4 => Indiquée comme résolue | >= 0.0.10 |

___

## Limitation des créations (depuis 0.0.22)

`create_issue.php` et `create_resolution.php` refusent les nouvelles créations au-delà d'un nombre de requêtes
par adresse IP sur les 10 dernières minutes (réglage « Anti-spam » de l'admin, `vigilo_ratelimit_create`, 60 par
défaut, 0 pour désactiver ; compté séparément pour chaque route, requêtes refusées comprises). Réponse : HTTP 429,
code `RATELIMITED`, en-tête `Retry-After: 600`. Ne sont pas limitées : sur `create_issue.php`, les requêtes avec une
clé admin ou modérateur ; sur `create_resolution.php`, celles avec une clé admin.

## Observations résolues anciennes (depuis 0.0.22)

Si le réglage « Masquer les observations résolues depuis plus de N jours » (`vigilo_resolved_hide_days`) est
supérieur à 0 (0 par défaut), `get_issues.php` (et donc `mosaic.php`) ne renvoie plus les observations dont la
résolution (statut 1) date de plus de N jours, sauf avec une clé admin ou modérateur.
