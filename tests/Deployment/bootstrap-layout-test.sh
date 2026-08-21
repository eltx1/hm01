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

create_app() {
    local current="$1"
    mkdir -p "$current/vendor" "$current/public" "$current/bootstrap/cache" \
        "$current/storage/app/public" "$current/storage/app/private" \
        "$current/storage/framework/cache/data" "$current/storage/framework/sessions" \
        "$current/storage/framework/views" "$current/storage/logs"

    cat > "$current/artisan" <<'SH'
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
    chmod +x "$current/artisan"
    : > "$current/vendor/autoload.php"
    : > "$current/public/index.php"
    printf 'APP_ENV=production\nAPP_KEY=base64:test\n' > "$current/.env"
}

BIN="$TMP/bin"
mkdir -p "$BIN"
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

# Successful conversion preserves runtime state and builds caches only after the
# application has reached its immutable release path.
APP_HOME="$TMP/home"
CURRENT="$APP_HOME/htdocs/app.horusmedia.net"
ARTISAN_LOG="$TMP/artisan.log"
create_app "$CURRENT"
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

# A failure after .env has moved but before storage can move must restore the
# original non-symlink layout with both runtime assets intact and maintenance off.
FAIL_HOME="$TMP/fail-home"
FAIL_CURRENT="$FAIL_HOME/htdocs/app.horusmedia.net"
FAIL_LOG="$TMP/fail-artisan.log"
FAIL_STORAGE_TARGET="/proc/horus-bootstrap-storage-$$"
create_app "$FAIL_CURRENT"
: > "$FAIL_LOG"

set +e
HORUS_TEST_ARTISAN_LOG="$FAIL_LOG" \
HORUS_DEPLOY_HOME="$FAIL_HOME" \
HORUS_DEPLOY_SHARED_STORAGE="$FAIL_STORAGE_TARGET" \
HORUS_DEPLOY_PHP_BIN="$BIN/php" \
HORUS_DEPLOY_FPM_RELOAD_COMMAND=true \
HORUS_DEPLOY_HEALTHCHECK_COMMAND=true \
HORUS_BOOTSTRAP_CONFIRMED_BACKUP=1 \
bash "$BOOTSTRAP_SCRIPT"
status=$?
set -e

(( status != 0 )) || fail 'Partial bootstrap failure unexpectedly succeeded.'
[[ -d "$FAIL_CURRENT" && ! -L "$FAIL_CURRENT" ]] || fail 'Original directory layout was not restored after partial failure.'
[[ -f "$FAIL_CURRENT/.env" ]] || fail '.env was not restored after partial bootstrap failure.'
[[ -d "$FAIL_CURRENT/storage" ]] || fail 'storage was not preserved after partial bootstrap failure.'
[[ ! -e "$FAIL_HOME/shared/.env" ]] || fail 'Shared .env was left behind after rollback.'
[[ ! -e "$FAIL_CURRENT/storage/framework/down" ]] || fail 'Maintenance mode remained enabled after partial rollback.'

echo 'Atomic layout bootstrap regression tests passed.'
