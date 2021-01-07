### Installation Vigilo sur serveur dédié avec Docker

#### Pré-requis

##### Logiciel

* OS Linux
* Docker
* Docker-compose
* Un reserve-proxy comme Nginx afin de géré la couche SSL et l'accès public à Vigilo

##### Connaissances

* OS Linux / PHP / MySQL / Docker 

#### Mise en place

A charge à l'administrateur d'installer le necessaire en amont pour permettre la mise en place d'un certificat SSL (LetsEncrypt).

Cloner le repo git complet en adaptant la version en remplacant X.X.X par la dernière branche du git existante.

```
$ git clone https://github.com/jesuisundesdeux/vigilo-backend.git -b X.X.X --single-branch
```

Copier le .env_sample vers .env

``` $ cp .env_sample .env_prod```

Adapter les valeurs dans ```.env_prod``` :
* VOLUME_PATH : Repertoire persistent sur le serveur où seront stockées les données de Vigilo
* MYSQL_ROOT_PASSWORD : Mot de passe root de la base de données
* MYSQL_PASSWORD : Mot de passe du compte vigilo de la base de données
* BIND : Adresse d'écoute HOST:PORT permettant d'accéder au conteneur à partir d'un reverse proxy sur l'hote ou à partir d'un autre container.

Adapter si besoin ce fichier au contexte du serveur sur lequel il est hebergé.

Lancer le service :

```
$ make ENV=prod install
```
- Aller ensuite sur http://IP/install.php et remplir les champs permettant de créer un compte admin.
- Supprimer ensuite le fichier app/install.php
