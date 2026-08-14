# Architecture

## System boundaries

Horus Media Platform is the control plane. It stores operational configuration,
connector metadata, and aggregated reporting. It never sits in the advertising
request path.

The permanent Horus Loader controls three independent serving engines from
static CDN configuration. GAM remains the established/default GAM path but is
optional at the product architecture level.

~~~mermaid
flowchart LR
    A["Publisher Website"] --> B["Cloudflare Pages static edge<br/>cdn.horusmedia.net"]
    B --> J["Permanent Loader + manifest + immutable config"]
    J --> GAM["GAM Engine"]
    J --> PB["Prebid Engine"]
    J --> JS["Direct JS Engine"]

    GAM --> GPT["Google Publisher Tag"]
    GPT --> E{"Selected GAM connection"}
    E --> F["HORUS_GAM"]
    E --> G["MCM_PARTNER_GAM"]
    E --> H["PUBLISHER_GAM"]

    PB --> PBG["GAM_BRIDGE"]
    PBG --> GPT
    PB --> PBS["STANDALONE"]
    PBS --> W["Prebid winning bid direct render"]

    JS --> N["Approved provider JS / custom direct demand"]
~~~

`HORUS_GAM` remains the application/database default serving mode for the
established GAM-managed path. `HORUS_DIRECT` represents a Horus-managed website
that does not require a GAM connection. `DIRECT_NATIVE_ONLY` remains a
legacy/specialized direct-native mode and `PAUSED` continues to prevent serving.

Serving mode and serving engine are intentionally distinct. Engine state is
additive: GAM, Prebid, and Direct JS may all be enabled on a site, or a site may
operate without GAM. Prebid and Direct JS may run simultaneously across
independent placements. One physical placement/container has one active renderer
at a time unless a future explicitly isolated composite-placement design defines
otherwise.

For the complete contract see [Multi-engine serving](MULTI_ENGINE_SERVING.md).

## Prebid delivery contexts

GAM-enabled Prebid preserves the current flow:

~~~mermaid
flowchart LR
    A["Prebid auction"] --> B["Targeting"] --> C["GPT"] --> D["GAM"] --> E["Final GAM serving decision"]
~~~

Standalone Prebid is the approved no-GAM flow:

~~~mermaid
flowchart LR
    A["Prebid auction"] --> B["Winning bid"] --> C["Horus Loader direct render"]
~~~

The standalone direct renderer is implemented incrementally by a dedicated
runtime task. The architecture must not simulate standalone mode by inventing a
GAM connection or by converting unrelated Direct JS demand into a Prebid bidder.

Direct JS is independent:

~~~mermaid
flowchart LR
    A["Horus Loader"] --> B["Approved provider JS/tag"] --> C["Provider serving endpoint"]
~~~

## Control-plane workflow

~~~mermaid
flowchart TD
    A["Horus Media Admin Dashboard"] --> B["Publishers"]
    B --> C["Websites"]
    C --> D["Placements"]
    D --> E["Direct Advertisers"]
    E --> F["Campaigns"]
    F --> G["GAM API for GAM-backed deployments"]
    G --> H["Reports"]
    H --> I["Revenue Shares"]
    I --> J["Publisher Payments"]
~~~

This is the implemented capability sequence. Existing GAM campaign deployment
remains unchanged for GAM-enabled sites. Live external activation and payment
execution remain governed by the go-live and finance gates.

## Runtime components

- app.horusmedia.net: Laravel control plane and dashboard
- cdn.horusmedia.net: Cloudflare Pages static loader, browser bundles, manifests, and configuration artifacts
- MySQL: transactional control-plane data, sessions, cache, queue, audit log, and aggregated reporting
- cron: Laravel scheduler once per minute
- browser: Prebid.js auctions, optional GPT/GAM setup, Direct JS/native provider execution, Click Guard, and direct advertising requests
- GAM and external networks: optional serving engines/sources and aggregated reporting systems

## Configuration publication

The application records immutable public configuration and an outbox item in one
database transaction. Normal work becomes eligible at the next deterministic UTC
half-hour boundary; the every-minute scheduler coalesces all due work into one
locked deterministic snapshot. Urgent safety changes remain immediately eligible.
The Admin-only Deploy Now control accelerates pending normal items through this
same manager and budget, never through a controller-to-Cloudflare call. With no
pending work, it is an audited no-op. The manager commits only public files to a
sanitized delivery branch and dispatches a Wrangler Pages deployment. Publication
is marked deployed only after CI confirms Cloudflare.
The loader resolves the immutable snapshot through a short-lived manifest with a
compatibility alias fallback. No publisher browser request reaches Laravel.

Runtime publication is lifecycle-driven. Draft and approved websites do not
create production traffic; activation queues the complete first version.
Subsequent active-site mutations queue automatically, while suspension and
emergency pause queue urgent paused versions. The payload builder independently
requires website status `ACTIVE`, so manual or concurrent publication cannot
turn an inactive website on at the edge.

The static schema evolves compatibly: old deployed schema versions remain valid
while newer versions may add explicit `engines` state. Publication, rollback,
and cache behavior remain deterministic, and secrets never enter CDN payloads.

The short-lived global control artifact independently represents the master
`AD_SERVING` stop and the `GAM`, `PREBID`, and `DIRECT_JS` engine stops. Loader
precedence is restrictive across platform, site, placement, GAM connection, and
demand network controls. Platform engine disables enter the same static outbox
with urgent priority and immediate eligibility; normal publisher requests still
never traverse Laravel.

The only privacy-specific exception is an explicit, Admin-initiated, one-shot
diagnostic. The Admin opens an authorized publisher hostname with a short-lived,
single-use token. Only that page load may post a small, schema-constrained set of
non-personal CMP/runtime facts to Laravel with credentials omitted. The raw token
is hashed at rest, the result is site and hostname scoped, and evidence becomes
stale. Normal Loader boot performs no diagnostic POST and keeps the permanent
no-browser-telemetry boundary. See [Privacy readiness](PRIVACY_READINESS.md).

See [ADR 0001](adr/0001-cloudflare-pages-static-delivery.md).

## Publisher onboarding control plane

The implemented module stores publisher commercial terms, encrypted payment
profiles, private contract documents, websites, authorized domains, verification
attempts, reviews, internal notes, status history, serving settings, and serving
mode history. The website owns one stable public key used in the permanent loader
tag. Its serving mode defaults independently in both sites and
site_serving_settings to HORUS_GAM; changing the operational selection does not
change the public key or permanent Loader tag.

`HORUS_DIRECT` is a first-class no-GAM mode. It must not require a synthetic
`gam_connection_id` or network code. Existing records are not automatically
rewritten to it, including `DIRECT_NATIVE_ONLY` records.

Domain checks support HTML meta, well-known text file, DNS TXT, and audited
manual verification. HTTP verification accepts public DNS targets only and does
not follow redirects, protecting the control plane from internal-network fetches.

## Portable hosting constraints

There is no production dependency on Docker, Redis, Supervisor, WebSockets,
permanent workers, or a Node.js runtime. Composer dependencies and Vite assets
are prepared before upload. Cron invokes the scheduler; scheduled database queue
work drains with stop-when-empty. These constraints preserve compatibility with
Hostinger while also supporting Apache, nginx, VPS, managed cloud, and other
standards-compliant PHP/MySQL hosts.

## Task 20 final serving topology

```text
Horus Loader
├── GAM -> GPT -> selected GAM connection
├── Prebid -> STANDALONE direct banner render | GAM_BRIDGE -> GPT/GAM
└── Direct JS -> reviewed structured provider recipe | isolated custom iframe
```

The Loader does not load GPT when no GAM-owned placement exists, does not load
Prebid when no Prebid placement is active, and does not load a Direct provider
script when no Direct placement is active. Shared Direct provider scripts are
deduplicated while independent placements initialize independently. Renderer
ownership remains per physical placement and conflicts fail closed.

## Financial truth boundary

Serving capability does not imply financial reporting coverage. Demand and Bidder accounts bind to the existing canonical reporting ledger through an explicit provider source and reporting method. Browser bid prices and `PREBID_ESTIMATES` remain estimates; they cannot satisfy financial close or produce payable Publisher earnings. Provider API, approved CSV, or controlled Finance manual data must pass through the existing import, normalization, checksum, reconciliation, and finality pipeline. No raw browser auction telemetry enters Laravel.
