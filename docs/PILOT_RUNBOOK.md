# Horus Media Controlled Pilot Runbook

The pilot proves the complete path with minimal external risk. It is not a
substitute for production security or financial approval.

## Scope

Use one Horus Media administrator, one publisher website, one test advertiser,
one GAM network, one or two placements, and a small capped campaign. Keep
HORUS_GAM as the serving default unless the pilot explicitly tests an alternative
connection.

## Before enabling traffic

1. Complete GO_LIVE_CHECKLIST.md Gates 0–3.
2. Create the publisher organization, contract, payment profile, website, and authorized domain.
3. Review the revenue-share terms and record the effective contract version.
4. Create local ad units and placements; synchronize GAM in dry-run first.
5. Publish and checksum the production static configuration.
6. Install the permanent Loader tag on the publisher test page.
7. Verify hostname authorization, GPT loading, house-ad targeting, pause, and rollback.
8. Create the advertiser, billing profile, creative, targeting, dates, and capped budget.
9. Preview the deterministic per-network campaign plan.
10. Obtain administrator and finance approval before external writes.

## Controlled activation

- Activate one placement first.
- Confirm direct browser-to-GAM requests and a valid test impression.
- Activate the second placement only after the first is stable.
- Enable Prebid or native demand separately, one change at a time.
- Record every configuration version, GAM operation, report import, and rollback point.
- Keep a daily comparison of Horus aggregates versus GAM/network aggregates.

## Daily review

- delivery status, fill, impressions, clicks, revenue, and errors;
- GAM operation ledger and unresolved errors;
- campaign drift and network-instance status;
- CDN configuration checksum and cache freshness;
- scheduler heartbeat and failed jobs;
- tenant isolation and administrator audit events;
- advertiser report and invoice balance;
- publisher statement and payment readiness.

## Stop conditions

Pause the site or campaign if any of these occurs:

- public configuration contains a secret or wrong network;
- a tenant can access another tenant's data;
- GAM objects duplicate or drift unexpectedly;
- a browser request reaches Laravel's impression path;
- reporting variance cannot be explained;
- a creative or provider tag violates approval policy;
- scheduler, CDN, or rollback behavior is unreliable.

Use the emergency site pause, preserve logs and operation IDs, and open an
incident record before attempting a repair.

## Exit criteria

End the pilot only after:

- seven consecutive days of stable delivery;
- no unresolved high-severity security or isolation findings;
- reports reconcile within the agreed tolerance;
- invoices and publisher statements are reproducible;
- backup and restore evidence is current;
- publisher and advertiser owners sign off;
- the next release has a documented rollback path.

The exit decision belongs to Horus Media operations and finance, not to the
automated deployment job.

## GAM-less and regression pilot profiles (Task 20)

Use the smallest profile that proves the intended deployment; do not configure a
fake GAM connection. All IDs below are deployment-time provider values and must
never be copied from tests.

- **PILOT A — Standalone Prebid only:** `HORUS_DIRECT`, GAM absent, one approved
  banner placement owned by `PREBID_STANDALONE`. Verify no GPT request.
- **PILOT B — Direct JS only:** `HORUS_DIRECT`, Prebid absent, one approved Direct
  placement. Verify only approved provider scripts load.
- **PILOT C — Standalone Prebid + Direct JS:** independent placements on one page.
  Verify parallel startup and no global yield competition.
- **PILOT D — GAM + Prebid GAM_BRIDGE:** regression profile for the established
  GAM path. Verify GPT, targeting, GAM refresh and setup mappings.
- **PILOT E — GAM + GAM_BRIDGE + Direct JS:** bridge placement remains GAM-owned
  while Direct JS owns a separate non-GAM-managed placement.

For OneTag, use the provider-issued `pubId` through the normal Prebid account
workflow. For ExoClick, import the exact asynchronous tag and review the public
zone/container values. For Adsterra, obtain and review the actual operator tag;
do not infer a script host or zone.

Stop immediately on secret exposure, cross-tenant access, same-container double
render, consent/Click Guard bypass, unexpected GPT in a GAM-less profile, or an
uncontrolled top-window third-party script.

