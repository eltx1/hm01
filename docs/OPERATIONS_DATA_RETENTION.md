# Horus Media — Operations Data Retention

## Scope and safety statement

This document defines the technical lifecycle used by Horus Media to bound selected transient operational data while preserving business and historical truth.

**This is a technical data-lifecycle policy and is not a legal retention certification.**

Age alone never makes a record deletable. Automated pruning is deny-by-default: only datasets with an explicit architecture-specific rule in `config/data-retention.php` and `DataRetentionManager` are eligible.

## Classification model

Every relevant dataset inspected on the current schema is classified into one of four technical categories:

- **PERMANENT / BUSINESS RECORD** — identity, contractual, commercial, financial, application, configuration/version, human-decision, or other historical truth that this automated retention system must not delete.
- **LONG-LIVED EVIDENCE** — operational, security, compliance, deployment, or diagnostic evidence retained for investigation/history. It is not part of the automatic Task 47 prune allowlist unless a separate explicit lifecycle already exists.
- **BOUNDED OPERATIONAL** — repeatable technical observations with a proven safe age boundary and no role as canonical business history.
- **EPHEMERAL** — temporary authorization/runtime metadata that is useful only for a short technical lifecycle and has a safe terminal-state predicate.

## Schema inventory and classification

The inventory below was built from the model directory and the migration schema on the Task 47 base (`main` at Task 46 merge). Table names are taken from the schema rather than inferred.

### PERMANENT / BUSINESS RECORD

**Identity and account truth**

`organizations`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `publishers`, `publisher_contacts`, `advertisers`, `advertiser_contacts`, `advertiser_users`, `advertiser_billing_profiles`.

**Publisher commercial and site history**

`publisher_payment_profiles`, `publisher_contracts`, `sites`, `site_domains`, `site_verifications`, `site_reviews`, `site_notes`, `site_status_history`, `site_serving_settings`, `serving_mode_changes`.

**Publisher Application and legal history**

`publisher_applications`, `publisher_application_domain_claims`, `publisher_application_revisions`, `publisher_application_events`, `publisher_application_legal_acceptances`, `publisher_application_marketing_consents`.

Application revisions and legal acceptances are historical evidence and are never removed by `data-retention:prune`. Terminal application status is not a deletion signal.

**Supply-chain and HMP/HMS identity truth**

`seller_declarations`, `demand_ads_txt_records`, and platform/bidder ads.txt records introduced by the later supply-chain migrations are preserved. In particular, Horus-managed HMP/HMS seller declarations are immutable/non-recyclable identity history and are permanently excluded from automated retention.

**Inventory, serving configuration and rollback history**

`loader_releases`, `tag_versions`, `site_configs`, `ad_units`, `ad_unit_sizes`, `placements`, `placement_sizes`, `placement_targeting`, `site_layout_profiles`, `config_versions`, `ad_formats`, plus the current Prebid/GAM/Demand configuration and remote-object mapping tables. These records define or explain serving configuration and rollback provenance.

**GAM / Prebid / Demand configuration**

`gam_connections`, `gam_credentials`, `gam_networks`, `gam_connection_permissions`, `gam_remote_objects`; `prebid_builds`, `prebid_adapters`, `prebid_bidders`, `bidder_accounts`, `bidder_site_mappings`, `bidder_placement_mappings`, `prebid_settings`, `prebid_price_buckets`, `prebid_gam_templates`, `prebid_gam_remote_objects`; `demand_networks`, `demand_accounts`, `demand_account_credentials`, `demand_sites`, `demand_placements`, `demand_widgets`, `demand_remote_objects`.

**Campaign and advertiser commercial history**

`campaigns`, `campaign_goals`, `campaign_targets`, `campaign_sites`, `campaign_placements`, `campaign_creatives`, `creative_files`, `campaign_budgets`, `campaign_network_instances`, `campaign_delivery_logs`, `campaign_approval_logs`, `advertiser_invoices`.

**Reporting and finance**

`report_sources`, `report_source_connections`, `financial_periods`, `report_dimensions`, `revenue_rules`, `revenue_rule_versions`, `hourly_reports`, `daily_reports`, `monthly_reports`, `revenue_adjustments`, `reconciliation_runs`, `publisher_statements`, `publisher_payments`, `publisher_payment_settlements` and the other finalized finance records attached to those histories.

Finalized periods, statements, payouts/payments, settlement history, revenue-rule versions and reconciliation evidence are never age-pruned by Task 47. Import metadata that is still required to explain finalized financial history is likewise preserved.

**THOTH quality history**

`publisher_quality_profiles`, `publisher_quality_review_runs`, `publisher_quality_decisions`. Human decisions are immutable business evidence and are never auto-pruned. Review runs are preserved because decisions may refer to them and because they provide the evidence snapshot/hash for the human review trail.

**Support**

`support_sla_policies`, `support_tickets`, `support_ticket_messages`, `support_ticket_attachments`, `support_ticket_events` are preserved as customer/support history. Closing a ticket does not make its thread disposable.

### LONG-LIVED EVIDENCE

The following records are intentionally not part of the Task 47 automated delete allowlist:

- `audit_logs` — existing independent security/audit lifecycle. The current default is **2555 days**, now exposed as `DATA_RETENTION_AUDIT_LOGS_DAYS`; `audit-logs:prune` remains the only command that deletes audit rows.
- `login_events` — security/authentication investigation evidence. Task 47 does not introduce a new deletion age.
- `failed_jobs` — failure evidence may contain the only operational explanation for an incident; not age-pruned here.
- `horus_notifications` — notification content and delivery/read state share the same record; there is no separate disposable delivery-state table in the current schema.
- `supply_chain_checks` and later supply-chain origin/check history — compliance/identity evidence, preserved.
- `static_delivery_batches`, `static_delivery_items`, `static_global_artifact_changes` — deployment and rollback evidence, preserved.
- `gam_sync_runs`, `gam_sync_logs`, `gam_api_operations`, `gam_errors`, `prebid_setup_runs`, `prebid_errors`, `demand_sync_logs`, `demand_errors`, `demand_report_imports` — operational/provider history with references and investigation value; not automatically deleted until a separate referential policy is proven safe.
- `report_import_jobs`, `report_import_files`, `report_errors` — retained because imports can support financial/reconciliation history.
- `google_cmp_evidence` — current operator/CMP evidence, not an append-only disposable diagnostic stream.

### BOUNDED OPERATIONAL

**`synthetic_probe_results` — 90 days by default**

Created by the scheduled `adtech:probe` command every fifteen minutes. Each row is a repeatable synthetic runtime observation. It does not represent impressions, finance, identity, or canonical serving state. Eligibility is strictly `observed_at < cutoff`.

Environment key: `DATA_RETENTION_SYNTHETIC_PROBES_DAYS`.

**`privacy_diagnostic_evidence` — 365 days by default**

Historical technical evidence from explicit privacy diagnostics. This is separate from `google_cmp_evidence`, which is preserved. Eligibility is strictly `observed_at < cutoff`.

Environment key: `DATA_RETENTION_PRIVACY_DIAGNOSTIC_EVIDENCE_DAYS`.

**Current-state operational tables**

`monetization_health_states`, `system_heartbeats`, and `platform_controls` are operational but are current-state rows rather than accumulated observation histories. They are therefore **not deleted by age**. Their normal writers replace/update the current state.

### EPHEMERAL

**`privacy_diagnostic_tokens` — expiry plus 30-day grace by default**

Only tokens whose `expires_at` is older than the grace cutoff are eligible. The schema intentionally makes `privacy_diagnostic_evidence.privacy_diagnostic_token_id` nullable with `nullOnDelete`, so removal of the expired authorization token does not delete diagnostic evidence.

Environment key: `DATA_RETENTION_PRIVACY_DIAGNOSTIC_TOKEN_GRACE_DAYS`.

**`user_invitations` — only expired and never accepted; 180 days by default**

Accepted invitations are not eligible. The rule requires both `accepted_at IS NULL` and `expires_at < cutoff`.

Environment key: `DATA_RETENTION_EXPIRED_INVITATIONS_DAYS`.

**`job_batches` — only completed/cancelled framework batch metadata; 90 days by default**

A batch must be old **and** have either `finished_at` or `cancelled_at`. Pending batch metadata is not eligible. `jobs` and `failed_jobs` are explicitly excluded from this rule.

Environment key: `DATA_RETENTION_COMPLETED_JOB_BATCHES_DAYS`.

**Framework-owned ephemeral state not managed by this command**

`jobs`, database sessions, cache/cache locks and password-reset state retain their existing framework lifecycle. Task 47 does not add a second cleanup mechanism that could invalidate active authentication, pending work, or cache locks.

## Current defaults

| Dataset | Category | Default | Eligibility |
|---|---|---:|---|
| `synthetic_probe_results` | BOUNDED OPERATIONAL | 90 days | `observed_at` older than cutoff |
| `privacy_diagnostic_evidence` | BOUNDED OPERATIONAL | 365 days | `observed_at` older than cutoff |
| `privacy_diagnostic_tokens` | EPHEMERAL | 30-day post-expiry grace | `expires_at` older than grace cutoff |
| expired `user_invitations` | EPHEMERAL | 180 days | old **and** never accepted |
| completed/cancelled `job_batches` | EPHEMERAL | 90 days | old **and** terminal |
| `audit_logs` | LONG-LIVED EVIDENCE | 2555 days | separate existing audit command only |

`DATA_RETENTION_CHUNK_SIZE` defaults to 500 and is bounded in code to a maximum of 5000 per delete chunk.

These values are deliberately independent. There is no universal “delete everything older than N days” age.

## Permanent exclusions

`data-retention:prune` has no execution rule for permanent/business datasets. In particular it must never automatically delete:

- HMP/HMS seller identity declarations/history;
- Publisher Application records, revisions, events, domain claims or legal acceptances;
- publisher contracts;
- finalized financial periods, reports, statements, invoices, payouts/payments or settlements;
- revenue-rule versions, adjustments and reconciliation required to explain financial history;
- immutable THOTH human decisions or their referenced review evidence;
- support threads;
- supply-chain identity/compliance history;
- audit/security evidence governed by its independent lifecycle.

Adding a table name to documentation or configuration alone is not enough to make it deletable: `DataRetentionManager` also requires a hard allowlisted query with a safe eligibility predicate.

## Operational command

### Preview / dry-run

```bash
php artisan data-retention:prune
```

Dry-run is the default. It performs bounded count queries and prints only:

- dataset name;
- technical category;
- UTC cutoff;
- eligible count;
- deleted count (`0` in dry-run);
- status;
- operation start/end timestamps.

It never prints row payloads, tokens, private diagnostic content or exception messages. A safe summary is recorded to the existing audit log.

### Execute

```bash
php artisan data-retention:prune --execute
```

Execution selects only primary-key IDs in configured chunks, reapplies the eligibility predicate at delete time, and uses normal `DELETE` statements. It never uses `TRUNCATE` and never loads an unbounded table into application memory.

Each dataset is isolated. Failure in one dataset is recorded as a safe failure type, remaining datasets continue, and the command exits non-zero after the summary.

Execution is idempotent: after all eligible rows are removed, rerunning the same policy deletes zero additional rows unless new records have independently crossed their cutoff.

## Scheduler

The command is scheduled daily at **00:40** using `withoutOverlapping(180)`.

That window is deliberately separated from the current scheduled maintenance/reporting boundaries:

- hourly reporting import at minute `:12`;
- audit retention at `02:15`;
- supply-chain verification at `03:20`;
- daily reporting import at `04:10`;
- monthly financial close at `05:20` on day 2.

The retention command does not pause serving, alter static deployment state, close financial periods, or modify reporting records.

## Referential and tenant safety

- The executable allowlist contains no finance, application, contract, support, seller-identity, supply-chain or configuration-history tables.
- Privacy token deletion relies on the existing `nullOnDelete` relationship; evidence is not cascaded away.
- Accepted invitations are excluded.
- Pending job batches are excluded.
- Queries operate on each eligible table directly and do not join one tenant's identifiers into another tenant's records.
- Synthetic/privacy rows from every tenant are evaluated independently by their own timestamps; a row in one organization cannot make a different organization's row eligible.

Before extending this policy, a new dataset must have a documented category, explicit retention age/terminal-state predicate, FK/reference review, tests for permanent-history preservation, and a hardcoded execution rule. Unknown datasets fail closed.
