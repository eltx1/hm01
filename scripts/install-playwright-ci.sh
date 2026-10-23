#!/usr/bin/env bash
set -euo pipefail

# Hosted Ubuntu runners may select an Azure regional mirror that stalls while
# downloading WebKit's media dependencies. Use Ubuntu's canonical HTTPS mirror;
# apt still verifies the distribution's signed indexes and package checksums.
sudo bash "$(dirname "${BASH_SOURCE[0]}")/normalize-ubuntu-mirrors.sh"

npx playwright install --with-deps chromium webkit
