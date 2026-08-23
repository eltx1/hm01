# Horus Media Current Product Truth

**Effective:** 2026-08-23

This document is the product-flow authority for current Horus Media development. Historical task documents may describe earlier onboarding, contract-file, email-verification, or pre-approval website flows. When those documents disagree with this file and the current executable tests, this file and the current code win.

## 1. Product principle

Horus Media must keep Publisher onboarding short and operationally simple. A Publisher account, a Website, website authorization, monetization activation, and payout readiness are separate lifecycle concerns. Do not combine them into one long wizard.

The intended flow is:

```text
Public registration
    -> Publisher application
    -> Human Horus approval
    -> Publisher dashboard
    -> Add Website
    -> Copy complete ads.txt block
    -> Verify the two Horus DIRECT records
    -> Submit Website for review
    -> Horus approves/configures monetization
    -> Reporting and earnings
    -> Payment profile when payout is needed
```

## 2. Public Publisher registration

New public Publisher registration is account-first.

Required at initial registration:

- contact name;
- email;
- password;
- Publisher/business display name.

The public registration form does **not** require:

- a Website;
- traffic-source percentages;
- pageview proof;
- ads.txt;
- HMP/HMS verification;
- payment details;
- a contract file;
- a self-selected revenue share.

Production authentication policy intentionally does not require email verification or administrator 2FA. The implementation may remain available for non-production testing or future migrations, but production configuration must not silently reintroduce either workflow.

Password policy should provide a meaningful minimum without unnecessary composition rules. Current public Publisher registration uses a 10-character minimum plus the existing hashing, throttling, lockout, reset, and session protections.

## 3. Publisher application and approval

The application collects the business identity, content description/categories, current legal acceptance, and optional high-level information needed for a human Horus decision.

Publisher approval is a human Admin action.

Approval activates the Publisher account and role. Approval does **not** automatically:

- create a Website;
- create placements;
- deploy ad serving;
- activate demand;
- create a payment profile;
- create a contract document;
- claim that traffic is verified or monetizable.

Website review begins only after an approved Publisher adds a Website from the Publisher dashboard.

## 4. Approved Publisher workspace

The historical seven-step Publisher onboarding wizard is retired.

The Publisher navigation must not expose an `Onboarding` destination. The Publisher dashboard should direct the user to `Add website` / `Manage websites`, Monetization Center, Earnings & Payments, Commercial terms, Support, and team functions according to permission.

Historical `/publisher/onboarding/{step}` GET URLs remain only as compatibility redirects to the Website list. Historical PUT submissions must perform no legacy mutation and redirect safely.

The old onboarding Blade view is removed.

## 5. Website onboarding

An approved Publisher creates each Website separately.

The compact Website create form should request only information useful for site review and operation, such as:

- Website display name;
- primary domain;
- content category;
- country;
- optional estimated pageviews.

The Publisher does not choose its own revenue share on the Website form. The Website inherits the applicable Publisher commercial default unless a more-specific approved Revenue Rule applies.

After Website creation Horus produces one complete installation block for the Publisher to copy into the Website's live `ads.txt`.

The block begins with the governed directives:

```text
OWNERDOMAIN=<publisher website owner domain>
MANAGERDOMAIN=horusmedia.net
CONTACT=mohamed@horusmedia.net
```

It then includes the two Horus DIRECT identities plus applicable reviewed master/demand records.

Website ownership verification intentionally checks only the two required Horus DIRECT records. Missing unrelated master/demand lines do not make the Horus ownership check fail.

## 6. HMP/HMS and public supply chain

Horus keeps one managed Publisher HMP identity and one Website HMS identity per Website where applicable.

HMP/HMS are seller/account identities, not separate payment-chain hops. The Website HMS remains the site-specific Horus SupplyChain identity. HMP and HMS must never be emitted as fake sequential SChain nodes.

Public sellers.json, ads.txt, SChain, and static delivery continue to follow the existing supply-chain architecture and safety controls.

## 7. Commercial terms

Commercial terms are account data, not a document-signature workflow.

The Admin creates/edits a Commercial Terms record containing values such as:

- terms reference;
- effective dates;
- default Publisher share;
- payment threshold;
- currency;
- payment terms;
- optional internal note.

The Publisher can view the applicable values read-only.

No contract upload or signature step is required. Historical Admin and Publisher contract-file upload/download endpoints are retained only as fail-closed compatibility endpoints and return 404. They must not store or serve new contract documents.

Activating Commercial Terms synchronizes the Publisher-level default Revenue Rule. Ending active terms disables that synchronized rule without rewriting historical financial results.

## 8. Revenue Rules

Revenue Rules provide controlled exceptions to the Publisher commercial default.

Specificity order is:

```text
Campaign
    > Publisher + Demand Source
    > Demand Source
    > Website
    > Publisher
    > Global
```

Within the same specificity, explicit priority decides first.

Every rule change is versioned. Closed financial periods remain protected from retroactive rule changes.

The three shares must be non-negative and total exactly 100%:

```text
Publisher + Horus Media + optional MCM partner = 100%
```

### Ambiguity protection

Horus must not rely on database fetch order to choose between equally ranked rules.

Creation or versioning is rejected when another active rule already covers the same:

- scope type;
- target;
- priority;
- overlapping date range;
- overlapping currency applicability.

A currency-agnostic rule overlaps every currency. Two different explicit currencies may coexist at the same rank. A deliberately higher-priority rule may coexist and wins explicitly.

The resolver also applies a deterministic final tie-break as a safety belt after specificity and priority.

## 9. Payment profile

Payment details are not part of initial Publisher registration and are not a gate to adding a Website.

A Publisher configures payment information through Earnings & Payments when payment readiness becomes relevant. Sensitive payment/tax references continue to use the existing encrypted storage and review controls.

## 10. Monetization activation

Website approval and Publisher approval do not automatically imply serving.

Horus may operate a Website through the existing multi-engine model:

- `HORUS_GAM` / GAM;
- Prebid (`GAM_BRIDGE` or standalone where supported);
- Direct JS;
- `HORUS_DIRECT` without a fake GAM dependency.

Only real reviewed provider/network identifiers should be activated. One physical placement must have one rendering owner at a time.

## 11. First production milestone

The next product milestone is not another broad feature expansion. It is one real end-to-end Publisher pilot:

1. register one controlled Publisher through the public form;
2. approve the Publisher through the human Admin flow;
3. add one real Website;
4. install and verify the complete ads.txt block;
5. review/approve the Website;
6. activate one real monetization profile/demand path;
7. collect real aggregated reporting;
8. trace gross revenue through the winning Revenue Rule;
9. verify Publisher earnings and Horus margin;
10. finalize one statement and prove rollback/stop controls.

Only after this trace is proven should Horus broaden traffic, providers, Publishers, or advertiser-campaign scope.

## 12. Development rule

Before adding a new feature, ask whether it is necessary to complete the first real Publisher-to-revenue trace. If not, prefer operational proof, cleanup, or a tightly scoped safety improvement.

Do not reintroduce the retired seven-step onboarding or contract-file workflow unless the product owner explicitly changes this product definition.
