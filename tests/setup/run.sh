#!/bin/bash
# End-to-end test of the install assistant (admin/setup.php), classic install with the
# local PHP: empty database, no config/config.php. See tests/setup/setup.py.
#
# Needs the same MYSQL_* variables as tests/api/run.sh (the database is dropped and
# recreated), php (mysqli, gd) and python3.
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
PORT=${SETUP_TEST_PORT:-8093}
WORK=$(mktemp -d)
cleanup() {
  pkill -f "^php -d display_errors=stderr -S 127.0.0.1:$PORT" > /dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -h "$MYSQL_HOST" -uroot -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`; GRANT ALL ON \`$MYSQL_DATABASE\`.* TO '$MYSQL_USER'@'%'"
unset MYSQL_PWD

# Code as uploaded on a shared hosting: no configuration file
cp -r "$REPO/app" "$WORK/app"
rm -f "$WORK/app/config/config.php" "$WORK/app/caches/setup-code.php" "$WORK/app/config/setup-code.php"
mkdir -p "$WORK/app/images/resolutions" "$WORK/app/caches"
chmod -R a+rwX "$WORK/app"

(cd "$WORK/app" && php -d display_errors=stderr -S "127.0.0.1:$PORT" > "$WORK/php.log" 2>&1 &)
for i in $(seq 1 30); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/setup.php" && break
  sleep 1
done

status=0
python3 "$REPO/tests/setup/setup.py" --base "http://127.0.0.1:$PORT" --app "$WORK/app" --repo "$REPO" || status=$?
if [ $status -ne 0 ] || grep -qi "warning\|fatal\|deprecated" "$WORK/php.log"; then
  echo "--- PHP log"
  grep -i "warning\|error\|deprecated" "$WORK/php.log" | sort | uniq -c | sort -rn | head -40 || true
  grep -qi "warning\|fatal\|deprecated" "$WORK/php.log" && status=1
fi
exit $status
