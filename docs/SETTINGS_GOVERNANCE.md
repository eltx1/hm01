# Typed Global Settings & Governance

## Purpose

Horus Media exposes only a controlled registry of safe business/product settings. The Settings UI is not a generic key/value editor: an administrator cannot invent a key, change its type, or surface a credential. Unknown keys are rejected server-side.

Runtime precedence is:

1. explicit `global_settings` database override for a registered runtime-editable key;
2. the existing Laravel config / ENV-derived fallback captured at boot.

An empty or not-yet-migrated Settings table is valid. Application boot continues with existing config values. Cache failure also falls back safely and does not introduce a Redis requirement.

## Registered runtime-editable settings

| Key | Group | Type | Config fallback | Impact |
| --- | --- | --- | --- | --- |
| `general.company_name` | GENERAL | string | `app.name` | Public product/company name. |
| `supply_chain.manager_domain` | SUPPLY CHAIN | normalized domain | `supply-chain.manager_domain` | **High impact**: Ads.txt `MANAGERDOMAIN`, sellers/schain identity and future static publication. Requires reason, current password, typed confirmation and audit. |
| `supply_chain.contact_email` | SUPPLY CHAIN | email | `supply-chain.contact_email` | Public sellers.json contact. |
| `supply_chain.contact_address` | SUPPLY CHAIN | string | `supply-chain.contact_address` | Public sellers.json contact. |
| `supply_chain.tag_id` | SUPPLY CHAIN | nullable constrained string | `supply-chain.tag_id` | Optional public TAG-ID. |
| `supply_chain.ads_txt_fresh_for_days` | SUPPLY CHAIN | bounded integer | `ads-txt.fresh_for_days` | Compliance freshness policy only; does not alter SSRF limits. |
| `reporting.discrepancy_warning_bp` | REPORTING | bounded integer | `reporting.discrepancy_warning_bp` | Reconciliation warning policy. |
| `reporting.retry_delay_minutes` | REPORTING | bounded integer | `reporting.retry_delay_minutes` | Aggregated-report retry policy. |
| `reporting.hourly_lookback_hours` | REPORTING | bounded integer | `reporting.hourly_lookback_hours` | Routine hourly import window. |
| `reporting.daily_lookback_days` | REPORTING | bounded integer | `reporting.daily_lookback_days` | Routine daily import window. |

All definitions declare key, group, type, config fallback/default, validation rules, optional allowed values, description, sensitivity, runtime editability, and impact. The typed engine also supports boolean and enum definitions; enum values are allowlisted rather than accepted as arbitrary strings.

## Configuration classification

### A. Safe business / product settings

The registered values above are safe for controlled Admin governance. They are public or non-secret policy values and have bounded semantic validation.

Support SLA targets are also safe business policy, but they already have a first-class `SupportSlaPolicy` domain model with ticket snapshots and lifecycle behavior. They remain there instead of being duplicated as global Settings.

Notification channel choices remain typed per-user `notification_preferences`; there is no global digest product to configure, so Task 11 does not invent one.

### B. Secrets / credentials — never ordinary Settings

The following remain ENV-only or in their existing encrypted credential models:

- `APP_KEY`, database passwords and connection credentials;
- mail passwords and SMTP credentials;
- AWS/object-storage credentials;
- GAM service-account files, OAuth/client secrets and access tokens;
- demand-provider API credentials and encrypted credential references;
- GitHub/Cloudflare edge tokens;
- payment destination data, bank/routing/IBAN/SWIFT/tax identifiers;
- encryption/signing/private keys and recovery secrets.

`GamConnection`/credential infrastructure, demand credential rows, and encrypted Publisher payment profiles remain the authoritative credential domains. Settings never copies or reveals those values.

### C. Infrastructure / deployment configuration

These remain code/ENV/server managed because changing them is a deployment concern, security boundary, or serving architecture choice:

- application environment/debug/URL, database/cache/session/queue drivers;
- CDN URL, permanent Horus Loader URL, GPT URL and static-delivery driver/branch/token/reference/budgets/file limits;
- GAM REST/SOAP endpoints, timeouts, retries and application identity;
- Ads.txt network timeouts, redirect limit, maximum response bytes and user agent;
- support attachment size/MIME allowlist and malware-scanner configuration;
- report CSV/invoice upload size limits and report-source connector catalog;
- browser Prebid build/module configuration and provider connector classes.

The permanent Loader, `HORUS_GAM` default and Laravel-not-in-ad-request-path architecture are not runtime-editable settings.

### D. Operational controls

Emergency and runtime controls remain in **Operations**, including platform/site/placement/GAM/demand disable controls, static-delivery retry/rollback and loader rollback. They are intentionally not represented as Settings.

### E. Contract / Publisher / domain-specific configuration

These remain in their owning domains rather than global Settings:

- Publisher revenue share, payment threshold, invoice requirement and contract terms;
- Publisher payment method/destination and verification state;
- revenue rules, versions, adjustments, financial periods and payout state;
- site serving mode, `HORUS_GAM`/MCM/Publisher GAM assignment, inventory, Prebid, native demand and Click Guard site configuration;
- seller declarations, seller IDs, publisher business domains, demand account mappings and Ads.txt seller-account relationships;
- Support ticket priority/SLA snapshot and per-priority `SupportSlaPolicy` records;
- per-user notification preferences.

This separation prevents a global setting from silently overriding contractual, tenant-scoped, financial, or operational state.

## Storage and security model

`global_settings` uses a unique controlled key, JSON typed scalar value, `changed_by`, and timestamps. It stores no arbitrary metadata and no secrets. Registry membership is the authorization boundary for keys; database rows with unknown keys are ignored by runtime resolution and cannot be created through the UI.

`settings.view` controls read access. `settings.manage` controls mutation. Super Admin and Operations Admin are high-trust managers; Ad Ops Admin and Finance Admin receive read-only view by default. Publisher, Advertiser and Partner roles receive neither permission.

All writes use authenticated, verified, 2FA-protected web routes with CSRF middleware and the sensitive-action rate limiter. High-impact manager-domain changes additionally require current password, a reason and exact typed confirmation.

Changes and resets emit `settings.global.updated` / `settings.global.reset` AuditLog events containing only safe before/after values, group, impact flag and bounded reason. Secret fields are not part of the registry and therefore cannot enter those events.

## Cache and deployment behavior

Overrides use the currently configured Laravel cache store for a short bounded cache. Cache invalidation happens immediately after mutation/reset. Cache exceptions fail safely to database reads, and missing database tables fail safely to config fallbacks. Redis is not required.

A Settings migration therefore needs no data backfill. Deploying code before any override leaves existing behavior unchanged. Rolling the migration back removes only runtime overrides and causes the application to use the original config/ENV fallbacks again.

Supply-chain manager/contact changes affect newly generated deterministic artifacts. They do not alter credentials, route ad requests through Laravel, or change publisher Loader integration code. Operators should confirm the public manager-domain deployment and static publication as part of the documented supply-chain rollout procedure.
