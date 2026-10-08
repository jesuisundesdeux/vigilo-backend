# Administration

Interface web `https://<instance>/admin/` (Bootstrap 5 servi localement, thème sombre, responsive) :

- **Tableau de bord** : observations à modérer, résolutions à valider, chiffres clés.
- **Observations** : modération (approuver, refuser), modification, suppression, filtres (statut, ville,
  catégorie, adresse, doublons proches), notes privées des modérateurs, photos non pixelisées.
- **Résolutions** : validation des résolutions déclarées et de leurs photos.
- **Villes**, **Scopes**, **Comptes** (rôles, villes des citystaff, clés API).
- **Configuration** : les réglages ci-dessus, validés à l'enregistrement.
- **Journal** : actions privilégiées (connexions, modérations, réglages, mises à jour).
- **Mises à jour** : versions du code et de la base, mise à jour en un clic, vérifications de sécurité.

Sécurité : sessions durcies, jeton CSRF sur toutes les actions, limitation des tentatives de connexion,
contrôle d'accès par rôle, mots de passe hachés (`password_hash`).

Rôles des comptes : [FONCTIONNEMENT.md](FONCTIONNEMENT.md#rôles). Réglages : [INSTALLATION.md](INSTALLATION.md#configuration).
