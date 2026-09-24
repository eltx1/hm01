# Publisher reporting and Finance code recheck

This review follows PR #197. It checks the implementation, routes and role
boundaries, rather than treating the previous green build as proof of every
business state.

## Confirmed defects and corrections

- Invoice upload only checked the threshold and whether an invoice was already
  received. A direct POST could upload against a paid, draft, carried-forward,
  zero-balance or explicitly not-required statement and change its status back
  to pending invoice. Model eligibility now controls the form, action list and
  server-side upload validation.
- Upload state was read and updated without a row lock. The current statement is
  now validated and updated inside a transaction with `lockForUpdate`, matching
  the locking discipline used by invoice review.
- Reviewing a received invoice could reopen a no-longer-payable statement.
  Review now validates its current financial state under the existing lock.
- A zero balance with a zero threshold generated a required invoice. It now
  generates a finalized statement with no invoice requirement.
- Payout readiness excluded draft/paid balances but not explicitly carried or
  below-threshold statuses when their amounts were inconsistent. Eligibility
  now agrees with the displayed payable amount.

No existing balances, settlements, invoices or financial rows are rewritten.
Ad runtimes and theme assets are untouched.

## Publisher checks

Both Publisher Admin and Publisher Viewer render reports, Finance overview,
statements, payment details and payouts using the shared theme script/toggle.
The viewer does not receive invoice-upload or payment-profile-edit controls.
Existing regression coverage checks cross-publisher statement/CSV/private-file
ownership, denied mutations, encrypted and masked payout details, settlement-only
paid totals, currency isolation and contractual-only publisher earnings.
New checks cover both current and legacy invoice POST routes, unchanged storage
and financial state after rejection, all ineligible statement states and paid
statement review. Existing positive upload/review tests remain in the full suite.

The reporting chart and dark/light shell are shared between publisher and admin;
report content differs intentionally by role. Browser tests use real shared
CSS/JS in a fixture; PHP tests render actual publisher routes. This does not
claim a visual inspection of an authenticated publisher's production session.

## Validation

PHP is not installed in the local workspace. PHP/MySQL tests run in GitHub CI;
record the exact results in the PR after completion. Local whitespace checks and
existing theme Node tests are used before submitting the patch.

## Changed files

- `app/Models/PublisherStatement.php`
- `app/Services/Reporting/PublisherFinanceService.php`
- `app/Services/Reporting/PublisherStatementService.php`
- `resources/views/publisher/finance/statement.blade.php`
- `tests/Feature/PublisherFinanceExperienceTest.php`
- `docs/PUBLISHER_FINANCE_RECHECK.md`
