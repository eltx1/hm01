# Horus Media Platform

## Goal

Build a private white-label platform for Horus Media to manage publishers, websites, advertisers, campaigns, reporting, revenue shares, statements, and payments.

## Fixed architecture

- Default ad server: `HORUS_GAM`
- Optional modes: `MCM_PARTNER_GAM`, `PUBLISHER_GAM`, `DIRECT_NATIVE_ONLY`, `PAUSED`
- Prebid.js runs in the browser.
- Google Ad Manager is the main campaign and inventory engine.
- Publishers install one permanent Horus loader.
- The PHP backend is a control plane and does not handle every ad impression.

## Primary users

- Horus Media administrators
- Publishers
- Advertisers
- Optional partners

## MVP modules

1. Authentication and permissions
2. Publisher and advertiser management
3. Website onboarding and verification
4. GAM connection management
5. Inventory and GPT configuration
6. Horus Loader
7. Browser-side Prebid.js
8. Direct campaigns
9. Native demand connectors
10. Reporting and revenue calculations
11. Statements and payments
12. Hostinger deployment
