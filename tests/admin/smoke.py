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

    pages = ['dashboard', 'observations', 'resolutions', 'problems', 'cities', 'accounts', 'scopes', 'categories', 'settings', 'webhooks', 'audit', 'update']
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
    fields.update({'csrf_token': admin.token(page), 'cfg[vigilo_resolved_hide_days]': '30',
                   'cfg[vigilo_problem_suggest_min]': '2', 'cfg[vigilo_problem_suggest_months]': '120'})
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
            'webhook_event': 'observation.approved',
            'webhook_categories[]': ['2', '99999'], 'webhook_cities[]': ['1', '0', 'x']}
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_save='1', webhook_event=''))
    check('Choisir un événement' in page, 'webhook without event refused')
    check('type="radio" name="webhook_event"' in page and 'name="webhook_events[]"' not in page, 'one event per webhook (radio buttons)')
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_save='1', webhook_event='observation.unknown'))
    check('Choisir un événement' in page, 'unknown event refused')
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_save='1'))
    clean('webhook invalid JSON', status, page)
    check('JSON valide' in page, 'webhook with an invalid JSON body refused')
    hook['webhook_body'] = '{"comment": "{{comment}}", "lat": {{lat}}}'
    status, _, page = admin.request('index.php?page=webhooks', dict(hook, webhook_test='1'))
    clean('webhook save and test', status, page)
    check('Test &lt;b&gt;hook&lt;/b&gt;' in page and 'enregistré' in page, 'webhook saved and escaped')
    check("Test de l'événement <code>observation.approved</code>" in page and 'Requête envoyée' in page, 'webhook test reported')
    check('observation.approved</span>' in page and 'Plusieurs événements' not in page, 'event of the webhook listed')
    check('<i class="bi bi-funnel"></i> Véhicule ou objet gênant</div>' in page and '<i class="bi bi-funnel"></i> Testville, Sans ville référencée</div>' in page,
          'filters of the webhook listed (unknown ids left out)')
    check('non envoyé, hors des filtres' in page, 'webhook test out of the filters reported')
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
        for event in ['observation.created', 'observation.approved', 'observation.disapproved', 'resolution.created', 'resolution.status_changed']:
            status, _, page = admin.request('index.php?page=webhooks', {
                'csrf_token': admin.token(page), 'webhook_id': '0', 'webhook_name': name, 'webhook_enabled': '1',
                'webhook_method': template['method'], 'webhook_url': template['url'] or 'https://example.org/hook',
                'webhook_format': template['format'], 'webhook_headers': template['headers'], 'webhook_body': template['body'],
                'webhook_save': '1', 'webhook_event': event})
            check('alert-success' in page and 'enregistré' in page, 'webhook template %s accepted for %s' % (key, event))

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
    # Website of the association (0.0.28): http(s) address only, then exposed by get_scope.php
    scope_form.update({'csrf_token': admin.token(page), 'scope_association_url': 'javascript:alert(1)'})
    status, _, page = admin.request('index.php?page=scopes', scope_form)
    check('Site de l&#039;association : adresse invalide' in page or "Site de l'association : adresse invalide" in page, 'invalid association url refused')
    scope_form.update({'csrf_token': admin.token(page), 'scope_association_url': 'https://asso.example/velo'})
    status, _, page = admin.request('index.php?page=scopes', scope_form)
    check('alert-danger' not in page and 'value="https://asso.example/velo"' in page, 'association url saved')
    scope = json.loads(urllib.request.urlopen(args.base.rstrip('/') + '/get_scope.php?scope=99_testville', timeout=30).read().decode())
    check(scope.get('association_url') == 'https://asso.example/velo', 'association url in get_scope.php')
    scope_form.update({'csrf_token': admin.token(page), 'scope_association_url': ''})
    status, _, page = admin.request('index.php?page=scopes', scope_form)

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
    # Link to choose the password (details in tests/api/functional.py, T15PasswordLinks)
    check('name="password_link_new"' in page and 'linkform' in page, 'password link option and button')
    m = re.search(r'id="role(\d+)linkform"', page)
    if m:
        status, _, page = admin.request('index.php?page=accounts', {'csrf_token': admin.token(page), 'role_id': m.group(1), 'password_link': '1'})
        clean('account password link', status, page)
        check(re.search(r'password\.php\?token=[a-f0-9]{64}', page) is not None and 'data-copy=' in page, 'password link shown with a copy button')
    status, _, page = admin.request('password.php?token=invalid')
    clean('password page', status, page)
    check('pas valide' in page, 'password page: invalid link')
    status, _, page = admin.request('index.php?page=accounts')
    # Key provided by the person (generated by the web app): used as the key of the account, checked
    provided = 'WEBAPPKEY0123456789ABCDEFGHIJKLMNOPQRSTU'
    moderator = {'role_id': '0', 'role_name': 'moderator', 'role_owner': 'Modo appli', 'role_login': '', 'role_password': '',
                 'role_city_present': '1'}
    status, _, page = admin.request('index.php?page=accounts', dict(moderator, csrf_token=admin.token(page), role_key='court'))
    check('de 20 à 64 lettres ou chiffres' in page, 'invalid provided key refused')
    status, _, page = admin.request('index.php?page=accounts', dict(moderator, csrf_token=admin.token(page), role_key=provided))
    clean('account with a provided key', status, page)
    check('(moderator) ajouté' in page, 'account created with the provided key')
    with urllib.request.urlopen(args.base.rstrip('/') + '/acl.php?key=' + provided, timeout=30) as r:
        check(json.loads(r.read().decode('utf-8')).get('role') == 'moderator', 'provided key recognised by acl.php')
    status, _, page = admin.request('index.php?page=accounts', dict(moderator, csrf_token=admin.token(page), role_key=provided))
    check('déjà utilisée' in page, 'provided key already used refused')

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
    check('name="webhook_categories[]"' in page and 'name="webhook_cities[]"' in page, 'category and city filters in the webhook form')

    # Report for a local authority (page Rapports, report.php): document and slides, filters
    status, _, page = admin.request('index.php?page=reports')
    clean('reports page', status, page)
    check('action="report.php"' in page and 'name="cities[]"' in page and 'name="categories[]"' in page, 'report form')
    check('name="sections[]" value="problem_details"' in page and 'name="sections[]" value="kpis"' in page, 'report form: sections to choose')
    status, _, page = admin.request('report.php?from=2020-01-01&to=2022-12-31&s=1&sections[]=kpis&sections[]=times')
    clean('report chosen sections', status, page)
    check('report-heatmap' in page and 'moins</text>' in page, 'report: matrix days × hours')
    check('Chiffres clés' in page and 'Jours et heures' in page and 'Évolution mensuelle' not in page and 'report-map' not in page
          and 'Méthode' not in page, 'report with the chosen sections only')
    status, _, page = admin.request('report.php?from=2020-01-01&to=2022-12-31&radius=50&format=document')
    clean('report document', status, page)
    check('Chiffres clés' in page and 'Lieux récurrents' in page and 'report-map' in page, 'report document sections')
    check('class="count">2</span>' in page and 'TOKA0001 TOKA0003' in page, 'similar observations grouped (TOKA0001 and TOKA0003, 42 m apart)')
    status, _, page = admin.request('report.php?from=2020-01-01&to=2022-12-31&categories[]=4&format=slides&title=%3Cb%3EBilan%3C%2Fb%3E')
    clean('report slides', status, page)
    check('class="slide title-slide"' in page and '&lt;b&gt;Bilan&lt;/b&gt;' in page, 'report slides, title escaped')
    check('Aucune observation' in page, 'report filtered by category: no published observation of category 4 (TOKA0004 refused)')

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

    # Archives: one observation, a selection, a period (preview then confirm); hidden from get_issues.php
    def api_tokens(query=''):
        with urllib.request.urlopen(args.base.rstrip('/') + '/get_issues.php?scope=99_testville' + query, timeout=30) as r:
            return {i['token']: i for i in json.loads(r.read().decode('utf-8'))}
    def action(query):
        _, _, page = admin.request('index.php?page=observations&approved=1')
        return admin.request('index.php?page=observations&approved=1' + query + '&csrf_token=' + admin.token(page))
    status, _, page = admin.request('index.php?page=observations&approved=1')
    check('token=TOKA0001&amp;obsid=1&amp;action=archive&amp;archiveto=1' in page, 'archive button on each row of the list')
    check(page.index('Archiver par période') > page.index('TOKA0001</code>'), 'archiving by period after the list')
    status, _, page = action('&action=archive&archiveto=1&token=TOKA0001&obsid=1')
    clean('archive observation', status, page)
    check('<strong>TOKA0001</strong> archivée' in page and 'TOKA0001</code>' not in page, 'observation archived, no longer in the default list')
    status, _, page = admin.request('index.php?page=observations&approved=1&searcharchived=1')
    clean('archived observations filter', status, page)
    check('TOKA0001</code>' in page and 'Archivée' in page and 'TOKA0003</code>' not in page, 'archived observations shown by the Archives filter')
    check('TOKA0001' not in api_tokens(), 'archived observation left out of get_issues.php')
    check(api_tokens('&archived=1').get('TOKA0001', {}).get('archived') == 1, 'archived observation in get_issues.php?archived=1')
    check(api_tokens('&archived=1').get('TOKA0003', {}).get('archived') == 0, 'archived field 0 for the others with archived=1')
    check('archived' not in api_tokens()['TOKA0003'], 'no archived field without archived=1')
    check('TOKA0001' in api_tokens('&token=TOKA0001'), 'archived observation still reachable by its token')
    status, _, page = action('&searcharchived=1&action=archive&archiveto=0&token=TOKA0001&obsid=1')
    check('<strong>TOKA0001</strong> désarchivée' in page, 'observation unarchived')
    status, _, page = bulk('index.php?page=observations&approved=1', 'archive', [1, 3])
    clean('bulk archive', status, page)
    check('Archiver : <strong>2</strong>' in page, 'bulk archive')
    status, _, page = bulk('index.php?page=observations&approved=1&searcharchived=1', 'unarchive', [1, 3])
    check('Désarchiver : <strong>2</strong>' in page, 'bulk unarchive')
    def period(step, **values):
        _, _, page = admin.request('index.php?page=observations&approved=1')
        data = {'csrf_token': admin.token(page), 'archive_step': step}
        data.update(values)
        return admin.request('index.php?page=observations&approved=1', data)
    status, _, page = period('preview', archive_from='', archive_to='', archive_category='0')
    check('indiquer une date de fin valide' in page, 'archiving by period without end date refused')
    status, _, page = period('preview', archive_from='2021-01-01', archive_to='2020-01-01', archive_category='0')
    check('date de début est après la date de fin' in page, 'archiving by period with dates inverted refused')
    status, _, page = period('preview', archive_from='', archive_to='2020-12-31', archive_category='3')
    check('aucune observation à archiver' in page, 'archiving by period: nothing in this category')
    status, _, page = period('preview', archive_from='', archive_to='2020-12-31', archive_category='2')
    clean('archive by period preview', status, page)
    check('<strong>1</strong> observation(s) à archiver' in page and 'name="archive_step" value="confirm"' in page, 'archiving by period: preview')
    check('TOKA0001' in api_tokens(), 'nothing archived by the preview')
    status, _, page = period('confirm', archive_from='', archive_to='2020-12-31', archive_category='2')
    check('<strong>1</strong> observation(s) archivée(s)' in page, 'archiving by period: confirmed')
    check('TOKA0001' not in api_tokens(), 'observation of the period archived')
    status, _, page = bulk('index.php?page=observations&approved=1&searcharchived=1', 'unarchive', [1])
    check('Désarchiver : <strong>1</strong>' in page, 'observation of the period unarchived')

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

    # Problems (#327): suggestions (TOKA0001 and TOKA0003, same category, 40 m apart), prefilled creation, dismissal
    status, _, page = admin.request('index.php?page=problems')
    check('Groupes d\'au moins 2 observations' in page and '120 derniers mois' in page, 'suggestion settings shown')
    status, _, page = admin.request('index.php?page=problems', {'csrf_token': admin.token(page), 'problem_suggest': '1'})
    clean('problem suggestions', status, page)
    check('Suggestions (1)' in page and 'TOKA0001, TOKA0003' in page, 'suggestion of TOKA0001 and TOKA0003')
    create = links(page, 'suggestion=1')
    check(len(create) == 1, 'create link of the suggestion')
    if create:
        status, _, form = admin.request('index.php' + create[0] if create[0].startswith('?') else create[0])
        clean('problem from suggestion', status, form)
        check('value="2" checked' in form and 'value="2020-09-13"' in form and 'id="problem_lat" name="problem_lat" inputmode="decimal" value="43.6' in form
              and 'ne correspond aux filtres' not in form, 'problem form prefilled by the suggestion (list not filtered)')
        check('name="reference_tokens" value="TOKA0001,TOKA0003"' in form and '&quot;token&quot;:&quot;TOKA0003&quot;' in form
              and 'value="Rue des Lilas, Bourgade"' in form, 'observations of the suggestion on the map, their streets proposed')
    m = re.search(r'name="problem_suggestion_dismiss" value="([^"]+)"', page)
    if m:
        status, _, page = admin.request('index.php?page=problems', {'csrf_token': admin.token(page), 'problem_suggestion_dismiss': m.group(1)})
        check('Suggestion ignorée' in page and 'Aucune suggestion' in page, 'suggestion dismissed')
    status, _, page = admin.request('index.php?page=problems', {'csrf_token': admin.token(page), 'problem_suggestion_dismiss': "1' OR '1"})
    check('Suggestion ignorée' not in page, 'invalid suggestion key refused')

    # Problems (#327): create, refused values, list filters, edit, delete
    status, _, page = admin.request('index.php?page=problems&edit=new')
    clean('problem form', status, page)
    check('data-problem-map' in page and 'name="problem_categories[]"' in page, 'problem form with map and categories')
    problem = {'csrf_token': admin.token(page), 'problem_id': '0', 'problem_title': 'Piste <b>bloquée</b>', 'problem_description': 'Gare',
               'problem_categories[]': ['2', '99999'], 'problem_zone': 'circle', 'problem_lat': '43.6', 'problem_lon': '3.9',
               'problem_radius': '100', 'problem_polygon': '', 'problem_start': '2020-01-01', 'problem_end': '',
               'problem_priority': '3', 'problem_status': 'open', 'problem_save': '1'}
    status, _, page = admin.request('index.php?page=problems', dict(problem, problem_title='', problem_radius='1'))
    clean('problem refused', status, page)
    check('Le titre est obligatoire' in page and 'Le rayon doit' in page, 'problem without title and with a too small radius refused')
    status, _, page = admin.request('index.php?page=problems', dict(problem, problem_zone='polygon', problem_polygon='[[43.6,3.9],[43.61,3.9]]'))
    check('Le polygone doit avoir' in page, 'polygon of 2 points refused')
    status, _, page = admin.request('index.php?page=problems', problem)
    clean('problem create', status, page)
    check('Problème <strong>Piste &lt;b&gt;bloquée&lt;/b&gt;</strong> enregistré' in page, 'problem created and escaped')
    check('Cercle de 100 m' in page and 'Depuis le 01/01/2020' in page and '>Haute</span>' in page, 'problem listed with zone, period and priority')
    polygon = dict(problem, problem_title='Travaux du centre', problem_description='', problem_zone='polygon', problem_priority='1', problem_status='closed',
                   problem_polygon='[[43.59,3.89],[43.61,3.89],[43.61,3.91],[43.59,3.91]]', problem_end='2021-12-31')
    polygon['problem_categories[]'] = ['3']
    status, _, page = admin.request('index.php?page=problems', polygon)
    check('Polygone de 4 points' in page and 'Du 01/01/2020 au 31/12/2021' in page, 'polygon problem created')
    for query, shown, hidden in [('q=gare', 'Piste', 'Travaux du centre'), ('status=active', 'Piste', 'Travaux du centre'),
                                 ('status=closed', 'Travaux du centre', 'Piste'), ('priority=1', 'Travaux du centre', 'Piste'),
                                 ('category=3', 'Travaux du centre', 'Piste'), ('q=%25', None, None)]:
        status, _, page = admin.request('index.php?page=problems&' + query)
        clean('problems ' + query, status, page)
        if shown:
            check(shown in page and hidden not in page, 'problems filter ' + query)
        else:
            check('Aucun problème ne correspond' in page, 'problems search of a literal %')
    status, _, page = admin.request('index.php?page=problems')
    # Sorted by priority: the high priority problem first
    edit = [l for l in links(page, 'edit=') if 'edit=new' not in l]
    check(len(edit) == 2, 'problem edit links')
    if edit:
        status, _, page = admin.request('index.php' + edit[0] if edit[0].startswith('?') else edit[0])
        clean('problem edit form', status, page)
        m = re.search(r'name="problem_id" value="(\d+)"', page)
        check(m is not None and 'value="Piste &lt;b&gt;bloquée&lt;/b&gt;"' in page and 'value="2020-01-01"' in page, 'problem edit form filled')
        if m:
            status, _, page = admin.request('index.php?page=problems', dict(problem, csrf_token=admin.token(page), problem_id=m.group(1),
                                                                            problem_status='in_progress', problem_radius='150'))
            check('Cercle de 150 m' in page and '>En cours</span>' in page and 'Cercle de 100 m' not in page
                  and len([l for l in links(page, 'edit=') if 'edit=new' not in l]) == 2, 'problem updated (not duplicated)')

    # Problem detail: count (no change), retroactive attribution (idempotent, conflicts kept),
    # manual attach and detach
    ids = dict((html.unescape(t), i) for i, t in re.findall(r'view=(\d+)">([^<]+)</a>', page))
    pb_gare, pb_works = ids.get('Piste <b>bloquée</b>'), ids.get('Travaux du centre')
    check(pb_gare is not None and pb_works is not None, 'problem detail links')
    if pb_gare and pb_works:
        gare_url, works_url = 'index.php?page=problems&view=' + pb_gare, 'index.php?page=problems&view=' + pb_works
        status, _, page = admin.request(gare_url)
        clean('problem detail', status, page)
        check('data-problem-view' in page and 'Observations rattachées (0)' in page, 'problem detail with map, nothing attached')
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_count': '1'})
        clean('problem count', status, page)
        check('<strong>2</strong> observation(s) publiée(s)' in page and 'TOKA0001, TOKA0003' in page and 'Observations rattachées (0)' in page,
              'problem count: TOKA0001 and TOKA0003 match, nothing attached')
        # TOKA0003 in another problem (manual attach ignores the criteria)
        status, _, page = admin.request(works_url, {'csrf_token': admin.token(page), 'problem_attach_token': '1', 'obs_token': 'toka0003'})
        check('Observation <strong>TOKA0003</strong> rattachée' in page and 'Observations rattachées (1)' in page, 'manual attach')
        for token, text in [('TOKA0003', 'déjà rattachée à ce problème'), ('TOKA0004', 'publiée'), ('NOPE0000', 'introuvable')]:
            status, _, page = admin.request(works_url, {'csrf_token': admin.token(page), 'problem_attach_token': '1', 'obs_token': token})
            check(text in page, 'manual attach of %s refused (%s)' % (token, text))
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_attach_token': '1', 'obs_token': 'TOKA0003'})
        check('appartient déjà à un autre problème' in page and 'Travaux du centre</a>' in page, 'manual attach of an observation of another problem refused')
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_count': '1'})
        check('<strong>1</strong> à rattacher : TOKA0001' in page and '<strong>1</strong> dans un autre problème' in page, 'problem count with a conflict')
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_attach_retro': '1'})
        clean('problem attach', status, page)
        check('<strong>1</strong> observation(s) rattachée(s), 0 déjà' in page and 'laissée(s) dans un autre problème' in page
              and 'Observations rattachées (1)' in page, 'retroactive attribution, conflict left in the other problem')
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_attach_retro': '1'})
        check('<strong>0</strong> observation(s) rattachée(s), 1 déjà' in page, 'retroactive attribution is idempotent')
        status, _, page = admin.request('index.php?page=problems')
        check(re.search(r'data-label="Observations">1<', page) is not None, 'number of observations in the list')
        status, _, page = admin.request(gare_url)
        detach = [l for l in links(page, 'action=detach')]
        check(len(detach) == 1, 'detach link')
        if detach:
            status, _, page = admin.request('index.php' + detach[0] if detach[0].startswith('?') else detach[0])
            clean('problem detach', status, page)
            check('<strong>TOKA0001</strong> détachée' in page and 'Observations rattachées (0)' in page, 'observation detached')
        status, _, page = admin.request(gare_url + '&action=detach&obsid=1')
        check('jeton de sécurité' in page, 'detach without CSRF token refused')
        # Automatic attachment when TOKA0001 is published again from the admin
        csrf = admin.token(page)
        for approveto in ['0', '1']:
            status, _, page = admin.request('index.php?page=observations&approved=0&action=approve&approveto=%s&token=TOKA0001&obsid=1&csrf_token=%s' % (approveto, csrf))
        clean('approve with a problem', status, page)
        check('Observation <strong>TOKA0001</strong> rattachée au problème' in page, 'observation attached to its problem when published')
        status, _, page = admin.request(gare_url)
        check('Observations rattachées (1)' in page and 'Automatique' in page, 'automatic attachment shown in the problem')
        # Two active problems match TOKA0001 once detached: to arbitrate
        detach = links(page, 'action=detach')
        if detach:
            admin.request('index.php' + detach[0] if detach[0].startswith('?') else detach[0])
        status, _, page = admin.request('index.php?page=problems', dict(problem, csrf_token=admin.token(page), problem_title='Gare bis', problem_radius='50'))
        check('Gare bis' in page and 'À arbitrer (1)' in page and 'name="problem_arbitrate"' in page, 'observation of two active problems to arbitrate')
        status, _, page = admin.request('index.php?page=problems', {'csrf_token': admin.token(page), 'problem_arbitrate': pb_gare, 'obs_id': '1'})
        clean('problem arbitrate', status, page)
        check('Observation <strong>TOKA0001</strong> rattachée au problème <strong>Piste' in page and 'À arbitrer' not in page, 'observation arbitrated')
        # Problem of the observation on the page Observations, timeline of the detail, map tab
        status, _, page = admin.request('index.php?page=observations&approved=1')
        check('href="?page=problems&amp;view=' + pb_gare + '"' in page and 'bi-diagram-3' in page, 'observation linked to its problem on the page Observations')
        status, _, page = admin.request(gare_url)
        check('id="timeline"' in page and 'class="report-chart report-heatmap"' in page and 'report-strip' in page and 'Mois le plus chargé' in page
              and page.count('<rect') >= 7 * 24, 'timeline and matrix days × hours of the observations of the problem')
        for query in ['tab=map', 'tab=map&status=active']:
            status, _, page = admin.request('index.php?page=problems&' + query)
            clean('problems ' + query, status, page)
            check('data-problems-map=' in page and '&quot;id&quot;:' + pb_gare in page and 'nav-tabs' in page, 'problems map tab ' + query)
        status, _, page = admin.request('index.php?page=problems&tab=map&q=nothing-matches')
        check('data-problems-map=' not in page and 'Aucun problème ne correspond' in page, 'problems map tab without problem')
        # Broadcast without webhook of the event: status set to "En attente", warning
        status, _, page = admin.request('index.php?page=problems')
        status, _, page = admin.request('index.php?page=problems', {'csrf_token': admin.token(page), 'problem_broadcast': pb_works})
        clean('problem broadcast', status, page)
        check('aucun webhook actif' in page and 'passé « En attente »' in page, 'problem broadcast without webhook')
        # Status and priority from the detail, internal notes (obs_notes shared with the observations)
        status, _, page = admin.request(gare_url)
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_quick': '1', 'problem_status': 'waiting', 'problem_priority': '4'})
        clean('problem quick edit', status, page)
        check('Statut et priorité enregistrés' in page and '>En attente</span>' in page and '>Critique</span>' in page, 'status and priority changed from the detail')
        status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_note_add': '1', 'note_text': 'Relance <b>mairie</b>\nle 3'})
        clean('problem note', status, page)
        check('Notes internes (1)' in page and 'Relance &lt;b&gt;mairie&lt;/b&gt;<br />' in page, 'problem note added and escaped')
        # Report: section of the problems (administrators, on request), never the internal notes
        status, _, report = admin.request('report.php?from=2020-01-01&to=2022-12-31&problems=1')
        clean('report with problems', status, report)
        check('Problèmes suivis' in report and 'Piste &lt;b&gt;bloquée&lt;/b&gt;' in report and 'Travaux du centre' in report
              and 'En attente' in report and 'Relance' not in report, 'problems in the report, without the notes')
        check('Problème : Piste &lt;b&gt;bloquée&lt;/b&gt;' in report and 'class="report-problem-map"' in report and 'Frise chronologique' in report and 'report-strip' in report
              and 'photo.php?s=300&amp;token=TOKA0001' in report and 'Créé le' in report, 'detail of each problem in the report')
        status, _, report = admin.request('report.php?from=2020-01-01&to=2022-12-31&s=1&sections[]=problems')
        check('Problèmes suivis' in report and 'report-problem-map' not in report, 'problems table without their detail')
        status, _, report = admin.request('report.php?from=2020-01-01&to=2022-12-31')
        check('Problèmes suivis' not in report, 'no problems in the report unless asked')
        status, _, report = admin.request('report.php?from=2020-01-01&to=2022-12-31&problems=1&format=slides')
        clean('report slides with problems', status, report)
        check('Problèmes suivis' in report, 'problems in the slides')
        m = re.search(r'name="note_id" value="(\d+)"', page)
        if m:
            status, _, obs_page = admin.request('index.php?page=observations&approved=1', {'csrf_token': admin.token(page), 'note_action': 'delete', 'note_id': m.group(1)})
            check('Note introuvable' in obs_page, 'problem note not deleted from the observations page')
            status, _, page = admin.request(gare_url, {'csrf_token': admin.token(page), 'problem_note_delete': '1', 'note_id': m.group(1)})
            check('Note supprimée' in page and 'Notes internes (0)' in page, 'problem note deleted')
        # Resolved problem whose observations are all resolved (TOKA0003, R_RES00002 validated above): nothing proposed
        # (the full case is in tests/api/functional.py, T14Problems)
        status, _, page = admin.request(works_url, {'csrf_token': admin.token(page), 'problem_resolve_observations': '1'})
        check('pas résolu' in page, 'observations not resolved while the problem is not resolved')
        status, _, page = admin.request(works_url, {'csrf_token': admin.token(page), 'problem_quick': '1', 'problem_status': 'resolved', 'problem_priority': '1'})
        clean('problem resolved', status, page)
        check('>Résolu</span>' in page and 'id="resolve"' not in page, 'resolved problem with resolved observations: nothing proposed')
        status, _, page = admin.request(works_url, {'csrf_token': admin.token(page), 'problem_resolve_observations': '1'})
        check('déjà résolues' in page, 'nothing to resolve')
        status, _, page = admin.request('index.php?page=problems')
        bis = [l for l in links(page, 'action=delete') if 'problemid=' in l and 'problemid=' + pb_gare not in l and 'problemid=' + pb_works not in l]
        if bis:
            admin.request('index.php' + bis[0] if bis[0].startswith('?') else bis[0])
        status, _, page = admin.request('index.php?page=problems')

    # Zone of streets: streets of the observations proposed, matching on the address
    status, _, page = admin.request('index.php?page=problems&edit=new')
    check('<option value="Rue des Lilas, Bourgade">' in page and 'value="streets"' in page, 'streets of the observations proposed')
    streets = dict(problem, csrf_token=admin.token(page), problem_title='Rue des Lilas', problem_zone='streets', problem_lat='', problem_lon='')
    status, _, page = admin.request('index.php?page=problems', dict(streets, **{'problem_streets[]': ['', ' ']}))
    check('Indiquer de 1 à' in page, 'zone of streets without street refused')
    status, _, page = admin.request('index.php?page=problems', dict(streets, **{'problem_streets[]': ['rue des lilas, bourgade', 'Rue <b>X</b>']}))
    clean('problem streets create', status, page)
    check('Rues : rue des lilas, bourgade ; Rue &lt;b&gt;X&lt;/b&gt;' in page, 'problem of streets created and escaped')
    m = re.search(r'view=(\d+)">Rue des Lilas</a>', page)
    check(m is not None, 'problem of streets listed')
    if m:
        status, _, page = admin.request('index.php?page=problems&view=' + m.group(1))
        clean('problem streets detail', status, page)
        check('&quot;zone&quot;:&quot;streets&quot;' in page and 'Modifier la zone' in page, 'problem of streets: map without shape, link to the zone')
        check('js/street-lines.js' in page and '&quot;streets&quot;:[{&quot;street&quot;:&quot;rue des lilas&quot;' in page, 'problem of streets: streets given to the map to be highlighted')
        status, _, page = admin.request('index.php?page=problems&view=' + m.group(1), {'csrf_token': admin.token(page), 'problem_count': '1'})
        check('<strong>1</strong> observation(s) publiée(s)' in page and 'TOKA0003' in page, 'problem of streets: TOKA0003 (Rue des Lilas) matches')
        status, _, page = admin.request('index.php?page=problems&edit=' + m.group(1))
        check('value="rue des lilas, bourgade"' in page and 'id="problem_zone_streets" value="streets" checked' in page
              and '&quot;kind&quot;:&quot;attached&quot;' not in page, 'problem of streets: edit form filled')
        status, _, page = admin.request('index.php?page=problems')
        delete = [l for l in links(page, 'action=delete') if re.search(r'problemid=' + m.group(1) + r'(&|$)', l)]
        if delete:
            admin.request('index.php' + delete[0] if delete[0].startswith('?') else delete[0])
        status, _, page = admin.request('index.php?page=problems')

    delete = [l for l in links(page, 'action=delete') if 'problemid=' in l]
    check(len(delete) == 2, 'problem delete links')
    if delete:
        status, _, page = admin.request('index.php' + delete[-1] if delete[-1].startswith('?') else delete[-1])
        clean('problem delete', status, page)
        check('supprimé' in page, 'problem deleted')

    # Audit log shows the actions
    status, _, page = admin.request('index.php?page=audit')
    for action in ['login', 'observation_approve', 'note_add', 'settings_edit', 'webhook_create', 'webhook_delete', 'city_import', 'city_create', 'scope_create', 'account_create', 'category_disable', 'category_create', 'category_delete', 'resolution_status', 'observation_delete', 'observation_archive', 'observation_archive_period', 'observation_unarchive',
                   'problem_create', 'problem_edit', 'problem_delete', 'problem_broadcast', 'account_password_link', 'problem_attach_retro', 'problem_attach', 'problem_detach', 'problem_attach_auto', 'problem_suggestion_dismiss']:
        check(action in page, 'audit log contains ' + action)

    # A problem without observation: not shown to a citystaff
    status, _, page = admin.request('index.php?page=problems&edit=new')
    admin.request('index.php?page=problems', dict(problem, csrf_token=admin.token(page), problem_title='Sans observation'))

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
    status, _, page = staff.request('report.php?from=2020-01-01&to=2022-12-31&cities[]=2')
    check('Aucune observation' in page, 'citystaff report limited to their cities')
    status, _, page = staff.request('report.php?from=2020-01-01&to=2022-12-31&problems=1')
    check('Villes : Testville' in page and 'Saint-Exemple' not in page, 'citystaff report of their cities')
    check('Problèmes suivis' not in page, 'no problems in a citystaff report')
    status, _, page = staff.request('report.php?from=2020-01-01&to=2022-12-31&s=1&sections[]=problems&sections[]=problem_details&sections[]=kpis')
    check('Problèmes suivis' not in page and 'report-problem-map' not in page and 'Chiffres clés' in page, 'no problem sections for a citystaff, even asked')
    status, _, page = staff.request('index.php?page=reports')
    check('value="problems"' not in page and 'value="problem_details"' not in page and 'value="kpis"' in page, 'no problems option for a citystaff')

    # Problems: read only, those having observations in the cities of the citystaff
    status, _, page = staff.request('index.php?page=problems')
    clean('citystaff problems', status, page)
    check('Piste &lt;b&gt;bloquée&lt;/b&gt;' in page and 'Sans observation' not in page, 'citystaff sees the problems of their cities only')
    check('edit=new' not in page and 'action=delete' not in page and 'Suggestions' not in page and 'Consultation seulement' in page, 'citystaff problems read only')
    m = re.search(r'view=(\d+)">Piste', page)
    if m:
        status, _, page = staff.request('index.php?page=problems&view=' + m.group(1))
        clean('citystaff problem detail', status, page)
        check('TOKA0001' in page and 'Notes internes' not in page and 'problem_quick' not in page and 'action=detach' not in page
              and 'problem_count' not in page and 'problem_attach_token' not in page, 'citystaff problem detail read only, without notes')
        status, _, page = staff.request('index.php?page=problems&view=' + m.group(1), {'csrf_token': staff.token(page), 'problem_note_add': '1', 'note_text': 'x',
                                                                                   'problem_quick': '1', 'problem_status': 'closed', 'problem_priority': '1'})
        check('Note ajoutée' not in page and 'enregistrés' not in page, 'citystaff changes refused')
        status, _, page = staff.request('index.php?page=problems&view=' + m.group(1) + '&action=detach&obsid=1' + '&csrf_token=' + staff.token(page))
        check('détachée' not in page and 'TOKA0001' in page, 'citystaff detach refused')
    status, _, page = staff.request('index.php?page=problems&edit=new')
    check('name="problem_save"' not in page, 'citystaff has no problem form')
    status, _, page = staff.request('index.php?page=observations&approved=1')
    clean('citystaff observations', status, page)
    check('TOKA0001' in page and 'TOKA0003' not in page, 'citystaff only sees the observations of Testville')
    check('value="resolution_new"' in page and '>Supprimer</option>' not in page and 'value="archive"' not in page and 'Archiver par période' not in page, 'citystaff bulk actions limited to the resolutions (no archiving)')
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
