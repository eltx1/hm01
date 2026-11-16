# Protection evidence and coverage

Operations includes a local comparison of 1–100 manually submitted diagnostic
snapshots. It reads the existing bounded startup trace and optional recorded
Click Guard debug state. No publisher Loader, admission Worker, network request,
storage, sampling or protection rule is added or changed. Input is never posted.
Only allowlisted site keys, device/browser labels, UTC capture day and counts
reach the output. UI errors do not echo input; output uses textContent.

Each group denominator is submitted snapshots, including pending/unknown states.
These are not distinct visitors or verification attempts: startup traces are
deduplicated and truncated, while a retry can follow a terminal event. Dropped
traces remain unknown. Click Guard evidence is a recorded debug snapshot only;
missing evidence is not clear traffic. User-supplied labels/dates are unverified.
Duplicates, selection bias and missing devices prevent population estimates or
fraud rates. Day groups support manual comparisons, not automatic risk decisions.

## Production aggregate boundary

Actual Cloudflare token-validation totals require authoritative server/provider
aggregates. Challenge solves, token validation, gate admission and browser Click
Guard snapshots have different denominators and must not be combined. In this
deployment the central Turnstile hostname/action is shared; it cannot establish
per-publisher attribution by itself. No token, page nonce or raw IP is collected
to manufacture that attribution. Missing production outcome coverage stays
unavailable, not zero. The optional post-PASS speed collector cannot observe
denied visitors, and its pages denominator must not be used as a gate solve rate.

The separate Analytics Engine-backed diagnostics deployment is still blocked by
Cloudflare account availability (403 / code 10089). No production site is enrolled
automatically. Account-side availability, safe aggregate collection, and live
device evidence remain separate acceptance steps. This local review is useful
diagnostic tooling; it does not complete production traffic telemetry.

Changed files: scripts/protection-evidence-summary.mjs,
resources/js/protection-diagnostics.js, resources/js/app.js,
resources/views/admin/operations/index.blade.php,
tests/Browser/protection-evidence.test.js, docs/PROTECTION_EVIDENCE.md.

Validation results are recorded in PLATFORM_EVOLUTION_PROGRESS.md and the PR.
