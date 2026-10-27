#!/usr/bin/env python3
"""
Functional tests of the API: the behaviour of each call, checked with assertions
(where tests/api/contract.py compares the responses with those of the last release).

Run on a fresh database loaded with tests/api/seed.sql (tests/api/run.sh does it):

  tests/api/run.sh app <image> --functional

or directly against a seeded instance:

  python3 tests/api/functional.py --base http://127.0.0.1:8089

Some tests change settings in the database: they use the mysql client with the
MYSQL_HOST / MYSQL_DATABASE / MYSQL_ROOT_PASSWORD variables.

Standard library only.
"""

import argparse
import base64
import email.parser
import email.policy
import http.cookiejar
import json
import os
import re
import struct
import subprocess
import sys
import threading
import time
import unittest
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = None
ADMIN = 'ADMINKEY0123456789'
MODO = 'MODKEY0123456789'

with open(os.path.join(HERE, 'fixtures', 'photo.jpg'), 'rb') as f:
    PHOTO = f.read()
# Photos with a face and with a licence plate (1024x768), see blur-server/tests/fixtures
with open(os.path.join(HERE, '..', '..', 'blur-server', 'tests', 'fixtures', 'street.jpg'), 'rb') as f:
    SCENE = f.read()
with open(os.path.join(HERE, '..', '..', 'blur-server', 'tests', 'fixtures', 'car.jpg'), 'rb') as f:
    CAR = f.read()


class Response:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.body = body

    def json(self):
        return json.loads(self.body.decode('utf-8'))

    @property
    def text(self):
        return self.body.decode('utf-8', 'replace')

    def header(self, name):
        return self.headers.get(name)


def call(path, query=None, form=None, raw=None, json_body=None, method=None):
    url = BASE.rstrip('/') + '/' + path
    if query:
        url += '?' + urllib.parse.urlencode(query)
    data, headers = None, {}
    if form is not None:
        data = urllib.parse.urlencode(form).encode()
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    elif json_body is not None:
        data = json.dumps(json_body).encode()
        headers['Content-Type'] = 'application/json'
    elif raw is not None:
        data = raw
        headers['Content-Type'] = 'application/octet-stream'
    req = urllib.request.Request(url, data=data, headers=headers, method=method or ('POST' if data is not None else 'GET'))
    try:
        r = urllib.request.urlopen(req, timeout=60)
        return Response(r.status, r.headers, r.read())
    except urllib.error.HTTPError as e:
        return Response(e.code, e.headers, e.read())


def sql(statement):
    env = dict(os.environ, MYSQL_PWD=os.environ.get('MYSQL_ROOT_PASSWORD', ''))
    out = subprocess.run(['mysql', '-h', os.environ['MYSQL_HOST'], '-uroot', '-N', '-B', os.environ['MYSQL_DATABASE'], '-e', statement],
                         env=env, check=True, capture_output=True, text=True)
    return out.stdout.strip()


def set_config(param, value):
    sql("INSERT INTO obs_config (config_param, config_value) VALUES ('%s', '%s') "
        "ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)" % (param, value))


def jpeg_size(data):
    i = 2
    while i < len(data) - 9:
        if data[i] != 0xFF:
            i += 1
            continue
        marker = data[i + 1]
        if marker in (0xC0, 0xC1, 0xC2):
            h, w = struct.unpack('>HH', data[i + 5:i + 9])
            return w, h
        if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
            i += 2
            continue
        i += 2 + struct.unpack('>H', data[i + 2:i + 4])[0]
    return None


def issue_tokens(query=None):
    r = call('get_issues.php', query)
    assert r.status == 200, r.text
    return [i['token'] for i in r.json()]


def create(**fields):
    form = {'coordinates_lat': '43.6050', 'coordinates_lon': '3.9050', 'categorie': '2',
            'address': 'Rue du Test, Testville', 'time': '1700000000', 'scope': '99_testville'}
    form.update(fields)
    query = {'key': form.pop('key')} if 'key' in form else None
    return call('create_issue.php', query, form=form)


class T01Configuration(unittest.TestCase):
    def test_version(self):
        r = call('get_version.php')
        self.assertEqual(r.status, 200)
        self.assertRegex(r.json()['version'], r'^\d+\.\d+\.\d+$')
        self.assertEqual(r.header('BACKEND_VERSION'), r.json()['version'])

    def test_cors_on_api(self):
        for path, query in [('get_issues.php', None), ('get_scope.php', {'scope': '99_testville'}),
                            ('acl.php', {'key': ADMIN}), ('get_photo.php', {'token': 'TOKA0001'})]:
            self.assertEqual(call(path, query).header('Access-Control-Allow-Origin'), '*', path)

    def test_add_image_preflight(self):
        r = call('add_image.php', method='OPTIONS')
        self.assertEqual(r.status, 200)
        self.assertEqual(r.header('Access-Control-Allow-Origin'), '*')
        self.assertEqual(r.header('Access-Control-Allow-Headers'), '*')

    def test_scope(self):
        r = call('get_scope.php', {'scope': '99_testville'})
        self.assertEqual(r.status, 200)
        scope = r.json()
        for key in ['display_name', 'department', 'coordinate_lat_min', 'coordinate_lat_max', 'coordinate_lon_min',
                    'coordinate_lon_max', 'map_center_string', 'map_zoom', 'contact_email', 'tweet_content', 'twitter',
                    'map_url', 'nominatim_urlbase', 'backend_version', 'cities']:
            self.assertIn(key, scope)
        self.assertEqual(scope['display_name'], 'Testville')
        self.assertEqual([c['name'] for c in scope['cities']], ['Saint-Exemple', 'Testville'], 'cities sorted by name')

    def test_scope_errors(self):
        self.assertEqual(call('get_scope.php', {'scope': 'nope'}).json()['error']['code'], 'SCOPENOTEXIST')
        self.assertEqual(call('get_scope.php', {'scope': 'nope'}).status, 404)
        self.assertEqual(call('get_scope.php').status, 400)

    def test_acl(self):
        self.assertEqual(call('acl.php', {'key': ADMIN}).json(), {'role': 'admin'})
        self.assertEqual(call('acl.php', {'key': MODO}).json(), {'role': 'moderator'})
        self.assertEqual(call('acl.php', {'key': 'NOPE'}).json(), {'role': False})
        self.assertEqual(call('acl.php').status, 400)


class T02IssuesList(unittest.TestCase):
    def test_public_list(self):
        tokens = issue_tokens()
        self.assertIn('TOKA0001', tokens)
        self.assertIn('TOKA0002', tokens, 'pending observations listed when vigilo_shownonapproved=1')
        self.assertNotIn('TOKA0004', tokens, 'disapproved observations never public')
        self.assertNotIn('TOKA0005', tokens, 'observations without photo not listed')

    def test_sorted_by_time_desc(self):
        times = [int(i['time']) for i in call('get_issues.php').json()]
        self.assertEqual(times, sorted(times, reverse=True))

    def test_fields(self):
        issue = call('get_issues.php', {'token': 'TOKA0001'}).json()[0]
        for key in ['token', 'coordinates_lat', 'coordinates_lon', 'address', 'comment', 'explanation', 'time',
                    'status', 'group', 'categorie', 'approved', 'cityname']:
            self.assertIn(key, issue)
        self.assertEqual(issue['cityname'], 'Testville')
        self.assertEqual(issue['address'], 'Rue de la Gare, Testville')

    def test_hide_pending_when_moderation_required(self):
        set_config('vigilo_shownonapproved', '0')
        try:
            self.assertNotIn('TOKA0002', issue_tokens())
            self.assertEqual(issue_tokens({'approved': '0'}), [], 'pending list empty for the public')
            self.assertIn('TOKA0002', issue_tokens({'approved': '0', 'key': MODO}), 'moderators see pending observations')
        finally:
            set_config('vigilo_shownonapproved', '1')

    def test_hide_nonapproved_text(self):
        # When enabled, the public no longer sees the text of non-approved observations
        set_config('vigilo_shownonapproved_notext', '1')
        try:
            pending = call('get_issues.php', {'token': 'TOKA0002'}).json()[0]
            self.assertEqual((pending['comment'], pending['explanation']), ('', ''), 'text hidden for the public')
            approved = call('get_issues.php', {'token': 'TOKA0001'}).json()[0]
            self.assertEqual(approved['comment'], 'Voiture "garée", trottoir é', 'approved text untouched')
            seen = call('get_issues.php', {'token': 'TOKA0002', 'key': MODO}).json()[0]
            self.assertEqual(seen['comment'], 'En attente', 'moderators still see the text')
        finally:
            set_config('vigilo_shownonapproved_notext', '0')

    def test_filters(self):
        self.assertEqual(issue_tokens({'scope': '98_autre'}), ['TOKA0006'])
        self.assertNotIn('TOKA0006', issue_tokens({'scope': '99_testville'}))
        self.assertTrue(all(i['categorie'] == '2' for i in call('get_issues.php', {'c': '2'}).json()))
        self.assertEqual(len(issue_tokens({'count': '2'})), 2)
        self.assertEqual(issue_tokens({'status': '1'}), ['TOKA0007'])
        self.assertEqual(issue_tokens({'cityid': '1'}), ['TOKA0008', 'TOKA0007', 'TOKA0001'])
        self.assertEqual(issue_tokens({'t': '1655000000'}), ['TOKA0008', 'TOKA0007'])
        self.assertEqual(issue_tokens({'since': '1', 'since_unit': 'day'}), [])
        self.assertEqual(issue_tokens({'approved': '2', 'key': ADMIN}), ['TOKA0004'])
        self.assertEqual(issue_tokens({'approved': '2'}), [])

    def test_radius(self):
        r = call('get_issues.php', {'lat': '43.6', 'lon': '3.9', 'radius': '100'}).json()
        self.assertEqual(sorted(i['token'] for i in r), ['TOKA0001', 'TOKA0003'])
        self.assertTrue(all(i['distance'] <= 100 for i in r))

    def test_similar_observations(self):
        tokens = issue_tokens({'token': 'TOKA0001', 'tokenfilters': 'distance,categorie', 'fdistance': '100'})
        self.assertEqual(sorted(tokens), ['TOKA0001', 'TOKA0003'], 'itself and the same category within 100 m')

    def test_cityfield(self):
        issue = call('get_issues.php', {'token': 'TOKA0001', 'cityfield': '1'}).json()[0]
        self.assertEqual(issue['address'], 'Rue de la Gare')

    def test_csv(self):
        r = call('get_issues.php', {'format': 'csv', 'scope': '99_testville'})
        self.assertTrue(r.header('Content-Type').startswith('text/csv'))
        lines = r.text.strip().split('\n')
        self.assertTrue(lines[0].startswith('token,coordinates_lat,coordinates_lon,address'))
        self.assertEqual(len(lines) - 1, len(issue_tokens({'scope': '99_testville'})))

    def test_geojson(self):
        geo = call('get_issues.php', {'format': 'geojson', 'scope': '99_testville'}).json()
        self.assertEqual(geo['type'], 'FeatureCollection')
        self.assertEqual(len(geo['features']), len(issue_tokens({'scope': '99_testville'})))
        first = geo['features'][0]
        self.assertEqual(first['geometry']['type'], 'Point')
        self.assertEqual(len(first['geometry']['coordinates']), 2)

    def test_hide_old_resolved(self):
        set_config('vigilo_resolved_hide_days', '30')
        try:
            self.assertNotIn('TOKA0007', issue_tokens(), 'resolved more than 30 days ago: hidden')
            self.assertIn('TOKA0007', issue_tokens({'key': ADMIN}), 'still visible with an admin key')
            self.assertIn('TOKA0007', issue_tokens({'resolvedhidden': '1'}), 'kept for the statistics with resolvedhidden=1')
        finally:
            set_config('vigilo_resolved_hide_days', '0')
        self.assertIn('TOKA0007', issue_tokens())


class T03Images(unittest.TestCase):
    def test_photo_rules(self):
        self.assertEqual(call('get_photo.php', {'token': 'TOKA0001'}).status, 200)
        self.assertEqual(call('get_photo.php', {'token': 'TOKA0002'}).status, 403, 'pending photo not public')
        self.assertEqual(call('get_photo.php', {'token': 'TOKA0002', 'key': MODO}).status, 200)
        self.assertEqual(call('get_photo.php', {'token': 'NOPE0000'}).status, 404)
        self.assertEqual(call('get_photo.php').status, 400)
        self.assertEqual(call('get_photo.php', {'token': 'R_RES00001', 'type': 'resolution'}).status, 200)

    def test_panel_is_the_photo(self):
        r = call('generate_panel.php', {'token': 'TOKA0001'})
        self.assertEqual(r.status, 200)
        self.assertEqual(r.header('Content-Type'), 'image/jpeg')
        self.assertEqual(jpeg_size(r.body), jpeg_size(PHOTO))

    def test_panel_width(self):
        for width in ['300', '50', '1024']:
            r = call('generate_panel.php', {'token': 'TOKA0001', 's': width})
            w, h = jpeg_size(r.body)
            self.assertEqual(w, min(int(width), jpeg_size(PHOTO)[0]))

    def test_panel_pixelated_until_approved(self):
        public = call('generate_panel.php', {'token': 'TOKA0002', 's': '400'}).body
        author = call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'secretid': 'SECRET0002'}).body
        moderator = call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'key': MODO}).body
        self.assertNotEqual(public, author, 'pending photo pixelated for the public')
        self.assertEqual(author, moderator)
        wrong = call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'secretid': 'WRONG'}).body
        self.assertEqual(wrong, public, 'wrong secretid: pixelated')
        approved_public = call('generate_panel.php', {'token': 'TOKA0001', 's': '400'}).body
        approved_author = call('generate_panel.php', {'token': 'TOKA0001', 's': '400', 'secretid': 'SECRET0001'}).body
        self.assertEqual(approved_public, approved_author, 'approved photo identical for everyone')

    def test_panel_substitute_mode(self):
        # Seed mode is pixelated (legacy '1'): capture the pixelated public render and the real one
        pixelated = call('generate_panel.php', {'token': 'TOKA0002', 's': '400'}).body
        author = call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'secretid': 'SECRET0002'}).body
        set_config('vigilo_shownonapproved', 'substitute')
        try:
            r = call('generate_panel.php', {'token': 'TOKA0002', 's': '400'})
            substitute = r.body
            self.assertEqual((r.status, r.header('Content-Type')), (200, 'image/jpeg'))
            self.assertNotEqual(substitute, pixelated, 'substitute differs from the pixelated photo')
            self.assertNotEqual(substitute, author, 'substitute is not the real photo')
            # Generic image: the same for every non-approved observation (TOKA0004 is also not approved).
            # Use a width no other test touches so a leftover cache file can't skew the comparison.
            self.assertEqual(call('generate_panel.php', {'token': 'TOKA0002', 's': '377'}).body,
                             call('generate_panel.php', {'token': 'TOKA0004', 's': '377'}).body)
            # Author and moderators still get the real photo
            self.assertEqual(call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'secretid': 'SECRET0002'}).body, author)
            self.assertEqual(call('generate_panel.php', {'token': 'TOKA0002', 's': '400', 'key': MODO}).body, author)
            # Approved photo unaffected by the mode
            self.assertEqual(call('generate_panel.php', {'token': 'TOKA0001', 's': '400'}).body,
                             call('generate_panel.php', {'token': 'TOKA0001', 's': '400', 'secretid': 'SECRET0001'}).body)
        finally:
            set_config('vigilo_shownonapproved', '1')

    def test_panel_errors(self):
        self.assertEqual(call('generate_panel.php').status, 400)
        r = call('generate_panel.php', {'token': 'NOPE0000'})
        self.assertEqual(r.status, 404)
        self.assertEqual(r.json()['error']['code'], 'TOKENNOTFOUND')

    def test_observation_without_photo(self):
        # TOKA0005 exists but has no photo: 404 PHOTONOTFOUND (the applications show a default image)
        for path in ['get_photo.php', 'generate_panel.php']:
            r = call(path, {'token': 'TOKA0005', 'key': ADMIN})
            self.assertEqual(r.status, 404, path)
            self.assertEqual(r.json()['error']['code'], 'PHOTONOTFOUND', path)

    def test_mosaic_removed(self):
        # 0.0.26: the web app shows the similar observations itself
        self.assertEqual(call('mosaic.php').status, 404)


class T04ObservationWorkflow(unittest.TestCase):
    """Creation, photo, moderation, publication, update and deletion of an observation."""

    def test_workflow(self):
        r = create(comment='Vélo sur la piste', explanation='Tous les jours', time='1700000000000', version='42')
        self.assertEqual(r.status, 200, r.text)
        created = r.json()
        self.assertRegex(created['token'], r'^[0-9A-F]{8}$')
        self.assertTrue(created['secretid'])
        self.assertEqual(created['status'], 0)
        token, secret = created['token'], created['secretid']

        self.assertEqual(issue_tokens({'token': token}), [], 'not listed before its photo')

        r = call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertEqual(r.status, 200, r.text)
        issue = call('get_issues.php', {'token': token}).json()[0]
        self.assertEqual(issue['approved'], '0')
        self.assertEqual(issue['time'], '1700000000', 'time sent in ms stored in seconds')
        self.assertEqual(issue['comment'], 'Vélo sur la piste')
        self.assertEqual(issue['cityname'], 'Testville', 'city found from the address')
        self.assertEqual(call('get_photo.php', {'token': token}).status, 403)

        self.assertEqual(call('approve.php', {'token': token}).status, 403, 'approval needs a key')
        r = call('approve.php', {'token': token, 'key': MODO})
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(r.json(), {'status': '0'})
        self.assertEqual(call('get_issues.php', {'token': token}).json()[0]['approved'], '1')
        self.assertEqual(call('get_photo.php', {'token': token}).status, 200)

        r = call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertEqual(r.status, 403, 'approved photo can not be replaced by its author')
        self.assertEqual(r.json()['error']['code'], 'ALREADYAPPROVED')
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret, 'key': ADMIN}, raw=PHOTO).status, 200)

        r = create(token=token, key=ADMIN, comment='Corrigé', categorie='4', coordinates_lat='43.6051')
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(r.json()['token'], token, 'update keeps the token')
        issue = call('get_issues.php', {'token': token}).json()[0]
        self.assertEqual((issue['comment'], issue['categorie'], issue['coordinates_lat']), ('Corrigé', '4', '43.6051'))

        self.assertEqual(call('approve.php', {'token': token, 'key': ADMIN, 'approved': '2'}).status, 200)
        self.assertNotIn(token, issue_tokens(), 'disapproved: no longer public')

        r = call('delete.php', {'token': token, 'secretid': 'WRONG'})
        self.assertEqual(r.status, 400)
        r = call('delete.php', {'token': token, 'secretid': secret})
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(r.json(), {'status': 0})
        self.assertEqual(issue_tokens({'token': token, 'key': ADMIN}), [])
        self.assertEqual(call('get_photo.php', {'token': token, 'key': ADMIN}).status, 404)

    def test_delete_with_admin_key(self):
        r = create()
        token = r.json()['token']
        self.assertEqual(call('delete.php', {'token': token, 'key': ADMIN}).status, 200)
        self.assertEqual(call('approve.php', {'token': token, 'key': ADMIN}).status, 400, 'deleted')

    def test_create_validation(self):
        r = call('create_issue.php', form={'scope': '99_testville'})
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'PARAMNOTDEFINED'))
        r = create(scope='nope')
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'UNKNOWSCOPE'))
        r = create(coordinates_lat='10.0', coordinates_lon='10.0')
        self.assertEqual((r.status, r.json()['error']['code']), (403, 'COORDINATESNOTALLOWED'))
        r = create(address='')
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'PARAMEMPTY'))

    def test_comment_truncated_and_escaped(self):
        comment = 'a' * 49 + "'" + ' ; DROP TABLE obs_list -- ' + 'b' * 20
        r = create(comment=comment)
        self.assertEqual(r.status, 200, r.text)
        token, secret = r.json()['token'], r.json()['secretid']
        call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertEqual(call('get_issues.php', {'token': token}).json()[0]['comment'], comment[:50])

    def test_cityname_and_city_lookup(self):
        r = create(address='Rue Basse', cityname='Saint-Exemple')
        token, secret = r.json()['token'], r.json()['secretid']
        call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertIn(token, issue_tokens({'cityid': '2'}), 'city name matched to the city id')

    def test_address_cleaned_at_creation(self):
        # Postcode, country, spaces and "St" for "Saint": the city is recognized, the address tidied, without house number
        r = create(address='12 ,  rue du Port , 99100 St Exemple, France')
        token, secret = r.json()['token'], r.json()['secretid']
        call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertIn(token, issue_tokens({'cityid': '2'}), 'city recognized despite postcode, country and "St"')
        issue = [i for i in call('get_issues.php').json() if i['token'] == token][0]
        self.assertEqual(issue['address'], 'Rue du Port, Saint-Exemple', 'no house number before the street, type of street in full')


class T05Upload(unittest.TestCase):
    def new(self):
        r = create()
        return r.json()['token'], r.json()['secretid']

    def test_base64_form(self):
        token, secret = self.new()
        r = call('add_image.php', {'token': token, 'secretid': secret, 'method': 'base64'},
                 form={'imagebin64': base64.b64encode(PHOTO).decode()})
        self.assertEqual(r.status, 200, r.text)
        self.assertIn(token, issue_tokens())

    def test_base64_json(self):
        token, secret = self.new()
        r = call('add_image.php', {'token': token, 'secretid': secret, 'method': 'base64'},
                 json_body={'imagebin64': 'data:image/jpeg;base64,' + base64.b64encode(PHOTO).decode()})
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(call('get_photo.php', {'token': token, 'key': ADMIN}).status, 200)

    def test_rejected_uploads(self):
        token, secret = self.new()
        r = call('add_image.php', {'token': token, 'secretid': 'WRONG'}, raw=PHOTO)
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'TOKENNOTEXIST'))
        r = call('add_image.php', raw=PHOTO)
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'MISSINGARGUMENT'))
        r = call('add_image.php', {'token': token, 'secretid': secret}, raw=b'GIF89a' + b'\0' * 200)
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'FILETYPENOTSUPPORTED'))
        self.assertNotIn(token, issue_tokens(), 'a refused upload does not complete the observation')


class T06Resolutions(unittest.TestCase):
    def test_resolution_workflow(self):
        r = call('create_resolution.php', form={'tokenlist': 'TOKA0003,NOPE0000', 'time': '1700000300000', 'comment': 'Réparé ?'})
        self.assertEqual(r.status, 200, r.text)
        res = r.json()
        self.assertRegex(res['token'], r'^R_[0-9A-F]{8}$')
        r = call('add_image.php', {'token': res['token'], 'secretid': res['secretid'], 'type': 'resolution'}, raw=PHOTO)
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(call('get_photo.php', {'token': res['token'], 'type': 'resolution'}).status, 200)
        statuses = {i['token']: i['status'] for i in call('get_issues.php').json()}
        self.assertEqual(statuses['TOKA0003'], 4, 'linked observation waits for the resolution to be validated')
        linked = sql("SELECT COUNT(*) FROM obs_resolutions_tokens t JOIN obs_resolutions r ON r.resolution_id = t.restok_resolutionid "
                     "WHERE r.resolution_token = '%s'" % res['token'])
        self.assertEqual(linked, '1', 'unknown tokens are not linked')

    def test_observation_in_several_resolutions(self):
        # TOKA0007 is resolved (resolution R_RES00001, status 1); a new resolution from the application (status 4)
        # must neither duplicate it in get_issues.php nor hide its resolved status
        r = call('create_resolution.php', form={'tokenlist': 'TOKA0007', 'time': '1700000400000', 'comment': 'Encore ?'})
        self.assertEqual(r.status, 200, r.text)
        try:
            issues = [i for i in call('get_issues.php').json() if i['token'] == 'TOKA0007']
            self.assertEqual([i['status'] for i in issues], [1], 'one row, most advanced status')
            self.assertEqual([i['token'] for i in call('get_issues.php', {'status': '1'}).json()].count('TOKA0007'), 1)
            self.assertNotIn('TOKA0007', [i['token'] for i in call('get_issues.php', {'status': '4'}).json()])
        finally:
            sql("DELETE t FROM obs_resolutions_tokens t JOIN obs_resolutions r ON r.resolution_id = t.restok_resolutionid "
                "WHERE r.resolution_token = '%s'" % r.json()['token'])
            sql("DELETE FROM obs_resolutions WHERE resolution_token = '%s'" % r.json()['token'])

    def test_resolution_validation(self):
        r = call('create_resolution.php', form={'comment': 'x'})
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'PARAMNOTDEFINED'))


class T07Security(unittest.TestCase):
    def test_issues_sql_injection(self):
        start = time.time()
        r = call('get_issues.php', {'token': "' OR SLEEP(3) -- ", 'c': "2') OR SLEEP(3) -- "})
        self.assertEqual(r.status, 200)
        self.assertLess(time.time() - start, 2.5)
        self.assertEqual(r.json(), [])

    def test_rate_limit(self):
        set_config('vigilo_ratelimit_create', '3')
        sql("DELETE FROM obs_rate_limit")
        try:
            codes = [create().status for _ in range(3)]
            self.assertEqual(codes, [200, 200, 200])
            r = create()
            self.assertEqual((r.status, r.json()['error']['code']), (429, 'RATELIMITED'))
            self.assertEqual(r.header('Retry-After'), '600')
            self.assertEqual(create(key=ADMIN).status, 200, 'not limited with an admin key')
            codes = [call('create_resolution.php', form={'tokenlist': 'TOKA0001', 'time': '1700000000'}).status for _ in range(4)]
            self.assertEqual(codes, [200, 200, 200, 429], 'resolutions limited too (own counter)')
        finally:
            set_config('vigilo_ratelimit_create', '0')
            sql("DELETE FROM obs_rate_limit")
        self.assertEqual(create().status, 200, 'no limit when set to 0')

    def test_private_dirs_not_listed(self):
        # The PHP built-in server does not read .htaccess: only checked under Apache
        if 'Apache' not in (call('get_version.php').header('Server') or ''):
            self.skipTest('not served by Apache')
        for path in ['images/TOKA0002.jpg', 'caches/', 'migrations/init-0.0.22.sql']:
            self.assertIn(call(path).status, (403, 404), path)


def png(width, height):
    """A grey PNG image (standard library only)."""
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    rows = b''.join(b'\0' + b'\x80' * (width * 3) for _ in range(height))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b''))


class StubBlurServer:
    """Blur server answering what the test asks (self.mode), recording the photos it gets."""

    def __init__(self):
        stub = self
        self.mode = 'ok'
        self.received = []

        class Handler(BaseHTTPRequestHandler):
            def do_POST(self):
                body = self.rfile.read(int(self.headers['Content-Length']))
                message = email.parser.BytesParser(policy=email.policy.HTTP).parsebytes(
                    b'Content-Type: ' + self.headers['Content-Type'].encode() + b'\r\n\r\n' + body)
                parts = {p.get_param('name', header='content-disposition'): p.get_payload(decode=True) for p in message.iter_parts()}
                stub.received.append((self.path, parts))
                if stub.mode == 'ok':
                    self.reply(200, SCENE, 'image/jpeg')
                elif stub.mode == 'png':
                    self.reply(200, png(320, 240), 'image/png')
                elif stub.mode == 'garbage':
                    self.reply(200, b'<html>not an image</html>', 'text/html')
                else:
                    self.reply(500, b'{"error": "boom"}', 'application/json')

            def reply(self, code, body, content_type):
                self.send_response(code)
                self.send_header('Content-Type', content_type)
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        self.httpd = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.url = 'http://127.0.0.1:%d/blur' % self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


class T08BlurServer(unittest.TestCase):
    """Optional blur server: photos sent to it and replaced by its answer, kept as sent when it fails
    (the moderators check them before publication)."""

    @classmethod
    def setUpClass(cls):
        cls.stub = StubBlurServer()
        set_config('vigilo_blur_url', cls.stub.url)

    @classmethod
    def tearDownClass(cls):
        set_config('vigilo_blur_url', '')
        cls.stub.close()

    def setUp(self):
        self.stub.mode = 'ok'
        self.stub.received = []

    def new(self):
        r = create()
        return r.json()['token'], r.json()['secretid']

    def photo(self, token, **query):
        query.update({'token': token, 'key': ADMIN})
        return call('get_photo.php', query).body

    def test_photo_replaced_by_the_blurred_one(self):
        token, secret = self.new()
        r = call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(r.header('X-Vigilo-Blur'), 'done')
        self.assertEqual(len(self.stub.received), 1, 'photo sent to the blur server')
        path, parts = self.stub.received[0]
        self.assertEqual(path, '/blur')
        self.assertEqual(jpeg_size(parts['picture']), (800, 600), 'multipart field "picture", the uploaded JPEG')
        self.assertEqual(jpeg_size(self.photo(token)), (1024, 768), 'the blurred photo is stored')
        self.assertIn(token, issue_tokens())

    def test_resolution_photo_blurred(self):
        res = call('create_resolution.php', form={'tokenlist': 'TOKA0004', 'time': '1700000300'}).json()
        r = call('add_image.php', {'token': res['token'], 'secretid': res['secretid'], 'type': 'resolution'}, raw=PHOTO)
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(len(self.stub.received), 1)
        self.assertEqual(jpeg_size(self.photo(res['token'], type='resolution')), (1024, 768))

    def test_other_image_format_stored_as_jpeg(self):
        self.stub.mode = 'png'
        token, secret = self.new()
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        self.assertEqual(jpeg_size(self.photo(token)), (320, 240))

    def test_kept_as_sent_when_the_server_fails(self):
        for mode in ['error', 'garbage', 'down']:
            token, secret = self.new()
            self.stub.mode = mode
            if mode == 'down':
                set_config('vigilo_blur_url', 'http://127.0.0.1:9/blur')
            try:
                r = call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
            finally:
                set_config('vigilo_blur_url', self.stub.url)
            self.assertEqual(r.status, 200, mode + ': ' + r.text)
            self.assertEqual(r.json(), {'status': 0}, mode)
            self.assertEqual(r.header('X-Vigilo-Blur'), 'failed', mode)
            self.assertEqual(jpeg_size(self.photo(token)), (800, 600), mode + ': photo stored as sent')
            self.assertEqual(sql("SELECT obs_complete FROM obs_list WHERE obs_token = '%s'" % token), '1', mode)
            issue = call('get_issues.php', {'token': token, 'key': ADMIN}).json()[0]
            self.assertEqual(issue['approved'], '0', mode + ': waits for the moderators')
            self.assertEqual(call('get_photo.php', {'token': token}).status, 403, mode + ': not public before approval')

    def test_disabled(self):
        set_config('vigilo_blur_url', '')
        try:
            token, secret = self.new()
            self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        finally:
            set_config('vigilo_blur_url', self.stub.url)
        self.assertEqual(self.stub.received, [], 'nothing sent when no blur server is configured')
        self.assertEqual(jpeg_size(self.photo(token)), (800, 600))


class T09RealBlurServer(unittest.TestCase):
    """With the blur server of this repository: the face and the licence plate of the
    uploaded photos are masked, the road sign is not. Needs numpy and opencv, and either
    BLUR_SERVER_URL (e.g. http://127.0.0.1:8000/blur, set in the admin setting) or
    BLUR_FROM_ENVIRONMENT=1 (the instance has VIGILO_BLUR_URL, e.g. docker-compose with
    the "blur" profile)."""

    def test_face_and_plates_masked(self):
        url = os.environ.get('BLUR_SERVER_URL')
        if not url and os.environ.get('BLUR_FROM_ENVIRONMENT') != '1':
            self.skipTest('BLUR_SERVER_URL or BLUR_FROM_ENVIRONMENT not set')
        import cv2
        import numpy

        def decode(data):
            return cv2.imdecode(numpy.frombuffer(data, numpy.uint8), cv2.IMREAD_GRAYSCALE)

        def sharpness(img, x0, y0, x1, y1):
            return cv2.Laplacian(img[y0:y1, x0:x1], cv2.CV_64F).var()

        def upload(photo):
            r = create()
            token, secret = r.json()['token'], r.json()['secretid']
            r = call('add_image.php', {'token': token, 'secretid': secret}, raw=photo)
            self.assertEqual(r.status, 200, r.text)
            after = decode(call('get_photo.php', {'token': token, 'key': ADMIN}).body)
            before = decode(photo)
            self.assertEqual(after.shape, before.shape)
            return before, after

        set_config('vigilo_blur_url', url or '')
        try:
            street = upload(SCENE)
            car = upload(CAR)
        finally:
            set_config('vigilo_blur_url', '')
        for name, (before, after), area in [('face', street, (885, 250, 935, 315)), ('plate', car, (428, 480, 590, 512))]:
            self.assertLess(sharpness(after, *area), sharpness(before, *area) * 0.1, name + ' masked')
        before, after = street
        sign = (315, 228, 375, 288)
        self.assertGreater(sharpness(after, *sign), sharpness(before, *sign) * 0.5, 'road sign kept')


class WebhookReceiver:
    """HTTP endpoint recording the calls it gets; answers self.code."""

    def __init__(self):
        receiver = self
        self.calls = []
        self.code = 200

        class Handler(BaseHTTPRequestHandler):
            def handle_call(self):
                length = int(self.headers.get('Content-Length') or 0)
                receiver.calls.append({'method': self.command, 'path': self.path, 'headers': dict(self.headers),
                                       'body': self.rfile.read(length).decode('utf-8')})
                self.send_response(receiver.code)
                self.send_header('Content-Length', '2')
                self.end_headers()
                self.wfile.write(b'ok')

            do_POST = do_PUT = do_PATCH = do_GET = handle_call

            def log_message(self, *args):
                pass

        self.httpd = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.base = 'http://127.0.0.1:%d' % self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def wait(self, count, timeout=10):
        end = time.time() + timeout
        while len(self.calls) < count and time.time() < end:
            time.sleep(0.05)
        return self.calls

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


def add_webhook(name, url, body='', headers='', fmt='json', method='POST', enabled=1, category_map='{}', category_only=0, events='observation.approved',
                categories='', cities=''):
    def q(v):
        return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"
    sql("INSERT INTO obs_webhooks (webhook_name, webhook_enabled, webhook_event, webhook_method, webhook_url, webhook_format, webhook_headers, webhook_body, "
        "webhook_category_map, webhook_category_only, webhook_categories, webhook_cities) VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)"
        % (q(name), enabled, q(events), q(method), q(url), q(fmt), q(headers), q(body), q(category_map), category_only, q(categories), q(cities)))
    return sql("SELECT MAX(webhook_id) FROM obs_webhooks")


class T10Webhooks(unittest.TestCase):
    """Webhooks called when a moderator publishes an observation, with the templates of the admin."""

    @classmethod
    def setUpClass(cls):
        cls.receiver = WebhookReceiver()
        sql("DELETE FROM obs_webhooks")
        sql("DELETE FROM obs_webhook_deliveries")
        add_webhook('json', cls.receiver.base + '/hook/{{token}}?city={{cityname}}',
                    body='{"token": "{{token}}", "comment": "{{comment}}", "lat": {{lat}}, "photo": "{{photo_url}}", "unknown": "{{nope}}", "date": "{{date}}"}',
                    headers='Authorization: Bearer secret-{{scope}}\nX-Comment: {{comment}}')
        add_webhook('form', cls.receiver.base + '/form', body='token={{token}}&comment={{comment}}', fmt='form', method='PUT')
        add_webhook('disabled', cls.receiver.base + '/disabled', body='{}', enabled=0)

    @classmethod
    def tearDownClass(cls):
        sql("DELETE FROM obs_webhooks")
        cls.receiver.close()

    def setUp(self):
        self.receiver.calls = []
        self.receiver.code = 200

    def observation(self, comment):
        r = create(comment=comment, address='Rue du Test, Testville')
        token, secret = r.json()['token'], r.json()['secretid']
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        return token

    def test_called_on_publication(self):
        token = self.observation('Voiture "garée"\nsur la piste & le trottoir')
        r = call('approve.php', {'token': token, 'key': MODO})
        self.assertEqual((r.status, r.json()), (200, {'status': '0'}), 'answer of approve.php unchanged')
        calls = {c['path'].split('?')[0].split('/')[1]: c for c in self.receiver.wait(2)}
        self.assertEqual(sorted(calls), ['form', 'hook'], 'the enabled webhooks only')

        hook = calls['hook']
        self.assertEqual(hook['method'], 'POST')
        self.assertEqual(hook['path'], '/hook/%s?city=Testville' % token)
        self.assertEqual(hook['headers']['Authorization'], 'Bearer secret-99_testville')
        # http.server reads the headers as latin-1, they are sent in UTF-8
        self.assertEqual(hook['headers']['X-Comment'].encode('latin-1').decode('utf-8'), 'Voiture "garée" sur la piste & le trottoir', 'no line break in a header')
        self.assertEqual(hook['headers']['Content-Type'], 'application/json')
        body = json.loads(hook['body'])
        self.assertEqual(body['token'], token)
        self.assertEqual(body['comment'], 'Voiture "garée"\nsur la piste & le trottoir', 'JSON-escaped')
        self.assertEqual(body['lat'], 43.605)
        self.assertRegex(body['photo'], r'/generate_panel\.php\?token=' + token + r'&v=\d-\d+$')
        self.assertEqual(body['unknown'], '')
        self.assertRegex(body['date'], r'^\d{4}-\d\d-\d\dT')

        form = calls['form']
        self.assertEqual(form['method'], 'PUT')
        self.assertEqual(form['headers']['Content-Type'], 'application/x-www-form-urlencoded')
        self.assertEqual(urllib.parse.parse_qs(form['body']), {'token': [token], 'comment': ['Voiture "garée"\nsur la piste & le trottoir']})

        self.assertEqual(sql("SELECT COUNT(*) FROM obs_webhook_deliveries WHERE delivery_token = '%s' AND delivery_http_code = 200 AND delivery_error = ''" % token), '2', 'deliveries logged')

    def test_only_when_published(self):
        token = self.observation('Pas publiée')
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '2'}).status, 200)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '0'}).status, 200)
        time.sleep(1)
        self.assertEqual(self.receiver.calls, [], 'refused or back to moderation: no call')
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        self.assertEqual(len(self.receiver.wait(2)), 2)
        self.receiver.calls = []
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        time.sleep(1)
        self.assertEqual(self.receiver.calls, [], 'already published: no second call')

    def test_failing_endpoint_does_not_block(self):
        token = self.observation('Endpoint en erreur')
        self.receiver.code = 500
        r = call('approve.php', {'token': token, 'key': MODO})
        self.assertEqual(r.status, 200)
        self.assertEqual(call('get_issues.php', {'token': token}).json()[0]['approved'], '1')
        self.receiver.wait(2)
        time.sleep(0.5)
        self.assertEqual(sql("SELECT COUNT(*) FROM obs_webhook_deliveries WHERE delivery_token = '%s' AND delivery_error = 'HTTP 500'" % token), '2')

    def test_unreachable_endpoint_does_not_delay_the_answer(self):
        hook = add_webhook('down', 'http://10.255.255.1/hook', body='{}')
        try:
            token = self.observation('Endpoint injoignable')
            start = time.time()
            self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
            self.assertLess(time.time() - start, 2, 'answer sent before the webhooks')
        finally:
            time.sleep(4)
            sql("DELETE FROM obs_webhooks WHERE webhook_id = %s" % hook)


class T13WebhookEvents(unittest.TestCase):
    """Other events of the workflow (#198): new observation, refused observation, new resolution,
    change of status of a resolution (admin). Since 0.0.32 the admin saves one event per webhook; a webhook
    of an older version subscribed to several events is still delivered and flagged in the admin."""

    BODY = ('{"event": "{{event}}", "token": "{{token}}", "approved": "{{approved}}", "photo": "{{photo_url}}", "photo_full": "{{photo_full_url}}", "resolution": "{{resolution_token}}", '
            '"status": "{{resolution_status}}", "status_name": "{{resolution_status_name}}", "previous": "{{resolution_previous_status}}", '
            '"observations": "{{resolution_observations}}", "address": "{{address}}", "label": "{{event_label}}", "description": "{{event_description}}"}')

    @classmethod
    def setUpClass(cls):
        cls.receiver = WebhookReceiver()
        sql("DELETE FROM obs_webhooks")
        add_webhook('all', cls.receiver.base + '/all', body=cls.BODY,
                    events='observation.created,observation.approved,observation.disapproved,resolution.created,resolution.status_changed')
        add_webhook('created', cls.receiver.base + '/created', body='{"token": "{{token}}"}', events='observation.created')

    @classmethod
    def tearDownClass(cls):
        sql("DELETE FROM obs_webhooks")
        cls.receiver.close()

    def setUp(self):
        self.receiver.calls = []

    def bodies(self, count, path='/all'):
        calls = [c for c in self.receiver.wait(count) if c['path'] == path]
        return [json.loads(c['body']) for c in calls]

    def observation(self):
        r = create(comment='Événements', address='Rue des Événements, Testville')
        token, secret = r.json()['token'], r.json()['secretid']
        r = call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO)
        self.assertEqual((r.status, r.json()), (200, {'status': 0}), 'answer of add_image.php unchanged')
        return token, secret

    def test_observation_created_once(self):
        token, secret = self.observation()
        calls = self.receiver.wait(2)
        self.assertEqual(sorted(c['path'] for c in calls), ['/all', '/created'])
        body = [json.loads(c['body']) for c in calls if c['path'] == '/all'][0]
        self.assertEqual((body['event'], body['token'], body['approved'], body['resolution']), ('observation.created', token, '0', ''))
        self.assertEqual(body['label'], 'Nouvelle observation (avant modération)')
        self.assertRegex(body['description'], r'^Nouvelle observation ' + token + r' à modérer : .*Rue des Événements.*\.$')
        # The photo link must be public before moderation (Slack image block downloads it)
        self.assertRegex(body['photo'], r'/generate_panel\.php\?token=' + token + r'&v=0-\d+$')
        photo = call('generate_panel.php', dict(urllib.parse.parse_qsl(body['photo'].split('?', 1)[1])))
        self.assertEqual((photo.status, photo.header('Content-Type')), (200, 'image/jpeg'), 'photo of a new observation reachable (pixelated)')
        # Signed link to the original photo, for the moderators: valid, then refused if altered or expired
        query = dict(urllib.parse.parse_qsl(body['photo_full'].split('?', 1)[1]))
        self.assertEqual(sorted(query), ['exp', 'sig', 'token'])
        self.assertEqual(call('get_photo.php', query).status, 200, 'signed link to the photo before moderation')
        self.assertEqual(call('get_photo.php', {'token': token}).status, 403, 'still not public without the signature')
        self.assertEqual(call('get_photo.php', dict(query, sig='0' * 64)).status, 403, 'wrong signature refused')
        self.assertEqual(call('get_photo.php', dict(query, exp=str(int(query['exp']) + 1))).status, 403, 'changed expiry refused')
        self.assertEqual(call('get_photo.php', dict(query, exp='1000')).status, 403, 'expired link refused')
        self.receiver.calls = []
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        time.sleep(1)
        self.assertEqual(self.receiver.calls, [], 'new photo of the same observation: no second call')

    def test_disapproved_then_approved(self):
        token, _ = self.observation()
        self.receiver.wait(2)
        self.receiver.calls = []
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '2'}).status, 200)
        refused = self.bodies(1)
        self.assertEqual([(b['event'], b['approved']) for b in refused], [('observation.disapproved', '2')])
        self.assertTrue(refused[0]['description'].startswith('Observation ' + token + ' refusée'), refused[0]['description'])
        self.receiver.calls = []
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '2'}).status, 200)
        time.sleep(1)
        self.assertEqual(self.receiver.calls, [], 'already refused: no second call')
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        approved = self.bodies(1)
        self.assertEqual([b['event'] for b in approved], ['observation.approved'])
        # The photo link changes once approved: Slack caches images by URL and would keep the pixelated one
        self.assertRegex(approved[0]['photo'], r'&v=1-\d+$')
        self.assertEqual(approved[0]['label'], 'Observation publiée (approuvée)')
        self.assertTrue(approved[0]['description'].startswith('Observation ' + token + ' publiée'), approved[0]['description'])
        query = dict(urllib.parse.parse_qsl(approved[0]['photo'].split('?', 1)[1]))
        self.assertEqual(call('generate_panel.php', query).status, 200, 'extra parameter ignored by generate_panel.php')

    def test_resolution_created_and_status_changed(self):
        token, _ = self.observation()
        self.receiver.wait(2)
        self.receiver.calls = []
        r = call('create_resolution.php', form={'tokenlist': token, 'time': '1700000300000', 'comment': 'Réparé'})
        self.assertEqual(r.status, 200, r.text)
        self.assertEqual(sorted(r.json()), ['secretid', 'token'], 'answer of create_resolution.php unchanged')
        resolution = r.json()['token']
        body = self.bodies(1)[0]
        self.assertEqual((body['event'], body['resolution'], body['status'], body['observations'], body['token'], body['address']),
                         ('resolution.created', resolution, '4', token, token, 'Rue des Événements'))
        self.assertEqual(body['description'], 'Nouvelle résolution %s (Indiquée résolue (à valider)) pour 1 observation : %s.' % (resolution, token))
        self.assertEqual(sql("SELECT delivery_token FROM obs_webhook_deliveries WHERE delivery_event = 'resolution.created' ORDER BY delivery_id DESC LIMIT 1"),
                         resolution, 'delivery logged with the resolution token')

        # Validation of the resolution in the admin (bulk action)
        self.receiver.calls = []
        resolution_id = sql("SELECT resolution_id FROM obs_resolutions WHERE resolution_token = '%s'" % resolution)
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def admin(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

        def csrf(page):
            return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': csrf(page)})
        page = admin('index.php?page=resolutions&resolved=4')
        page = admin('index.php?page=resolutions&resolved=4', {'csrf_token': csrf(page), 'bulk_action': 'status_1', 'bulk_ids[]': [resolution_id]})
        self.assertIn('<strong>1</strong> résolution(s)', page)
        body = self.bodies(1)[0]
        self.assertEqual((body['event'], body['resolution'], body['status'], body['status_name'], body['previous']),
                         ('resolution.status_changed', resolution, '1', 'Résolue', '4'))
        self.assertEqual(body['description'], 'Résolution %s : Indiquée résolue (à valider) → Résolue (1 observation).' % resolution)

    def test_legacy_multi_event_webhook_flagged(self):
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def admin(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)})
        page = admin('index.php?page=webhooks')
        self.assertEqual(page.count('Plusieurs événements (ancienne version)'), 1, 'only the multi-event webhook is flagged')
        hook = sql("SELECT webhook_id FROM obs_webhooks WHERE webhook_name = 'all'")
        page = admin('index.php?page=webhooks&edit=' + hook)
        self.assertIn('était abonné à plusieurs événements', page)
        self.assertRegex(page, r'type="radio" name="webhook_event" id="event_observation_created" value="observation.created" checked',
                         'first event of the list chosen')
        self.assertEqual(page.count('type="radio" name="webhook_event"'), 6, 'one radio button per event')


class T11Categories(unittest.TestCase):
    """Categories of the instance: national list (vigilo-conf), disabled locally, added locally."""

    def tearDown(self):
        sql("DELETE FROM obs_categories")

    def categories(self):
        r = call('get_categories.php')
        self.assertEqual(r.status, 200)
        self.assertEqual(r.header('Access-Control-Allow-Origin'), '*')
        return {c['catid']: c for c in r.json()}

    def test_national_list(self):
        cats = self.categories()
        self.assertEqual(cats[2]['catname'], 'Véhicule ou objet gênant')
        self.assertTrue(cats[50].get('catdisable'), 'disabled nationally')
        self.assertFalse(cats[2].get('catdisable', False))

    def test_disable_and_add(self):
        sql("INSERT INTO obs_categories (cat_id, cat_custom, cat_disabled) VALUES (2, 0, 1)")
        sql("INSERT INTO obs_categories (cat_id, cat_custom, cat_disabled, cat_name, cat_name_en, cat_color, cat_resolvable) "
            "VALUES (1000, 1, 0, 'Trottinette mal garée', 'Badly parked scooter', '#e67e22', 1)")
        cats = self.categories()
        self.assertTrue(cats[2]['catdisable'], 'national category disabled by the instance, still listed')
        self.assertEqual(cats[1000], {'catcolor': '#e67e22', 'catid': 1000, 'catname': 'Trottinette mal garée', 'catresolvable': True,
                                      'catcustom': True, 'catname_en_US': 'Badly parked scooter'})
        r = create(categorie='1000')
        self.assertEqual(r.status, 200, 'observation in a category of the instance')


class T12WebhookCategories(unittest.TestCase):
    """Correspondence of the categories of a webhook: {{categorie_code}}, only the mapped categories."""

    @classmethod
    def setUpClass(cls):
        cls.receiver = WebhookReceiver()
        sql("DELETE FROM obs_webhooks")
        add_webhook('mapped', cls.receiver.base + '/all', body='service_code={{categorie_code}}', fmt='form', category_map='{"2": "VOIRIE-12"}')
        add_webhook('only', cls.receiver.base + '/only', body='{"code": "{{categorie_code}}"}', category_map='{"2": "STAT"}', category_only=1)

    @classmethod
    def tearDownClass(cls):
        sql("DELETE FROM obs_webhooks")
        cls.receiver.close()

    def publish(self, categorie):
        r = create(categorie=categorie)
        token, secret = r.json()['token'], r.json()['secretid']
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        self.receiver.calls = []
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)

    def test_mapped_category(self):
        self.publish('2')
        calls = {c['path']: c for c in self.receiver.wait(2)}
        self.assertEqual(urllib.parse.parse_qs(calls['/all']['body']), {'service_code': ['VOIRIE-12']})
        self.assertEqual(json.loads(calls['/only']['body']), {'code': 'STAT'})

    def test_unmapped_category(self):
        self.publish('5')
        self.receiver.wait(1)
        time.sleep(1)
        paths = [c['path'] for c in self.receiver.calls]
        self.assertEqual(paths, ['/all'], 'not sent to the webhook limited to the mapped categories')
        self.assertEqual(self.receiver.calls[0]['body'], 'service_code=', 'no code: empty value')


class T15WebhookFilters(unittest.TestCase):
    """Filters of a webhook on the categories and the cities (0: no known city); a resolution
    matches when one of its observations does."""

    @classmethod
    def setUpClass(cls):
        cls.receiver = WebhookReceiver()
        sql("DELETE FROM obs_webhooks")
        add_webhook('cat', cls.receiver.base + '/cat', body='{}', categories='2,3')
        add_webhook('city', cls.receiver.base + '/city', body='{}', cities='2')
        add_webhook('nocity', cls.receiver.base + '/nocity', body='{}', cities='0')
        add_webhook('both', cls.receiver.base + '/both', body='{}', categories='2', cities='1')
        add_webhook('res', cls.receiver.base + '/res', body='{}', cities='2', events='resolution.created')

    @classmethod
    def tearDownClass(cls):
        sql("DELETE FROM obs_webhooks")
        cls.receiver.close()

    def publish(self, categorie, city):
        r = create(categorie=categorie)
        token, secret = r.json()['token'], r.json()['secretid']
        sql("UPDATE obs_list SET obs_city = %d WHERE obs_token = '%s'" % (city, token))
        self.assertEqual(call('add_image.php', {'token': token, 'secretid': secret}, raw=PHOTO).status, 200)
        self.receiver.calls = []
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        return token

    def paths(self, count):
        self.receiver.wait(count)
        time.sleep(1)
        return sorted(c['path'] for c in self.receiver.calls)

    def test_category_and_city(self):
        self.publish('2', 1)
        self.assertEqual(self.paths(2), ['/both', '/cat'])

    def test_city(self):
        self.publish('5', 2)
        self.assertEqual(self.paths(1), ['/city'])

    def test_no_known_city(self):
        self.publish('3', 0)
        self.assertEqual(self.paths(2), ['/cat', '/nocity'])

    def test_resolution(self):
        first = self.publish('5', 1)
        self.paths(0)
        second = self.publish('5', 2)
        self.assertEqual(self.paths(1), ['/city'])
        self.receiver.calls = []
        self.assertEqual(call('create_resolution.php', form={'tokenlist': first, 'time': '1700000300000'}).status, 200)
        self.assertEqual(self.paths(0), [], 'no observation of the resolution in the cities of the webhook')
        self.assertEqual(call('create_resolution.php', form={'tokenlist': first + ',' + second, 'time': '1700000300000'}).status, 200)
        self.assertEqual(self.paths(1), ['/res'], 'one observation of the resolution in the cities of the webhook')


class T14Archives(unittest.TestCase):
    """Archived observations: left out of get_issues.php, kept with archived=1 (statistics) and by token."""

    def setUp(self):
        r = create(comment='A archiver')
        self.token = r.json()['token']
        self.assertEqual(call('add_image.php', {'token': self.token, 'secretid': r.json()['secretid']}, raw=PHOTO).status, 200)
        self.assertEqual(call('approve.php', {'token': self.token, 'key': MODO}).status, 200)
        sql("UPDATE obs_list SET obs_archived=1 WHERE obs_token='%s'" % self.token)

    def tearDown(self):
        sql("DELETE FROM obs_list WHERE obs_token='%s'" % self.token)

    def tokens(self, **query):
        query.setdefault('scope', '99_testville')
        r = call('get_issues.php', query)
        self.assertEqual(r.status, 200)
        return {i['token']: i for i in r.json()}

    def test_not_listed(self):
        self.assertNotIn(self.token, self.tokens())
        self.assertNotIn(self.token, self.tokens(c='2'))
        self.assertNotIn(self.token, self.tokens(key=ADMIN), 'not listed for the moderators either')

    def test_statistics(self):
        issues = self.tokens(archived='1')
        self.assertEqual(issues[self.token]['archived'], 1)
        self.assertTrue(all(i['archived'] in (0, 1) for i in issues.values()))
        self.assertNotIn('archived', next(iter(self.tokens().values())), 'no new field without archived=1')

    def test_link(self):
        self.assertIn(self.token, self.tokens(token=self.token), 'still reachable by its token')
        self.assertNotIn(self.token, self.tokens(token=self.token, tokenfilters='distance', fdistance='100'),
                         'not offered as a similar observation')


class T14Problems(unittest.TestCase):
    """Problems (#327): automatic attachment of an observation when it becomes published."""

    def setUp(self):
        sql("DELETE FROM obs_problems WHERE problem_title LIKE 'T14%'")

    def tearDown(self):
        sql("DELETE FROM obs_problem_observations WHERE po_problemid NOT IN (SELECT problem_id FROM obs_problems WHERE problem_title NOT LIKE 'T14%')")
        sql("DELETE FROM obs_problems WHERE problem_title LIKE 'T14%'")

    def problem(self, title, lat, lon, status='open', categories='2', radius=200):
        sql("INSERT INTO obs_problems (problem_title, problem_description, problem_categories, problem_zone, problem_lat, problem_lon, "
            "problem_radius, problem_polygon, problem_start, problem_status) VALUES ('T14 %s', '', '%s', 'circle', %s, %s, %d, '', 0, '%s')"
            % (title, categories, lat, lon, radius, status))
        return sql("SELECT MAX(problem_id) FROM obs_problems")

    def observation(self, lat, lon, categorie='2', approve_first=False, address='Rue du Test, Testville'):
        r = create(coordinates_lat=str(lat), coordinates_lon=str(lon), categorie=categorie, address=address)
        self.assertEqual(r.status, 200, r.text)
        token, secret = r.json()['token'], r.json()['secretid']
        if approve_first:
            self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        # Approved observation: its photo needs a moderator key (ALREADYAPPROVED otherwise)
        query = {'token': token, 'secretid': secret, 'key': ADMIN} if approve_first else {'token': token, 'secretid': secret}
        self.assertEqual(call('add_image.php', query, raw=PHOTO).status, 200)
        return token

    def streets_problem(self, title, streets):
        sql("INSERT INTO obs_problems (problem_title, problem_description, problem_categories, problem_zone, problem_polygon, "
            "problem_streets, problem_start, problem_status) VALUES ('T14 %s', '', '2', 'streets', '', '%s', 0, 'open')"
            % (title, json.dumps(streets, ensure_ascii=False).replace("'", "''")))
        return sql("SELECT MAX(problem_id) FROM obs_problems")

    def link(self, token):
        return sql("SELECT CONCAT(po_problemid, ':', po_mode) FROM obs_problem_observations INNER JOIN obs_list ON obs_id = po_obsid "
                   "WHERE obs_token = '%s'" % token)

    def test_attached_when_published(self):
        problem = self.problem('gare', 43.55, 3.85)
        self.problem('fermé', 43.55, 3.85, status='closed')
        token = self.observation(43.5501, 3.8501)
        self.assertEqual(self.link(token), '', 'not attached before the publication')
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        self.assertEqual(self.link(token), problem + ':auto', 'attached to the only active problem')
        self.assertIn('"source":"api"', sql("SELECT audit_details FROM obs_audit_log WHERE audit_action = 'problem_attach_auto' "
                                            "AND audit_details LIKE '%%%s%%'" % token))
        # Published again later: unchanged
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '0'}).status, 200)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        self.assertEqual(self.link(token), problem + ':auto')

    def test_not_attached_outside_the_criteria(self):
        self.problem('catégorie', 43.56, 3.86)
        for token in [self.observation(43.5601, 3.8601, categorie='3'), self.observation(43.57, 3.87)]:
            self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
            self.assertEqual(self.link(token), '', 'other category or outside the zone')
        token = self.observation(43.5601, 3.8601)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO, 'approved': '2'}).status, 200)
        self.assertEqual(self.link(token), '', 'refused: not attached')

    def test_several_problems_left_to_arbitrate(self):
        self.problem('un', 43.58, 3.88)
        self.problem('deux', 43.5801, 3.8801, categories='2,3')
        token = self.observation(43.5801, 3.8801)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        self.assertEqual(self.link(token), '', 'several problems: not attached')
        self.assertEqual(sql("SELECT COUNT(*) FROM obs_audit_log WHERE audit_action = 'problem_attach_ambiguous' AND audit_target = '%s'" % token), '1')

    def test_approved_before_its_photo(self):
        problem = self.problem('photo', 43.59, 3.89)
        token = self.observation(43.5901, 3.8901, approve_first=True)
        self.assertEqual(self.link(token), problem + ':auto', 'attached when the photo makes it published')

    def test_zone_of_streets(self):
        problem = self.streets_problem('rues', [{'street': 'Rue Émile Zola', 'city': 'Testville'}, {'street': 'Avenue T14', 'city': ''}])
        tokens = {
            'street': self.observation(43.611, 3.911, address='12 rue emile-zola, Testville'),
            'any_city': self.observation(43.612, 3.912, address='Avenue T14, Ailleurs'),
            'other_city': self.observation(43.613, 3.913, address='Rue Émile Zola, Ailleurs'),
            'other_street': self.observation(43.614, 3.914, address='Rue Victor Hugo, Testville'),
        }
        for token in tokens.values():
            self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        self.assertEqual(self.link(tokens['street']), problem + ':auto', 'same street, without case, accents or number')
        self.assertEqual(self.link(tokens['any_city']), problem + ':auto', 'street without city: any city')
        self.assertEqual(self.link(tokens['other_city']), '', 'other city')
        self.assertEqual(self.link(tokens['other_street']), '', 'other street')

    def test_resolved_problem_resolves_its_observations(self):
        problem = self.problem('résolu', 43.615, 3.915)
        free, other = self.observation(43.6151, 3.9151), self.observation(43.6152, 3.9152)
        for token in (free, other):
            self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
            self.assertEqual(self.link(token), problem + ':auto')
        # "other" in a resolution taken into account, without date
        sql("INSERT INTO obs_resolutions (resolution_token, resolution_secretid, resolution_app_version, resolution_comment, resolution_time, "
            "resolution_status) VALUES ('R_T14RES', 'X', 1, '', 0, 2)")
        sql("INSERT INTO obs_resolutions_tokens (restok_resolutionid, restok_observationid) SELECT resolution_id, "
            "(SELECT obs_id FROM obs_list WHERE obs_token = '%s') FROM obs_resolutions WHERE resolution_token = 'R_T14RES'" % other)
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def admin(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

        def csrf(page):
            return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': csrf(page)})
        url = 'index.php?page=problems&view=' + problem
        page = admin(url)
        self.assertNotIn('id="resolve"', page, 'nothing proposed while the problem is open')
        page = admin(url, {'csrf_token': csrf(page), 'problem_quick': '1', 'problem_status': 'resolved', 'problem_priority': '2'})
        self.assertIn('Passer 2 observation(s) en résolues', page)
        self.assertIn('R_T14RES', page)
        page = admin(url, {'csrf_token': csrf(page), 'problem_resolve_observations': '1'})
        self.assertNotIn('id="resolve"', page)
        self.assertEqual(sql("SELECT CONCAT(resolution_status, ':', resolution_time > 0, ':', resolution_comment) FROM obs_resolutions "
                             "INNER JOIN obs_resolutions_tokens ON restok_resolutionid = resolution_id INNER JOIN obs_list ON obs_id = restok_observationid "
                             "WHERE obs_token = '%s'" % free), '1:1:Problème résolu : T14 résolu', 'new resolution, resolved and dated')
        self.assertEqual(sql("SELECT CONCAT(resolution_status, ':', resolution_time > 0) FROM obs_resolutions WHERE resolution_token = 'R_T14RES'"), '1:1',
                         'existing resolution resolved and dated')
        self.assertEqual(json.loads(call('get_issues.php', {'token': free}).text)[0]['status'], 1, 'observation resolved in the API')
        self.assertIn('"failed":[]', sql("SELECT audit_details FROM obs_audit_log WHERE audit_action = 'problem_resolve_observations' "
                                         "AND audit_target = 'problem:%s'" % problem))
        sql("DELETE obs_resolutions_tokens, obs_resolutions FROM obs_resolutions_tokens INNER JOIN obs_resolutions ON resolution_id = restok_resolutionid "
            "INNER JOIN obs_list ON obs_id = restok_observationid WHERE obs_token IN ('%s', '%s')" % (free, other))

    def test_broadcast(self):
        problem = self.problem('diffusion', 43.616, 3.916)
        token = self.observation(43.6161, 3.9161)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        receiver = WebhookReceiver()
        try:
            hook = add_webhook('broadcast', receiver.base + '/problem', events='problem.broadcast',
                               body='{"event": "{{event}}", "id": "{{problem_id}}", "title": "{{problem_title}}", "status": "{{problem_status}}", '
                                    '"count": "{{problem_observations}}", "tokens": "{{problem_tokens}}", "token": "{{token}}", "zone": "{{problem_zone}}", '
                                    '"url": "{{problem_url}}", "description": "{{event_description}}"}')
            jar = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

            def admin(path, data=None):
                body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
                return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

            def csrf(page):
                return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
            page = admin('login.php')
            admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': csrf(page)})
            page = admin('index.php?page=problems')
            self.assertIn('name="problem_broadcast" value="%s"' % problem, page)
            page = admin('index.php?page=problems', {'csrf_token': csrf(page), 'problem_broadcast': problem})
            self.assertIn('diffusé (1 webhook(s)) et passé « En attente »', page)
            body = json.loads(receiver.wait(1)[0]['body'])
            self.assertEqual((body['event'], body['id'], body['title'], body['status'], body['count'], body['tokens'], body['token']),
                             ('problem.broadcast', problem, 'T14 diffusion', 'waiting', '1', token, token))
            self.assertEqual(body['zone'], 'Cercle de 200 m')
            self.assertTrue(body['url'].endswith('/admin/index.php?page=problems&view=' + problem))
            self.assertTrue(body['description'].startswith('Problème « T14 diffusion » : 1 observation du '), body['description'])
            self.assertEqual(sql("SELECT problem_status FROM obs_problems WHERE problem_id = " + problem), 'waiting')
            self.assertEqual(sql("SELECT delivery_token FROM obs_webhook_deliveries WHERE delivery_webhookid = " + hook), 'P' + problem)
            self.assertIn('"webhooks":1', sql("SELECT audit_details FROM obs_audit_log WHERE audit_action = 'problem_broadcast' AND audit_target = 'problem:%s'" % problem))
        finally:
            sql("DELETE FROM obs_webhooks WHERE webhook_name = 'broadcast'")
            receiver.close()

    def test_archived_observations_only_when_asked(self):
        problem = self.problem('archives', 43.617, 3.917)
        token = self.observation(43.6171, 3.9171)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        sql("DELETE FROM obs_problem_observations WHERE po_obsid = (SELECT obs_id FROM obs_list WHERE obs_token = '%s')" % token)
        sql("UPDATE obs_list SET obs_archived = 1 WHERE obs_token = '%s'" % token)
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def admin(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

        def csrf(page):
            return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': csrf(page)})
        url = 'index.php?page=problems&view=' + problem
        page = admin(url)
        page = admin(url, {'csrf_token': csrf(page), 'problem_count': '1'})
        self.assertIn('<strong>0</strong> observation(s) publiée(s)', page, 'archived observation not counted by default')
        page = admin(url, {'csrf_token': csrf(page), 'problem_attach_retro': '1'})
        self.assertEqual(self.link(token), '', 'archived observation not attached by default')
        page = admin(url, {'csrf_token': csrf(page), 'problem_count': '1', 'include_archived': '1'})
        self.assertIn('<strong>1</strong> observation(s) publiée(s)', page)
        self.assertIn('id="include_archived" checked', page)
        page = admin(url, {'csrf_token': csrf(page), 'problem_attach_retro': '1', 'include_archived': '1'})
        self.assertEqual(self.link(token), problem + ':retro', 'archived observation attached when asked')
        sql("UPDATE obs_list SET obs_archived = 0 WHERE obs_token = '%s'" % token)

    def test_resolved_observations_only_when_asked(self):
        problem = self.problem('résolues', 43.619, 3.919)
        token = self.observation(43.6191, 3.9191)
        self.assertEqual(call('approve.php', {'token': token, 'key': MODO}).status, 200)
        sql("DELETE FROM obs_problem_observations WHERE po_obsid = (SELECT obs_id FROM obs_list WHERE obs_token = '%s')" % token)
        # Observation in a resolution "Résolue"
        sql("INSERT INTO obs_resolutions (resolution_token, resolution_secretid, resolution_app_version, resolution_comment, resolution_time, "
            "resolution_status) VALUES ('R_T14DONE', 'X', 1, '', 1600000000, 1)")
        sql("INSERT INTO obs_resolutions_tokens (restok_resolutionid, restok_observationid) SELECT resolution_id, "
            "(SELECT obs_id FROM obs_list WHERE obs_token = '%s') FROM obs_resolutions WHERE resolution_token = 'R_T14DONE'" % token)
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def admin(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()

        def csrf(page):
            return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': csrf(page)})
        url = 'index.php?page=problems&view=' + problem
        page = admin(url)
        page = admin(url, {'csrf_token': csrf(page), 'problem_count': '1'})
        self.assertIn('<strong>0</strong> observation(s) publiée(s)', page, 'resolved observation not counted by default')
        page = admin(url, {'csrf_token': csrf(page), 'problem_attach_retro': '1'})
        self.assertEqual(self.link(token), '', 'resolved observation not attached by default')
        # New problem created with the filters of a suggestion (hidden fields of the form): counted with them
        page = admin('index.php?page=problems&edit=new&suggestion=1&title=T14&catid=2&lat=43.619&lon=3.919&radius=200&start=2000-01-01&resolved=1')
        self.assertIn('<input type="hidden" name="include_resolved" value="1">', page)
        page = admin('index.php?page=problems&status=all', {'csrf_token': csrf(page), 'problem_save': '1', 'problem_id': '0', 'problem_title': 'T14 filtres',
                                                            'problem_description': '', 'problem_categories[]': '2', 'problem_zone': 'circle',
                                                            'problem_lat': '43.619', 'problem_lon': '3.919', 'problem_radius': '200', 'problem_polygon': '',
                                                            'problem_start': '2000-01-01', 'problem_end': '', 'problem_priority': '2', 'problem_status': 'open',
                                                            'include_resolved': '1'})
        self.assertIn('id="created-include_resolved" checked', page)
        self.assertRegex(page, r'<strong>1</strong> à rattacher : [^;]*' + token)
        created = re.search(r'id="attachModal"[\s\S]*?action="\?page=problems&amp;view=(\d+)#attach"', page).group(1)
        sql("DELETE FROM obs_problems WHERE problem_id = " + created)
        page = admin(url, {'csrf_token': csrf(page), 'problem_count': '1', 'include_resolved': '1'})
        self.assertIn('<strong>1</strong> observation(s) publiée(s)', page)
        self.assertIn('id="include_resolved" checked', page)
        page = admin(url, {'csrf_token': csrf(page), 'problem_attach_retro': '1', 'include_resolved': '1'})
        self.assertEqual(self.link(token), problem + ':retro', 'resolved observation attached when asked')
        # Once attached, it stays counted as attached to this problem
        page = admin(url, {'csrf_token': csrf(page), 'problem_count': '1'})
        self.assertIn('<strong>1</strong> déjà rattachée(s) à ce problème', page)
        # Suggestions: resolved observations left out unless asked
        page = admin('index.php?page=problems', {'csrf_token': csrf(page), 'problem_suggest': '1', 'suggest_resolved': '1'})
        self.assertIn('résolues comprises', page)
        self.assertIn('<option value="1" selected>Oui</option>', page)
        page = admin('index.php?page=problems', {'csrf_token': csrf(page), 'problem_suggest': '1'})
        self.assertIn('sans les observations résolues', page)
        sql("DELETE obs_resolutions_tokens FROM obs_resolutions_tokens INNER JOIN obs_resolutions ON resolution_id = restok_resolutionid "
            "WHERE resolution_token = 'R_T14DONE'")
        sql("DELETE FROM obs_resolutions WHERE resolution_token = 'R_T14DONE'")


class T15PasswordLinks(unittest.TestCase):
    """Link to set the password of an account (page Comptes, admin/password.php)."""

    def opener(self):
        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        def request(path, data=None):
            body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
            return opener.open(urllib.request.Request(BASE + '/admin/' + path, data=body), timeout=30).read().decode()
        return request

    @staticmethod
    def csrf(page):
        return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)

    def tearDown(self):
        sql("DELETE FROM obs_roles WHERE role_login LIKE 't15%'")

    def test_link_flow(self):
        admin = self.opener()
        page = admin('login.php')
        admin('login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': self.csrf(page)})
        page = admin('index.php?page=accounts')
        form = {'csrf_token': self.csrf(page), 'role_id': '0', 'role_city_present': '1', 'role_name': 'citystaff', 'role_owner': 'T15',
                'role_login': 't15staff', 'role_password': '', 'role_key': ''}
        page = admin('index.php?page=accounts', form)
        self.assertIn('Un mot de passe est obligatoire', page, 'login without password nor link refused')
        page = admin('index.php?page=accounts', dict(form, role_name='moderator', password_link_new='1'))
        self.assertIn('ne sert qu&#039;aux comptes admin et citystaff', page.replace("'", '&#039;'), 'no link for a moderator')
        page = admin('index.php?page=accounts', dict(form, password_link_new='1'))
        self.assertIn('ajouté', page)
        links = re.findall(r'/admin/password\.php\?token=([a-f0-9]{64})', page)
        self.assertEqual(len(links), 1, 'link shown once after the creation')
        token = links[0]
        self.assertEqual(sql("SELECT role_password FROM obs_roles WHERE role_login = 't15staff'"), '', 'no password yet')
        self.assertEqual(sql("SELECT COUNT(*) FROM obs_password_links WHERE link_hash = SHA2('%s', 256)" % token), '1', 'only the hash is stored')
        self.assertEqual(sql("SELECT COUNT(*) FROM obs_password_links WHERE link_hash = '%s'" % token), '0')
        self.assertNotIn(token, sql("SELECT GROUP_CONCAT(audit_details) FROM obs_audit_log WHERE audit_action = 'account_password_link'"), 'token not logged')

        person = self.opener()
        page = person('password.php?token=' + token)
        self.assertIn('value="t15staff"', page)
        for password, confirm, error in [('court', 'court', 'au moins'), ('un-long-mot-de-passe', 'autre-long-mot-de-passe', 'différents')]:
            page = person('password.php?token=' + token, {'csrf_token': self.csrf(page), 'password': password, 'password_confirm': confirm})
            self.assertIn(error, page)
        page = person('password.php?token=' + token, {'password': 'un-long-mot-de-passe', 'password_confirm': 'un-long-mot-de-passe'})
        self.assertIn('Session expirée', page, 'CSRF token required')
        page = person('password.php?token=' + token, {'csrf_token': self.csrf(page), 'password': 'un-long-mot-de-passe', 'password_confirm': 'un-long-mot-de-passe'})
        self.assertIn('Mot de passe enregistré', page)
        page = person('password.php?token=' + token)
        self.assertIn('pas valide', page, 'link used once')
        page = person('login.php')
        page = person('login.php', {'login': 't15staff', 'password': 'un-long-mot-de-passe', 'csrf_token': self.csrf(page)})
        self.assertNotIn('incorrect', page)
        self.assertIn('logout.php', page, 'signed in with the chosen password')
        self.assertIn('account_password_set', sql("SELECT GROUP_CONCAT(audit_action) FROM obs_audit_log WHERE audit_target = "
                                                  "(SELECT CONCAT('role:', role_id) FROM obs_roles WHERE role_login = 't15staff')"))

        # A new link replaces the previous one; an expired link is refused
        role = sql("SELECT role_id FROM obs_roles WHERE role_login = 't15staff'")
        page = admin('index.php?page=accounts')
        self.assertIn('id="role%slinkform"' % role, page)
        first = re.findall(r'token=([a-f0-9]{64})', admin('index.php?page=accounts', {'csrf_token': self.csrf(page), 'role_id': role, 'password_link': '1'}))[0]
        page = admin('index.php?page=accounts')
        second = re.findall(r'token=([a-f0-9]{64})', admin('index.php?page=accounts', {'csrf_token': self.csrf(page), 'role_id': role, 'password_link': '1'}))[0]
        self.assertIn('pas valide', person('password.php?token=' + first), 'previous link invalidated')
        self.assertIn('Nouveau mot de passe', person('password.php?token=' + second))
        sql("UPDATE obs_password_links SET link_expires = %d WHERE link_roleid = %s" % (int(time.time()) - 1, role))
        self.assertIn('pas valide', person('password.php?token=' + second), 'expired link refused')
        self.assertIn('pas valide', person('password.php?token=nope'))


class T16Banner(unittest.TestCase):
    # Announcements stored in obs_banners, served by get_banner.php for the web app
    def tearDown(self):
        sql("DELETE FROM obs_banners")

    def add(self, message='Coucou', type='info', display='banner', scopes='', start=0, end=0, enabled=1, persistent=0, title=''):
        out = sql("INSERT INTO obs_banners (banner_title, banner_message, banner_type, banner_display, banner_scopes, "
                  "banner_start, banner_end, banner_enabled, banner_persistent, banner_created) VALUES "
                  "('%s', '%s', '%s', '%s', '%s', %d, %d, %d, %d, %d); SELECT LAST_INSERT_ID()"
                  % (title, message, type, display, scopes, start, end, enabled, persistent, int(time.time())))
        return int(out.splitlines()[-1])

    def banners(self, scope=None):
        r = call('get_banner.php', {'scope': scope} if scope else None)
        self.assertEqual((r.status, r.header('Content-Type')), (200, 'application/json; charset=utf-8'))
        return r.json()['banners']

    def banner(self, scope=None):
        # the most recent active banner (first of the list), or None
        lst = self.banners(scope)
        return lst[0] if lst else None

    def test_none_without_banner(self):
        self.assertIsNone(self.banner(), 'no banner when the table is empty')
        self.add(message='Caché', enabled=0)
        self.assertIsNone(self.banner(), 'a disabled banner is not shown')

    def test_fields(self):
        self.add(message='Travaux en cours', type='warning', display='both')
        b = self.banner()
        self.assertEqual((b['message'], b['type'], b['display']), ('Travaux en cours', 'warning', 'both'))
        self.assertTrue(b['id'])
        self.assertEqual(b['persistent'], 0, 'non-persistent by default')
        self.assertEqual(b['title'], '', 'no title by default')

    def test_title(self):
        b = self.add(message='Message', title='Nouveautés')
        self.assertEqual(self.banner()['title'], 'Nouveautés', 'the title is served to the web app')
        first = self.banner()['id']
        sql("UPDATE obs_banners SET banner_title='Autre titre' WHERE banner_id=%d" % b)
        self.assertNotEqual(self.banner()['id'], first, 'editing the title changes the id')

    def test_persistent(self):
        b = self.add(message='Toujours visible')
        first = self.banner()['id']
        self.assertEqual(self.banner()['persistent'], 0, 'default is non-persistent')
        sql("UPDATE obs_banners SET banner_persistent=1 WHERE banner_id=%d" % b)
        self.assertEqual(self.banner()['persistent'], 1, 'persistent flag served to the web app')
        self.assertNotEqual(self.banner()['id'], first, 'toggling persistent changes the id')

    def test_unknown_type_and_display_fall_back(self):
        self.add(message='Coucou', type='bogus', display='bogus')
        b = self.banner()
        self.assertEqual(b['type'], 'info', 'unknown type falls back to the first one')
        self.assertEqual(b['display'], 'banner', 'unknown display falls back to the bar')

    def test_date_window(self):
        now = int(time.time())
        b = self.add(message='Fenetre', start=now - 86400, end=now + 86400)
        self.assertIsNotNone(self.banner(), 'active within the window')
        sql("UPDATE obs_banners SET banner_start=%d, banner_end=0 WHERE banner_id=%d" % (now + 86400, b))
        self.assertIsNone(self.banner(), 'not shown before its start')
        sql("UPDATE obs_banners SET banner_start=0, banner_end=%d WHERE banner_id=%d" % (now - 86400, b))
        self.assertIsNone(self.banner(), 'not shown after its end')

    def test_most_recently_started_wins(self):
        now = int(time.time())
        self.add(message='Ancienne', start=now - 7200)
        self.add(message='Récente', start=now - 60)
        self.assertEqual(self.banner()['message'], 'Récente', 'the most recently started active banner wins')

    def test_scope_targeting(self):
        # scope ids in the seed: 1 = 99_testville, 2 = 98_autre
        self.add(message='Pour Autre', scopes='2')
        self.assertIsNone(self.banner(scope='99_testville'), 'a banner targeting another scope is not shown')
        self.assertIsNone(self.banner(), 'scope-targeted banner not shown without a scope')
        self.assertEqual(self.banner(scope='98_autre')['message'], 'Pour Autre', 'shown for the targeted scope')
        sql("UPDATE obs_banners SET banner_scopes='' WHERE 1")
        self.assertEqual(self.banner(scope='99_testville')['message'], 'Pour Autre', 'an all-scopes banner is shown everywhere')

    def test_id_changes_with_content(self):
        b = self.add(message='Message A')
        first = self.banner()['id']
        sql("UPDATE obs_banners SET banner_message='Message B' WHERE banner_id=%d" % b)
        self.assertNotEqual(self.banner()['id'], first, 'editing the message changes the id')

    def test_several_active_at_once(self):
        # a bar and a window from two different banners show at the same time
        self.add(message='Bandeau', display='banner', start=int(time.time()) - 60)
        self.add(message='Fenetre', display='modal', start=int(time.time()) - 30)
        lst = self.banners()
        self.assertEqual(len(lst), 2, 'both active banners are returned')
        messages = [b['message'] for b in lst]
        self.assertIn('Bandeau', messages)
        self.assertIn('Fenetre', messages)
        self.assertEqual(lst[0]['message'], 'Fenetre', 'most recently started banner is first')


class T17ApiStats(unittest.TestCase):
    # Visit statistics: common.php counts one hit per public API route, aggregated by day (obs_api_stats)
    def count(self, route):
        out = sql("SELECT COALESCE(SUM(stat_count), 0) FROM obs_api_stats WHERE stat_route='%s'" % route)
        return int(out.splitlines()[-1])

    def test_call_is_counted(self):
        before = self.count('get_version.php')
        call('get_version.php')
        self.assertEqual(self.count('get_version.php'), before + 1, 'a public API call increments its daily counter')

    def test_image_routes_not_counted(self):
        # image-serving routes are excluded (one call per asset would drown the visit signal)
        before = self.count('get_photo.php')
        call('get_photo.php', {'token': 'ZZZZZZZZ'})
        self.assertEqual(self.count('get_photo.php'), before, 'get_photo.php is not counted')


def main():
    global BASE
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', required=True)
    args, rest = parser.parse_known_args()
    BASE = args.base
    # Anti-spam off by default: the tests create many observations from one IP
    set_config('vigilo_ratelimit_create', '0')
    program = unittest.main(argv=[sys.argv[0], '-v'] + rest, exit=False)
    return 0 if program.result.wasSuccessful() else 1


if __name__ == '__main__':
    sys.exit(main())
