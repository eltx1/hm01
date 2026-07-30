#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; ZIP="$ROOT/release/horus-media-platform.zip"; CHECKSUMS="$ROOT/release/CHECKSUMS.txt"; TEMP="$(mktemp -d)"; trap 'rm -rf "$TEMP"' EXIT
[[ -s "$ZIP" ]] || { echo 'Release ZIP is missing or empty.' >&2; exit 1; }
[[ -s "$CHECKSUMS" ]] || { echo 'CHECKSUMS.txt is missing or empty.' >&2; exit 1; }
(cd "$ROOT" && sha256sum --check release/CHECKSUMS.txt)
unzip -q "$ZIP" -d "$TEMP"; PACKAGE="$TEMP/horus-media-platform"
required=(vendor/autoload.php artisan composer.json composer.lock .env.example public/index.php public/build/manifest.json public/assets/hm-loader.min.js public/assets/prebid/horus-prebid.min.js public/cdn/configs/control.json cdn/hm-loader.min.js cdn/assets/prebid/horus-prebid.min.js cdn/configs/control.json database/migrations INSTALLATION.md UPGRADE.md ROLLBACK.md CRON_JOBS.md GAM_SETUP.md PREBID_SETUP.md CLOUDFLARE_SETUP.md SECURITY_REPORT.md TEST_REPORT.md PILOT_PLAN.md)
for path in "${required[@]}"; do [[ -e "$PACKAGE/$path" ]] || { echo "Missing required release path: $path" >&2; exit 1; }; done
if unzip -Z1 "$ZIP" | grep -Eq '(^|/)(\.git|\.github|node_modules|tests)(/|$)|(^|/)\.env$|\.log$|\.sqlite$'; then echo 'Release contains a forbidden development or secret path.' >&2; exit 1; fi
if grep -RIlE --exclude='.env.example' 'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|sk-proj-[A-Za-z0-9_-]{20,}|AIza[0-9A-Za-z_-]{30,}' "$PACKAGE/app" "$PACKAGE/config" "$PACKAGE/routes" "$PACKAGE/database" "$PACKAGE/public" >/dev/null; then echo 'Release secret scan detected a credential-like value.' >&2; exit 1; fi
php -r "require '$PACKAGE/vendor/autoload.php'; echo 'Production autoloader OK'.PHP_EOL;"
php "$PACKAGE/artisan" --version
echo 'Production release ZIP validation passed.'
