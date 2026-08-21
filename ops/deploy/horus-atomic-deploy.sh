#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ARTIFACT="${1:-}"
EXPECTED_SHA256="${2:-}"

APP_HOME="${HORUS_DEPLOY_HOME:-/home/horusapp}"
CURRENT_LINK="${HORUS_DEPLOY_CURRENT_LINK:-$APP_HOME/htdocs/app.horusmedia.net}"
RELEASES_DIR="${HORUS_DEPLOY_RELEASES_DIR:-$APP_HOME/releases}"
SHARED_DIR="${HORUS_DEPLOY_SHARED_DIR:-$APP_HOME/shared}"
SHARED_ENV="${HORUS_DEPLOY_SHARED_ENV:-$SHARED_DIR/.env}"
SHARED_STORAGE="${HORUS_DEPLOY_SHARED_STORAGE:-$SHARED_DIR/storage}"
BACKUPS_DIR="${HORUS_DEPLOY_BACKUPS_DIR:-$APP_HOME/backups}"
LOGS_DIR="${HORUS_DEPLOY_LOGS_DIR:-$APP_HOME/logs}"
PHP_BIN="${HORUS_DEPLOY_PHP_BIN:-/usr/bin/php8.4}"
FPM_RELOAD_COMMAND="${HORUS_DEPLOY_FPM_RELOAD_COMMAND:-sudo -n /usr/bin/systemctl reload php8.4-fpm}"
HEALTH_URL="${HORUS_DEPLOY_HEALTH_URL:-https://app.horusmedia.net/up}"
HEALTH_RESOLVE_IP="${HORUS_DEPLOY_HEALTH_RESOLVE_IP:-127.0.0.1}"
HEALTH_INSECURE_TLS="${HORUS_DEPLOY_HEALTH_INSECURE_TLS:-0}"
HEALTH_ATTEMPTS="${HORUS_DEPLOY_HEALTH_ATTEMPTS:-6}"
HEALTH_DELAY_SECONDS="${HORUS_DEPLOY_HEALTH_DELAY_SECONDS:-2}"
KEEP_RELEASES="${HORUS_DEPLOY_KEEP_RELEASES:-5}"
KEEP_BACKUPS="${HORUS_DEPLOY_KEEP_BACKUPS:-10}"
SKIP_BACKUP="${HORUS_DEPLOY_SKIP_BACKUP:-0}"
HEALTHCHECK_COMMAND="${HORUS_DEPLOY_HEALTHCHECK_COMMAND:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MYSQL_CONFIG_HELPER="${HORUS_DEPLOY_MYSQL_CONFIG_HELPER:-$SCRIPT_DIR/write-mysql-client-config.php}"
DEPLOY_LOG="$LOGS_DIR/horus-deploy.log"
LOCK_FILE="$APP_HOME/.horus-deploy.lock"

STAGING_DIR=""
RELEASE_DIR=""
PREVIOUS_TARGET=""
SWITCHED=0
MAINTENANCE_ENABLED=0

log() {
    printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"
}

die() {
    log "ERROR: $*"
    return 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
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
    if [[ -z "$FPM_RELOAD_COMMAND" ]]; then
        log 'PHP-FPM reload skipped by configuration.'
        return 0
    fi

    bash -lc "$FPM_RELOAD_COMMAND"
}

atomic_switch() {
    local target="$1"
    local temp_link="${CURRENT_LINK}.next.$$"

    rm -f "$temp_link"
    ln -s "$target" "$temp_link"
    mv -Tf "$temp_link" "$CURRENT_LINK"
}

health_check_once() {
    if [[ -n "$HEALTHCHECK_COMMAND" ]]; then
        bash -lc "$HEALTHCHECK_COMMAND"
        return
    fi

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

    local curl_args=(-fsS --max-time 12)
    if [[ -n "$HEALTH_RESOLVE_IP" ]]; then
        curl_args+=(--resolve "${host}:${port}:${HEALTH_RESOLVE_IP}")
    fi
    if [[ "$scheme" == 'https' && "$HEALTH_INSECURE_TLS" == '1' ]]; then
        curl_args+=(--insecure)
    fi

    curl "${curl_args[@]}" "$HEALTH_URL" >/dev/null
}

health_check() {
    local attempt
    for ((attempt = 1; attempt <= HEALTH_ATTEMPTS; attempt++)); do
        if health_check_once; then
            return 0
        fi
        if (( attempt < HEALTH_ATTEMPTS )); then
            sleep "$HEALTH_DELAY_SECONDS"
        fi
    done

    return 1
}

create_backup() {
    if [[ "$SKIP_BACKUP" == '1' ]]; then
        log 'Backup skipped explicitly.'
        return 0
    fi

    local backup_dir mysql_cnf db_name dump_bin
    backup_dir="$BACKUPS_DIR/$RELEASE_ID"
    mkdir -p "$backup_dir"

    cp -p "$SHARED_ENV" "$backup_dir/.env"
    chmod 600 "$backup_dir/.env"

    if [[ -d "$SHARED_STORAGE/app" ]]; then
        tar -C "$SHARED_STORAGE" -czf "$backup_dir/storage-app.tar.gz" app
    fi

    [[ -f "$MYSQL_CONFIG_HELPER" ]] || die "MySQL backup helper not found: $MYSQL_CONFIG_HELPER"
    mysql_cnf="$backup_dir/mysql-client.cnf"
    db_name="$("$PHP_BIN" "$MYSQL_CONFIG_HELPER" "$RELEASE_DIR" "$SHARED_ENV" "$mysql_cnf")"

    if command -v mariadb-dump >/dev/null 2>&1; then
        dump_bin="$(command -v mariadb-dump)"
    elif command -v mysqldump >/dev/null 2>&1; then
        dump_bin="$(command -v mysqldump)"
    else
        rm -f "$mysql_cnf"
        die 'Neither mariadb-dump nor mysqldump is installed.'
    fi

    MYSQL_PWD='' "$dump_bin" --defaults-extra-file="$mysql_cnf" \
        --single-transaction --quick --skip-lock-tables "$db_name" \
        | gzip -c > "$backup_dir/database.sql.gz"
    rm -f "$mysql_cnf"

    [[ -s "$backup_dir/database.sql.gz" ]] || die 'Database backup is empty.'

    {
        printf 'release_id=%s\n' "$RELEASE_ID"
        printf 'created_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        printf 'previous_target=%s\n' "$PREVIOUS_TARGET"
        printf 'artifact_sha256=%s\n' "$ARTIFACT_SHA256"
    } > "$backup_dir/manifest.txt"

    log "Backup created: $backup_dir"
}

cleanup_old_releases() {
    [[ "$KEEP_RELEASES" =~ ^[0-9]+$ ]] || return 0
    (( KEEP_RELEASES > 0 )) || return 0

    local current_target
    current_target="$(readlink -f "$CURRENT_LINK")"

    mapfile -t release_paths < <(
        find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d ! -name '.staging-*' \
            -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2-
    )

    local index candidate
    for ((index = KEEP_RELEASES; index < ${#release_paths[@]}; index++)); do
        candidate="${release_paths[$index]}"
        if [[ "$candidate" == "$current_target" || "$candidate" == "$PREVIOUS_TARGET" ]]; then
            continue
        fi
        rm -rf "$candidate"
        log "Removed old release: $candidate"
    done
}

cleanup_old_backups() {
    [[ "$SKIP_BACKUP" == '1' ]] && return 0
    [[ "$KEEP_BACKUPS" =~ ^[0-9]+$ ]] || return 0
    (( KEEP_BACKUPS > 0 )) || return 0

    mapfile -t backup_paths < <(
        find "$BACKUPS_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
            | sort -nr | cut -d' ' -f2-
    )

    local index candidate
    for ((index = KEEP_BACKUPS; index < ${#backup_paths[@]}; index++)); do
        candidate="${backup_paths[$index]}"
        rm -rf "$candidate"
        log "Removed old deployment backup: $candidate"
    done
}

recover() {
    local status="$1"
    trap - EXIT

    if (( status != 0 )); then
        log 'Deployment failed; beginning application rollback.'

        if (( SWITCHED == 1 )) && [[ -n "$PREVIOUS_TARGET" && -d "$PREVIOUS_TARGET" ]]; then
            atomic_switch "$PREVIOUS_TARGET" || true
            reload_fpm || true
        fi

        # Always target the known previous immutable release directly when
        # clearing maintenance mode. This avoids depending on symlink path
        # resolution during a rollback switch and makes recovery deterministic.
        if (( MAINTENANCE_ENABLED == 1 )) && [[ -n "$PREVIOUS_TARGET" && -f "$PREVIOUS_TARGET/artisan" ]]; then
            run_artisan_in "$PREVIOUS_TARGET" up || true
            MAINTENANCE_ENABLED=0
        fi

        if (( SWITCHED == 1 )); then
            health_check || log 'WARNING: rollback health check did not pass.'
        fi
    fi

    if [[ -n "$STAGING_DIR" && -d "$STAGING_DIR" ]]; then
        rm -rf "$STAGING_DIR"
    fi

    exit "$status"
}

trap 'recover $?' EXIT

[[ -n "$ARTIFACT" ]] || die 'Usage: horus-atomic-deploy.sh /path/to/horus-media-platform.zip [expected-sha256]'
[[ -f "$ARTIFACT" ]] || die "Release artifact not found: $ARTIFACT"
[[ -x "$PHP_BIN" ]] || die "PHP binary is not executable: $PHP_BIN"
[[ -L "$CURRENT_LINK" ]] || die "Current application path must be an atomic symlink: $CURRENT_LINK"
[[ -f "$SHARED_ENV" ]] || die "Shared environment file is missing: $SHARED_ENV"
[[ -d "$SHARED_STORAGE" ]] || die "Shared storage directory is missing: $SHARED_STORAGE"
[[ "$HEALTH_ATTEMPTS" =~ ^[1-9][0-9]*$ ]] || die 'HORUS_DEPLOY_HEALTH_ATTEMPTS must be a positive integer.'
[[ "$HEALTH_DELAY_SECONDS" =~ ^[0-9]+$ ]] || die 'HORUS_DEPLOY_HEALTH_DELAY_SECONDS must be a non-negative integer.'
[[ "$HEALTH_INSECURE_TLS" =~ ^[01]$ ]] || die 'HORUS_DEPLOY_HEALTH_INSECURE_TLS must be 0 or 1.'
if [[ "$HEALTH_INSECURE_TLS" == '1' && -z "$HEALTH_RESOLVE_IP" && -z "$HEALTHCHECK_COMMAND" ]]; then
    die 'HORUS_DEPLOY_HEALTH_INSECURE_TLS=1 is allowed only for an explicitly resolved direct-origin health check.'
fi

require_command unzip
require_command sha256sum
require_command flock
require_command curl
require_command tar
require_command gzip

mkdir -p "$RELEASES_DIR" "$BACKUPS_DIR" "$LOGS_DIR"
touch "$DEPLOY_LOG"
exec > >(tee -a "$DEPLOY_LOG") 2>&1

exec 9>"$LOCK_FILE"
flock -n 9 || die 'Another Horus deployment is already running.'

PREVIOUS_TARGET="$(readlink -f "$CURRENT_LINK")"
[[ -d "$PREVIOUS_TARGET" ]] || die "Current release target is invalid: $PREVIOUS_TARGET"

ARTIFACT_SHA256="$(sha256sum "$ARTIFACT" | awk '{print $1}')"
if [[ -n "$EXPECTED_SHA256" && "$ARTIFACT_SHA256" != "$EXPECTED_SHA256" ]]; then
    die "Artifact checksum mismatch. Expected $EXPECTED_SHA256, got $ARTIFACT_SHA256"
fi

RAW_RELEASE_ID="${HORUS_RELEASE_ID:-$(date -u '+%Y%m%dT%H%M%SZ')-${ARTIFACT_SHA256:0:12}}"
RELEASE_ID="$(printf '%s' "$RAW_RELEASE_ID" | tr -cd 'A-Za-z0-9._-')"
[[ -n "$RELEASE_ID" ]] || die 'Release ID is empty after sanitization.'

RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
[[ ! -e "$RELEASE_DIR" ]] || die "Release already exists: $RELEASE_DIR"

STAGING_DIR="$RELEASES_DIR/.staging-${RELEASE_ID}-$$"
mkdir -p "$STAGING_DIR"
unzip -q "$ARTIFACT" -d "$STAGING_DIR"
SOURCE_DIR="$STAGING_DIR/horus-media-platform"

[[ -f "$SOURCE_DIR/artisan" ]] || die 'Release is missing artisan.'
[[ -f "$SOURCE_DIR/vendor/autoload.php" ]] || die 'Release is missing production vendor dependencies.'
[[ -f "$SOURCE_DIR/public/index.php" ]] || die 'Release is missing public/index.php.'

# Move into the immutable final release path before any Laravel cache is built.
mv "$SOURCE_DIR" "$RELEASE_DIR"
rm -rf "$STAGING_DIR"
STAGING_DIR=""

rm -f "$RELEASE_DIR/.env"
ln -s "$SHARED_ENV" "$RELEASE_DIR/.env"
rm -rf "$RELEASE_DIR/storage"
ln -s "$SHARED_STORAGE" "$RELEASE_DIR/storage"

mkdir -p \
    "$SHARED_STORAGE/app/private" \
    "$SHARED_STORAGE/app/public" \
    "$SHARED_STORAGE/framework/cache/data" \
    "$SHARED_STORAGE/framework/sessions" \
    "$SHARED_STORAGE/framework/views" \
    "$SHARED_STORAGE/logs" \
    "$RELEASE_DIR/bootstrap/cache"
chmod u+rwx "$RELEASE_DIR/bootstrap/cache"
chmod -R u+rwX "$SHARED_STORAGE"

rm -rf "$RELEASE_DIR/public/storage"

log "Prepared release in final path: $RELEASE_DIR"
run_artisan_in "$RELEASE_DIR" optimize:clear
run_artisan_in "$RELEASE_DIR" route:list >/dev/null
run_artisan_in "$RELEASE_DIR" schedule:list >/dev/null

create_backup

run_artisan_in "$PREVIOUS_TARGET" down --retry=10
MAINTENANCE_ENABLED=1

run_artisan_in "$RELEASE_DIR" migrate --force
run_artisan_in "$RELEASE_DIR" storage:link
run_artisan_in "$RELEASE_DIR" optimize

if grep -R -Fq '/.staging-' "$RELEASE_DIR/bootstrap/cache" 2>/dev/null; then
    die 'A staging path leaked into Laravel bootstrap cache.'
fi

{
    printf 'release_id=%s\n' "$RELEASE_ID"
    printf 'artifact_sha256=%s\n' "$ARTIFACT_SHA256"
    printf 'deployed_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$RELEASE_DIR/.horus-release"

atomic_switch "$RELEASE_DIR"
SWITCHED=1
reload_fpm
run_artisan_in "$CURRENT_LINK" up
MAINTENANCE_ENABLED=0

if ! health_check; then
    # Ensure recovery explicitly brings the previous immutable release up after
    # switching back, even though the shared maintenance marker may already be
    # cleared by the failed release.
    MAINTENANCE_ENABLED=1
    die "New release failed health check: $HEALTH_URL"
fi

log "Deployment healthy: $RELEASE_ID"
cleanup_old_releases
cleanup_old_backups

trap - EXIT
exit 0
