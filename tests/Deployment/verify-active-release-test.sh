#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /tmp/horus-proof-XXXXXXXX)
trap 'rm -rf "$fixture"' EXIT
sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
checksum=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
mkdir -p "$fixture/htdocs" "$fixture/releases/$sha" "$fixture/releases/previous"
ln -s "$fixture/releases/$sha" "$fixture/htdocs/app.horusmedia.net"
printf 'release_id=%s\nartifact_sha256=%s\n' "$sha" "$checksum" > "$fixture/releases/$sha/.horus-release"
bash "$root/ops/deploy/verify-active-release.sh" "$fixture" "$sha" "$checksum"
if bash "$root/ops/deploy/verify-active-release.sh" "$fixture" "$sha" "${checksum/b/c}"; then
  echo 'Wrong artifact was accepted' >&2; exit 1
fi
ln -sfn "$fixture/releases/previous" "$fixture/htdocs/app.horusmedia.net"
if bash "$root/ops/deploy/verify-active-release.sh" "$fixture" "$sha" "$checksum"; then
  echo 'Rolled-back release was accepted' >&2; exit 1
fi
echo 'PASS: exact release, mismatched artifact, and rolled-back symlink'
