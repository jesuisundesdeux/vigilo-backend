# Changelog

## 0.0.26

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
