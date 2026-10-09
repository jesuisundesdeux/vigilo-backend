#!/usr/bin/env python3
"""
Smoke test of the admin, on the database of tests/api/seed.sql (accounts admin / staff,
password vigilo-test):

- login, every page of the menu, logout
- actions found in the pages themselves (links carrying the CSRF token, forms)
- CSRF: a change without token is refused
- roles: a citystaff can not open the admin pages
- no PHP warning, notice, deprecation or fatal error in the HTML

  python3 tests/admin/smoke.py --base http://127.0.0.1:8089

Standard library only.
"""

import argparse
import html
import json
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

PHP_ERRORS = re.compile(r'(Fatal error|Parse error|Warning</b>|Notice</b>|Deprecated</b>|Uncaught|Stack trace|<b>Warning|<b>Notice|<b>Deprecated)')

failures = []


def check(condition, message):
    if condition:
        print('ok   ' + message)
    else:
        print('FAIL ' + message)
        failures.append(message)


class Client:
    def __init__(self, base):
        self.base = base.rstrip('/') + '/admin/'
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, data=None):
        url = path if path.startswith('http') else self.base + path
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        try:
            resp = self.opener.open(urllib.request.Request(url, data=body), timeout=60)
            return resp.status, resp.geturl(), resp.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, url, e.read().decode('utf-8', 'replace')

    def token(self, page_html):
        m = re.search(r'name="csrf_token" value="([a-f0-9]+)"', page_html)
        return m.group(1) if m else ''

    def login(self, login, password):
        _, _, page = self.request('login.php')
        return self.request('login.php', {'login': login, 'password': password, 'csrf_token': self.token(page)})


def clean(name, status, page):
    check(status == 200, '%s: HTTP 200 (got %s)' % (name, status))
    m = PHP_ERRORS.search(page)
    check(m is None, '%s: no PHP error in the page%s' % (name, (' (' + page[max(0, m.start() - 80):m.end() + 200] + ')') if m else ''))


def links(page, needle):
    return [html.unescape(h) for h in re.findall(r'href="([^"]*)"', page) if needle in html.unescape(h)]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', required=True)
    args = parser.parse_args()

    # Login failures and success
    admin = Client(args.base)
    status, url, page = admin.login('admin', 'wrong-password')
    check('incorrect' in page, 'wrong password refused')
    status, url, page = admin.login('admin', 'vigilo-test')
    check(url.endswith('index.php'), 'admin login redirects to the admin')
    clean('dashboard', status, page)

    pages = ['dashboard', 'observations', 'resolutions', 'cities', 'accounts', 'scopes', 'categories', 'settings', 'webhooks', 'audit', 'update']
    for name in pages:
        status, _, page = admin.request('index.php?page=' + name)
        clean('page ' + name, status, page)
        check('Accès non autorisé' not in page, 'page %s allowed for admin' % name)

    # Observation tabs and filters
    for query in ['approved=0', 'approved=1', 'approved=2', 'approved=1&pagenb=2', 'filtertype=uniq&filtertoken=TOKA0001',
                  'filtertype=similar&filtertoken=TOKA0001', 'filteraddress=Gare', 'searchcity=1', 'searchcategory=2',
                  'filterpbaddress=1', 'filtercityunknown=1']:
        status, _, page = admin.request('index.php?page=observations&' + query)
        clean('observations ' + query, status, page)
    for query in ['resolved=1', 'resolved=2', 'resolved=3', 'resolved=4']:
        status, _, page = admin.request('index.php?page=resolutions&' + query)
        clean('resolutions ' + query, status, page)

    # Photos in the admin: never pixelated, only with a session
    try:
        resp = admin.opener.open(admin.base + 'photo.php?token=TOKA0002&s=200', timeout=30)
        check(resp.status == 200 and resp.headers.get('Content-Type') == 'image/jpeg', 'admin photo of a pending observation')
    except urllib.error.HTTPError as e:
        check(False, 'admin photo of a pending observation (HTTP %s)' % e.code)
    try:
        urllib.request.urlopen(admin.base + 'photo.php?token=TOKA0002', timeout=30)
        check(False, 'admin photo refused without session')
    except urllib.error.HTTPError as e:
        check(e.code == 403, 'admin photo refused without session')

    # CSRF: an action without token is ignored
    status, _, page = admin.request('index.php?page=observations&approved=0&action=approve&approveto=1&token=TOKA0002&obsid=2')
    check('jeton de sécurité' in page, 'action link without CSRF token is refused')
    status, _, page = admin.request('index.php?page=observations&approved=0')
    check('TOKA0002' in page, 'TOKA0002 still waiting for moderation after the refused action')

    # Approve TOKA0002 with the link displayed in the page (it carries the token)
    approve = [l for l in links(page, 'action=approve') if 'TOKA0002' in l and 'approveto=1' in l and 'twitt' not in l]
    check(len(approve) > 0, 'approve link for TOKA0002 found')
    if approve:
        status, _, page = admin.request('index.php' + approve[0] if approve[0].startswith('?') else approve[0])
        clean('approve TOKA0002', status, page)
        status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0002')
        check('TOKA0002' in page, 'TOKA0002 is approved')

    # Resolution status of an observation (TOKA0007: resolved, TOKA0003: reported resolved, to validate)
    status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0007')
    clean('observation TOKA0007', status, page)
    check('Résolue</span>' in page and 'En résolution' not in page, 'resolved observation shown as resolved')
    status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0003')
    check('Indiquée résolue</span>' in page, 'observation reported resolved shown as such')

    # Moderator note on TOKA0001 (#266)
    status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0001')
    status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0001',
                                    {'csrf_token': admin.token(page), 'note_action': 'add', 'note_obsid': '1', 'note_text': 'Vu avec la mairie <b>test</b>'})
    clean('add note', status, page)
    check('Vu avec la mairie &lt;b&gt;test&lt;/b&gt;' in page, 'note added and escaped')

    # Settings: save a value
    status, _, page = admin.request('index.php?page=settings')
    fields = dict(re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', page))
    fields.update(re.findall(r'name="(cfg\[[a-z_]+\])"[^>]*value="([^"]*)"', page))
    fields.update({'csrf_token': admin.token(page), 'cfg[vigilo_resolved_hide_days]': '30'})
    for name, value in re.findall(r'<select[^>]*name="(cfg\[[a-z_]+\])"[^>]*>.*?<option[^>]*value="([^"]*)"[^>]*selected', page, re.S):
        fields.setdefault(name, value)
    status, _, page = admin.request('index.php?page=settings', fields)
    clean('settings save', status, page)
    check('alert-success' in page, 'settings saved')

    # Webhooks: invalid JSON refused, creation with the test button (endpoint down), deletion
    status, _, page = admin.request('index.php?page=webhooks&edit=new')
    clean('webhook form', status, page)
    check('{{observation_url}}' in page, 'webhook variables listed')
    hook = {'csrf_token': admin.token(page), 'webhook_id': '0', 'webhook_name': 'Test <b>hook</b>', 'webhook_enabled': '1',
            'webhook_method': 'POST', 'webhook_url': 'http://127.0.0.1:9/hook?t={{token}}', 'webhook_format': 'json',
            'webhook_headers': 'Authorization: Bearer x', 'webhook_body': '{"comment": {{comment}}}',
            'webhook_events[]': ['observation.approved', 'resolution.status_changed']}
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_save='1', **{'webhook_events[]': []}))
    check('au moins un événement' in page, 'webhook without event refused')
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_save='1'))
    clean('webhook invalid JSON', status, page)
    check('JSON valide' in page, 'webhook with an invalid JSON body refused')
    hook['webhook_body'] = '{"comment": "{{comment}}", "lat": {{lat}}}'
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_test='1'))
    clean('webhook save and test', status, page)
    check('Test &lt;b&gt;hook&lt;/b&gt;' in page and 'enregistré' in page, 'webhook saved and escaped')
    check("Test de l'événement <code>observation.approved</code>" in page and 'Requête envoyée' in page, 'webhook test reported')
    check('resolution.status_changed</span>' in page, 'events of the webhook listed')
    delete = [l for l in links(page, 'action=delete') if 'webhookid=' in l]
    check(len(delete) == 1, 'webhook delete link')
    if delete:
        status, _, page = admin.request('index.php' + delete[0] if delete[0].startswith('?') else delete[0])
        clean('webhook delete', status, page)
        check('supprimé' in page, 'webhook deleted')

    # Templates of the webhook form: listed, and each one is accepted as is (valid JSON body)
    status, _, page = admin.request('index.php?page=webhooks&edit=new')
    m = re.search(r'<script type="application/json" id="webhook_templates_data">(.*?)</script>', page, re.S)
    templates = json.loads(m.group(1)) if m else {}
    check(set(['empty', 'json', 'mastodon', 'slack', 'discord', 'bluesky', 'open311', 'redmine']) <= set(templates), 'webhook templates available')
    check('Ticketing de collectivité (Open311)' in page, 'webhook template menu')
    for key, template in templates.items():
        if key == 'empty':
            continue
        name = template['name'] or 'Modèle ' + key
        status, _, page = admin.request('index.php?page=webhooks', {
            'csrf_token': admin.token(page), 'webhook_id': '0', 'webhook_name': name, 'webhook_enabled': '1',
            'webhook_method': template['method'], 'webhook_url': template['url'] or 'https://example.org/hook',
            'webhook_format': template['format'], 'webhook_headers': template['headers'], 'webhook_body': template['body'],
            'webhook_save': '1', 'webhook_events[]': ['observation.created', 'observation.approved', 'observation.disapproved',
                                                       'resolution.created', 'resolution.status_changed']})
        check('alert-success' in page and 'enregistré' in page, 'webhook template %s accepted for every event' % key)

    # Scopes: identifier checked when it changes, legacy identifier still editable
    status, _, page = admin.request('index.php?page=scopes')
    clean('scopes map', status, page)
    check('data-scope-map="scope1_"' in page and 'leaflet.js' in page, 'scope map present')
    scope_form = {'csrf_token': admin.token(page), 'scope_id': '1', 'scope_name': '34 test ville', 'scope_display_name': 'Testville'}
    status, _, page = admin.request('index.php?page=scopes', scope_form)
    check('Identifiant invalide' in page, 'invalid scope identifier refused')
    scope_form.update({'scope_name': '99_testville', 'scope_display_name': 'Testville', 'scope_map_zoom': '14',
                       'scope_coordinate_lat_min': '43.5', 'scope_coordinate_lat_max': '43.7'})
    status, _, page = admin.request('index.php?page=scopes', scope_form)
    check('Identifiant invalide' not in page and 'alert-danger' not in page, 'unchanged legacy identifier accepted')
    check('scope_sharing_content_text' not in page, 'no sharing text field (Twitter)')

    # Scopes: creation window, refused values reopen it with what was typed
    new_scope = {'csrf_token': admin.token(page), 'scope_create': '1', 'scope_name': '99 bad', 'scope_display_name': 'Nouveau <b>scope</b>',
                 'scope_department': '34', 'scope_contact_email': 'contact@example.org'}
    status, _, page = admin.request('index.php?page=scopes', new_scope)
    clean('scope create refused', status, page)
    check('Identifiant invalide' in page and 'data-reopen-modal="#scopeModal"' in page, 'invalid new scope refused, window reopened')
    new_scope.update({'csrf_token': admin.token(page), 'scope_name': '34_nouveau'})
    status, _, page = admin.request('index.php?page=scopes', new_scope)
    clean('scope create', status, page)
    check('Scope <strong>34_nouveau</strong> ajouté' in page and 'Nouveau &lt;b&gt;scope&lt;/b&gt;' in page, 'scope created from the window')
    status, _, page = admin.request('index.php?page=scopes', dict(new_scope, csrf_token=admin.token(page)))
    check('déjà utilisé' in page, 'duplicate scope identifier refused')

    # Cities: import of communes (data checked server side), duplicates skipped
    status, _, page = admin.request('index.php?page=cities&import_scope=1')
    clean('cities import', status, page)
    check('data-cities-import' in page, 'cities import form present')
    cities = [{'name': 'Lattes', 'postcode': '34970', 'area': 27.88, 'population': 16000},
              {'name': 'Testville', 'postcode': '34000', 'area': 1, 'population': 1},
              {'name': "<b>X</b>' OR 1=1 -- ", 'postcode': 'abc', 'area': 'x', 'population': -5}]
    status, _, page = admin.request('index.php?page=cities', {'csrf_token': admin.token(page), 'cities_import': '1',
                                                               'import_scope': '1', 'cities_json': json.dumps(cities)})
    clean('cities import result', status, page)
    check('2 villes importées' in page and '1 ignorée' in page, 'communes imported, existing one skipped')
    check('&lt;b&gt;X&lt;/b&gt;' in page and '<b>X</b>' not in page, 'imported names escaped')

    # Cities: creation and edition windows
    check('data-bs-target="#cityModal"' in page and 'action=add' not in page, 'city window, no empty city created')
    city = {'csrf_token': admin.token(page), 'city_id': '0', 'city_name': '', 'city_scope': '1', 'city_postcode': '34170',
            'city_area': '23,5', 'city_population': '1 000', 'city_website': ''}
    status, _, page = admin.request('index.php?page=cities', city)
    check('obligatoire' in page and 'data-reopen-modal="#cityModal"' in page, 'city without name refused, window reopened')
    city.update({'csrf_token': admin.token(page), 'city_name': 'Castelnau-le-Lez'})
    status, _, page = admin.request('index.php?page=cities', city)
    clean('city create', status, page)
    m = re.search(r'Ville <strong>Castelnau-le-Lez</strong> \(#(\d+)\) ajoutée', page)
    check(m is not None and '34170' in page, 'city created from the window')
    if m:
        city.update({'csrf_token': admin.token(page), 'city_id': m.group(1), 'city_population': '22000'})
        status, _, page = admin.request('index.php?page=cities', city)
        check('mise à jour' in page and '22 000' in page, 'city edited from the window')

    # Accounts: creation window
    status, _, page = admin.request('index.php?page=accounts')
    check('data-bs-target="#accountModal"' in page and 'action=add' not in page, 'account window, no empty account created')
    account = {'csrf_token': admin.token(page), 'role_id': '0', 'role_name': 'citystaff', 'role_owner': 'Mairie', 'role_login': 'mairie2',
               'role_password': '', 'role_city_present': '1', 'role_city[]': 'Testville'}
    status, _, page = admin.request('index.php?page=accounts', account)
    check('mot de passe est obligatoire' in page and 'data-reopen-modal="#accountModal"' in page, 'account with login and no password refused')
    account.update({'csrf_token': admin.token(page), 'role_password': 'mot-de-passe-long'})
    status, _, page = admin.request('index.php?page=accounts', account)
    clean('account create', status, page)
    check('(citystaff) ajouté' in page and 'mairie2' in page and 'Testville' in page, 'account created from the window')

    # Categories: national one disabled then enabled again, category of the instance added, edited, deleted
    status, _, page = admin.request('index.php?page=categories')
    check('Véhicule ou objet gênant' in page, 'national categories listed')
    check('vigilo-conf/blob/main/main/categorielist.json' in page and 'pull request' in page, 'suggestion to share a category in vigilo-conf')
    disable = [l for l in links(page, 'action=disable') if 'catid=2&' in l or l.endswith('catid=2')]
    check(len(disable) == 1, 'disable link of a national category')
    if disable:
        status, _, page = admin.request('index.php' + disable[0] if disable[0].startswith('?') else disable[0])
        clean('category disable', status, page)
        check('désactivée pour cette instance' in page, 'national category disabled')
        enable = [l for l in links(page, 'action=enable') if 'catid=2' in l]
        check(len(enable) == 1, 'enable link')
        if enable:
            status, _, page = admin.request('index.php' + enable[0] if enable[0].startswith('?') else enable[0])
            check('réactivée' in page, 'national category enabled again')
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_save': '1', 'cat_id': '0',
                                                                   'cat_name': 'Trottinette <b>gênante</b>', 'cat_color': '#123456', 'cat_active': '1'})
    clean('category add', status, page)
    check('ajoutée (n° 1000)' in page and 'Trottinette &lt;b&gt;gênante&lt;/b&gt;' in page, 'category of the instance added')
    status, _, page = admin.request('index.php?page=webhooks&edit=new')
    check('category_map[1000]' in page and '{{categorie_code}}' in page, 'category correspondence in the webhook form')

    # Bulk actions on observations: category 1000 given to TOKA0007, disapprove / approve again
    def bulk(path, action, ids, **values):
        _, _, page = admin.request(path)
        data = {'csrf_token': admin.token(page), 'bulk_action': action, 'bulk_ids[]': [str(i) for i in ids]}
        data.update(values)
        return admin.request(path, data)
    status, _, page = admin.request('index.php?page=observations&approved=1')
    check('data-bulk' in page and 'name="bulk_ids[]" value="7"' in page, 'bulk selection on the observations')
    status, _, page = bulk('index.php?page=observations&approved=1', 'category', [7], bulk_value_category='1000')
    clean('bulk category', status, page)
    check('Changer la catégorie : <strong>1</strong>' in page, 'bulk category change')
    status, _, page = bulk('index.php?page=observations&approved=1', 'category', [7], bulk_value_category='99999')
    check('Choisir une catégorie active' in page, 'bulk category change to an unknown category refused')
    status, _, page = bulk('index.php?page=observations&approved=1', 'disapprove', [1, 3])
    clean('bulk disapprove', status, page)
    check('Désapprouver : <strong>2</strong>' in page, 'bulk disapprove')
    status, _, page = admin.request('index.php?page=observations&approved=2')
    check('TOKA0001' in page and 'TOKA0003' in page, 'observations disapproved in bulk')
    status, _, page = bulk('index.php?page=observations&approved=2', 'approve', [1, 3])
    check('Approuver : <strong>2</strong>' in page, 'bulk approve')
    status, _, page = bulk('index.php?page=observations&approved=1', 'resolution_new', [2, 3])
    clean('bulk new resolution', status, page)
    check('créée avec 1 observation(s)' in page and 'déjà dans une résolution' in page, 'bulk new resolution (observation already in a resolution skipped)')
    status, _, page = bulk('index.php?page=observations&approved=1', 'nothing', [1])
    check('Action non autorisée' in page, 'unknown bulk action refused')

    # Category of the instance deleted, its observations moved to category 2
    status, _, page = admin.request('index.php?page=categories')
    check('categoryDeleteModal' in page and '&quot;obs_count&quot;:1' in page, 'delete window with the number of observations')
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_delete': '1', 'cat_id': '1000'})
    check('choisir de les déplacer ou de les supprimer' in page, 'used category not deleted without a choice')
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_delete': '1', 'cat_id': '1000',
                                                                   'obs_action': 'move', 'target_catid': '1000'})
    check('Choisir une catégorie active' in page, 'observations not moved to the deleted category')
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_delete': '1', 'cat_id': '1000',
                                                                   'obs_action': 'move', 'target_catid': '2'})
    clean('category delete and move', status, page)
    check('supprimée ; 1 observation(s) déplacée(s) vers' in page, 'category deleted, observations moved')
    status, _, page = admin.request('index.php?page=observations&approved=1&searchcategory=2&filtertype=uniq&filtertoken=TOKA0007')
    check('TOKA0007' in page, 'moved observation in category 2')

    # Category deleted with its observations
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_save': '1', 'cat_id': '0',
                                                                   'cat_name': 'Temporaire', 'cat_color': '#123456', 'cat_active': '1'})
    check('ajoutée (n° 1000)' in page, 'second category of the instance added')
    bulk('index.php?page=observations&approved=1', 'category', [6], bulk_value_category='1000')
    status, _, page = admin.request('index.php?page=categories')
    status, _, page = admin.request('index.php?page=categories', {'csrf_token': admin.token(page), 'category_delete': '1', 'cat_id': '1000',
                                                                   'obs_action': 'delete'})
    clean('category delete with observations', status, page)
    check('1 observation(s) supprimée(s)' in page, 'category deleted with its observations')
    status, _, page = admin.request('index.php?page=observations&approved=1&filtertype=uniq&filtertoken=TOKA0006')
    check('Aucune observation' in page, 'observation of the deleted category deleted')

    # Bulk actions on resolutions: transition refused, then allowed
    status, _, page = admin.request('index.php?page=resolutions&resolved=4')
    check('name="bulk_ids[]" value="2"' in page, 'bulk selection on the resolutions')
    status, _, page = bulk('index.php?page=resolutions&resolved=4', 'status_3', [2])
    clean('bulk resolution status refused', status, page)
    check('<strong>0</strong> résolution(s)' in page and 'non autorisé' in page, 'bulk status change refused for a forbidden transition')
    status, _, page = bulk('index.php?page=resolutions&resolved=4', 'status_1', [2])
    check('<strong>1</strong> résolution(s)' in page, 'bulk status change')
    status, _, page = admin.request('index.php?page=resolutions&resolved=1')
    check('R_RES00002' in page, 'resolution validated in bulk')

    # Audit log shows the actions
    status, _, page = admin.request('index.php?page=audit')
    for action in ['login', 'observation_approve', 'note_add', 'settings_edit', 'webhook_create', 'webhook_delete', 'city_import', 'city_create', 'scope_create', 'account_create', 'category_disable', 'category_create', 'category_delete', 'resolution_status', 'observation_delete']:
        check(action in page, 'audit log contains ' + action)

    # Logout
    status, _, page = admin.request('index.php')
    status, url, page = admin.request('logout.php', {'csrf_token': admin.token(page)})
    check(url.endswith('login.php'), 'logout')
    status, url, page = admin.request('index.php')
    check(url.endswith('login.php'), 'admin closed after logout')

    # Citystaff: restricted pages
    staff = Client(args.base)
    status, url, page = staff.login('staff', 'vigilo-test')
    clean('citystaff dashboard', status, page)
    for name in ['accounts', 'settings', 'update', 'audit', 'cities', 'scopes', 'webhooks', 'categories']:
        status, _, page = staff.request('index.php?page=' + name)
        check('Accès non autorisé' in page, 'citystaff refused on ' + name)
    status, _, page = staff.request('index.php?page=observations&approved=1')
    clean('citystaff observations', status, page)
    check('TOKA0001' in page and 'TOKA0003' not in page, 'citystaff only sees the observations of Testville')
    check('value="resolution_new"' in page and '>Supprimer</option>' not in page, 'citystaff bulk actions limited to the resolutions')
    status, _, page = staff.request('index.php?page=observations&approved=1', {'csrf_token': staff.token(page), 'bulk_action': 'delete', 'bulk_ids[]': ['1']})
    check('Action non autorisée' in page, 'citystaff bulk delete refused')
    status, _, page = staff.request('index.php?page=observations&approved=1', {'csrf_token': staff.token(page), 'bulk_action': 'resolution_new', 'bulk_ids[]': ['3']})
    check('hors de vos villes' in page, 'citystaff bulk action outside their cities refused')

    # Direct access to the page files is refused
    status, _, page = admin.request('inc/settings.php')
    check(status in (403, 404) or 'Not allowed' in page, 'direct access to inc/settings.php refused')

    print('%d failure(s)' % len(failures))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
