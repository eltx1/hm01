# Publisher Earnings and Payments

## Scope and source of truth

The Publisher `Earnings & Payments` workspace is a presentation and
self-service layer over the existing Horus reporting ledger, financial periods,
publisher statements, payment records, and private invoice storage. It does not
create a second ledger or recalculate finalized accounting in a controller.

Laravel continues to store aggregated reporting only. Ad requests, bids,
impressions, and visitor events never pass through or enter the control plane.
All money stored by this module uses integer minor units with an explicit ISO
currency. Totals from different currencies are never combined.

## Publisher workspace

The role-aware navigation exposes four non-overlapping sections:

- **Overview** separates current-period estimated earnings from finalized
  earnings and shows payable, below-threshold, carry-forward, pending,
  scheduled, and settled amounts per currency.
- **Statements** lists immutable finalized statements and provides a
  Publisher-safe detail and CSV projection.
- **Payment Method** manages the Publisher's own encrypted payout destination.
- **Payout History** distinguishes requested, scheduled, and actually settled
  amounts, including partial settlement and safe references.

Legacy `/publisher/reporting` URLs remain compatibility aliases to the same
canonical views. They do not maintain a duplicate finance product.

## Finality and currency boundaries

`DailyReport.finality` is authoritative for the overview:

- `ESTIMATED` rows appear only under **Estimated earnings** and may change.
- `FINALIZED` rows appear only under **Finalized earnings**.
- Statement amounts are finalized accounting records produced by
  `PublisherStatementService` when a financial period closes.

Every overview card group is keyed by currency. The active contract's payment
threshold is converted from its fixed two-decimal database value to integer
minor units with `App\Support\Money`; no floating-point accounting conversion
is used.

## Payment profile lifecycle

`PublisherPaymentProfile.verification_status` is the operational lifecycle:

| Status | Meaning |
| --- | --- |
| `INCOMPLETE` | Required identity or destination information is missing. |
| `PENDING_VERIFICATION` | A complete destination awaits Finance review. |
| `VERIFIED` | Finance verified the current destination. |
| `REJECTED` | Finance rejected it with a safe Publisher-visible reason. |
| `NEEDS_UPDATE` | A previously verified destination changed materially and must be verified again. |

The legacy `is_verified` boolean remains synchronized for backward data
compatibility. Account/routing data and tax identifiers retain encrypted model
casts. Normal screens render only the last four account characters. Raw values
are excluded from validation old input, HTML, audit values, and audit metadata.

`PublisherPaymentProfileService` is the single write boundary used by onboarding,
Publisher self-service, and Admin editing. A change to beneficiary, method,
currency, country, billing address, account, routing, or tax destination clears
verification evidence. Verification is a separate Admin-only action guarded by
`finance.payment_profiles.verify`.

## Statements and private invoices

The statement's invoice lifecycle is `NOT_REQUIRED`, `REQUIRED`, `RECEIVED`,
`ACCEPTED`, or `REJECTED`. Task 5 records secure upload as `RECEIVED`; Admin
acceptance/rejection is an explicit Finance operation, not inferred from file
creation.

Invoice files are stored on the private local disk through
`SecureUploadService`. Upload and download routes first resolve the authenticated
organization's Publisher and then compare both `publisher_id` and
`organization_id`. A caller cannot select a Publisher identity through a query
parameter or URL. Download responses are private and no-store.

Publisher CSV exports omit Horus margin and provider-confidential gross/net
economics. Text cells that begin with spreadsheet formula control characters
are prefixed safely. Admin CSV remains the existing internal projection.

## Payout presentation

`PublisherPayment.amount_minor` is the requested amount and is never presented
as settled merely because a row exists. `settled_amount_minor` records actual
settlement. A partial payment therefore preserves the requested amount, records
the smaller settled amount, and updates the statement balance using only the
settled amount. Publisher pages never render internal payment notes; only the
explicit safe `publisher_message` may explain a failure or required action.

Creating a payout requires a verified payment profile, an Admin-accepted
invoice when required, matching currency, and an unreserved statement balance.
The Admin payout state machine, separation of duties, immutable partial/full
settlement controls, holds/failures, and reconciliation workbench are documented
in [`FINANCE_OPERATIONS.md`](FINANCE_OPERATIONS.md).

## Permissions

| Permission | Default role |
| --- | --- |
| `finance.publisher.view_own` | Publisher Admin, Publisher Viewer |
| `finance.publisher.payment_profile.manage` | Publisher Admin |
| `finance.publisher.invoice.upload` | Publisher Admin |
| `finance.payment_profiles.verify` | Finance Admin (and Super Admin through all permissions) |

Old reporting permissions remain on compatibility routes during the transition.
Admin payment-profile editing remains separate from Publisher self-service.

## Audit and security events

The existing `AuditRecorder` records profile creation/update, material
destination change, verification change, invoice upload, statement generation,
and payout lifecycle events. Its recursive redaction list includes common bank,
IBAN, SWIFT/BIC, PayPal, Wise, routing, account, and tax identifiers. Audit
snapshots include only the masked account suffix, booleans describing which
private values exist, and the lifecycle state.

## Release checks

Focused coverage is in `PublisherFinanceExperienceTest`,
`PublisherPaymentProfileTest`, `ReportingFinancialSystemTest`, and
`MoneyAndCsvSafetyTest`. It covers access, permissions, cross-Publisher IDOR,
private invoice ownership, encryption, masking, validation input redaction,
verification reset, finality, thresholds, carry-forward, currency isolation,
payout history, partial payment, safe CSV, and regression of the existing
statement/payment services.
