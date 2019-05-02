Présentation API Vigilo
============

## Méthodes 

### Récupération d'informations

#### Configurations

##### Récupération catégories

| Version backend |
| ------- |
| >= 0.0.1 |

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

##### Récupération catégories (legacy)

| Version backend |
| ------- |
| LEGACY |

    GET /get_categories.php?

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

#### Observations

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



#### Ajout/modifications informations


## Données

### Scope

### Status
