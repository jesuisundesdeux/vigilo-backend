### Guide du contributeur

#### Pré-requis

##### Connaissances

Vigilo-backend est developpé en PHP avec base de données MySQL.

##### Environemenrt de developpement

L'installaton de l'envirroenemnt de dev est semblable à celui de la production disponible selon la procédure disponible [INSTALLATION.md](https://github.com/jesuisundesdeux/vigilo-backend/blob/master/doc/INSTALLATION.md)

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

L'appliaction est installable via Docker/Docker-compose grace aux fichiers suivants :

* *backup* est un repertoire destinée aux backups lors des mises en productions
* *config* est liée aux configurations utilisée pour le fonctionnement de vigilo-backend avec docker
* *docker_images* : source des images docker utilisée dans docker-compose
* .env* : fichiers d'envirroenemnt chargés par docker
* docker-compose.yml

Une installation automatisée et des tests unitaires complète également le modèle sous docker avec Makefile et Travis

###### Vigilo-Backend App


* *app* contient l'ensemble du code PHP. La version du code (liée à la version figée) est renseignée dans app/includes/common.php avec la variable BACKEND_VERSION
* *mysql* contient les évolutions mysql necessaires pour les nouvelles versions
