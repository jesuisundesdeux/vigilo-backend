### Installation

### Pré-requis 

#### Connaissances 

* OS Linux / Docker (si serveur dédié)
* LAMP (si hebergement mutualisé)

#### Vigilo-Backend

Vigilo-Backend necessite soit :
* Un serveur dédié sur lequel est installé Docker
* Un hébergement PHP/MySQL

Versions :
* PHP >= 7.1

#### Services externes

##### MapQuest

Obtenir une clé d'API MapQuest sur le module StaticMAP API => https://developer.mapquest.com/

##### Twitter

Créer un compte Twitter application dédié 
(voir https://creerapplication.zendesk.com/hc/fr/articles/115000691364-Int%C3%A9grer-Twitter-dans-votre-application)

#### Serveur dédié

Cloner le repo git complet.

```
$ git clone https://github.com/jesuisundesdeux/vigilo-backend.git 
```

Copier le .env_sample vers .env

``` $ cp .env_sample .env_prod```

Adapter les valeurs dans ```.env_prod``` :
* VOLUME_PATH : Repertoire persistent sur le serveur  où seront stockées les données de Vigilo 
* MYSQL_ROOT_PASSWORD : Mot de passe root de la base de données 
* MYSQL_PASSWORD : Mot de passe du compte vigilo de la base de données

Copier le docker-compose_sample.yml vers docker_compose_prod.yml

``` $ cp docker-compose_sample.yml docker-compose_prod.yml ```

Adapter si besoin ce fichier au contexte du serveur sur lequel il est hebergé.

Lancer le service :

``` 
$ make ENV=prod env start
```

#### Hebergement mutualisé

##### Mise en place sources

Importer le contenu de ```app/``` dans l'arborescence web.

##### Mise en place base de données

Executer l'ensemble des scripts MySQL présents dans ```mysql/init/``` dans l'ordre sur MySQL.

### Configuration

#### config.php

Copier le fichier ```config/config_sample.php``` dans ```config/config.php```.

Renseigner les différents valeurs à configurer.
 
#### Base de données

#### Création du scope

Un scope est une zone géographique (ou ville) qui permet de mettre en place sur un même backend plusieurs instances.

Créer une entrée pour chaque scope souhaité :
```
INSERT INTO `obs_scopes` 
                (`scope_name`,
                `scope_display_name`,
                `scope_department`,
                `scope_coordinate_lat_min`, 
                `scope_coordinate_lat_max`, 
                `scope_coordinate_lon_min`, 
                `scope_coordinate_lon_max`, 
                `scope_map_center_string`, 
                `scope_map_zoom`, 
                `scope_contact_email`, 
                `scope_sharing_content_text`, 
                `scope_twitter`,
                `scope_umap_url`
         ) VALUES
                ('ID_DU_SCOPE',
                 'NOM_SCOPE',
                 'DEPARTEMENT',
                 'COORDONNEE_LAT_MIN',
                 'COORDONNEE_LAT_MAX', 
                 'COORDONNEE_LON_MIN', 
                 'COORDONNEE_LON_MAX',
                 'COORDONNEES_CENTRE_CARTE',
                 'ZOOM', 
                 'CONTACT_EMAIL', 
                 'TWEET_CONTENT', 
                 'TWITTER',
                 'MAP_URL'
            );
```

* ID_DU_SCOPE : Numero du département + "_" + nom ville attaché (exemple : 34_montpellier)
* NOM_SCOPE : Nom affiché du scope dans Vigilo (exemple : Montpellier)
* DEPARTEMENT : Numéro du département du scope. Permet de trier / filtrer les instances de Vigilo dans les applications clientes.
* COORDONNEE_LAT_MIN / COORDONNEE_LAT_MAX / COORDONNEE_LON_MIN / COORDONNEE_LON_MAX : Limites géographique en degré décimal de la zone
* COORDONNEES_CENTRE_CARTE : Latitude + "," + Longitude du centre de la carte qui sera affichée
* ZOOM : Zoom de la carte qui sera affiché (voir Zoom Google Map)
* CONTACT_EMAIL : Adresse mail de contact de l'instance
* TWEET_CONTENT : Contenu du tweet qui mis par défaut via le composant de partage de l'application
* TWITTER : Compte Twitter associé à l'instance de Vigilo
* MAP_URL : Adresse de la carte où sont affichées les observations

#### Modération

##### Via Vigilo Android

* Ouvrir Vigilo
* Créer une nouvelle observation
* Choisir une photo
* Cliquer sur la photo pour passer en mode edition
* Appuyer 15 fois sur l'icone affichage des outils (tout en bas à droite)
* Dès qu'un rectangle s'affiche en bas, annuler la création de l'observation et revenir sur la liste des observation
* Un message affichant "texte copié" s'affiche / votre clé est copié automatiquement
* ajouter la clé dans la table obs_role avec pour role name "admin" et role_owner le nom du propriétaire du compte.

##### Via Web

* Ajouter le login / mot de passe dans une entrée dans la table obs_role (mot de passe en sha256)






