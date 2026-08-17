#!/usr/bin/env bash
set -euo pipefail

# GitHub codeload ZIPs can be independently rate-limited. CI installs the exact
# source references already locked in composer.lock, then exports each checkout
# using the package's own .gitattributes/export-ignore contract before Composer
# builds the optimized autoloader. This avoids source-only test/monorepo files
# without suppressing warnings or changing dependency versions.
composer install --prefer-source --no-autoloader --no-interaction --no-progress "$@"

while IFS= read -r -d '' package_dir; do
    if ! git -C "$package_dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        continue
    fi

    export_dir="$(mktemp -d)"
    git -C "$package_dir" archive --format=tar HEAD | tar -xf - -C "$export_dir"

    rm -rf "$package_dir"
    mkdir -p "$package_dir"
    cp -a "$export_dir"/. "$package_dir"/
    rm -rf "$export_dir"
done < <(find vendor -mindepth 2 -maxdepth 2 -type d -print0)

dump_args=(--optimize --no-interaction)
for arg in "$@"; do
    if [[ "$arg" == "--no-dev" ]]; then
        dump_args+=(--no-dev)
        break
    fi
done

composer dump-autoload "${dump_args[@]}"
