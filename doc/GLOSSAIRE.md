### Glossaire :

#### Back-end

Composant de l'application Vigilo permettant l'hebergement, la gestion et l'affichage des observations 

#### Catégories 

Permettent de classer les observations selon leur type, elles doivent être identiques sur l'ensemble des backends

#### Observation 

Une entrée contenant une photo, des caractéristiques et des champs de statuts.

#### Panel 

Jusqu'à la 0.0.21, image générée via ```generate_panel.php``` contenant une synthèse de l'observation (photo, carte, textes). Depuis la 0.0.22, ```generate_panel.php``` renvoie la photo de l'observation (pixelisée tant qu'elle n'est pas approuvée).

#### Scope 

Instance d'une zone géographique au sein d'un backend permettant la coohabitation de plusieurs zones sur un même backend

#### Token 

Un identifiant d'observation unique généré aléatoirement et affiché avec l'observation

#### Résolution

Déclaration qu'une ou plusieurs observations sont résolues, avec une photo, validée par un administrateur.

#### Rôles

`admin`, `moderator`, `citystaff` (services d'une ville, limités à leurs villes) et `guest` : voir [FONCTIONNEMENT.md](FONCTIONNEMENT.md#rôles).

#### Secretid

Secret remis à l'auteur d'une observation à sa création : il permet d'y ajouter la photo ou de la supprimer.

#### Serveur de floutage

Service optionnel qui masque visages et plaques d'immatriculation sur les photos reçues ([blur-server](../blur-server/README.md)).

#### Statut

État de suivi d'une observation : 0 nouvelle, 1 résolue, 2 prise en compte, 3 en cours de résolution, 4 indiquée comme résolue.
