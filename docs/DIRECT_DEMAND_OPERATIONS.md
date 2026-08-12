# Direct Demand Admin Operations

This document defines the Horus-only control-plane workflow for the Direct Demand engine introduced by `DIRECT_DEMAND_ENGINE.md`.

## Information architecture

Horus Admin uses the existing Demand domain and routes under **Monetization → Direct Demand**. The normalized models remain `DemandNetwork`, `DemandAccount`, `DemandSite`, `DemandPlacement`, and `DemandWidget`; no second native/direct domain is introduced.

The control center covers network policy, account configuration, website mappings, placement mappings, tags/widgets, operational health, reporting, and links into the canonical Ads.txt Compliance Center.

Publisher users do not receive these Admin routes. Publisher-facing monetization health remains white-label and exposes no provider credentials, confidential account identifiers, or commercial terms.

## Operational precedence

- `AD_SERVING OFF` remains the platform-wide master stop for every serving engine.
- `DIRECT_JS OFF` at platform/site/placement/network scope suppresses Direct Demand while leaving unrelated GAM/Prebid delivery eligible according to their own controls.
- the legacy `NATIVE_DEMAND` compatibility control remains understood during rollout.
- persistent network/account/site/placement enabled flags remain lifecycle gates in addition to operational controls.

High-impact platform/network runtime changes require an operator reason and are recorded through `PlatformControlService`. Affected active sites receive new static production config versions; publisher installation code never changes.

## Network policy

Horus Admin may control:

- connector enabled state;
- Direct JS capability;
- approved formats (`DISPLAY`, `NATIVE`, `VIDEO`, `OUTSTREAM`);
- approved integration modes;
- approved HTTPS script origins;
- operational health metadata.

The control-plane origin cannot be configured as a provider script origin. Direct JS placement mapping validates the selected provider format and any configured provider size allowlist before publication.

## Accounts

The existing account workflow manages provider-issued public account IDs, integration mode, enable state, approval lifecycle, revenue share, fallback priority, reporting method, default render timeout, approved public script origins, and server-side credential references.

Credential references remain encrypted/server-side and are never copied into static delivery payloads. Reporting remains provider API or approved CSV/manual import through `DemandReportService` and unified reporting.

## Website and placement mappings

Website and placement mappings each have a simple local ON/OFF delivery toggle in addition to provider synchronization actions. Toggle changes are audited and publish a fresh static production configuration for active sites.

Remote provider IDs are stored only when supplied by the provider/operator. Horus does not invent remote site, placement, zone, widget, or account IDs.

## Tag review and approval

The tag preview endpoint calls the Task 17 connector parser and renders the result as escaped Admin HTML. It never executes pasted markup.

The preview displays detected scripts, container data, public identifiers/attributes, unsupported inline code, warnings, and the normalized structured recipe when one can be produced safely.

For a normal Direct JS provider, an approved widget containing a public tag must parse to a safe structured recipe. Explicit Admin review approval is required before an `APPROVED` widget can publish.

For `CUSTOM_THIRD_PARTY_TAG`, arbitrary markup is never promoted to top-window JavaScript. Approval requires explicit isolated provider origins, and the connector must successfully produce the opaque-origin `allow-scripts` iframe/CSP recipe inside the same database transaction. Failure rolls back the widget approval.

## Ads.txt and reporting

Direct Demand reuses the canonical Ads.txt Compliance Center and `DemandReportService`; there is no second compliance/reporting system and no browser-to-Laravel raw impression event stream.

## Static configuration and rollback

Every delivery-affecting Admin mutation republishes affected active production configs using `SiteConfigPublisher`. Existing config version history, immutable payload checksums, static delivery outbox, and rollback behavior remain unchanged.
