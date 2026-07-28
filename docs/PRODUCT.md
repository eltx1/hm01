# Product

## Purpose

Horus Media is a white-label advertising control plane for onboarding
publishers, configuring websites and placements, managing optional demand
connections, importing aggregated reporting, calculating revenue shares, and
preparing publisher payments.

The product does not proxy advertising traffic. Google Ad Manager remains the
primary serving engine.

## Users

- Horus Media administrators configure organizations, websites, placements,
  serving modes, commercial terms, and operational access.
- Publisher users review their websites, reporting, balances, and payments.
- Finance and operations users review reconciliations and payment readiness.

Phase 1 implements organization-scoped identity, invitations, system roles and
permissions, account states, audit trails, Horus administrator impersonation,
and separate publisher and advertiser dashboard shells. Website, campaign, and
reporting links remain explicit placeholders.

## Account model

Organizations are typed as `HORUS_MEDIA`, `PUBLISHER`, `ADVERTISER`, or
`PARTNER`. Users belong to one organization and receive one or more system
roles. Publisher and advertiser records carry white-label presentation fields,
while Horus-only internal notes are never rendered to tenant users.

## Serving modes

| Mode | Purpose | New-website default |
|---|---|---:|
| `HORUS_GAM` | Horus Media's main GAM network | Yes |
| `MCM_PARTNER_GAM` | Optional approved MCM partner connection | No |
| `PUBLISHER_GAM` | Optional publisher-owned GAM connection | No |
| `DIRECT_NATIVE_ONLY` | Optional native/direct serving without GAM | No |
| `PAUSED` | Disable serving for the website | No |

There is no activation gate that prevents `HORUS_GAM` from being selected.
MCM and Publisher GAM are optional alternatives, never prerequisites.

## Installation promise

A publisher installs one permanent loader from `cdn.horusmedia.net`. Website
configuration is resolved by a stable public site key. Administrators can
change serving mode and demand configuration without modifying that loader tag.

## Foundation acceptance criteria

- Hostinger-compatible Laravel application and MySQL schema foundation
- health, exception, session, CSRF, logging, scheduling, and audit foundations
- responsive dashboard shell
- architecture and deployment documentation
- tests that run on SQLite where practical

Publisher workflows, serving integrations, bidders, campaigns, and reporting
are outside this foundation release.
