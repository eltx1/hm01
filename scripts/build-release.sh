#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE="$ROOT/release"
ZIP="$RELEASE/horus-media-platform.zip"
STAGING="$(mktemp -d)"
PACKAGE="$STAGING/horus-media-platform"
trap 'rm -rf "$STAGING"' EXIT
rm -f "$ZIP" "$RELEASE/CHECKSUMS.txt"
mkdir -p "$PACKAGE"
copy_path() { local source="$1"; local target="${2:-$1}"; mkdir -p "$(dirname "$PACKAGE/$target")"; cp -a "$ROOT/$source" "$PACKAGE/$target"; }
for path in app bootstrap config routes vendor; do copy_path "$path"; done
copy_path artisan; copy_path composer.json; copy_path composer.lock; copy_path .env.production.example .env.example
mkdir -p "$PACKAGE/database"; copy_path database/migrations database/migrations; copy_path database/seeders database/seeders
copy_path resources/views resources/views
if [[ -d "$ROOT/resources/lang" ]]; then copy_path resources/lang resources/lang; fi
copy_path public public
rm -f "$PACKAGE/public/hot"; rm -rf "$PACKAGE/public/cdn/configs"; mkdir -p "$PACKAGE/public/cdn/configs"; cp -a "$ROOT/public/cdn/configs/control.json" "$PACKAGE/public/cdn/configs/control.json"
mkdir -p "$PACKAGE/cdn/assets/prebid" "$PACKAGE/cdn/configs"
cp -a "$ROOT/public/assets/hm-loader.js" "$ROOT/public/assets/hm-loader.min.js" "$PACKAGE/cdn/"
cp -a "$ROOT/public/assets/prebid/horus-prebid.js" "$ROOT/public/assets/prebid/horus-prebid.min.js" "$ROOT/public/assets/prebid/horus-prebid.sha256" "$PACKAGE/cdn/assets/prebid/"
cp -a "$ROOT/public/cdn/configs/control.json" "$PACKAGE/cdn/configs/control.json"
mkdir -p "$PACKAGE/storage/app/private/credentials" "$PACKAGE/storage/framework/cache/data" "$PACKAGE/storage/framework/sessions" "$PACKAGE/storage/framework/views" "$PACKAGE/storage/logs" "$PACKAGE/bootstrap/cache"
printf '%s\n' '*' '!.gitignore' > "$PACKAGE/storage/app/private/credentials/.gitignore"; printf '%s\n' 'Require all denied' > "$PACKAGE/storage/app/private/credentials/.htaccess"
for directory in "$PACKAGE/storage/framework/cache/data" "$PACKAGE/storage/framework/sessions" "$PACKAGE/storage/framework/views" "$PACKAGE/storage/logs" "$PACKAGE/bootstrap/cache"; do printf '%s\n' '*' '!.gitignore' > "$directory/.gitignore"; done
for doc in INSTALLATION UPGRADE ROLLBACK CRON_JOBS GAM_SETUP PREBID_SETUP CLOUDFLARE_SETUP SECURITY_REPORT TEST_REPORT PILOT_PLAN; do copy_path "release/$doc.md" "$doc.md"; done
find "$PACKAGE" -type f \( -name '.env' -o -name '*.log' -o -name '*.sqlite' -o -name '*.sql' -o -name '*.bak' \) -delete
find "$PACKAGE" -type d -name node_modules -prune -exec rm -rf {} +
(cd "$STAGING" && zip -q -r -9 "$ZIP" horus-media-platform)
(cd "$ROOT" && sha256sum release/horus-media-platform.zip public/assets/hm-loader.min.js public/assets/prebid/horus-prebid.min.js public/build/manifest.json > release/CHECKSUMS.txt)
echo "Built $ZIP"
