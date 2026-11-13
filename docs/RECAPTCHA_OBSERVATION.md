# Optional reCAPTCHA v3 observation

Admin → Sites → selected site → Serving Control Center → Google reCAPTCHA v3.

Disabled by default, independently per site. Enable with a v3 site key registered for the publisher domain. An optional secret can be stored encrypted for future use; it is never returned to the UI, public config, CDN, audit payload or validation old input. Blank retains it; Remove clears it. Saving uses normal static publication for active sites. No site is enabled automatically.

The loader schedules one background attempt per page only after an actual verified Cloudflare PASSED state. DISABLED is not PASS. No CAPTCHA fetch/preconnect occurs before this boundary. A one-second delay and idle scheduling give ad startup priority; the script fetch has low priority. All errors, timeouts and rejected promises are contained. No result is read by the ad or protection decision paths. Existing publisher reCAPTCHA is not replaced or reconfigured: observation skips that page to prevent conflicts. Late callbacks recheck the current configuration and PASS; there are no retries or refresh loops.

The action is `horus_cf_pass`. Tokens are discarded, never posted to Horus, stored or verified. Google Admin analytics describe only executed observations after Cloudflare PASS, not total traffic or verified ad invalid-traffic rates. Charts require sufficient traffic. No real-key dashboard result is claimed by automated tests.

A third-party script consumes network/CPU and can display Google's normal badge. Absolute zero performance/UI impact cannot be guaranteed; this implementation removes control-flow dependencies and leaves the feature off unless selected by an admin. No changes to Google's messages, Turnstile rules, CSP, placement scheduling or ad auctions.

Validation covers default-off, no PASS/no SDK, verified PASS, duplicate boots, failed/hung SDK, thrown/rejected execute, stale config/late callbacks, publisher CAPTCHA coexistence, secret confidentiality, authorization and static publication. Browser fixtures mock Google and never send real visitor tokens. Rollback: disable the per-site option and allow normal CDN publication, or revert the code release. Already loaded third-party JavaScript cannot be unloaded from an existing page; settings take effect on newly loaded config/pages.

References:
- https://developers.google.com/recaptcha/docs/v3
- https://developers.google.com/recaptcha/docs/analytics
