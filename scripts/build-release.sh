#!/bin/bash
# Builds the update archive of a version, as installed by the admin updater:
#
#   scripts/build-release.sh <version> <output dir>
#
# Produces vigilo-backend-<version>.zip (app/ + manifest.json), SHA256SUMS and, when
# VIGILO_RELEASE_SIGNING_KEY holds an ed25519 secret key (base64, see
# scripts/release-keygen.php), vigilo-backend-<version>.zip.sig.
set -euo pipefail

VERSION=$1
OUT=$(mkdir -p "$2" && cd "$2" && pwd)
REPO=$(cd "$(dirname "$0")/.." && pwd)

CODE_VERSION=$(sed -n "s/^define('BACKEND_VERSION', '\([0-9.]*\)');/\1/p" "$REPO/app/includes/version.php")
if [ "$VERSION" != "$CODE_VERSION" ]; then
  echo "Version $VERSION does not match app/includes/version.php ($CODE_VERSION)" >&2
  exit 1
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/app"
cp -r "$REPO/app/." "$STAGE/app/"
# Instance data never ships in an update
rm -f "$STAGE/app/config/config.php" "$STAGE/app/install.php"
find "$STAGE/app/images" "$STAGE/app/caches" "$STAGE/app/maps" -mindepth 1 \
  ! -name 'index.html' ! -name '.htaccess' -exec rm -rf {} + 2> /dev/null || true

OBSOLETE=$(grep -v '^#' "$REPO/scripts/obsolete-paths.txt" | sed '/^$/d' | sed 's/.*/"&"/' | paste -sd, -)

cat > "$STAGE/manifest.json" <<JSON
{
  "version": "$VERSION",
  "obsolete_paths": [$OBSOLETE],
  "min_php": "7.3",
  "required_extensions": ["mysqli", "gd", "curl", "json"],
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

ARCHIVE="vigilo-backend-$VERSION.zip"
rm -f "$OUT/$ARCHIVE"
(cd "$STAGE" && TZ=UTC find . -exec touch -d '2000-01-01 00:00:00' {} + && zip -qrX "$OUT/$ARCHIVE" manifest.json app)
(cd "$OUT" && sha256sum "$ARCHIVE" > SHA256SUMS)

if [ -n "${VIGILO_RELEASE_SIGNING_KEY:-}" ]; then
  php -r '
    $key = base64_decode(getenv("VIGILO_RELEASE_SIGNING_KEY"), true);
    if (!$key || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "Invalid signing key\n"); exit(1); }
    echo base64_encode(sodium_crypto_sign_detached(file_get_contents($argv[1]), $key)), "\n";
  ' "$OUT/$ARCHIVE" > "$OUT/$ARCHIVE.sig"
  echo "Signed $ARCHIVE"
fi

ls -la "$OUT"
