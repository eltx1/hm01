# Native and Alternative Demand Networks

## Multi-engine serving architecture

Native and alternative demand is an optional control-plane layer with two
fundamentally different delivery paths:

1. **GAM-managed demand** — Horus deploys a third-party creative and line item to
   the GAM connection selected for the website. GPT remains the browser delivery
   path.
2. **Direct JS engine** — the permanent Horus Loader injects an approved public
   JavaScript tag when that physical placement is assigned to Direct JS.

GAM remains a first-class/default engine for GAM-enabled sites but is not a
universal prerequisite. Direct JS is an independent serving engine: it does not
enter a Prebid auction, does not require GAM, and does not need to lose a
Prebid/GAM request before it may run. A site may run standalone Prebid on one
placement and Direct JS on other independent placements at the same time.

No global winner is created between Prebid and Direct JS. One physical placement
must still have one renderer at a time. GAM-slot no-fill fallback to Direct JS
remains a valid backward-compatible behavior for configured GAM placements, but
it is no longer the only conceptual way Direct JS may operate.

See `MULTI_ENGINE_SERVING.md` for the authoritative serving-engine contract.

Publishers continue to install only:

```html
<script async
        src="https://cdn.horusmedia.net/hm-loader.js"
        data-site-key="PUBLIC_SITE_KEY"></script>
```

and the existing placement container:

```html
<div class="hm-ad" data-placement="article_native"></div>
```

Adding, disabling, reprioritizing, or removing a demand network publishes a new
static configuration version. It never requires publisher code changes and
never routes an impression through Laravel.

## Data model

- `demand_networks`: connector registry and public capabilities.
- `demand_accounts`: Horus, publisher, or MCM-partner account definitions.
- `demand_account_credentials`: encrypted `env:` or `file:` references only.
- `demand_sites`: account-to-website assignments and remote website IDs.
- `demand_placements`: placement assignments, approval, mode, and priority.
- `demand_widgets`: provider widget mappings and approved templates.
- `demand_ads_txt_records`: normalized provider seller declarations.
- `demand_report_imports`: immutable aggregated API or CSV imports.
- `demand_remote_objects`: provider and GAM local-to-remote mappings.
- `demand_sync_logs`: sanitized dry-run and execution history.
- `demand_errors`: categorized, retryable, and resolvable failures.

Historical reports and remote mappings are not deleted when an account or
connector is disabled.

## Connector registry

Initial connectors:

- `MGID`
- `TABOOLA`
- `SPEAKOL`
- `OUTBRAIN`
- `CUSTOM_NATIVE`
- `CUSTOM_DISPLAY`
- `CUSTOM_THIRD_PARTY_TAG`

Every registry entry names a class implementing
`DemandConnectorInterface`. Adding a connector does not require changing the
connector manager; the manager validates and instantiates the registered class.

The interface provides:

```text
testConnection()
validateConfiguration()
createSite()
getSiteStatus()
createPlacement()
getPlacementCode()
generateDirectTag()
generateGamCreative()
getAdsTxtRecords()
runReport()
pausePlacement()
activatePlacement()
```

All provider writes support dry-run, use deterministic idempotency keys where
the provider accepts them, and persist remote IDs before a retry can advance.

## Integration modes

- `DIRECT_JS`: Loader injects the approved public script for a placement owned by
  the Direct JS renderer. It may also remain configured as a GAM no-fill
  fallback where backward-compatible placement policy explicitly requests that
  behavior.
- `GAM_THIRD_PARTY_CREATIVE`: Horus deploys an advertiser, order, line item,
  third-party creative, association, inventory targeting, and status controls.
- `GAM_LINE_ITEM`: the same isolated deployment surface, available for accounts
  whose commercial setup uses provider-managed GAM demand.
- `MANUAL_TAG`: an administrator supplies a reviewed public tag configuration;
  raw credentials remain prohibited.
- `API_INTEGRATION`: approved API paths may create or synchronize remote sites,
  placements, status, ads.txt records, and aggregated reports.

The effective demand integration mode is resolved in this order:

1. placement override;
2. website assignment override;
3. account default.

This demand integration mode is distinct from the site serving mode and from the
site-level serving-engine state.

## Account scopes and approval

Accounts are scoped as `HORUS_MEDIA`, `PUBLISHER`, or `MCM_PARTNER`.
Publisher-scoped accounts may only be assigned to websites owned by that
publisher. Administrators configure the default account, revenue share,
fallback priority, integration mode, enabled state, approval state, and public
provider identifiers.

Approval states are:

- `NOT_SUBMITTED`
- `PENDING`
- `APPROVED`
- `REJECTED`
- `SUSPENDED`

A public configuration includes a mapping only when the network, account,
website assignment, placement assignment, and selected widget are enabled and
approved. Rejected or suspended placements never run.

## Credential boundary

The database stores only Laravel-encrypted references such as:

```text
env:MGID_API_TOKEN
file:/home/account/private/mgid-token.txt
```

Credential files must be readable and outside the public web directory.
Configuration JSON rejects secret-like keys. Static configuration contains only
allowlisted HTTPS script URLs, public widget IDs, public attributes, priorities,
and sanitized house content. It never contains credential references, tokens,
revenue shares, account notes, or private API endpoints.

## MGID connector

MGID is the first complete connector implementation. It supports:

- account and provider-issued publisher identifier configuration;
- remote website mapping or an approved create-site API path;
- placement and widget mapping;
- direct JavaScript output with an allowlisted MGID origin;
- GAM third-party creative output;
- normalized ads.txt records;
- approved report API paths and encrypted token references;
- CSV report fallback with duplicate checksum protection.

No token, API endpoint, site ID, or widget ID is invented. Accounts without
approved API access remain fully usable through manual remote mappings, direct
or GAM tags, ads.txt configuration, and CSV reports.

Taboola, Speakol, and Outbrain implement the same interface. Their account pages
remain functional for public IDs, approved tags, ads.txt, and CSV reporting.
API actions become active only after the provider grants access and an
administrator supplies the documented paths and encrypted credential reference.

## Static configuration and Loader

The current public payload contains a `nativeDemand` compatibility object with
an ordered candidate list per placement. `GAM_THIRD_PARTY_CREATIVE` and
`GAM_LINE_ITEM` candidates are marked `gamManaged` and expose no direct tag.
Direct candidates contain only an allowlisted script URL, public container
information, public data attributes, a bounded render timeout, and an optional
success selector.

Existing GAM-placement fallback remains:

1. Prebid/GAM request.
2. If the GAM slot is empty and fallback policy permits it, try approved direct candidates by priority.
3. Continue after script error or verified no-render.
4. Render sanitized house content when configured.
5. Stop safely without breaking the publisher page.

Independent Direct JS placement serving is also an approved architecture:

1. Loader resolves the placement to the Direct JS renderer.
2. No GAM or Prebid request is required for that placement.
3. Loader attempts approved direct candidates by priority.
4. It may render sanitized house content if configured.
5. It stops safely on failure.

A direct-only placement can run without an ad-unit path while using the same
`.hm-ad[data-placement]` publisher markup. Debug mode exposes only delivery state,
selected public network code, and failure category.

## GAM deployment

For the GAM connection resolved from a GAM-enabled website, Horus creates a
deterministic plan containing:

- one advertiser company per demand account;
- one order per account and website;
- one line item per approved GAM-mode placement;
- one third-party creative per approved widget or placement;
- one creative association;
- selected ad-unit targeting, sizes, cost settings, and active dates/status.

Dry-run performs no Google write. Remote objects are mapped in
`demand_remote_objects`, while the existing GAM operation ledger retains its
normal audit and retry behavior. A repeated deployment skips unchanged payload
hashes. A partial failure preserves completed mappings, so retry resumes without
duplicating earlier objects. Pausing or resuming a local GAM-mode placement
performs the corresponding line-item action before publishing the next static
configuration.

A `HORUS_DIRECT` site does not require this GAM deployment path for Direct JS
placements.

## Reporting

Connectors import aggregated daily rows only. API responses and CSV fallback are
normalized to date, site, placement/widget, impressions, clicks, and revenue in
integer minor units. Raw browser impressions and bids are never posted to
Laravel. Disabling a connector affects future delivery configuration only;
historical imports remain queryable.

## Operations

After changing a network, account, website mapping, placement, or widget, publish
a new production static configuration. The existing Laravel scheduler and
normal request lifecycle are sufficient; no Redis, Supervisor, WebSockets,
Docker, or permanent worker is required.

## Direct Demand compatibility note

The historical Native/Alternative Demand domain is retained for compatibility,
but the product surface is now Direct Demand. Existing native networks, accounts,
widgets, ads.txt records, reports and history remain valid. Task 20 does not
create a second native domain.

