# Google Ad Manager Architecture

## Fixed default

Horus Media's Google Ad Manager network (`HORUS_GAM`) is the main and default ad
server. A newly created website persists `HORUS_GAM` as its serving mode. No
automatic ownership, MCM, publisher-GAM, or other business-rule gate prevents
Horus Media administrators from selecting or activating it.

Optional alternatives are `MCM_PARTNER_GAM`, `PUBLISHER_GAM`,
`DIRECT_NATIVE_ONLY`, and `PAUSED`.

## Selection model

The website configuration owns the serving-mode selection. The publisher loader
contains only a stable Horus site key and loader version. It contains no fixed
GAM network code, so an administrator can change serving mode or assign a
specific GAM connection without asking the publisher to edit their page.

Resolution order:

1. An enabled connection explicitly assigned to the website.
2. The single enabled primary `HORUS_GAM` connection for `HORUS_GAM` sites.
3. The best enabled MCM partner connection for `MCM_PARTNER_GAM` sites.
4. An enabled publisher connection owned by the publisher organization for
   `PUBLISHER_GAM` sites.

Assigning one website never changes another website. Serving mode and
connection changes increment the site's configuration version and publish a new
production static configuration. The permanent installation code is unchanged.

## API and browser boundary

Laravel uses GAM APIs only for control-plane management and reporting imports.
Browser ad calls go from GPT directly to the configured GAM network.

```text
Laravel administrator action
  -> local inventory and GAM API synchronization
  -> static public JSON publication

Publisher browser
  -> Horus Loader from CDN
  -> static JSON from CDN
  -> GPT
  -> selected GAM network
```

There is no Laravel request per impression, slot, bid, or refresh. The browser
never receives GAM credentials.

Every connector write:

- supports dry-run output before execution
- uses deterministic idempotency keys and local-to-remote reconciliation
- audits intent, sanitized request, result, attempts, and upstream identifiers
- retries reads safely and retries writes only when the transport confirms that
  retrying cannot duplicate an upstream object
- stores categorized errors without credential material

## Inventory synchronization

Local ad units belong to a website. Synchronization resolves that website's
current GAM connection and creates or updates the corresponding GAM ad unit.
Each connection supplies a non-secret `configuration.root_ad_unit_id` used as
the remote parent.

`gam_remote_objects` stores the local ad-unit ID, remote GAM ID, connection,
payload hash, status, and synchronization time. A matching hash is considered in
sync. A changed hash is reported as a difference and may be deliberately
resynchronized. Changing a site's selected GAM connection produces a separate
mapping in that connection rather than reusing an unrelated remote ID.

Placements are browser slot definitions. They contain the stable placement code,
local ad-unit assignment, fixed/fluid sizes, responsive size mappings,
targeting, lazy loading, refresh, collapse, SafeFrame, and pause state.

## Static configuration

Laravel publishes public static JSON for `PREVIEW`, `TEST`, and `PRODUCTION`.
Each publication creates:

```text
configs/{SITE_KEY}/production.v{VERSION}.json
configs/{SITE_KEY}/production.json
```

The versioned object is immutable evidence. The current alias is the URL fetched
by the loader. Rollback creates a new version copied from the selected prior
payload and records its source instead of editing historical data.

Static configurations include the resolved GAM network code and public slot
settings only. They exclude:

- private keys and credential references
- OAuth material
- internal database IDs that are not required for delivery
- revenue shares and payment data
- contracts and internal notes

An immediate site pause publishes a file that tells the loader to stop before
GPT is loaded. A disabled placement remains in the public configuration for
operational diagnostics but is not defined or requested by the loader.

## Horus Loader and GPT

The permanent publisher script is:

```html
<script async
        src="https://cdn.horusmedia.net/hm-loader.js"
        data-site-key="PUBLIC_SITE_KEY"></script>
```

The loader:

- validates the current hostname against the static configuration
- loads GPT at most once
- defines active GAM slots and responsive mappings
- applies page and placement targeting
- configures lazy loading, empty collapse, SafeFrame, and optional refresh
- supports interstitial and rewarded out-of-page formats
- discovers added placement elements for practical SPA navigation
- supports loader and configuration version selection
- renders diagnostics only in debug mode
- catches failures without breaking the publisher page

The loader and configuration are public CDN resources. No cookie or credential
is needed to retrieve them. See `docs/INVENTORY_AND_LOADER.md` for the complete
runtime and cache policy.

## Version-stable REST integration

Production uses the Google Ad Manager REST `v1` endpoint exclusively. New and
existing connection records are normalized to `REST`; stale non-test driver
values are also routed through `GamRestConnector`. There is no dated SOAP
version in runtime configuration and no silent SOAP fallback.

Google is expanding the REST surface incrementally. Horus currently writes ad
units, placements, custom targeting, orders, and reports through REST. At the
time of this release, Google publishes line items and companies as read-only
resources and does not publish creative or line-item association writes. Those
operations return `REST_CAPABILITY_UNAVAILABLE`, remain audited, and are never
reported as successful. This protects production from false-positive traffic
deployment while preserving a stable API boundary as Google adds capabilities.

## Credentials

The database never stores raw private keys, refresh tokens, client secrets, or
credential JSON. `gam_credentials.reference` is encrypted and accepts only
references such as:

```text
env:GAM_HORUS_SERVICE_ACCOUNT_PATH
file:/home/account/private/gam-horus.json
```

The resolved file must be readable and outside the public web directory. OAuth
access tokens are cached only as Laravel-encrypted ciphertext.

## Implemented GAM data model

- `gam_connections`: Horus, MCM partner, and publisher connection definitions
- `gam_networks`: networks discovered through each credential
- `gam_credentials`: encrypted references and non-secret hints
- `gam_connection_permissions`: validated API/network capabilities
- `gam_remote_objects`: local-to-GAM identifier mappings
- `gam_api_operations`: sanitized request/response and idempotency ledger
- `gam_sync_runs`: synchronization execution summaries
- `gam_sync_logs`: structured per-run events
- `gam_errors`: categorized and resolvable failures
- `sites.gam_connection_id`: optional explicit website assignment

Only one connection can be selected as primary `HORUS_GAM` by the service
layer. Creating the first Horus connection automatically makes it primary.

## Connector surface

`GamConnectorInterface` provides a stable internal contract for:

- connection and network discovery
- companies
- ad units and placements
- custom targeting keys and values
- orders and line items
- creatives and associations
- line-item pause and activation
- generic archive actions
- report jobs
- remote-object lookup

`GamRestConnector` implements every published REST capability and explicitly
rejects unpublished writes. `GamMockConnector` implements the entire contract
for deterministic tests. The application contains no SOAP transport or dated API
version. All production traffic passes through `GamOperationExecutor` for dry-run,
idempotency, retries, sanitization, mappings, and error capture.

## Administrator workflow

The Horus Media administrator console provides:

- add and edit connection
- select primary `HORUS_GAM`
- enable/disable and default dry-run settings
- test credentials and synchronize network metadata
- view permission checks, health, operations, and open errors
- assign a connection to a specific website
- create local ad units and synchronize them to the selected GAM
- create and bulk-create placements
- configure sizes, responsive mappings, targeting, lazy loading, refresh,
  SafeFrame, and collapse behavior
- preview, publish, pause, resume, and roll back static configurations
- duplicate a local layout to another website without copying remote IDs

The control plane never exposes credential material to browser JavaScript.

## Deployment profile

Production requires PHP 8.2+, OpenSSL, and cURL. The application remains compatible with Hostinger shared/cloud
hosting: no root process, Redis, Supervisor, Docker, WebSockets, permanent
worker, or impression endpoint is required.
