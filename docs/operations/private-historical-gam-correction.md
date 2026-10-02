# Private historical GAM correction operations

This manual-only wrapper delegates all financial changes to the existing
`GamRevenueCorrectionService::start/poll/apply`. It does not change the correction
service, importers, reporting bindings, forward cutover, revenue rules, periods,
statements, payments, accounts, or credentials. No scheduler or repository comment
can execute it.

## Before production use

The wrapper must be reviewed, merged, validated, deployed, and live-verified on
the exact main commit. Review actual production environment protections and the
supported workflow-dispatch capability before attempting a run: neither is proven
by adding this workflow. Missing dispatch or approval capabilities are blockers;
do not substitute a comment/repository-event trigger or browser credential reuse.

Use the existing authorized application administrator whose login was independently
verified. Compute SHA-256 of the lowercased, trimmed login identifier privately.
The fingerprint is a pseudonymous selector, not authentication or new approval.
The command resolves exactly one existing, active, unlocked, email-verified Horus
administrator in an active organization with all three permissions:
`reporting.admin.view`, `reporting.import`, `finance.adjustments.approve`.
Never select an arbitrary administrator, create an account, or enter an email,
account ID, domain, amount, arbitrary reason, or credential in workflow inputs.

Generate a random 32-byte opaque operation token, represented as 64 lowercase
hexadecimal characters. Retain it for every stage and retry. Choose the successful
live-verification run for the exact current main commit. The workflow verifies
linked validation/deployment/live artifacts, workflow paths, repository, events,
attempts, main SHA, and production release marker/immutable directory. It holds
the existing server deployment lock while running the command.

## Modes and internal review

1. `discover` (default) makes no Google calls and no financial writes. It creates
   an immutable plan of actual contiguous stored days, split by month, binding,
   gaps, and verified forward cutover. It inventories all GAM-associated source
   connections and daily/hourly facts, including inactive, unbound and old data.
   A source can be identified by GAM source code, connection type, binding, or
   GAM dimension metadata. Active bindings alone are never the coverage census.
2. `prepare` starts at most `limit` sequential windows (1–6). The underlying
   service retains its six-active-candidate, one-hour expiry, and 60-poll limits.
   Candidate evidence and all actual amounts stay in the private database.
3. `poll` advances at most `limit` pending windows once. It respects the service's
   next-poll timestamp. It also records substantive internal checks for currency,
   selected unit, registered hostname, every expected day, original rule versions,
   old/new allocation arithmetic, aggregate reconciliation, current facts,
   financial state, query/candidate digests, and pre-cutover range. READY status
   alone never passes internal review. Missing Google days block the original
   whole window; the wrapper never narrows it or supplies zeroes.
4. `status` inspects existing private evidence without Google/financial writes.
   Review the aggregate counts and blocked reason codes internally. Detailed
   candidates, original rules, amounts, dates and receipt evidence remain private.
5. After internal review, `apply` requires the exact operation digest returned by
   the reviewed batch and `limit` at least the batch size. It preflights the whole
   batch, then calls the existing service per candidate with its own exact digest.
   Each window remains an independent transaction with the original locks,
   current-evidence checks, closed-period/statement/payment exclusions, and
   reconciliation. An interruption can leave a partly committed batch; status and
   receipt-only retries preserve that progress honestly.

Complete a batch before preparing the next. `pending` includes unprepared windows
as well as pending Google candidates; `ready` counts internally reviewed windows;
`applied` counts committed windows. `blocked_facts` reports inventory exclusions,
and `blocked` reports candidate windows that cannot proceed. Discovery's fact/source
counts are a frozen census, so an OK batch is never a claim that all old reports
have been corrected. All blocked coverage remains visible in the private manifest.
Fresh fully verified forward-scope rows are counted separately, not corrected;
their normal updates do not stale historical evidence. Unknown/mixed post-cutover
rows remain blocked and participate in drift checks.

New or changed historical rows, bindings, scope, or receipts invalidate the
operation. Candidate service checks additionally bind original rules and all
financial state. Start a new discovery only after inspecting the reason; previous
manifests and receipts must be retained. A new discovery followed by explicit
`prepare` can replace an expired READY/BLOCKED/FAILED candidate through the existing
service, retaining its reference and superseded evidence. The same operation never
restarts a terminal window. STARTING, PENDING, APPLIED and ambiguous attempts are
never replaced, even after expiry; an uncertain Google submission must be
investigated through the existing private application evidence. Never reopen a
period or edit raw financial SQL to proceed.

## Private storage and output boundary

Manifests are locked and atomically replaced under
`storage/app/private/gam-historical-operations`, with owner-only files. They contain
only required identifiers, inventory hashes, coverage, window/candidate references,
review checks, and receipt provenance. They never serialize User or GAM credential
models. The service database remains authoritative for financial payloads.

The remote transport redirects PHP bootstrap, stdout, stderr and exceptions to a
new owner-only server directory before PHP starts. Public workflow output is only
reconstructed JSON with an exact key schema and allowlisted outcome/reason codes,
counts, opaque operation token and digest. There are no public artifacts, comments,
issue updates, amounts, identities, domain names, CSVs, signed URLs or raw errors.
Application permission checks and every service guard still apply to CLI execution.

## Verification

Run `php artisan test --filter=GamHistoricalOperationTest` and the existing correction
service suites against SQLite and the project's MySQL contract runtime. Run
`node --test tests/Browser/private-historical-correction-workflow.test.js` for trust,
argument and output-boundary tests. PHP execution requires the supported CI runtime
when PHP/vendor are unavailable locally; static inspection is not a PHP test pass.
Production dry-run discovery/preparation and actual application are separate from
source tests and require the exact deployed, trusted main revision.
