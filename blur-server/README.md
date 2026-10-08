# Serveur de floutage Vigilo

Service optionnel qui masque les **visages** et les **plaques d'immatriculation** des photos
avant leur publication. Quand il est configuré, le backend lui envoie chaque photo reçue
(observations et résolutions) et enregistre la photo floutée à la place de l'originale.
Si le serveur ne répond pas ou renvoie une erreur, la photo est enregistrée telle
qu'envoyée et l'incident est journalisé (log PHP) : la modération manuelle reste le garde-fou,
une photo non approuvée n'étant visible que pixelisée.

Le service tourne sur le CPU, sans GPU ni accès réseau :

- visages : détecteur YuNet (OpenCV, `models/face_detection_yunet_2023mar.onnx`, licence MIT) ;
- plaques : lignes de 4 à 10 caractères sur un fond de plaque (plaques européennes, toutes
  couleurs) et le détecteur de plaques fourni avec OpenCV ;
- les zones détectées sont pixelisées puis floutées : l'opération est irréversible.

## Avec docker-compose

```sh
# .env
VIGILO_BLUR_URL=http://blur:8000/blur

docker compose --profile blur up -d
```

L'image `ghcr.io/jesuisundesdeux/vigilo-blur` est publiée à chaque version. Elle peut aussi être
construite depuis ce répertoire (`docker compose --profile blur build blur`).

## Installation classique (hors Docker)

```sh
docker run -d --restart always -p 127.0.0.1:8000:8000 ghcr.io/jesuisundesdeux/vigilo-blur:0.0
# ou : pip install -r requirements.txt && python3 blur_server.py
```

puis, dans l'admin (Configuration > Photos), **Serveur de floutage** : `http://127.0.0.1:8000/blur`.

Le réglage de l'admin est prioritaire ; s'il est vide, la variable d'environnement
`VIGILO_BLUR_URL` du backend est utilisée ; si les deux sont vides, les photos sont publiées
telles qu'envoyées. Tout serveur répondant au même appel convient (par exemple
[SGBlur](https://github.com/cquest/sgblur), dont l'ancien réglage `sgblur_url` est repris
automatiquement en 0.0.22).

## API

| Appel | Réponse |
|---|---|
| `POST /blur`, photo dans le champ multipart `picture` (ou en corps brut `image/jpeg`, `image/png`…) | `200` `image/jpeg` : la photo floutée ; en-têtes `X-Blur-Faces` et `X-Blur-Plates` (nombre de zones masquées) |
| | `400` : pas une image ; `413` : photo trop grande |
| `GET /health` | `200` `{"status": "ok"}` |

```sh
curl -F picture=@photo.jpg http://127.0.0.1:8000/blur -o floutee.jpg
```

## Réglages (variables d'environnement)

| Variable | Défaut | |
|---|---|---|
| `BLUR_PORT` | `8000` | port d'écoute |
| `BLUR_WORKERS` | `2` | photos traitées en même temps |
| `BLUR_FACE_THRESHOLD` | `0.6` | seuil de détection des visages (plus bas : plus de visages trouvés) |
| `BLUR_MAX_BYTES` | 20 Mo | taille maximale d'une photo |
| `BLUR_JPEG_QUALITY` | `90` | qualité du JPEG renvoyé |

Côté backend, `VIGILO_BLUR_TIMEOUT` (secondes, 60 par défaut) limite l'attente du serveur.

## Tests

```sh
pip install -r requirements.txt
python3 -m unittest discover -s tests -v
```

`tests/fixtures/scene.jpg` contient un visage et deux plaques ; les tests vérifient que ces zones
sont masquées et que le reste de la photo est intact. `tests/blur/compose.sh` (à la racine du dépôt)
teste docker-compose de bout en bout : photo envoyée au backend, stockée floutée.
