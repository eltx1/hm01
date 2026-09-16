#!/usr/bin/env bash
set -euo pipefail

root="${1:?snapshot root is required}"
base="${2:?Traffic Gate base URL is required}"
probe="${3:-verify}"

[[ "$root" == /* ]]
[[ "$base" =~ ^https://[^/]+/?$ ]]
base="${base%/}"

waf_fallback="${HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK:-0}"
[[ "$waf_fallback" == '0' || "$waf_fallback" == '1' ]]
pages_project="${CLOUDFLARE_PAGES_PROJECT:-horus-media-cdn}"

test -f "$root/delivery-manifest.json"
test -f "$root/assets/traffic-gate/horus-traffic-gate.js"

work_root="${RUNNER_TEMP:-/tmp}"
tmp="$(mktemp -d "$work_root/horus-gate-verify.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

run_id="${GITHUB_RUN_ID:-remote}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
user_agent='Mozilla/5.0 Horus-Static-Sync/3.1'

expected_manifest_hash="$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["manifestHash"] ?? "";' "$root/delivery-manifest.json")"
[[ "$expected_manifest_hash" =~ ^[0-9a-f]{64}$ ]]
expected_gate_js="$(sha256sum "$root/assets/traffic-gate/horus-traffic-gate.js" | awk '{print $1}')"
[[ "$expected_gate_js" =~ ^[0-9a-f]{64}$ ]]

manifest_file="$tmp/delivery-manifest.json"
gate_html="$tmp/traffic-gate.html"
gate_js="$tmp/horus-traffic-gate.js"
gate_headers="$tmp/traffic-gate.headers"

manifest_status="$(
  curl --silent --show-error --location --globoff --max-time 20 \
    --retry 2 --retry-delay 1 \
    --user-agent "$user_agent" \
    "$base/delivery-manifest.json?edge_verify=${run_id}-${run_attempt}-${probe}-manifest" \
    --output "$manifest_file" \
    --write-out '%{http_code}' || true
)"

if [[ "$manifest_status" != '200' ]]; then
  # This fallback may be enabled only after the exact authoritative Pages
  # deployment, including the Traffic Gate page, JS and CSP, was verified.
  # It accepts only a custom-host HTTP 403 plus an active-domain proof from
  # Cloudflare's control plane; all other statuses still fail closed.
  if [[ "$manifest_status" == '403' && "$waf_fallback" == '1' ]]; then
    script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
    domain="${base#https://}"
    bash "$script_dir/verify-cloudflare-pages-domain-active.sh" "$pages_project" "$domain"
    echo "Cloudflare WAF returned HTTP 403 for Traffic Gate $base after authoritative Pages verification; active custom-domain control-plane proof accepted."
    exit 0
  fi

  echo "Traffic Gate manifest request failed at $base with HTTP ${manifest_status:-000}." >&2
  exit 1
fi

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
cache_control="$(tr -d '\r' < "$gate_headers" | awk 'BEGIN{IGNORECASE=1} /^Cache-Control:/ {line=$0} END{sub(/^[^:]+:[[:space:]]*/, "", line); print line}')"
[[ "$csp" == *"script-src 'self' https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"frame-src https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"connect-src 'self' https://challenges.cloudflare.com"* ]]
[[ "$csp" == *"frame-ancestors https:"* ]]
[[ "$cache_control" == *"no-transform"* ]]

echo "Traffic Gate public origin verified at $base with manifest $expected_manifest_hash."
