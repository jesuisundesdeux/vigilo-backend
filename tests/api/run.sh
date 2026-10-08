#!/bin/bash
# Runs the API contract tests against a given code tree and PHP runtime image.
#
#   tests/api/run.sh <app dir> <docker image> [--record]
#
# - <app dir>: the "app" directory to serve (this repository's app/, or an older version)
# - <docker image>: image providing PHP + Apache (e.g. vigilobs/vigilo-backend:0.0.20
#   for PHP 7.3, or the image built from this repository)
#
# Needs a MariaDB reachable with MYSQL_HOST/MYSQL_USER/MYSQL_PASSWORD/MYSQL_DATABASE and
# a root password in MYSQL_ROOT_PASSWORD (the database is dropped and recreated).
# CONTRACT_PORT (default 8089) is the port the instance listens on (host network).
set -euo pipefail

APP_SRC=$(cd "$1" && pwd)
IMAGE=$2
shift 2
REPO=$(cd "$(dirname "$0")/../.." && pwd)
PORT=${CONTRACT_PORT:-8089}
NAME=vigilo-contract-$PORT
WORK=$(mktemp -d)
# Files written by Apache (www-data) in the container: made removable before cleaning up
trap 'docker exec "$NAME" chmod -R a+rwX /var/www/html > /dev/null 2>&1 || true; docker rm -f "$NAME" > /dev/null 2>&1 || true; rm -rf "$WORK" 2> /dev/null || true' EXIT

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -h "$MYSQL_HOST" -uroot -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`; GRANT ALL ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%'"

# Schema from this repository's migrations, then the test data
cp -r "$REPO/app" "$WORK/schema"
cp "$REPO/config/config.php.docker" "$WORK/schema/config/config.php"
php "$REPO/scripts/vigilo-migrate.php" --app="$WORK/schema" > /dev/null
mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" < "$REPO/tests/api/seed.sql"
mysql -h "$MYSQL_HOST" -uroot "$MYSQL_DATABASE" -e "UPDATE obs_config SET config_value='127.0.0.1:$PORT' WHERE config_param='vigilo_urlbase'"

# Writable copy of the code under test, with the seeded photos
cp -r "$APP_SRC" "$WORK/app"
cp "$REPO/config/config.php.docker" "$WORK/app/config/config.php"
mkdir -p "$WORK/app/images/resolutions" "$WORK/app/caches" "$WORK/app/maps"
for t in TOKA0001 TOKA0002 TOKA0003 TOKA0004 TOKA0006 TOKA0007 TOKA0008; do
  cp "$REPO/tests/api/fixtures/photo.jpg" "$WORK/app/images/$t.jpg"
done
cp "$REPO/tests/api/fixtures/photo.jpg" "$WORK/app/images/resolutions/R_RES00001.jpg"
chmod -R a+rwX "$WORK/app"

EXTRA=()
if [ -n "${HTTPS_PROXY:-}" ]; then
  EXTRA+=(-e "HTTPS_PROXY=$HTTPS_PROXY" -e "https_proxy=$HTTPS_PROXY")
fi
if [ -n "${CONTRACT_CA_BUNDLE:-}" ]; then
  echo "curl.cainfo=/etc/ssl/contract-ca.crt" > "$WORK/ca.ini"
  EXTRA+=(-v "$CONTRACT_CA_BUNDLE:/etc/ssl/contract-ca.crt:ro" -v "$WORK/ca.ini:/usr/local/etc/php/conf.d/zz-contract-ca.ini:ro")
fi

if [ "$IMAGE" == "host" ]; then
  # Local PHP (built-in server): quick check of the PHP version installed on this machine
  (cd "$WORK/app" && MYSQL_HOST="$MYSQL_HOST" MYSQL_USER="$MYSQL_USER" MYSQL_PASSWORD="$MYSQL_PASSWORD" MYSQL_DATABASE="$MYSQL_DATABASE" \
    PHP_CLI_SERVER_WORKERS=4 php -d display_errors=stderr -d variables_order=EGPCS -S "127.0.0.1:$PORT" > "$WORK/php.log" 2>&1 &)
  trap 'pkill -f "^php -d display_errors=stderr -d variables_order=EGPCS -S 127.0.0.1:$PORT" > /dev/null 2>&1 || true; rm -rf "$WORK" 2> /dev/null || true' EXIT
else
docker run -d --name "$NAME" --network host \
  -v "$WORK/app:/var/www/html" \
  -e MYSQL_HOST="$MYSQL_HOST" -e MYSQL_USER="$MYSQL_USER" -e MYSQL_PASSWORD="$MYSQL_PASSWORD" -e MYSQL_DATABASE="$MYSQL_DATABASE" \
  "${EXTRA[@]}" \
  --entrypoint sh "$IMAGE" -c "sed -i 's/^Listen 80$/Listen $PORT/' /etc/apache2/ports.conf && sed -i 's/:80>/:$PORT>/' /etc/apache2/sites-enabled/*.conf && exec apache2-foreground" > /dev/null
fi

for i in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/get_version.php" && break
  sleep 1
done

status=0
python3 "$REPO/tests/api/contract.py" --base "http://127.0.0.1:$PORT" "$@" || status=$?
# Admin smoke test (only for the code of this repository, the old admin differs)
if [ "${ADMIN_SMOKE:-0}" = "1" ]; then
  python3 "$REPO/tests/admin/smoke.py" --base "http://127.0.0.1:$PORT" || status=$?
fi
if [ $status -ne 0 ]; then
  echo "--- PHP / Apache log"
  if [ "$IMAGE" == "host" ]; then grep -i "warning\|error\|deprecated" "$WORK/php.log" | sort | uniq -c | sort -rn | head -40; else docker logs "$NAME" 2>&1 | grep -v ' 200 ' | tail -50; fi
fi
exit $status
