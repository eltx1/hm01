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

manifest="$root/delivery-manifest.json"
test -f "$manifest"

work_root="${RUNNER_TEMP:-/tmp}"
tmp="$(mktemp -d "$work_root/horus-static-verify.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

base="${base%/}"
run_id="${GITHUB_RUN_ID:-local}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
user_agent='Mozilla/5.0 Horus-Static-Sync/2.0'

# The root manifest is not listed inside its own files map. Verify it explicitly
# first so a stale/missing root can never be mistaken for a complete snapshot.
remote_manifest="$tmp/delivery-manifest.json"
if ! curl --fail --silent --show-error --location --globoff --max-time 20 \
    --retry 2 --retry-delay 1 \
    --user-agent "$user_agent" \
    "$base/delivery-manifest.json?edge_verify=${run_id}-${run_attempt}-${probe}-root" \
    --output "$remote_manifest"; then
  echo "Public edge is missing delivery-manifest.json at $base." >&2
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
# without weakening parity. Every response body is streamed directly into
# sha256sum, so the runner does not need to store the complete remote snapshot.
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
    | sha256sum | awk "{print \\$1}"
  )"
  if [[ "$actual" != "$expected" ]]; then
    echo "Public edge hash mismatch for $path at $HORUS_VERIFY_BASE: expected $expected, got $actual." >&2
    exit 1
  fi
' _ < "$args"; then
  echo "Public edge snapshot parity failed at $base." >&2
  exit 1
fi
