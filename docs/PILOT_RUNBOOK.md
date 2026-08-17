# Horus Media Controlled Pilot Runbook

This runbook follows the four independent launch profiles in `FINAL_LAUNCH_READINESS.md`. A Publisher-only pilot does not need an advertiser campaign, and a GAM-less Publisher pilot must not be given a fake GAM dependency.

## Recommended first controlled production sequence

1. Public Publisher/Application smoke validation.
2. Approve one Publisher through the human review path.
3. Create one Website only after approval/onboarding.
4. Verify HMP/HMS, live ads.txt, root sellers.json, and site SChain.
5. Enable exactly one chosen Publisher monetization profile.
6. Import real aggregated reporting and reconcile revenue.
7. Prove pause/rollback and obtain Operations/Finance sign-off.
8. Only then broaden traffic, providers, sites, or product profiles.

Do not force a Direct Advertiser Campaign into a Publisher-only pilot when `advertiser_campaigns.enabled` is disabled or when the production GAM campaign backend has not been verified.

## Common preparation

- Complete the Common Release, Infrastructure, and Security/Recovery gates in `GO_LIVE_CHECKLIST.md`.
- Record production release SHA and artifact checksum.
- Verify DNS/TLS, production MySQL, stable APP_KEY, APP_DEBUG=false, SMTP, scheduler, cache lock, backup, restore drill, and Admin TOTP.
- Use one Publisher and one Website initially.
- Keep every credential out of screenshots, logs, and public static artifacts.
- Record static configuration checksum, deployment evidence, and rollback point before enabling traffic.

## Profile A — Public Publisher Application smoke

1. Open `https://app.horusmedia.net/register/publisher` on the production hostname.
2. If Turnstile is enabled, verify the production hostname and server-side Siteverify result.
3. Register a controlled applicant mailbox and prove email verification.
4. Confirm the identity can access only the applicant application portal.
5. Complete profile/legal acceptance.
6. Record the reserved HMP and website HMS without creating a Site.
7. Publish both exact real records on the applicant Website:
   - `horusmedia.net, HMP-..., DIRECT`
   - `horusmedia.net, HMS-..., DIRECT`
8. Run Horus ads.txt verification.
9. If THOTH is enabled, run one provider connection test and one verified-domain website review; confirm it remains advisory.
10. Perform the human Admin decision.
11. Confirm approval grants the existing Publisher onboarding path but still creates zero Sites/placements/serving deployments.

Stop on cross-tenant access, fake/temporary seller IDs, failed ads.txt ownership evidence, or any automatic serving activation during approval.

## Profile B — GAM-backed Publisher pilot

Use only after Profile A/common gates or an already approved Publisher satisfy equivalent evidence.

1. Create/approve one Website and authorized domain.
2. Confirm HMP/HMS supply-chain state and live public artifacts.
3. Select one real eligible GAM connection and verify network permissions.
4. Create one placement; dry-run/sync required GAM inventory before writes.
5. Publish the production static configuration and install the permanent Loader.
6. Verify browser-direct GPT/GAM requests and no Laravel impression proxy.
7. Enable Prebid `GAM_BRIDGE` only if required for this pilot; verify bidder IDs/consent/mappings.
8. Enable Direct JS only on a separately owned placement if required.
9. Run live privacy evidence appropriate to the traffic geography and active engines.
10. Import real aggregated reporting, reconcile to the provider/GAM source, and verify financial finality.
11. Prove site pause/static rollback.
12. Obtain Operations and Finance sign-off.

Prebid or Direct JS are optional; unused engines do not become blockers.

## Profile C — GAM-less `HORUS_DIRECT` Publisher pilot

1. Set the Website to `HORUS_DIRECT`; do not configure a fake GAM connection or network code.
2. Choose the smallest real engine profile:
   - standalone Prebid only;
   - Direct JS only;
   - standalone Prebid + Direct JS on different placements.
3. Configure only real reviewed provider/bidder identifiers.
4. Publish static configuration and install the same permanent Loader.
5. Verify pure GAM-less pages generate **zero GPT/GAM requests**.
6. Confirm each physical DOM surface has one owner and no GAM/standalone/direct double render.
7. Verify privacy controls and the explicit live privacy probe.
8. Verify live ads.txt/sellers.json/SChain requirements for the demand actually used.
9. Import source-aware aggregated reporting and reconcile realized revenue; Prebid bid estimates are not payout evidence.
10. Prove engine kill switches, site pause, and static rollback.
11. Obtain Operations and Finance sign-off.

Stop immediately on unexpected GPT, a renderer collision, an unreviewed third-party script, secret exposure, or unreconciled revenue.

## Profile D — Direct Advertiser Campaign pilot

Do **not** start this profile while its Task-42 decision is NOT READY. It may begin only after a real production GAM campaign backend has been externally verified.

When that blocker is cleared:

1. Intentionally review and record `advertiser_campaigns.enabled`.
2. Create one small capped advertiser campaign and keep the initial state Draft.
3. Confirm customer-facing readiness is safe and Admin-facing capability identifies the exact selected GAM backend/blockers.
4. Verify all selected campaign sites resolve to eligible production GAM connections and valid planner mappings.
5. Preview/dry-run the plan, then authorize one controlled external deployment.
6. Verify repeated deployment/retry is idempotent.
7. Verify loss of capability blocks submit/approve/schedule/resume/deploy before new external writes.
8. Verify emergency pause still works for an already deployed campaign without deleting remote history or rewriting finance.
9. Reconcile advertiser reporting/invoice and Publisher revenue for the pilot interval.
10. Prove rollback and obtain Ad Ops/Finance sign-off.

Never substitute standalone Prebid or Direct JS as a fake advertiser campaign backend.

## Daily review for an active monetization pilot

- serving/engine state and static manifest checksum;
- impressions, clicks, realized revenue, and errors by real reporting source;
- scheduler/static-delivery health and unresolved jobs;
- supply-chain/public artifact drift;
- privacy evidence freshness;
- tenant-isolation/security events;
- reconciliation and payout-readiness blockers;
- rollback readiness.

## Universal stop conditions

Stop the affected profile on credential exposure, cross-tenant access, wrong public seller identity, unexpected GAM traffic in a pure GAM-less profile, renderer collision, uncontrolled provider tag, unexplained reporting variance, failed safety pause, or failed rollback. Preserve immutable audit/operation evidence and open a scoped incident/remediation item.

The pilot is production evidence collection, not proof that every Horus profile is live.
