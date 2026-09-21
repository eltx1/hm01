# Google reporting Connect handoff

The website's Connect with Google form submitted successfully to Horus but used a
cross-origin HTTP redirect afterward. Chromium applies the source document's
`form-action 'self'` policy to that redirect, preventing Google from opening and
leaving the original form in its submitted state.

The POST now returns a same-origin, private/no-store handoff document. Its native
zero-delay refresh navigates to the fixed Google authorization endpoint; a visible
Continue to Google link provides a fallback. Neither path depends on JavaScript.
The existing CSP remains unchanged. Referrers are suppressed, and form/CSRF data
stay on Horus. The same session-bound PKCE/state, cached ad unit, consent callback,
network discovery and reporting-only isolation remain authoritative.

Changed files:
- `app/Http/Controllers/Admin/GamReportingAccountController.php`: use a document
  handoff for both current start and legacy setup routes.
- `resources/views/admin/gam/reporting-google-redirect.blade.php`: automatic
  navigation, visible Google link and return-to-reports link using existing styles.
- `resources/views/admin/gam/reporting-google-button.blade.php`: explicit submit
  type and visible Connecting to Google feedback.
- `tests/Feature/GamReportingOnboardingTest.php`: adapt consent entry assertions;
  verify strict CSP, private response, escaped URL, no secrets and preserved unit.
- `tests/Browser/site-gam-reporting.playwright.spec.js`: reproduce the old Chromium
  failure and exercise the actual handoff template, no-JavaScript navigation and
  fallback link with Google intercepted, on the existing Chromium/WebKit matrix.
- This report.

Validation:
- Targeted onboarding: 16 passed, 193 assertions.
- All reporting/onboarding feature tests: 43 passed, 389 assertions.
- Pint, Blade compilation and git diff --check: passed.
- Local real-browser run could not launch: the pinned Chromium/WebKit binaries
  are not installed. The hosted browser workflow installs both and is required
  before merge; this is not recorded as a browser pass.
- Full hosted PHP/MySQL/browser results and deployment evidence are recorded in
  the pull request after CI completes.

Scope: no serving, inventory, loader, report aggregation, or finance changes.
Google consent by the account owner remains required before actual synchronization.
