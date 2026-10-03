#!/usr/bin/env bash
# Private bounded operations only. This script never schedules or uploads results.
set +x
set -Eeuo pipefail
umask 077

OPERATION="${OPERATION:-}"
ACTOR_FINGERPRINT="${ACTOR_FINGERPRINT:-}"
ACTOR_SELECTOR="${ACTOR_SELECTOR:-}"
ACTIVATION_SHA256="${ACTIVATION_SHA256:-}"
DIGEST="${DIGEST:-}"
MODE="${MODE:-discover}"
BATCH_LIMIT="${BATCH_LIMIT:-1}"
private_temp=''
result_emitted=0

emit_failure() {
    local token digest_json
    token="${OPERATION:-}"
    [[ "$token" =~ ^[0-9a-f]{64}$ ]] || token='0000000000000000000000000000000000000000000000000000000000000000'
    digest_json='null'
    if [[ "${DIGEST:-}" =~ ^[0-9a-f]{64}$ ]]; then digest_json="\"$DIGEST\""; fi
    printf '{"schema_version":1,"outcome":"FAILED","reason":"%s","operation":"%s","digest":%s,"counts":{"sources":0,"daily_facts":0,"hourly_facts":0,"forward_facts":0,"windows":0,"eligible_windows":0,"blocked_facts":0,"corrected_facts":0,"pending":0,"ready":0,"applied":0,"blocked":0},"reasons":{}}\n' "$1" "$token" "$digest_json"
    result_emitted=1
}

cleanup() {
    if [[ -n "$private_temp" ]]; then rm -rf -- "$private_temp" 2>/dev/null || true; fi
}
trap cleanup EXIT

main() {
    # Every value interpolated into the remote command has a closed alphabet.
    # This includes configuration paths, not merely workflow_dispatch inputs.
    local actor_identity
    if [[ "${GITHUB_EVENT_NAME:-}" == workflow_run ]]; then
        [[ "$MODE" == execute-once && "$ACTOR_SELECTOR" =~ ^[0-9a-f]{64}$ && -z "$ACTOR_FINGERPRINT" &&
            "$ACTIVATION_SHA256" =~ ^[0-9a-f]{64}$ && -z "$DIGEST" && "$BATCH_LIMIT" == 1 &&
            "${TRIGGER_HEAD_SHA:-}" == "${RELEASE_SHA:-}" && "${TRIGGER_CONCLUSION:-}" == success ]] || {
            emit_failure CONFIGURATION_INVALID
            return 1
        }
        actor_identity="$ACTOR_SELECTOR"
    else
        [[ "${GITHUB_EVENT_NAME:-}" == workflow_dispatch && "$MODE" =~ ^(discover|prepare|poll|status|apply)$ &&
            "$ACTOR_FINGERPRINT" =~ ^[0-9a-f]{64}$ && -z "$ACTOR_SELECTOR" && -z "$ACTIVATION_SHA256" ]] || {
            emit_failure CONFIGURATION_INVALID
            return 1
        }
        actor_identity="$ACTOR_FINGERPRINT"
    fi
    [[ "$MODE" =~ ^(discover|prepare|poll|status|apply|execute-once)$ ]] &&
        [[ "$OPERATION" =~ ^[0-9a-f]{64}$ ]] &&
        [[ -z "$DIGEST" || "$DIGEST" =~ ^[0-9a-f]{64}$ ]] &&
        [[ "$MODE" != apply || "$DIGEST" =~ ^[0-9a-f]{64}$ ]] &&
        [[ "$BATCH_LIMIT" =~ ^[1-6]$ ]] &&
        [[ "${RELEASE_SHA:-}" =~ ^[0-9a-f]{40}$ ]] &&
        [[ "${ARTIFACT_SHA256:-}" =~ ^[0-9a-f]{64}$ ]] &&
        [[ "${GITHUB_REF:-}" == refs/heads/main ]] &&
        [[ "${GITHUB_SHA:-}" == "$RELEASE_SHA" ]] &&
        [[ "${REMOTE_HOME:-}" =~ ^(/[A-Za-z0-9_-]+)+$ ]] &&
        [[ "${HOME:-}" =~ ^(/[A-Za-z0-9_-]+)+$ ]] &&
        [[ "${RUNNER_TEMP:-}" =~ ^(/[A-Za-z0-9_-]+)+$ ]] &&
        [[ "${HOST:-}" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ ]] &&
        [[ "${SSH_USER:-}" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] &&
        [[ "${SSH_PORT:-}" =~ ^[0-9]{1,5}$ ]] &&
        (( 10#$SSH_PORT >= 1 && 10#$SSH_PORT <= 65535 )) &&
        [[ -n "${SSH_KEY:-}" && -n "${KNOWN_HOSTS:-}" ]] || {
            emit_failure CONFIGURATION_INVALID
            return 1
        }

    private_temp="$(mktemp -d "$RUNNER_TEMP/private-gam.XXXXXXXX")" || { emit_failure INTERNAL_ERROR; return 1; }
    # All setup, transport, validator, and unexpected shell errors remain private.
    exec 2>"$private_temp/local.stderr"
    local validator transport_status validation_status
    validator="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/validate-private-correction-result.php"
    install -d -m 700 "$HOME/.ssh"
    printf '%s\n' "$SSH_KEY" > "$HOME/.ssh/id_ed25519"
    printf '%s\n' "$KNOWN_HOSTS" > "$HOME/.ssh/known_hosts"
    chmod 600 "$HOME/.ssh/id_ed25519" "$HOME/.ssh/known_hosts"

    transport_status=0
    ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes \
        -o "UserKnownHostsFile=$HOME/.ssh/known_hosts" -o LogLevel=ERROR \
        -o ConnectTimeout=20 -o ServerAliveInterval=20 -o ServerAliveCountMax=3 \
        -i "$HOME/.ssh/id_ed25519" -p "$SSH_PORT" "$SSH_USER@$HOST" \
        "bash -s -- '$REMOTE_HOME' '$RELEASE_SHA' '$ARTIFACT_SHA256' '$MODE' '$OPERATION' '$actor_identity' '$DIGEST' '$BATCH_LIMIT' '$ACTIVATION_SHA256'" \
        >"$private_temp/transport.stdout" 2>"$private_temp/transport.stderr" <<'REMOTE' || transport_status=$?
set +x
set -Eeuo pipefail
umask 077
# fd 3 is reserved for the private result file/canonical errors only.
exec 3>&1
exec 1>/dev/null 2>/dev/null
remote_home="$1"; release_sha="$2"; artifact_sha256="$3"; mode="$4"
operation="$5"; actor_identity="$6"; digest="$7"; batch_limit="$8"; activation_sha256="$9"
fail() {
    local digest_json='null'
    if [[ -n "$digest" ]]; then digest_json="\"$digest\""; fi
    printf '{"schema_version":1,"outcome":"FAILED","reason":"%s","operation":"%s","digest":%s,"counts":{"sources":0,"daily_facts":0,"hourly_facts":0,"forward_facts":0,"windows":0,"eligible_windows":0,"blocked_facts":0,"corrected_facts":0,"pending":0,"ready":0,"applied":0,"blocked":0},"reasons":{}}\n' "$1" "$operation" "$digest_json" >&3
    exit 0
}
trap 'fail INTERNAL_ERROR' ERR
private_root="$remote_home/.horus-private-historical"
[[ ! -L "$private_root" ]] || fail CONFIGURATION_INVALID
install -d -m 700 "$private_root"
[[ "$(readlink -f "$private_root")" == "$private_root" ]] || fail CONFIGURATION_INVALID
private_request="$(mktemp -d "$private_root/request.XXXXXXXX")"
raw_log="$private_request/command.log"
result_file="$private_request/result.json"
exec 1>>"$raw_log" 2>&1

# Match the atomic deploy's lock. Hold fd 9 across verification and all PHP work.
[[ ! -L "$remote_home/.horus-deploy.lock" ]] || fail CONFIGURATION_INVALID
exec 9>"$remote_home/.horus-deploy.lock"
flock -n 9 || fail DEPLOY_LOCK_BUSY
release_dir="$remote_home/releases/$release_sha"
current_link="$remote_home/htdocs/app.horusmedia.net"
[[ -d "$release_dir" && ! -L "$release_dir" ]] || fail RELEASE_MISMATCH
[[ "$(readlink -f "$release_dir")" == "$release_dir" ]] || fail RELEASE_MISMATCH
[[ "$(readlink -f "$current_link")" == "$release_dir" ]] || fail RELEASE_MISMATCH
marker="$release_dir/.horus-release"
[[ -f "$marker" && ! -L "$marker" ]] || fail RELEASE_MISMATCH
[[ "$(grep -c '^release_id=' "$marker")" == 1 ]] || fail RELEASE_MISMATCH
[[ "$(grep -c '^artifact_sha256=' "$marker")" == 1 ]] || fail RELEASE_MISMATCH
grep -Fxq "release_id=$release_sha" "$marker" || fail RELEASE_MISMATCH
grep -Fxq "artifact_sha256=$artifact_sha256" "$marker" || fail RELEASE_MISMATCH
cd "$release_dir"
command_status=0
# Redirect before PHP starts: even parse/bootstrap/auth/API errors stay on server.
actor_option="--actor-fingerprint=$actor_identity"
activation_options=()
if [[ "$mode" == execute-once ]]; then
    activation="ops/audit/historical-gam-correction-once.json"
    [[ -f "$activation" && ! -L "$activation" ]] || fail CONFIGURATION_INVALID
    [[ "$(sha256sum "$activation" | cut -d ' ' -f1)" == "$activation_sha256" ]] || fail CONFIGURATION_INVALID
    actor_option="--actor-selector=$actor_identity"
    activation_options=("--activation-sha256=$activation_sha256")
fi
php artisan reporting:gam-historical-operation "$mode" \
    "--operation=$operation" "$actor_option" "${activation_options[@]}" \
    "--digest=$digest" "--limit=$batch_limit" "--result-file=$result_file" \
    >>"$raw_log" 2>&1 || command_status=$?
[[ -f "$result_file" && ! -L "$result_file" && -s "$result_file" ]] || fail COMMAND_FAILED
[[ "$(stat -c '%s' "$result_file")" -le 16384 ]] || fail RESULT_INVALID
chmod 600 "$result_file"
# Only this designated result crosses SSH. The local validator reconstructs it.
cat "$result_file" >&3
if (( command_status != 0 )); then exit 10; fi
REMOTE

    if [[ "$transport_status" != 0 && "$transport_status" != 10 ]]; then
        emit_failure TRANSPORT_FAILED
        return 1
    fi
    validation_status=0
    php -n "$validator" "$private_temp/transport.stdout" "$OPERATION" "$DIGEST" "$transport_status" \
        >"$private_temp/validated.json" 2>"$private_temp/validation.stderr" || validation_status=$?
    if [[ "$validation_status" != 0 && "$validation_status" != 1 ]] || [[ ! -s "$private_temp/validated.json" ]]; then
        emit_failure RESULT_INVALID
        return 1
    fi
    cat "$private_temp/validated.json"
    result_emitted=1
    return "$validation_status"
}

trap 'exit_status=$?; if [[ "$result_emitted" != 1 ]]; then emit_failure INTERNAL_ERROR; fi; exit "$exit_status"' ERR
main 2>/dev/null
