#!/usr/bin/env bash
set -euo pipefail

root="${1:?snapshot root is required}"
base="${2:?Traffic Gate base URL is required}"
probe="${3:-verify}"

[[ "$root" == /* ]]
[[ "$base" =~ ^https://[^/]+/?$ ]]
base="${base%/}"

test -f "$root/delivery-manifest.json"
test -f "$root/assets/traffic-gate/horus-traffic-gate.js"

work_root="${RUNNER_TEMP:-/tmp}"
tmp="$(mktemp -d "$work_root/horus-gate-verify.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

run_id="${GITHUB_RUN_ID:-remote}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
user_agent='Mozilla/5.0 Horus-Static-Sync/3.0'

expected_manifest_hash="$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["manifestHash"] ?? "";' "$root/delivery-manifest.json")"
[[ "$expected_manifest_hash" =~ ^[0-9a-f]{64}$ ]]
expected_gate_js="$(sha256sum "$root/assets/traffic-gate/horus-traffic-gate.js" | awk '{print $1}')"
[[ "$expected_gate_js" =~ ^[0-9a-f]{64}$ ]]

manifest_file="$tmp/delivery-manifest.json"
gate_html="$tmp/traffic-gate.html"
gate_js="$tmp/horus-traffic-gate.js"
gate_headers="$tmp/traffic-gate.headers"

curl --fail --silent --show-error --location --globoff --max-time 20 \
  --retry 2 --retry-delay 1 \
  --user-agent "$user_agent" \
  "$base/delivery-manifest.json?edge_verify=${run_id}-${run_attempt}-${probe}-manifest" \
  --output "$manifest_file"

remote_manifest_hash="$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["manifestHash"] ?? "";' "$manifest_file")"
if [[ "$remote_manifest_hash" != "$expected_manifest_hash" ]]; then
  echo "Traffic Gate manifest mismatch at $base: expected $expected_manifest_hash, got $remote_manifest_hash." >&2
  exit 1
fi

curl --fail --silent --show-error --location --globoff --max-time 20 \
  --retry 2 --retry-delay 1 \
  --user-agent "$user_agent" \
  --dump-header "$gate_headers" \
  "$base/traffic-gate/?edge_verify=${run_id}-${run_attempt}-${probe}-page" \
  --output "$gate_html"

grep -Fq '<title>Horus Client Traffic Gate</title>' "$gate_html"
grep -Fq '/assets/traffic-gate/horus-traffic-gate.js' "$gate_html"

curl --fail --silent --show-error --location --globoff --max-time 20 \
  --retry 2 --retry-delay 1 \
  --user-agent "$user_agent" \
  "$base/assets/traffic-gate/horus-traffic-gate.js?edge_verify=${run_id}-${run_attempt}-${probe}-js" \
  --output "$gate_js"

actual_gate_js="$(sha256sum "$gate_js" | awk '{print $1}')"
if [[ "$actual_gate_js" != "$expected_gate_js" ]]; then
  echo "Traffic Gate JavaScript hash mismatch at $base." >&2
  exit 1
fi

csp="$(tr -d '\r' < "$gate_headers" | awk 'BEGIN{IGNORECASE=1} /^Content-Security-Policy:/ {line=$0} END{sub(/^[^:]+:[[:space:]]*/, "", line); print line}')"
[[ "$csp" == *"script-src 'self' https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"frame-src https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"connect-src 'self' https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"frame-ancestors https:"* ]]

echo "Traffic Gate public origin verified at $base with manifest $expected_manifest_hash."
