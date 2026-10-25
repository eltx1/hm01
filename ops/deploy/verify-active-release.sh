#!/usr/bin/env bash
set -euo pipefail
deploy_home=${1:?Deployment home is required}
release_sha=${2:?Release SHA is required}
artifact_sha=${3:?Artifact checksum is required}
[[ "$deploy_home" =~ ^/[A-Za-z0-9/_-]+$ ]]
[[ "$release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$artifact_sha" =~ ^[0-9a-f]{64}$ ]]
active=$(readlink -f "$deploy_home/htdocs/app.horusmedia.net")
[[ "$active" == "$deploy_home/releases/$release_sha" ]]
grep -Fxq "release_id=$release_sha" "$active/.horus-release"
grep -Fxq "artifact_sha256=$artifact_sha" "$active/.horus-release"
printf 'Active production release and artifact verified: %s\n' "$release_sha"
