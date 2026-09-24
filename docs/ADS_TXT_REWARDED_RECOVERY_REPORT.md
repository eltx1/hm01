# Ads.txt and rewarded recovery — 24 September 2026

## Production evidence

Read-only production diagnosis (Actions run 35944618273) found 17 latest
ads.txt checks with DOMAIN_NOT_VERIFIED. That error was returned before DNS
or HTTP, so UNREACHABLE incorrectly suggested an unavailable endpoint.
Two active-site probes returned HTTP 200 in 140 ms and 260 ms; the third
was blocked locally by the ownership prerequisite.

## Changes

- app/Services/Compliance/AdsTxtFetcher.php: permit reading the site's public
  primary ads.txt while domain ownership is pending. Keep public DNS/IP
  validation, bounded responses, timeouts and verified redirect host scope.
  No ownership, activation or permissions are granted by this read.
- app/Http/Controllers/Admin/AdsTxtComplianceController.php and
  app/Http/Controllers/Publisher/AdsTxtComplianceController.php: include the
  safe fetch failure explanation in manual-recheck results.
- public/assets/hm-gpt-direct.js: hide rewarded prompts before provider cleanup;
  tolerate failing cleanup/focus; explicit pointer handling; discard malformed
  or late ready callbacks; expire unused prompts after two minutes.
- public/assets/hm-video-direct.js: optional storage access is exception-safe;
  rewarded startup has a 15-second watchdog cleared on playback STARTED;
  dismissal/focus cleanup and synchronous setup failures release the page.
  Existing non-rewarded startup and provider completion-based grants remain.
- tests/Feature/AdsTxtComplianceTest.php: pending-domain success without ownership
  mutation, private-DNS rejection, and unauthorized redirect regressions.
- tests/Browser/rewarded-reading.playwright.spec.js: inherited pointer-events,
  provider/focus exceptions, late callbacks, prompt expiry, silent IMA startup.
- .github/workflows/diagnose-ads-txt.yml: bounded read-only live fetch checks and
  aggregate Workers request/error/CPU query using existing credentials; no
  Cloudflare configuration or permission changes. Runs after live verification.

## Validation

Local Node runtime/browser suite: 234 tests passed, 0 failed.
JavaScript syntax and diff whitespace checks passed.
PHP, MySQL and real Chromium/WebKit desktop/mobile checks run in PR #196.
Local PHP is unavailable; Playwright browser downloads failed in the local
network environment. Do not describe local real-browser testing as passed.
The real-browser test boundary stubs Google SDKs and uses no paid inventory.
It verifies interaction/recovery, not real-world ad fill or publisher hardware.

## Cloudflare interpretation

The $5 Workers plan increases request/CPU allowances. It is not an automatic
fix for Turnstile, WAF challenges, provider no-fill or SDK network latency.
Inspect requests/errors/CPU and the browser's wait stage before recommending
an upgrade. Workers CPU time excludes time spent waiting for network I/O.
The account dashboard is blocked by a Cloudflare verification loop in the
available browser; the read-only existing-token analytics query may be limited
by its current permissions. No permissions are broadened to obtain metrics.

References:
- https://developers.cloudflare.com/workers/platform/pricing/
- https://developers.cloudflare.com/workers/platform/limits/
- https://developers.cloudflare.com/analytics/graphql-api/tutorials/querying-workers-metrics/
