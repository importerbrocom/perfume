#!/usr/bin/env bash
# Builds the release archive that server/platform.sh installs on cPanel:
#   dist/pmshop.tar.gz  +  dist/pmshop.tar.gz.sha256
# Contains the Laravel app with production vendor/ and the built React shop. No .env, no tests, no dev tools.
# Usage (from the repo root):  bash platform/scripts/package.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT=${OUT:-$ROOT/dist}
VERSION=${VERSION:-$(date -u +%Y%m%d-%H%M%S)-$(git -C "$ROOT" rev-parse --short=8 HEAD 2>/dev/null || echo local)}
BUILD=$(mktemp -d); trap 'rm -rf "$BUILD"' EXIT
APP="$BUILD/pmshop"

echo "==> Building the shop (React)"
( cd "$ROOT/platform/frontend" && { [ -d node_modules ] || npm ci --no-audit --no-fund; } && npm run build )
test -f "$ROOT/platform/backend/resources/spa/index.html"

echo "==> Copying the app"
mkdir -p "$APP"
tar -C "$ROOT/platform/backend" \
  --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./.env --exclude='./.env.*' --exclude=./.phpunit.cache \
  --exclude=./phpunit.xml --exclude=./.phpunit.result.cache --exclude='*.sqlite' --exclude=./storage/logs --exclude=./storage/framework --exclude=./storage/app \
  --exclude=./public/storage --exclude='./bootstrap/cache/*.php' --exclude=./.git -cf - . | tar -C "$APP" -xf -
cp "$ROOT/platform/backend/.env.example" "$APP/.env.example"
# Server scripts travel with the release (platform.sh updates itself from here; export-wp.php reads WordPress)
cp "$ROOT/server/platform.sh" "$ROOT/server/export-wp.php" "$APP/deploy/"

echo "==> Production PHP packages"
( cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --no-scripts -q )
( cd "$APP" && composer dump-autoload --optimize --no-dev --no-scripts -q )

# Empty storage skeleton (the server keeps the real storage outside the releases)
for d in app/public app/private framework/cache/data framework/sessions framework/views framework/testing logs; do mkdir -p "$APP/storage/$d"; done
mkdir -p "$APP/bootstrap/cache"
printf '%s\n' "$VERSION" > "$APP/VERSION"

# Sanity: the app boots and lists its routes without a database
( cd "$APP" && APP_KEY=base64:$(head -c 32 /dev/urandom | base64) DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array \
  php artisan route:list --path=api/store >/dev/null ) || { echo "Packaged app does not boot" >&2; exit 1; }
rm -f "$APP"/bootstrap/cache/*.php

echo "==> Archive"
mkdir -p "$OUT"
tar -C "$BUILD" -czf "$OUT/pmshop.tar.gz" pmshop
( cd "$OUT" && sha256sum pmshop.tar.gz > pmshop.tar.gz.sha256 )
echo "$VERSION" > "$OUT/VERSION"
echo "Built $OUT/pmshop.tar.gz ($(du -h "$OUT/pmshop.tar.gz" | cut -f1)), version $VERSION"
