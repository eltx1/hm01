#!/usr/bin/env bash
# Incoming trusted source inspects the current release before any transfer.
# This path never invokes the financial operation or changes its activation.
set +x
set -Eeuo pipefail
umask 077
private_temp=''
emitted=0
fail() {
    printf '{"schema_version":1,"status":"FAILED","reason":"%s"}\n' "$1"
    emitted=1
    return 1
}
cleanup() { if [[ -n "$private_temp" ]]; then rm -rf -- "$private_temp" 2>/dev/null || true; fi; }
trap cleanup EXIT
trap 'status=$?; if [[ "$emitted" != 1 ]]; then fail INTERNAL_ERROR || true; fi; exit "$status"' ERR

main() {
    local base='708ab326a5241b3ee098dd622a5f8e2e1266d978'
    [[ "${RELEASE_SHA:-}" =~ ^[0-9a-f]{40}$ && "${HISTORICAL_AUDIT_TRUSTED_SHA:-}" == "$RELEASE_SHA" &&
       "${GITHUB_REF:-}" == refs/heads/main &&
       "${GITHUB_REPOSITORY:-}" == eltx1/hm01 && "${GITHUB_REPOSITORY_ID:-}" == 1315038901 &&
       "${GITHUB_EVENT_NAME:-}" =~ ^(workflow_run|workflow_dispatch)$ &&
       "${REMOTE_HOME:-}" =~ ^(/[A-Za-z0-9_-]+)+$ &&
       "${HOME:-}" =~ ^(/[A-Za-z0-9_-]+)+$ &&
       "${RUNNER_TEMP:-}" =~ ^(/[A-Za-z0-9_-]+)+$ &&
       "${HOST:-}" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ &&
       "${SSH_USER:-}" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ &&
       "${SSH_PORT:-}" =~ ^[0-9]{1,5}$ ]] &&
       (( 10#$SSH_PORT >= 1 && 10#$SSH_PORT <= 65535 )) || { fail CONFIGURATION_INVALID; return 1; }
    [[ "$(git rev-parse HEAD)" == "$RELEASE_SHA" && "$(git rev-parse HEAD^1)" == "$base" ]] || {
        fail TRUST_PROOF_INVALID; return 1;
    }
    local script_dir reader validator
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    reader="$script_dir/verify-historical-correction.php"
    validator="$script_dir/validate-historical-correction-audit.mjs"
    [[ -f "$reader" && ! -L "$reader" && -f "$validator" && ! -L "$validator" &&
       -s "$HOME/.ssh/id_ed25519" && -s "$HOME/.ssh/known_hosts" ]] || { fail CONFIGURATION_INVALID; return 1; }
    private_temp="$(mktemp -d "$RUNNER_TEMP/historical-audit.XXXXXXXX")"
    exec 2>"$private_temp/local.stderr"
    local remote remote_command transport_status=0 validation_status=0
    remote="$(cat <<'REMOTE'
set +x
set -Eeuo pipefail
# No raw shell/PHP errors are ever returned to public workflow logs.
exec 2>/dev/null
fail() { printf '{"schema_version":1,"status":"FAILED","reason":"%s"}\n' "$1"; exit 10; }
trap 'fail INTERNAL_ERROR' ERR
remote_home="$1"; base="$2"
lock="$remote_home/.horus-deploy.lock"
[[ -f "$lock" && ! -L "$lock" ]] || fail DEPLOY_LOCK_UNAVAILABLE
# Reuse the atomic deploy lock without creating or truncating a server file.
exec 9<"$lock"
flock -n 9 || fail DEPLOY_LOCK_BUSY
release_dir="$remote_home/releases/$base"
current="$remote_home/htdocs/app.horusmedia.net"
[[ -d "$release_dir" && ! -L "$release_dir" && "$(readlink -f "$release_dir")" == "$release_dir" &&
   -L "$current" && "$(readlink -f "$current")" == "$release_dir" ]] || fail RELEASE_MISMATCH
marker="$release_dir/.horus-release"
[[ -f "$marker" && ! -L "$marker" && "$(grep -c '^release_id=' "$marker")" == 1 &&
   "$(grep -c '^artifact_sha256=' "$marker")" == 1 ]] || fail RELEASE_MISMATCH
grep -Fxq "release_id=$base" "$marker" || fail RELEASE_MISMATCH
grep -Fxq 'artifact_sha256=16a8869b50351e095c2b9943a50ec58c0cb7cc598149226bd9585f50bf6aa222' "$marker" || fail RELEASE_MISMATCH
cd "$release_dir"
# Stdin is the incoming standalone reader; bootstrap only the deployed app.
php || exit 10
REMOTE
)"
    printf -v remote_command 'bash -c %q bash %q %q' "$remote" "$REMOTE_HOME" "$base"
    ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes \
        -o "UserKnownHostsFile=$HOME/.ssh/known_hosts" -o LogLevel=ERROR \
        -o ConnectTimeout=20 -o ServerAliveInterval=20 -o ServerAliveCountMax=3 \
        -i "$HOME/.ssh/id_ed25519" -p "$SSH_PORT" "$SSH_USER@$HOST" "$remote_command" \
        <"$reader" >"$private_temp/transport.stdout" 2>"$private_temp/transport.stderr" || transport_status=$?
    if [[ "$transport_status" != 0 && "$transport_status" != 10 ]]; then fail TRANSPORT_FAILED; return 1; fi
    node "$validator" "$private_temp/transport.stdout" "$transport_status" \
        >"$private_temp/validated.json" 2>"$private_temp/validation.stderr" || validation_status=$?
    if [[ "$validation_status" != 0 && "$validation_status" != 1 ]] || [[ ! -s "$private_temp/validated.json" ]]; then
        fail RESULT_INVALID; return 1
    fi
    cat "$private_temp/validated.json"
    emitted=1
    return "$validation_status"
}
main 2>/dev/null
