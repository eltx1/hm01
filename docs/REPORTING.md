# Reporting

## Source-of-truth model

GAM and optional external networks are serving-system sources. Horus Media
imports their reports into the control plane. The Laravel backend stores
aggregated reporting data only; it does not receive ad requests and does not
store bidstream or visitor-level serving events.

## Future pipeline

1. Cron triggers report discovery and short-lived import work.
2. Connector requests or retrieves a bounded source report.
3. Raw transfer files are processed transiently outside the public web root.
4. Rows are validated and normalized into aggregate dimensions and metrics.
5. Idempotent upserts use source, account, date, dimension key, and report
   version.
6. Reconciliation compares imported totals with source totals.
7. Revenue-share calculations produce versioned ledger entries.
8. Approved balances advance through a separately audited payment workflow.

## Initial grain

Daily aggregation is expected across only the dimensions needed by the product,
such as organization, website, placement, serving mode, demand source, country,
device class, and currency. The exact dimensional model will be finalized with
reporting requirements and API limits.

## Financial correctness

Source revenue, adjustments, platform share, publisher share, currency, and
calculation version must remain independently traceable. Calculations use fixed
precision or integer minor units. Corrections append adjustments rather than
silently rewriting settled amounts.

Reporting, revenue shares, and payments are not implemented in this release.
