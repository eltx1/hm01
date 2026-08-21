#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_SCRIPT="$ROOT/ops/deploy/horus-atomic-deploy.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

assert_eq() {
    [[ "$1" == "$2" ]] || fail "Expected '$2', got '$1'"
}

assert_file_contains() {
    grep -Fq -- "$2" "$1" || fail "Expected $1 to contain: $2"
}

create_fake_php() {
    local bin_dir="$1"
    mkdir -p "$bin_dir"
    cat > "$bin_dir/php" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
script="$1"
shift
if [[ "$script" != */* ]]; then
    script="./$script"
fi
exec "$script" "$@"
SH
    chmod +x "$bin_dir/php"
}

create_fake_app() {
    local root="$1"
    mkdir -p "$root/vendor" "$root/public" "$root/bootstrap/cache"
    : > "$root/vendor/autoload.php"
    : > "$root/public/index.php"
    cat > "$root/artisan" <<'SH'
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
    chmod +x "$root/artisan"
}

prepare_environment() {
    local name="$1"
    local base="$TMP/$name"
    local app_home="$base/home"
    local old_release="$app_home/releases/old-release"
    local current="$app_home/htdocs/app.horusmedia.net"
    local shared="$app_home/shared"
    local package="$base/horus-media-platform.zip"
    local package_stage="$base/package/horus-media-platform"

    mkdir -p "$app_home/releases" "$app_home/htdocs" "$shared/storage/app/public" \
        "$shared/storage/app/private" "$shared/storage/framework/cache/data" \
        "$shared/storage/framework/sessions" "$shared/storage/framework/views" "$shared/storage/logs"
    printf 'APP_ENV=production\nAPP_KEY=base64:test\n' > "$shared/.env"

    create_fake_app "$old_release"
    rm -rf "$old_release/storage"
    ln -s "$shared/storage" "$old_release/storage"
    ln -s "$shared/.env" "$old_release/.env"
    ln -s "$old_release" "$current"

    create_fake_app "$package_stage"
    (
        cd "$base/package"
        zip -qr "$package" horus-media-platform
    )

    printf '%s\n' "$base|$app_home|$old_release|$current|$package"
}

create_fake_php "$TMP/bin"
FAKE_PHP="$TMP/bin/php"
cat > "$TMP/bin/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${HORUS_TEST_CURL_LOG:?}"
SH
chmod +x "$TMP/bin/curl"

# Successful deployment must build Laravel caches only after the release has its
# immutable final path, switch the symlink atomically, and preserve shared state.
IFS='|' read -r SUCCESS_BASE SUCCESS_HOME SUCCESS_OLD SUCCESS_CURRENT SUCCESS_ZIP <<< "$(prepare_environment success)"
SUCCESS_LOG="$SUCCESS_BASE/artisan.log"
SUCCESS_CURL_LOG="$SUCCESS_BASE/curl.log"
: > "$SUCCESS_LOG"
: > "$SUCCESS_CURL_LOG"
SUCCESS_SHA="$(sha256sum "$SUCCESS_ZIP" | awk '{print $1}')"

PATH="$TMP/bin:$PATH" \
HORUS_TEST_ARTISAN_LOG="$SUCCESS_LOG" \
HORUS_TEST_CURL_LOG="$SUCCESS_CURL_LOG" \
HORUS_DEPLOY_HOME="$SUCCESS_HOME" \
HORUS_DEPLOY_PHP_BIN="$FAKE_PHP" \
HORUS_DEPLOY_FPM_RELOAD_COMMAND=true \
HORUS_DEPLOY_HEALTH_INSECURE_TLS=1 \
HORUS_DEPLOY_SKIP_BACKUP=1 \
HORUS_DEPLOY_KEEP_RELEASES=5 \
HORUS_DEPLOY_HEALTH_ATTEMPTS=1 \
HORUS_RELEASE_ID=release-success \
bash "$DEPLOY_SCRIPT" "$SUCCESS_ZIP" "$SUCCESS_SHA"

SUCCESS_NEW="$SUCCESS_HOME/releases/release-success"
assert_eq "$(readlink -f "$SUCCESS_CURRENT")" "$SUCCESS_NEW"
assert_eq "$(readlink -f "$SUCCESS_NEW/.env")" "$SUCCESS_HOME/shared/.env"
assert_eq "$(readlink -f "$SUCCESS_NEW/storage")" "$SUCCESS_HOME/shared/storage"
assert_file_contains "$SUCCESS_NEW/bootstrap/cache/config.php" "$SUCCESS_NEW"
if grep -Fq '/.staging-' "$SUCCESS_NEW/bootstrap/cache/config.php"; then
    fail 'Successful release cache contains a staging path.'
fi
assert_file_contains "$SUCCESS_LOG" "$SUCCESS_NEW|migrate --force"
assert_file_contains "$SUCCESS_LOG" "$SUCCESS_NEW|optimize "
assert_file_contains "$SUCCESS_CURL_LOG" '--resolve app.horusmedia.net:443:127.0.0.1'
assert_file_contains "$SUCCESS_CURL_LOG" '--insecure'
[[ ! -e "$SUCCESS_HOME/shared/storage/framework/down" ]] || fail 'Maintenance mode remained enabled after success.'

# Invalid flag values must fail before a release is prepared or the active
# application is changed.
IFS='|' read -r INVALID_BASE INVALID_HOME INVALID_OLD INVALID_CURRENT INVALID_ZIP <<< "$(prepare_environment invalid-flag)"
INVALID_LOG="$INVALID_BASE/artisan.log"
: > "$INVALID_LOG"
INVALID_SHA="$(sha256sum "$INVALID_ZIP" | awk '{print $1}')"

set +e
HORUS_TEST_ARTISAN_LOG="$INVALID_LOG" \
HORUS_DEPLOY_HOME="$INVALID_HOME" \
HORUS_DEPLOY_PHP_BIN="$FAKE_PHP" \
HORUS_DEPLOY_FPM_RELOAD_COMMAND=true \
HORUS_DEPLOY_HEALTH_INSECURE_TLS=true \
HORUS_DEPLOY_SKIP_BACKUP=1 \
HORUS_RELEASE_ID=release-invalid-flag \
bash "$DEPLOY_SCRIPT" "$INVALID_ZIP" "$INVALID_SHA"
invalid_status=$?
set -e

(( invalid_status != 0 )) || fail 'Invalid TLS flag unexpectedly passed validation.'
assert_eq "$(readlink -f "$INVALID_CURRENT")" "$INVALID_OLD"
[[ ! -e "$INVALID_HOME/releases/release-invalid-flag" ]] || fail 'Invalid TLS flag prepared a release.'
[[ ! -e "$INVALID_HOME/shared/storage/framework/down" ]] || fail 'Invalid TLS flag enabled maintenance mode.'

# A failed post-switch health check must restore the previous release and clear
# maintenance mode without attempting a destructive database rollback.
IFS='|' read -r FAIL_BASE FAIL_HOME FAIL_OLD FAIL_CURRENT FAIL_ZIP <<< "$(prepare_environment rollback)"
FAIL_LOG="$FAIL_BASE/artisan.log"
COUNTER="$FAIL_BASE/health-counter"
: > "$FAIL_LOG"
printf '0\n' > "$COUNTER"
cat > "$FAIL_BASE/health.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
n="$(cat "${HORUS_TEST_HEALTH_COUNTER:?}")"
n=$((n + 1))
printf '%s\n' "$n" > "$HORUS_TEST_HEALTH_COUNTER"
if (( n == 1 )); then
    exit 1
fi
exit 0
SH
chmod +x "$FAIL_BASE/health.sh"
FAIL_SHA="$(sha256sum "$FAIL_ZIP" | awk '{print $1}')"

set +e
HORUS_TEST_ARTISAN_LOG="$FAIL_LOG" \
HORUS_TEST_HEALTH_COUNTER="$COUNTER" \
HORUS_DEPLOY_HOME="$FAIL_HOME" \
HORUS_DEPLOY_PHP_BIN="$FAKE_PHP" \
HORUS_DEPLOY_FPM_RELOAD_COMMAND=true \
HORUS_DEPLOY_HEALTHCHECK_COMMAND="$FAIL_BASE/health.sh" \
HORUS_DEPLOY_SKIP_BACKUP=1 \
HORUS_DEPLOY_KEEP_RELEASES=5 \
HORUS_DEPLOY_HEALTH_ATTEMPTS=1 \
HORUS_RELEASE_ID=release-fails-health \
bash "$DEPLOY_SCRIPT" "$FAIL_ZIP" "$FAIL_SHA"
status=$?
set -e

(( status != 0 )) || fail 'Deployment unexpectedly succeeded despite a failing new-release health check.'
assert_eq "$(readlink -f "$FAIL_CURRENT")" "$FAIL_OLD"
[[ ! -e "$FAIL_HOME/shared/storage/framework/down" ]] || fail 'Maintenance mode remained enabled after rollback.'
assert_file_contains "$FAIL_LOG" "$FAIL_OLD|up "

echo 'Atomic deployment regression tests passed.'
