# Changelog

## 0.0.32

### Problèmes (lot 1, première partie)
- Nouvelle page **Problèmes** dans l'admin (administrateurs seulement, issue #327) : un problème regroupe les
  observations publiées d'une **zone** (cercle ou polygone tracé sur la carte), d'une ou plusieurs **catégories** (les
  catégories nationales et celles de l'instance) et d'une **période** (début, fin facultative, sur la date des
  observations), avec une **priorité** (basse, normale, haute, critique) et un **statut** (ouvert, en cours, en
  attente, résolu, clos).
- Liste avec recherche (titre, description) et filtres (statut, priorité, catégorie), création, modification et
  suppression (les observations sont conservées). Les actions sont inscrites au journal (`problem_create`,
  `problem_edit` avec les valeurs modifiées, `problem_delete`).
- **Fiche d'un problème** : carte de la zone et des observations, rattachement des observations existantes en deux
  temps, **Compter** (aucune modification) puis **Rattacher** (avec confirmation, calcul refait, idempotent) ; une
  observation déjà dans un autre problème n'est jamais déplacée mais signalée (conflit, lien vers l'autre problème) ;
  rattachement manuel d'une observation publiée par son token, détachement. Journal : `problem_attach_retro`,
  `problem_attach`, `problem_detach`.
- **Rattachement automatique** à la publication d'une observation (admin, `approve.php`, ou photo reçue après
  l'approbation) au problème actif (ouvert, en cours, en attente) qui lui correspond, s'il n'y en a qu'un ; sinon
  l'observation apparaît dans le cadre **À arbitrer** de la page Problèmes. Journal : `problem_attach_auto`,
  `problem_attach_ambiguous`. Les réponses de l'API ne changent pas.
- Migration 0.0.32 : tables `obs_problems` et `obs_problem_observations` (une observation dans un seul problème pour
  l'instant). Rien ne change pour l'API publique ni pour l'application web.

### Plus ancienne version prise en charge : 0.0.17
- Aucune instance en service n'est plus ancienne que la 0.0.17 (issue #328). Les migrations `init-0.0.2.sql` à
  `init-0.0.17.sql` sont remplacées par le schéma `schema-0.0.17.sql`, appliqué seulement à une base vide (même
  structure et mêmes réglages, vérifié par la CI). Une base plus ancienne que la 0.0.17 est refusée par la migration,
  sans être modifiée ; les procédures de mise à jour des versions antérieures (branches, 0.0.13) sont retirées de la
  documentation.
- La mise à jour depuis l'admin supprime les anciens fichiers de migration (`scripts/obsolete-paths.txt`).

### Webhooks : un événement par webhook
- Le formulaire des webhooks ne propose plus qu'**un seul événement** par webhook (boutons radio) : le corps et les
  variables dépendent de l'événement (une résolution n'a pas de commentaire d'observation, par exemple), et un
  webhook abonné à plusieurs événements envoyait des requêtes incomplètes ou invalides pour certains.
- Les webhooks créés avec plusieurs événements (0.0.24 à 0.0.31) continuent d'être envoyés ; ils sont signalés
  dans la liste, et leur modification demande de garder un seul événement (créer un webhook pour chacun des autres).

### Docker : Watchtower par défaut
- `docker-compose.yml` : le service `watchtower` (mise à jour depuis l'admin) est actif par défaut, sans profil ;
  `WATCHTOWER_TOKEN` est obligatoire dans `.env` et `VIGILO_WATCHTOWER_URL` vaut `http://watchtower:8080` par défaut.
- Le fichier fonctionne seul, sans copie du dépôt (la construction du serveur de floutage passe dans
  `docker-compose.dev.yml`) : la documentation d'installation donne directement le contenu de `docker-compose.yml`
  et `.env`.

## 0.0.31

### Assistant d'installation
- Premier accès à `/admin/` sur une instance non installée : **assistant d'installation** (`admin/setup.php`), le même
  en hébergement mutualisé et avec Docker, protégé par un **code d'installation** à usage unique écrit sur le serveur
  (`caches/setup-code.php`, affiché aussi dans les journaux du conteneur Docker) :
  - hébergement mutualisé : saisie de l'accès à la base, **test de la connexion et des droits** (création,
    modification, suppression d'une table), puis écriture de `config/config.php` (ou contenu à déposer par FTP) ;
  - Docker : vérification de la connexion (variables `MYSQL_*`) ;
  - création des tables avec les scripts de `migrations/`, comparaison de la version de la base et du code (refus
    d'une base plus récente) ;
  - compte administrateur (mot de passe de 10 caractères au moins), nom de l'instance, **URL de base et protocole
    détectés** d'après l'adresse utilisée ; l'assistant se ferme ensuite définitivement.
- Vérifications affichées : version de PHP, extensions, dossiers accessibles en écriture, base de données.
- L'ancien `install.php` (accessible à n'importe qui tant qu'aucun compte n'existait) est retiré ; s'il reste sur
  une instance, il est supprimé au démarrage du conteneur Docker, par l'accueil de l'admin et par la mise à jour depuis
  l'admin.

### Première configuration
- Accueil de l'admin : cadre **Première configuration** avec les étapes restantes (nom et adresse de l'instance,
  scope, villes, référencement dans vigilo-conf ; facultatif : modérateurs), leur avancement et un lien vers chaque
  page. Les badges « Étape N » du menu sont retirés.

### Instances de démonstration (écosystème fermé)
- Variables d'environnement (ou `config/config.php`) pour une instance qui ne dépend ni de vigilo-conf ni
  d'app.vigilo.city, utilisées par [vigilo-demo](https://github.com/jesuisundesdeux/vigilo-demo) :
  - `VIGILO_CONF_URL` : dossier de `citylist.json` et `categorielist.json` (vigilo-conf par défaut) ; liste des
    instances relue toutes les 5 minutes quand elle n'est pas celle de vigilo-conf ;
  - `VIGILO_WEBAPP_URL` : application web des liens des webhooks (`https://app.vigilo.city/` par défaut) ;
  - `VIGILO_SETUP_CODE` : code d'installation de l'assistant fixé par l'hébergeur (jamais affiché dans les journaux).
- Image Docker, `VIGILO_TRUSTED_PROXIES` : réseaux des proxys inverses devant le conteneur (Traefik, réseaux Docker),
  ajoutés à `mod_remoteip` : l'IP du client est lue dans `X-Forwarded-For`. Sans elle, derrière un proxy d'un autre
  réseau que `172.19.0.0/24`, tous les visiteurs partagent l'IP du proxy pour l'anti-spam et le blocage des connexions.

## 0.0.30

### Webhooks : filtres par catégorie et par ville
- Un webhook peut être limité à une ou plusieurs **catégories** et à une ou plusieurs **villes** (ou aux observations
  sans ville référencée) : cases à cocher du formulaire de la page Webhooks, aucune case cochée = toutes. Une
  résolution déclenche le webhook si l'une de ses observations correspond. Les filtres sont rappelés dans la liste
  des webhooks ; « Enregistrer et tester » indique quand l'observation de test est hors des filtres.
- Migration `init-0.0.30.sql` : colonnes `webhook_categories` et `webhook_cities` de `obs_webhooks` (vides : les
  webhooks existants restent inchangés).

### Clé modérateur fournie par la personne
- Page Comptes de l'admin : champ facultatif **Clé fournie par la personne**, pour enregistrer comme clé API du compte
  la clé qu'une personne a générée dans l'application web (« Devenir modérateur ») et transmise à l'association
  (20 à 64 lettres ou chiffres, unique). Sans clé fournie, la clé reste générée par l'instance.

### Correctifs
- Page Observations de l'admin : le cadre « Archiver par période » est placé sous la liste des observations ; le
  bouton « Archiver » / « Désarchiver » de chaque ligne et le badge « Archivée », illisibles en thème sombre, sont
  désormais visibles dans les deux thèmes.

## 0.0.29

### Rapport pour une collectivité
- Nouvelle page **Rapports** de l'admin (administrateurs et comptes citystaff, limités à leurs villes) : rapport des
  observations publiées d'une période, de villes et de types d'observation choisis, en **document** (A4) ou en
  **diaporama** (16:9, mode présentation), enregistrable en PDF par l'impression du navigateur.
- Chiffres clés (nombre, moyenne par mois, évolution par rapport à la période précédente, pour 10 000 habitants, taux
  de résolution et de prise en charge, délai médian de résolution, lieux et lieux récurrents), graphiques (mois, types,
  état de traitement, villes, rues, jours, heures), carte des lieux et **observations similaires regroupées** (même
  catégorie, à moins de 25 à 200 m ou dans la même rue) avec photo, dates et états.
- Sans nouvelle dépendance : graphiques en SVG générés en PHP, carte Leaflet déjà présente dans l'admin.

### Archivage des observations
- Page Observations de l'admin (administrateurs) : une observation peut être **archivée** (bouton « Archiver » de sa
  ligne, ou actions groupées « Archiver » / « Désarchiver » sur la sélection), et toutes les observations d'une
  **période** (date de l'observation), d'une catégorie ou de toutes, avec le cadre « Archiver par période » (aperçu du
  nombre d'observations, puis confirmation). Filtre « Archives » (non archivées par défaut, archivées, toutes) et
  badge « Archivée ». Actions journalisées (`observation_archive`, `observation_unarchive`,
  `observation_archive_period`).
- API : une observation archivée n'est plus listée par `get_issues.php` (application, carte, site) ; elle reste
  accessible par son `token` (liens) et comptée dans les statistiques : nouveau paramètre `archived=1` qui l'inclut,
  avec un nouveau champ `archived` (0 ou 1) sur chaque observation. Réponse inchangée sans ce paramètre.
- Migration `init-0.0.29.sql` : colonne `obs_list.obs_archived`.

## 0.0.28

### Scopes : site de l'association
- Nouveau champ « Site de l'association » sur la carte de chaque scope (page Scopes de l'admin) : adresse
  `http(s)://` du site de l'association qui gère le territoire. Renvoyé par `get_scope.php` dans le nouveau champ
  `association_url` (vide s'il n'est pas renseigné) et affiché sur la page du territoire sur vigilo.city.
- Migration `init-0.0.28.sql` : colonne `obs_scopes.scope_association_url`.

## 0.0.27

### Observations liées à plusieurs résolutions
- `get_issues.php` renvoyait une entrée par résolution : une observation résolue qui figurait aussi dans une
  résolution plus récente (envoyée depuis l'application, « indiquée résolue ») apparaissait en double et pouvait être
  affichée « en résolution ». Une seule entrée, avec le statut le plus avancé (résolue > indiquée résolue > en cours >
  prise en compte) ; le masquage des anciennes observations résolues (`vigilo_resolved_hide_days`) suit la même règle.
- Admin, page Observations : le badge « En résolution » affiche l'état réel (Résolue, Indiquée résolue, En cours de
  résolution, Prise en compte).

### Webhooks : description de l'action
- Variables `{{event_label}}` (nom de l'événement, comme dans l'admin) et `{{event_description}}` (l'action en une
  phrase : « Observation ABCD1234 publiée : Véhicule ou objet gênant, Rue de la Gare (Montpellier). », « Résolution
  R123 : En cours de résolution → Résolue (2 observations). »…), utiles pour un webhook abonné à plusieurs événements.
- Admin, « Enregistrer et tester » sans observation : `{{event_label}}` est rempli dans les valeurs d'exemple. Modèles
  « JSON générique » et « Slack / Mattermost » : `{{event_label}}` et `{{event_description}}`.

### Webhooks : photo pixelisée dans Slack après approbation
- `{{photo_url}}` porte un paramètre `v` (état de modération et date de la photo, ignoré par `generate_panel.php`) :
  le lien d'une observation approuvée diffère de celui de la photo pixelisée envoyé à sa création. Slack garde en cache
  les images par adresse et affichait encore la version pixelisée au message « Observation approuvée ».

## 0.0.26

### API
- `mosaic.php` (page HTML des photos en mosaïque, observations similaires) est supprimée, avec `style/mosaic.css` :
  l'application web (1.14.0) affiche elle-même les observations similaires dans la fiche d'une observation (mêmes
  règles : même catégorie, à moins de 300 m ou à la même adresse). La recherche « Similaires » de l'admin est inchangée.

### Webhooks : photo d'origine pour les modérateurs
- Variable `{{photo_full_url}}` : lien vers la photo d'origine (non pixelisée, même avant modération), signé et valable
  7 jours, par exemple pour l'afficher dans le canal des modérateurs avec l'événement « Nouvelle observation ».
  `get_photo.php` accepte ce lien signé (`exp`, `sig`) ; le secret de signature est créé automatiquement
  (`vigilo_photo_link_secret` dans `obs_config`, supprimer cette ligne invalide les liens envoyés).

## 0.0.25

### Administration
- Création d'une catégorie de l'instance : rappel qu'une catégorie utile à d'autres instances peut être proposée
  dans la liste nationale par une pull request sur vigilo-conf (`main/categorielist.json`).

### Webhooks
- `{{photo_url}}` pointe vers `generate_panel.php`, public dans tous les états (photo pixelisée tant que l'observation
  n'est pas approuvée) : `get_photo.php` refusait les photos non approuvées et Slack rejetait le message
  (`invalid_blocks`) pour une nouvelle observation. « Enregistrer et tester » utilise une observation dans l'état de
  l'événement (nouvelle, refusée).

## 0.0.24

### Docker
- Watchtower (mise à jour de l'image depuis l'admin) : image `nickfedor/watchtower`, version maintenue de
  `containrrr/watchtower`, archivée, qui ne démarre plus avec Docker Engine 29 (`client version 1.25 is too old`).
  Reprendre le `docker-compose.yml` (ou `WATCHTOWER_IMAGE=nickfedor/watchtower:1`), voir doc/UPGRADE.md.
- Le bouton de l'admin appelle l'API de Watchtower en POST (seule méthode acceptée par la nouvelle image).

### Webhooks
- Nouveaux événements (#198) : nouvelle observation (`observation.created`, à la réception de sa photo, par exemple pour
  prévenir les modérateurs, #239), observation refusée (`observation.disapproved`), nouvelle résolution
  (`resolution.created`) et changement d'état d'une résolution (`resolution.status_changed`), en plus de la publication.
  Un webhook peut s'abonner à plusieurs événements (cases à cocher ; les webhooks existants gardent la publication).
- Nouvelles variables : `approved` et, pour les résolutions, `resolution_token`, `resolution_status`,
  `resolution_status_name`, `resolution_previous_status`, `resolution_comment`, `resolution_date`,
  `resolution_photo_url`, `resolution_observations`. Le journal des envois indique l'événement.
- Migration `init-0.0.24.sql` : colonne `webhook_event` élargie (liste d'événements).

### Administration
- Suppression d'une catégorie de l'instance utilisée par des observations : les observations sont déplacées vers une
  autre catégorie active ou supprimées (avec leurs photos), au choix, dans la fenêtre de suppression.
- Actions groupées sur les observations (approuver, désapprouver, remettre à qualifier, changer la catégorie ou la
  ville, nouvelle résolution regroupant la sélection, ajout à une résolution, effacer le cache, supprimer) et sur les
  résolutions (changer l'état, supprimer) : cocher les éléments puis choisir l'action. Mêmes droits que les actions
  unitaires (un citystaff seulement sur ses villes) ; les transitions d'état des résolutions sont contrôlées.

### Floutage des photos
- Serveur de floutage (`blur-server/`) plus fiable : détection des visages et des plaques par le modèle YOLO11s
  entraîné par Panoramax pour son service de floutage (SGBlur), exécuté sur CPU avec ONNX Runtime, complété par
  YuNet pour les visages proches. Les plaques lointaines sont trouvées et les textes et panneaux ne sont plus floutés
  à tort (98 à 100 % des plaques trouvées sur le benchmark OpenALPR). Environ 0,4 s par photo sur 4 cœurs.
- Nouveaux réglages : `BLUR_SIZES` (passe supplémentaire à 2048 pour les très petits visages), `BLUR_THREADS`,
  `BLUR_FACE_CONFIDENCE`, `BLUR_PLATE_CONFIDENCE`, `BLUR_MODEL`. Mettre à jour l'image `vigilo-blur`.

## 0.0.23

L'API reste compatible avec les applications ; la base est mise à jour automatiquement (migration
`init-0.0.23.sql` : table `obs_categories`, deux colonnes de `obs_webhooks`).

### Catégories
- Page « Catégories » de l'admin : désactiver des catégories nationales pour l'instance, ajouter des catégories propres
  à l'instance (numéros à partir de 1000 ; nom, nom anglais, couleur, résolvable).
- `get_categories.php` : catégories de l'instance au format de `categorielist.json` ; l'application web et le site
  l'utilisent et se rabattent sur la liste nationale pour les instances antérieures.

### Webhooks
- Correspondance des catégories par webhook : code de chaque catégorie dans l'outil appelé (`{{categorie_code}}`, par
  exemple le `service_code` Open311), et option pour n'envoyer que les catégories qui ont un code.

### Scopes et villes
- Page Scopes : carte (OpenStreetMap, Leaflet servi localement) pour tracer le rectangle du territoire, choisir le
  centre et le zoom des cartes (vue de la carte ou marqueur déplaçable) et rechercher un lieu.
- Identifiant de scope vérifié : `XX_nom` où `XX` est un numéro de département (01 à 95, 2A, 2B, 971 à 976) ou un code
  pays, et `nom` sans espace ni caractère spécial ; le département se remplit à partir de l'identifiant.
- Page Villes : import des communes françaises du territoire d'un scope (geo.api.gouv.fr : nom, code postal, surface,
  population), avec carte, sélection et doublons ignorés.

### Admin
- Villes, comptes et catégories de l'instance : listes en lecture, création et modification dans une fenêtre ; scopes :
  création dans une fenêtre, puis modification sur la carte de chaque scope (plus d'entrée vide à compléter). Les
  valeurs refusées rouvrent la fenêtre avec la saisie.
- Tableaux sans défilement horizontal : colonnes secondaires masquées sur les écrans étroits, observations et listes
  affichées en blocs sur tablette et mobile.
- Photos ouvertes dans une fenêtre plutôt qu'un nouvel onglet ; image par défaut si la photo manque.
- Cartes OpenStreetMap de l'admin : tuiles demandées avec l'origine du site (« Access blocked » corrigé).
- Champ « Texte de partage par défaut » retiré (il servait à Twitter) ; `tweet_content` reste renvoyé par `get_scope.php`.

### API
- `get_photo.php` et `generate_panel.php` répondent 404 (`PHOTONOTFOUND`) quand l'observation n'a pas de photo ; les
  applications affichent une image par défaut.

## 0.0.22

Version de sécurité et de maintenance. L'API reste compatible avec les applications : les réponses de
toutes les routes publiques sont vérifiées identiques à la 0.0.21 (tests de contrat, PHP 7.3 et 8.3), sauf
l'image renvoyée par `generate_panel.php` (voir « Fonctionnalités retirées »).

### Fonctionnalités retirées
- **Twitter** : publication automatique ou depuis l'admin, comptes Twitter (supprimés de la base, ils
  contenaient des secrets d'API), modèles de tweets, bibliothèque codebird.
- **Panneaux** : `generate_panel.php` ne génère plus d'image composée (photo, carte, textes). La route est
  conservée pour la compatibilité et renvoie la photo de l'observation, pixelisée tant qu'elle n'est pas
  approuvée, avec les mêmes paramètres (`token`, `s`, `secretid`, `key`) et les mêmes erreurs.
- **MapQuest** : plus de carte ni de clé d'API (#278 : plus besoin de compte MapQuest).
- Le champ `twitter` de `get_scope.php` est toujours renvoyé (valeur existante) mais n'est plus modifiable.

### Sécurité
- Injections SQL corrigées (`mosaic.php` sans authentification, commentaires tronqués après échappement,
  formulaires de l'admin, import des villes).
- XSS de l'admin corrigés, jeton CSRF sur toutes les actions de l'admin.
- Contrôle d'accès de l'admin, sessions durcies, limitation des tentatives de connexion,
  anciens mots de passe SHA-256 re-hachés automatiquement.
- Floutage appliqué à toutes les tailles d'image et renforcé ; plus d'image non floutée en cache.
- `install.php` ne peut plus créer d'administrateur sur une instance installée.
- Photo d'une observation approuvée non remplaçable ; upload validé avant écriture.
- Limitation des créations par IP (anti-spam, #139).
- Comptes citystaff limités à leurs villes (#240).
- Journal des actions privilégiées (#75) et vérifications de sécurité dans l'admin.

### Mises à jour
- Version unique (`app/includes/version.php`), migrations automatiques et rejouables, refus du retour arrière.
- Mise à jour depuis l'admin : archive signée, sauvegarde, migrations, retour arrière automatique.
- Docker : image PHP 8.3 publiée par la CI avec des tags glissants, mise à jour depuis l'admin avec Watchtower.

### Admin
- Interface modernisée (Bootstrap 5, thème sombre, responsive), sans ressource externe.
- Tableau de bord, notes privées des modérateurs (#266), gestion des villes des comptes citystaff (#237, #270),
  réglages regroupés.
- Les modérateurs voient les photos non pixelisées dans l'admin (`admin/photo.php`, réservé aux sessions admin).

### Floutage des photos
- Serveur de floutage optionnel (`blur-server/`, service `blur` du docker-compose, image
  `ghcr.io/jesuisundesdeux/vigilo-blur`) : visages et plaques d'immatriculation masqués sur chaque photo
  envoyée (observations et résolutions), sur CPU, sans service externe.
- Remplace l'appel spécifique à SGBlur : réglage générique « Serveur de floutage » (ancienne URL reprise)
  ou variable `VIGILO_BLUR_URL` ; tout serveur compatible (dont SGBlur) reste utilisable.
- Si le serveur échoue, la photo est enregistrée telle qu'envoyée (journalisé, en-tête `X-Vigilo-Blur: failed`) :
  la modération manuelle reste le garde-fou, l'envoi n'échoue jamais à cause du floutage.

### Webhooks
- Page « Webhooks » de l'admin : à chaque publication d'une observation (validation par un modérateur, depuis
  l'admin ou `approve.php`), appel d'un ou plusieurs endpoints HTTP (POST, PUT, PATCH ou GET).
- URL, en-têtes et corps personnalisables avec des variables (`{{token}}`, `{{comment}}`, `{{photo_url}}`,
  `{{observation_url}}`, `{{lat}}`…) échappées selon le format (JSON, formulaire, texte).
- Menu « Modèle » qui préremplit le formulaire : Mastodon, Slack / Mattermost, Discord, Bluesky (relais), ticketing de
  collectivité (Open311), Redmine, JSON générique ou vide (exemples détaillés dans `doc/WEBHOOKS.md`).
- Appels en parallèle après la réponse à l'application (5 s au plus, `VIGILO_WEBHOOK_TIMEOUT`), journal des envois,
  bouton de test ; un endpoint en échec n'empêche jamais la publication.

### Fonctionnalités et corrections
- Option pour masquer les observations résolues depuis N jours (#257).
- Image en base64 dans un corps JSON (#267), documentation de l'envoi d'image.
- Compatibilité PHP 8.x (#285), code toujours compatible PHP 7.3.
- `delete.php` sans notice PHP dans le JSON.
- Docker : volumes `images/` et `caches/` accessibles en écriture à Apache sur une installation neuve.
- Catégories et liste des instances mises en cache (plus de 500 si GitHub ne répond pas).
- `mosaic.php` sans scripts tiers.
