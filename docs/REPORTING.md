# Horus Media Reporting and Finance

## Source of truth

Horus GAM is the primary reporting source. Optional MCM partner GAM and publisher GAM connections, Prebid estimates, MGID, Taboola, Speakol, Outbrain, custom CSV files, and approved manual adjustments remain separately identifiable while being normalized into one Horus Media reporting ledger.

The Laravel control plane stores aggregated rows only. It never receives ad requests and never stores impression-level, bid-request, visitor, or browser telemetry. Transfer files are private, bounded, checksummed, and processed outside the public web root.

## Reporting grain

The unified ledger supports hourly estimates, finalized daily rows, and immutable monthly closing snapshots. Rows are keyed by source connection, date or hour, normalized dimension hash, and revision.

Supported dimensions are publisher, website, placement, GAM network, demand network, bidder, advertiser, campaign, country, device, browser, operating system, and ad size. Missing dimensions remain null instead of inventing mappings.

Supported aggregate metrics are ad requests, matched requests, unfilled requests, impressions, clicks, fill rate, CTR, viewability, gross revenue, eCPM, CPC, video starts, and completed views. Money is stored in integer minor units. Rates use basis points or integer micro-units.

## Import lifecycle

1. Cron discovers active report source connections.
2. Hourly imports are estimated and daily imports are finalized.
3. The source connector returns a bounded aggregate report.
4. CSV and manual imports pass through the same normalization pipeline.
5. A checksum and deterministic idempotency key prevent duplicate imports.
6. Each normalized row receives a deterministic source-row hash.
7. Existing rows are unchanged when the hash matches, or revised when finalized source data changes while the period is open.
8. Source totals are reconciled with stored totals and discrepancies above the configured threshold generate warnings.
9. Failures are categorized, retained, and eligible for cron-compatible retry.
10. A closed financial period rejects every automatic revision.

The scheduled commands are:

```bash
php artisan reporting:import hourly --retry-failed
php artisan reporting:import daily --retry-failed
php artisan reporting:close-period 2026-07 --currency=USD --force
```

These commands are short-lived and compatible with shared or cloud hosting cron. They do not require Redis, Supervisor, Docker, WebSockets, or a permanent worker.

## Revenue calculation

```text
Gross Revenue
- Demand Partner Deductions
- Invalid Traffic Adjustments
- Other Approved Adjustments
= Net Revenue

Net Revenue × Publisher Share = Publisher Earnings
Net Revenue × Horus Media Share = Horus Media Earnings
Net Revenue × optional MCM Partner Share = MCM Partner Earnings
```

The three share percentages are stored as basis points and must total exactly 10,000. The rounding remainder belongs to Horus Media so the three earnings amounts always equal net revenue exactly.

## Revenue rules and versions

Rules may be global or scoped to a publisher, website, demand source, or campaign. The active rule with the highest specificity wins: campaign, demand source, website, publisher, then global. Priority resolves ties within the same scope.

Every percentage change creates a new `revenue_rule_versions` row. Existing versions are never overwritten. A new version cannot start within a closed financial period, and reports retain the version that calculated them.

## Adjustments and reconciliation

Adjustments are positive deduction amounts classified as demand-partner deduction, invalid traffic, or another approved adjustment. Creation and approval are separate audited operations. Approved impact is allocated using the applicable revenue-share version and included in the monthly publisher statement. Closed periods accept no new adjustments; corrections belong to the next open period.

Every import creates a reconciliation record containing source totals, stored totals, differences, maximum discrepancy basis points, and warnings. The report source connection retains last-attempt, last-success, last-finalized, status, and error state.

## Financial periods

A financial period is opened automatically by the first import for a currency. Closing locks the period, aggregates finalized daily rows into monthly snapshots, generates publisher statements, records approved adjustments, stores totals and a snapshot hash, and marks the period closed.

Once closed, imports, revenue rules, or adjustments cannot rewrite that period automatically. The monthly rows and statements carry hashes for traceability.

## Publisher statements and payments

A statement contains opening carry-forward, gross revenue, deductions, net revenue, publisher earnings, payment threshold, paid amount, balance due, line items, source rows, and an immutable snapshot hash.

Balances below threshold carry forward. Payable statements may require the publisher to upload a private PDF or image invoice. Finance can create and approve payments, settle a full or partial amount, and store the Horus payment reference. Partial settlement updates the remaining statement balance without deleting payment history.

The Publisher product surface, payment-profile verification lifecycle, private
invoice ownership rules, Publisher-safe CSV, currency separation, and
requested-versus-settled payout presentation are documented in
[`PUBLISHER_FINANCE.md`](PUBLISHER_FINANCE.md).

Publishers can view only their organization-scoped reports and statements, download CSV, print the HTML statement, and upload their invoice. Horus administrators can view gross revenue, net revenue, publisher earnings, Horus margin, optional MCM earnings, and all outstanding payments.

## Advertiser reporting and invoices

Campaign delivery from Horus GAM or an explicitly selected GAM connection enters `advertiser_reports` and the same unified import history. Advertisers see only their own campaign impressions, clicks, CTR, spend, remaining budget, and invoices.

The existing campaign invoice entity is extended rather than duplicated. It stores paid amount, balance due, payment reference, linked financial period, report snapshot, and snapshot hash. The invoice can be synchronized from finalized campaign-cost reports.

## Security and audit

- No production credential or raw source file is public.
- Uploaded reports and publisher invoices are stored on the private local disk.
- Publisher invoice downloads require explicit statement ownership and return
  private, no-store responses.
- Encrypted payment account, routing, and tax values are masked after save and
  excluded from HTML, validation old input, and audit metadata.
- Every manual import, revenue-rule version, adjustment decision, financial close, statement generation, and publisher payment is audited.
- Organization global scopes protect publisher and advertiser views; Horus administrators retain cross-organization reporting access.
- External report reads are deterministic and idempotent. No reporting operation changes publisher installation code or the browser delivery path.

## Release validation

Run the focused financial and integration suite before the complete repository matrix:

```bash
php artisan migrate:fresh --seed --force
php artisan route:list --path=reporting
php artisan test \
  tests/Unit/RevenueCalculationTest.php \
  tests/Feature/ReportingFinancialSystemTest.php \
  tests/Feature/DirectCampaignSystemTest.php \
  tests/Feature/DemandNetworkSystemTest.php
```

The focused suite verifies duplicate and checksum protection, unified Horus GAM and alternative-source totals, revenue formulas and exact rounding, rule specificity and version history, reconciliation, closed-period immutability, statement thresholds and carry-forward, audited adjustments, private invoice upload, partial payment, advertiser balances, and publisher isolation.

A reporting release must additionally pass PHP 8.2, PHP 8.3, PHP 8.4, SQLite and MySQL migrations, the complete backend suite, dependency audit, Horus Loader browser tests, production asset compilation, and browser distribution verification. Temporary transport or materialization files must never remain in the final tree.
