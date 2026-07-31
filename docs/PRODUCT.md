# Product

## Purpose

Horus Media is a white-label advertising control plane for onboarding
publishers, configuring websites and placements, managing optional demand
connections, importing aggregated reporting, calculating revenue shares,
preparing publisher payments, and managing direct advertiser campaigns.

The product does not proxy advertising traffic. Google Ad Manager remains the
primary serving engine.

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
and payment records. External provider activation and live payment execution
remain operational decisions requiring credentials, approvals, and a controlled
pilot.

## Account model

Organizations are typed as HORUS_MEDIA, PUBLISHER, ADVERTISER, or PARTNER.
Users belong to one organization and receive one or more system roles. Publisher
and advertiser records carry white-label presentation fields, while Horus-only
internal notes are never rendered to tenant users.

## Serving modes

| Mode | Purpose | New-website default |
|---|---|---:|
| HORUS_GAM | Horus Media's main GAM network | Yes |
| MCM_PARTNER_GAM | Optional approved MCM partner connection | No |
| PUBLISHER_GAM | Optional publisher-owned GAM connection | No |
| DIRECT_NATIVE_ONLY | Optional native/direct serving without GAM | No |
| PAUSED | Disable serving for the website | No |

There is no activation gate that prevents HORUS_GAM from being selected.
MCM and Publisher GAM are optional alternatives, never prerequisites.

## Installation promise

A publisher installs one permanent loader from cdn.horusmedia.net. Website
configuration is resolved by a stable public site key. Administrators can
change serving mode and demand configuration without modifying that loader tag.

## Release acceptance criteria

The repository release is accepted when:

- the Laravel application passes the PHP and MySQL test matrix;
- browser delivery assets and Horus Loader tests pass;
- the production artifact contains dependencies and compiled assets without secrets;
- deployment, scheduler, CDN, security, backup, and rollback procedures are documented.

Production go-live additionally requires:

- real infrastructure and TLS;
- private production environment and credentials;
- healthy scheduler and SMTP;
- a verified HORUS_GAM connection;
- a test publisher site and controlled campaign;
- reconciled reporting;
- operations and finance sign-off.

See GO_LIVE_CHECKLIST.md and PILOT_RUNBOOK.md for the evidence gates.
