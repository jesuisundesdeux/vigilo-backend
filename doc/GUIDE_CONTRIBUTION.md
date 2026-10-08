# Guide du contributeur

## Prérequis

Vigilo-backend est développé en PHP procédural (compatible PHP 7.3 à 8.3, sans framework ni Composer) avec
une base MySQL/MariaDB. L'organisation du code et le schéma de la base sont décrits dans
[ARCHITECTURE.md](ARCHITECTURE.md).

## Environnement de développement

```sh
cp .env_sample .env
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build   # code de ./app monté
```

L'image est construite depuis le dépôt et le code de `app/` est monté dans le conteneur : les modifications
sont prises en compte sans reconstruire. Voir l'[initialisation](https://vigilo.city/fr/documentation/installation/initialisation/) pour la mise en route
(`install.php`, configuration).

## Branches et pull requests

- `master` est la branche de développement ; chaque version est publiée par un tag `vX.Y.Z`.
- Contributions par pull request depuis une branche (nom de la fonctionnalité) ou un fork ; la CI doit être verte.
- Toute évolution du schéma passe par une migration `app/migrations/init-X.Y.Z.sql` (rejouable) et la version
  dans `app/includes/version.php`.
- Compatibilité : les réponses de l'API ne doivent pas changer pour les applications existantes (tests de contrat) ;
  un changement voulu est déclaré dans `tests/api/expected_changes.json` et documenté dans [REST_API.md](REST_API.md).

## Tests

Les tests tournent en CI (`.github/workflows/ci.yml`) et en local ([tests/README.md](../tests/README.md)) :

| Suite | Vérifie |
|---|---|
| Lint | Syntaxe PHP 7.3 et 8.3 |
| Migrations | Installation neuve, rejeu, montée depuis chaque version, refus du retour arrière |
| Contrat de l'API (`tests/api/run.sh`) | 72 requêtes : réponses identiques à la version précédente (PHP 8.3 et 7.3) |
| Fonctionnels (`--functional`) | Comportement de chaque appel, modération, pixelisation, floutage, anti-spam, injections SQL |
| Admin (`ADMIN_SMOKE=1`) | Toutes les pages et actions, CSRF, rôles, aucune erreur PHP |
| Mise à jour (`tests/update/run.sh`) | Version signée installée, archive altérée refusée, migration cassée annulée |
| Floutage (`blur-server/tests`, `tests/blur/compose.sh`) | Visages et plaques masqués, docker-compose de bout en bout |

## Publier une version

1. Mettre à jour `app/includes/version.php`, ajouter `app/migrations/init-X.Y.Z.sql` et compléter le `CHANGELOG.md`.
2. Pousser le tag `vX.Y.Z` : le workflow « Release image » (`.github/workflows/release.yml`) publie :
   - la release GitHub avec l'archive de mise à jour (`scripts/build-release.sh`), `SHA256SUMS` et sa signature ;
   - les images `vigilo-backend` (`X.Y.Z`, `X.Y`, `stable`, `latest`) et `vigilo-blur` (`X.Y.Z`, `X.Y`, `latest`),
     multi-architecture, signées avec cosign, sur ghcr.io.
3. Signature (recommandé) : `php scripts/release-keygen.php`, clé secrète dans le secret GitHub
   `VIGILO_RELEASE_SIGNING_KEY`, clé publique dans `app/includes/release_key.php` (publiée avec une version).
4. Docker Hub : secrets `DOCKERHUB_USERNAME` et `DOCKERHUB_TOKEN` (dépôts `vigilobs/vigilo-backend` et
   `vigilobs/vigilo-blur`) ; sans eux, les images ne sont publiées que sur ghcr.io.
5. Code retiré d'une version : le lister dans `scripts/obsolete-paths.txt` pour que la mise à jour depuis
   l'admin le supprime des instances.

## Suivi

Les bugs, réflexions et demandes de fonctionnalités sont dans le
[tracker GitHub](https://github.com/jesuisundesdeux/vigilo-backend/issues).
