# Serveur de floutage Vigilo

Service optionnel qui masque les **visages** et les **plaques d'immatriculation** des photos
avant leur publication. Quand il est configuré, le backend lui envoie chaque photo reçue
(observations et résolutions) et enregistre la photo floutée à la place de l'originale.
Si le serveur ne répond pas ou renvoie une erreur, la photo est enregistrée telle
qu'envoyée et l'incident est journalisé (log PHP) : la modération manuelle reste le garde-fou,
une photo non approuvée n'étant visible que pixelisée.

Le service tourne sur le CPU, sans GPU ni accès réseau, avec la même méthode que le service de
floutage de [Panoramax](https://panoramax.fr) ([SGBlur](https://github.com/cquest/sgblur)) :

- visages et plaques : le modèle de détection **YOLO11s entraîné par Panoramax** sur des photos de
  rue (`models/yolo11s_panoramax.onnx`, classes panneau / plaque / visage), exécuté avec
  ONNX Runtime ; les panneaux de signalisation détectés ne sont **pas** masqués ;
- visages, en complément : le détecteur YuNet (OpenCV, `models/face_detection_yunet_2023mar.onnx`),
  meilleur sur les visages proches et de face ;
- les zones détectées sont pixelisées puis floutées : l'opération est irréversible.

Temps de traitement : environ 0,4 s par photo (1024 px) sur 4 cœurs ;
mémoire : environ 500 Mo.

Sur des photos de rue annotées (benchmark OpenALPR), le modèle trouve 98 à 100 % des plaques,
y compris les plaques lointaines, avec beaucoup moins de zones masquées à tort que l'ancienne
méthode (lignes de caractères), qui floutait aussi les textes et les panneaux.

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
| `GET /health` | `200` `{"status": "ok", "model": "yolo11s_panoramax.onnx", "sizes": [1024]}` |

```sh
curl -F picture=@photo.jpg http://127.0.0.1:8000/blur -o floutee.jpg
```

## Réglages (variables d'environnement)

| Variable | Défaut | |
|---|---|---|
| `BLUR_PORT` | `8000` | port d'écoute |
| `BLUR_WORKERS` | `2` | photos traitées en même temps |
| `BLUR_THREADS` | nombre de CPU / `BLUR_WORKERS` | threads de calcul par photo |
| `BLUR_SIZES` | `1024` | passes de détection (taille de l'image en pixels) ; `1024,2048` trouve davantage de petits visages et plaques lointaines, environ 5 fois plus lent |
| `BLUR_FACE_CONFIDENCE` | `0.2` | score minimal d'un visage pour le modèle (plus bas : plus de visages trouvés) |
| `BLUR_PLATE_CONFIDENCE` | `0.3` | score minimal d'une plaque pour le modèle |
| `BLUR_FACE_THRESHOLD` | `0.6` | score minimal d'un visage pour YuNet |
| `BLUR_MODEL` | `models/yolo11s_panoramax.onnx` | modèle de détection (autre modèle YOLO exporté en ONNX avec les mêmes classes) |
| `BLUR_MAX_BYTES` | 20 Mo | taille maximale d'une photo |
| `BLUR_JPEG_QUALITY` | `90` | qualité du JPEG renvoyé |

Côté backend, `VIGILO_BLUR_TIMEOUT` (secondes, 60 par défaut) limite l'attente du serveur.

## Tests

```sh
pip install -r requirements.txt
python3 -m unittest discover -s tests -v
```

`tests/fixtures/street.jpg` contient un visage et un panneau, `car.jpg` une voiture et sa plaque ;
les tests vérifient que le visage et la plaque sont masqués et que le panneau et le reste de la
photo sont intacts. `tests/blur/compose.sh` (à la racine du dépôt)
teste docker-compose de bout en bout : photo envoyée au backend, stockée floutée.
