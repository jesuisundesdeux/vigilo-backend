# Changelog

## 0.0.24

### Docker
- Watchtower (mise à jour de l'image depuis l'admin) : image `nickfedor/watchtower`, version maintenue de
  `containrrr/watchtower`, archivée, qui ne démarre plus avec Docker Engine 29 (`client version 1.25 is too old`).
  Reprendre le `docker-compose.yml` (ou `WATCHTOWER_IMAGE=nickfedor/watchtower:1`), voir doc/UPGRADE.md.
- Le bouton de l'admin appelle l'API de Watchtower en POST (seule méthode acceptée par la nouvelle image).

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
- Villes, comptes, scopes et catégories de l'instance : listes en lecture, création et modification dans une fenêtre
  (plus d'entrée vide à compléter) ; les valeurs refusées rouvrent la fenêtre avec la saisie.
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
