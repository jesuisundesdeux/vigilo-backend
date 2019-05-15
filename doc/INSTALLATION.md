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
* BIND : Adresse d'écoute HOST:PORT

Adapter si besoin ce fichier au contexte du serveur sur lequel il est hebergé.

Lancer le service :

``` 
$ make ENV=prod install
```
- Aller ensuite sur http://IP/install.php et remplir les champs.
- Supprimer ensuite le fichier app/install.php

#### Hebergement mutualisé

##### Mise en place sources

Importer le contenu de ```app/``` dans l'arborescence web.

##### Mise en place base de données

Executer l'ensemble des scripts MySQL présents dans ```mysql/init/``` dans l'ordre sur MySQL.

### Configuration

#### config/config.php

Renseigner les différents valeurs à configurer concernant la base de données.
 
#### Initialisation Vigilo

* Copier le fichier install_app/install.php sur l'hebergement et y accéder.
* Remplir tous les champs et valider.
* Supprimer install.php.

##### Listing des villes

Chaque scope est constitué de une ou plusieurs villes. Ces villes sont listées
et décrites dans la table obs_cities.

Créer une entrée par ville du scope.

```
INSERT INTO `obs_cities`
                (`city_id`,
                `city_scope`,
                `city_name`,
                `city_postcode`,
                `city_area`,
                `city_population`,
                `city_website`
         ) VALUES
                (ID_DE_LA_VILLE,
                 ID_DU_SCOPE,
                 NOM_DE_LA_VILLE,
                 CODE_POSTAL,
                 SUPERFICIE,
                 POPULATION,
                 WEBSITE
         );
```

* ID_DE_LA_VILLE : valeur auto incrémentée
* ID_DU_SCOPE : identifiant unique entier définissant le scope
* NOM_DE_LA_VILLE : Nom entier de la ville
* NOM_DE_LA_VILLE : Code postal de la ville
* SUPERFICIE : Superficie de la ville en km2
* POPULATION : Nombre d'habitants de la ville
* WEBSITE : Site web de la ville

#### Champs de configuration Vigilo 

WIP

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






