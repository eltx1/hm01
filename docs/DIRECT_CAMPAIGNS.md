# Direct Advertiser Campaigns and GAM Deployment

## Ownership and fixed architecture

Horus Media is the local source of truth for advertisers, campaigns, budgets, creatives, approvals, invoices, targeting, network instances, delivery totals, and remote object mappings. Advertisers never require Google Ad Manager access.

Google Ad Manager is the direct-campaign ad server. Each selected website resolves its current GAM connection using the existing website routing rules:

1. an enabled connection explicitly selected for the website;
2. the primary `HORUS_GAM` connection for a `HORUS_GAM` website;
3. the selected MCM partner connection for `MCM_PARTNER_GAM`;
4. the publisher-owned connection for `PUBLISHER_GAM`.

A campaign targeting websites on more than one connection is divided into one `campaign_network_instance` per connection. A failed partner or publisher instance never rolls back, repeats, or duplicates a successful Horus instance.

## Local data model

The direct campaign migration extends the existing `advertisers` table and creates:

- `advertiser_users`
- `advertiser_billing_profiles`
- `campaigns`
- `campaign_goals`
- `campaign_targets`
- `campaign_sites`
- `campaign_placements`
- `campaign_creatives`
- `creative_files`
- `campaign_budgets`
- `campaign_network_instances`
- `campaign_delivery_logs`
- `campaign_approval_logs`
- `advertiser_invoices`

The existing `gam_remote_objects` table is deliberately reused. Mappings remain isolated by GAM connection and local object type, so the same campaign and creative receive different remote IDs in different networks.

Money is stored as integer minor units with an ISO currency. Delivery logs store only aggregated daily GAM results. No impression-level, visitor-level, clickstream, or browser event is sent to Laravel.

## Pricing and lifecycle

Supported pricing models: `CPM`, `CPC`, `CPV`, `FIXED_SPONSORSHIP`, `HOUSE`, and `BONUS`.

Supported campaign statuses: `DRAFT`, `PENDING_REVIEW`, `APPROVED`, `SCHEDULED`, `ACTIVE`, `PAUSED`, `COMPLETED`, `REJECTED`, and `ARCHIVED`.

Every transition is written to `campaign_approval_logs` and sensitive operations are also written to the platform audit log.

## Advertiser workflow

The advertiser dashboard supports campaign creation, objective, dates, pricing model, total and daily budgets, unit price, impression and click goals, frequency caps, countries, devices, publishers, websites, placements, creative upload, submission, status, joined reports, billing profiles, and invoice download.

The website selector exposes Horus inventory, not GAM credentials or remote identifiers. The campaign page displays network status without granting GAM access.

## Creative validation

Supported creative types: `IMAGE`, `HTML5`, `THIRD_PARTY_TAG`, `NATIVE`, `VIDEO_VAST`, `TEXT`, and `HOUSE`.

Validation occurs before database approval or GAM deployment:

- extension and detected MIME type must match the creative type;
- configured file-size limits are enforced;
- image dimensions are read from the actual file and checked against declared dimensions;
- landing and click-through URLs must be HTTP or HTTPS and cannot contain credentials;
- SHA-256 prevents duplicate files inside one advertiser organization;
- HTML blocks JavaScript URLs, cookie/storage access, dynamic evaluation, top navigation, embedded objects, inline event handlers, and insecure resources;
- HTML5 ZIP paths cannot escape the archive, `index.html` is required at the root, and referenced relative assets must exist;
- native, VAST, text, and house creatives validate their required fields.

Creative replacement creates a new local creative, marks the prior creative `REPLACED`, deploys a new remote creative and association, and archives the prior remote creative independently in every affected network.

## GAM deployment plan

For each network instance the deterministic plan contains the advertiser company, campaign order, direct line item, approved creatives, creative associations, selected remote ad-unit targeting, optional country and device targeting, frequency caps, allocated network budget, dates, and desired status.

Selected local ad units must already have `gam_remote_objects` mappings in that exact connection. Missing inventory is a deployment blocker instead of silently targeting the wrong network.

Country and device targeting use non-secret maps in `gam_connections.configuration`:

```json
{
  "country_location_ids": {"EG": "2818", "US": "2840"},
  "device_category_ids": {"DESKTOP": "30000", "MOBILE": "30001"},
  "native_creative_template_id": "123456"
}
```

## Idempotency, failures, and reconciliation

Every object has a deterministic local identity, payload hash, and bounded GAM API idempotency key. Re-running an unchanged deployment skips mapped objects. Changed company, order, or line-item payloads use update operations. New creative versions receive new remote IDs.

Deployment processes each network instance separately. A partial or failed instance stores its cursor, completed count, error, and plan. Administrators retry only that network. Successful networks are not executed again.

Local pause and resume call the corresponding line-item action for every deployed instance. The local campaign state remains authoritative, while failures are surfaced per instance for retry.

Reconciliation reads remote line items, compares remote status and local payload hashes, updates `remote_status`, and marks `DRIFTED` when remote state differs. `campaigns:monitor --reconcile` can be run by the normal Laravel scheduler/cron to advance scheduled campaigns, complete expired campaigns, request aggregated delivery reports, and detect drift. It requires no permanent worker.

## Billing and reports

Campaign approval issues one local advertiser invoice using the default billing profile. Invoices can be downloaded as self-contained HTML documents. Tax is controlled with `ADVERTISER_INVOICE_TAX_BPS` and defaults to zero.

Aggregated rows are upserted idempotently by network instance, report date, source, and external report ID. Campaign reports sum all network instances under the one local campaign, preserving a single advertiser-facing report even when delivery spans Horus, MCM partner, and publisher GAM networks.
