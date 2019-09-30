### Installation

### Pré-requis 

#### Connaissances 

* OS Linux / PHP / MySQL / Docker (si serveur dédié)
* PHP / MySQL (si hebergement mutualisé)

#### Vigilo-Backend

Vigilo-Backend necessite soit :
* Un serveur dédié sur lequel est installé Docker avec un reverse proxy permettant d'accéder au service Vigilo et de Offloader le SSL.
* Un hébergement PHP/MySQL

Versions :
* PHP >= 7.1

#### Services externes

Avant d'installer Vigilo, il est necessaire d'obtenir les clé d'API des services externes utilisés.

##### MapQuest

Mapquest est utilisé pour générer les images de carte dans le poster qui est généré.

Pour obtenir une clé d'API MapQuest sur le module StaticMAP API => https://developer.mapquest.com/

Créer un compte sur la plate-forme et générer une clé via le lien "Manage Key".
Récupérer ensuite le "Consumer Key" qui sera à renseigner dans Vigilo.	

##### Twitter

- Créer un compte Twitter dédié à votre scope. Par soucis de cohérence avec toutes les autres instances, il est recommandé d'utiliser le modèle @VigiloVille. Exemples : [VigiloTroyes](https://twitter.com/VigiloTroyes), [VigiloMtp34](https://twitter.com/VigiloMtp34), [VigiloValleeSud](https://twitter.com/VigiloValleeSud), [VigiloMetz](https://twitter.com/VigiloMetz)

Pour la suite, tout se passe en Anglais.

- Se rendre sur https://developer.twitter.com et se connecter avec votre compte Twitter.
- Cliquer sur « Apply » en haut à droite (Apply for a developer account)
- Choisir « Doing something else »
- Choisir « France » à la réponse « What country do you live in? »
- Choisir le nom de votre association dans « What would you like us to call you? »
- Sur la page « How will you use the Twitter API or Twitter data? », renseignez le texte suivant dans le champ « In your words »

> Our application is called Vigilo and is a service allowing users to post observations from the street (badly parked cars, dangers on the road, infrastructure issues, etc.) through a mobile application.
> 
> We want to make a Twitter account that will Tweet programmatically content from our Vigilo server.
> When a user post a new observation on our application and when the observation is moderated, our server will automatically tweet the observation (picture + text).
> 
> Then Tweeter users can interact with those tweets and retweet them. Nobody will access Twitter data. It's a one-way broadcast from our application. 

- Répondre No à toutes les questions « The specifics »
- Cliquer sur « Looks good! »
- Cocher les conditions d'utilisation puis cliquer sur « Accept » puis « Submit Application »

Une fois que votre compte est validé

- Retourner sur https://developer.twitter.com et aller sur « Create an app »
- Dans « App name » choisissez « Vigilo Votre Ville / agglo »
- Dans « Application description » renseigner le texte suivant

> Vigilo is a service allowing users to post observations from the street (badly parked cars, dangers on the road, infrastructure issues, etc.) through a mobile application.

- Dans « Website URL », renseigner le site web associé à votre association.

- Dans « Tell us how this app will be used », renseigner le texte suivant

> Our Twitter account will Tweet programmatically content from our server. When a user posts a new observation on our application and when the observation is moderated, our server will automatically tweet the observation (picture + text). Then Tweeter users can interact with those tweets and retweet them. Nobody will access Twitter data. It's a one-way broadcast from our application.

- Cliquer sur « Create » et encore « Create » pour valider.
- Normalement votre application devrait être créée à ce stade.
- Rendez-vous dans l'onglet « Keys and tokens » et récupérez l'API key (consumer) et l'API secret key (consumersecret)
- Créez un Access token dans « Access token & access token secret » et récupérez l'Access token (accesstoken) et l'Access token secret (accesstokensecret)
- Renseignez enfin ces 4 clés dans votre configuration Vigilo.

#### Installation Vigilo

##### Serveur dédié

Cette partie documentaire explique uniquement la partie installation de Vigilo via docker. 
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

##### Hebergement mutualisé

Cette partie documentaire explique la mise en place du code Vigilo sur un hebergement mutualité PHP/MySQL

###### Mise en place sources

Cloner le repo git complet en adaptant la version en remplacant X.X.X par la dernière branche du git existante.

$ git clone https://github.com/jesuisundesdeux/vigilo-backend.git -b X.X.X --single-branch

Importer le contenu de ```app/``` dans l'arborescence web.

###### Mise en place base de données

Executer l'ensemble des scripts MySQL présents dans ```mysql/init/``` dans l'ordre sur MySQL.

###### Configuration

* config/config.php

Copier le fichier config/config.php.tpl vers config/config.php

Renseigner les différents valeurs à configurer concernant la base de données.
 
###### Initialisation Vigilo

* Copier le fichier install_app/install.php sur l'hebergement et y accéder via https://adresse_du_serveur/install.php
* Remplir les champs permettant de créer un compte admin
* Supprimer install.php.

#### Configuration de l'instance

Aller sur https://adresse_du_serveur/admin/

##### Configuration

Remplir les différents champs :

* URL base	: adresse de vigilo (exmeple : vigilo.jesuisundesdeux.org)
* Protocole d'accès	: http ou https
* Nom de l'instance	
* Langue : fr-Fr (pas utilisé à ce jour)
* Clé Mapquest	: clé définie ci-dessus
* Nombre d'heure max pour Tweeter observations	: Age maxiumum en heures des observations qui seront postées sur twitter automatiquement
* Charset : utf8
* Timezone : Fuseau horaire normalisé

##### Twitter

Remplir pour chaque ligne les informations du compte twitter correspondant

##### Scopes

Les scopes sont des zones géographiques indépendantes au sein d'une même instance.

Il correspondent en règle générale à une Metropole, Agglomération voire un ville.

En règle générale, un seul scope est necessaire.

Ajouter un scope et remplir ses information comme suit :
* Identifiant : XX_yyyyyyy où "xx" correspond au numero de departement ou code pays si non français (be, uk, ...) et "yyyyyyy" à un nom court (sans espace, ni accents, ni caractères spéciaux) correspondant au nom de la zone
* Nom affiché	: Nom qui sera affiché correspondant à la zone
* Departement : Numero du département ou 0 si non applicable
* Latitude/Longitude minimale/maximale : Permet de limiter géographiquement la zone
* Coordonées centre du scope	: Coordonnées du centre des cartes qui seront affichées dans l'application
* Zoom cartes	: Zoom  des cartes qui seront affichées dans l'application
* Email Contact	: Email de contact de l'association en charge du scope
* Text de partage par défaut	: Texte de partage qui sera mis par défaut quand les utilisateur utiliseront la fonction partage de l'application
* Compte Twitter affiché	: Compte twitter affiché correspondant au scope
* Identifiant compte twitter	: Numero du compte twitter configuré précédement qui sera utilisé par le scope
* Contenu des tweets autos : Contenu des tweets qui seront postés par le compte twitter lors de la validation des observations
* URL carte externe	: Si besoin, URL d'une carte qui affiche les observations

##### Villes

Ensemble des villes qui font parties des scopes.

Cette fonctionnalité n'est pas encore utilisée, mais est à remplir pour anticiper les futures évolutions.


#### Modération

##### Via Vigilo Android

* Ouvrir Vigilo
* Panneau latérale (accessible via les trois barres en haut à gauche)
* Générer une clé
* Un message affichant "texte copié" s'affiche / votre clé est copié automatiquement
* Ajouter la clé sur la ligne du compte dans admin/

##### Via Vigilo Web


* Panneau latérale (accessible via les trois barres en haut à gauche)
* "Presque modérateur"
* Ajouter la clé sur la ligne du compte dans admin/







