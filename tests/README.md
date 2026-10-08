# Tests

Tous les tests tournent dans la CI (`.github/workflows/ci.yml`) et peuvent être lancés en local.

Prérequis : une MariaDB accessible (`MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`,
`MYSQL_ROOT_PASSWORD` ; la base est recréée), le client `mysql`, PHP en ligne de commande, Python 3 et Docker.

| Suite | Commande | Ce qui est vérifié |
| ----- | -------- | ------------------ |
| Contrat de l'API | `tests/api/run.sh app <image>` | Les réponses des routes publiques sont identiques à celles de la version précédente (`tests/api/snapshots.json`, changements voulus dans `tests/api/expected_changes.json`). |
| Tests fonctionnels de l'API | `tests/api/run.sh app <image> --functional` | Le comportement de chaque appel : création, photo, modération, publication, mise à jour, suppression, filtres et formats de `get_issues`, pixellisation, résolutions, webhooks (appel à la publication, variables, échappement, échec sans blocage), serveur de floutage (photo envoyée et remplacée, conservée telle qu'envoyée en cas d'échec ; avec le vrai serveur si `BLUR_SERVER_URL` est défini), anti-spam, injections SQL, répertoires privés. |
| Admin | `ADMIN_SMOKE=1 tests/api/run.sh app <image>` | Toutes les pages, les actions, le CSRF, les rôles, les photos de l'admin, sans erreur PHP. |
| Serveur de floutage | `python3 -m unittest discover -s blur-server/tests` | Visages et plaques détectés et masqués, reste de la photo intact, appels HTTP. |
| docker-compose + floutage | `tests/blur/compose.sh <image backend> <image blur>` | Profil `blur` de bout en bout : photo envoyée au backend, enregistrée visage et plaques masqués. |
| Migrations | job `migrations` de la CI | Installation neuve, rejeu, montée depuis chaque version, refus du retour arrière. |
| Mise à jour depuis l'admin | `tests/update/run.sh` | Version signée installée, code obsolète supprimé, archive altérée refusée, migration cassée annulée. |

`<image>` : l'image construite depuis ce dépôt (`docker build -t vigilo-backend:dev .`),
`vigilobs/vigilo-backend:0.0.20` pour PHP 7.3 (cas des hébergements mutualisés), ou `host` pour le PHP
installé sur la machine (serveur intégré de PHP, sans Apache).
