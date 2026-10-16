# Vigilo Backend

API REST et interface d'administration des observations de l'application [Vigilo](https://vigilo.city/fr/).
Chaque association installe et gère sa propre instance ; les applications mobiles et la
[web app](https://github.com/jesuisundesdeux/vigilo-webapp) s'y connectent.

Un citoyen signale un problème (photo, position, catégorie, commentaire) ; les modérateurs de l'instance
l'approuvent avant publication ; les services des villes (comptes citystaff) en suivent la résolution.
Une instance peut couvrir plusieurs zones (scopes). Les photos sont pixelisées tant qu'elles ne sont pas
approuvées et peuvent être floutées automatiquement (visages, plaques) par un serveur de floutage optionnel.

## Démarrage rapide (Docker)

Seuls [`docker-compose.yml`](docker-compose.yml) et [`.env_sample`](.env_sample) sont nécessaires (le code est dans
l'image) :

```sh
curl -fsSLO https://raw.githubusercontent.com/jesuisundesdeux/vigilo-backend/master/docker-compose.yml
curl -fsSL -o .env https://raw.githubusercontent.com/jesuisundesdeux/vigilo-backend/master/.env_sample
# .env : mots de passe, VOLUME_PATH, BIND, WATCHTOWER_TOKEN (openssl rand -hex 32)
docker compose up -d         # Watchtower inclus (mises à jour depuis l'admin) ; --profile blur : serveur de floutage
```

Puis ouvrir `https://<instance>/admin/` : l'assistant d'installation demande le code d'installation affiché par
`docker compose logs web` et crée le premier administrateur. En hébergement mutualisé, il configure aussi la base de données.
Procédures complètes (Docker, hébergement mutualisé, configuration) : [documentation d'installation](https://vigilo.city/fr/documentation/installation/) sur vigilo.city.

Images : [`vigilobs/vigilo-backend`](https://hub.docker.com/r/vigilobs/vigilo-backend) et
`ghcr.io/jesuisundesdeux/vigilo-backend` (PHP 8.3 ; le code reste compatible PHP 7.3).

## Documentation

| Document | Contenu |
|---|---|
| [Fonctionnement](doc/FONCTIONNEMENT.md) | Cycle d'une observation, statuts, scopes, rôles, catégories, photos, pixelisation, floutage, webhooks |
| [Installation](https://vigilo.city/fr/documentation/installation/) (vigilo.city) | Docker ou hébergement mutualisé, initialisation |
| [Configuration](https://vigilo.city/fr/documentation/configuration/) (vigilo.city) | Réglages de l'instance, scopes, villes, catégories, référencement |
| [Administration](https://vigilo.city/fr/documentation/administration/) (vigilo.city) | Panneau d'administration, rôles, modération |
| [Sauvegarde](https://vigilo.city/fr/documentation/maintenance/sauvegarde/) (vigilo.city) | Données à sauvegarder |
| [Webhooks](doc/WEBHOOKS.md) | Exemples : Mastodon, Slack, Bluesky, ticketing de collectivité (Open311), Redmine |
| [API REST](doc/REST_API.md) | Vue d'ensemble des routes, paramètres et réponses |
| [Mises à jour](doc/UPGRADE.md) | Mise à jour depuis l'admin ou Docker (Watchtower), actions nécessaires selon la version de départ, anciennes versions (publiée aussi sur vigilo.city) |
| [Architecture](doc/ARCHITECTURE.md) | Organisation du code, variables d'environnement, base de données |
| [Guide du code](doc/GUIDE_CODE.md) | Fonctionnement détaillé du code : modules, routes, admin, données, sous-systèmes, tests, recettes, pièges |
| [Guide du contributeur](doc/GUIDE_CONTRIBUTION.md) | Développement, tests, publication d'une version |
| [Glossaire](doc/GLOSSAIRE.md) | Vocabulaire Vigilo |
| [Serveur de floutage](blur-server/README.md) | Masquage des visages et plaques d'immatriculation |
| [Tests](tests/README.md) | Lancer les suites de tests en local |
| [Historique](CHANGELOG.md) | Changements par version |

Documentation utilisateur de Vigilo : [vigilo.city](https://vigilo.city/fr/documentation/).

## Licence

GPL-3.0 ([COPYING](COPYING)). © Vélocité Montpellier et les contributeurs.
