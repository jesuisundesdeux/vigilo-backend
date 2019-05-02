Présentation API Vigilo
============

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
       - [Récupération panel](#récupération-panel)
       - [Récupération liste observations](#récupération-liste-observations)
       - [Récupération photo originale](#récupération-photo-originale)
   - Ajout/modifications informations
     - Observations
       - [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation)
       - [Approuver observation](#approuver-observation)
       - [Création observation](#création-observation)
       - [Suppression observation](#suppression-observation)
       - [Changer status observation](#changer-status-observation)
       - [Obtenir carte en cache](#obtenir-carte-en-cache)
 - Données
   - [Catégories](#catégories)
   - [Observations](#observations)
   - [Scope](#scope)
   - [Status](#status)
 
       
       
       
     
     
     

___
## Workflows 

### Ajout d'une observation

- [Création observation](#création-observation) : Création de l'entrée et récupération des informations d'identification
- [Ajout d'une image à l'observation](#ajout-dune-image-à-lobservation) : Ajout de l'image 
- [Récupération panel](#récupération-panel) : Génération du panel

___

## Méthodes 

### Récupération d'informations

#### Configurations

___
##### Vérification ACL

**Version backend** : >= 0.0.1

    GET /acl.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | X |  Clé privé de l'utilisateur | >= 0.0.1 |

###### Retour

JSON : Retourne les informations suivantes :

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.1 |
| str | role | Rôle correspondant à la clé (admin) | >= 0.0.1 |

___

##### Récupération catégories

| Version backend |
| ------- |
| >= 0.0.1 |

    GET /get_categories_list.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

JSON : Retourne les informations des [Catégories](#categories).

___

##### Récupération catégories (legacy)

| Version backend |
| ------- |
| LEGACY |

    GET /get_categories.php?

___

##### Récupération informations scope

| Version backend |
| ------- |
| >= 0.0.4 |

    GET /get_scope.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | scope | X |  Nom du scope | >= 0.0.4 |

###### Retour

JSON : Retourne les informations du [Scope](#scope).

___

##### Récupération version backend (legacy)

| Version backend |
| ------- |
| <= 0.0.3 |

    GET /get_version.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

JSON : Retourne la version du backend

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | version | Version du backend | >= 0.0.3 |

___

#### Observations

___
##### Récupération panel

| Version backend |
| ------- |
| >= 0.0.1 |

    GET /generate_panel.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | int | s | | Largeur de l'image | >= 0.0.1 |
| URL | str | key | | Clé d'admin pour visualisation non pixelisée | >= 0.0.1 |
| URL | str | secretid | | Clé secret de l'observation pour visualisation non pixelisée | >= 0.0.1 |

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/png | / | Image | <= 0.0.4 |
| image/jpeg | / | Image | >= 0.0.5 |

___

##### Récupération liste observations

| Version backend |
| ------- |
| <= 0.0.1 |

    GET /get_issues.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | int | c | | filtre selon catégorie | >= 0.0.1 |
| URL | int | t | |  filtre selon date | Changé en timestamp à partir de >= 0.0.5 |
| URL | str | scope | | filtre selon scope  | >= 0.0.1 |
| URL | int | status |  | filtre selon status de l'observation | >= 0.0.1 |
| URL | int | token |  | filtre selon token de l'observation | >= 0.0.1 |
| URL | int | count |  | limite le nombre d'occurences | >= 0.0.1 |
| URL | int | offset |  | démarrage le nombre d'occurence en décallé | >= 0.0.1 |
| URL | str | format |  | format (json,csv,geojson) | >= 0.0.3 |

###### Retour

JSON : Retourne la liste des [observations](#observation).

___

##### Récupération photo originale

| Version backend |
| ------- |
| >= 0.0.1 |

    GET /get_photo.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | X | Clé privé de l'utilisateur | >= 0.0.1 |
| URL | str | token | X | Token de l'observation | >= 0.0.1 |

###### Retour

Retourne une image

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| image/jpeg | / | Image | >= 0.0.1 |

___

### Ajout/modifications informations

#### Observations

___

##### Ajout d'une image à l'observation

| Version backend |
| ------- |
| >= 0.0.1 |

    POST /add_image.php?
    
###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | token | X | Token de l'observation | >= 0.0.1 |
| URL | str | secretid | X | Clé secrète de l'observation >= 0.0.1 |
| RAW | image/jpeg | / | X | Flux de l'image en JPEG | >= 0.0.1 |


###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.1 |

___

##### Approuver observation

| Version backend |
| ------- |
| >= 0.0.1 |

    POST /approve.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé privé de l'utilisateur | >= 0.0.1 |
| Form | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.1 |

___

##### Création observation

| Version backend |
| ------- |
| >= 0.0.1 |

    POST /create_issue.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | | Clé privé de l'utilisateur | >= 0.0.1 |
| Form | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |
| Form | str | coordinates_lat' | X | Latitude de l'observation | >= 0.0.1 |
| Form | str | coordinates_lon | X | Longitude de l'observation | >= 0.0.1 |
| Form | str | comment | X | Remarque de l'observation | >= 0.0.1 |
| Form | str | explanation | X | Explications observation | >= 0.0.1 |
| Form | str | categorie | X | ID de catégorie | >= 0.0.1 |
| Form | str | address | X | Adresse de l'observation | >= 0.0.1 |
| Form | str | time | X |  Timestamp de l'observation en ms | >= 0.0.1 |
| Form | str | version | X | Version de l'application cliente | >= 0.0.1 |
| Form | str | scope | X | Identifiant du scope | >= 0.0.1 |

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.1 |
| str | token | Retourne le token généré | >= 0.0.1 |
| str | secretid | Retourne la clé secrete de l'observation | >= 0.0.1 |
| int | group | LEGACY | LEGACY |

___

##### Suppression observation

| Version backend |
| ------- |
| >= 0.0.1 |

    GET /delete.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | Si secretid non fourni | | Clé privé de l'utilisateur | >= 0.0.1 |
| Form | str | token | Uniquement en cas de modif | Token de l'observation | >= 0.0.1 |
| URL | str | secretid | Si key non fourni | | Clé secrète de l'observation >= 0.0.1 |


###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.1 |

___

##### Obtenir liste observations en CSV (legacy)

| Version backend |
| ------- |
| <= 0.0.5 |

    GET /to_csv.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|

###### Retour

CSV : Retourne les informations d'identification de l'observation

___

##### Changer status observation

| Version backend |
| ------- |
| >= 0.0.5 |

    GET /update_status.php?

###### Arguments

| Localisation | Type | Nom | Obligatoire ? | Description | Compatibilité |
| ------------ | ---- | ----|------------ | ------------- | --------------|
| URL | str | key | Si secretid non fourni | Clé privé de l'utilisateur | >= 0.0.5 |
| URL | str | token | X | Token de l'observation | >= 0.0.5 |
| URL | str | secretid | Si key non fourni | Clé secrète de l'observation | >= 0.0.5 |
| URL | int | statusobs | X | Status à appliquer (0: non résolu / 1 : résolu) | >= 0.0.5 |

###### Retour

JSON : Retourne les informations d'identification de l'observation

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | Retourne le [status](#status) de l'appel  | >= 0.0.5 |

___

##### Obtenir carte en cache

| Version backend |
| ------- |
| >= 0.0.1 |

    GET /maps/{TOKEN}_zoom.jpg

___


## Données


### Catégories

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
| str | time | Timestamp (en secondes) de l'observation | >= 0.0.1 |
| int | group | Groupe de l'observation | LEGACY |
| int | categorie | Identifiant de catégorie de l'obseration | >= 0.0.1 |
| int | approved | Etat d'approbation de l'observation | >= 0.0.1 |

### Scope

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| str | display_name | Nom affiché du scope dans Vigilo | >= 0.0.5 |
| str | coordinate_lat_min| Latitude minimum de la zone en dégré décimal | >= 0.0.5 |
| str | coordinate_lat_max | Latitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_min | Longitude minimum de la zone en dégré décimal  | >= 0.0.5 |
| str | coordinate_lon_max | Longitude maximum de la zone en dégré décimal  | >= 0.0.5 |
| str | map_center_string | Latitude + "," + Longitude du centre de la carte qui doit être affichée | >= 0.0.5 |
| str | map_zoom | Zoom de la carte à afficher | >= 0.0.5 |
| str | contact_email | Adresse mail de contact du scope  | >= 0.0.5 |
| str | tweet_content | ontenu du tweet qui mis par défaut via le composant de partage de l'application | >= 0.0.5 |
| str | map_url | Adresse de la carte où sont affichées les observations| >= 0.0.5 |
| str | backend_version | Version du backend| >= 0.0.5 |

### Status

| Type | Nom | Description | Compatibilité |
| ---- | ----|------------ | ------------- | 
| bool | status | 0 => OK / 1 => NOK | >= 0.0.1 |


