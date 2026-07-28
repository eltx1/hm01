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

The implemented product includes organization-scoped identity, invitations,
system roles and permissions, account states, audit trails, administrator
impersonation, publisher onboarding, contracts, payment profiles, website and
domain management, verification, operational reviews, serving-mode history,
and separate tenant dashboard shells. Campaign and reporting links remain
explicit placeholders.

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

## Current acceptance criteria

- portable Laravel application with a Hostinger-compatible deployment profile
- health, exception, session, CSRF, logging, scheduling, and audit foundations
- responsive dashboard shell
- publisher onboarding, contracts, site review, domain verification, and histories
- architecture and portable deployment documentation
- tests that run on SQLite where practical

GAM/Prebid serving integrations, bidders, campaigns, reporting, and payment
execution remain outside this release.
