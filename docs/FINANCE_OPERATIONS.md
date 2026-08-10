# Admin Finance Operations

## Scope and source of truth

`Finance Operations` is the Horus-only monthly operating surface over the
existing reporting ledger, financial periods, Publisher statements, payment
profiles, revenue-rule versions, adjustments, reconciliation runs, and payout
records. It does not create a second ledger and never initiates or pretends to
initiate bank, Wise, PayPal, or other external money movement.

Laravel continues to store aggregated reporting only. Ad requests, bids,
impressions, visitors, and browser events do not transit or enter the control
plane. Accounting amounts use integer minor units and explicit ISO currency.
Dashboard totals are separated by currency; there is no implicit FX or fake
cross-currency total.

Reporting-source setup/imports remain under `/admin/reporting`. Monthly finance
operations live under `/admin/finance`.

## Control center

The workspace contains:

- **Overview**: latest Publisher liability per Publisher/currency, unreserved
  payout readiness, threshold/invoice blockers, payout states, settlements this
  month, profile queues, period readiness, and reconciliation discrepancies.
- **Financial Periods**: readiness evidence, normal close, and exceptional
  permission/reason-gated override.
- **Publisher Statements**: statement history, invoice review, eligibility,
  safe CSV, individual partial payout creation, and atomic multi-selection.
- **Payouts**: maker-checker approval, scheduling, external-processing record,
  immutable full/partial settlement, hold/release/failure, and safe CSV.
- **Payment Profiles**: incomplete, pending, verified, rejected, needs-update,
  and changed-after-verification queues with masked destinations only.
- **Revenue Rules**: scope/target, effective window, priority, immutable
  versions, shares, currency, creator, and reason.
- **Adjustments**: period, Publisher/website/campaign scope, type, reason,
  amount, Publisher/Horus impact, creator, approver, decision, and timestamps.
- **Reconciliation**: source, connection/import, period, source/stored totals,
  difference, warnings/errors, attempts, retry, and explicit remediation.
- **Advertiser Billing**: the existing advertiser billing product when the
  actor has permission; no duplicate invoice system is created.

## Payout and settlement lifecycle

The defined operational states are `PENDING`, `APPROVED`, `SCHEDULED`,
`PROCESSING`, `PARTIALLY_PAID`, `PAID`, `HELD`, `FAILED`, and the compatible
existing `CANCELLED` state.

Key invariants:

1. Creation requires a payable/partially-paid statement, accepted invoice when
   required, verified profile, matching currency, and unreserved balance.
2. A caller idempotency key is unique. Exact replay returns the existing payout;
   reuse for different instructions fails.
3. The statement and active payouts are locked before availability is
   calculated. Active and held remainders reserve balance.
4. The payout creator cannot approve that payout. Lifecycle methods cannot skip
   approval or reverse terminal states.
5. Scheduling and `PROCESSING` record intent only; they never claim money moved.
6. `publisher_payment_settlements` is append-only settlement evidence. Each row
   has an immutable reference, integer amount, currency, date, and recorder.
   References are globally unique so one external settlement cannot be applied
   to two payouts. Exact repeat is idempotent; changed amount, currency, date,
   or payout for the same reference fails, including concurrent submissions.
7. Settlement locks payout and statement. Amount cannot exceed either remaining
   payout or statement balance. Statement paid/balance fields reconcile from
   settlement rows, never from payout creation or UI state.
8. Partial settlement can continue under another reference. Hold reserves the
   unpaid remainder. Failure releases only the unpaid remainder; already
   settled money remains in the statement.

No batch model was added. The existing per-statement payout stays authoritative;
multi-selection performs all selected creations atomically and derives an
idempotency key per statement.

## Invoice and profile review

A received invoice remains `RECEIVED` and the statement remains
`PENDING_INVOICE` until Finance review. Acceptance moves it to `PAYABLE`;
rejection records a safe Publisher-visible reason. Private storage and object
ownership remain as documented in `PUBLISHER_FINANCE.md`.

The profile queue never renders raw account, routing, or tax values. It shows
beneficiary, method, country/currency, masked last four, and lifecycle evidence.
No sensitive-reveal endpoint was added. A material verified destination change
still resets the shared profile to `NEEDS_UPDATE`.

## Period readiness and concurrency

`FinancialPeriodService` remains the close boundary. Import writes and close
lock the same period row, preventing reporting mutation from passing the open
check while the period closes.

Normal close is blocked by:

- a period that has not ended;
- no finalized daily data;
- estimated/non-final daily data;
- failed or in-progress overlapping imports;
- pending/running/warning/failed reconciliation;
- pending revenue adjustments; or
- reporting/adjustment currency that differs from the period currency.

Readiness evidence is persisted. Override requires
`finance.periods.override`, a specific reason, and audit. The cron command's
`--force` confirms the close request; it does not bypass readiness.

## Reconciliation and correction

Reconciliation warnings retain source/stored totals and differences. Authorized
remediation can mark a warning/failed run `RESOLVED` with a required note; it
does not change DailyReport, MonthlyReport, statement, or period totals. Failed
imports use the existing retry service.

Closed history is never edited to force a match. Corrections use an explicit
open-period import revision, versioned revenue rule, or separately created and
approved revenue adjustment.

## Permissions

| Permission | Capability |
| --- | --- |
| `finance.operations.view` | Open Finance Operations and non-secret queues. |
| `finance.publisher.view` | View Publisher statements/internal CSV. |
| `finance.statements.review` | Accept/reject received Publisher invoices. |
| `finance.payments.view` | View/export payouts. |
| `finance.payments.create` | Create individual/selected payouts. |
| `finance.payments.approve` | Independently approve pending payouts. |
| `finance.payments.settle` | Schedule/process/settle/hold/fail payouts. |
| `finance.payment_profiles.verify` | Decide masked profile verification. |
| `finance.periods.close` | Close a ready period. |
| `finance.periods.override` | Exceptionally override close blockers. |
| `finance.adjustments.create` | Create a pending adjustment. |
| `finance.adjustments.approve` | Independently approve/reject adjustment. |
| `finance.reconciliation.manage` | Retry/remediate reconciliation. |
| `finance.revenue_rules.manage` | Create rules/effective versions. |

Super Admin retains all permissions. Finance Admin and Operations Admin retain
normal operations; period override remains exceptional by default. Service
checks and maker-checker rules remain effective outside UI controls.

## Audit, privacy, and exports

Payout creation/approval/scheduling/processing/settlement/hold/failure, invoice
review, profile verification, close/override, adjustment decisions, rule
versions, and reconciliation remediation are audited through `AuditRecorder`.
Payment destination, routing, tax, and account secrets never enter audit values.
Hold/failure audit records only that a safe reason exists. Settlement references
are operational evidence, not credentials.

Payout and statement CSV require view permission, neutralize spreadsheet formula
cells, retain integer minor-unit fields, and include explicit currency.

## Migration and tests

`2026_08_10_010000_add_finance_operations_controls.php` adds payout
idempotency/hold/failure evidence, append-only settlements, period
readiness/override evidence, adjustment decision reason, and reconciliation
remediation evidence. Existing settled payouts are backfilled without changing
their amounts; duplicate legacy references receive a deterministic legacy
marker while the original payment record remains intact.

Focused coverage is in `AdminFinanceOperationsTest`, with regression coverage
in `ReportingFinancialSystemTest`, `PublisherFinanceExperienceTest`,
`MoneyAndCsvSafetyTest`, and `RevenueCalculationTest`. It covers currency
isolation, thresholds, eligibility, maker-checker permissions, double-payout
prevention, repeated settlement, partial settlement, holds/failures, balance
reconciliation, rollback, close readiness/override, adjustments, rule versions,
reconciliation, audit, masking, safe CSV, and cross-organization denial.
