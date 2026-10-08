#!/bin/bash
# End-to-end test of the admin updater (classic install, local PHP):
#
#   1. the current code is installed and seeded (tests/api/seed.sql)
#   2. fake releases are built with scripts/build-release.sh and served locally:
#      - X.Y.Z+1 with a new migration, signed  -> installed from the admin
#      - X.Y.Z+2 with a tampered archive       -> refused (checksum)
#      - X.Y.Z+2 with a broken migration       -> code and database restored
#   3. checks: version answered by the instance, database version, preserved config
#      and photos, audit log
#
# Needs the same MYSQL_* variables as tests/api/run.sh, php (mysqli, gd, curl, zip,
# sodium), zip and python3.
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
PORT=${UPDATE_TEST_PORT:-8091}
FEED_PORT=$((PORT + 1))
WORK=$(mktemp -d)
cleanup() {
  pkill -f "^php -d variables_order=EGPCS -S 127.0.0.1:$PORT" > /dev/null 2>&1 || true
  pkill -f "^python3 -m http.server $FEED_PORT" > /dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

CURRENT=$(sed -n "s/^define('BACKEND_VERSION', '\([0-9.]*\)');/\1/p" "$REPO/app/includes/version.php")
IFS=. read -r MA MI PA <<< "$CURRENT"
NEXT="$MA.$MI.$((PA + 1))"
AFTER="$MA.$MI.$((PA + 2))"
echo "Installed: $CURRENT, releases: $NEXT (good), $AFTER (bad)"

KEYS=$(php -r '$p = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($p)), " ", base64_encode(sodium_crypto_sign_publickey($p));')
SECRET=${KEYS% *}
PUBLIC=${KEYS#* }

# --- fake release sources
make_release() { # version, extra migration SQL
  local version=$1 sql=$2 src="$WORK/src-$1"
  mkdir -p "$src"
  cp -r "$REPO/app" "$REPO/scripts" "$src/"
  sed -i "s/define('BACKEND_VERSION', '[0-9.]*');/define('BACKEND_VERSION', '$version');/" "$src/app/includes/version.php"
  sed -i "s|define('VIGILO_RELEASE_PUBLIC_KEY', '[^']*');|define('VIGILO_RELEASE_PUBLIC_KEY', '$PUBLIC');|" "$src/app/includes/release_key.php"
  printf '%s\n' "$sql" "UPDATE obs_config SET config_value = '$version' WHERE config_param = 'vigilo_db_version';" > "$src/app/migrations/init-$version.sql"
  VIGILO_RELEASE_SIGNING_KEY=$SECRET "$src/scripts/build-release.sh" "$version" "$WORK/feed/$version" > /dev/null
}
feed() { # version -> releases/latest JSON
  local v=$1
  cat > "$WORK/feed/latest.json" <<JSON
{"tag_name": "v$v", "name": "v$v", "body": "Test release $v", "html_url": "http://127.0.0.1:$FEED_PORT/",
 "assets": [
  {"name": "vigilo-backend-$v.zip", "browser_download_url": "http://127.0.0.1:$FEED_PORT/$v/vigilo-backend-$v.zip"},
  {"name": "SHA256SUMS", "browser_download_url": "http://127.0.0.1:$FEED_PORT/$v/SHA256SUMS"},
  {"name": "vigilo-backend-$v.zip.sig", "browser_download_url": "http://127.0.0.1:$FEED_PORT/$v/vigilo-backend-$v.zip.sig"}
 ]}
JSON
  rm -f "$WORK/app/caches/updates/latest-release.json"
}

mkdir -p "$WORK/feed"
make_release "$NEXT" "INSERT IGNORE INTO obs_config (config_param, config_value) VALUES ('update_test', 'ok');"
(cd "$WORK/feed" && python3 -m http.server "$FEED_PORT" --bind 127.0.0.1 > /dev/null 2>&1 &)

# --- installed instance (current code, trusting the test key)
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -h "$MYSQL_HOST" -uroot -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`; GRANT ALL ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%'"
cp -r "$REPO/app" "$WORK/app"
cp "$REPO/config/config.php.docker" "$WORK/app/config/config.php"
echo "// instance specific" >> "$WORK/app/config/config.php"
sed -i "s|define('VIGILO_RELEASE_PUBLIC_KEY', '[^']*');|define('VIGILO_RELEASE_PUBLIC_KEY', '$PUBLIC');|" "$WORK/app/includes/release_key.php"
cp "$REPO/tests/api/fixtures/photo.jpg" "$WORK/app/images/TOKA0001.jpg"
# Code of an older version, listed in scripts/obsolete-paths.txt: removed by the update
mkdir -p "$WORK/app/panels/jesuisundesdeux" && echo "<?php // old panel" > "$WORK/app/panels/jesuisundesdeux/panel.php"
php "$REPO/scripts/vigilo-migrate.php" --app="$WORK/app" > /dev/null
mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" < "$REPO/tests/api/seed.sql"
mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" -e "UPDATE obs_config SET config_value='127.0.0.1:$PORT' WHERE config_param='vigilo_urlbase'"

feed "$NEXT"
(cd "$WORK/app" && VIGILO_RELEASE_API="http://127.0.0.1:$FEED_PORT/latest.json" PHP_CLI_SERVER_WORKERS=4 \
  php -d variables_order=EGPCS -S "127.0.0.1:$PORT" > "$WORK/php.log" 2>&1 &)
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/get_version.php" && break; sleep 1; done

status=0
python3 - "$PORT" "$CURRENT" "$NEXT" "$AFTER" "$WORK" <<'PY' || status=$?
import http.cookiejar, json, re, subprocess, sys, urllib.parse, urllib.request
port, current, nxt, after, work = sys.argv[1:]
base = 'http://127.0.0.1:%s/' % port
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
fails = []

def req(path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    r = op.open(urllib.request.Request(base + path, data=body), timeout=300)
    return r.read().decode('utf-8', 'replace')

def token(page):
    return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)

def check(cond, msg):
    print(('ok   ' if cond else 'FAIL ') + msg)
    if not cond:
        fails.append(msg)

def version():
    return json.loads(urllib.request.urlopen(base + 'get_version.php').read())['version']

def install(expected_version, password='vigilo-test'):
    page = req('admin/index.php?page=update')
    check('Vigilo %s est disponible' % expected_version in page, 'release %s offered in the admin' % expected_version)
    return req('admin/index.php?page=update', {'csrf_token': token(page), 'install_release': '1',
                                              'version': expected_version, 'password': password})

def sh(cmd):
    subprocess.check_call(cmd, shell=True)

page = req('admin/login.php')
req('admin/login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': token(page)})
check(version() == current, 'instance answers %s before the update' % current)

# Wrong password
page = install(nxt, password='wrong')
check('Mot de passe incorrect' in page and version() == current, 'update refused with a wrong password')

# Good, signed release
page = install(nxt)
check('Vigilo %s est installé' % nxt in page, 'update to %s reported as installed' % nxt)
check(version() == nxt, 'instance answers %s after the update' % nxt)
page = req('admin/index.php?page=update')
check('Vigilo est à jour' in page, 'admin says up to date')
check('// instance specific' in open(work + '/app/config/config.php').read(), 'config.php preserved')
import os
check(os.path.exists(work + '/app/images/TOKA0001.jpg'), 'photos preserved')
check(not os.path.exists(work + '/app/panels'), 'obsolete code (panels) removed')
page = req('admin/index.php?page=audit')
check('update_install' in page, 'update recorded in the audit log')

# Tampered archive
sh('cd %s/feed && mkdir -p %s && cp -r %s/* %s/ && cd %s && sed -i "s/%s/%s/g" SHA256SUMS && mv vigilo-backend-%s.zip vigilo-backend-%s.zip && mv vigilo-backend-%s.zip.sig vigilo-backend-%s.zip.sig && echo junk >> vigilo-backend-%s.zip'
   % (work, after, nxt, after, after, nxt, after, nxt, after, nxt, after, after))
sh('''python3 - <<'EOF'
import json; p = "%s/feed/latest.json"; d = json.load(open(p)); s = json.dumps(d).replace("%s", "%s"); open(p, "w").write(s)
EOF''' % (work, nxt, after))
sh('rm -f %s/app/caches/updates/latest-release.json' % work)
page = install(after)
check('ne correspond pas' in page and version() == nxt, 'tampered archive refused, %s still installed' % nxt)
PY
[ $status -ne 0 ] && { echo "--- PHP log"; tail -30 "$WORK/php.log"; }

# --- broken migration: the update must be rolled back (code and database)
if [ $status -eq 0 ]; then
  rm -rf "$WORK/feed/$AFTER" "$WORK/src-$AFTER"
  make_release "$AFTER" "ALTER TABLE obs_list ADD COLUMN broken_column INT; THIS IS NOT SQL;"
  feed "$AFTER"
  python3 - "$PORT" "$NEXT" "$AFTER" <<'PY' || status=$?
import http.cookiejar, json, re, sys, urllib.parse, urllib.request
port, nxt, after = sys.argv[1:]
base = 'http://127.0.0.1:%s/' % port
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    return op.open(urllib.request.Request(base + path, data=body), timeout=300).read().decode('utf-8', 'replace')
def token(page):
    return re.search(r'name="csrf_token" value="([a-f0-9]+)"', page).group(1)
page = req('admin/login.php')
req('admin/login.php', {'login': 'admin', 'password': 'vigilo-test', 'csrf_token': token(page)})
page = req('admin/index.php?page=update')
page = req('admin/index.php?page=update', {'csrf_token': token(page), 'install_release': '1', 'version': after, 'password': 'vigilo-test'})
version = json.loads(urllib.request.urlopen(base + 'get_version.php').read())['version']
ok1 = 'restaurée' in page
ok2 = version == nxt
print(('ok   ' if ok1 else 'FAIL ') + 'broken migration reported and rolled back')
print(('ok   ' if ok2 else 'FAIL ') + 'instance answers %s after the rollback (got %s)' % (nxt, version))
sys.exit(0 if ok1 and ok2 else 1)
PY
  DBV=$(mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" -N -e "SELECT config_value FROM obs_config WHERE config_param='vigilo_db_version'")
  COL=$(mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" -N -e "SHOW COLUMNS FROM obs_list LIKE 'broken_column'")
  if [ "$DBV" = "$NEXT" ] && [ -z "$COL" ]; then echo "ok   database restored ($DBV, no partial change)"; else echo "FAIL database not restored ($DBV, column: $COL)"; status=1; fi
fi

[ $status -ne 0 ] && { echo "--- PHP log"; tail -30 "$WORK/php.log"; }
exit $status
