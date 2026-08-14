# Product

## Purpose

Horus Media is a white-label advertising control plane for onboarding
publishers, configuring websites and placements, managing optional demand
connections, importing aggregated reporting, calculating revenue shares,
preparing publisher payments, and managing direct advertiser campaigns.

The product does not proxy advertising traffic. Runtime monetization is provided
by three independent serving engines: GAM, Prebid, and Direct JS. GAM remains a
first-class/default Horus path for GAM-enabled websites but is not a universal
product prerequisite.

## Users

- Horus Media administrators configure organizations, websites, placements,
  serving modes, commercial terms, and operational access.
- Publisher users review their websites, reporting, balances, and payments.
- Advertiser users manage creatives, targeting, campaigns, invoices, and reports.
- Finance and operations users review reconciliations, statements, and payment readiness.

## Current implementation status

The control plane is implemented across identity, publisher onboarding,
inventory, GAM, browser delivery, Prebid, native demand, direct sales,
aggregated reporting, reconciliation, revenue shares, statements, invoices,
payment records, and public Publisher applications. Public signup creates an
application-only identity and pending canonical Publisher account; it never
means automatic Publisher approval, website approval, or ad serving. The
multi-engine architecture contract additionally permits
GAM-less Horus-managed sites and establishes standalone Prebid and independent
Direct JS as supported engine contexts. Full standalone Prebid winner rendering
is delivered incrementally by dedicated runtime work rather than being emulated
through GAM.

External provider activation and live payment execution remain operational
decisions requiring credentials, approvals, and a controlled pilot.

## Account model

Organizations are typed as HORUS_MEDIA, PUBLISHER, ADVERTISER, or PARTNER.
Users belong to one organization and receive one or more system roles. Publisher
and advertiser records carry white-label presentation fields, while Horus-only
internal notes are never rendered to tenant users.

Public applicants are a constrained state of the same identity model. Their
pending Organization is not made active merely to permit authentication. They
can access only the application portal until an authorized Horus Admin approves
the explicit application lifecycle and atomically grants the existing Publisher
role and onboarding access.

## Serving modes

Serving mode describes how the site is broadly operated; it is not the same as
engine state.

| Mode | Purpose | New-website default |
|---|---|---:|
| HORUS_GAM | Horus Media's default GAM-managed mode | Yes |
| HORUS_DIRECT | Horus-managed site with no required GAM connection | No |
| MCM_PARTNER_GAM | Optional approved MCM partner connection | No |
| PUBLISHER_GAM | Optional publisher-owned GAM connection | No |
| DIRECT_NATIVE_ONLY | Legacy/specialized direct-native mode retained for compatibility | No |
| PAUSED | Disable serving for the website | No |

There is no activation gate that prevents a valid HORUS_GAM site from being
selected or operating. MCM and Publisher GAM are optional alternatives, never
prerequisites. `HORUS_DIRECT` does not require a GAM connection or fake network
identifier.

## Serving engines

The independent engine capabilities are:

- **GAM** — GPT plus the selected GAM connection and the existing GAM control
  plane, inventory, direct-campaign, and reconciliation behavior.
- **Prebid** — browser-side auctions operating either as `GAM_BRIDGE` or
  `STANDALONE`.
- **Direct JS** — approved provider JavaScript/tags controlled by Horus static
  configuration and independent of GAM and Prebid auctions.

Engine combinations are additive rather than mutually exclusive. For example,
a site may resolve to GAM + Prebid + Direct JS, standalone Prebid + Direct JS,
or Direct JS alone. Prebid and Direct JS may run simultaneously across different
placements and do not require a global winner. One physical placement retains
one renderer at a time.

See `MULTI_ENGINE_SERVING.md` for the authoritative contract.

## Installation promise

A publisher installs one permanent loader from cdn.horusmedia.net. Website
configuration is resolved by a stable public site key. Administrators can
change serving mode and demand configuration without modifying that loader tag.
GAM-less engine activation must preserve the same installation promise.

## Release acceptance criteria

The repository release is accepted when:

- the Laravel application passes the PHP and MySQL test matrix;
- browser delivery assets and Horus Loader tests pass;
- the production artifact contains dependencies and compiled assets without secrets;
- deployment, scheduler, CDN, security, backup, and rollback procedures are documented.

Production go-live additionally requires the evidence appropriate to the engines
actually enabled for a pilot site. A GAM-enabled pilot requires a verified GAM
connection; a GAM-less pilot does not invent or require one. Every pilot still
requires real infrastructure/TLS, private credentials where applicable, a
healthy scheduler and SMTP, a test publisher site, controlled monetization,
reconciled reporting, and operations/finance sign-off.

See GO_LIVE_CHECKLIST.md and PILOT_RUNBOOK.md for the evidence gates.

## Task 20 controlled-pilot product state

The supported pilot combinations are: standalone Prebid only; Direct JS only;
standalone Prebid plus Direct JS on different placements; GAM plus Prebid
`GAM_BRIDGE`; and GAM plus bridge Prebid plus an independent Direct JS placement.
No combination introduces a Prebid-vs-Direct global auction or automatic
waterfall. `AD_SERVING` stops all engines; GAM, PREBID and DIRECT_JS controls
isolate only their own paths.
