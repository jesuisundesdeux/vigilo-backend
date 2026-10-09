#!/usr/bin/env python3
"""
API contract tests: every public endpoint used by the web app and the mobile apps is
called with a fixed set of requests, on a database loaded with tests/api/seed.sql.

  record: python3 tests/api/contract.py --base http://127.0.0.1:8080 --record
  check:  python3 tests/api/contract.py --base http://127.0.0.1:8080

The snapshots (tests/api/snapshots.json) were recorded on the last released backend.
A new version must give the same status codes, content types, CORS headers and JSON
bodies. Volatile values (generated tokens, version numbers) are masked. Deliberate
changes are listed, with their reason, in tests/api/expected_changes.json.

Standard library only.
"""

import argparse
import base64
import json
import os
import struct
import sys
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
SNAPSHOTS = os.path.join(HERE, 'snapshots.json')
EXPECTED_CHANGES = os.path.join(HERE, 'expected_changes.json')
PHOTO = os.path.join(HERE, 'fixtures', 'photo.jpg')

ADMIN = 'ADMINKEY0123456789'
MODO = 'MODKEY0123456789'

with open(PHOTO, 'rb') as f:
    PHOTO_BYTES = f.read()

# name, method, path, query, form body, raw body, masks, save
# - masks: top-level JSON keys whose values are generated (replaced by "<masked>")
# - save: {"var": "json key"} to reuse a value in a later request as {var}
CASES = [
    # Version / configuration
    dict(name='get_version', path='get_version.php'),
    dict(name='acl_admin', path='acl.php', query={'key': ADMIN}),
    dict(name='acl_moderator', path='acl.php', query={'key': MODO}),
    dict(name='acl_unknown', path='acl.php', query={'key': 'NOPE'}),
    dict(name='acl_missing', path='acl.php'),
    dict(name='get_scope', path='get_scope.php', query={'scope': '99_testville'}),
    dict(name='get_scope_other', path='get_scope.php', query={'scope': '98_autre'}),
    dict(name='get_scope_unknown', path='get_scope.php', query={'scope': 'nope'}),
    dict(name='get_scope_missing', path='get_scope.php'),

    # Observations list
    dict(name='issues_all', path='get_issues.php'),
    dict(name='issues_scope', path='get_issues.php', query={'scope': '99_testville'}),
    dict(name='issues_scope_other', path='get_issues.php', query={'scope': '98_autre'}),
    dict(name='issues_count', path='get_issues.php', query={'scope': '99_testville', 'count': '2'}),
    dict(name='issues_categorie', path='get_issues.php', query={'c': '2'}),
    dict(name='issues_categories', path='get_issues.php', query={'c': '2,3'}),
    dict(name='issues_status_resolved', path='get_issues.php', query={'status': '1'}),
    dict(name='issues_status_new', path='get_issues.php', query={'status': '0', 'scope': '99_testville'}),
    dict(name='issues_approved_1', path='get_issues.php', query={'approved': '1'}),
    dict(name='issues_approved_0', path='get_issues.php', query={'approved': '0'}),
    dict(name='issues_approved_2_public', path='get_issues.php', query={'approved': '2'}),
    dict(name='issues_approved_2_admin', path='get_issues.php', query={'approved': '2', 'key': ADMIN}),
    dict(name='issues_token', path='get_issues.php', query={'token': 'TOKA0003'}),
    dict(name='issues_token_filters', path='get_issues.php',
         query={'token': 'TOKA0001', 'tokenfilters': 'distance,categorie', 'fdistance': '100'}),
    dict(name='issues_radius', path='get_issues.php', query={'lat': '43.6', 'lon': '3.9', 'radius': '100'}),
    dict(name='issues_cityid', path='get_issues.php', query={'cityid': '1'}),
    dict(name='issues_cityfield', path='get_issues.php', query={'cityfield': '1', 'scope': '99_testville'}),
    dict(name='issues_time', path='get_issues.php', query={'t': '1625000000'}),
    dict(name='issues_since_all', path='get_issues.php', query={'since': '100', 'since_unit': 'year'}),
    dict(name='issues_since_none', path='get_issues.php', query={'since': '1', 'since_unit': 'day'}),
    dict(name='issues_csv', path='get_issues.php', query={'format': 'csv', 'scope': '99_testville'}),
    dict(name='issues_geojson', path='get_issues.php', query={'format': 'geojson', 'scope': '99_testville'}),

    # Images
    dict(name='panel_approved', path='generate_panel.php', query={'token': 'TOKA0001'}),
    dict(name='panel_approved_small', path='generate_panel.php', query={'token': 'TOKA0001', 's': '300'}),
    dict(name='panel_pending', path='generate_panel.php', query={'token': 'TOKA0002', 's': '800'}),
    dict(name='panel_unknown', path='generate_panel.php', query={'token': 'NOPE0000'}),
    dict(name='panel_missing', path='generate_panel.php'),
    dict(name='photo_approved', path='get_photo.php', query={'token': 'TOKA0001'}),
    dict(name='photo_pending', path='get_photo.php', query={'token': 'TOKA0002'}),
    dict(name='photo_pending_admin', path='get_photo.php', query={'token': 'TOKA0002', 'key': ADMIN}),
    dict(name='photo_unknown', path='get_photo.php', query={'token': 'NOPE0000'}),
    dict(name='photo_missing', path='get_photo.php'),
    dict(name='photo_resolution', path='get_photo.php', query={'token': 'R_RES00001', 'type': 'resolution'}),
    dict(name='mosaic', path='mosaic.php', body_kind='status_only'),

    # Observation workflow
    dict(name='create_missing', method='POST', path='create_issue.php', form={'scope': '99_testville'}),
    dict(name='create_unknown_scope', method='POST', path='create_issue.php',
         form={'coordinates_lat': '43.6', 'coordinates_lon': '3.9', 'categorie': '2',
               'address': 'Rue X', 'time': '1700000000', 'scope': 'nope'}),
    dict(name='create_out_of_scope', method='POST', path='create_issue.php',
         form={'coordinates_lat': '10.0', 'coordinates_lon': '10.0', 'categorie': '2',
               'address': 'Rue X', 'time': '1700000000', 'scope': '99_testville'}),
    dict(name='create_ok', method='POST', path='create_issue.php',
         form={'coordinates_lat': '43.6010', 'coordinates_lon': '3.9010', 'categorie': '2',
               'address': 'Rue Neuve, Testville', 'comment': "Il y a un \"souci\" l'été",
               'explanation': 'Détails', 'time': '1700000000000', 'scope': '99_testville', 'version': '42'},
         masks=['token', 'secretid'], save={'new_token': 'token', 'new_secret': 'secretid'}),
    dict(name='create_ok_cityname', method='POST', path='create_issue.php',
         form={'coordinates_lat': '43.6020', 'coordinates_lon': '3.9020', 'categorie': '3',
               'address': 'Rue Basse', 'cityname': 'Saint-Exemple', 'time': '1700000100',
               'scope': '99_testville'},
         masks=['token', 'secretid'], save={'new_token2': 'token', 'new_secret2': 'secretid'}),
    dict(name='add_image_bad_secret', method='POST', path='add_image.php',
         query={'token': '{new_token}', 'secretid': 'WRONG'}, raw=PHOTO_BYTES, masks_message=True),
    dict(name='add_image_missing', method='POST', path='add_image.php', raw=PHOTO_BYTES),
    dict(name='add_image_not_jpeg', method='POST', path='add_image.php',
         query={'token': '{new_token2}', 'secretid': '{new_secret2}'}, raw=b'GIF89a' + b'\0' * 200),
    dict(name='add_image_raw', method='POST', path='add_image.php',
         query={'token': '{new_token}', 'secretid': '{new_secret}'}, raw=PHOTO_BYTES),
    dict(name='add_image_base64', method='POST', path='add_image.php',
         query={'token': '{new_token2}', 'secretid': '{new_secret2}', 'method': 'base64'},
         form={'imagebin64': base64.b64encode(PHOTO_BYTES).decode()}),
    dict(name='issues_after_create', path='get_issues.php', query={'t': '1699999999'},
         masks_items=['token']),
    dict(name='panel_new', path='generate_panel.php', query={'token': '{new_token}'}),
    dict(name='photo_new_public', path='get_photo.php', query={'token': '{new_token}'}),
    dict(name='photo_new_author_key', path='get_photo.php', query={'token': '{new_token}', 'key': MODO}),
    dict(name='approve_no_key', path='approve.php', query={'token': '{new_token}'}),
    dict(name='approve_unknown', path='approve.php', query={'token': 'NOPE0000', 'key': MODO}),
    dict(name='approve_ok', path='approve.php', query={'token': '{new_token}', 'key': MODO}),
    dict(name='disapprove_ok', path='approve.php', query={'token': '{new_token2}', 'key': ADMIN, 'approved': '2'}),
    dict(name='photo_new_after_approve', path='get_photo.php', query={'token': '{new_token}'}),
    dict(name='create_update_admin', method='POST', path='create_issue.php', query={'key': ADMIN},
         form={'token': '{new_token}', 'coordinates_lat': '43.6011', 'coordinates_lon': '3.9011',
               'categorie': '4', 'address': 'Rue Neuve, Testville', 'comment': 'Corrigé',
               'time': '1700000000', 'scope': '99_testville'},
         masks=['token', 'secretid']),
    dict(name='issues_after_update', path='get_issues.php', query={'t': '1699999999', 'key': ADMIN},
         masks_items=['token']),
    dict(name='delete_bad_secret', path='delete.php', query={'token': 'TOKA0008', 'secretid': 'WRONG'}),
    dict(name='delete_ok', path='delete.php', query={'token': 'TOKA0008', 'secretid': 'SECRET0008'}),
    dict(name='issues_after_delete', path='get_issues.php', query={'token': 'TOKA0008'}),

    # Resolutions
    dict(name='resolution_missing', method='POST', path='create_resolution.php', form={'comment': 'x'}),
    dict(name='resolution_ok', method='POST', path='create_resolution.php',
         form={'tokenlist': 'TOKA0001', 'time': '1700000200', 'comment': 'Résolu ?', 'version': '42'},
         masks=['token', 'secretid'], save={'res_token': 'token', 'res_secret': 'secretid'}),
    dict(name='resolution_image', method='POST', path='add_image.php',
         query={'token': '{res_token}', 'secretid': '{res_secret}', 'type': 'resolution'}, raw=PHOTO_BYTES),
    dict(name='photo_resolution_new', path='get_photo.php', query={'token': '{res_token}', 'type': 'resolution'}),
    dict(name='issues_after_resolution', path='get_issues.php', query={'token': 'TOKA0001'}),
]


def image_info(data):
    if data[:3] == b'\xff\xd8\xff':
        i = 2
        while i < len(data) - 9:
            if data[i] != 0xFF:
                i += 1
                continue
            marker = data[i + 1]
            if marker in (0xC0, 0xC1, 0xC2):
                h, w = struct.unpack('>HH', data[i + 5:i + 9])
                return {'image': 'jpeg', 'width': w, 'height': h}
            if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
                i += 2
                continue
            length = struct.unpack('>H', data[i + 2:i + 4])[0]
            i += 2 + length
        return {'image': 'jpeg'}
    if data[:8] == b'\x89PNG\r\n\x1a\n':
        w, h = struct.unpack('>II', data[16:24])
        return {'image': 'png', 'width': w, 'height': h}
    return None


def mask(value, keys):
    if isinstance(value, dict):
        return {k: ('<masked>' if k in keys else mask(v, keys)) for k, v in value.items()}
    if isinstance(value, list):
        return [mask(v, keys) for v in value]
    return value


def substitute(value, variables):
    if isinstance(value, str):
        for k, v in variables.items():
            value = value.replace('{' + k + '}', v)
    return value


def run_case(base, case, variables):
    query = {k: substitute(v, variables) for k, v in case.get('query', {}).items()}
    url = base.rstrip('/') + '/' + case['path']
    if query:
        url += '?' + urllib.parse.urlencode(query)
    data = None
    headers = {}
    if 'form' in case:
        form = {k: substitute(v, variables) for k, v in case['form'].items()}
        data = urllib.parse.urlencode(form).encode()
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    elif 'raw' in case:
        data = case['raw']
        headers['Content-Type'] = 'application/octet-stream'
    req = urllib.request.Request(url, data=data, headers=headers, method=case.get('method', 'GET'))
    try:
        resp = urllib.request.urlopen(req, timeout=60)
        status, rheaders, body = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        status, rheaders, body = e.code, e.headers, e.read()

    result = {
        'status': status,
        'content_type': (rheaders.get('Content-Type') or '').replace(' ', '').lower(),
        'cors': rheaders.get('Access-Control-Allow-Origin'),
        'backend_version_header': rheaders.get('BACKEND_VERSION') is not None,
    }

    if case.get('body_kind') == 'status_only':
        return result

    info = image_info(body)
    if info:
        result['body'] = info
        return result

    text = body.decode('utf-8', errors='replace')
    try:
        parsed = json.loads(text)
    except ValueError:
        result['body_text'] = text
        return result

    for var, key in case.get('save', {}).items():
        if isinstance(parsed, dict) and key in parsed:
            variables[var] = str(parsed[key])

    masked_keys = set(case.get('masks', [])) | {'backend_version', 'version'}
    if case.get('masks_items'):
        masked_keys |= set(case['masks_items'])
    parsed = mask(parsed, masked_keys)
    if case.get('masks_message') and isinstance(parsed, dict) and 'error' in parsed:
        parsed['error']['message'] = '<masked>'
    result['body'] = parsed
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', required=True, help='URL of the instance under test')
    parser.add_argument('--record', action='store_true', help='write the snapshots instead of checking')
    parser.add_argument('--only', help='run only the cases whose name contains this text')
    args = parser.parse_args()

    variables = {}
    results = {}
    for case in CASES:
        if args.only and args.only not in case['name']:
            continue
        results[case['name']] = run_case(args.base, case, variables)

    if args.record:
        with open(SNAPSHOTS, 'w') as f:
            json.dump(results, f, indent=2, ensure_ascii=False, sort_keys=True)
            f.write('\n')
        print('Recorded %d cases in %s' % (len(results), SNAPSHOTS))
        return 0

    with open(SNAPSHOTS) as f:
        snapshots = json.load(f)
    expected = {}
    if os.path.exists(EXPECTED_CHANGES):
        with open(EXPECTED_CHANGES) as f:
            expected = json.load(f)

    failures = 0
    for name, got in results.items():
        want = snapshots.get(name)
        if name in expected:
            want = dict(want or {})
            want.update(expected[name]['now'])
            # "__removed__": the key does not exist anymore in the response
            want = {k: v for k, v in want.items() if v != '__removed__'}
            # "__any__": the value depends on the web server (e.g. its own 404 page)
            got = {k: v for k, v in got.items() if want.get(k) != '__any__'}
            want = {k: v for k, v in want.items() if v != '__any__'}
        if got != want:
            failures += 1
            print('FAIL %s' % name)
            print('  expected: %s' % json.dumps(want, ensure_ascii=False, sort_keys=True)[:2000])
            print('  got:      %s' % json.dumps(got, ensure_ascii=False, sort_keys=True)[:2000])
        else:
            print('ok   %s' % name)
    print('%d/%d cases match the snapshots' % (len(results) - failures, len(results)))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
