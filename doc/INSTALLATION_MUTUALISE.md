### Installation sur serveur mutualisé

#### Pré-requis

##### Logiciel

* PHP >= 7.1 avec php-gd
* Une base de données MySQL/MariaDB

##### Connaissances

* PHP/MySQL

#### Mise en place

##### Mise en place sources

Cloner le repo git complet en adaptant la version en remplacant X.X.X par la dernière branche du git existante.

$ git clone https://github.com/jesuisundesdeux/vigilo-backend.git -b X.X.X --single-branch

Importer le contenu de ```app/``` dans l'arborescence web.

Chez OVH : supprimer le fichier ```.htaccess``` (sinon : erreur 500 lors de l'accès aux pages php).

##### Mise en place base de données

Executer l'ensemble des scripts MySQL présents dans ```mysql/init/``` dans l'ordre sur MySQL.

Chez OVH : dans phpMyAdmin, enlever les lignes commentées des requêtes SQL (sinon : erreur lors de l'exécution).

##### Configuration

* config/config.php

Copier le fichier config/config.php.tpl vers config/config.php

Renseigner les différents valeurs à configurer concernant la base de données.

