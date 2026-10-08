#!/bin/bash
# End-to-end test of docker-compose.yml with the "blur" profile: the backend sends the
# photos to the blur server (VIGILO_BLUR_URL), the stored photo has its face and
# licence plates masked.
#
#   tests/blur/compose.sh <backend image> <blur image>
#
# Needs docker compose, the mysql client and python3 with numpy and opencv.
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d)
PORT=${COMPOSE_TEST_PORT:-8095}
DB_PORT=${COMPOSE_TEST_DB_PORT:-3307}
PROJECT=vigilo-blur-test
compose() { docker compose -p "$PROJECT" --env-file "$WORK/.env" -f "$REPO/docker-compose.yml" -f "$WORK/test.yml" --profile blur "$@"; }
cleanup() {
  compose logs --no-color > "$WORK/compose.log" 2>&1 || true
  [ "${status:-1}" -ne 0 ] && tail -60 "$WORK/compose.log"
  compose down -v > /dev/null 2>&1 || true
  docker run --rm -v "$WORK:/w" alpine:3.20 rm -rf /w/data > /dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

cat > "$WORK/.env" <<ENV
AUTOUPDATE=true
VOLUME_PATH=$WORK/data
BIND=127.0.0.1:$PORT
MYSQL_ROOT_PASSWORD=root
MYSQL_USER=vigilo
MYSQL_PASSWORD=vigilo
MYSQL_HOST=db
MYSQL_DATABASE=vigilo
VIGILO_IMAGE=$1
VIGILO_BLUR_IMAGE=$2
VIGILO_BLUR_URL=http://blur:8000/blur
ENV
# The database is published for the test (settings and fixtures)
cat > "$WORK/test.yml" <<YML
services:
  db:
    ports: ["127.0.0.1:$DB_PORT:3306"]
YML

compose up -d --no-build --pull missing
echo "Waiting for the backend..."
for i in $(seq 1 90); do curl -sf -o /dev/null "http://127.0.0.1:$PORT/get_version.php" && break; sleep 2; done
curl -sf "http://127.0.0.1:$PORT/get_version.php"; echo

export MYSQL_HOST=127.0.0.1 MYSQL_TCP_PORT=$DB_PORT MYSQL_DATABASE=vigilo MYSQL_ROOT_PASSWORD=root MYSQL_PWD=root
mysql --default-character-set=utf8mb4 -h "$MYSQL_HOST" -uroot vigilo < "$REPO/tests/api/seed.sql"
mysql -h "$MYSQL_HOST" -uroot vigilo -e "UPDATE obs_config SET config_value='127.0.0.1:$PORT' WHERE config_param='vigilo_urlbase'"

status=0
BLUR_FROM_ENVIRONMENT=1 python3 "$REPO/tests/api/functional.py" --base "http://127.0.0.1:$PORT" T09RealBlurServer || status=$?
exit $status
