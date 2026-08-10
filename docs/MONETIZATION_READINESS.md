# Monetization Readiness

Horus Media computes website monetization health in `SiteMonetizationReadinessService`. Blade templates render the structured result; they do not decide monetization health.

## Status vocabulary

- `ACTIVE` — the module's required persisted runtime state is active.
- `READY` — the module is operationally configured but is not itself an ad-serving activity.
- `ACTION_REQUIRED` — a required dependency is missing or failed and an explicit action is required.
- `PENDING` — the site/module is waiting for a lifecycle or first-data event.
- `PAUSED` — a site, serving mode, static configuration, or operations control intentionally stops the module.
- `DEGRADED` — the module is configured/operating but persisted health or freshness is below normal.
- `NOT_CONFIGURED` — an unused optional/recommended integration is not configured.

## Dependency levels

- `CRITICAL` affects whether the overall site can be monetized under its current serving mode.
- `RECOMMENDED` may degrade the overall experience but does not replace a critical serving dependency.
- `OPTIONAL` never makes an otherwise healthy site look broken merely because the product is unused.

Display is critical for GAM serving modes and optional for `DIRECT_NATIVE_ONLY`. Native Monetization becomes critical only for `DIRECT_NATIVE_ONLY`. Ads.txt is critical when the canonical compliance service says records are required. Header Bidding and Click Guard are optional. Consent/privacy and reporting are recommended operational dependencies.

## Overall status

A suspended site or any effective ad-serving pause is `PAUSED`. A non-active lifecycle is `PENDING`. For active sites, critical `ACTION_REQUIRED`, `PAUSED`, `DEGRADED`, or `PENDING` conditions determine overall state in that order. A degraded recommended module can degrade an otherwise active site. Optional modules do not block overall `ACTIVE`.

A site is never reported healthy while ad serving is paused.

## Data sources and side effects

Normal page rendering reads persisted Horus state only. It does not synchronously call Google, bidder/demand providers, or reporting providers. Ads.txt readiness reuses `AdsTxtComplianceService::summary()` and stored verification content; it does not re-fetch the publisher domain. Prebid readiness deliberately does not call `PrebidManager::settingsFor()` because that method can create defaults.

## Publisher-safe versus Admin output

Publisher output contains only product-level status, reason, dependency level, last known update, and safe next action. Native provider identity is abstracted to **Native Network**. Publisher output never includes provider names, bidder/account identifiers, GAM network/account diagnostics, credentials, revenue share, Horus margin, partner deductions, internal notes, debug configuration, or raw diagnostic metadata.

Admin Site 360 receives the same core calculation plus technical diagnostics such as resolved GAM connection identity/health, inventory counts, static delivery status, managed Prebid build/mapping counts, Native provider/account labels, compliance counts, and persisted reporting-source health. Credentials and secrets are never included.

White-labeling is limited to product UI. It does not change required ads.txt/sellers.json/schain information, required ad attribution, or network traffic routing.
