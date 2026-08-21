#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BOOTSTRAP_SCRIPT="$ROOT/ops/deploy/horus-bootstrap-atomic-layout.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

assert_eq() {
    [[ "$1" == "$2" ]] || fail "Expected '$2', got '$1'"
}

BIN="$TMP/bin"
APP_HOME="$TMP/home"
CURRENT="$APP_HOME/htdocs/app.horusmedia.net"
ARTISAN_LOG="$TMP/artisan.log"

mkdir -p "$BIN" "$CURRENT/vendor" "$CURRENT/public" "$CURRENT/bootstrap/cache" \
    "$CURRENT/storage/app/public" "$CURRENT/storage/app/private" \
    "$CURRENT/storage/framework/cache/data" "$CURRENT/storage/framework/sessions" \
    "$CURRENT/storage/framework/views" "$CURRENT/storage/logs"

cat > "$BIN/php" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
script="$1"
shift
if [[ "$script" != */* ]]; then
    script="./$script"
fi
exec "$script" "$@"
SH
chmod +x "$BIN/php"

cat > "$CURRENT/artisan" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
cmd="${1:-}"
shift || true
printf '%s|%s %s\n' "$PWD" "$cmd" "$*" >> "${HORUS_TEST_ARTISAN_LOG:?}"
case "$cmd" in
    optimize:clear)
        mkdir -p bootstrap/cache
        rm -f bootstrap/cache/*
        ;;
    optimize)
        mkdir -p bootstrap/cache
        printf '%s\n' "$PWD" > bootstrap/cache/config.php
        ;;
    storage:link)
        mkdir -p public
        rm -rf public/storage
        ln -s "$PWD/storage/app/public" public/storage
        ;;
    down)
        mkdir -p storage/framework
        : > storage/framework/down
        ;;
    up)
        rm -f storage/framework/down
        ;;
esac
SH
chmod +x "$CURRENT/artisan"
: > "$CURRENT/vendor/autoload.php"
: > "$CURRENT/public/index.php"
printf 'APP_ENV=production\nAPP_KEY=base64:test\n' > "$CURRENT/.env"
: > "$ARTISAN_LOG"

HORUS_TEST_ARTISAN_LOG="$ARTISAN_LOG" \
HORUS_DEPLOY_HOME="$APP_HOME" \
HORUS_DEPLOY_PHP_BIN="$BIN/php" \
HORUS_DEPLOY_FPM_RELOAD_COMMAND=true \
HORUS_DEPLOY_HEALTHCHECK_COMMAND=true \
HORUS_BOOTSTRAP_CONFIRMED_BACKUP=1 \
bash "$BOOTSTRAP_SCRIPT"

[[ -L "$CURRENT" ]] || fail 'Current application path was not converted to a symlink.'
TARGET="$(readlink -f "$CURRENT")"
[[ "$TARGET" == "$APP_HOME/releases/bootstrap-"* ]] || fail "Unexpected release target: $TARGET"
assert_eq "$(readlink -f "$TARGET/.env")" "$APP_HOME/shared/.env"
assert_eq "$(readlink -f "$TARGET/storage")" "$APP_HOME/shared/storage"
assert_eq "$(readlink -f "$TARGET/public/storage")" "$APP_HOME/shared/storage/app/public"
grep -Fq "$TARGET" "$TARGET/bootstrap/cache/config.php" || fail 'Bootstrap cache was not generated in the final release path.'
[[ ! -e "$APP_HOME/shared/storage/framework/down" ]] || fail 'Maintenance mode remained enabled after bootstrap.'
grep -Fq "$TARGET|optimize " "$ARTISAN_LOG" || fail 'Final optimize did not run from the immutable release path.'

echo 'Atomic layout bootstrap regression test passed.'
