# Horus Media Go-Live Checklist

This checklist separates repository evidence from external production evidence.
A checked code item does not mean that the live service has been configured.

## Gate 0 — Release evidence

- [x] Main branch contains the Laravel control plane and documented serving boundary.
- [x] PHP 8.2, 8.3, and 8.4 test matrix passed in the latest release validation.
- [x] MySQL integration tests passed.
- [x] Horus Loader browser suite passed.
- [x] Production Composer install, frontend build, release ZIP, and secret scan passed.
- [ ] A named release owner has recorded the production commit SHA and artifact checksum.
- [ ] A rollback release and database backup have been identified.

Evidence: release commit, workflow run, artifact ID, ZIP checksum, owner.

## Gate 1 — Infrastructure

- [ ] app.horusmedia.net points to the Laravel public/ directory.
- [ ] cdn.horusmedia.net is verified as the Cloudflare Pages custom domain and does not point to Hostinger.
- [ ] TLS is valid on all production domains.
- [ ] MySQL production database and least-privilege user exist.
- [ ] .env is private, APP_DEBUG=false, and APP_KEY is stable.
- [ ] storage/ and bootstrap/cache/ are writable; no Hostinger CDN document root is required.
- [ ] SMTP delivery works for invitations, password reset, and operational alerts.
- [ ] The once-per-minute scheduler is installed and healthy.
- [ ] Static delivery uses 30-minute UTC normal boundaries and the production database cache lock store is healthy.
- [ ] CDN cache and CORS headers match CLOUDFLARE_SETUP.md.
- [ ] GitHub Pages workflow has least-privilege Cloudflare Secrets and the control plane uses a private token reference.

Evidence: URLs, screenshots or command output, hosting configuration, and
scheduler heartbeat.

## Gate 2 — Security and recovery

- [ ] First administrator uses the protected bootstrap command and completes TOTP.
- [ ] No credential file is under a public document root.
- [ ] GAM and provider references use encrypted env: or file: references.
- [ ] Authentication, export, loader configuration, and administrative write rate limits are enabled or consciously accepted.
- [ ] Security headers, CSP, HSTS, clickjacking, and MIME-sniffing policy are verified.
- [ ] MySQL backup completed before migration.
- [ ] A restore drill succeeded on an isolated database.
- [ ] Contract files and private storage are included in backup scope.
- [ ] Retention, access review, incident owner, and escalation path are documented.

Evidence: security-header scan, backup ID, restore timestamp, and access-review record.

## Gate 3 — Ad delivery

- [ ] HORUS_GAM is connected, enabled, and passes a dry-run health check.
- [ ] Network permissions and root ad unit configuration are validated.
- [ ] A test website has an authorized domain and one test placement.
- [ ] Its production batch is confirmed DEPLOYED with manifest hash, workflow run, and Pages evidence.
- [ ] Operations → Static Delivery shows budget/reserve, last remote evidence, snapshot limits, and the next normal boundary.
- [ ] Deploy Now with an empty queue is verified as an audited no-op, while an emergency pause is verified as immediate URGENT work.
- [ ] The permanent loader works on the authorized hostname.
- [ ] GPT requests reach the selected GAM network directly from the browser.
- [ ] House-ad testing proves slot rendering and empty-slot behavior.
- [ ] Pause and rollback are tested using a non-critical site.
- [ ] Browser Network evidence shows zero requests to app.horusmedia.net across load, refresh, and SPA navigation.
- [ ] Prebid is enabled only after bidder consent, IDs, timeout, and GAM setup are reviewed.
- [ ] Privacy Readiness shows CONFIGURED separately from current LIVE_VERIFIED evidence.
- [ ] Required TCF/GPP APIs respond on the authorized production hostname and the active Prebid build/config contains the required privacy modules and controls.
- [ ] Where the runtime policy explicitly requires Google CMP evidence, an Admin has recorded current operator verification; a CMP ID alone is not accepted as proof.
- [ ] Native fallback is tested for script failure, timeout, and no-render cases.

Evidence: site key, config checksum, browser/network captures, GAM test report,
and rollback result.

## Gate 4 — Commercial pilot

- [ ] One publisher contract and revenue-share rule are approved.
- [ ] One advertiser billing profile and test invoice are approved.
- [ ] A small campaign is approved with explicit dates, budget, targeting, and creative.
- [ ] Campaign deployment is previewed, confirmed, and reconciled per network.
- [ ] Aggregated delivery reporting matches the source network for the pilot period.
- [ ] Advertiser report and invoice balance are reviewed.
- [ ] Publisher statement and payment readiness are reviewed.
- [ ] Finance owner signs off before any real payment is executed.

Evidence: signed pilot record, campaign ID, report comparison, invoice, statement,
and finance approval.

## Go / No-Go

Go only when Gates 0–3 are complete and Gate 4 has an owner and a rollback plan.
Start with one publisher and one controlled campaign. Stop immediately on
credential exposure, cross-tenant access, incorrect public configuration,
unreconciled GAM writes, unexplained reporting variance, or failed rollback.

## Task 20 GAM-less pilot gate

Before enabling a GAM-less site, require: production static config checksum;
`HORUS_DIRECT`; at least one healthy standalone-Prebid or Direct-JS placement;
zero renderer conflicts; privacy and Click Guard gates verified; no GPT request
for pure no-GAM profiles; provider account/site/placement approvals; canonical
ads.txt where required; source-aware aggregated reporting configured; rollback
validated; and Operations sign-off. The production-release workflow now executes
migration fresh, rollback of the latest migration, and reapply before the full
PHP suite and release archive validation.
