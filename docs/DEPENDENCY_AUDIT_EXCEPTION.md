# Temporary npm audit exception: build-only picomatch GHSA-fc5q-39xc-vjpf

2026-11-18. Upstream advisory GHSA-fc5q-39xc-vjpf (CVE-2026-112366)
reports a high-severity ReDoS in every published picomatch version through
4.0.7. As of this checkpoint npm does not publish a patched release. Running
npm audit fix --force proposes a Vite downgrade, not a verified patch.

Only the **exact reviewed development-only** dependency chain in the current
npm lockfile is accepted until **2026-12-01 UTC**. This affects local/CI
Vite build glob processing, not a shipped browser script or the Laravel/PHP
production dependency set. Build globs are repository-controlled, not
publisher/user-supplied expressions. The release pipeline still compiles
prebid and the Loader, runs browser/PHP/MySQL tests and verifies artifacts.

scripts/audit-npm-ci.mjs first requires a fully clean production npm audit
(--omit=dev), then runs the complete npm audit. Any other advisory, changed
nested dependencies, production dependency, missing audit output, changed
picomatch pin, or expiry **fails the build**. The exception is logged loudly.
No global audit disable, npm --force install, WAF/Turnstile/Click Guard
changes or ad-runtime changes are introduced.

Security follow-up: revisit upstream before the expiry. Once a fixed
picomatch release is available, upgrade Vite-related packages and regenerate
the lockfile, rerun the full matrix, remove the temporary exception and
verify the normal complete npm audit passes. **This is a bounded
development-tool risk acceptance, not a claim that the advisory is fixed.**
