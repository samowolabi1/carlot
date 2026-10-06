#!/usr/bin/env bash
# Builds build/caryard-cpanel.zip: the app with production PHP packages (vendor), the built front end (public/build)
# and the admin panel's assets, ready to upload to cPanel. Needs PHP 8.3+, Composer, Node 20+ and zip on the machine
# that builds it (your PC or GitHub Actions), not on the server. See docs/deploy-cpanel.md.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
OUT="$ROOT/build"
STAGE="$OUT/caryard"

echo "→ Building the front end"
# Browser-side settings are baked in at build time: no websocket server on cPanel (chat polls).
npm ci --no-audit --no-fund
VITE_REVERB_APP_KEY= npm run build

echo "→ Preparing a clean copy"
rm -rf "$STAGE" "$OUT/caryard-cpanel.zip"
mkdir -p "$STAGE"
git archive HEAD | tar -x -C "$STAGE"
cp -R public/build "$STAGE/public/build"

echo "→ Installing production PHP packages"
(cd "$STAGE" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)
# If Composer had to clone a package instead of downloading it, drop the clone's history.
find "$STAGE/vendor" -type d \( -name .git -o -name .github \) -prune -exec rm -rf {} +

echo "→ Copying the admin panel's assets"
php artisan filament:assets -q
for dir in css/filament js/filament fonts/filament; do
    [ -d "public/$dir" ] && mkdir -p "$STAGE/public/$(dirname "$dir")" && cp -R "public/$dir" "$STAGE/public/$dir"
done

echo "→ Removing what the server doesn't need"
(cd "$STAGE" && rm -rf tests loadtest .github node_modules phpunit.xml vite.config.* tsconfig.json eslint.config.* .prettier* \
    package.json package-lock.json resources/js resources/css storage/logs/*.log)
mkdir -p "$STAGE/storage/framework/"{cache/data,sessions,views} "$STAGE/storage/logs" "$STAGE/storage/app/private" "$STAGE/storage/app/public" "$STAGE/bootstrap/cache"

echo "→ Zipping"
(cd "$OUT" && zip -qr caryard-cpanel.zip caryard)
echo "Done: build/caryard-cpanel.zip ($(du -h "$OUT/caryard-cpanel.zip" | cut -f1))"
