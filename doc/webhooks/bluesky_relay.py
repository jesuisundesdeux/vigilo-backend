#!/usr/bin/env python3
"""
Relais Vigilo -> Bluesky (Python 3, bibliothèque standard uniquement).

Bluesky n'accepte pas de jeton permanent : chaque publication demande d'ouvrir une
session (com.atproto.server.createSession) puis de créer le message
(com.atproto.repo.createRecord). Ce relais reçoit le webhook de Vigilo et fait ces
deux appels.

    BSKY_HANDLE=vigilo-maville.bsky.social BSKY_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx \
    RELAY_SECRET=une-longue-chaine-aleatoire python3 bluesky_relay.py

Variables : BSKY_HANDLE, BSKY_APP_PASSWORD (mot de passe d'application créé dans
Bluesky > Paramètres > Mots de passe d'application), RELAY_SECRET (doit être envoyé
par Vigilo dans l'en-tête X-Relay-Secret), BSKY_SERVICE (https://bsky.social par
défaut), RELAY_PORT (8080 par défaut). À placer derrière un reverse proxy HTTPS, ou
sur le même réseau que le backend.

Corps JSON attendu (webhook Vigilo) :
    {"text": "...", "url": "{{observation_url}}", "title": "...", "description": "..."}
"""

import datetime
import hmac
import json
import os
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

SERVICE = os.environ.get('BSKY_SERVICE', 'https://bsky.social').rstrip('/')
HANDLE = os.environ['BSKY_HANDLE']
PASSWORD = os.environ['BSKY_APP_PASSWORD']
SECRET = os.environ['RELAY_SECRET']


def xrpc(method, payload, token=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(SERVICE + '/xrpc/' + method, data=json.dumps(payload).encode(), headers=headers)
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read())


def post(observation):
    session = xrpc('com.atproto.server.createSession', {'identifier': HANDLE, 'password': PASSWORD})
    record = {
        '$type': 'app.bsky.feed.post',
        'text': observation.get('text', '')[:300],  # 300 caractères au plus
        'createdAt': datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z'),
        'langs': ['fr'],
    }
    if observation.get('url'):
        # Carte de lien vers l'observation (cliquable, avec titre et description)
        record['embed'] = {'$type': 'app.bsky.embed.external', 'external': {
            'uri': observation['url'], 'title': observation.get('title', '')[:300],
            'description': observation.get('description', '')[:1000]}}
    return xrpc('com.atproto.repo.createRecord',
                {'repo': session['did'], 'collection': 'app.bsky.feed.post', 'record': record}, session['accessJwt'])


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if not hmac.compare_digest(self.headers.get('X-Relay-Secret', ''), SECRET):
            return self.reply(403, {'error': 'secret invalide'})
        try:
            observation = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))))
            self.reply(200, {'uri': post(observation)['uri']})
        except Exception as e:  # erreur Bluesky ou JSON invalide : visible dans le journal des webhooks
            self.reply(502, {'error': str(e)[:200]})

    def reply(self, code, body):
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)


if __name__ == '__main__':
    HTTPServer(('', int(os.environ.get('RELAY_PORT', '8080'))), Handler).serve_forever()
