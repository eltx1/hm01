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

- **Overview**: unpaid Publisher statement liabilities per Publisher/currency, unreserved
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
- active production Demand/Bidder accounts whose canonical financial source is missing, estimate-only, stale, failed, currency-mismatched, uncovered for the period, or unreconciled; or
- finalized-labelled rows whose source is not settlement eligible.

The close override remains permissioned, reasoned, and audited. It may allow an exceptional operational close, but it never changes row eligibility: only settlement-eligible finalized rows flow into monthly snapshots, Publisher statements, and payouts.

Readiness evidence is persisted. Override requires
`finance.periods.override`, a specific reason, and audit. The cron command's
`--force` confirms the close request; it does not bypass readiness.

The scheduler runs `reporting:close-ready-periods --force` hourly. It retains
second-day earliest closing, retries late-ready months, and processes each
organization/currency chronologically. A blocked earlier period with ledger
rows prevents later automatic closes in that scope. `--dry-run` only reports
readiness. Neither option overrides financial gates or records payments.

Previous-month net publisher earnings remain visible while awaiting close.
Outstanding totals include older unpaid statements. Only below-threshold
balances roll forward; payable and partially paid debts stay on their original
statement. Settlements preserve both publishing and affiliate earnings, and
partial payment does not reapply the threshold to the smaller remainder.

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

## Reviewed historical coverage

A Site GAM financial declaration may now specify one `YYYY-MM` coverage month.
Readiness applies it only to that month. Provider imports that overlap the
month remain blocked to prevent duplicate revenue, while imports and readiness
for other months retain their independent source requirements. In-flight imports and unresolved imports crossing the month boundary retain
their original retry path and must finish before a scoped declaration. Separate provider
facts and closed periods must be reconciled before making a scoped declaration.
The declaration and its evidence reason are audited; it never supplies missing
Google report rows by itself.

Authorized staff can request missing Video history from the website workspace.
The request names the existing verified Video binding and a non-overlapping
range in one open month before that binding starts. It creates a separate,
ended historical binding and exact-hostname scope, leaving active unit claims
and forward reporting unchanged. The normal scheduler/Google importer performs
source verification, dated revenue allocation, duplicate checks and reconciliation.
Creating the request does not import money or close a period. Repeated identical
requests reuse the historical binding. Closed history cannot be backfilled.


### Exact-hostname sharing and ongoing coverage reviews

Website primary and Video reporting can share a physical network/ad unit only
across different verified exact hostnames. Network-level transaction locks serialize
claims across duplicate GAM credentials and both reporting purposes. Each import
rechecks effective ownership and the exact Google Site scope before writing money.
Same-hostname, mobile-app and legacy/unscoped owners remain exclusive. Historical
Video recovery uses the same disjoint-scope checks, retains closed-period protection,
and never rewrites previous financial facts. Releasing one website claim cannot
release another website's claim.

New or re-saved ongoing Site GAM declarations record a server-owned fingerprint of
the reviewed published revenue sources and mapped websites. Changing an ad source,
bidder parameters, delivery mode or adding a mapped website requires an explicit
coverage review before month close (`SITE_GAM_SOURCE_CHANGED`). Changing a source
and then reverting it still requires review because it may have generated revenue.
Pure ordering, candidate priority and container ID changes do not require review.
The fingerprint stores no raw ad recipes or credentials. Month-specific declarations
remain limited to their chosen month; existing declarations retain their original
semantics until an administrator explicitly reviews and saves them again. None of
these reporting controls changes ad serving or creates publisher invoices/payments.

A reviewed Video gap before a future activation may include the current network
day. The existing scheduler imports that day as an estimate and finalizes it only
after network midnight. Future dates and dates overlapping active/history bindings
remain rejected. This closes cutover gaps without moving the active binding,
overwriting primary reports or making today's estimates payable.
