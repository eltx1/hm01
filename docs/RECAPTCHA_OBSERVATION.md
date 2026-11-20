# Optional reCAPTCHA v3 observation

Admin → Sites → selected site → Serving Control Center → Google reCAPTCHA v3.

Disabled by default, independently per site. Enable with a v3 site key registered for the publisher domain. An optional secret can be stored encrypted for future use; it is never returned to the UI, public config, CDN, audit payload or validation old input. Blank retains it; Remove clears it. Saving uses normal static publication for active sites. No site is enabled automatically.

Observation is an independent admin monitor that runs in parallel. Once the site configuration is admitted for the page (enabled key, active site, allowed hostname, not paused), the loader schedules one background attempt per page view. It does not wait for, depend on, or influence the Cloudflare traffic gate: it runs whether the gate is off (DISABLED), pending, passed or rejected, and advertising still waits for the gate exactly as before. A one-second delay and idle scheduling give ad startup priority; the script fetch has low priority. All errors, timeouts and rejected promises are contained. No result is read by the ad or protection decision paths. Existing publisher reCAPTCHA is not replaced or reconfigured: observation skips that page to prevent conflicts. Late callbacks recheck the current configuration; there are no retries or refresh loops.

Why the dashboard stayed empty before (fixed): the first version started only after a verified Cloudflare PASSED state. On sites where the Cloudflare gate was off the state is DISABLED, so Google's script was never requested and the reCAPTCHA Admin console received nothing. The browser debug API now reports the phase: `HorusMediaLoader.getRecaptchaObservation()` returns `{ phase, action }` (`loading`, `executing`, `completed`, `publisher_recaptcha_present`, `cancelled`, `timeout` or `unavailable`).

The action is `horus_pageview`. Tokens are discarded, never posted to Horus, stored or verified. Google Admin analytics describe executed observations on eligible page views (not pages that already run the publisher's own reCAPTCHA), not verified ad invalid-traffic rates. Google shows charts only after it has processed enough traffic. No real-key dashboard result is claimed by automated tests.

A third-party script consumes network/CPU even with the badge hidden. Absolute zero performance/UI impact cannot be guaranteed; this implementation removes control-flow dependencies and leaves the feature off unless selected by an admin. No changes to Google's messages, Turnstile rules, CSP, placement scheduling or ad auctions.

Validation covers default-off, every Cloudflare gate state (observation runs and never changes the gate or ad admission), duplicate boots, failed/hung SDK, thrown/rejected execute, stale config/late callbacks, publisher CAPTCHA coexistence, secret confidentiality, authorization and static publication. Browser fixtures mock Google and never send real visitor tokens. Rollback: disable the per-site option and allow normal CDN publication, or revert the code release. Already loaded third-party JavaScript cannot be unloaded from an existing page; settings take effect on newly loaded config/pages.

References:
- https://developers.google.com/recaptcha/docs/v3
- https://developers.google.com/recaptcha/docs/analytics

Badge: hide only the badge whose Google anchor uses this exact site key, using visibility:hidden (not display:none). A bounded MutationObserver catches creation before paint; it never globally hides publisher CAPTCHA. Google's visible branding disclosure is still required in the publisher user flow; the admin form explains it. No publisher notice is injected automatically. https://developers.google.com/recaptcha/docs/faq/
