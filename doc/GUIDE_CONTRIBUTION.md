### Guide du contributeur

#### Pré-requis

##### Connaissances

Vigilo-backend est developpé en PHP avec base de données MySQL.

##### Environemenrt de developpement

L'installaton de l'envirronement de developpement est semblable à celui de la production installable avec la procédure [INSTALLATION.md](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/doc/INSTALLATION.md)

#### Fonctionnement

##### SCM 

Sur ce projet, la branche *master* est utilisée comme branche de developpement. 
Dés qu'elle est stabilisée, une branche de version (X.X.X) est à créer

L'ajout des contributions sur la branche master se fait via une Pull Request :
 * soit à partir d'une branche spécifique (avec le nom de la feature)
 * soit à partir d'un fork du repo git
 
##### Organisation des sources

Le repo est organisé comme suit :

###### Docker

L'application est installable soit comme une applkication PHP/MySQL classique soit avec via Docker/Docker-compose grace à l'arboresence suivante :

* *backup* est un repertoire destinée aux backups lors des mises en production
* *config* configure l'application avec un fichier poussé via docker
* *docker_images* : source des images docker personnalisées
* .env* : fichiers de variables d'environement chargés par docker
* docker-compose.yml

Une installation automatisée et des tests unitaires complète également le modèle sous docker avec Makefile et Travis

###### Vigilo-Backend App


* *app* contient l'ensemble du code PHP. La version du code (liée à la version figée) est renseignée dans app/includes/common.php avec la variable BACKEND_VERSION
* *mysql* contient les évolutions mysql necessaires pour les nouvelles versions

##### Tracker

Les bugs, reflexions ou features requests sont regroupés dans le tracker github accessible [ici](https://github.com/jesuisundesdeux/vigilo-backend/issues)

Un slack est également disponible pour échanger de manière réactive (me contacter pour une invitation).

Si vous êtes sur Montpellier et que vous souhaitez contribuer, n'hesitez pas à nous contacter, une présentation autour d'une bière est envisageable.
Pour les autres villes, c'est aussi envisageable selon les contributeurs locaux présents.

##### Roadmap

Le projet étant encore au stade de conception, il est actuellement codé en Quick&Dirty (mais pas trop quand même !).

Lorsque les besoins de nouvelles fonctionnalités seront stabilisés, une refactorisation sera envisagée en POO from scratch ou alors via l'utilisation d'un framework existant.
