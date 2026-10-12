#!/usr/bin/env python3
"""
Install assistant (admin/setup.php), classic install, run by tests/setup/run.sh:
install code, database settings (test, written config/config.php), schema from the
migrations, first admin account with the URL of the instance, then the normal login.
"""
import argparse
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'admin'))
from smoke import Client, check, clean, failures  # noqa: E402


def sql(statement):
    env = dict(os.environ, MYSQL_PWD=os.environ.get('MYSQL_ROOT_PASSWORD', ''))
    out = subprocess.run(['mysql', '-h', os.environ['MYSQL_HOST'], '-uroot', '-N', '-B', os.environ['MYSQL_DATABASE'], '-e', statement],
                         env=env, check=True, capture_output=True, text=True)
    return out.stdout.strip()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', required=True)
    parser.add_argument('--app', required=True)
    parser.add_argument('--repo', required=True)
    parser.add_argument('--env-base', required=True, help='same code, served with VIGILO_SETUP_CODE=demo-code-1234-abcd')
    args = parser.parse_args()
    version = re.search(r"define\('BACKEND_VERSION', '([0-9.]+)'\)", open(os.path.join(args.repo, 'app/includes/version.php')).read()).group(1)
    config_file = os.path.join(args.app, 'config/config.php')
    code_file = os.path.join(args.app, 'caches/setup-code.php')
    db = {'MYSQL_HOST': os.environ['MYSQL_HOST'], 'MYSQL_USER': os.environ['MYSQL_USER'],
          'MYSQL_PASSWORD': os.environ['MYSQL_PASSWORD'], 'MYSQL_DATABASE': os.environ['MYSQL_DATABASE']}

    client = Client(args.base)
    status, url, page = client.request('login.php')
    check(url.endswith('/admin/setup.php'), 'login page sends to the install assistant')
    clean('setup page', status, page)
    check("Code d'installation" in page and 'caches/setup-code.php' in page, 'install code asked, with its file')
    check(os.path.exists(code_file), 'install code written in caches/')
    content = open(code_file).read()
    code = re.search(r'^([A-Z0-9]{24})$', content, re.M).group(1)
    check(content.startswith('<?php http_response_code(404); exit; ?>'), 'install code file shows nothing over HTTP')
    status, _, served = client.request(args.base + '/caches/setup-code.php')
    check(code not in served, 'install code not served over HTTP')
    check('Vérifications' in page and 'Extension mysqli' in page, 'requirements listed')

    # Install code set by the host: asked instead of the file's, never printed by the CLI
    host = Client(args.env_base)
    _, _, host_page = host.request('setup.php')
    check('VIGILO_SETUP_CODE' in host_page and 'caches/setup-code.php' not in host_page, 'install code of the host asked (VIGILO_SETUP_CODE)')
    _, _, host_page = host.request('setup.php', {'csrf_token': host.token(host_page), 'setup_code': code})
    check('Code d\'installation incorrect' in host_page, 'code of the file refused when the host sets one')
    _, _, host_page = host.request('setup.php', {'csrf_token': host.token(host_page), 'setup_code': 'DEMO CODE 1234 ABCD'})
    check('name="MYSQL_HOST"' in host_page, 'install code of the host accepted')

    # Nothing without the code
    status, _, page = client.request('setup.php', dict(db, csrf_token=client.token(page), setup_action='db_save'))
    check('code d\'installation manquant' in page and not os.path.exists(config_file), 'database settings refused without the code')
    status, _, page = client.request('setup.php', {'csrf_token': client.token(page), 'setup_code': 'WRONGCODE'})
    check('Code d\'installation incorrect' in page, 'wrong install code refused')
    other = Client(args.base)
    _, _, other_page = other.request('setup.php')
    status, _, page = client.request('setup.php', {'csrf_token': client.token(page), 'setup_code': code.lower()[:4] + ' ' + code.lower()[4:]})
    clean('setup unlocked', status, page)
    check('name="MYSQL_HOST"' in page, 'database form after the install code (case and spaces ignored)')
    _, _, other_page = other.request('setup.php', dict(db, csrf_token=other.token(other_page), setup_action='db_save'))
    check(not os.path.exists(config_file), 'another browser session still needs the code')

    # 1. Database
    status, _, page = client.request('setup.php', dict(db, MYSQL_PASSWORD='wrong-password', csrf_token=client.token(page), setup_action='db_test'))
    clean('database test (wrong password)', status, page)
    check('Connexion impossible' in page, 'wrong database password reported')
    status, _, page = client.request('setup.php', dict(db, csrf_token=client.token(page), setup_action='db_test'))
    check('Connexion réussie' in page and 'base vide' in page and not os.path.exists(config_file), 'database test: connection and privileges, nothing written')
    check(sql("SHOW TABLES LIKE 'vigilo_setup_check'") == '', 'privilege test table dropped')
    status, _, page = client.request('setup.php', dict(db, csrf_token=client.token(page), setup_action='db_save'))
    clean('database settings saved', status, page)
    check(os.path.exists(config_file), 'config/config.php written')
    config = open(config_file).read()
    check("$config['MYSQL_PASSWORD'] = '%s';" % db['MYSQL_PASSWORD'] in config, 'database settings in config/config.php')
    check('Initialiser la base' in page, 'next step: database initialisation')

    # 2. Schema
    status, _, page = client.request('setup.php', {'csrf_token': client.token(page), 'setup_action': 'migrate'})
    clean('database initialised', status, page)
    check('Base initialisée' in page, 'migrations applied')
    check(sql("SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version'") == version, 'database at the code version')
    check('name="password2"' in page and 'value="127.0.0.1:' in page and '<option value="http" selected>' in page,
          'admin form with the detected URL and protocol')

    # Command line (Docker entrypoint): the install code while no admin exists
    out = subprocess.run(['php', os.path.join(args.repo, 'scripts/vigilo-migrate.php'), '--app=' + args.app, '--setup-code'],
                         capture_output=True, text=True, env=dict(os.environ))
    check(out.returncode == 0 and out.stdout.split(' ')[0] == code, 'vigilo-migrate.php --setup-code prints the same code')
    out = subprocess.run(['php', os.path.join(args.repo, 'scripts/vigilo-migrate.php'), '--app=' + args.app, '--setup-code'],
                         capture_output=True, text=True, env=dict(os.environ, VIGILO_SETUP_CODE='demo-code-1234-abcd'))
    check(out.returncode == 0 and out.stdout == '', 'vigilo-migrate.php --setup-code prints nothing with VIGILO_SETUP_CODE')

    # 3. Admin account
    admin = {'name': 'Admin <b>Test</b>', 'login': 'admin', 'password': 'court', 'password2': 'court', 'instance': 'Vigilo Test',
             'urlbase': 'http://127.0.0.1:%s/' % args.base.rsplit(':', 1)[1], 'proto': 'http', 'setup_action': 'admin'}
    status, _, page = client.request('setup.php', dict(admin, csrf_token=client.token(page)))
    check('au moins 10 caractères' in page, 'short password refused')
    admin.update({'password': 'mot-de-passe-long', 'password2': 'autre-mot-de-passe'})
    status, _, page = client.request('setup.php', dict(admin, csrf_token=client.token(page)))
    check('différents' in page, 'different passwords refused')
    admin['password2'] = admin['password']
    status, url, page = client.request('setup.php', dict(admin, csrf_token=client.token(page)))
    clean('admin created', status, page)
    check(url.endswith('/admin/index.php') and 'Se déconnecter' in page, 'admin created and logged in')
    check(sql("SELECT config_value FROM obs_config WHERE config_param='vigilo_urlbase'") == '127.0.0.1:' + args.base.rsplit(':', 1)[1],
          'vigilo_urlbase from the URL (protocol and slash removed)')
    check(sql("SELECT config_value FROM obs_config WHERE config_param='vigilo_http_proto'") == 'http', 'vigilo_http_proto')
    check(sql("SELECT config_value FROM obs_config WHERE config_param='vigilo_name'") == 'Vigilo Test', 'instance name')
    check(sql("SELECT role_owner FROM obs_roles WHERE role_login='admin' AND role_name='admin'") == 'Admin <b>Test</b>', 'admin account')
    check(sql("SELECT COUNT(*) FROM obs_audit_log WHERE audit_action='setup_admin_create'") == '1', 'creation in the audit log')
    check(not os.path.exists(code_file), 'install code removed')

    # Installed: the assistant is closed, the normal login works
    fresh = Client(args.base)
    status, url, page = fresh.request('setup.php')
    check(url.endswith('/admin/login.php') and 'Se connecter' in page, 'assistant closed once installed')
    check(not os.path.exists(code_file), 'no new install code once installed')
    status, url, page = fresh.login('admin', 'mot-de-passe-long')
    check(url.endswith('/admin/index.php') and 'Se déconnecter' in page, 'login with the new account')
    # First configuration guide: instance done by the assistant, then scope, cities, vigilo-conf
    check('Première configuration' in page and '1 / 4 étapes requises' in page, 'first configuration guide on the dashboard')
    check('Étape ' not in page, 'no step badge in the menu')
    check('Floutage' not in page and 'Modérateurs' in page, 'steps listed (photo blurring not one of them)')
    sql("SET sql_mode=''; INSERT INTO obs_scopes (scope_name, scope_display_name) VALUES ('99_test', 'Test')")
    sql("SET sql_mode=''; INSERT INTO obs_cities (city_scope, city_name) VALUES (1, 'Testville')")
    # install.php of the old versions: removed by the dashboard
    open(os.path.join(args.app, 'install.php'), 'w').write('<?php // old install')
    status, _, page = fresh.request('index.php')
    clean('dashboard after scope and cities', status, page)
    check(not os.path.exists(os.path.join(args.app, 'install.php')) and 'install.php' not in page, 'old install.php removed by the dashboard')
    check('Première configuration' not in page or 'vigilo-conf' in page, 'guide left only for the listing in vigilo-conf')
    status, _, page = fresh.request(args.base + '/get_version.php')
    check(status == 200 and version in page, 'public API answers')
    out = subprocess.run(['php', os.path.join(args.repo, 'scripts/vigilo-migrate.php'), '--app=' + args.app, '--setup-code'],
                         capture_output=True, text=True, env=dict(os.environ))
    check(out.returncode == 0 and out.stdout == '', 'vigilo-migrate.php --setup-code prints nothing once installed')

    print('%d failure(s)' % len(failures))
    sys.exit(1 if failures else 0)


if __name__ == '__main__':
    main()
