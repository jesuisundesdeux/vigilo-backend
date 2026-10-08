# Changelog

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

### Fonctionnalités et corrections
- Option pour masquer les observations résolues depuis N jours (#257).
- Image en base64 dans un corps JSON (#267), documentation de l'envoi d'image.
- Compatibilité PHP 8.x (#285), code toujours compatible PHP 7.3.
- `delete.php` sans notice PHP dans le JSON.
- Catégories et liste des instances mises en cache (plus de 500 si GitHub ne répond pas).
- `mosaic.php` sans scripts tiers.
