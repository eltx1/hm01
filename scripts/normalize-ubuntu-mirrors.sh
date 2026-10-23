#!/usr/bin/env bash
set -euo pipefail

# GitHub runner images can use either legacy lists or Deb822 source files.
# Replace Ubuntu's Azure mirror and the official runner mirror indirection;
# retain suites, components and trust keys. The runner's apt-mirrors.txt gives
# the Azure mirror first priority even when sources contain no Azure URL.
apt_sources_root="${1:-/etc/apt}"
shopt -s nullglob
for source_file in "$apt_sources_root/sources.list" \
    "$apt_sources_root/sources.list.d/"*.list \
    "$apt_sources_root/sources.list.d/"*.sources; do
    [[ -f "$source_file" ]] || continue
    if grep -Eq '(https?://azure\.archive\.ubuntu\.com/ubuntu|mirror\+file:/etc/apt/apt-mirrors\.txt)([/[:space:]]|$)' "$source_file"; then
        sed -i -E 's#(https?://azure\.archive\.ubuntu\.com/ubuntu|mirror\+file:/etc/apt/apt-mirrors\.txt)([/[:space:]]|$)#https://archive.ubuntu.com/ubuntu\2#g' "$source_file"
    fi
done
