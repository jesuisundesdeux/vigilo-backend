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
import http.cookiejar
import re
import sys
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

    pages = ['dashboard', 'observations', 'resolutions', 'cities', 'accounts', 'scopes', 'twitter', 'settings', 'audit', 'update']
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

    # Audit log shows the actions
    status, _, page = admin.request('index.php?page=audit')
    for action in ['login', 'observation_approve', 'note_add', 'settings_edit']:
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
    for name in ['accounts', 'settings', 'update', 'audit', 'cities', 'scopes', 'twitter']:
        status, _, page = staff.request('index.php?page=' + name)
        check('Accès non autorisé' in page, 'citystaff refused on ' + name)
    status, _, page = staff.request('index.php?page=observations&approved=1')
    clean('citystaff observations', status, page)
    check('TOKA0001' in page and 'TOKA0003' not in page, 'citystaff only sees the observations of Testville')

    # Direct access to the page files is refused
    status, _, page = admin.request('inc/settings.php')
    check(status in (403, 404) or 'Not allowed' in page, 'direct access to inc/settings.php refused')

    print('%d failure(s)' % len(failures))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
