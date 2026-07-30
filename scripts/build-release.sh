#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="${RUNNER_TEMP:-/tmp}/horus-media-release-stage"
OUT="$ROOT/release/horus-media-platform.zip"
rm -rf "$STAGE" "$OUT"
mkdir -p "$STAGE/horus-media-platform"
rsync -a "$ROOT/" "$STAGE/horus-media-platform/" \
  --exclude '.git/' --exclude '.github/' --exclude '.env' --exclude 'node_modules/' \
  --exclude 'tests/' --exclude 'phpunit.xml' --exclude 'package.json' --exclude 'package-lock.json' --exclude 'vite.config.js' --exclude 'resources/js/' --exclude 'resources/css/' --exclude 'resources/prebid/' --exclude 'release/horus-media-platform.zip' --exclude 'release/CHECKSUMS.txt' \
  --exclude 'database/database.sqlite' --exclude 'public/cdn/' --exclude 'storage/logs/*' --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*'
cp "$ROOT/.env.production.example" "$STAGE/horus-media-platform/.env.example"
find "$STAGE/horus-media-platform" -type f \( -name '*.log' -o -name '.DS_Store' \) -delete
find "$STAGE/horus-media-platform/storage" -type f ! -name '.gitignore' ! -name '.htaccess' -delete 2>/dev/null || true
(
  cd "$STAGE"
  zip -qr "$OUT" horus-media-platform
)
sha256sum "$OUT" > "$ROOT/release/CHECKSUMS.txt"
