# Horus Media Current Product Truth

**Effective:** 2026-08-24

This document is the product-flow authority for current Horus Media development. Historical task documents may describe earlier onboarding, contract-file, email-verification, pre-approval website, or AI-review flows. When those documents disagree with this file and the current executable tests, this file and the current code win.

## 1. Product principle

Horus Media must keep Publisher onboarding short and operationally simple. A Publisher account, a Website, website authorization, Website review, monetization activation, and payout readiness are separate lifecycle concerns. Do not combine them into one long wizard.

The intended flow is:

```text
Public registration
    -> Publisher application
    -> Human Horus approval
    -> Publisher dashboard
    -> Add Website
    -> Copy complete ads.txt block
    -> Submit Website for human review
         + THOTH Website Review runs in parallel when AI is available
         + Horus HMP/HMS ads.txt verification may complete before or during review
    -> Human Horus Website approval
    -> Production activation only after current HMP/HMS verification is also complete
    -> Configure/activate real monetization
    -> Reporting and earnings
    -> Payment profile when payout is needed
```

THOTH is advisory only. AI availability, success, failure, quota, timeout, provider readiness, or model availability must never become a dependency for Website submission, human review, approval, or the rest of the platform lifecycle.

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

The public Publisher Website-create form requests exactly four operational fields:

- Website display name;
- primary domain;
- content category;
- primary country.

Initial Website creation does **not** request estimated pageviews, estimated users, traffic-source splits, GAM/AdSense/AdX status, Prebid/native configuration, or any monetization-provider setup. Stale/manual clients must not be able to reintroduce estimated traffic collection during create; the create path persists the traffic estimates as zero defaults.

The Publisher does not choose its own revenue share on the Website form. The Website inherits the applicable Publisher commercial default unless a more-specific approved Revenue Rule applies.

After Website creation Horus produces one complete installation block for the Publisher to copy into the Website's live `ads.txt`.

The block begins with the governed directives:

```text
OWNERDOMAIN=<publisher website owner domain>
MANAGERDOMAIN=horusmedia.net
CONTACT=mohamed@horusmedia.net
```

It then includes the two Horus DIRECT identities plus applicable reviewed master/demand records.

The Publisher may submit the Website for review while Horus ads.txt verification is still pending. Human Website review and THOTH advisory work may therefore proceed in parallel with HMP/HMS verification. Production activation remains blocked until the exact current HMP/HMS verification requirement is satisfied.

Website activation readiness intentionally checks only the two required current Horus DIRECT records for the current primary domain. Missing unrelated master/demand lines do not block initial Website activation. Generic/manual domain ownership verification must not bypass this HMP/HMS activation gate.

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

## 10. Website review and THOTH advisory

Website approval is a human Horus Admin action.

When a Publisher submits a Website, Horus first persists the normal Website lifecycle transition to `PENDING_REVIEW` and the human `SiteReview`. Only after that durable submission does Horus attempt to queue the optional automatic THOTH Website Review.

THOTH Website Review is:

- automatic on Website submission;
- asynchronous through the database queue;
- advisory-only;
- bounded and SSRF-safe when collecting public Website evidence;
- visible to Horus Admins in Site 360 under `Quality Review`;
- manually rerunnable while the Website remains pending human review.

THOTH may return a recommendation, risk level, confidence, findings, positive signals, concerns, recommended Admin checks, summary, limitations, and evidence gaps. Those values are evidence for the human reviewer only.

THOTH must never approve, reject, activate, suspend, archive, or otherwise mutate Website lifecycle state.

Any THOTH failure must be fail-safe and Admin-visible without exposing raw provider errors or credentials. This includes disabled AI, missing/not-ready provider configuration, authentication failure, quota/rate limit, unavailable/incompatible model, timeout, provider outage/unreachability, invalid/oversized response, queue failure, unexpected service failure, or inability to collect acceptable public Website evidence.

A THOTH failure never invalidates Website submission and never blocks the existing human Approve/Reject controls. If THOTH cannot run, the Admin continues the Website review normally.

## 11. Monetization activation

Publisher approval, Website submission, Website approval, THOTH recommendation, generic domain ownership verification, and ads.txt master/demand diagnostics are separate concerns and do not individually imply serving.

First production activation requires:

1. the Website is in `APPROVED` state; and
2. the current primary domain has a current successful Horus ads.txt verification for the exact assigned HMP/HMS DIRECT records.

Only then may the Admin activate the Website. Suspended-site recovery remains a separate lifecycle path and must not be conflated with initial activation.

Horus may operate an activated Website through the existing multi-engine model:

- `HORUS_GAM` / GAM;
- Prebid (`GAM_BRIDGE` or standalone where supported);
- Direct JS;
- `HORUS_DIRECT` without a fake GAM dependency.

Only real reviewed provider/network identifiers should be activated. One physical placement must have one rendering owner at a time.

## 12. First production milestone

The next product milestone is not another broad feature expansion. It is one real end-to-end Publisher pilot:

1. register one controlled Publisher through the public form;
2. approve the Publisher through the human Admin flow;
3. add one real Website with the compact four-field form;
4. copy the complete ads.txt block and submit the Website for review;
5. observe the automatic THOTH advisory if AI is available, without treating it as a decision;
6. complete human Website review/approval and exact current HMP/HMS verification;
7. activate one real monetization profile/demand path;
8. collect real aggregated reporting;
9. trace gross revenue through the winning Revenue Rule;
10. verify Publisher earnings and Horus margin;
11. finalize one statement and prove rollback/stop controls.

Only after this trace is proven should Horus broaden traffic, providers, Publishers, or advertiser-campaign scope.

## 13. Development rule

Before adding a new feature, ask whether it is necessary to complete the first real Publisher-to-revenue trace. If not, prefer operational proof, cleanup, or a tightly scoped safety improvement.

Do not reintroduce the retired seven-step onboarding, contract-file workflow, traffic-heavy Website create form, or AI-as-decision-maker behavior unless the product owner explicitly changes this product definition.
