#!/usr/bin/env bash
set -euo pipefail
ZIP="${1:-release/horus-media-platform.zip}"
test -s "$ZIP"
unzip -t "$ZIP" >/dev/null
required=(
  'horus-media-platform/vendor/autoload.php'
  'horus-media-platform/public/build/manifest.json'
  'horus-media-platform/public/assets/hm-loader.min.js'
  'horus-media-platform/public/assets/prebid/horus-prebid.min.js'
  'horus-media-platform/database/migrations/2026_07_30_000000_create_production_operations_tables.php'
  'horus-media-platform/.env.example'
  'horus-media-platform/release/INSTALLATION.md'
)
for file in "${required[@]}"; do unzip -Z1 "$ZIP" | grep -Fxq "$file" || { echo "Missing $file" >&2; exit 1; }; done
for forbidden_file in '.env' 'database/database.sqlite' 'phpunit.xml' 'package.json' 'package-lock.json' 'vite.config.js'; do
  if unzip -Z1 "$ZIP" | grep -Fxq "horus-media-platform/$forbidden_file"; then echo "Forbidden release entry: $forbidden_file" >&2; exit 1; fi
done
for forbidden_dir in 'node_modules/' 'tests/' '.git/' '.github/'; do
  if unzip -Z1 "$ZIP" | grep -Fq "horus-media-platform/$forbidden_dir"; then echo "Forbidden release entry: $forbidden_dir" >&2; exit 1; fi
done
if unzip -p "$ZIP" 'horus-media-platform/.env.example' | grep -Eq '(^|_)(PASSWORD|SECRET|TOKEN|KEY)=.+$'; then
  echo 'Environment template appears to contain a populated secret.' >&2; exit 1
fi
sha256sum -c release/CHECKSUMS.txt
