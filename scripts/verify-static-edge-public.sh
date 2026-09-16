#!/usr/bin/env bash
set -euo pipefail

root="${1:?snapshot root is required}"
base="${2:?public base URL is required}"
probe="${3:-verify}"
concurrency="${4:-32}"

[[ "$root" == /* ]]
[[ "$base" =~ ^https://[^/]+/?$ ]]
[[ "$concurrency" =~ ^[0-9]+$ ]]
(( concurrency >= 1 && concurrency <= 64 ))

waf_fallback="${HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK:-0}"
[[ "$waf_fallback" == '0' || "$waf_fallback" == '1' ]]
pages_project="${CLOUDFLARE_PAGES_PROJECT:-horus-media-cdn}"

manifest="$root/delivery-manifest.json"
test -f "$manifest"

work_root="${RUNNER_TEMP:-/tmp}"
tmp="$(mktemp -d "$work_root/horus-static-verify.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

base="${base%/}"
run_id="${GITHUB_RUN_ID:-local}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
user_agent='Mozilla/5.0 Horus-Static-Sync/3.1'

# delivery-manifest.json is deliberately not listed inside its own files map.
# Verify it first so a stale/missing root manifest can never be mistaken for a
# complete deployment merely because all referenced artifacts still exist.
remote_manifest="$tmp/delivery-manifest.json"
remote_status="$(
  curl --silent --show-error --location --globoff --max-time 20 \
    --retry 2 --retry-delay 1 \
    --user-agent "$user_agent" \
    "$base/delivery-manifest.json?edge_verify=${run_id}-${run_attempt}-${probe}-root" \
    --output "$remote_manifest" \
    --write-out '%{http_code}' || true
)"

if [[ "$remote_status" != '200' ]]; then
  # This escape hatch is intentionally narrow. Callers may enable it only after
  # the exact authoritative *.pages.dev deployment has already passed full
  # manifest/file-hash parity. A 403 is then treated as a WAF visibility issue
  # only when Cloudflare's control plane also proves this exact custom hostname
  # is active on the expected Pages project. 404/5xx/network failures still fail.
  if [[ "$remote_status" == '403' && "$waf_fallback" == '1' ]]; then
    script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    domain="${base#https://}"
    "$script_dir/verify-cloudflare-pages-domain-active.sh" "$pages_project" "$domain"
    echo "Cloudflare WAF returned HTTP 403 for $base after authoritative Pages parity; active custom-domain control-plane proof accepted."
    exit 0
  fi

  echo "Public edge delivery-manifest.json request failed at $base with HTTP ${remote_status:-000}." >&2
  exit 1
fi

if ! cmp -s "$manifest" "$remote_manifest"; then
  echo "Public edge root manifest mismatch at $base." >&2
  exit 1
fi

args="$tmp/manifest-args.bin"
php -r '
  $d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach (($d["files"] ?? []) as $path => $sha) {
      if ($path === "_headers" || $path === "_routes.json") continue;
      if (!is_string($path) || !is_string($sha)
          || !preg_match("/^[0-9a-f]{64}$/", $sha)
          || str_starts_with($path, "/")
          || str_contains($path, "..")
          || str_contains($path, "\0")) {
          fwrite(STDERR, "Invalid static manifest entry.\n");
          exit(2);
      }
      fwrite(STDOUT, $sha."\0".$path."\0");
  }
' "$manifest" > "$args"

if [[ ! -s "$args" ]]; then
  exit 0
fi

export HORUS_VERIFY_BASE="$base"
export HORUS_VERIFY_PROBE="$probe"
export HORUS_VERIFY_RUN_ID="$run_id"
export HORUS_VERIFY_RUN_ATTEMPT="$run_attempt"
export HORUS_VERIFY_USER_AGENT="$user_agent"

# Bounded parallelism keeps the supported 20k-file snapshot budget practical
# without weakening parity. Each body is streamed directly into sha256sum, so
# the runner does not have to retain a second complete snapshot on disk.
if ! xargs -0 -n2 -P "$concurrency" bash -c '
  set -euo pipefail
  expected="$1"
  path="$2"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]]
  [[ "$path" != /* && "$path" != *..* ]]
  actual="$(
    curl --fail --silent --show-error --location --globoff --max-time 20 \
      --retry 2 --retry-delay 1 \
      --user-agent "$HORUS_VERIFY_USER_AGENT" \
      "$HORUS_VERIFY_BASE/$path?edge_verify=$HORUS_VERIFY_RUN_ID-$HORUS_VERIFY_RUN_ATTEMPT-$HORUS_VERIFY_PROBE" \
    | sha256sum | cut -d " " -f1
  )"
  if [[ "$actual" != "$expected" ]]; then
    echo "Public edge hash mismatch for $path at $HORUS_VERIFY_BASE: expected $expected, got $actual." >&2
    exit 1
  fi
' _ < "$args"; then
  echo "Public edge snapshot parity failed at $base." >&2
  exit 1
fi
