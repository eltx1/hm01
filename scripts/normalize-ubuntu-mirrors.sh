#!/usr/bin/env bash
set -euo pipefail

# GitHub runner images can use either legacy lists or Deb822 source files.
# Only replace Ubuntu's Azure mirror; retain suites, components and trust keys.
apt_sources_root="${1:-/etc/apt}"
shopt -s nullglob
for source_file in "$apt_sources_root/sources.list" \
    "$apt_sources_root/sources.list.d/"*.list \
    "$apt_sources_root/sources.list.d/"*.sources; do
    [[ -f "$source_file" ]] || continue
    if grep -Eq 'https?://azure\.archive\.ubuntu\.com/ubuntu([/[:space:]]|$)' "$source_file"; then
        sed -i -E 's#https?://azure\.archive\.ubuntu\.com/ubuntu([/[:space:]]|$)#https://archive.ubuntu.com/ubuntu\1#g' "$source_file"
    fi
done
