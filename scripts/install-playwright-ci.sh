#!/usr/bin/env bash
set -euo pipefail

# Hosted Ubuntu runners may select an Azure regional mirror that stalls while
# downloading WebKit's media dependencies. Use Ubuntu's canonical HTTPS mirror;
# apt still verifies the distribution's signed indexes and package checksums.
sources=/etc/apt/sources.list.d/ubuntu.sources
if [[ -f "$sources" ]] && grep -q 'http://azure.archive.ubuntu.com/ubuntu' "$sources"; then
    sudo sed -i 's|http://azure.archive.ubuntu.com/ubuntu|https://archive.ubuntu.com/ubuntu|g' "$sources"
fi

npx playwright install --with-deps chromium webkit
