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
  'horus-media-platform/database/migrations/2026_08_01_000000_create_static_delivery_tables.php'
  'horus-media-platform/.env.example'
  'horus-media-platform/release/INSTALLATION.md'
  'horus-media-platform/release/UPGRADE.md'
  'horus-media-platform/release/ROLLBACK.md'
  'horus-media-platform/release/CRON_JOBS.md'
  'horus-media-platform/release/GAM_SETUP.md'
  'horus-media-platform/release/PREBID_SETUP.md'
  'horus-media-platform/release/CLOUDFLARE_SETUP.md'
  'horus-media-platform/release/SECURITY_REPORT.md'
  'horus-media-platform/release/TEST_REPORT.md'
  'horus-media-platform/release/PILOT_PLAN.md'
)
for file in "${required[@]}"; do
  unzip -Z1 "$ZIP" | grep -Fxq "$file" || { echo "Missing $file" >&2; exit 1; }
done

for forbidden_file in \
  '.env' '.env.production.example' '.editorconfig' '.gitattributes' '.gitignore' \
  '.phpunit.result.cache' 'AGENTS.md' 'README.md' 'database/database.sqlite' \
  'phpunit.xml' 'package.json' 'package-lock.json' 'vite.config.js'; do
  if unzip -Z1 "$ZIP" | grep -Fxq "horus-media-platform/$forbidden_file"; then
    echo "Forbidden release entry: $forbidden_file" >&2
    exit 1
  fi
done

for forbidden_dir in \
  'node_modules/' 'tests/' '.git/' '.github/' 'design/' 'docs/' 'ops/' 'scripts/' \
  'cloudflare-pages-dist/' \
  'resources/js/' 'resources/css/' 'resources/prebid/' 'public/cdn/' \
  'storage/framework/testing/'; do
  if unzip -Z1 "$ZIP" | grep -Fq "horus-media-platform/$forbidden_dir"; then
    echo "Forbidden release entry: $forbidden_dir" >&2
    exit 1
  fi
done

if unzip -Z1 "$ZIP" | grep -Eq '^horus-media-platform/bootstrap/cache/[^/]+\.php$'; then
  echo 'Release must not contain Laravel bootstrap cache files generated on the CI host.' >&2
  exit 1
fi

env_template="$(unzip -p "$ZIP" 'horus-media-platform/.env.example')"
if printf '%s\n' "$env_template" | grep -Eq '(^|_)(PASSWORD|SECRET|TOKEN|KEY)=.+$'; then
  echo 'Environment template appears to contain a populated secret.' >&2
  exit 1
fi

for required_line in \
  'SESSION_COOKIE=horus-media-session' \
  'DB_CACHE_TABLE=cache' \
  'DB_CACHE_LOCK_TABLE=cache_locks' \
  'AUTH_EMAIL_VERIFICATION_REQUIRED=false' \
  'AUTH_ADMIN_2FA_REQUIRED=false'; do
  if ! printf '%s\n' "$env_template" | grep -Fxq "$required_line"; then
    echo "Production environment template is missing required line: $required_line" >&2
    exit 1
  fi
done

if printf '%s\n' "$env_template" | grep -Eq '^(SESSION_COOKIE|DB_CACHE_TABLE|DB_CACHE_LOCK_TABLE|MYSQL_ATTR_SSL_CA)=\s*$'; then
  echo 'Production environment template contains a runtime-critical blank value.' >&2
  exit 1
fi

sha256sum -c release/CHECKSUMS.txt
