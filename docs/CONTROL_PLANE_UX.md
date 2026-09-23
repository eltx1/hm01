# Horus Media Control Plane UX

## Product rule

The control plane is role-specific. Publisher users should not have to understand
Horus internal architecture in order to answer three ordinary questions:

1. What needs my attention?
2. How are my websites performing?
3. Where are my reports, earnings, statements, and payouts?

Horus administrators need a different first-level model:

1. What requires action now?
2. Which publisher or website am I operating?
3. Am I changing monetization, reporting/finance, quality/compliance, or platform operations?

## Publisher information architecture

The primary navigation is intentionally limited to four groups:

- **Home** — overview.
- **Websites** — websites and monetization health.
- **Reports & Money** — reports & earnings, statements, payouts.
- **Account & Help** — commercial terms, affiliate referrals, support, notifications, and team access.

The Publisher home page uses the canonical Horus reporting currency, USD, and
surfaces Today So Far, finalized current-month earnings, finalized impressions,
and website status before secondary account detail.

Detailed finance may retain historical non-USD records for immutable accounting
history. Those records are visually secondary and are never combined with the
canonical USD current-reporting totals.

## Administrator information architecture

The administrator sidebar is reduced to six operational groups:

- **Home**
- **Publishers & Sites**
- **Monetization**
- **Reporting & Finance**
- **Quality & Compliance**
- **Operations & Access**

High-frequency destinations (Publishers, Websites, Quick Monetize) are also
available as quick access links. The admin dashboard prioritizes action items,
then common workflows, then platform metrics.

## Workspace pages

Large 360-degree pages keep their full diagnostic and control content, but their
workspace tabs expose only the primary operator journeys. Secondary technical
sections remain on the page and are reachable by scrolling or contextual links;
they do not compete equally in the first navigation layer.

## Currency presentation

Current GAM reporting is canonical USD. The Google network's native currency is
source metadata only. Current dashboards and reporting do not present an
operator-selectable display currency that can accidentally mix or fragment the
platform's financial source of truth.

## Safety and accessibility

- Permissions continue to decide which navigation items and actions exist.
- Publisher surfaces do not expose internal gross/net/Horus margin economics.
- Existing URLs and route names remain stable.
- Navigation remains keyboard accessible and responsive on mobile.
- No UX change mutates serving, reporting, or finance state merely by viewing a page.
