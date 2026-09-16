#!/usr/bin/env bash
set -euo pipefail

project="${1:?Cloudflare Pages project is required}"
domain="${2:?custom domain is required}"

: "${CLOUDFLARE_ACCOUNT_ID:?CLOUDFLARE_ACCOUNT_ID is required}"
: "${CLOUDFLARE_API_TOKEN:?CLOUDFLARE_API_TOKEN is required}"

[[ "$project" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$domain" =~ ^[A-Za-z0-9.-]+$ ]]

work_root="${RUNNER_TEMP:-/tmp}"
response_file="$(mktemp "$work_root/horus-pages-domain.XXXXXX")"
trap 'rm -f "$response_file"' EXIT

api_url="https://api.cloudflare.com/client/v4/accounts/$CLOUDFLARE_ACCOUNT_ID/pages/projects/$project/domains"

curl --fail-with-body --silent --show-error \
  --header "Authorization: Bearer $CLOUDFLARE_API_TOKEN" \
  "$api_url" \
  --output "$response_file"

jq -e --arg domain "$domain" '
  .success == true and
  ([.result[]? | select(.name == $domain and .status == "active")] | length == 1)
' "$response_file" >/dev/null

echo "Cloudflare Pages custom domain $domain is active on project $project."
