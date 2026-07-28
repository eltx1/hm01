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
connection changes increment the site's configuration version for later loader
publication.

## API boundary

Laravel uses GAM APIs only for control-plane management and reporting imports.
Browser ad calls go from GPT directly to the configured GAM network.

Every connector write:

- supports dry-run output before execution
- uses deterministic idempotency keys and local-to-remote reconciliation
- audits intent, sanitized request, result, attempts, and upstream identifiers
- retries reads safely and retries writes only when the transport confirms that
  retrying cannot duplicate an upstream object
- stores categorized errors without credential material

## Centralized versioning

The SOAP API version exists only in `config/gam.php` and defaults to `v202602`.
No service class hard-codes a version. Upgrading an active SOAP version is a
single configuration change followed by the automated test suite and a live
connection test.

The REST connector is intentionally isolated behind
`GamRestConnectorPlaceholder` until the beta surface is deliberately enabled.
It cannot silently replace the production SOAP connector.

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

## Implemented data model

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

`GamConnectorInterface` supports:

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

`GamSoapConnector` maps this surface to the corresponding Ad Manager SOAP
services. `GamMockConnector` implements the entire contract for deterministic
tests. All traffic passes through `GamOperationExecutor` for dry-run,
idempotency, retries, sanitization, mappings, and error capture.

## Administrator workflow

The Horus Media administrator console provides:

- add and edit connection
- select primary `HORUS_GAM`
- enable/disable and default dry-run settings
- test credentials and synchronize network metadata
- view permission checks, health, operations, and open errors
- assign a connection to a specific website

The control plane never exposes credential material to browser JavaScript.

## Deployment profile

Production requires PHP 8.2+, OpenSSL, cURL, and the PHP SOAP extension for live
SOAP calls. The application remains compatible with Hostinger shared/cloud
hosting: no root process, Redis, Supervisor, Docker, WebSockets, or permanent
worker is required.
