# Monetization Readiness

Horus Media computes website monetization health in `SiteMonetizationReadinessService`. Blade templates render the structured result; they do not decide monetization health. Per-site serving-engine capability comes from `SiteEngineStateResolver`; readiness does not recreate GAM/Prebid/Direct-JS eligibility independently.

## Status vocabulary

- `ACTIVE` — the module's required persisted runtime state is active.
- `READY` — the module is operationally configured but its final serving runtime is not yet active or the module is configuration-only.
- `ACTION_REQUIRED` — a required dependency is missing or failed and an explicit action is required.
- `PENDING` — the site/module is waiting for a lifecycle or first-data event.
- `PAUSED` — a site, serving mode, static configuration, or operations control intentionally stops the module.
- `DEGRADED` — the module is configured/operating but persisted health or freshness is below normal.
- `NOT_CONFIGURED` — an unused optional/recommended integration is not configured.

## Dependency levels

- `CRITICAL` affects whether the overall site can be monetized under its current serving mode.
- `RECOMMENDED` may degrade the overall experience but does not replace a critical serving dependency.
- `OPTIONAL` never makes an otherwise healthy site look broken merely because the product is unused.

### GAM modes

For `HORUS_GAM`, `MCM_PARTNER_GAM`, and `PUBLISHER_GAM`, GAM/Display remains a critical dependency. A missing required GAM connection remains `ACTION_REQUIRED`; disabled/failed/degraded connection health keeps the existing semantics. Prebid in these modes remains the existing `GAM_BRIDGE` context.

### HORUS_DIRECT

For `HORUS_DIRECT`, GAM is optional. A site with no GAM connection reports the GAM/Display module as `NOT_CONFIGURED` / `OPTIONAL`, not broken.

Standalone Prebid and Direct JS may independently become critical when they are the configured monetization engines for the site. A complete standalone Prebid foundation reports `READY` during Task 14 because the direct winning-bid renderer is intentionally delivered by a later browser-runtime task. Once that renderer is deployed and verified, the module may report `ACTIVE` without any GAM connection.

Approved Direct JS / Native Network demand may already report `ACTIVE` without GAM when an eligible direct placement exists. GAM-managed demand is not treated as a substitute for a required Direct JS placement on a `HORUS_DIRECT` site.

`DIRECT_NATIVE_ONLY` retains its legacy specialized semantics and is not silently migrated to `HORUS_DIRECT`.

Ads.txt is critical when the canonical compliance service says records are required. Click Guard remains optional. Consent/privacy and reporting remain recommended operational dependencies.

## Operational controls

`AD_SERVING` is the master kill switch and makes the site overall `PAUSED` while disabling all engine/placement eligibility.

Engine controls are independent:

- `GAM` pauses only GAM delivery.
- `PREBID` pauses only Prebid.
- `DIRECT_JS` pauses direct provider JavaScript.
- legacy `NATIVE_DEMAND` remains a broader compatibility control while old payload/runtime behavior is supported.

A GAM kill switch does not make otherwise healthy Direct JS ineligible. A Prebid kill switch does not disable Direct JS. A Direct JS kill switch does not disable standalone Prebid and must not remove GAM-managed demand or House content from static configuration.

## Overall status

A suspended site or any effective master ad-serving pause is `PAUSED`. A non-active lifecycle is `PENDING`. For active sites, critical `ACTION_REQUIRED`, `PAUSED`, `DEGRADED`, or `PENDING` conditions determine overall state in that order. A degraded recommended module can degrade an otherwise active site. Optional modules do not block overall `ACTIVE`.

A `READY` critical foundation does not by itself make the site look broken; the reason text explains the remaining runtime rollout. A site is never reported healthy while master ad serving is paused.

## Data sources and side effects

Normal page rendering reads persisted Horus state only. It does not synchronously call Google, bidder/demand providers, or reporting providers. Ads.txt readiness reuses `AdsTxtComplianceService::summary()` and stored verification content; it does not re-fetch the publisher domain.

GAM-bridge Prebid readiness deliberately does not call `PrebidManager::settingsFor()` because that method can create defaults. Standalone Prebid reads the persisted active `PrebidBuild` and existing bidder/site/placement mappings directly and does not create GAM-scoped `PrebidSetting` rows or synthetic remote IDs.

## Publisher-safe versus Admin output

Publisher output contains only product-level status, reason, dependency level, last known update, and safe next action. Native provider identity is abstracted to **Native Network**. Publisher output never includes provider names, bidder/account identifiers, GAM network/account diagnostics, credentials, revenue share, Horus margin, partner deductions, internal notes, debug configuration, or raw diagnostic metadata.

Admin Site 360 receives the same core calculation plus technical diagnostics such as serving-engine state, resolved GAM connection identity/health when applicable, inventory counts, static delivery status, managed Prebid build/mapping counts, Native provider/account labels, compliance counts, and persisted reporting-source health. Credentials and secrets are never included.

White-labeling is limited to product UI. It does not change required ads.txt/sellers.json/schain information, required ad attribution, or network traffic routing.
