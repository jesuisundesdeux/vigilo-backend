# Guide du code

Ce guide s'adresse à un développeur qui reprend le code du backend sans le connaître. Il décrit **comment le code
fonctionne** : cycle d'une requête, rôle de chaque fichier et de chaque fonction, données manipulées, sous-systèmes
(images, webhooks, mises à jour, floutage), tests, recettes pour les modifications courantes et pièges connus.

Il complète, sans les répéter :

- [ARCHITECTURE.md](ARCHITECTURE.md) : arborescence, variables d'environnement, liste des tables ;
- [REST_API.md](REST_API.md) : paramètres et réponses de chaque route, compatibilité par version ;
- [FONCTIONNEMENT.md](FONCTIONNEMENT.md) : cycle d'une observation, statuts, rôles, vu de l'utilisateur ;
- [GUIDE_CONTRIBUTION.md](GUIDE_CONTRIBUTION.md) et [tests/README.md](../tests/README.md) : lancer l'environnement et
  les tests ;
- [UPGRADE.md](UPGRADE.md), [WEBHOOKS.md](WEBHOOKS.md), [GLOSSAIRE.md](GLOSSAIRE.md),
  [blur-server/README.md](../blur-server/README.md).

Les règles impératives (PHP 7.3, compatibilité de l'API, SQL, admin, migrations) sont résumées dans
[AGENTS.md](../AGENTS.md) ; ce guide explique leur raison d'être dans le code.

## Sommaire

1. [Vue d'ensemble du code](#1-vue-densemble-du-code)
2. [Modules partagés (`app/includes/`)](#2-modules-partagés-appincludes)
3. [Routes publiques](#3-routes-publiques)
4. [Administration](#4-administration)
5. [Modèle de données](#5-modèle-de-données)
6. [Chaîne de traitement des images](#6-chaîne-de-traitement-des-images)
7. [Sous-systèmes](#7-sous-systèmes)
8. [Tests et intégration continue](#8-tests-et-intégration-continue)
9. [Recettes](#9-recettes)
10. [Pièges connus et dette technique](#10-pièges-connus-et-dette-technique)

---

## 1. Vue d'ensemble du code

Le code servi est entièrement dans `app/` (racine du site web). Il n'y a ni routeur, ni autoloader, ni framework :
**chaque URL est un fichier PHP**, qui inclut lui-même les fichiers de `app/includes/` dont il a besoin, puis exécute son
traitement de haut en bas. Les fonctions partagées sont des fonctions globales ; l'état partagé passe par quelques
variables globales.

### 1.1 Cycle d'une requête publique

Exemple type (`app/approve.php`, simplifié) :

```php
$cwd = dirname(__FILE__);
require_once("{$cwd}/includes/common.php");     // config, connexion, $acls, $status_list
require_once("{$cwd}/includes/functions.php");  // jsonError, getrole, catégories, cache…
require_once("{$cwd}/includes/webhooks.php");   // seulement si la route en a besoin

header('BACKEND_VERSION: ' . BACKEND_VERSION);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$error_prefix = "APPROVE";
// … lecture et échappement des paramètres, contrôle de la clé …
if (getrole($key, $acls) != "admin" && getrole($key, $acls) != "moderator") {
    jsonError($error_prefix, "Unauthorized access.", "ACCESSDENIED", 403);   // exit
}
// … SQL, fichiers …
echo json_encode(array('status' => '0'));
```

Étapes, dans l'ordre où le code les exécute :

1. **`includes/common.php`** (inclus par toutes les routes) :
   - `ini_set('display_errors', 'Off')` : les erreurs PHP partent dans le journal, jamais dans la réponse ;
   - charge `app/config/config.php` (`dirname(__FILE__) . "/../config/config.php"`). S'il manque, le script affiche
     « Fichier config/config.php manquant » et s'arrête (code HTTP 200). Ce fichier définit `$config['MYSQL_HOST']`,
     `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE` ; en Docker c'est une copie de `config/config.php.docker` qui lit les
     variables d'environnement par `getenv()`. Modèle pour une installation classique : `app/config/config.php.tpl`.
     Clés facultatives reconnues par le code : `DATA_PATH` (préfixe des répertoires `images/` et `caches/`, vide par
     défaut) et `SAAS_MODE` (masque certaines pages de l'admin) ;
   - inclut `includes/version.php` (`BACKEND_VERSION`) ;
   - `mysqli_report(MYSQLI_REPORT_OFF)` : depuis PHP 8.1, mysqli lève des exceptions par défaut ; le code vérifie
     lui-même les retours (`if (!$query)`), ce réglage garde le comportement de PHP 7 ;
   - ouvre la connexion `$db` (en cas d'échec : `error_log` puis `exit()` sans corps, donc HTTP 200 vide) ;
   - `SET sql_mode = ''` : désactive le mode strict de MySQL (insertion de chaînes vides dans des colonnes entières,
     troncature silencieuse…). Une grande partie du code ancien en dépend ;
   - lit toute la table `obs_config` et copie six réglages dans `$config` : `vigilo_urlbase` → `URLBASE`,
     `vigilo_http_proto` → `HTTP_PROTOCOL`, `vigilo_name` → `VIGILO_NAME`, `vigilo_language` → `VIGILO_LANGUAGE`,
     `mysql_charset` → `MYSQL_CHARSET`, `vigilo_timezone` → `VIGILO_TIMEZONE` ;
   - définit `$config['VIGILO_CONF_URL']` (configuration partagée : vigilo-conf, ou variable `VIGILO_CONF_URL`),
     `$config['VIGILO_WEBAPP_URL']` (`https://app.vigilo.city/`, ou variable `VIGILO_WEBAPP_URL`) et
     `$config['CATEGORIES_NATIONAL_URL']` (`categorielist.json` de cette configuration) ;
   - applique le fuseau horaire et le jeu de caractères de la connexion ;
   - construit **`$acls`** : `role_name => liste des role_key` à partir de **toute** la table `obs_roles` (une requête
     par appel de route) ;
   - définit **`$status_list`** : les statuts 0 à 4 avec leur nom, les rôles autorisés à les poser et les statuts
     suivants permis (`nextstatus`), utilisé par la page Résolutions de l'admin.
2. **En-têtes** : chaque route envoie elle-même `BACKEND_VERSION: x.y.z` (nom d'en-tête historique avec un « _ »), son
   `Content-Type` et, pour la plupart, `Access-Control-Allow-Origin: *` (voir le tableau de la section 3). Il n'y a pas
   de gestion générale du pré-vol CORS : seule `add_image.php` répond aux requêtes `OPTIONS`.
3. **Paramètres** : lus directement dans `$_GET` / `$_POST`, échappés avec `mysqli_real_escape_string($db, …)` ou
   convertis (`intval`) avant d'entrer dans une requête SQL.
4. **Authentification** : il n'y a pas de session pour l'API. Les appels privilégiés passent la clé d'un compte en
   paramètre `key` ; `getrole($key, $acls)` renvoie le nom du rôle (`admin`, `moderator`, `citystaff`, `guest`) ou
   `False`. L'auteur d'une observation est reconnu par son `secretid`.
5. **Erreurs** : `jsonError($prefix, $message, $code, $http_status, $severity = "FATAL")` écrit
   `[FATAL] PREFIX: CODE - message` dans le journal PHP, puis, si la sévérité est `FATAL`, envoie le code HTTP et le
   corps `{"error": {"status", "code", "message"}}` (JSON indenté) et **termine le script** (`exit`). Avec une autre
   sévérité (`"WARNING"`), il journalise seulement et le script continue. Le `Content-Type` n'est pas modifié par
   `jsonError` : une route qui a déjà envoyé `image/jpeg` renvoie ses erreurs JSON avec ce type (voir section 10).
6. **Réponse** : `echo json_encode(...)`. Plusieurs fichiers se terminent par `?>` : attention aux espaces ou lignes
   vides après cette balise, ils seraient envoyés dans la réponse.

### 1.2 Variables globales

| Variable | Défini dans | Contenu |
|---|---|---|
| `$db` | `common.php` | Connexion mysqli. Les fonctions des includes y accèdent par `global $db` ou la reçoivent en paramètre (code récent). |
| `$config` | `config/config.php` puis `common.php` | Connexion MySQL, `DATA_PATH`, `SAAS_MODE`, les six réglages copiés depuis `obs_config`, `VIGILO_CONF_URL`, `VIGILO_WEBAPP_URL`, `CATEGORIES_NATIONAL_URL`. |
| `$acls` | `common.php` | `array('admin' => array(clé, …), 'moderator' => …)`. |
| `$status_list` | `common.php` | Statuts d'observation (voir section 5.2). |
| `BACKEND_VERSION` | `includes/version.php` | Seule source de la version (lue aussi par l'entrypoint Docker, la CI et `build-release.sh`). |
| `$error_prefix`, `$cwd` | chaque route | Préfixe des messages de journal, répertoire du script. |

### 1.3 Cycle d'une requête de l'administration

`app/admin/index.php` est le **seul point d'entrée** des pages de l'admin ; les pages sont des fragments
`app/admin/inc/<page>.php` inclus dans sa mise en page.

1. `require_once('../includes/security.php')`, puis `vigilo_session_start()` : cookie de session `VIGILOADMIN`
   (`httponly`, `SameSite=Lax`, `secure` si HTTPS ou `X-Forwarded-Proto: https`), `session.use_strict_mode`, expiration
   après 8 heures d'inactivité (`$_SESSION['last_activity']`).
2. `vigilo_admin_headers()` : `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: same-origin`, `Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'`
   (pas de `script-src` : les scripts en ligne des pages fonctionnent).
3. Sans `$_SESSION['login']` et `$_SESSION['role']` : redirection vers `login.php`.
4. Inclusion de `common.php`, `functions.php`, `handle.php`, `webhooks.php`.
5. Tableau **`$menu`** : `page => array(icon, name, access)` ; `access` est la liste des rôles autorisés. Une page
   absente du menu ou un `page` inconnu donne `dashboard`.
6. `$page_allowed = in_array($_SESSION['role'], $menu[$page_name]['access'], true)`.
7. `csrf_protect_request()` : toute requête POST, ou GET portant un paramètre `action`, doit contenir le jeton CSRF de
   la session (`csrf_token`, en POST ou en GET). Sinon `$_POST` est vidé et `$_GET['action']` supprimé : la page
   s'affiche normalement, avec l'avertissement « Action ignorée : jeton de sécurité absent ou expiré ».
8. Indicateur du menu : nombre d'observations complètes en attente de modération (la première configuration est
   suivie à l'accueil, voir `dashboard.php` en §4.1).
9. Mise en page Bootstrap 5 (barre latérale, thème clair/sombre mémorisé dans `localStorage`), puis
   `include('inc/' . $page_name . '.php')` si `$page_allowed`, sinon « Accès non autorisé pour ce rôle ».
10. Fenêtre `#photoModal` commune et scripts `assets/vendor/bootstrap/bootstrap.bundle.min.js`, `assets/admin.js`.

Chaque page commence par le même contrôle, qui empêche aussi l'appel direct de `inc/<page>.php` :

```php
if (!isset($page_name) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $menu[$page_name]['access'])) {
    exit('Not allowed');
}
```

Une page traite d'abord ses **actions** (POST ou lien GET `action=…`), affiche les messages, puis construit
l'affichage. Le traitement et l'affichage sont dans le même fichier.

**Connexion** (`app/admin/login.php`) : vérifie le jeton CSRF du formulaire, la limitation (`login_is_throttled`),
cherche le compte par `role_login` **parmi les rôles `admin` et `citystaff` seulement** (un `moderator` n'a pas accès
au panneau, il modère par l'API avec sa clé). Mot de passe : `password_verify`, ou ancien hash SHA-256 non salé
accepté une fois puis remplacé par `password_hash` ; un login inconnu coûte un `password_verify` factice (temps
constant). En cas de succès : `session_regenerate_id(true)`, `$_SESSION = array('login', 'role', 'last_activity')`,
`audit_log('login')`. En cas d'échec : `login_record_failure`.

**Déconnexion** (`app/admin/logout.php`) : uniquement en POST avec jeton CSRF valide (bouton du menu), puis
redirection vers `login.php`.

**Photos de l'admin** (`app/admin/photo.php`) : voir section 6.5.

---

## 2. Modules partagés (`app/includes/`)

Aucun de ces fichiers ne fait quoi que ce soit lorsqu'il est appelé directement par HTTP, à part `common.php` (qui se
connecte à la base et s'arrête sans rien afficher).

### 2.1 `common.php`

Voir 1.1. Pas de fonction ; définit `$db`, `$config`, `$acls`, `$status_list`.

### 2.2 `functions.php`

| Fonction | Rôle | Utilisée par |
|---|---|---|
| `vigilo_truncate($text, $length)` | Premiers caractères d'une chaîne UTF-8, avec `mb_substr` si mbstring est présent, sinon une expression régulière `/u`. | `create_issue`, `create_resolution`, notes de l'admin, `audit_log` |
| `tokenGenerator($length)` | `strtoupper(bin2hex(random_bytes($length)))` : `tokenGenerator(4)` donne 8 caractères hexadécimaux majuscules. | créations d'observations et de résolutions |
| `distance($lat1, $lng1, $lat2, $lng2, $unit = 'k')` | Distance orthodromique (rayon 6 378 137 m), en km par défaut, en mètres avec tout autre `$unit` (le code passe `'m'`). | `get_issues`, `sameas` |
| `delete_token_cache($token)` | Supprime `caches/<token>*` (images pixelisées de `generate_panel.php`). | approbation, modification, suppression, ajout de photo |
| `parseAdminDateTime($date, $time)` | Timestamp depuis `jj/mm/aaaa` et `hh:mm`, `False` si invalide (format strict, avertissements de `DateTime` refusés). | admin (observations, résolutions) |
| `getrole($privatekey, $acls)` | Nom du rôle de la clé, `False` si clé vide ou inconnue. | toutes les routes à clé |
| `vigilo_street_name($address)`, `vigilo_street_key($address)`, `vigilo_count_streets($addresses)`, `vigilo_ascii_lower($text)`, `vigilo_street_types()`, `vigilo_street_type_normalize($street)` | **Rues des observations** (admin, rapports, problèmes ; pas l'API publique) : rue sans numéro (`5`, `5 bis`, `12-14`, `7b`, virgule) et espaces réduits ; type de voie en toutes lettres avec une majuscule, premier mot seulement et jamais seul (0.0.37 : « rue » → « Rue », « bd » → « Boulevard », « av. » → « Avenue », « rte » → « Route »… ; même liste en JavaScript dans `js/street-lines.js`, `window.vigiloStreetType`, utilisée par la clé de `problem-map.js` et la recherche Overpass) ; clé de comparaison sans casse, accents, espaces ni ponctuation, type développé (« avenue de lodève » = « Avenue De Lodeve » = « 5 av. de lodeve ») ; décompte par rue sous l'orthographe la plus fréquente, trié. | `report.php`, `problems.php`, admin |
| `flatstring($string)` | Minuscules, sans espaces ni tirets : comparaison souple des noms de villes et des adresses. | `create_issue`, `sameas`, admin |
| `sameas($token, $filter)` | Tokens des observations « similaires » à `$token` : même catégorie (`fcategorie`), même ville (`faddress`), puis même adresse aplatie **ou** distance < `$filter['distance']` m (`fdistance`). Parcourt toutes les observations correspondantes en PHP. | recherche « Similaires » de l'admin (l'application web applique les mêmes règles, `similar-issues.js`) |
| `removeEmoji($text)` | Supprime quatre plages d'émojis (les colonnes de `obs_list` sont en `utf8` sur 3 octets et ne peuvent pas stocker les caractères de 4 octets). | `create_issue`, `create_resolution` |
| `jsonError(...)` | Voir 1.1. | partout |
| `getCachedRemoteJson($url, $cache_name, $ttl = 86400)` | JSON distant mis en cache dans `caches/remote_<nom>.json` ; si la source est injoignable, la dernière copie est utilisée même périmée ; sinon `array()`. | catégories, citylist |
| `getNationalCategoriesList()` | `categorielist.json` de vigilo-conf, cache `remote_categories.json`, 1 heure. | catégories |
| `getCategoriesList()` | Catégories de l'instance (section 7.2). | `get_categories.php`, webhooks, admin |
| `getInstanceNameFromFirebase($scope)` | Nom de l'instance dans `citylist.json` de la configuration partagée (`VIGILO_CONF_URL`, cache `remote_citylist.json`, 24 h ; 5 min pour une configuration autre que vigilo-conf, `vigilo_conf_is_custom()`) dont le `scope` correspond ; sert à construire les liens `<VIGILO_WEBAPP_URL>?instance=…`. Le nom rappelle l'ancien hébergement de la liste (Firebase). | webhooks |
| `getWebContent($url)` | GET avec curl (agent `Vigilo-Backend/<version>`, connexion 5 s, total 10 s, 3 redirections), `false` si erreur ou HTTP ≥ 400. | tous les appels sortants simples |
| `generategroups()`, `get_data_from_gps_coordinates()` | Code mort (plus appelé). | — |

### 2.3 `handle.php`

Accès aux observations et aux résolutions. **Attention : la plupart de ces fonctions insèrent leurs arguments tels
quels dans le SQL** (code ancien) ; l'appelant doit les avoir échappés ou convertis en entier.

| Fonction | Rôle |
|---|---|
| `getRoleCityIds($db, $login)` | Identifiants des villes d'un compte citystaff. `obs_roles.role_city` contient un tableau JSON de noms de villes, ou une liste séparée par des virgules pour les comptes non réenregistrés depuis 0.0.21 ; chaque nom est cherché dans `obs_cities.city_name`. Échappe ses paramètres. |
| `getObsIdByToken($token)`, `getTokenByObsid($obsid)` | Conversion token ↔ `obs_id`, `False` si absent. |
| `isTokenExists($token)`, `isTokenWithSecretId($token, $secretid)` | Existence d'une observation, vérification du secret de l'auteur. |
| `isScopeExists($scope)` | Code mort. |
| `deleteObs($obsid)` | Suppression complète depuis l'admin : ligne `obs_list`, photo `images/<token>.jpg`, cache, retrait de toutes les résolutions (`delObsToResolution`). |
| `getResolutionIdByResolutionToken($t)`, `isResolutionTokenExists($t)`, `isResolutionTokenWithSecretId($t, $s)` | Équivalents pour les résolutions. |
| `addResolution($fields, $obsidlist)` | Insère une résolution (`$fields` déjà échappés) puis la lie à chaque observation ; `False` si la liste est vide. |
| `addObsToResolution($obsid, $resid)`, `delObsToResolution($obsid, $resid)` | Lien observation ↔ résolution ; la suppression du dernier lien supprime la résolution orpheline. |
| `delResolution($resid)` | Retire toutes les observations (donc supprime la résolution). |
| `updateResolution($fields, $resid)` | Met à jour `resolution_comment`, `resolution_time`, `resolution_status` (seules clés retenues). Passer au statut 1 (résolue) lève une `Exception` si la date est 0 ou si une des observations figure dans plusieurs résolutions. Vide le cache des observations liées. |
| `getResolutionObservations($resid)`, `getDuplicateObsIdsInResolutions()` | Observations d'une résolution ; observations présentes dans plusieurs résolutions. |
| `flushImagesCacheResolution($resid)` | `delete_token_cache` de chaque observation de la résolution. |
| `getResolutionStatus($obsid)` | Statut le plus avancé des résolutions de l'observation (0 sans résolution) ; badge de la page Observations de l'admin (Résolue, Indiquée résolue, En cours de résolution, Prise en compte). |

### 2.4 `security.php`

| Fonction | Rôle |
|---|---|
| `h($value)` | `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` : échappement de toute sortie HTML de l'admin. |
| `vigilo_is_https()` | HTTPS direct ou derrière un proxy (`X-Forwarded-Proto`). |
| `vigilo_session_start()`, `vigilo_admin_headers()` | Voir 1.3. |
| `csrf_token()`, `csrf_field()`, `csrf_query()`, `csrf_valid()`, `csrf_protect_request()` | Jeton CSRF de session (64 caractères hexadécimaux) : champ caché pour les formulaires, suffixe `&csrf_token=…` pour les liens d'action, vérification `hash_equals`. |
| `vigilo_client_ip()` | `$_SERVER['REMOTE_ADDR']` (derrière un proxy, c'est Apache `mod_remoteip` qui le corrige, voir `config/remoteip.conf` ; autres proxys : variable `VIGILO_TRUSTED_PROXIES`, écrite par l'entrypoint dans `remoteip-proxies.conf`). |
| `login_is_throttled($db, $login)`, `login_failures()`, `login_record_failure()`, `login_clear_failures()` | Limitation des connexions, table `obs_login_attempts` : refus après 5 échecs pour le couple login + IP, ou 10 échecs pour le login **ou** l'IP, sur 15 minutes. Les tentatives de plus de 24 h sont purgées à chaque échec. |
| `audit_log($action, $target = '', $details = '')` | Ligne dans `obs_audit_log` avec login, rôle et IP de la session ; `$details` tableau → JSON ; tronqué à 2 000 caractères. |
| `password_link_create()`, `password_link_find()`, `password_link_use()`, `password_link_url()` (`password_links.php`, 0.0.34) | Liens pour choisir le mot de passe d'un compte : jeton aléatoire de 256 bits (64 hex), seule son empreinte SHA-256 dans `obs_password_links` ; un nouveau lien supprime les précédents du compte ; valable `VIGILO_PASSWORD_LINK_HOURS` (72 h), une seule fois (`link_used` mis à jour avant le mot de passe, contrôle de `mysqli_affected_rows`) ; compte avec login seulement ; adresse : `vigilo_urlbase` + `/admin/password.php`, sinon l'hôte de la requête. |
| `api_rate_limited($db, $kind, $max, $window)` | Anti-spam de l'API, table `obs_rate_limit` : `true` si l'IP a déjà fait `$max` requêtes de ce type sur `$window` secondes, sinon enregistre la requête. Purge des lignes de plus de 24 h une fois sur 50. |

### 2.5 `images.php`

| Fonction | Rôle |
|---|---|
| `isGoodImage($fn)` | Refuse une image de moins de 50 × 50 px, ou dont le coin inférieur droit (5 × 5 px) contient au moins 12 pixels gris `#808080`, signe d'un JPEG tronqué complété par GD. |
| `hasAllowedType($filepath)` | `exif_imagetype() == IMAGETYPE_JPEG` (seul format accepté). |
| `saveImageOnDisk($method, $filepath, $error_prefix)` | `method=base64` → `saveImageOnDiskFromBase64`, sinon `saveImageOnDiskFromStdinOrInput`. |
| `saveImageOnDiskFromBase64(...)` | Lit `$_POST['imagebin64']` ou un corps JSON `{"imagebin64": "…"}`, retire un préfixe `data:…,`, remplace `-`→`+`, `_`→`/`, espace→`+` (base64 « URL » et `+` transformés en espaces par l'encodage de formulaire), décode et écrit. |
| `saveImageOnDiskFromStdinOrInput(...)` | Écrit `php://stdin` s'il n'est pas vide, sinon `php://input` ; sinon `jsonError … IMAGEUPLOADFAILED` (500). |
| `pixalize($filepath)` | Image GD pixelisée par blocs de 1/25 du plus grand côté, puis flou gaussien. |
| `resizeImage($image, $maxWidth, $maxHeight)` | Réduction proportionnelle dans la boîte donnée (jamais d'agrandissement). |

### 2.6 `blur.php`

| Fonction | Rôle |
|---|---|
| `blur_server_url($db)` | Réglage `vigilo_blur_url` d'`obs_config` s'il est rempli, sinon variable d'environnement `VIGILO_BLUR_URL`, sinon `''` (pas de floutage). |
| `blur_photo($url, $filepath)` | Envoie le fichier en multipart (champ `picture`), délai `VIGILO_BLUR_TIMEOUT` (60 s par défaut), protocoles HTTP(S) seulement. Remplace le fichier par la réponse si c'est une image (convertie en JPEG qualité 90 si ce n'en est pas un). Renvoie `null` si tout s'est bien passé, sinon le motif de l'échec (le fichier reste tel quel). |

### 2.7 `webhooks.php`

Voir section 7.1. Fonctions : `webhook_variables()`, `webhook_observation_values()`, `webhook_value()`,
`webhook_escape()`, `webhook_render()`, `webhook_category_map()`, `webhook_category_code()`, `webhook_filter_ids()`,
`webhook_matches()`,
`webhook_build_request()`, `webhooks_for_event()`, `webhooks_deliver()`, `webhook_approval_state()`,
`webhooks_on_approval()`, `webhook_events()`, `webhook_hook_events()`, `webhook_resolution_values()`,
`webhook_resolution_state()`, `webhooks_for_observation()`, `webhooks_for_resolution()`,
`webhooks_on_resolution_status()`, `vigilo_send_response_and_continue()`. Constantes `VIGILO_WEBHOOK_EVENT_*` (5 événements),
(`observation.approved`) et `VIGILO_WEBHOOK_LOG_KEEP` (500).

### 2.8 `migrations.php`

Voir section 7.4. Fonctions : `vigilo_migrations_list()`, `vigilo_table_exists()`, `vigilo_db_version()`,
`vigilo_migrations_pending()`, `vigilo_migration_statements()`, `vigilo_set_db_version()`, `vigilo_migrate()`.
Constante `VIGILO_MIGRATIONS_DIR`. Ce fichier n'inclut pas `common.php` : il est utilisé aussi en ligne de commande
par `scripts/vigilo-migrate.php`.

### 2.9 `updater.php`, `release_key.php`, `version.php`

`updater.php` (mise à jour depuis l'admin, section 7.3) inclut `migrations.php` et `release_key.php`.
`release_key.php` définit `VIGILO_RELEASE_PUBLIC_KEY` (clé publique ed25519 en base64, **vide** actuellement : seule la
somme SHA-256 est alors vérifiée). `version.php` définit `BACKEND_VERSION`.

### 2.10 `report.php` — rapport pour une collectivité

Utilisé par `admin/report.php` (page « Rapports »). Fonctions pures autant que possible :

| Fonction | Rôle |
|---|---|
| `report_category_list()`, `report_category($categories, $id)`, `report_month_names()`, `report_date()`, `report_number()`, `report_photo_src($token, $width)` | Aides partagées par `admin/report.php` et `admin/problem_report.php` : catégories (nom, couleur), mois abrégés, date `jj/mm/aaaa`, nombre à la française, photo d'une observation (`photo.php`, ou `assets/photo-missing.svg` sans fichier). `report_photo_exists($token)` ; `report_recent_photos($observations, $count)` (0.0.36) : photos montrées pour un lieu récurrent, le détail d'un problème et « Dernières photos » du rapport d'un problème : les plus récentes **qui existent**, au plus `$count`, sinon l'observation la plus récente (image par défaut). `report_observation_url($db, $token)`, `report_token_link($db, $token)` (0.0.37) : lien vers l'observation dans l'application web (`VIGILO_WEBAPP_URL`, `instance=` d'après le scope, comme `{{observation_url}}`). `report_recent_comments($observations, $count)`, `report_comments_html($db, $comments)` (0.0.37) : commentaires non vides les plus récents, sans doublon, avec le lien de l'observation et sa date. |
| `report_filters($get, $allowed_cities)` | Filtres de la requête : `cities[]`, `categories[]` (ids), `from` / `to` (`aaaa-mm-jj`, 12 derniers mois par défaut, inversés si besoin), `radius` (25, 50, 100, 200 m ; 50 par défaut), `title` (120 car.). `$allowed_cities` (citystaff) : les villes demandées sont limitées aux siennes, toutes ses villes si aucune. |
| `report_observations($db, $filters, $start, $end)` | Avec `$filters['ids']` : ces observations seulement (rapport d'un problème). Observations publiées (`obs_complete=1`, `obs_approved=1`, **archivées comprises**) des filtres et de la période, avec leur état (le plus avancé de leurs résolutions, comme `get_issues.php`) et la date de leur résolution résolue. Ville : `city_name`, sinon `obs_cityname`, sinon la partie après la virgule de l'adresse. |
| `report_groups($observations, $radius, $mode = 'both')` | **Observations similaires regroupées** : même catégorie, et à moins de `$radius` m de la première du groupe ou dans la même rue (`report_street()` = `vigilo_street_key()`, à moins de 1 km) ; `$mode` (0.0.37) : `radius` = distance seulement, `street` = même rue de la même ville quelle que soit la distance (sans rue : ignorée). Chaque groupe : adresse la plus fréquente, villes, nombre, première / dernière date, états, centre, photo de la plus récente. Triés par nombre puis récence. |
| `report_stats(...)` | Chiffres clés : total, moyenne par mois, évolution par rapport à la période précédente de même durée, pour 10 000 habitants (`city_population` des villes), taux de résolues et de prises en charge (états 1 à 4), délai médian de résolution (jours), lieux, lieux récurrents (groupes ≥ 2) ; répartitions par mois, catégorie, ville, rue (10), jour de la semaine, heure. |
| `report_sections()`, `report_chosen_sections($get, $is_admin)` | Sections du rapport dans leur ordre (clé => titre, réservée aux administrateurs, cochée par défaut) ; choisies par `sections[]` avec `s=1` (formulaire), sinon (anciens liens) celles par défaut, les sections des problèmes seulement avec `problems=1` ; jamais celles des administrateurs pour un citystaff. |
| `report_svg_strip($months, $title, $milestones = array())` | **Frise chronologique** compacte et horizontale : une case par mois (`'AAAA-MM' => n`), regroupées par trimestre au-delà de 48 mois et par année au-delà de 144, couleur de `report_heat_ramp()` / `report_heat_step()` (rampe partagée avec la matrice), années sous les cases (sans chevauchement), échelle et période la plus chargée ; fiche et détail d'un problème (`problem_time_charts()['timeline']`). `$milestones` (0.0.37, liste de `array('time', 'label')`) : bande au-dessus des cases, un drapeau numéroté par période (numéros des jalons de la période, infobulle avec dates et libellés). `report_months_cover($months, $times)` étend les mois (à 0, sans trou, 240 au plus) pour couvrir les jalons ; `report_milestones_html($milestones, $with_text)` : légende numérotée (`ol.milestones`, date, libellé, texte de la note si `$with_text`). |
| `report_week_grid($observations)`, `report_svg_heatmap($grid, $title)` | Matrice jours × heures (7 × 24, lundi d'abord) et son SVG : cases d'autant plus foncées que les observations sont nombreuses (même rampe de 7 couleurs et mêmes paliers que les statistiques de l'application web), échelle « moins / plus », case la plus fréquente ; utilisée par la section « Jours et heures », la fiche et le détail d'un problème. |
| `report_svg_columns()`, `report_svg_bars()`, `report_svg_stack()` | Graphiques en SVG générés côté serveur (barres verticales, horizontales, proportions avec légende) : pas de bibliothèque, imprimés tels quels. |

### 2.12 `addresses.php` — adresses des observations

Fonctions pures (testées par `tests/problems/unit.php`), utilisées par `create_issue.php` et la page Observations
(« Qualité des adresses »). Convention : `obs_address_string` = la rue **sans numéro ni ville**, la ville dans
`obs_city` (ville référencée) ou `obs_cityname` (texte libre) ; `get_issues.php` recompose « rue, ville ».

| Fonction | Rôle |
|---|---|
| `address_tidy($text)` | Espaces réduits, apostrophes droites, virgules et parties vides nettoyées. |
| `address_city_key($name)` | Clé de comparaison d'un nom de ville : `vigilo_street_key()` après « St » → « Saint », « Ste » → « Sainte ». |
| `address_parse($address)` | `array(street, city, postcode)` : dernière partie après une virgule = ville, codes postaux (5 chiffres) et pays (« France ») retirés, numéro seul rattaché à sa rue (« 12, rue X »). |
| `address_match_city($name, $postcode, $cities)` | Id de la ville référencée de ce nom (clé), sinon, sans nom, de ce code postal s'il n'y en a qu'une ; 0 sinon. `$cities` = `id => array(name, postcode)`. |
| `address_fix($row, $cities)` | Correction d'une observation : ville reconnue dans l'adresse (`city`), ville libre d'une ville référencée (`city_known`), ville extraite non référencée (`city_extracted`), ville en double retirée (`city_duplicate`), numéro de bâtiment retiré (`number`, `vigilo_street_name()`), type de voie en toutes lettres (`street_type`, `vigilo_street_type_normalize()`, 0.0.37), mise en forme (`format`). Une adresse en deux parties dont la seconde n'est pas la ville (« Résidence X, Rue Y ») est gardée. |
| `address_spelling_fixes($rows)`, `address_spelling_score($name)` | Même rue (`vigilo_street_key()`) écrite différemment dans la même ville : orthographe la plus fréquente, à égalité celle avec accents et majuscules (`spelling`). |
| `address_format_details($before, $after)`, `address_visible_html($address)` | (0.0.37) Ce que change une correction « Mise en forme » invisible une fois affichée (espaces en double, au début ou à la fin, insécables, virgules, apostrophe) ; adresse en HTML avec ses caractères invisibles montrés (`.addr-invisible` : ␣, ⍽, ⇥). Utilisés par la vue des corrections ; deux villes de même nom y sont suivies de leur numéro. |
| `address_cleanup_proposals($rows, $cities)`, `address_reason_labels()` | Corrections proposées (seulement les observations qui changent) : `obs_id => array(token, before, after, reasons)` ; libellés des raisons. |

### 2.11 `problems.php` — problèmes

Utilisé par la page « Problèmes » (`admin/inc/problems.php`). Une observation **correspond** à un problème si elle
est publiée (`obs_complete=1`, `obs_approved=1`), de l'une de ses catégories, dans sa période (`obs_time` entre
`problem_start` et `problem_end`, fin facultative) et dans sa zone. Nécessite `functions.php` (`distance()`).

| Fonction | Rôle |
|---|---|
| `problem_priorities()`, `problem_statuses()`, `problem_active_statuses()` | Valeurs stockées, libellés, couleurs des badges. Statuts actifs (rattachement automatique) : `open`, `in_progress`, `waiting`. |
| `problem_load($db, $id)`, `problem_category_ids($problem)` | Lecture d'un problème ; identifiants de `problem_categories` (liste séparée par des virgules, catégories nationales et de l'instance ; vide = toutes les catégories, 0.0.36). |
| `problem_polygon_points($json)` | Points d'un polygone (`[[lat, lon], …]`, 3 à 200 points, coordonnées valides), liste vide sinon. |
| `problem_bbox($problem)`, `problem_zone_contains($problem, $lat, $lon)` | Rectangle englobant de la zone (préfiltre SQL) ; test exact : distance au centre (cercle) ou lancer de rayon (polygone, concave compris). Aucun pour une zone de rues. |
| `problem_streets_list($json)`, `problem_street_from_text($text)`, `problem_street_text($street)` | Zone **de rues** (0.0.33) : `problem_streets` JSON `[{"street": "Rue X", "city": "Ville"}, …]` (ville vide : toutes les villes, 50 rues au plus, doublons retirés) ; saisie « Rue X, Ville » (coupée à la dernière virgule). |
| `problem_street_key($name)`, `problem_address_parts($address)`, `problem_full_address($address, $city)`, `problem_streets_contain($streets, $address, $city)` | Comparaison des rues : `vigilo_street_key()` (minuscules, sans accents, numéro, espaces ni ponctuation) ; adresse coupée en rue et ville (« Rue X, Ville ») ; l'API retire souvent la ville de l'adresse : la ville de l'observation vient de `obs_cities` (`obs_city`) ou d'`obs_cityname` (`PROBLEM_CITY_SQL`, `PROBLEM_CITY_JOIN`). |
| `problem_location_matches($problem, $lat, $lon, $address, $city)` | L'observation est-elle dans la zone (cercle, polygone, ou rues) ? Utilisé par toutes les correspondances. |
| `problem_candidates_where($problem, $include_archived)`, `problem_matching_observations($db, $problem, $filters)` | Condition SQL (publiées, non archivées sauf `$include_archived`, catégories s'il y en a, période, rectangle avec `CAST` des coordonnées varchar ; pas de rectangle pour les rues ; 0.0.37 : **non résolues** — `problem_unresolved_sql()`, dans aucune résolution au statut 1 — sauf `$filters['resolved']` ou déjà rattachées à ce problème ; 0.0.38 : de la ville `$filters['city']` seulement si elle est donnée), puis test exact en PHP ; `problem_attach_filters($input)` (0.0.38) : filtres `archived`, `resolved`, `city` d'un tableau ou des champs `include_archived`, `include_resolved`, `only_city` ; chaque observation avec le problème auquel elle est rattachée (0 : aucun). |
| `problem_observation_counts($db)` | Nombre d'observations rattachées par problème. |
| `problem_count($db, $problem, $filters)` | Rattachement rétroactif, 1re étape (ne modifie rien) : observations correspondantes réparties en `here` (déjà dans ce problème), `to_attach` (dans aucun problème), `conflicts` (dans un autre problème). |
| `problem_attach($db, $problem_id, $obs_id, $mode, $login)` | Rattache une observation **publiée** : `attached`, `already`, `conflict` (dans un autre problème, jamais déplacée), `unpublished`, `error`. `INSERT IGNORE` + clé unique sur `po_obsid` : sûr si deux requêtes rattachent la même observation. |
| `problem_attach_retro($db, $problem, $login, $filters)` | Rattachement rétroactif, 2e étape : recalcule les observations correspondantes et rattache celles qui ne sont dans aucun problème (mode `retro`), sous le verrou `GET_LOCK('vigilo_problem_attach')`. Idempotent. Renvoie les tokens rattachés, le nombre déjà rattaché, les conflits. |
| `problems_on_publication($db, $token, $login, $source)` | **Rattachement automatique**, appelé quand une observation devient publiée (`obsadmin_approve()` de l'admin, `approve.php`, `add_image.php`) : si elle n'est dans aucun problème, n'est pas résolue (0.0.37) et correspond (`problem_matches_observation()`) à **un seul** problème actif (`problems_active()`), elle y est rattachée (mode `auto`, journal `problem_attach_auto` avec `source` `admin` ou `api`) ; à plusieurs, rien n'est rattaché (journal `problem_attach_ambiguous`). Inclut `security.php` (`audit_log()`, login vide dans l'API). |
| `problem_suggest_settings($db)`, `problem_suggestions($db, $settings)`, `problem_suggestion_dismiss($db, $key, $login)` | **Suggestions** : groupes (`report_groups()` de `report.php`) d'observations publiées dans aucun problème, des `months` derniers mois (3 000 plus récentes au plus, `VIGILO_PROBLEM_SUGGEST_MAX`), d'au moins `min` observations, à moins de `radius` m ou dans la même rue ; réglages `vigilo_problem_suggest_min` (3), `vigilo_problem_suggest_radius` (50 m), `vigilo_problem_suggest_months` (12) de la page Configuration, bornés (2 à 1 000, 10 à 1 000 m, 1 à 120 mois). Clé d'une suggestion : `<catid>:<obs_id de la première observation>` ; ignorée : `obs_problem_suggestions_dismissed`. Rayon proposé : couvre le groupe. Adresse de la suggestion : la rue la plus fréquente suivie de la ville (ou des villes) de ses observations (`PROBLEM_CITY_SQL`), sauf si elle contient déjà une virgule. Rien n'est créé. |
| `problem_suggest_filters($input, $settings, $cities, $categories)` | Filtres d'une recherche de suggestions (0.0.36) : `suggest_city` (ville connue, 0 = toutes), `suggest_category` (0 = toutes), `suggest_archived` (1 : observations archivées comprises ; exclues par défaut), `suggest_resolved` (0.0.37, 1 : observations résolues comprises ; exclues par défaut), `suggest_grouping` (0.0.37 : `both` par défaut, `radius`, `street` ; passé à `report_groups()`, et `&zone=streets` ajouté au lien « Créer le problème » en mode rue, qui coche la zone de rues du formulaire), `suggest_months`, `suggest_min`, `suggest_radius` (valeurs par défaut : réglages d'`obs_config`, mêmes bornes) ; ajoutés à `$settings` pour `problem_suggestions()`. |
| `problem_analysis($problem, $observations, $now)` | Analyse du **rapport d'un problème** (fonction pure, `tests/problems/unit.php`) sur ses observations (`report_observations()`) : `time` (première / dernière, mois, mois avec observation, moyenne par mois, mois le plus chargé, intervalle médian, plus longue période sans observation, 3 derniers mois et 3 précédents, jours depuis la dernière, part des jours de semaine, jour le plus fréquent, moments de la journée, années), `geo` (centre moyen, distances au centre : médiane, 90 %, maximum ; étendue ; observations hors de la zone, `problem_location_matches()` ; rues, villes ; points chauds : `report_groups()` à 25 m, ≥ 2), `follow` (résolues, délai médian) et `findings` (phrases : `summary`, `time`, `geo`, `follow`). |
| `problem_period_text($problem)` | Période d'un problème en texte (« Depuis le … » ou « Du … au … »). |
| `problem_time_stats($observations)`, `problem_time_charts($stats, $color, $milestones)` | Répartition dans le temps (mois continus de la première à la dernière observation, jours de la semaine lundi d'abord, heures, matrice `grid` jours × heures) et graphiques SVG (`report_svg_columns()`) de la fiche ; avec `$milestones` (0.0.37), la frise couvre les jalons et les montre, `'milestones'` = leur légende sans les textes. |
| `problem_public_notes($db, $problem_id, $milestones_only = false)`, `problem_note_milestone_time($note)`, `problem_note_fields($input)` | Notes de suivi (0.0.37) : notes non privées d'un problème (lignes d'`obs_notes`, plus anciennes d'abord), ou seulement ses jalons (`array('time', 'label', 'text')` triés par date du jalon, sinon de la note) ; date d'un jalon ; champs d'un formulaire de note : privée sauf si `note_private` vaut `0` (case décochée : seul le champ caché `0` est envoyé ; absent = privée), jalon (`note_milestone`, 200 car., espaces réduits) et date (`note_milestone_date` `AAAA-MM-JJ` valide, à midi, sinon 0 = date de la note) seulement pour une note non privée. |
| `problem_report_statuses($get)` | Statuts des problèmes choisis pour le rapport (`problem_status[]`, valeurs de `problem_statuses()` seulement ; aucun = tous) : `report.php` filtre les lignes de `problem_report_rows()` (tableau et détail), la note du tableau les rappelle. |
| `problem_report_rows($db, $observations)` | Section **Problèmes suivis** du rapport : problèmes ayant des observations parmi celles du rapport, avec leur nombre dans le rapport et au total, première et dernière dates ; triés par priorité puis nombre. Jamais les notes. |
| `problems_ambiguous($db)` | Observations **à arbitrer** : publiées, dans aucun problème, correspondant à plusieurs problèmes actifs (calculées à l'affichage, pas de table). |
| `problem_detach($db, $problem_id, $obs_id)`, `problem_observations($db, $problem_id)`, `problem_titles($db)` | Détachement ; observations rattachées avec le mode, la date et le login du rattachement ; titres des problèmes (conflits). |
| `problem_move_observations($db, $from_id, $to_id, $obs_ids, $login)` | Déplace les observations d'un problème (toutes si `$obs_ids` vaut `null`) vers un autre, en mode `manual` ; renvoie leur nombre. |
| `problem_copy($db, $problem, $title, $login)` | Nouveau problème copié de `$problem` (description, catégories, zone, période, priorité, statut), sans observation ni note ; renvoie son id (0 en cas d'échec). Séparation. |
| `problem_merge($db, $source, $target, $login)` | Fusion : observations et notes (`note_problemid`) de `$source` vers `$target`, catégories réunies (toutes si l'un n'en a pas), début le plus ancien, fin la plus tardive (aucune si l'un n'en a pas), zone de `$target` gardée, `$source` supprimé ; renvoie le nombre d'observations déplacées, -1 en cas d'échec. |
| `problem_home_stats($db, $city_ids = null)` | Accueil : nombre de problèmes (`problems`), actifs (`active`), observations publiées (`published`), rattachées (`attached`), part en % (`rate`) ; limité aux villes `$city_ids` si non nul. |
| `problem_from_post($post, $known_categories)` | Validation du formulaire : titre obligatoire (200 car.), catégories connues (aucune = toutes), cercle (centre, rayon de 10 à 20 000 m), polygone (centre = milieu de son rectangle) ou rues (`problem_streets[]`, 1 à 50), dates `aaaa-mm-jj` (début à 0 h, fin à 23 h 59 min 59 s, fin après le début), priorité et statut connus. |

Tests sans base de données : `tests/problems/unit.php`.

---

## 3. Routes publiques

Paramètres et formats de réponse : [REST_API.md](REST_API.md). Ce qui suit décrit ce que fait le code.

### 3.1 Tableau récapitulatif

| Fichier | Méthode | Includes (en plus de `common` et `functions`) | CORS | `Content-Type` | Accès |
|---|---|---|---|---|---|
| `acl.php` | GET | — | oui | JSON | clé |
| `get_version.php` | GET | (`common` seul) | **non** | JSON | public |
| `get_scope.php` | GET | — | oui | JSON | public |
| `get_categories.php` | GET | — | oui | JSON, `Cache-Control: public, max-age=300` | public |
| `get_banner.php` | GET | — | oui | JSON (`{"banner": {...}|null}`) | public |
| `get_issues.php` | GET | `handle` | oui | JSON, CSV ou GeoJSON | public, clé pour les non approuvées |
| `create_issue.php` | POST | `security` | oui | JSON | public (anti-spam), clé pour modifier |
| `add_image.php` | POST, OPTIONS | `images`, `blur`, `handle` | oui + `Allow-Headers: *` | JSON | `secretid`, clé |
| `approve.php` | GET | `webhooks` | oui | JSON | clé admin/modérateur |
| `delete.php` | GET | — | oui | JSON | `secretid` ou clé |
| `create_resolution.php` | POST | `handle`, `security` | oui | JSON | public (anti-spam) |
| `get_photo.php` | GET | `handle` | oui | `image/png` (contenu JPEG) | public si approuvée, clé sinon |
| `generate_panel.php` | GET | `images`, `handle` | **non** | `image/jpeg` | public (pixelisée), `secretid` ou clé |
| `index.php` | GET | — | — | redirection vers https://vigilo.city | — |

Il n'y a plus de `install.php` (0.0.31) : la première installation passe par l'assistant `admin/setup.php` (§7.6).

### 3.2 `create_issue.php` — création et modification d'une observation

1. Si `$_POST['token']` est fourni **et** que la clé `key` (en GET) est celle d'un admin ou d'un modérateur, et que le
   token existe : c'est une **modification** (`$update = 1`), le cache de l'observation est vidé et son `secretid`
   relu en base. Sans clé valide, un `token` envoyé est ignoré et une nouvelle observation est créée.
2. **Anti-spam** (création seulement, hors admin/modérateur) : si `vigilo_ratelimit_create` > 0,
   `api_rate_limited($db, 'create_issue', N, 600)` ; au-delà, `Retry-After: 600` et erreur `RATELIMITED` (429).
3. Nouvelle observation : `secretid = str_replace('.', '', uniqid('', true))`, `token = tokenGenerator(4)`.
4. Champs obligatoires `coordinates_lat`, `coordinates_lon`, `categorie`, `address`, `time`, `scope` (absents :
   `PARAMNOTDEFINED` 400 ; vides : `PARAMEMPTY` 400). Une modification doit renvoyer tous les champs.
5. `time` de 13 chiffres (millisecondes) divisé par 1000.
6. `comment` : émojis retirés, **tronqué à 50 caractères**, puis échappé. `explanation` : échappé puis émojis retirés
   (pas de limite). `version` : version de l'application, stockée dans `obs_app_version`.
7. Le scope doit exister dans `obs_scopes` (`UNKNOWSCOPE` 400).
8. **Ville** : `cityid` numérique existant → `obs_city` ; inconnu → avertissement journalisé seulement (`CITYNOTFOUND`,
   sévérité `WARNING`). Sinon (0.0.36) `address_fix()` de `includes/addresses.php` : `cityname`, ou la dernière partie
   de l'adresse après une virgule ; adresse remise en forme, sans numéro de bâtiment, code postal ni pays
   (« 12, rue X, 34000 Ville, France » → « rue X ») ; ville cherchée dans `obs_cities` sans casse, accents ni
   ponctuation, « St » = « Saint », ou par son code postal (`city_postcode`, une seule ville) : trouvée → `obs_city`,
   `obs_cityname` vide ; sinon → `obs_cityname`.
9. Les coordonnées doivent être dans le rectangle du scope (`COORDINATESNOTALLOWED` 403). Comparaison de chaînes
   converties implicitement en nombres par PHP.
10. `INSERT` (statut 0, `obs_approved` 0 par défaut, `obs_complete` 0) ou `UPDATE … WHERE obs_token AND obs_secretid`
    (le scope n'est pas modifié par une mise à jour).
11. Réponse `{"token", "status": 0, "secretid", "group": 0}` (`group` est un champ historique toujours à 0).

Le commentaire et l'explication sont aussi écrits dans le journal PHP (`error_log("Comment: …")`).

### 3.3 `add_image.php` — photo d'une observation ou d'une résolution

1. Requête `OPTIONS` : réponse vide (pré-vol CORS).
2. `token` et `secretid` obligatoires en GET (`MISSINGARGUMENT` 400). `type` : `obs` (défaut) ou `resolution`.
   `method` : `base64`, sinon le corps brut.
3. `type=obs` : fichier `images/<token nettoyé [A-Za-z0-9]>.jpg` ; le couple token/secretid doit exister
   (`TOKENNOTEXIST` 400) ; si l'observation est déjà approuvée (`obs_approved = 1`) et que la clé n'est pas
   admin/modérateur, refus `ALREADYAPPROVED` 403 (une photo publiée ne peut plus être remplacée sans modération).
   `type=resolution` : fichier `images/resolutions/<token [A-Za-z0-9_]>.jpg`, répertoire créé si besoin,
   `RESOLTOKENNOTEXIST` 400.
4. Le téléversement est écrit dans un **fichier temporaire** `<fichier>.upload-<hex>` : la photo en place n'est
   remplacée que par une image valide.
5. Contrôles : JPEG (`FILETYPENOTSUPPORTED` 400), `isGoodImage` (`FILECORRUPTED` 500) ; le fichier temporaire est
   supprimé en cas de refus.
6. Réduction à 1024 × 1024 px au plus (`resizeImage`), réécriture JPEG (qualité par défaut de GD).
7. Floutage si `blur_server_url()` n'est pas vide : `blur_photo()` ; en-tête `X-Vigilo-Blur: done` ou `failed`
   (échec journalisé, photo gardée telle qu'envoyée).
8. `rename()` du fichier temporaire vers le fichier final.
9. `obs` : cache vidé, `obs_complete = 1` (l'observation apparaît alors dans les listes). `resolution` :
   `resolution_complete = 1, resolution_withphoto = 1`.
10. Réponse `{"status": 0}` (le champ `status` est historique, le code HTTP fait foi).

### 3.4 `get_issues.php` — liste des observations

Le fichier définit une **classe `GetIssues`** et, à la fin, ne l'exécute que s'il est appelé directement
(`if (!debug_backtrace())`) : on peut l'inclure pour réutiliser la classe sans relancer la sortie (c'était le cas de
`mosaic.php`, supprimée en 0.0.26). Les
`setX()` valident les paramètres et lèvent une `Exception` (non interceptée : erreur PHP, HTTP 500) pour une valeur
non numérique ou un format inconnu.

Construction de la requête (`getQuery()`) :

- toujours `WHERE obs_complete = 1` (observations avec photo) ;
- `c` : liste de catégories séparées par des virgules (`IN (…)`) ;
- `t` : `obs_time > t` ; `since` + `since_unit` (`day`, `week`, `month`, `year`) : `obs_time > strtotime('- N unit')` ;
- `token` seul : cette observation (même archivée, pour les liens) ; sinon, sans `archived=1`, `obs_archived = 0` :
  les observations archivées (0.0.29) ne sont pas listées ; avec `archived=1` (`setArchived()`), elles le sont et
  chaque observation reçoit le champ `archived` (statistiques de l'application web) ;
- `scope` : `obs_scope = …`, **sauf pour `34_montpellier`** (première instance, historiquement sans filtre) ;
- approbation : avec `approved` explicite, voir `setApproved()` ci-dessous ; sinon `obs_approved IN (0, 1)` si le
  réglage `vigilo_shownonapproved` est actif (toute valeur sauf `0`/vide, voir `showNonApproved()`) ou si la clé est
  admin/modérateur, `obs_approved = 1` sinon ;
- texte masqué : si `vigilo_shownonapproved_notext` est actif (`hideNonApprovedText()`) et que la clé n'est pas
  admin/modérateur, le commentaire (`obs_comment`) et l'explication (`obs_explanation`) des observations non
  approuvées (`obs_approved != 1`) sont renvoyés vides ;
- `cityid` : `obs_city = …` ;
- masquage des observations résolues depuis plus de `vigilo_resolved_hide_days` jours (sauf clé admin/modérateur, ou
  `resolvedhidden=1` → `setShowResolvedHidden()`, utilisé par les statistiques), d'après `resolution_status = 1` et
  `resolution_time` ;
- jointures `LEFT JOIN obs_cities` et sous-requête `obs_res` (une ligne par observation : `MAX` du rang du statut
  de ses résolutions, date de sa résolution résolue) ; le **statut renvoyé est le plus avancé de ses résolutions**
  (0 sans résolution), pas la colonne `obs_status` ;
- `ORDER BY obs_time DESC`, puis `LIMIT count` si `count` est fourni.

`setApproved($value)` : avec une clé admin/modérateur, toute valeur ; sinon `0` seulement si
`vigilo_shownonapproved` est actif, `1` toujours, et toute autre valeur rend la liste **vide**
(`returnempty`) plutôt qu'une erreur. `showNonApproved()` et `hideNonApprovedText()` lisent ces réglages par
comparaison de **chaîne** (jamais `== 0`, qui vaudrait `true` pour une valeur comme `pixelated` en PHP 7) :
actif = valeur non vide et différente de `0`.

Filtrage après la requête, en PHP (`getIssues()`) :

- `status` : comparé au statut de résolution de chaque ligne ;
- ville : si `obs_city` est renseigné, `cityname` = nom de la ville et l'adresse est réécrite « Rue, Ville » (ou « Rue »
  seule avec `cityfield=1`) ; sinon `obs_cityname`, sinon la partie après la virgule de l'adresse ;
- `lat` + `lon` + `radius` : ne garde que les observations à moins de `radius` mètres, et ajoute `distance` ;
- `token` + `tokenfilters` (`distance`, `categorie`, `address`) + `fdistance` : observations similaires à `token`
  (même catégorie si demandé, puis même adresse ou distance inférieure à `fdistance`).

Sortie (`outputToWebServer()`) : `json` (indenté), `csv` (en-têtes = clés du premier élément, virgules des valeurs
remplacées par `_`, sauts de ligne par des espaces) ou `geojson` (la description contient l'URL de
`generate_panel.php` entre `{{ }}`, format attendu par uMap).

### 3.5 `approve.php` — modération par l'API

Clé admin ou modérateur obligatoire (`ACCESSDENIED` 403). `approved` (défaut 1) : toute valeur numérique est acceptée
et écrite telle quelle (l'admin, lui, se limite à 0, 1, 2). L'état précédent est lu (`webhook_approval_state`), la
ligne mise à jour, le cache de l'observation vidé (pour que `generate_panel.php` ne serve plus la version pixelisée).
La réponse `{"status": "0"}` est envoyée **avant** les webhooks par `vigilo_send_response_and_continue()`, puis
`problems_on_publication()` (rattachement automatique à un problème, source `api`) puis `webhooks_on_approval()`
appellent les webhooks si l'observation vient de passer à 1. `add_image.php` appelle aussi `problems_on_publication()`
à la première photo (observation approuvée avant sa photo : publiée à ce moment).

### 3.6 `delete.php` — suppression

Avec une clé admin/modérateur, le token suffit ; sinon `secretid` doit correspondre (`TOKENNOTPROVIDED` 400 sinon,
nom d'erreur historique). Supprime la ligne, la photo et le cache. Contrairement à `deleteObs()` de l'admin, **ne
retire pas l'observation de ses résolutions** (voir section 10).

### 3.7 `create_resolution.php` — déclaration de résolution par un citoyen

Anti-spam identique à la création (type `create_resolution`, exemption pour la seule clé admin). Le `token` posté est
ignoré (« FIXME : consider updates ») : un token `R_` + 8 hexadécimaux et un `secretid` sont toujours générés.
`tokenlist` (tokens séparés par des virgules) et `time` obligatoires ; `comment` tronqué à 50 caractères. Les tokens
inconnus sont ignorés ; si aucun n'est valide, `FUNCTIONERROR` 500. La résolution est créée avec le **statut 4**
(« indiquée comme résolue », à valider dans l'admin). Réponse `{"token", "secretid"}`. La photo est envoyée ensuite par
`add_image.php?type=resolution`.

### 3.8 `get_photo.php` — photo originale

`type=obs` : 404 `TOKENNOTFOUND` si le token n'existe pas ; photo servie si l'observation est approuvée ou si la clé
est admin/modérateur, ou avec un lien signé valide (`exp`, `sig` : `photo_signed_valid()` de `functions.php`, depuis 0.0.26), sinon `NOTALLOWED` 403. `type=resolution` : toujours considérée comme approuvée. Photo absente :
`PHOTONOTFOUND` 404 en JSON. L'image est relue et réencodée par GD (`imagejpeg`) mais l'en-tête annonce `image/png`
(comportement figé par les tests de contrat).

### 3.9 `generate_panel.php` — image publique d'une observation

Ancienne route des « panneaux » (photo + carte + textes, retirés en 0.0.22) : elle sert désormais la photo seule, avec
les mêmes paramètres. Voir section 6.4 pour la logique de pixelisation et de cache.

### 3.10 `get_scope.php`, `get_categories.php`, `get_version.php`, `acl.php`

- `get_scope.php` : ligne de `obs_scopes` par `scope_name` (400 sans `scope`, 404 inconnu), ses villes
  (`city_scope = scope_id`, triées par nom) et `backend_version`. Les noms des champs JSON diffèrent des colonnes
  (`tweet_content` ← `scope_sharing_content_text`, `map_url` ← `scope_umap_url`, `twitter` ← `scope_twitter`).
- `get_categories.php` : `json_encode(getCategoriesList(), JSON_UNESCAPED_UNICODE)`.
- `get_banner.php` : `{"banners": vigilo_banners_active($db, $scope_id), "banner": <la première>|null}`
  (`functions.php`). `$scope_id` vient du paramètre `scope` (nom de scope résolu via `obs_scopes`, 0 si absent).
  `vigilo_banners_active()` renvoie **toutes** les bannières actives de `obs_banners` (activées, dans leur plage
  `start`/`end`, ciblant ce scope — `banner_scopes` vide = tous ; la plus récemment commencée d'abord), chacune mise
  en forme par `vigilo_banner_format()` (`id` = condensé du contenu, `type`, `message`, `display`, `start`, `end`,
  `persistent`). `banner` (= la première, ou `null`, via `vigilo_banner()`) reste pour les clients < 0.0.39.
  L'application affiche une bannière par type d'affichage, donc un bandeau et une fenêtre peuvent venir de deux
  bannières différentes. `persistent` (0/1, depuis 0.0.39) : à 1 l'application réaffiche la bannière à chaque ouverture
  même fermée (comportement historique à 0). Types dans `vigilo_banner_types()` (extensible), affichages dans
  `vigilo_banner_displays()`.
- `get_version.php` : `{"version": BACKEND_VERSION}` ; utilisée aussi par le contrôle de santé de la mise à jour.
- `acl.php` : `{"role": <rôle ou false>}` ; 400 `KEYNOTPROVIDED` sans `key`. Utilisée par l'application web pour
  activer le mode modérateur.
- `mosaic.php` : supprimée en 0.0.26 (listée dans `scripts/obsolete-paths.txt` avec `style/mosaic.css`) ; les
  observations similaires sont calculées par l'application web.

---

## 4. Administration

### 4.1 Pages

Toutes les actions sont protégées par le jeton CSRF (1.3) ; les liens d'action portent `csrf_query()`, les formulaires
`csrf_field()`. Colonne « Journal » : valeurs de `audit_action` écrites par `audit_log()`.

| Page (`inc/`) | Rôles | Contenu | Actions (paramètres) | Journal |
|---|---|---|---|---|
| `dashboard.php` | admin, citystaff | Compteurs (total, complètes, approuvées, refusées, en attente), 5 dernières observations en attente, résolutions à valider (statut 4), version, carte **Problèmes** (`problem_home_stats()` : nombre, part des observations publiées rattachées, actifs ; lien vers la page). Pour un citystaff, limité à ses villes. Un ancien `install.php` (versions antérieures à 0.0.31) est supprimé (`unlink`), alerte s'il ne peut pas l'être. Admins : cadre **Première configuration** tant qu'une étape requise reste à faire (`admin_setup_checklist($db, $config, true)` de `inc/setup_checklist.php` : nom et URL de l'instance, scope, villes, référencement d'un scope dans `citylist.json` de vigilo-conf ou de `VIGILO_CONF_URL` (liste en cache 24 h, 5 min pour `VIGILO_CONF_URL`, « non vérifié » sans réseau) ; facultatif : comptes moderator), avec barre d'avancement et lien vers chaque page. | — | — |
| `observations.php` | admin, citystaff | Recherche (token unique ou similaires, rue, ville, catégorie, archives : `searcharchived` = 0 non archivées par défaut, 1 archivées, `all`), badge « Archivée », lien vers le problème de l'observation (badge, admin et citystaff), badge de résolution lié à la page Résolutions (`q=<token>`), cadre « Archiver par période » sous la liste (admin). **Qualité des adresses** (admin, 0.0.36, remplace l'alerte « observations sans villes » et son import sans aperçu) : `address_cleanup_proposals()` (`includes/addresses.php`) sur toutes les observations complètes ; cadre avec le nombre de corrections par raison, les observations dans une ville non référencée (`filtercityunknown=1`, lien vers Villes) et sans ville (`filterpbaddress=1`) ; ces deux liens ajoutent `searcharchived=all` (les nombres comptent toutes les observations complètes) et, sans `approved`, la liste s'ouvre sur le premier onglet qui a des observations (0.0.37) ; vue `addresses=1` (filtre `reason=`) : tableau avant / après / raisons, cases cochées par défaut (`data-check-all`), 300 au plus, POST `address_fix=1` + `fix_ids[]` : corrections recalculées côté serveur (jamais lues dans la requête), `obs_address_string`, `obs_city`, `obs_cityname` mis à jour, cache des images vidé (`delete_token_cache()`), journal `observation_address_fix` (nombre et 100 premiers changements). **Liste compacte** (0.0.36, `table-compact`) : une ligne en lecture seule par observation (miniature 56 px, token et date, catégorie, commentaire, explication sur une ligne, adresse et ville, liens OpenStreetMap et **Panoramax** : `explore.panoramax.fr/#focus=map&map=19/<lat>/<lon>`) ; actions : Approuver / Désapprouver, **Modifier** (fenêtre `#obsEditModal` remplie par `data-fill`, ville ou ville non référencée selon `city_shown` / `cityname_shown` avec `data-fill-show`), **Résolution** (fenêtre `#obsResolutionModal`, onglet Approuvées, observation approuvée hors résolution : liste des résolutions groupées par statut `obsadmin_resolution_options()` avec token, commentaire et nombre d'observations, champ de filtre `data-filter-select`, boutons « Ajouter » = `bulk_action=resolution_add` et « Nouvelle résolution » = `bulk_action=resolution_new` sur la seule observation), Notes (ligne repliable `.obs-notes-row` sous l'observation), menu « … » (Archiver / Désarchiver, Effacer le cache, Remettre à qualifier, Supprimer) ; sous 1200 px, photo à gauche des textes ; boutons utiles seulement (« Approuver » absent pour une observation approuvée, « Désapprouver » pour une refusée, « Résolution » pour les seules observations approuvées hors résolution ; actions groupées `$bulk_actions_shown` filtrées de même selon l'onglet et `searcharchived`), onglets Approuvées / À qualifier / Refusées (`approved` = 1, 0, 2), 100 observations par page (`pagenb`), notes, alerte « observations sans villes ». Un citystaff ne voit que ses villes. | GET `action=approve&obsid=&approveto=0\|1\|2` (admin) ; `action=archive&archiveto=1\|0` (admin, `obsadmin_archive()`) ; POST `archive_step=preview\|confirm` + `archive_from` (facultatif) / `archive_to` (`aaaa-mm-jj`, `obsadmin_day()`) + `archive_category` (0 : toutes) : archivage des observations non archivées de la période (date de l'observation), aperçu du nombre puis confirmation (admin) ; `action=delete` (admin, `deleteObs`) ; `action=cleancache` (admin) ; `action=resolve` (admin, citystaff : crée une résolution au statut 2 « prise en compte » ; plus utilisé par la liste, remplacé par la fenêtre Résolution) ; POST `obs_id` + `obs_comment`, `obs_explanation`, `obs_categorie`, `obs_address_string`, `obs_cityname`, `obs_city`, `post_date`, `post_heure`, `resolution_add` (admin) ; POST `note_action=add\|delete`, `note_obsid`, `note_text`, `note_id` ; GET `addresses=1[&reason=]` puis POST `address_fix=1` + `fix_ids[]` (admin) ; actions groupées : POST `bulk_action` (`approve`, `disapprove`, `pending`, `category`, `city`, `resolution_new`, `resolution_add`, `archive`, `unarchive`, `cleancache`, `delete`, chacune avec le droit de l'action unitaire) + `bulk_ids[]` + `bulk_value_<action>` (catégorie active, ville, résolution) ; chaque observation est contrôlée comme une action unitaire (`obsadmin_can_act`), approbation commune dans `obsadmin_approve()` (webhooks). | `observation_approve`, `observation_delete`, `observation_archive`, `observation_unarchive`, `observation_archive_period`, `observation_cleancache`, `resolution_create`, `observation_edit`, `note_add`, `note_delete` (avec le texte de la note), `observation_address_fix` |
| `resolutions.php` | admin, citystaff | Onglets par statut de résolution (`resolved` = 2, 3, 4, 1 ; défaut 2), recherche `q` (token ou commentaire de la résolution, token d'une de ses observations ; nombres des onglets filtrés aussi), **liste compacte** (0.0.36) : 25 résolutions par page, une ligne chacune (token, date, miniature de la photo, commentaire, observations en pastilles `.obs-chip` avec leur nombre, retrait par « × », les 12 premières puis « + n » dépliable, ajout par token dans un petit champ, observations de la page lues en une requête), boutons d'état courts, **Modifier** dans la fenêtre `#resolutionModal` (commentaire ; date et heure seulement pour les statuts 4 et 1 : `with_date` + `data-fill-show`), alerte sur les observations présentes dans plusieurs résolutions. | POST `obsadd` + `obstoken` + `resolutionid` ; POST `resolution_id` + `post_date`/`post_heure`, `resolution_comment`, `resolution_status` (transition contrôlée par `$status_list`) ; POST `resolution_add` + `obs_id` ; GET `action=deleteobs&obsid=`, `action=delete` (admin), `action=resolve&new_status=` ; actions groupées : POST `bulk_action` (`status_<n>` selon le rôle, `delete` admin) + `bulk_ids[]`. Changement d'état unitaire ou groupé par `resolutionSetStatus()` (rôle et transition de `$status_list`). Un citystaff n'agit que sur des résolutions et observations de ses villes. | `resolution_add_observation`, `resolution_edit`, `resolution_remove_observation`, `resolution_delete`, `resolution_status` |
| `problems.php` | admin, citystaff (lecture seule) | **Problèmes** (`includes/problems.php`). Citystaff (`$readonly`) : `$_POST` vidé, `action` et `edit` ignorés ; liste et fiche limitées aux problèmes ayant des observations dans ses villes (`getRoleCityIds()`, `problem_city_condition()`), et à ces observations ; pas de formulaire, comptage, rattachement, détachement, notes, suggestions ni arbitrage. Admin : cadre **Suggestions** (calculées sur demande, « Créer le problème » ouvre le formulaire prérempli : `edit=new&suggestion=1&title=&catid=&lat=&lon=&radius=&start=&tokens=` ; 0.0.38 : `archived=1`, `resolved=1`, `city=<id>` selon les filtres de la recherche, gardés par le formulaire (champs cachés `include_archived`, `include_resolved`, `only_city`, note sous la carte) puis appliqués au comptage de la fenêtre `#attachModal` et à la carte de la fiche (`problem_admin_attach_filters()`, ville connue seulement ; case « Seulement à <ville> » du cadre de rattachement), « Ignorer » ; les observations `tokens` (200 au plus, publiées) sont affichées sur la carte du formulaire, gardées dans le champ caché `reference_tokens`, et leurs rues proposées), cadre **À arbitrer** (`problems_ambiguous()`, un bouton par problème candidat), liste triée par priorité puis date de modification, recherche `q` (titre, description, `LIKE` échappé), filtres `status` (`active` par défaut, `all` pour tous, ou un statut), `priority`, `category` (`FIND_IN_SET`), nombre d'observations rattachées, onglet **Carte** (`tab=map`, mêmes filtres : `problem_admin_map_data()`, carte `data-problems-map` de `js/problem-map.js`, une couleur par problème `problem_admin_map_color()` (teintes espacées de l'angle d'or, selon l'ordre de la liste) et légende des problèmes, contour en pointillés pour un problème résolu ou clos (`active`), problème de rues = ses rues surlignées, ses observations rattachées servant de zone de recherche et de repli si aucune rue n'est trouvée ; sans observation, rues cherchées dans leur ville ; la vue est fixée avant l'ajout des couches), bouton **Diffuser** par ligne (grisé si `webhooks_for_event()` ne trouve aucun webhook actif de `problem.broadcast`) ; **fiche** d'un problème (`view=<id>` : informations, carte en lecture seule `data-problem-view` avec la zone et les observations rattachées / à rattacher / dans un autre problème, rattachement rétroactif « Compter » puis « Rattacher » avec confirmation (`problem_admin_attach_panel()`, partagé avec la fenêtre `#attachModal` ouverte après la **création** d'un problème : comptage immédiat sans les archivées, formulaires postés vers la fiche ; conflits par `problem_admin_conflict_list()`), conflits avec un lien vers l'autre problème, observations rattachées avec photo, mode et auteur du rattachement, 20 par page (`opage`), rattachement manuel par token, détachement, cases à cocher et formulaire `#split-form` (`data-bulk` d'`admin.js`) pour **séparer**, cadre `#merge` pour **fusionner**, notes dans la fenêtre `#notesModal` (bouton « Notes » avec leur nombre dans l'encart principal, rouverte après un ajout, une modification ou une suppression par `data-reopen-modal` ; badges « privée » / « dans les rapports » et jalon ; champs communs `problem_note_fields_html()` : interrupteur « Note privée » coché par défaut, jalon (`datalist` d'exemples) et date masqués quand il est coché par `data-hide-when-checked` d'`admin.js` ; bouton crayon = formulaire repliable de la note), cadre `#timeline` : frise chronologique compacte avec les jalons des notes non privées et leur légende, matrice jours × heures, `problem_time_stats()` et `problem_time_charts()` avec les graphiques SVG de `report.php`) ; formulaire sur la page (titre, description, catégories, début, fin, priorité, statut, zone tracée sur une carte Leaflet par `js/problem-map.js` : clic = centre du cercle (marqueur déplaçable) et rayon, ou points du polygone dans le champ caché `problem_polygon` (points déplaçables, insertion par le milieu d'un côté, retrait par clic droit), ou rues (`problem_streets[]`, liste `datalist` des rues des observations publiées, clic sur une observation de la carte = sa rue), recherche Nominatim ; **autres problèmes** (0.0.37) : `data-others` = `problem_admin_map_data()` des problèmes autres que celui modifié (500 au plus), dessinés en gris par `problem-map.js` dans un volet `others` sous la zone tracée (`problemZoneLayer()` et `problemStreets()`, partagées avec l'onglet Carte), titre au survol, les clics passent à la carte, case « Autres problèmes » (`L.control.layers`) ; catégories (aucune cochée : toutes, « Toutes les catégories » dans la liste, la fiche, les rapports et `{{problem_categories}}`, et un problème sans catégorie apparaît avec le filtre de n'importe quelle catégorie) ; après l'enregistrement, la **fiche** du problème est affichée (formulaire posté vers `view=<id>`, « Annuler » y revient ; création : fenêtre `#attachModal` sur la fiche) ; actions de la fiche ramenées à leur cadre : comptage et rattachement rétroactif vers `#attach` (message dans le cadre, `$local_messages['attach']`), rattachement par token, détachement et séparation vers `#observations` (`$local_messages['observations']`, `problem_admin_alerts()`) ; repères `data-points` : observations de la suggestion ou rattachées au problème modifié, en vert celles des rues choisies, infobulle au survol avec la photo (`report_photo_src()`, chargée au premier survol), le token, la date et l'adresse ; ancre `#zone`, lien « Modifier la zone » de la fiche). **Rues surlignées** : `js/street-lines.js` (`window.vigiloStreetLines(map, streets, {bbox, style})`) demande à l'API Overpass, depuis le navigateur, les voies `highway` du même nom (expression insensible à la casse, aux accents et à la ponctuation, sans le numéro) dans la zone des observations (au moins ~1 km), sinon dans la commune de la rue (`area` administrative), et les trace en `L.polyline` (nom en texte) ; réponses gardées dans le `localStorage` (clé `vigilo-overpass:<empreinte de la requête>`, 7 jours, 1 jour si aucune voie, 60 au plus, les plus anciennes retirées ; jamais les échecs) en plus du cache de la page ; utilisé par la carte du formulaire (zone Rues, après la saisie), la fiche, l'onglet Carte (`streets` dans les données) et le détail du rapport (`report.js`) ; sans réponse, rien n'est tracé. | GET `edit=new\|<id>` ; POST `problem_save` + `problem_id` (0 = création), `problem_title`, `problem_description`, `problem_categories[]`, `problem_zone` (`circle`, `polygon`, `streets`), `problem_lat`, `problem_lon`, `problem_radius`, `problem_polygon`, `problem_streets[]`, `problem_start`, `problem_end`, `problem_priority`, `problem_status` ; GET `action=delete&problemid=` (les liens avec les observations sont supprimés, pas les observations) ; POST `problem_broadcast=<id>` (webhooks `problem.broadcast`, statut `waiting` ; refusé sans webhook actif, statut inchangé) ; POST `problem_arbitrate=<problem_id>` + `obs_id` (arbitrage) ; POST `problem_suggest` (calcul des suggestions), POST `problem_suggestion_dismiss=<clé>` ; fiche (`view=<id>`) : POST `problem_count` (aucune modification), POST `problem_attach_retro` (avec `include_archived=1` : observations archivées comprises, sinon exclues ; de même `include_resolved=1` pour les observations résolues, 0.0.37, et `only_city=<id>` pour une seule ville, 0.0.38 ; aussi pour `problem_count`), POST `problem_attach_token` + `obs_token` (observation publiée, même hors des critères ; refusée si elle est dans un autre problème), GET `action=detach&obsid=`, POST `problem_quick` + `problem_status`, `problem_priority`, POST `problem_resolve_observations` (problème résolu : `problem_admin_resolution_plan()` répartit ses observations publiées en sans résolution, résolutions non résolues avec le nombre d'observations hors du problème, déjà résolues ; `problem_admin_resolve_observations()` crée une résolution « Résolue » datée (`addResolution()`, commentaire « Problème résolu : <titre> ») pour les premières et passe les résolutions à 1 avec `updateResolution()` (date fixée si absente ; refus de `updateResolution()` signalés), cache des images vidé, webhooks `resolution.created` / `resolution.status_changed` ; cadre `#resolve` de la fiche, message avec lien après l'enregistrement du formulaire), POST `problem_note_add` + `note_text` (5 000 car.), `note_private`, `note_milestone`, `note_milestone_date` (`problem_note_fields()`), POST `problem_note_edit` + `note_id` + les mêmes champs (visibilité et jalon), POST `problem_note_delete` + `note_id` (note de ce problème), POST `bulk_action=split_new` + `bulk_ids[]` (obs_id) + `split_title` (nouveau problème `problem_copy()`, message avec le lien `edit=<id>#zone`) ou `bulk_action=split_move` + `bulk_ids[]` + `split_target` (autre problème), POST `problem_merge=<id cible>` (traité avant le chargement de la fiche, qui affiche ensuite le problème cible). Supprimer un problème supprime ses notes. | `problem_create`, `problem_edit` (valeurs modifiées : `[avant, après]`), `problem_delete`, `problem_attach_retro` (`archived`, `resolved`, `city`, nombre et tokens rattachés, déjà rattachées, conflits), `problem_attach` (`arbitration` pour un arbitrage), `problem_detach`, `note_add` / `note_edit` / `note_delete` (cible `problem:<id>` ; privée et jalon, texte de la note supprimée), `problem_suggestion_dismiss`, `problem_broadcast` (ancien et nouveau statut, nombre de webhooks, échecs), `problem_split` (cible, nouveau ou non, nombre), `problem_merge` (cible `problem:<id cible>`, problème fusionné, nombre), `problem_resolve_observations` (résolution créée, résolutions modifiées, refus) avec `resolution_create` et `resolution_status` ; à la publication (`observations.php`, API) : `problem_attach_auto`, `problem_attach_ambiguous` |
| `reports.php` | admin, citystaff | Formulaire du **rapport pour une collectivité** (GET vers `report.php`, nouvel onglet) : titre, période (raccourcis 3 / 12 mois, année en cours / précédente, `admin.js`), villes (un citystaff : les siennes), types d'observation, distance de regroupement, sections à inclure (`sections[]` + `s=1`, « Tout cocher » / « Tout décocher » dans `admin.js` ; celles des problèmes pour les administrateurs), format document ou diaporama. | — (lecture seule) | — |
| `cities.php` | admin | Liste des villes, fenêtre de création / modification, remplissage depuis Wikidata, import des communes d'un scope (geo.api.gouv.fr). | POST `cities_import` + `import_scope` + `cities_json` (2 000 villes max, valeurs revalidées) ; POST `city_id` (0 = création) + `city_name`, `city_scope`, `city_postcode`, `city_area`, `city_population`, `city_website`, `wikidata_import` ; GET `action=delete&cityid=`. | `city_import`, `city_create`, `city_edit`, `city_delete` |
| `accounts.php` | admin | Comptes, clé API masquée (`account_mask_key`), fenêtre de création / modification. | POST `role_id` (0 = création) + `role_name` (`guest`, `admin`, `citystaff`, `moderator`), `role_owner`, `role_login` (unique, 60 car.), `role_password` (10 car. min., vide = inchangé), `role_key` (clé fournie par la personne, générée par l'application web : 20 à 64 lettres ou chiffres, unique ; vide = générée à la création, inchangée à la modification), `role_city[]` + `role_city_present` (citystaff, JSON ≤ 255 car.) ; POST `role_id` + `key_regenerate` ; `password_link_new=1` (création ou modification : lien pour choisir le mot de passe, comptes admin / citystaff avec login ; le mot de passe peut rester vide) et POST `role_id` + `password_link` (bouton « Lien » : nouveau lien) : lien affiché une seule fois avec un bouton « Copier » (`data-copy`, `admin.js`) ; GET `action=delete&roleid=` (pas son propre compte) ; GET `ask_pwd_update` (message). On ne peut pas se retirer le rôle admin. | `account_create`, `account_edit`, `account_key_regenerate`, `account_delete`, `account_password_link` (sans le jeton) ; `account_password_set` (par `password.php`) |
| `scopes.php` | admin | Une carte par scope (Leaflet : rectangle, centre, zoom, recherche Nominatim), fenêtre de création. Création et suppression désactivées si `SAAS_MODE`. | POST `scope_create` + `scope_name`, `scope_display_name`, `scope_department`, `scope_contact_email` ; POST `scope_id` + colonnes de `$scope_fields` ; GET `action=delete&scopeid=`. Identifiant vérifié par `scope_name_valid()` (seulement s'il change). Site de l'association (`scope_association_url`, type `url`) : vide ou adresse `http(s)://` valide. | `scope_create`, `scope_edit`, `scope_delete` |
| `categories.php` | admin | Catégories nationales (désactivables) et catégories de l'instance (≥ 1000) ; la fenêtre de création rappelle qu'une catégorie utile à d'autres instances peut être proposée par pull request dans vigilo-conf. | GET `action=disable\|enable&catid=` (nationale) ; POST `category_delete` + `cat_id` (instance) et, si elle est utilisée, `obs_action=move` + `target_catid` (catégorie active, observations déplacées) ou `obs_action=delete` (observations supprimées par `deleteObs`, journalisées une à une) ; POST `category_save` + `cat_id` (0 = création), `cat_name`, `cat_name_en`, `cat_color` (`#rrggbb` ou nom CSS), `cat_resolvable`, `cat_active`. | `category_disable`, `category_enable`, `category_delete`, `category_create`, `category_edit` |
| `settings.php` | admin | Réglages de `obs_config` décrits dans `$settings_fields` (cartes Instance, Publication, Photos, Anti-spam, Suggestions de problèmes). Types de champ : `text`, `urlbase`, `proto`, `language`, `timezone`, `charset`, `bool`, `int`, `url`, `choice` (options). Indisponible si `SAAS_MODE`. | POST `settings_save` + `cfg[<param>]`. Seuls les paramètres de `$settings_fields` sont écrits (`INSERT … ON DUPLICATE KEY UPDATE`). | `settings_edit` |
| `banner.php` | admin | Bannières d'annonce (`obs_banners`) affichées dans l'application : liste, création/modification (fenêtre modale : message, type, affichage, scopes concernés, plage début/fin, activée, persistante), suppression. Plusieurs bannières programmables ; `get_banner.php` choisit l'active à afficher. | POST `banner_save` + `banner_id` (0 = création), `banner_message`, `banner_type`, `banner_display`, `banner_scopes[]`, `banner_start`, `banner_end`, `banner_enabled`, `banner_persistent` ; POST `banner_delete` + `banner_id`. | `banner_create`, `banner_edit`, `banner_delete` |
| `webhooks.php` | admin | Liste avec le dernier envoi et les filtres de chaque webhook (`webhook_admin_filter_summary()`), formulaire (modèles, filtres par catégorie et par ville, correspondance des catégories), 30 derniers envois (la table en conserve 500). | GET `edit=new\|<id>` (affiche le formulaire) ; POST `webhook_save` ou `webhook_test` + `webhook_id`, `webhook_name`, `webhook_enabled`, `webhook_event` (un seul ; « Plusieurs événements » signalé dans la liste et le formulaire pour les anciens webhooks), `webhook_categories[]` (catégories connues, désactivées comprises), `webhook_cities[]` (villes de `obs_cities`, `0` : sans ville), `webhook_method`, `webhook_url`, `webhook_format`, `webhook_headers`, `webhook_body`, `webhook_category_only`, `category_map[<catid>]` ; GET `action=delete&webhookid=`. | `webhook_create`, `webhook_edit`, `webhook_delete` |
| `audit.php` | admin | Journal paginé (50 par page, `p`), filtres `f_login`, `f_action` (pas `action`, réservé aux liens d'action). | — | — |
| `update.php` | admin | Versions (code, base, image Docker `VIGILO_IMAGE_VERSION`, dernière release), migrations en attente, contrôles de sécurité (`vigilo_security_checks()`), installation. Indisponible si `SAAS_MODE`. | POST `apply_migrations` ; POST `install_release` + `version` + `password` (mot de passe de l'admin redemandé) ; POST `watchtower_update` ; POST `refresh` (relit la dernière release). | `update_migrations`, `update_install`, `update_failed`, `update_docker_watchtower` |

`login.php` écrit aussi `login` dans le journal.

`admin/password.php?token=…` (hors `index.php`, sans session) : choix du mot de passe depuis un lien de la page Comptes
(`password_link_find()`), formulaire avec jeton CSRF de session, au moins 10 caractères, confirmation ; en-têtes
`Referrer-Policy: no-referrer` et `Cache-Control: no-store` (le jeton est dans l'adresse) ; lien invalide, expiré ou
déjà utilisé : message sans détail.

`admin/report.php` (hors `index.php`, même contrôle de session : admin ou citystaff) affiche le rapport avec
`includes/report.php` : en **document** A4 (`format=document`) ou en **diaporama** 16:9 (`format=slides`, une section
par diapositive, mode « Présenter » plein écran avec les flèches). Sections (`report_chosen_sections()`, cases du formulaire) : chiffres clés, évolution mensuelle, types,
état de traitement, villes (si plusieurs), rues, jours et heures, carte des lieux (Leaflet, un cercle par groupe),
**problèmes suivis** (administrateurs seulement ; `problem_report_rows()`, tableau empilé sous 700 px, 6 par diapositive ; jamais les notes), **détail de chaque problème** (administrateurs : `report_problem_detail_html()`, informations, carte `.report-problem-map` dessinée par `report.js`, 6 dernières photos, frise (avec les jalons des notes non privées et leur légende, textes compris en document) et matrice jours × heures de `problem_time_charts()` sur ses observations du rapport ; deux diapositives en diaporama), **lieux récurrents** après les problèmes (photo `photo.php`, états, tokens ; quand une section des problèmes est demandée, les lieux dont une observation appartient à un problème du rapport sont retirés et comptés dans la note : `$followed_groups`), méthode. Photos : `report_photo_src()` (dans `report.php`) donne `assets/photo-missing.svg` (« Photo non disponible », carré) quand le fichier n'existe pas, `report.js` fait de même pour une photo qui ne se charge pas. Points des cartes : `scaleMarkers()` de `report.js` réduit leur rayon (jusqu'à 30 %) quand la distance médiane au point voisin à l'écran est inférieure à leur taille, après le premier cadrage et à chaque `zoomend`. Les libellés des cartes sont insérés comme texte (`textNode()` de `report.js`). Le PDF s'obtient avec « Imprimer / PDF » (impression du
navigateur) : `assets/report.css` (mise en page d'impression, `@page` A4 ou 297 × 167 mm posé par `assets/report.js`)
et `report.js` (carte redimensionnée et recentrée à sa taille imprimée sur `beforeprint`, de même que les cartes des
problèmes : 182 mm, 88 mm dans deux colonnes). Aucune donnée n'est modifiée : pas de jeton CSRF.

`admin/problem_report.php?id=<problem_id>[&format=slides]` (bouton « Rapport » de la fiche d'un problème ; admin ou
citystaff, 404 pour un problème inconnu ou, pour un citystaff, sans observation dans ses villes) : rapport d'un problème
avec les mêmes styles et scripts (`report.css`, `report.js`, impression et présentation). Observations :
`problem_observations()` (limitées aux villes du citystaff) puis `report_observations()` avec `ids` ; analyse
`problem_analysis()`. Sections : synthèse (8 chiffres clés et `findings['summary']`), le problème,
analyse temporelle (constats, frise avec les jalons et leur légende, évolution par mois ou par année au-delà de 36 mois, matrice jours × heures,
moments de la journée), répartition géographique (constats, carte `.report-problem-map.problem-map-large` dessinée par
`report.js`, rues, villes, points chauds), types et suivi, dernières photos, commentaires des observations, **suivi du
problème** (0.0.37 : notes non privées `problem_public_notes()`, date, jalon et texte ; les 6 dernières en diaporama),
méthode. Jamais les notes privées.

### 4.2 Comportements JavaScript partagés (`app/admin/assets/admin.js`)

| Attribut | Effet |
|---|---|
| `data-confirm="message"` | Sur un lien ou un bouton : `confirm()` avant l'action (écouteur en phase de capture). |
| `<a href="photo.php…" data-photo data-photo-title="…">` | Ouvre la photo dans la fenêtre `#photoModal` de `index.php` (Ctrl/Cmd/Maj-clic : comportement normal). |
| image `photo.php` ou `generate_panel.php` en erreur | Remplacée par `../style/image_404.jpg` (sauf `data-no-fallback`). |
| bouton `data-bs-toggle="modal" data-bs-target="#id" data-fill='{"champ": valeur}' data-title="…"` | À l'ouverture de la fenêtre, remplit son formulaire : champs texte, cases à cocher (`true`, `1`, `"1"`), boutons radio (celui de même valeur), listes multiples (`name="x[]"`, tableau de valeurs) ; émet l'évènement `vigilo:filled` sur le formulaire. |
| élément `data-reopen-modal="#id" data-fill='…'` | Au chargement, rouvre la fenêtre remplie avec la saisie refusée (les pages le produisent à partir de `$reopen`). |
| `<input type="checkbox" data-hide-when-checked=".sélecteur">` | Les éléments du même formulaire qui correspondent au sélecteur sont cachés (`hidden`) tant que la case est cochée (champs du jalon d'une note de problème, 0.0.37). |
| bloc `data-fill-show="clé"` dans une fenêtre | Affiché, et ses champs envoyés, seulement si la valeur `clé` du `data-fill` est vraie (sinon caché et champs désactivés). |
| champ `data-filter-select="#id"` | Filtre une longue liste `<select>` : seules les options contenant le texte saisi restent (groupes vides cachés), la première est choisie ; Entrée ne soumet pas le formulaire ; vidé à l'ouverture d'une fenêtre. Jamais obligatoire dans une action groupée. |
| `admin_pagination($url, $page, $pages, $label)` (`index.php`) | Pagination condensée des listes : première et dernière pages, deux de part et d'autre de la page courante, « … » entre (observations, résolutions). |
| `<form data-bulk id="…">` avec `select[name=bulk_action]` | Actions groupées : cases `name="bulk_ids[]" form="<id>"` dans la liste, `[data-bulk-all]` (tout sélectionner), `[data-bulk-count]` (nombre coché) ; une option `data-field="x"` affiche les éléments `[data-bulk-field="x"]` (champ requis), `data-confirm` (avec `%n`) demande confirmation ; bouton désactivé tant que rien n'est coché. |
| `form[data-category-delete]` | Fenêtre de suppression d'une catégorie : choix déplacer / supprimer les observations affiché seulement si `obs_count` > 0, catégorie supprimée retirée des destinations. |
| `data-bs-toggle="tooltip"` | Infobulles Bootstrap. |
| `#theme-toggle` | Bascule clair/sombre, mémorisée dans `localStorage['vigilo-admin-theme']`. |

Scripts de pages (`app/admin/js/`) :

- `scope-map.js` (page Scopes) : pour chaque `[data-scope-map="<préfixe>"]`, carte Leaflet synchronisée avec les
  champs `<préfixe>lat_min`, `lat_max`, `lon_min`, `lon_max`, `center`, `zoom` ; boutons `data-map-action` (`draw`,
  `view-bounds`, `view-center`, `search`), recherche Nominatim depuis le navigateur ; `[data-scope-name]` remplit le
  département à partir de l'identifiant. Les tuiles OSM sont demandées avec
  `referrerPolicy: 'strict-origin-when-cross-origin'` car l'admin n'envoie pas de `Referer` (`same-origin`) et les
  serveurs de tuiles refusent les requêtes sans.
- `cities-import.js` (page Villes) : lit `#cities_import_data` (scopes et villes existantes, JSON émis par PHP),
  interroge `https://geo.api.gouv.fr` (départements couverts par le rectangle, puis communes), affiche carte et liste,
  et poste la sélection dans `cities_json`.
- `wikidata.js` (page Villes) : requête SPARQL sur `query.wikidata.org` (code postal P281, site P856, population
  P1082, surface P2046) pour remplir le formulaire d'une ville ; positionne `wikidata_import=1`.

Les bibliothèques sont copiées dans `app/admin/assets/vendor/` (Bootstrap, Bootstrap Icons, Leaflet) : aucune
ressource de CDN n'est chargée. Les appels aux API externes ci-dessus se font depuis le navigateur.

### 4.3 Conventions pour une page d'admin

- Une entrée dans `$menu` de `app/admin/index.php` (`icon` = nom d'icône Bootstrap Icons sans `bi-`, `name`,
  `access`) et un fichier `app/admin/inc/<clé>.php` commençant par le contrôle d'accès de 1.3.
- Droits fins dans la page : tableau `$actions_acl` (`action => array('access' => rôles)`) comme dans
  `observations.php` et `resolutions.php`.
- Actions de modification : formulaire POST avec `csrf_field()`, ou lien GET avec `action=…` et `csrf_query()`. Un
  paramètre GET qui modifie la base sans s'appeler `action` doit appeler `csrf_valid()` lui-même.
- Colonnes SQL issues d'une **liste blanche** (`$editable_fields`, `$scope_fields`, `$settings_fields`…), jamais des
  clés POST ; valeurs échappées ou converties.
- Toute sortie HTML passe par `h()` ; les messages construits par la page sont des tableaux `array(type, html)` dont le
  HTML ne contient que des valeurs déjà échappées.
- Création / modification dans une fenêtre Bootstrap (`data-fill`), réouverture après refus (`$reopen` →
  `data-reopen-modal`).
- `audit_log('objet_verbe', 'type:id', $details)` après chaque action privilégiée réussie.
- Fonctions définies dans une page : nom préfixé (`obsadmin_…`, `account_…`) et, si la page peut être incluse deux
  fois, entourées de `if (!function_exists(...))`.

---

## 5. Modèle de données

### 5.1 Tables

Le schéma est celui de `app/migrations/schema-0.0.17.sql` (base de départ) suivi des migrations `app/migrations/init-*.sql` (section 7.4). Les jeux de caractères varient selon
l'époque de création : `obs_list`, `obs_scopes`, `obs_config`, `obs_resolutions` en `utf8` (`utf8_bin`), certaines
colonnes de `obs_roles` et `obs_cities` en `latin1`, tables créées depuis 0.0.22 en `utf8mb4`.

| Table | Colonnes importantes | Remarques |
|---|---|---|
| `obs_list` | `obs_id`, `obs_token` (8 hex), `obs_secretid`, `obs_scope` (= `scope_name`), `obs_city` (→ `city_id`, 0 si aucune), `obs_cityname` (nom libre si la ville n'est pas référencée), `obs_coordinates_lat/lon` (**varchar**), `obs_address_string`, `obs_comment` (varchar 255, 50 car. par l'API), `obs_explanation` (text), `obs_categorie` (smallint), `obs_time` (timestamp Unix), `obs_status` (toujours 0, voir 5.2), `obs_app_version`, `obs_approved` (0 à modérer, 1 approuvée, 2 refusée), `obs_complete` (1 quand la photo est reçue), `obs_archived` (1 : archivée, plus listée par `get_issues.php` sauf `archived=1`, 0.0.29) | Index sur `obs_token`, `obs_city`. |
| `obs_resolutions` | `resolution_id`, `resolution_token` (`R_` + 8 hex), `resolution_secretid`, `resolution_app_version` (`admin` si créée dans l'admin), `resolution_comment`, `resolution_time`, `resolution_status`, `resolution_withphoto`, `resolution_complete` | |
| `obs_resolutions_tokens` | `restok_resolutionid`, `restok_observationid` (= `obs_id`) | Table de liaison, sans clé primaire. Une observation **devrait** être dans une seule résolution. |
| `obs_scopes` | `scope_id`, `scope_name` (`XX_nom`), `scope_display_name`, `scope_department`, `scope_coordinate_lat_min/max`, `scope_coordinate_lon_min/max` (varchar), `scope_map_center_string` (« lat, lon »), `scope_map_zoom`, `scope_contact_email`, `scope_association_url` (site de l'association, 0.0.28), `scope_sharing_content_text`, `scope_twitter`, `scope_umap_url`, `scope_nominatim_urlbase` | `scope_sharing_content_text` et `scope_twitter` ne sont plus modifiables mais restent renvoyés par `get_scope.php`. |
| `obs_cities` | `city_id`, `city_scope` (→ `scope_id`), `city_name`, `city_postcode`, `city_area`, `city_population`, `city_website` | |
| `obs_password_links` | `link_id`, `link_roleid`, `link_hash` (SHA-256 du jeton, unique), `link_created`, `link_created_login`, `link_expires`, `link_used` (0 : pas utilisé) | 0.0.34. Liens pour choisir son mot de passe (`password_links.php`). |
| `obs_banners` | `banner_id`, `banner_message`, `banner_type` (`info`/`success`/`warning`/`alert`), `banner_display` (`banner`/`modal`/`both`), `banner_scopes` (ids `obs_scopes` séparés par des virgules, `''` = tous), `banner_start`, `banner_end` (timestamps, 0 = sans borne), `banner_enabled`, `banner_persistent` (0.0.39 : 1 = réaffichée à chaque ouverture même fermée), `banner_created` | 0.0.38. Bannières d'annonce (page admin `banner.php`), servies par `get_banner.php`. |
| `obs_roles` | `role_id`, `role_key` (clé API, 40 hex), `role_name`, `role_owner` (nom de la personne), `role_login`, `role_password` (bcrypt ou ancien SHA-256), `role_city` (JSON des noms de villes d'un citystaff) | Un compte sans login (clé seule) est possible. |
| `obs_config` | `config_param` (unique), `config_value` (varchar 255) | Voir 5.3. |
| `obs_categories` | `cat_id`, `cat_custom` (0 nationale surchargée, 1 propre à l'instance), `cat_disabled`, `cat_name`, `cat_name_en`, `cat_color`, `cat_resolvable` | Une ligne n'existe pour une catégorie nationale que si l'admin l'a désactivée / réactivée. |
| `obs_notes` | `note_obsid`, `note_problemid` (0.0.32), `note_time`, `note_login`, `note_text`, `note_private` (0.0.37, 1 par défaut), `note_milestone`, `note_milestone_time` (0.0.37, 0 = date de la note) | Notes jamais exposées par l'API. Note d'une observation : `note_obsid` (toujours privée) ; note d'un problème : `note_obsid = 0` et `note_problemid` (gérée seulement dans la page Problèmes) : privée, ou dans les rapports (`note_private = 0`, section « Suivi du problème » du rapport du problème) avec un jalon éventuel sur les frises. |
| `obs_webhooks` | `webhook_name`, `webhook_enabled`, `webhook_event` (un événement depuis 0.0.32 ; plusieurs, séparés par des virgules, pour les webhooks créés de 0.0.24 à 0.0.31, toujours envoyés), `webhook_method`, `webhook_url`, `webhook_format` (`json`, `form`, `text`), `webhook_headers`, `webhook_body`, `webhook_category_map` (JSON `catid => code`), `webhook_category_only`, `webhook_categories` et `webhook_cities` (filtres : identifiants séparés par des virgules, vide = toutes, ville 0 = sans ville ; 0.0.30) | |
| `obs_webhook_deliveries` | `delivery_webhookid`, `delivery_time`, `delivery_event`, `delivery_token`, `delivery_http_code`, `delivery_error`, `delivery_duration_ms`, `delivery_response` (500 car.) | 500 lignes conservées. |
| `obs_problems` | `problem_id`, `problem_title`, `problem_description`, `problem_categories` (catids séparés par des virgules ; vide = toutes), `problem_zone` (`circle`, `polygon`, `streets`), `problem_lat` / `problem_lon` (centre ; milieu du rectangle d'un polygone ; 0 pour des rues), `problem_radius` (m), `problem_polygon` (JSON `[[lat, lon], …]`), `problem_streets` (0.0.33, JSON `[{"street", "city"}, …]`, NULL sauf zone de rues), `problem_start`, `problem_end` (NULL : sans fin), `problem_priority` (1 basse à 4 critique), `problem_status` (`open`, `in_progress`, `waiting`, `resolved`, `closed`), `problem_created_time/login`, `problem_updated_time/login` | 0.0.32. Admin seulement, jamais exposé par l'API. Historique dans `obs_audit_log`. |
| `obs_problem_suggestions_dismissed` | `sd_key` (`<catid>:<obs_id>`), `sd_time`, `sd_login` | 0.0.32. Suggestions de problèmes ignorées. |
| `obs_problem_observations` | `po_problemid`, `po_obsid`, `po_mode` (`retro`, `auto`, `manual`), `po_time`, `po_login` | 0.0.32. Clé unique sur `po_obsid` : une observation dans un seul problème (supprimer cette clé pour en permettre plusieurs). |
| `obs_audit_log` | `audit_time`, `audit_login`, `audit_role`, `audit_ip`, `audit_action`, `audit_target`, `audit_details` | |
| `obs_login_attempts`, `obs_rate_limit` | tentatives de connexion ; requêtes de l'API par type et IP | Purgées après 24 h. |

Tables disparues : `obs_status_update` (créée en 0.0.10, supprimée en 0.0.14), `obs_twitteraccounts` (supprimée en
0.0.22).

### 5.2 Statuts, approbation, résolutions

Trois notions indépendantes :

- **complétude** (`obs_complete`) : l'observation n'apparaît nulle part dans l'API publique tant que sa photo n'est pas
  reçue ;
- **approbation** (`obs_approved`) : décision du modérateur ; conditionne la publication (sauf
  `vigilo_shownonapproved`) et la pixelisation des photos ;
- **statut de suivi** : il est porté par la **résolution** liée (`obs_resolutions.resolution_status`), et non par
  `obs_list.obs_status`, qui est écrit à 0 à la création et n'est plus jamais modifié. `get_issues.php` renvoie
  le statut le plus avancé des résolutions de l'observation (0 sans résolution) : une observation liée à plusieurs
  résolutions (par exemple une résolution validée et une autre envoyée ensuite depuis l'application) n'apparaît
  qu'une fois. Ordre : 1 résolue > 4 indiquée résolue > 3 en cours > 2 prise en compte (`resolution_rank_sql()`,
  `resolution_status_from_rank()` de `functions.php`).

Statuts (`$status_list` de `common.php`) :

| Valeur | Nom | Rôles autorisés à le poser | Statuts suivants permis |
|---|---|---|---|
| 0 | Nouvelle observation | admin | 1, 2, 3, 4 |
| 1 | Observation résolue | admin | 0 |
| 2 | Observation prise en compte | admin, citystaff | 0, 3, 4 |
| 3 | Observation en cours de résolution | admin, citystaff | 0, 4 |
| 4 | Observation indiquée comme résolue | admin, citystaff | 0, 1 |

Les résolutions sont créées au statut 4 par un citoyen (`create_resolution.php`), au statut 2 depuis l'admin (action
« resolve » de la page Observations). Le passage à 1 exige une date et l'absence de doublon
(`updateResolution()`). La transition est vérifiée par `nextstatus` dans le formulaire d'une résolution, mais pas dans
le lien `action=resolve&new_status=` (voir section 10).

### 5.3 Clés de `obs_config` utilisées par le code

| Clé | Lue par | Modifiable dans l'admin | Défaut (migration) |
|---|---|---|---|
| `vigilo_db_version` | migrations, page Mises à jour | non | version de la base |
| `vigilo_urlbase` | `common.php` (`URLBASE`) : liens, contrôle de santé, `geojson`, webhooks | oui | `''` (étape 1 du menu) |
| `vigilo_http_proto` | `common.php` (`HTTP_PROTOCOL`) | oui | `''` |
| `vigilo_name` | `common.php` (`VIGILO_NAME`) : titre de l'admin, webhooks, mosaïque | oui | `Vigilo` |
| `vigilo_language` | `common.php` (`VIGILO_LANGUAGE`) : attribut `lang` de la mosaïque | oui | `fr-FR` |
| `vigilo_timezone` | `common.php` | oui | `Europe/Paris` |
| `mysql_charset` | `common.php` (`mysqli_set_charset`) | oui | `utf8` |
| `vigilo_shownonapproved` | `get_issues.php` (liste), `generate_panel.php` (photo) | oui | `1` (installation neuve : `schema-0.0.17.sql`) ; `0` masque, `pixelated` photo pixelisée, `substitute` image de substitution (ancienne `1` = `pixelated`) |
| `vigilo_shownonapproved_notext` | `get_issues.php` | oui | `0` (ligne absente : texte visible) ; actif = commentaire et explication vides au public pour les non modérées |
| `vigilo_resolved_hide_days` | `get_issues.php` | oui | `0` |
| `vigilo_blur_url` | `blur.php` | oui | `''` |
| `vigilo_ratelimit_create` | `create_issue.php`, `create_resolution.php` | oui | `60` (ligne absente : pas de limite) |

Clés supprimées par `init-0.0.22.sql` : `sgblur_url` (reprise dans `vigilo_blur_url`), `twitter_expiry_time`,
`vigilo_mapquest_api`, `vigilo_panel`, `vigilo_map_provider`, `vigilo_map_tiles_url`.

### 5.4 Fichiers sur disque

| Chemin (relatif à `app/`, préfixé par `DATA_PATH`) | Contenu | Écrit par |
|---|---|---|
| `images/<token>.jpg` | Photo d'une observation (≤ 1024 px, floutée si un serveur est configuré) | `add_image.php` |
| `images/resolutions/<R_token>.jpg` | Photo d'une résolution | `add_image.php?type=resolution` |
| `caches/<token>_p3_<mode>_w<largeur>.jpg` | Rendu public de `generate_panel.php` (`<mode>` = `pixelated` ou `substitute`, `vigilo_shownonapproved`) | `generate_panel.php` |
| `caches/remote_categories.json`, `caches/remote_citylist.json` | Copies des fichiers de vigilo-conf (ou de `VIGILO_CONF_URL`) | `getCachedRemoteJson()` |
| `caches/updates/` | `latest-release.json` (1 h), archives téléchargées, `stage-<version>/`, `backup-<version>-<date>/` (code + `database.sql`, 3 conservées) ; `.htaccess` `Require all denied` créé automatiquement | `updater.php` |
| `config/config.php` | Connexion à la base | installation |
| `caches/setup-code.php` (ou `config/setup-code.php`) | Code d'installation de l'assistant, supprimé à la création du premier admin | `vigilo_setup_code_ensure()` (assistant, `vigilo-migrate.php --setup-code`) |

`images/`, `caches/` et `migrations/` contiennent un `.htaccess` `Require all denied` ; l'image Docker les interdit
aussi dans `config/000-default.conf`. Sous nginx, il faut une règle équivalente (la page Mises à jour le vérifie,
`vigilo_security_checks()`). En Docker, `images/` et `caches/` sont des volumes.

---

## 6. Chaîne de traitement des images

### 6.1 Réception

`add_image.php` (section 3.3) : corps brut (`php://stdin` puis `php://input`) ou base64 (`method=base64`, champ
`imagebin64` en formulaire ou JSON). Le contenu est écrit dans un fichier temporaire à côté du fichier final.

### 6.2 Validation et redimensionnement

`hasAllowedType()` (JPEG uniquement, d'après les octets et non l'extension), `isGoodImage()` (taille minimale,
détection d'un JPEG tronqué), puis `imagecreatefromjpeg` → `resizeImage(…, 1024, 1024)` → `imagejpeg`. Le
réencodage par GD **supprime les métadonnées EXIF** (position GPS, appareil) ; l'orientation EXIF n'est pas appliquée.

### 6.3 Floutage

Si `blur_server_url()` renvoie une URL, `blur_photo()` envoie le JPEG réduit au serveur de floutage et le remplace par
la réponse. En cas d'échec (injoignable, code ≠ 200, réponse qui n'est pas une image, écriture impossible), la photo
réduite est conservée, l'incident est journalisé (`[WARNING] ADD_IMAGE: photo of … not blurred`) et l'en-tête
`X-Vigilo-Blur: failed` est envoyé : la modération reste le garde-fou. Internes du serveur : section 7.6.

### 6.4 Diffusion publique et cache (`generate_panel.php`)

```
token, s (largeur ≤ 1024, défaut 1024), secretid, key
        │
        ├─ mode = vigilo_nonapproved_photo_mode() : pixelated (défaut) ou substitute (vigilo_shownonapproved)
        │
        ├─ ni clé admin/modérateur ni secretid, et caches/<token>_p3_<mode>_w<s>.jpg existe → readfile (fin)
        │
        ├─ token inconnu → 404 TOKENNOTFOUND ; photo absente → 404 PHOTONOTFOUND (JSON)
        │
        ├─ non approuvée et ni admin/modérateur ni auteur (secretid égal) :
        │     mode substitute → style/image_404.jpg (repli pixalize() si absente), sinon pixalize()
        │  sinon → photo telle quelle
        │
        ├─ réduction à la largeur s (hauteur ≤ 1024)
        │
        ├─ rendu public uniquement → écrit dans caches/<token>_p3_<mode>_w<s>.jpg
        └─ envoi JPEG qualité 90
```

Le cache ne contient **que des rendus publics** : un rendu pour l'auteur ou un modérateur n'est jamais écrit. Le
préfixe `_p3` invalide les anciens panneaux mis en cache par les versions précédentes : changer ce préfixe est la
façon d'invalider tout le cache après un changement de rendu. Le **mode** (`pixelated`/`substitute`) fait partie du nom
du fichier : changer `vigilo_shownonapproved` ne sert donc jamais un rendu d'un autre mode resté en cache.

### 6.5 Autres accès aux photos

- `get_photo.php` : photo d'origine si approuvée (ou clé, ou lien signé `exp` + `sig`), sinon 403 ; jamais pixelisée, jamais mise en cache.
- `admin/photo.php` : session admin ou citystaff obligatoire (403 sinon) ; token nettoyé (`[A-Za-z0-9_]`) ; un
  citystaff ne voit que les observations de ses villes (404 sinon) ; jamais pixelisée ; largeur `s` entre 50 et 1024 ;
  `Cache-Control: private, max-age=300`.

### 6.6 Invalidation du cache

`delete_token_cache($token)` est appelée lors de l'approbation (API et admin), de l'ajout d'une photo, de la
modification par `create_issue.php`, de la suppression, de l'action « cleancache » et de toute modification d'une
résolution (`flushImagesCacheResolution`). Une nouvelle route qui change ce que montre l'image d'une observation doit
l'appeler aussi.

---

## 7. Sous-systèmes

### 7.1 Webhooks (`includes/webhooks.php`)

Événements (`webhook_events()`), un webhook stockant le sien dans `webhook_event` (cherché avec `FIND_IN_SET` :
les webhooks créés de 0.0.24 à 0.0.31 peuvent en avoir plusieurs, séparés par des virgules ; l'admin n'en enregistre
plus qu'un depuis 0.0.32, bouton radio `webhook_event`, et signale les anciens) :

| Événement | Déclenché par |
|---|---|
| `observation.created` | `add_image.php`, quand `obs_complete` passe de 0 à 1 (première photo) : `webhooks_for_observation()` après la réponse |
| `observation.approved`, `observation.disapproved` | `webhooks_on_approval($db, $token, $before, $after)` : seulement si l'état précédent existait et change, vers 1 ou vers 2. Appelée par `approve.php` (après la réponse) et par `obsadmin_approve()` de la page Observations (synchrone : les échecs sont affichés) |
| `resolution.created` | `create_resolution.php` (création, pas `update`, après la réponse) ; `obsadmin_resolution_created()` de la page Observations (action `resolve` et action groupée `resolution_new`) |
| `resolution.status_changed` | `resolutionSetStatus()` (lien et action groupée) et le formulaire d'une résolution de la page Résolutions, via `webhooks_on_resolution_status($db, $id, $before, $after)` si l'état change |
| `problem.broadcast` | Bouton « Diffuser » de la page Problèmes (POST `problem_broadcast`), via `webhooks_for_problem($db, $id)` : `webhook_problem_values()` prend les valeurs de la **dernière** observation du problème et ajoute les variables `problem_*` (titre, statut, priorité, catégories, zone, centre, nombre et tokens des observations, dates, lien vers la fiche) ; filtres : le problème correspond si l'une de ses observations correspond ; jeton journalisé `P<id>` |

Pour les événements de résolution, `webhook_resolution_values()` prend les valeurs de la **première** observation liée
(`webhook_observation_values()`) et ajoute les variables `resolution_*` ; la catégorie (correspondance, option
« catégories correspondantes seulement ») est donc celle de cette observation. Pour les filtres par catégorie et par
ville, en revanche, une résolution correspond si **l'une** de ses observations correspond (clé interne `_filter` des
valeurs : paires catégorie / ville de chaque observation ; une clé commençant par `_` n'est pas une variable). Le
journal des envois enregistre le token de la résolution pour ses événements.

Filtres d'un webhook (0.0.30) : `webhook_categories` et `webhook_cities` (identifiants séparés par des virgules, vide =
toutes ; ville `0` = observations sans ville référencée, `obs_city = 0`), lus par `webhook_filter_ids()`.
`webhook_matches($hook, $values)` applique ces filtres (une observation doit être à la fois dans une catégorie et une
ville choisies) et l'option « catégories correspondantes seulement ».

Déroulement :

1. `webhooks_for_event()` : webhooks actifs abonnés à l'évènement.
2. `webhook_observation_values()` : valeurs des variables (liste et description dans `webhook_variables()`, affichée
   dans l'admin). `cityname` suit la même règle que `get_issues.php`. `event_label` = libellé de `webhook_events()`
   (`webhook_event_label()`) ; `event_description` (phrase de `webhook_event_description()`, selon l'événement : jeton,
   catégorie, adresse, ou états de la résolution) est calculée à la première utilisation (nom de la catégorie). `photo_url` =
   `generate_panel.php?token=…&v=<obs_approved>-<date de la photo>` : `v`, ignoré par `generate_panel.php`, change le
   lien à l'approbation ou au remplacement de la photo (Slack garde les images en cache par adresse). `observation_url` et `categorie_name` valent
   `null` et ne sont calculées qu'à la première utilisation (`webhook_value()`), car elles demandent la liste distante
   des instances ou des catégories.
3. Pour chaque webhook, `webhook_build_request()` : `categorie_code` d'après `webhook_category_map` ; en-têtes ligne à
   ligne (nom vérifié par une expression régulière, valeur rendue en mode `header`) ; corps rendu selon le format
   (aucun corps en `GET`) ; `Content-Type` ajouté s'il n'est pas fourni ; `User-Agent: vigilo-backend/<version>` ; URL
   rendue en mode `url`.
4. `webhook_render()` remplace `{{ nom }}` (lettres minuscules et `_`) par `webhook_escape()` de la valeur :
   `json` → `json_encode` sans les guillemets extérieurs (d'où `"{{comment}}"` dans les modèles), `form`/`url` →
   `rawurlencode`, `header` → sauts de ligne remplacés par des espaces, `text` → tel quel. Variable inconnue → chaîne
   vide.
5. `webhooks_deliver()` : webhooks ignorés si `webhook_matches()` est faux (filtres de catégories et de villes,
   « catégories correspondantes seulement » sans code pour la catégorie) ;
   URL non HTTP(S) → erreur sans appel ; appels **en parallèle** (`curl_multi`), délai `VIGILO_WEBHOOK_TIMEOUT`
   (5 s par défaut), connexion ≤ 3 s, pas de redirection suivie ; un code hors 2xx est une erreur ; chaque envoi est
   enregistré dans `obs_webhook_deliveries` puis la table est réduite aux 500 derniers. Aucun nouvel essai.
6. `vigilo_send_response_and_continue($body)` : `fastcgi_finish_request()` sous PHP-FPM ; sous mod_php, vide les
   tampons, envoie `Connection: close` et `Content-Length`, puis `flush()` ; `ignore_user_abort(true)` dans les deux
   cas.

Le bouton « Enregistrer et tester » (page Webhooks) envoie le premier événement coché avec la dernière résolution (événements
de résolution), la dernière observation dans l'état de l'événement (nouvelle, refusée) ou la dernière observation publiée, ou des valeurs d'exemple (`webhook_admin_test_values()`), et enregistre aussi l'envoi dans le journal des envois ; si
cette observation est hors des filtres du webhook, rien n'est envoyé et le message l'indique. À l'enregistrement d'un webhook au format JSON, le
corps rendu avec les valeurs de chaque événement coché doit être un JSON valide ; au moins un événement est requis. Les modèles du menu « Modèle » sont dans
`$webhook_templates` de `inc/webhooks.php` (même contenu que [WEBHOOKS.md](WEBHOOKS.md)).

Liens signés vers la photo d'origine (`{{photo_full_url}}`, 0.0.26) : `photo_signed_query()` de `functions.php` produit
`token=…&exp=…&sig=…`, avec `exp` = maintenant + `VIGILO_PHOTO_LINK_DAYS` (7 jours) et `sig` = HMAC-SHA256 de
`token|exp` par le secret `vigilo_photo_link_secret` d'`obs_config` (créé au premier usage par `photo_link_secret()`,
jamais affiché dans l'admin). `get_photo.php` vérifie avec `photo_signed_valid()` (`hash_equals`, expiration).
Supprimer la ligne `vigilo_photo_link_secret` d'`obs_config` invalide tous les liens déjà envoyés.

### 7.2 Catégories

- **Nationales** : `categorielist.json` de vigilo-conf, récupéré par `getNationalCategoriesList()` et mis en cache une
  heure dans `caches/remote_categories.json` (copie périmée utilisée si GitHub est injoignable).
- **Surcharges locales** (`obs_categories`, depuis 0.0.23) : `cat_custom = 0` + `cat_disabled` désactive une catégorie
  nationale ; `cat_custom = 1` ajoute une catégorie de l'instance, numérotée à partir de 1000
  (`VIGILO_CUSTOM_CATEGORY_FIRST_ID`, `max(1000, MAX(cat_id) + 1)`).
- `getCategoriesList()` fusionne : catégories nationales dans l'ordre de vigilo-conf (avec `catdisable: true` si
  désactivée localement), puis catégories de l'instance (`catcustom: true`, `catname_en_US` si un nom anglais existe).
  Les catégories désactivées restent listées pour que les observations existantes gardent leur nom.
- Le backend **ne vérifie pas** la catégorie envoyée à `create_issue.php` : la liste ne sert qu'à l'affichage
  (applications, admin, `{{categorie_name}}`).

### 7.3 Mise à jour depuis l'admin (`includes/updater.php`)

Deux cas, déterminés par `vigilo_runtime()` (`docker` si `VIGILO_RUNTIME=docker` ou si `/.dockerenv` existe) :

**Installation classique** — `vigilo_install_release($db, $release, $log)` :

1. `vigilo_update_blockers()` : pas Docker, archive `vigilo-backend-<v>.zip` publiée, extensions `zip` et `curl`,
   droits d'écriture sur `app/`, `includes/`, `admin/`, `migrations/`. La version doit être plus récente que
   `BACKEND_VERSION`.
2. Release : `vigilo_latest_release()` lit l'API GitHub (`releases/latest`, ou `VIGILO_RELEASE_API` pour les tests),
   cache d'une heure ; sans release, le dernier tag `vX.Y.Z` (sans archive, donc non installable).
3. Téléchargement (`vigilo_download`, 300 s) de l'archive et de `SHA256SUMS` ; vérification SHA-256 ; si
   `VIGILO_RELEASE_PUBLIC_KEY` est défini, téléchargement de `.sig` et vérification ed25519
   (`sodium_crypto_sign_verify_detached`).
4. Extraction dans `caches/updates/stage-<v>` (chemins contenant `..` ou absolus refusés) ; `manifest.json` doit avoir
   la bonne version, `min_php` et `required_extensions` sont contrôlés.
5. Sauvegarde : copie du code (sans `vigilo_update_preserved()` : `config/config.php`, `images`, `caches`, `maps`,
   `install.php`) et dump SQL des tables `obs_*` écrit en PHP (`vigilo_dump_database`, sans `mysqldump`).
6. Installation : `vigilo_copy_tree()` (chaque fichier copié sous un nom temporaire puis renommé), `opcache_reset()`,
   `vigilo_migrate($db, $version)`, puis `vigilo_health_check()` (`get_version.php` de l'instance doit répondre la
   nouvelle version ; ignoré si `vigilo_urlbase` est vide).
7. En cas d'exception : recopie du code sauvegardé et, **si la version de la base a changé**, restauration du dump
   (`mysqli_multi_query`) ; l'exception est relancée avec « La version précédente a été restaurée ».
8. Succès : suppression des chemins `obsolete_paths` du manifeste (jamais un chemin préservé, jamais un chemin présent
   dans la nouvelle version), nettoyage, conservation des 3 dernières sauvegardes.

La page demande à nouveau le mot de passe de l'admin (`update_password_ok`) et vérifie que la version postée est
toujours la dernière.

**Docker** — le code fait partie de l'image et n'est jamais modifié dans le conteneur. Si `VIGILO_WATCHTOWER_URL` et
`VIGILO_WATCHTOWER_TOKEN` sont définis, `vigilo_watchtower_update()` envoie `POST <url>/v1/update` avec
`Authorization: Bearer <jeton>` (délai 8 s ; un dépassement de délai, errno 28, est normal car Watchtower redémarre le
conteneur ; 401 : jeton refusé ; 405 : ancienne image de Watchtower). Les migrations sont appliquées au redémarrage par
l'entrypoint.

L'archive est produite par `scripts/build-release.sh <version> <dossier>` : `app/` sans `config/config.php`,
`install.php` ni données, `manifest.json` (`version`, `obsolete_paths` tirés de `scripts/obsolete-paths.txt`,
`min_php` 7.3, `required_extensions` mysqli, gd, curl, json, `built_at`), dates de fichiers fixées (archive
reproductible), `SHA256SUMS`, et `.sig` si `VIGILO_RELEASE_SIGNING_KEY` est défini (clé générée par
`php scripts/release-keygen.php`).

### 7.4 Migrations (`includes/migrations.php`)

- Base de départ : `VIGILO_MIGRATIONS_BASE` (`0.0.17`), schéma `schema-0.0.17.sql` (tables, index et réglages d'une
  base en 0.0.17, `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`) qui remplace depuis 0.0.32 les migrations 0.0.2 à
  0.0.17 (issue #328 : aucune instance plus ancienne). Il n'est appliqué qu'à une base vide (`0.0.0`) ; une base entre
  les deux (`vigilo_db_too_old()`) est refusée par `vigilo_migrate()` sans être modifiée. La CI vérifie que le schéma
  est identique à celui produit par les anciennes migrations du tag v0.0.31.
- `vigilo_migrations_list()` : `init-X.Y.Z.sql` triés par `version_compare` ; `vigilo_migrations_pending()` commence
  par `schema-0.0.17.sql` pour une base vide.
- `vigilo_db_version()` : `0.0.0` si ni `obs_config` ni `obs_list` n'existent ; **exception** si `obs_list` existe
  sans `obs_config`, si la lecture échoue ou si la valeur est invalide (une erreur passagère ne doit jamais faire
  rejouer toutes les migrations).
- `vigilo_migration_statements()` : supprime les lignes commençant par `--` ou `#`, puis découpe sur un `;` **suivi
  d'une fin de ligne** (ou de la fin du fichier). Deux instructions sur une même ligne ne sont donc pas séparées.
- `vigilo_migrate($db, $target = BACKEND_VERSION, $log)` : verrou MySQL `GET_LOCK('vigilo_migrate', 0)` (une seule
  migration à la fois), refus si la base est plus récente que la cible ou plus ancienne que la base de départ, `innodb_strict_mode=OFF` et `sql_mode=''`,
  exécution de chaque instruction, `vigilo_set_db_version()` **après chaque fichier réussi** (une migration interrompue
  reprend au dernier fichier réussi), puis alignement de la version sur la cible si aucun fichier ne correspond à
  `BACKEND_VERSION` (version sans changement de schéma).

Appelants : `scripts/vigilo-migrate.php` (CLI : `--app=`, `--status` qui affiche « version_base version_code »,
`--to=X.Y.Z` ; codes de sortie 0, 1 erreur, 2 base plus récente que le code), la page Mises à jour (bouton
« Appliquer les migrations ») et `vigilo_install_release()`.

### 7.5 Docker et installation

`Dockerfile` : `php:8.5-apache-trixie` (0.0.38 ; `php:8.3-apache-bookworm` avant), extensions `gd` (freetype, jpeg), `mysqli`, `exif`, `zip`, modules Apache
`remoteip`, `rewrite`, `headers`, `php.ini-production` + `upload_max_filesize = 20M`, `post_max_size = 25M`. Le code
`app/` est copié dans `/var/www/html`, `config/config.php.docker` devient
`config/config.php`. Variables : `VIGILO_RUNTIME=docker`, `VIGILO_IMAGE_VERSION` (argument de build, le tag de la
release), `AUTOUPDATE=true`.

`vigilo-entrypoint` :

1. attend la base (30 essais, une seconde d'intervalle) ;
2. `vigilo-migrate.php --status` : arrêt (code 1) si la version de la base est illisible ; arrêt (code 2) si la base
   est plus récente que le code ;
3. base vide (`0.0.0`) : migrations complètes ;
4. base plus ancienne : migrations si `AUTOUPDATE` ≠ `false`, sinon message (à appliquer depuis l'admin) ;
5. `VIGILO_TRUSTED_PROXIES` : écrit `RemoteIPInternalProxy <réseaux>` dans
   `/etc/apache2/conf-enabled/remoteip-proxies.conf` (supprimé si la variable est vide) ;
   supprime l'ancien `install.php` (copié dans le conteneur par les versions antérieures à 0.0.31), crée `images/`,
   `images/resolutions/`, `caches/` et en donne la propriété à l'UID 33 (le contenu n'est pas modifié) ;
6. sans compte admin : `vigilo-migrate.php --setup-code` crée le code d'installation (`caches/setup-code.php`,
   propriétaire `www-data`) et l'entrypoint l'affiche dans les journaux (« code d'installation ») ; rien avec
   `VIGILO_SETUP_CODE` (code fixé par l'hébergeur) ;
7. `exec apache2-foreground`.

`docker-compose.yml` : services `db` (MariaDB, `MARIADB_AUTO_UPGRADE`), `web` (image
`${VIGILO_IMAGE:-vigilobs/vigilo-backend:0.0}`, volumes `images`, `caches`, journaux Apache ;
`VIGILO_WATCHTOWER_URL` par défaut `http://watchtower:8080`), `watchtower` (actif par défaut depuis 0.0.32,
`nickfedor/watchtower:1`, `--http-api-update --label-enable --cleanup`, ne met à jour que le conteneur portant le label
`com.centurylinklabs.watchtower.enable=true` ; `WATCHTOWER_TOKEN` obligatoire) et le profil `blur` (image publiée).
Le fichier fonctionne seul, sans le dépôt. `docker-compose.dev.yml` monte `./app` et construit les images locales
(`web`, et `blur` depuis `blur-server/`).

### 7.6 Serveur de floutage (`blur-server/blur_server.py`)

Serveur HTTP Python (bibliothèque standard + OpenCV + NumPy + ONNX Runtime), image `vigilo-blur` séparée, CPU
seulement. Méthode reprise du service de floutage de Panoramax ([SGBlur](https://github.com/cquest/sgblur)).

- `POST /blur` : photo en multipart (champ `picture`, ou premier fichier) ou corps brut. 400 sans photo ou si ce n'est
  pas une image, 413 au-delà de `BLUR_MAX_BYTES` (20 Mo). Réponse 200 `image/jpeg` (qualité `BLUR_JPEG_QUALITY`, 90)
  avec `X-Blur-Faces` et `X-Blur-Plates` (nombre de zones masquées). `GET /health` (ou `/`) :
  `{"status": "ok", "model": …, "sizes": […]}`, utilisé par le `HEALTHCHECK` de l'image.
- Concurrence : `ThreadingHTTPServer`, un sémaphore limite à `BLUR_WORKERS` (2) les photos traitées en même temps.
  Une seule session ONNX Runtime (`_session`, thread-safe, `BLUR_THREADS` threads par photo) ; détecteur YuNet créé une
  fois par thread (`threading.local`, il n'est pas thread-safe).
- `detect()` renvoie `(visages, plaques)` en `(x, y, w, h)` dans l'image d'origine :
  - `_yolo(img, size)` pour chaque taille de `BLUR_SIZES` (1024 par défaut) : modèle YOLO11s de Panoramax
    (`models/yolo11s_panoramax.onnx`, classes `sign`, `plate`, `face`). Image réduite à `size` sur son grand côté,
    complétée à un multiple de 32 (gris 114), RGB / 255 ; sortie `(1, 7, N)` (centre, taille, 3 scores) ; par classe,
    seuil (`BLUR_FACE_CONFIDENCE` 0.2, `BLUR_PLATE_CONFIDENCE` 0.3) puis `cv2.dnn.NMSBoxes` (IoU 0.45). Les panneaux
    (`sign`) sont ignorés : ils ne sont jamais masqués ;
  - `_yunet_faces()` : YuNet (`models/face_detection_yunet_2023mar.onnx`, seuil `BLUR_FACE_THRESHOLD`, 0.6) sur une
    copie réduite à 1 600 px au plus, en complément (meilleur sur les visages proches) ;
  - `_dedupe()` retire une zone contenue à plus de 60 % dans une zone plus grande déjà retenue.
- Masquage (`_mask`) : pixelisation (environ 6 blocs sur le petit côté) puis flou gaussien, en ellipse avec une marge de
  20 % pour les visages, en rectangle avec 10 % pour les plaques. Non réversible.
- Le backend réduit la photo à 1 024 px avant de l'envoyer : une passe à 1024 voit l'image à pleine résolution
  (environ 0,4 s sur 4 cœurs, 500 Mo de mémoire).
- Changer de modèle : exporter un autre modèle YOLO de SGBlur en ONNX (voir `blur-server/models/README.md`) et le
  désigner par `BLUR_MODEL` ; les classes doivent rester dans le même ordre.
- Tests : `blur-server/tests/test_blur.py` (`tests/fixtures/street.jpg` : un visage masqué et un panneau intact ;
  `car.jpg` : une plaque masquée).

---


### 7.6 Première installation (`admin/setup.php`, `includes/setup.php`)

`admin/login.php` appelle `vigilo_setup_needed()` **avant** `common.php` (qui s'arrête sans configuration ou sans
base) et redirige vers `setup.php` tant que l'instance n'est pas installée. `vigilo_setup_state()` (sans
`common.php`) donne l'étape :

| Étape | Condition | Action de l'assistant |
|---|---|---|
| `config` | pas de `config/config.php`, ou connexion impossible | installation classique : formulaire (serveur, `serveur:port`, base, utilisateur, mot de passe), **Tester la connexion** (`vigilo_setup_connect()`, délai 5 s, puis `vigilo_setup_check_privileges()` : création, modification et suppression d'une table `vigilo_setup_check`), **Tester et enregistrer** (`vigilo_setup_write_config()`, refus si le fichier existe ; si `config/` n'est pas accessible en écriture, le contenu à déposer par FTP est affiché). Docker (`VIGILO_RUNTIME=docker`) : erreur de connexion et rappel des variables `MYSQL_*` |
| `database` | base vide (`0.0.0`), ou plus ancienne que le code **sans** compte admin | `vigilo_migrate()` (scripts de `migrations/`) |
| `newer` | base plus récente que le code | refus, rien n'est modifié |
| `admin` | aucun compte `admin` avec login et mot de passe | formulaire : nom, login (`[A-Za-z0-9._@-]{3,60}`), mot de passe (10 caractères au moins, confirmé), nom de l'instance, **URL de base** et protocole détectés par `vigilo_setup_detect_url()` (hôte + chemin au-dessus de `admin/`, `vigilo_is_https()`) ; crée le compte (clé de 40 caractères), écrit `vigilo_name`, `vigilo_urlbase`, `vigilo_http_proto`, journalise `setup_admin_create`, ouvre la session et redirige vers l'admin |
| `done` | compte admin présent | redirection vers `login.php` (les mises à jour passent ensuite par la page Mises à jour ou Docker) |

**Code d'installation** : chaque étape exige le code de `vigilo_setup_code_ensure()` (24 caractères sans ambiguïté,
`random_int`), écrit dans `caches/setup-code.php` (sinon `config/setup-code.php`) sous la forme d'un fichier PHP qui
répond 404 sans rien afficher. Saisi une fois (`vigilo_setup_code_valid()`, `hash_equals`, majuscules et espaces
ignorés, une seconde d'attente si faux), il est gardé en session (empreinte SHA-256) ; la session est régénérée. Il est
supprimé à la création du compte admin, et ne peut plus être recréé ensuite (`setup.php` redirige, `--setup-code`
n'affiche rien). Docker : l'entrypoint le crée avec `vigilo-migrate.php --setup-code` et l'affiche dans les journaux.
Un hébergeur qui crée beaucoup d'instances (démos, [vigilo-demo](https://github.com/jesuisundesdeux/vigilo-demo)) peut
fixer le code avec la variable `VIGILO_SETUP_CODE` (12 à 64 lettres ou chiffres, `vigilo_setup_code_env()`) : aucun
fichier n'est écrit, la page renvoie vers l'hébergeur et `--setup-code` n'affiche rien (le code n'est jamais écrit
dans les journaux).

La page liste aussi les vérifications (`vigilo_setup_requirements()` : PHP ≥ 7.3, extensions `mysqli`, `gd`, `exif`,
`curl` et `zip` (ces deux dernières facultatives), dossiers `images/`, `images/resolutions/`, `caches/` accessibles en
écriture, créés si besoin ; connexion et versions de la base et du code).

`common.php` lit `config/config.php` avec `require` (et non `require_once`) : `login.php` l'a déjà lu par
`vigilo_setup_read_config()`.

Test : `tests/setup/run.sh` (installation classique de bout en bout, base vide, PHP local).

## 8. Tests et intégration continue

Commandes : [tests/README.md](../tests/README.md). Fonctionnement interne :

### 8.1 Lanceur `tests/api/run.sh <app> <image> [--record | --functional | --only …]`

1. Recrée la base (`DROP DATABASE` / `CREATE DATABASE` en root) et applique les migrations **du dépôt**
   (`vigilo-migrate.php` sur une copie de `app/`).
2. Charge `tests/api/seed.sql` : `vigilo_http_proto=http`, `vigilo_shownonapproved=1`, deux scopes (`99_testville`,
   `98_autre`), deux villes, trois comptes (`admin` clé `ADMINKEY0123456789`, `modo` clé `MODKEY0123456789`, `staff`
   citystaff de Testville ; mot de passe `vigilo-test`), huit observations `TOKA0001` à `TOKA0008` couvrant les cas
   (approuvée, en attente, refusée, sans photo, autre scope, résolue, à supprimer), deux résolutions.
3. Copie le **code à tester** (`<app>`, qui peut être une ancienne version) avec `config.php.docker`, les photos de test
   (`fixtures/photo.jpg`) et `fixtures/categorielist.json` comme cache `remote_categories.json` (pas d'accès réseau
   nécessaire).
4. Lance `<image>` en réseau hôte sur `CONTRACT_PORT` (8089), code monté dans `/var/www/html` ; `host` utilise le
   serveur intégré de PHP.
5. Lance `contract.py` (défaut) ou `functional.py` (`--functional`), puis `tests/admin/smoke.py` si `ADMIN_SMOKE=1`.
   En cas d'échec, affiche la fin du journal Apache/PHP.

### 8.2 Contrat de l'API (`tests/api/contract.py`)

- `CASES` : 72 requêtes (nom, méthode, chemin, paramètres, corps de formulaire ou brut). `save` mémorise une valeur
  de réponse (`{new_token}`…) pour les requêtes suivantes : l'ordre compte.
- Pour chaque réponse : code HTTP, `Content-Type` (sans espaces, minuscules), `Access-Control-Allow-Origin`, présence
  de l'en-tête `BACKEND_VERSION`, et corps : dimensions pour une image (`image_info`), JSON avec les valeurs générées
  masquées (`masks`, `masks_items`, et toujours `version`, `backend_version`), ou texte brut.
- Comparaison avec `tests/api/snapshots.json` (enregistré sur la dernière version publiée). Un changement **voulu** se
  déclare dans `tests/api/expected_changes.json` : `"<cas>": {"why": "raison", "now": {clés remplacées}}`, la valeur
  `"__removed__"` supprimant une clé attendue.
- `--record` réécrit les instantanés (à faire sur le code de la dernière release, pas sur le code modifié) ; `--only`
  filtre les cas par nom.

### 8.3 Tests fonctionnels (`tests/api/functional.py`)

`unittest`, bibliothèque standard seulement. Classes numérotées `T01Configuration` … `T12WebhookCategories`, exécutées
dans l'ordre alphabétique. Aides : `call()` (requête HTTP), `sql()` et `set_config()` (client `mysql` en root, pour
modifier un réglage pendant un test), `create()` (observation par défaut dans Testville), `issue_tokens()`,
`jpeg_size()`. `main()` désactive l'anti-spam (`vigilo_ratelimit_create=0`) avant de lancer les tests.
Des serveurs locaux simulent les services externes : `StubBlurServer` (floutage), `WebhookReceiver` (réception des
webhooks). `T09RealBlurServer` ne s'exécute que si `BLUR_SERVER_URL` est défini ou `BLUR_FROM_ENVIRONMENT=1`.

Ajouter un test : une méthode `test_…` dans la classe du domaine (ou une nouvelle classe `TNN…`), en partant des
données de `seed.sql` ou en créant ses propres observations ; restaurer les réglages modifiés.

### 8.4 Admin (`tests/admin/smoke.py`)

Script séquentiel (`main()`) avec un client à cookies : connexion (échec puis succès), chaque page du menu, actions
trouvées dans les pages (liens portant le jeton, formulaires), refus d'une action sans jeton CSRF, photos de l'admin,
notes, réglages, webhooks et leurs modèles, problèmes (création, refus, filtres, modification, comptage, rattachement rétroactif idempotent, conflit, rattachement manuel et ses refus, détachement, rattachement automatique à la publication, arbitrage, statut et priorité depuis la fiche, séparation vers un nouveau problème et refus vers lui-même, fusion (observations et notes déplacées, refus avec lui-même), carte Problèmes de l'accueil (admin et citystaff), problème résolu dont les observations le sont déjà, notes de suivi (privée par défaut, non privée avec un jalon sur les frises de la fiche et des rapports, modification, note privée sans jalon, date invalide), suggestions et formulaire prérempli (observations de la suggestion sur la carte, leurs rues proposées), suggestion ignorée, zone de rues (rues proposées, refus sans rue, comptage), section du rapport sans les notes privées et absente pour un citystaff, consultation en lecture seule par un citystaff, suppression), scopes, villes (import, fenêtres), comptes, catégories, journal, déconnexion,
restrictions du citystaff, accès direct à `inc/*.php`. Chaque page est contrôlée contre les motifs d'erreur PHP
(`PHP_ERRORS`). Ajouter une page ou une action : ajouter ses vérifications dans `main()`.

### 8.5 Autres suites

- `tests/update/run.sh` : installation classique avec le serveur intégré de PHP, clé de signature générée pour le
  test, fausses releases construites avec `build-release.sh` et servies localement (`VIGILO_RELEASE_API`) : mise à jour
  signée installée (configuration, photos préservées, code obsolète `panels/` supprimé, journal), mot de passe
  erroné refusé, archive altérée refusée, migration invalide annulée.
- `tests/blur/compose.sh` : `docker-compose.yml` avec le profil `blur`, de bout en bout.
- `php tests/problems/unit.php` : règles des problèmes sans base de données (cercle, polygone concave, rues, rectangle
  englobant, validation du formulaire).
- `blur-server/tests/` : tests unitaires du serveur de floutage.

### 8.6 CI (`.github/workflows/ci.yml`, à chaque push sur `master` et pull request)

| Job | Contenu |
|---|---|
| `lint` | `php -l` de `app/` et `scripts/` sous PHP 7.3, 8.3 et 8.5 (tout message autre que « No syntax errors », dont une dépréciation, fait échouer), puis `tests/problems/unit.php` (zones et formulaire des problèmes). |
| `setup` | `tests/setup/run.sh` : assistant d'installation de bout en bout (base vide, PHP 8.5 local). |
| `version` | La dernière migration n'est pas plus récente que `BACKEND_VERSION` et met bien à jour `vigilo_db_version` à sa version. |
| `migrations` | Installation depuis une base vide, rejeu, montée depuis 0.0.17 à 0.0.22 (schéma reconstruit avec `mysql`), reprise du réglage SGBlur, montée pas à pas, refus d'une base plus récente (code 2). |
| `contract` | `ADMIN_SMOKE=1 tests/api/run.sh` avec l'image construite et avec `vigilobs/vigilo-backend:0.0.20` (PHP 7.3). |
| `functional` | `tests/api/run.sh --functional`, mêmes deux environnements. |
| `blur` | Tests unitaires du serveur, images construites, `tests/blur/compose.sh`. |
| `updater` | `tests/update/run.sh` (PHP 8.5). |

`.github/workflows/release.yml` (tag `v*.*.*`) : vérifie que le tag est `v` + `BACKEND_VERSION`, construit l'archive,
publie la release GitHub, construit et signe (cosign) les images multi-architectures `vigilo-backend` et `vigilo-blur`
(ghcr.io, et Docker Hub si les secrets existent).

---

## 9. Recettes

### 9.1 Ajouter une route d'API

1. Créer `app/<nom>.php` sur le modèle de 1.1 : includes nécessaires, en-têtes `BACKEND_VERSION`, `Content-Type`,
   `Access-Control-Allow-Origin: *`, `$error_prefix`.
2. Échapper chaque paramètre (`mysqli_real_escape_string`, `intval`) ; contrôler la clé avec `getrole()` si besoin ;
   erreurs par `jsonError()` avec un code en majuscules.
3. Ne pas utiliser de syntaxe postérieure à PHP 7.3.
4. Documenter dans [REST_API.md](REST_API.md) (version de disponibilité) et dans `vigilo-website/data/openapi.yaml`.
5. Ajouter des tests dans `functional.py`. L'ajouter au contrat (`CASES` de `contract.py`) n'est possible qu'après
   une release qui la contient (les instantanés viennent de la version publiée).
6. Côté clients (application web, site) : prévoir le repli quand la route renvoie 404 sur une instance plus ancienne.

### 9.2 Ajouter un champ à une réponse sans casser les clients

- Ajouter une clé, ne jamais renommer, retirer ni changer le type d'une clé existante (les applications mobiles ne
  sont plus mises à jour).
- Le contrat échouera sur les cas concernés : déclarer le changement dans `tests/api/expected_changes.json` avec la
  nouvelle valeur attendue et la raison, et le mentionner dans la colonne « Compatibilité » de REST_API.md.
- Pour `get_issues.php`, ajouter la colonne au `SELECT` de `getQuery()` et la clé dans `$issue` ; penser aux sorties
  CSV (les colonnes suivent les clés) et GeoJSON (`properties` construites à la main).

### 9.3 Ajouter une page ou une action à l'admin

Voir 4.3. Pour une action dans une page existante : ajouter le rôle dans `$actions_acl` si la page en a un, traiter
l'action en tête de page, écrire le lien avec `csrf_query()` ou le formulaire avec `csrf_field()`, journaliser par
`audit_log()`, ajouter une vérification dans `tests/admin/smoke.py`.

### 9.4 Ajouter un réglage d'instance

1. Migration : `INSERT IGNORE INTO obs_config (config_param, config_value) VALUES ('vigilo_xxx', '<défaut>');`
   (choisir un défaut qui garde le comportement précédent).
2. `$settings_fields` de `app/admin/inc/settings.php` : `card`, `type` (`text`, `urlbase`, `proto`, `language`,
   `timezone`, `charset`, `bool`, `int`, `url`), `label`, `default`, `help`, éventuellement `env` (variable
   d'environnement de repli affichée) et `link`.
3. Lecture dans le code : requête `SELECT config_value FROM obs_config WHERE config_param='vigilo_xxx' LIMIT 1` en
   traitant l'absence de ligne (instances non migrées), ou ajout d'un `case` dans la boucle de `common.php` si le
   réglage est utilisé partout.
4. Documenter le réglage sur le site (`vigilo-website/content/documentation/configuration/`).

### 9.5 Ajouter une migration

1. Augmenter `BACKEND_VERSION` dans `app/includes/version.php` si la version courante est déjà publiée (tag existant) :
   **ne jamais modifier une migration publiée**.
2. Créer `app/migrations/init-X.Y.Z.sql` (même version) :
   - instructions rejouables : `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, motif conditionnel pour les colonnes :

     ```sql
     SET @vigilo_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `obs_x` ADD COLUMN `x_y` int NOT NULL DEFAULT 0', 'SELECT 1')
       FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'obs_x' AND COLUMN_NAME = 'x_y');
     PREPARE vigilo_stmt FROM @vigilo_sql;
     EXECUTE vigilo_stmt;
     DEALLOCATE PREPARE vigilo_stmt;
     ```

   - une instruction par ligne ou par bloc, terminée par `;` en fin de ligne ; commentaires sur des lignes
     commençant par `--` ;
   - dernière ligne :
     ``UPDATE `obs_config` SET `config_value` = 'X.Y.Z' WHERE `obs_config`.`config_param` = 'vigilo_db_version';``
     (vérifié par le job `version` de la CI).
3. Ajouter la version précédente à la boucle « Upgrade from every released version » de la CI si elle est publiée.
4. Mettre à jour la liste des tables d'[ARCHITECTURE.md](ARCHITECTURE.md) et le CHANGELOG.

### 9.6 Ajouter une variable de webhook

1. `webhook_variables()` : nom et description (affichés dans l'admin) ; `webhook_variable_events()` : événements qui
   l'utilisent si elle n'est pas commune (préfixes `resolution_`, `problem_`) — le formulaire n'affiche que les
   variables de l'événement choisi (`data-events`, script de `admin/inc/webhooks.php`).
2. `webhook_observation_values()` : valeur (chaîne). Si son calcul est coûteux ou distant, mettre `null` et la
   calculer dans `webhook_value()` à la première utilisation.
3. Si les valeurs d'exemple de `webhook_admin_test_values()` (page Webhooks) sont utilisées, y ajouter la clé.
4. Documenter dans [FONCTIONNEMENT.md](FONCTIONNEMENT.md) (section Webhooks) et [WEBHOOKS.md](WEBHOOKS.md) ; tester
   dans `T10Webhooks`.

### 9.7 Publier une version

1. `BACKEND_VERSION`, migration éventuelle, section du `CHANGELOG.md`.
2. Fusion sur `master` avec la CI verte.
3. Tag `vX.Y.Z` sur le commit de fusion et push du tag : `release.yml` publie la release (archive, `SHA256SUMS`,
   signature si le secret existe) et les images.
4. Après publication, réenregistrer si besoin les instantanés du contrat sur cette version
   (`tests/api/run.sh <app de la release> <image> --record`) et vider `expected_changes.json` des entrées devenues
   inutiles.
5. Code supprimé : l'ajouter à `scripts/obsolete-paths.txt` avant la release.

---

## 10. Pièges connus et dette technique

Comportements à connaître avant de modifier le code. Beaucoup sont **conservés volontairement pour la
compatibilité** (les tests de contrat les figent) ; les autres sont des défauts connus.

### Compatibilité et contraintes

- **PHP 7.3** : pas de `match`, `fn`, types union, arguments nommés, propriétés typées, `str_contains`,
  `str_starts_with`, opérateur `?->`. Les types de retour scalaires (`: bool`, `: void`) de `get_issues.php` sont
  permis (PHP 7.1). La CI exécute les tests sous PHP 7.3 avec l'image `vigilobs/vigilo-backend:0.0.20`.
- **mbstring n'est pas garanti** sur les hébergements mutualisés (d'où `vigilo_truncate()`), mais `webhooks.php`
  (`mb_substr`), `inc/categories.php` (`mb_strlen`), `inc/cities.php` (`mb_strtolower`) et `inc/webhooks.php`
  l'appellent directement. L'image Docker l'inclut.
- **`sql_mode = ''`** dans `common.php` et dans les migrations : le code compte sur les conversions implicites de MySQL
  (chaîne vide dans une colonne entière, troncature). Ne pas l'activer sans revoir toutes les requêtes.
- **Noms historiques** : en-tête `BACKEND_VERSION`, champ `group` toujours 0, `status` en réponse de
  `add_image.php`/`delete.php`, `getInstanceNameFromFirebase()`, `generate_panel.php` qui ne génère plus de panneau,
  `tweet_content` / `twitter` dans `get_scope.php`, code d'erreur `TOKENNOTPROVIDED` pour un secret erroné dans
  `delete.php`, `UNKNOWSCOPE`.
- `get_photo.php` annonce `Content-Type: image/png` mais envoie du JPEG ; ses erreurs JSON partent aussi avec ce type,
  sauf `PHOTONOTFOUND`. De même, les erreurs de `generate_panel.php` (sauf `PHOTONOTFOUND`) sont envoyées avec
  `Content-Type: image/jpeg`.
- `get_version.php` et `generate_panel.php` n'envoient pas `Access-Control-Allow-Origin`.
- `get_issues.php` ne filtre jamais le scope `34_montpellier` (première instance).

### Défauts connus

- **`offset` de `get_issues.php`** : documenté dans REST_API.md, `setOffset()` existe, mais le paramètre n'est jamais
  lu dans le bloc principal : il est ignoré.
- **`status` de `get_issues.php`** est filtré en PHP **après** le `LIMIT count` : `count=10&status=1` peut renvoyer
  moins de 10 observations alors qu'il en existe davantage.
- **Doublons dans `get_issues.php`** : une observation liée à deux résolutions sort deux fois (jointure sans
  regroupement). L'admin signale ces doublons et empêche alors de valider la résolution.
- **`{{status}}` des webhooks** vient de `obs_list.obs_status`, toujours 0 ; le statut réel est celui de la résolution
  (`get_issues.php`). La variable vaut donc toujours « 0 ».
- `get_issues.php` : un paramètre invalide (`format=xml`, `count=abc`) lève une exception non interceptée (HTTP 500,
  corps vide) ; `lat` + `lon` sans `radius` provoque un avertissement PHP (`$_GET['radius']` non défini).
- `approve.php` accepte n'importe quelle valeur numérique de `approved` (par exemple 5) et l'écrit en base.
- `approve.php` et `delete.php` lisent `$_GET['token']` sans `isset` : sans token, avertissement PHP dans le journal,
  puis refus d'accès ou réponse « token inconnu ».
- `delete.php` ne retire pas l'observation de ses résolutions (les lignes de `obs_resolutions_tokens` restent), à la
  différence de `deleteObs()` de l'admin. Aucune des deux suppressions n'efface les notes (`obs_notes`).
- La suppression d'une ville (page Villes) ne touche pas aux observations qui la référencent : elles gardent un
  `obs_city` sans ville correspondante et `get_issues.php` renvoie alors `cityname` à `null`.
- `create_resolution.php` exempte de l'anti-spam la clé admin mais pas la clé modérateur (contrairement à
  `create_issue.php`).
- `create_issue.php` : le message `CITYNOTFOUND` affiche toujours « ID 0 » (la variable est remise à 0 avant le
  message) ; `explanation` n'est pas limitée en longueur ; une modification par un admin ne change pas le scope.
- Lien `action=resolve&new_status=` de la page Résolutions : seul le rôle autorisé pour le nouveau statut est vérifié,
  pas la transition (`nextstatus`), contrairement au formulaire de la même page.
- **Sessions de l'admin** : le rôle est lu une fois à la connexion et conservé en session. Un compte supprimé ou
  rétrogradé garde ses droits jusqu'à la fin de sa session (8 heures d'inactivité ou déconnexion).
- Un citystaff n'a pas l'onglet « À qualifier », mais `?page=observations&approved=0` lui affiche les observations non
  modérées de ses villes (champs non modifiables, mais notes et action « resolve » disponibles).
- **Rollback de mise à jour** : la base n'est restaurée que si `vigilo_db_version` a changé. Si la première migration
  à appliquer échoue après avoir exécuté une partie de ses instructions, la version ne change pas : le code est
  restauré mais les changements partiels de schéma restent (le test `tests/update/run.sh` utilise une migration qui
  échoue dès sa première instruction et ne couvre pas ce cas).
- **Migrations anciennes** (0.0.2 à 0.0.21) : `CREATE TABLE` sans `IF NOT EXISTS`, non rejouables ; 0.0.2 et 0.0.3 ne
  peuvent pas enregistrer leur version (`obs_config` n'existe qu'à partir de 0.0.4). Une installation interrompue
  avant 0.0.4 laisse une base que `vigilo_db_version()` refuse (« obs_config absente mais obs_list présente ») : il faut
  vider la base.
- `vigilo_shownonapproved` vaut **1** sur une installation neuve (`schema-0.0.17.sql`) — lu comme `pixelated` : les
  observations non modérées sont listées (photo pixelisée) tant que l'admin ne choisit pas « ne rien afficher » (`0`),
  alors que le défaut de `$settings_fields` (utilisé si la ligne manque) est `0`. De même `mysql_charset` vaut `utf8`
  en base (`init-0.0.4.sql`), le défaut de la page étant `utf8mb4`.
- `common.php` arrête le script sans code d'erreur HTTP (200, corps vide ou texte) si `config.php` manque ou si la base
  est injoignable.

### Code à manier avec précaution

- **Fonctions de `handle.php` sans échappement interne** (`getObsIdByToken`, `isTokenWithSecretId`, `addResolution`,
  `updateResolution`…) : leurs arguments vont tels quels dans le SQL. Les appelants actuels échappent ou convertissent
  avant ; toute nouvelle utilisation doit faire de même.
- **Double échappement** : `create_issue.php` échappe le commentaire, l'adresse et l'explication une fois et les insère
  sans repasser par `mysqli_real_escape_string` ; les autres champs sont échappés deux fois sans effet visible car ce
  sont des nombres ou des tokens. Ne pas « corriger » en échappant encore les champs texte (des `\` apparaîtraient dans
  l'application).
- `$acls` charge toute la table `obs_roles` à chaque requête ; `sameas()` et le contrôle « observations sans villes »
  de la page Observations parcourent toutes les observations en PHP. Acceptable pour la taille des instances actuelles.
- Code mort : `generategroups()`, `get_data_from_gps_coordinates()`, `isScopeExists()` ;
  colonne `obs_list.obs_status` ; `$_POST['token']` de `create_resolution.php` (mise à jour non implémentée).
- Répertoires et fichiers hors de `app/` non utilisés par l'image ni par la CI : `debug/`, `docker_backup/`,
  `scripts/umap/`, `config/montpellier.sh`, `mysql/populate/`. `mysql/pre_sql.sql` est utilisé par le job
  `migrations` de la CI.
