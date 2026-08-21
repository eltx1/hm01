#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP_HOME="${HORUS_DEPLOY_HOME:-/home/horusapp}"
CURRENT_PATH="${HORUS_DEPLOY_CURRENT_LINK:-$APP_HOME/htdocs/app.horusmedia.net}"
RELEASES_DIR="${HORUS_DEPLOY_RELEASES_DIR:-$APP_HOME/releases}"
SHARED_DIR="${HORUS_DEPLOY_SHARED_DIR:-$APP_HOME/shared}"
SHARED_ENV="${HORUS_DEPLOY_SHARED_ENV:-$SHARED_DIR/.env}"
SHARED_STORAGE="${HORUS_DEPLOY_SHARED_STORAGE:-$SHARED_DIR/storage}"
LOGS_DIR="${HORUS_DEPLOY_LOGS_DIR:-$APP_HOME/logs}"
PHP_BIN="${HORUS_DEPLOY_PHP_BIN:-/usr/bin/php8.4}"
FPM_RELOAD_COMMAND="${HORUS_DEPLOY_FPM_RELOAD_COMMAND:-sudo -n /usr/bin/systemctl reload php8.4-fpm}"
HEALTH_URL="${HORUS_DEPLOY_HEALTH_URL:-https://app.horusmedia.net/up}"
HEALTH_RESOLVE_IP="${HORUS_DEPLOY_HEALTH_RESOLVE_IP:-127.0.0.1}"
BACKUP_CONFIRMED="${HORUS_BOOTSTRAP_CONFIRMED_BACKUP:-0}"
LOCK_FILE="$APP_HOME/.horus-deploy.lock"

RELEASE_ID="bootstrap-$(date -u '+%Y%m%dT%H%M%SZ')"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
MOVED=0
LINKED=0

log() {
    printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

die() {
    log "ERROR: $*"
    return 1
}

run_artisan_in() {
    local root="$1"
    shift
    (
        cd "$root"
        "$PHP_BIN" artisan "$@"
    )
}

reload_fpm() {
    bash -lc "$FPM_RELOAD_COMMAND"
}

health_check() {
    local scheme rest authority host port
    scheme="${HEALTH_URL%%://*}"
    rest="${HEALTH_URL#*://}"
    authority="${rest%%/*}"
    host="${authority%%:*}"

    if [[ "$authority" == *:* ]]; then
        port="${authority##*:}"
    elif [[ "$scheme" == 'https' ]]; then
        port=443
    else
        port=80
    fi

    local args=(-fsS --max-time 12)
    if [[ -n "$HEALTH_RESOLVE_IP" ]]; then
        args+=(--resolve "${host}:${port}:${HEALTH_RESOLVE_IP}")
    fi

    curl "${args[@]}" "$HEALTH_URL" >/dev/null
}

rollback_bootstrap() {
    local status="$1"
    trap - EXIT

    if (( status != 0 )); then
        log 'Atomic-layout bootstrap failed; restoring the original directory layout.'

        if (( LINKED == 1 )) && [[ -L "$CURRENT_PATH" ]]; then
            rm -f "$CURRENT_PATH"
        fi

        if (( MOVED == 1 )) && [[ -d "$RELEASE_DIR" ]]; then
            if [[ -L "$RELEASE_DIR/.env" ]]; then
                rm -f "$RELEASE_DIR/.env"
                if [[ -f "$SHARED_ENV" ]]; then
                    mv "$SHARED_ENV" "$RELEASE_DIR/.env"
                fi
            fi

            if [[ -L "$RELEASE_DIR/storage" ]]; then
                rm -f "$RELEASE_DIR/storage"
                if [[ -d "$SHARED_STORAGE" ]]; then
                    mv "$SHARED_STORAGE" "$RELEASE_DIR/storage"
                fi
            fi

            mv "$RELEASE_DIR" "$CURRENT_PATH" || true
            run_artisan_in "$CURRENT_PATH" optimize:clear || true
            run_artisan_in "$CURRENT_PATH" optimize || true
            run_artisan_in "$CURRENT_PATH" up || true
            reload_fpm || true
        fi
    fi

    exit "$status"
}

trap 'rollback_bootstrap $?' EXIT

[[ "$BACKUP_CONFIRMED" == '1' ]] || die 'Set HORUS_BOOTSTRAP_CONFIRMED_BACKUP=1 only after verified database, .env, and storage backups exist.'
[[ -d "$CURRENT_PATH" && ! -L "$CURRENT_PATH" ]] || die "Expected the current application to be a real directory: $CURRENT_PATH"
[[ -f "$CURRENT_PATH/.env" ]] || die 'Current application .env is missing.'
[[ -d "$CURRENT_PATH/storage" ]] || die 'Current application storage directory is missing.'
[[ -x "$PHP_BIN" ]] || die "PHP binary is not executable: $PHP_BIN"
[[ ! -e "$SHARED_ENV" ]] || die "Shared .env already exists: $SHARED_ENV"
[[ ! -e "$SHARED_STORAGE" ]] || die "Shared storage already exists: $SHARED_STORAGE"
[[ ! -e "$RELEASE_DIR" ]] || die "Bootstrap release already exists: $RELEASE_DIR"

command -v flock >/dev/null 2>&1 || die 'flock is required.'
command -v curl >/dev/null 2>&1 || die 'curl is required.'

mkdir -p "$RELEASES_DIR" "$SHARED_DIR" "$LOGS_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || die 'Another Horus deployment is already running.'

log "Converting current production directory into atomic release layout: $RELEASE_ID"
run_artisan_in "$CURRENT_PATH" down --retry=10

mv "$CURRENT_PATH" "$RELEASE_DIR"
MOVED=1

mv "$RELEASE_DIR/.env" "$SHARED_ENV"
chmod 600 "$SHARED_ENV"
mv "$RELEASE_DIR/storage" "$SHARED_STORAGE"

ln -s "$SHARED_ENV" "$RELEASE_DIR/.env"
ln -s "$SHARED_STORAGE" "$RELEASE_DIR/storage"

mkdir -p \
    "$SHARED_STORAGE/app/private" \
    "$SHARED_STORAGE/app/public" \
    "$SHARED_STORAGE/framework/cache/data" \
    "$SHARED_STORAGE/framework/sessions" \
    "$SHARED_STORAGE/framework/views" \
    "$SHARED_STORAGE/logs" \
    "$RELEASE_DIR/bootstrap/cache"
chmod -R u+rwX "$SHARED_STORAGE"
chmod u+rwx "$RELEASE_DIR/bootstrap/cache"

rm -rf "$RELEASE_DIR/public/storage"
run_artisan_in "$RELEASE_DIR" optimize:clear
run_artisan_in "$RELEASE_DIR" storage:link
run_artisan_in "$RELEASE_DIR" optimize

ln -s "$RELEASE_DIR" "$CURRENT_PATH"
LINKED=1
reload_fpm
run_artisan_in "$CURRENT_PATH" up

if ! health_check; then
    die "Bootstrap release failed health check: $HEALTH_URL"
fi

{
    printf 'release_id=%s\n' "$RELEASE_ID"
    printf 'deployed_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    printf 'origin=bootstrap-existing-production\n'
} > "$RELEASE_DIR/.horus-release"

log 'Atomic production layout is healthy.'
trap - EXIT
exit 0
