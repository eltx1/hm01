# Optional reCAPTCHA v3 observation

Admin → Sites → selected site → Serving Control Center → Google reCAPTCHA v3.

Disabled by default, independently per site. Enable with a v3 site key registered for the publisher domain. An optional secret can be stored encrypted for future use; it is never returned to the UI, public config, CDN, audit payload or validation old input. Blank retains it; Remove clears it. Saving uses normal static publication for active sites. No site is enabled automatically.

The loader schedules one background attempt per page only after an actual verified Cloudflare PASSED state. DISABLED is not PASS. No CAPTCHA fetch/preconnect occurs before this boundary. A one-second delay and idle scheduling give ad startup priority; the script fetch has low priority. All errors, timeouts and rejected promises are contained. No result is read by the ad or protection decision paths. Existing publisher reCAPTCHA is not replaced or reconfigured: observation skips that page to prevent conflicts. Late callbacks recheck the current configuration and PASS; there are no retries or refresh loops.

The action is `horus_cf_pass`. Tokens are discarded, never posted to Horus, stored or verified. Google Admin analytics describe only executed observations after Cloudflare PASS, not total traffic or verified ad invalid-traffic rates. Charts require sufficient traffic. No real-key dashboard result is claimed by automated tests.

A third-party script consumes network/CPU even with the badge hidden. Absolute zero performance/UI impact cannot be guaranteed; this implementation removes control-flow dependencies and leaves the feature off unless selected by an admin. No changes to Google's messages, Turnstile rules, CSP, placement scheduling or ad auctions.

Validation covers default-off, no PASS/no SDK, verified PASS, duplicate boots, failed/hung SDK, thrown/rejected execute, stale config/late callbacks, publisher CAPTCHA coexistence, secret confidentiality, authorization and static publication. Browser fixtures mock Google and never send real visitor tokens. Rollback: disable the per-site option and allow normal CDN publication, or revert the code release. Already loaded third-party JavaScript cannot be unloaded from an existing page; settings take effect on newly loaded config/pages.

## Checking a live page

Open a page of the site in a normal browser, wait for the ads, then run in the browser console:

```js
HorusMediaLoader.getRecaptchaObservation()
```

It returns local, read-only evidence; nothing is sent to Horus and neither the token nor the site key is included:

- `cloudflare`: the current traffic-gate state (observation starts only at `PASSED`).
- `phase` / `reason`: `not_started` + `CLOUDFLARE_*` (waiting for or not given a PASS), `publisher_recaptcha_present`, `cancelled` + why (`CONFIG_REPLACED`, `SITE_PAUSED`, `HOST_NOT_ALLOWED`, `CLOUDFLARE_*`), `unavailable` + `SDK_NETWORK_ERROR` / `SDK_API_MISSING` / `EXECUTE_REJECTED` (with Google's message, key redacted), `timeout` + `SDK_LOAD_TIMEOUT` / `GOOGLE_READY_TIMEOUT` / `EXECUTE_TIMEOUT`, or `completed`.
- `tokenReceived`: Google returned a token for `horus_cf_pass` (the token itself is discarded). `completed` with `EMPTY_TOKEN` means Google answered without one.
- `googleRequests`: how many reCAPTCHA anchor/reload requests this page made, from the browser's own resource timing.
- `timingsMs`: when the attempt was scheduled, started, loaded the SDK and finished.

Sites with Loader debug enabled also log each phase with `console.info`. When `phase` is `completed` with `tokenReceived: true` but the Google console stays empty, the key's type (must be Score based v3), its domain list, or Google's minimum-traffic threshold are the remaining causes; the badge that would show Google's site-owner error is intentionally hidden.

References:
- https://developers.google.com/recaptcha/docs/v3
- https://developers.google.com/recaptcha/docs/analytics

Badge: hide only the badge whose Google anchor uses this exact site key, using visibility:hidden (not display:none). A bounded MutationObserver catches creation before paint; it never globally hides publisher CAPTCHA. Google's visible branding disclosure is still required in the publisher user flow; the admin form explains it. No publisher notice is injected automatically. https://developers.google.com/recaptcha/docs/faq/
