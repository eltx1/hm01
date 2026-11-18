# Immutable Loader pilots

The default remains the permanent Loader and no site is automatically enrolled.
An authorized Horus administrator can open a site's Loader pilot screen, select a
committed archive and provide a reason. Existing sites/configuration permissions,
CSRF, audited writes and normal static publication apply. A stale form is rejected;
an unchanged selection creates no duplicate production version. Inactive websites
save their selection without activation or a production publish.

Each archive has an exact byte checksum and a composed-source runtime identity.
The same semantic version can therefore identify different serving builds. The
registry accepts only fixed local filenames, validated bytes, known admission
protocol and public identity fields. Archived code is published under immutable
runtime/loader paths and retained in later snapshots. Build tooling never removes
referenced generations automatically; archive cleanup requires separate review.

New bootstrap code compares the selected runtime identity, loads only an approved
same-CDN immutable path with Subresource Integrity/CORS, verifies the installed
runtime identity, and then uses the established verified-admission handoff. A
missing/modified/wrong archive fails closed for that pilot. No default site adds
a handoff request, and a matching build is not fetched again. Older cached
bootstrap code must reload before it can honor new pins.

Start with a controlled internal site. Confirm production publication separately
from the runtimeBuild reported by that page's local startup trace. Compare the
same device/browser/network conditions with Default, and retain the baseline.
Return to Default to recover through normal delivery; reload a page to adopt the
new configuration. Current pages are not deliberately switched mid-page. Provider,
Cloudflare and Click Guard controls remain authoritative. No live site is selected
by these tests or by deployment.

Before reverting application code to a release that does not understand these pin
records, return pilot sites to Default and confirm delivery. Retaining archives in
this feature's snapshots does not establish compatibility with every older app
or every older static-delivery implementation. Automatic bad-canary promotion or
automatic global rollback is not added.

Validation so far: PHP 8.4 pilot/registry/static-delivery/inventory suites: 43 tests,
304 assertions passed. Full Node browser unit suite: 1114 passed, 36 intentional
skips, zero failures. Added real-browser scenarios for verified PASS, rejected
admission and SRI tampering; their Chromium/Pixel/WebKit/iPhone CI result is pending.
Playwright is not installed in the local Node environment, so those scenarios are
not reported as locally passed. Live pilot activation, physical Android evidence
and complete release/deployment verification remain pending.
