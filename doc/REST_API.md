Présentation API Vigilo
============

## Vue d'ensemble

Toutes les routes sont à la racine de l'instance, répondent en JSON (sauf images) avec
`Access-Control-Allow-Origin: *` et l'en-tête `BACKEND_VERSION`. Erreurs :
`{"error": {"status", "code", "message"}}` avec le code HTTP correspondant. Le détail de chaque route suit.

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

## Sommaire

 - Workflows
   - [Ajout d'une observation](#ajout-dune-observation)
 - Méthodes
   - Récupération d'informations
     - Configurations
       - [Vérification Acl](#vérification-acl)
       - [Récupération catégories](#récupération-catégories)
       - [Récupération informations scope](#récupération-informations-scope)
     - Observations
       - [Récupération image de l'observation (ex-panel)](#récupération-panel)
       - [Récupération liste observations](#récupération-liste-observations)
       - [Récupération photo originale](#récupération-photo-originale)
   - Ajout/modifications informations
     - Observations
       - [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation)
       - [Approuver observation](#approuver-observation)
       - [Création observation](#création-observation)
       - [Suppression observation](#suppression-observation)
       - [Changer status observation](#changer-status-observation)
 - Données
   - [Catégories](#catégories)
   - [Observations](#observations)
   - [Scope](#scope)
 
       
       
       
     
     
     

___
## Workflows 

### Ajout d'une observation

- [Création observation](#création-observation) : Création de l'entrée et récupération des informations d'identification
- [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation) : Ajout de l'image 
- [Récupération panel](#récupération-panel) : Image de l'observation

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
| str | role | Rôle correspondant à la clé (admin) | >= 0.0.1 |

___

##### Récupération catégories

###### Compatibilité

*LEGACY*

######  Requête

    GET /get_categories_list.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

JSON : Retourne les informations des [Catégories](#catégories).

___

##### Récupération catégories (legacy)

###### Compatibilité

LEGACY

######  Requête

    GET /get_categories.php?

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

___

##### Récupération version backend (legacy)

###### Compatibilité

Version backend <= 0.0.3

######  Requête

    GET /get_version.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

JSON : Retourne la version du backend

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | version | Version du backend | <= 0.0.3 |

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
| URL | int | s | | Largeur de l'image | >= 0.0.1 |
| URL | str | key | | Clé d'admin pour visualisation non pixelisée | >= 0.0.1 |
| URL | str | secretid | | Clé secret de l'observation pour visualisation non pixelisée | >= 0.0.1 |

Depuis la 0.0.22, le « panel » (photo, carte, textes) n'est plus généré : la route renvoie la **photo de
l'observation**, pixelisée tant qu'elle n'est pas approuvée (sauf avec `secretid` ou une clé admin/modérateur),
à la largeur demandée (`s`, 1024 au maximum). Mêmes paramètres, mêmes codes d'erreur, toujours en `image/jpeg`.

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/png | / | Image | <= 0.0.4 |
| image/jpeg | / | Image | >= 0.0.5 |

___

##### Récupération liste observations

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /get_issues.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | int | c | | filtre selon catégorie | >= 0.0.1 |
| URL | int | t | |  filtre selon date | Changé en timestamp à partir de >= 0.0.5 |
| URL | str | scope | X | filtre selon scope  | >= 0.0.1 |
| URL | int | status |  | filtre selon statut de l'observation | >= 0.0.1 |
| URL | int | token |  | filtre selon token de l'observation | >= 0.0.1 |
| URL | str / int | lat / lon / radius |  | filtre selon les coordonnées lat et lon et les observations autour dans la limite de radius  (en mètres)  | >= 0.0.1 |
| URL | str | tokenfilters / fdistance | | Se combine avec "token" pour afficher les observations similaires avec les filtres distance, categorie et/ou address. fdistance (distance est mètre) est à renseigner si distance est utilisé | >= 0.0.9 |
| URL | int | count |  | limite le nombre d'occurences | >= 0.0.1 |
| URL | int | offset |  | démarrage le nombre d'occurence en décallé | >= 0.0.1 |
| URL | str | format |  | format (json,csv,geojson) | >= 0.0.3 |
| URL | int | approved |  | filtre selon approbation) | >= 0.0.10 |
| URL | bool | cityfield | | Affiche la ville dans un champs dédié plutôt que dans l'adresse | >= 0.0.13 |
| URL | int | cityid | | filtre selon id de la ville | >= 0.0.13 |
| URL | str | key | | Clé d'admin pour donner accès à toutes les observations | >= 0.0.13 |
| URL | int | since | | nombre d'unités pour le filtre relatif sur la date | >= 0.0.19 |
| URL | str | since_unit | | unité pour le filtre relatif sur la date, valeur parmi : day, week, month, year  | >= 0.0.19 |


###### Retour

JSON : Retourne la liste des [observations](#observations-2).

___

##### Récupération photo originale

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /get_photo.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | X | Clé privé de l'utilisateur | >= 0.0.1 |
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | str | type |  | Type d'image (resolution/obs) | >= 0.0.14 |

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/jpeg | / | Image | >= 0.0.1 |

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
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | str | secretid | X | Clé secrète de l'observation | >= 0.0.1 |
| URL | str | type |  | Type d'image (resolution/obs) | >= 0.0.14 |
| RAW | image/jpeg | / | X | Flux de l'image en JPEG si method=stdin | >= 0.0.1 |
| URL | str | method | | Methode d'upload d'image (par defaut stdin pour upload en RAW / base64 pour upload en base64 dans le champs imagebin64) | >= 0.0.16 |
| POST | JPEG base64 |  imagebin64 | | Image encodée en base64 (formulaire, ou corps JSON `{"imagebin64": "..."}` depuis 0.0.22, préfixe `data:image/jpeg;base64,` accepté) | >= 0.0.16 |
| URL | str | key | | Clé admin/modérateur : nécessaire pour remplacer la photo d'une observation déjà approuvée | >= 0.0.22 |

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

Erreurs ajoutées en 0.0.22 : `ALREADYAPPROVED` (403, l'observation est approuvée : sa photo ne peut plus être remplacée sans clé).

Si un serveur de floutage est configuré (réglage « Serveur de floutage » ou variable `VIGILO_BLUR_URL`, voir `blur-server/`), la photo enregistrée est celle renvoyée par ce serveur, visages et plaques d'immatriculation masqués (en-tête de réponse `X-Vigilo-Blur: done`). Si le serveur échoue, la photo est enregistrée telle qu'envoyée (`X-Vigilo-Blur: failed`) et la réponse reste un succès : la modération manuelle s'en charge.


###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le statut de l'appel  | >= 0.0.1 / < 0.0.10 |

___

##### Approuver observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    POST /approve.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé privé de l'utilisateur | >= 0.0.1 |
| POST | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |
| POST | int | approved | | 0 => A approuver / 1 => Approuvé / 2 => Désapprouvé | >= 0.0.1 |

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
|  |  | |  |

___

##### Création observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    POST /create_issue.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé privé de l'utilisateur | >= 0.0.1 |
| POST | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |
| POST | str | coordinates_lat' | création | Latitude de l'observation | >= 0.0.1 |
| POST | str | coordinates_lon | création | Longitude de l'observation | >= 0.0.1 |
| POST | str | comment | non | Remarque de l'observation (max 50 caractères) | >= 0.0.1 |
| POST | str | explanation | non | Explications observation | >= 0.0.1 |
| POST | str | categorie | création | ID de catégorie | >= 0.0.1 |
| POST | str | address | création | Adresse de l'observation | >= 0.0.1 |
| POST | str | time | création |  Timestamp de l'observation au format Unix en ms | >= 0.0.1 |
| POST | str | version | création | Version de l'application cliente | >= 0.0.1 |
| POST | str | scope | création | Identifiant du scope | >= 0.0.1 |
| POST | str | cityid | création | Identifiant de la ville | >= 0.0.13 |
| POST | str | cityname | création | Nom de la ville | >= 0.0.13 |


###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Retourne le token généré | >= 0.0.1 |
| str | secretid | Retourne la clé secrete de l'observation | >= 0.0.1 |
| int | group | LEGACY | LEGACY |
___

##### Créer résolution

##### Compatibilité

Version backend >= 0.0.14

######  Requête

    POST /create_resolution.php
    
###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token |  | Token de la résolution | >= 0.0.14 |
| POST | str | comment | | Commentaire de résolution (max 50 chars) | >= 0.0.14 |
| POST | int | time | X | Timestamp format Unix | >= 0.0.14 |
| POST | str | tokenlist | X | Liste, séparée par une virgule des tokens d'observations | >= 0.0.14 |
| POST | str | version | | Version du client | >= 0.0.14 |

###### Retour

JSON : Retourne les informations d'identification de la résolution

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Retourne le token généré | >= 0.0.1|
| str | secretid | Retourne la clé secrete de l'observation | >= 0.0.14 |
___

##### Suppression observation

###### Compatibilité

Version backend >= 0.0.1

######  Requête

    GET /delete.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | Si secretid non fourni | | Clé privé de l'utilisateur | >= 0.0.1 |
| URL | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |
| URL | str | secretid | Si key non fourni | | Clé secrète de l'observation >= 0.0.1 |


###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
|  |  |  |  |

___

##### Obtenir liste observations en CSV (legacy)

###### Compatibilité

Version backend <= 0.0.5

######  Requête

    GET /to_csv.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

CSV : Retourne les informations d'identification de l'observation




##### Changer status observation

###### Compatibilité

*LEGACY*

######  Requête

    GET /update_status.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | Si secretid non fourni | Clé privé de l'utilisateur | >= 0.0.5 |
| URL | str | token | X | Token de l'observation | >= 0.0.5 |
| URL | str | secretid | Si key non fourni | Clé secrète de l'observation | >= 0.0.5 |
| URL | int | statusobs | X | Status à appliquer (0: non résolu / 1 : résolu) | >= 0.0.5 |
| POST | str | comment | | Commentaire de résolution (max 50 chars) | >= 0.0.10 |
| POST | int | time | | Timestamp format Unix | >= 0.0.10 |

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
|  |  |   |  |

___



## Données


### Catégories

Les catégories sont disponibles sur toutes les instance sur l'adresse https://vigilo-bf7f2.firebaseio.com/categorieslist.json

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| int | catid | Identifiant unique de catégorie | >= 0.0.1 |
| str | catname | Nom affiché de la catégorie | >= 0.0.1 |

### Observations

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | token | Token de l'observation | >= 0.0.1 |
| str | coordinate_lat| Latitude de l'observation en dégré décimal | >= 0.0.1 |
| str | coordinate_lon | Longitude de l'observation en dégré décimal  | >= 0.0.1 |
| str | address | Adresse de l'observation | >= 0.0.1 |
| str | comment | Remarque de l'observation | >= 0.0.1 |
| str | explanation | Explications de l'observation  | >= 0.0.1 |
| int | time | Timestamp (en secondes) de l'observation | >= 0.0.1 |
| int | status | Statut de l'observation (voir "Status des observations") | >= 0.0.6 |
| int | group | Groupe de l'observation | LEGACY |
| int | categorie | Identifiant de catégorie de l'obseration | >= 0.0.1 |
| int | approved | Etat d'approbation de l'observation | >= 0.0.1 |
| int | cityname | Nom de la ville (si existe, n'est pas affiché dans l'adresse) | >= 0.0.13 |

### Scope

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | display_name | Nom affiché du scope dans Vigilo | >= 0.0.5 |
| str | coordinate_lat_min| Latitude minimum de la zone en dégré décimal | >= 0.0.5 |
| str | coordinate_lat_max | Latitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_min | Longitude minimum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_max | Longitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | map_center_string | Latitude + "," + Longitude du centre de la carte qui doit être affichée | >= 0.0.5 |
| int | map_zoom | Zoom de la carte à afficher | >= 0.0.5 |
| str | contact_email | Adresse mail de contact du scope  | >= 0.0.5 |
| str | tweet_content | Texte proposé par défaut par le composant de partage de l'application | >= 0.0.5 |
| str | map_url | Adresse de la carte où sont affichées les observations| >= 0.0.5 |
| str | nominatim_urlbase | URL base du service nominatim | >= 0.0.14 |
| str | backend_version | Version du backend| >= 0.0.5 |

### Status des observations

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| int | status | 0 => Nouvelle observation <br> 1 => Observation résolue <br> 2 => Prise en compte <br> 3 => En cours de résolution <br> 4 => Indiquée comme résolue | >= 0.0.10 |

___

## Limitation des créations (depuis 0.0.22)

`create_issue.php` et `create_resolution.php` refusent les nouvelles créations au-delà d'un nombre
par adresse IP et par tranche de 10 minutes (réglage « Anti-spam » de l'admin, 60 par défaut, 0 pour
désactiver). Réponse : HTTP 429, code `RATELIMITED`, en-tête `Retry-After`. Les requêtes faites avec une
clé admin ou modérateur ne sont pas limitées.

## Observations résolues anciennes (depuis 0.0.22)

Si le réglage « Masquer les observations résolues depuis plus de N jours » est activé (désactivé par
défaut), `get_issues.php` ne renvoie plus ces observations, sauf avec une clé admin ou modérateur.
