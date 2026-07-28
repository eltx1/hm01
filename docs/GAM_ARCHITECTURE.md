# Google Ad Manager Architecture

## Fixed default

Horus Media's Google Ad Manager network (`HORUS_GAM`) is the main and default ad
server. A newly created website must persist `HORUS_GAM` as its serving mode.
No compliance, authorization, ownership, MCM, or other business-rule gate may
prevent administrators from selecting or activating it.

Optional alternatives are `MCM_PARTNER_GAM`, `PUBLISHER_GAM`,
`DIRECT_NATIVE_ONLY`, and `PAUSED`.

## Selection model

The website configuration owns the serving-mode selection. The publisher loader
contains only a stable Horus site key and loader version. It contains no fixed
GAM network code, so an administrator can change serving mode without asking
the publisher to edit their page.

The published configuration snapshot will eventually contain the selected
network code, ad-unit mapping, placement behavior, consent-compatible flags,
Prebid targeting policy, and a configuration version.

## API boundary

Laravel will use GAM APIs only for control-plane management and reporting
imports. Browser ad calls go from GPT directly to the configured GAM network.

Future GAM connector writes must:

- expose dry-run output before execution
- use deterministic idempotency keys and safe reconciliation
- audit intent, actor, sanitized request, result, and upstream identifiers
- retry transient failures within a bounded cron execution
- avoid placing credentials in logs, database plaintext, or source control

## Connector records

Future connector records will distinguish Horus, MCM partner, and publisher
credentials while presenting one internal interface. `HORUS_GAM` will be
provisioned as the platform default; missing optional connector records do not
invalidate it.

## Not in this release

No GAM credentials, network codes, API clients, ad units, orders, line items,
creatives, reports, or synchronization jobs are implemented in the foundation.
