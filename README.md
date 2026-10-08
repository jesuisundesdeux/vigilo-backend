# Vigilo Backend

API REST et interface d'administration des observations de l'application [Vigilo](https://vigilo.city/fr/).
Chaque association installe et gère sa propre instance ; les applications mobiles et la
[web app](https://github.com/jesuisundesdeux/vigilo-webapp) s'y connectent.

Un citoyen signale un problème (photo, position, catégorie, commentaire) ; les modérateurs de l'instance
l'approuvent avant publication ; les services des villes (comptes citystaff) en suivent la résolution.
Une instance peut couvrir plusieurs zones (scopes). Les photos sont pixelisées tant qu'elles ne sont pas
approuvées et peuvent être floutées automatiquement (visages, plaques) par un serveur de floutage optionnel.

## Démarrage rapide (Docker)

```sh
git clone https://github.com/jesuisundesdeux/vigilo-backend.git && cd vigilo-backend
cp .env_sample .env          # mots de passe, VOLUME_PATH, BIND
docker compose up -d         # --profile blur : serveur de floutage, --profile watchtower : mises à jour depuis l'admin
```

Puis ouvrir `https://<instance>/install.php` pour créer le premier administrateur, et `https://<instance>/admin/`.
Hébergement PHP/MySQL classique : voir [l'installation](doc/INSTALLATION.md#hébergement-classique-mutualisé-serveur-dédié).

Images : [`vigilobs/vigilo-backend`](https://hub.docker.com/r/vigilobs/vigilo-backend) et
`ghcr.io/jesuisundesdeux/vigilo-backend` (PHP 8.3 ; le code reste compatible PHP 7.3).

## Documentation

| Document | Contenu |
|---|---|
| [Fonctionnement](doc/FONCTIONNEMENT.md) | Cycle d'une observation, statuts, scopes, rôles, photos, pixelisation et floutage |
| [Installation](doc/INSTALLATION.md) | Docker ou hébergement classique, mise en route, configuration (réglages, variables d'environnement) |
| [Administration](doc/ADMINISTRATION.md) | Pages de l'admin, modération, comptes, journal, sécurité |
| [API REST](doc/REST_API.md) | Vue d'ensemble des routes, paramètres et réponses |
| [Mises à jour](doc/UPGRADE.md) | Mise à jour depuis l'admin ou Docker, passage à la 0.0.22, anciennes versions |
| [Architecture](doc/ARCHITECTURE.md) | Organisation du code, base de données |
| [Guide du contributeur](doc/GUIDE_CONTRIBUTION.md) | Développement, tests, publication d'une version |
| [Glossaire](doc/GLOSSAIRE.md) | Vocabulaire Vigilo |
| [Serveur de floutage](blur-server/README.md) | Masquage des visages et plaques d'immatriculation |
| [Tests](tests/README.md) | Lancer les suites de tests en local |
| [Historique](CHANGELOG.md) | Changements par version |

Documentation utilisateur de Vigilo : [vigilo.city](https://vigilo.city/fr/documentation/).

## Licence

GPL-3.0 ([COPYING](COPYING)). © Vélocité Montpellier et les contributeurs.
