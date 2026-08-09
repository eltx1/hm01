# Ads.txt Compliance Center

## Status and scope

This document is the implementation and operations reference for Horus Media's
Ads.txt Compliance Center. It builds on the canonical supply-chain identities
described in `SUPPLY_CHAIN_IDENTITY.md`; it does not create a second ads.txt
record store.

The implementation follows the IAB Tech Lab ads.txt 1.1 final specification:

- <https://iabtechlab.com/ads-txt/>
- <https://iabtechlab.com/wp-content/uploads/2022/04/Ads.txt-1.1.pdf>

`DemandAdsTxtRecord` remains authoritative for managed seller records.
`SupplyChainArtifactBuilder::adsTxtForSite()` is the single deterministic
canonical file generator. `SupplyChainCheck` stores bounded live-verification
evidence.

## Architecture

1. `SupplyChainInvariantService` selects eligible account/site records and
   detects canonical duplicate or relationship conflicts.
2. `SupplyChainArtifactBuilder` generates one exact ads.txt 1.1 file with
   `OWNERDOMAIN`, `MANAGERDOMAIN`, and the eligible seller records.
3. `AdsTxtParser` parses canonical or public text into directives, seller
   records, invalid lines, and duplicates.
4. `AdsTxtFetcher` fetches only the primary domain of an existing `Site` after
   that domain has been verified. No controller accepts a URL.
5. `AdsTxtComparator` computes correct, missing, additional, invalid, and
   conflicting declarations.
6. `AdsTxtVerifier` stores a deduplicated snapshot and optionally records one
   audit event for a manual admin/publisher check.
7. `AdsTxtComplianceService` derives the current table/card status, including
   the seven-day stale state, from canonical data and the latest check.

The computed states are `COMPLIANT`, `PARTIAL`, `MISSING`, `INVALID`,
`CONFLICT`, `STALE`, `UNREACHABLE`, and `NOT_CONFIGURED`. They are never
operator-selected labels.

## Parser and comparison rules

The parser:

- ignores blank lines and full/inline `#` comments;
- removes a UTF-8 BOM;
- requires three seller fields and permits the fourth certification-authority
  field;
- normalizes advertising-system domains and certification IDs to lowercase,
  and `DIRECT`/`RESELLER` to uppercase;
- preserves publisher account ID casing because the specification does not
  permit changing it;
- safely recognizes extension data after `;` without treating it as a core
  seller field;
- recognizes `CONTACT`, `SUBDOMAIN`, `INVENTORYPARTNERDOMAIN`, `OWNERDOMAIN`,
  and `MANAGERDOMAIN` instead of misclassifying variables as seller records;
- preserves multiple variable declarations, preserves unknown variables for
  forward compatibility, uses only the first `OWNERDOMAIN`, and enforces one
  `MANAGERDOMAIN` per global or ISO-country scope;
- retains line numbers and escaped original live lines for diagnostics.

When no advertising system is authorized, deterministic generation emits the
official `placeholder.example.com, placeholder, DIRECT, placeholder` record.

Exact canonical seller records are compared after specification-permitted
normalization. A live declaration with the same advertising-system domain and
publisher account ID but another relationship or certification ID is a
conflict. Additional valid live records are visible but do not by themselves
make the Horus-required set noncompliant.

## SSRF and response controls

The verifier is deliberately narrower than a general URL checker:

- the initial URL is always `https://{Site.primary_domain}/ads.txt`;
- the primary `SiteDomain` must have `verification_status=VERIFIED`;
- redirect hosts must also be explicitly verified domains of the same Site;
- redirects are manual, limited to three, and each target is resolved and
  validated again;
- HTTPS-to-HTTP downgrade redirects are rejected;
- URL credentials, localhost, `.localhost`, `.local`, non-HTTP schemes, and
  ports other than 80/443 are rejected;
- every resolved A/AAAA address must be public; loopback, private, reserved,
  link-local, and metadata-network addresses fail closed;
- the chosen validated address is pinned with cURL `CURLOPT_RESOLVE`, reducing
  DNS-rebinding exposure between validation and connection;
- connection and total timeouts default to 3 and 8 seconds;
- only a successful 2xx `text/plain` response is parsed;
- `Content-Length` is checked and the response stream is read only up to one
  byte beyond the 1 MiB limit;
- a stable, product-identifying user agent and `Accept: text/plain` are sent;
- manual routes are limited to two checks per user/site/minute and twenty per
  user/IP/hour.

The IAB specification permits same-root redirects and one third-party redirect.
Horus applies the stricter product rule required here: an external target must
first be explicitly added to and verified for that Site. This intentionally
does not auto-trust arbitrary third-party redirect delegation.

Failures store a bounded code and product-safe message, not exception details.
Live content is UTF-8 scrubbed, capped, stored as text, and always rendered
through Blade escaping.

## History, deduplication, and retention

The expanded `supply_chain_checks` row stores:

- canonical and live SHA-256 checksums;
- a deterministic snapshot hash;
- bounded live response text and byte count;
- HTTP status, content type, final URL, redirect findings, and duration;
- exact comparison findings;
- `SCHEDULED`, `ADMIN`, or `PUBLISHER` trigger and optional initiating user;
- first/latest check timestamps and an occurrence counter.

Consecutive identical snapshots update the existing row's latest timestamp and
occurrence count. Only the newest 30 distinct snapshots per Site are retained
by default. Environment settings can tune response size, timeouts, freshness,
redirect count, and retained snapshot count.

Scheduled reads are evidence rows, not audit events. Meaningful manual checks,
structured record create/update/disable operations, and atomic bulk assignment
are written to `AuditLog`.

## Routes and interfaces

| Audience | Method and path | Purpose |
|---|---|---|
| Admin | `GET /admin/compliance/ads-txt` | Network compliance table and safe bulk assignment |
| Admin | `GET /admin/compliance/ads-txt/sites/{site}` | Canonical/live inspection, diff, sources, management, history, audit |
| Admin | `GET .../sites/{site}/download` | Download exact canonical text |
| Admin | `POST .../sites/{site}/verify` | Throttled safe manual recheck |
| Admin | `POST .../records` | Create a structured managed record |
| Admin | `PUT .../records/{record}` | Update/reassign a manual record |
| Admin | `PATCH .../records/{record}/disable` | Disable a manual record without deleting history |
| Admin | `POST .../bulk-assign` | Atomically assign one structured record to mapped Sites |
| Publisher | `GET /publisher/ads-txt` | Own-Site status, exact file, and remediations |
| Publisher | `GET .../sites/{site}/download` | Download own canonical text |
| Publisher | `POST .../sites/{site}/verify` | Throttled verification of own Site |

Publisher route-model binding remains organization-scoped. Publisher views
receive exact legally/technically required record strings but no account
identifier metadata, provider credentials, connector configuration, or private
source labels.

## Permissions and seeded roles

| Permission | Default roles |
|---|---|
| `supply_chain.ads_txt.view` | Super Admin, Operations Admin, Ad Ops Admin, Finance Admin, Support Agent |
| `supply_chain.ads_txt.manage` | Super Admin, Operations Admin, Ad Ops Admin |
| `supply_chain.ads_txt.verify` | Super Admin, Operations Admin, Ad Ops Admin |
| `publisher.ads_txt.view` | Publisher Admin, Publisher Viewer |
| `publisher.ads_txt.verify_own` | Publisher Admin |

All admin routes additionally require a Horus Media administrator and the
existing administrator two-factor middleware.

## Scheduler and operations

`php artisan supply-chain:check` checks every Site. An optional `--site={ULID}`
limits it to one Site. Laravel Scheduler runs it daily at `03:20` with overlap
protection; the implementation requires cron-compatible Scheduler execution,
not a permanent worker.

Recommended remediation sequence:

1. Resolve canonical `CONFLICT` findings in managed records.
2. Copy or download the exact canonical content.
3. Publish it as UTF-8 `text/plain` at the verified root domain `/ads.txt`.
4. Ensure redirects stay within explicitly verified Site domains.
5. Run a manual recheck and inspect correct/missing/invalid/additional sections.

## Tests and render notes

`AdsTxtParserTest` covers comments, blanks, BOM, extensions, malformed records,
duplicates, `DIRECT`/`RESELLER`, certification IDs, all official 1.1 directives,
exact comparison, conflict detection, and additional records.

`AdsTxtComplianceTest` covers computed admin/publisher surfaces, permissions,
publisher isolation, successful and missing files, content type, timeout,
response-size limits, safe/unsafe redirects, private DNS, unverified domains,
live diff, snapshot deduplication, audit behavior, structured and bulk record
management, scheduled execution, stale state, and deterministic generation.
The existing supply-chain suite remains the regression authority.

The views use the existing Horus dark glass theme and responsive primitives.
Wide network results use the existing controlled-scroll table; publisher cards
and diff columns collapse to one column below 800px. Long canonical/live text
uses bounded scrollable monospace panels. Copy actions include a selection
fallback, and every server-side string remains HTML-escaped. No screenshot
fixture is committed because it would age with test data; feature rendering and
the production Vite build are release checks.

## Known limitations

- Horus does not automatically authorize the IAB-permitted single external
  redirect. Add and verify that host as a Site domain first.
- Domain values are syntax-normalized; public-suffix ownership remains part of
  the audited publisher business-domain review rather than an embedded,
  automatically updating PSL dependency.
- Checks are HTTP snapshots. Cache-header-driven freshness is not yet used;
  the IAB default seven-day freshness window is applied consistently.
- Connector-managed records are read-only in the Compliance Center. They must
  be corrected at their authoritative connector; only `MANUAL` records can be
  edited or disabled here.
