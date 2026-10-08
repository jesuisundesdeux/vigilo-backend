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
import json
import os
import struct
import subprocess
import sys
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = None
ADMIN = 'ADMINKEY0123456789'
MODO = 'MODKEY0123456789'

with open(os.path.join(HERE, 'fixtures', 'photo.jpg'), 'rb') as f:
    PHOTO = f.read()


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

    def test_panel_errors(self):
        self.assertEqual(call('generate_panel.php').status, 400)
        r = call('generate_panel.php', {'token': 'NOPE0000'})
        self.assertEqual(r.status, 404)
        self.assertEqual(r.json()['error']['code'], 'TOKENNOTFOUND')

    def test_mosaic(self):
        r = call('mosaic.php')
        self.assertEqual(r.status, 200)
        self.assertIn('TOKA0001', r.text)
        self.assertNotIn('TOKA0004', r.text)
        self.assertNotIn('<script src=', r.text, 'no third-party script')


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

    def test_resolution_validation(self):
        r = call('create_resolution.php', form={'comment': 'x'})
        self.assertEqual((r.status, r.json()['error']['code']), (400, 'PARAMNOTDEFINED'))


class T07Security(unittest.TestCase):
    def test_mosaic_sql_injection(self):
        start = time.time()
        r = call('mosaic.php', {'t': "' OR SLEEP(3) -- "})
        self.assertEqual(r.status, 200)
        self.assertLess(time.time() - start, 2.5, 'token not injected in SQL')

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
