# Private historical GAM correction operations

This private wrapper delegates all financial changes to the existing
`GamRevenueCorrectionService::start/poll/apply`. It does not change the correction
service, importers, reporting bindings, forward cutover, revenue rules, periods,
statements, payments, accounts, or credentials. No scheduler or repository comment
can execute it.

## Before production use

The wrapper must be reviewed, merged, validated, deployed, and live-verified on
the exact main commit. Review actual production environment protections and the
supported workflow-dispatch capability before attempting a manual run: neither is
proven by adding this workflow. The separately authorized one-time path below
retains the native verification event; never spoof a dispatch event or substitute
an arbitrary comment/repository-event trigger or browser credential reuse.

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

## Finite activation release

The immutable `ops/audit/historical-gam-correction-once.json` contract activates
one automatic operation after the successful `Verify production live` completion
for its first main release. That release must have the approved deployed base
`37569a88ef6180266de313d00487445e6d475079` as its first parent, and the contract
must be absent in that base. Both ordinary two-parent and squash merges work.
Later releases, including deleting and re-adding the file, do not activate another
operation. A new main commit before this merge requires a new internal review;
do not quietly change the approved base to make activation pass.

The native `workflow_run` event must match the exact main SHA, repository identity,
workflow path and authoritative workflow ID, successful run ID and current attempt.
The existing live/deploy/release artifact chain is checked in full. Current main
and every attempt are rechecked after downloads. The runner checks out that same
release. The remote server verifies both the deployed release marker and activation
file checksum while holding the deployment lock. Permissions and secrets are the
existing read-only GitHub permissions and pinned production SSH transport.

The fixed operation inventories all historical sources through `2026-10-02`.
It uses one window per step, substantive internal review before application, and
private receipts for each committed change. The operation-scoped actor selector
is a pseudonymous commitment to the separately verified existing administrator;
it is not authentication and does not prevent dictionary confirmation of guessed
login identifiers. The public contract contains no raw login, reusable login hash,
site allowlist, financial amounts, or new access credentials.

`execute-once` is available only to this automatic transport path, not as a dispatch
input. It advances the private operation for at most 900 seconds between bounded
steps, with five-second spacing. Candidate expiry and existing uncertainty guards
remain unchanged. Unfinished or blocked historical coverage returns `FAILED` /
`INCOMPLETE`, retaining counts and reason codes. An unexpected later failure also
retains the last known progress. The 20-minute workflow timeout remains the outer
bound; a forced process interruption can leave a receipt checkpoint requiring
reconciliation on retry. Rerun the existing failed job on this same activation
release to resume the same operation and receipts; do not create a new workflow
chain level, substitute another operation token, or claim unresolved rows are done.

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

The completed receipt audit verified the fixed 96-fact census: 35 corrected facts
covered by six matching receipts and 61 unchanged, blocked facts with all money
fields zero. All twelve structural, provenance and reporting-parity checks passed.
Of those 61 remaining facts, 59 had all ten stored counters zero; exactly two had
known nonzero counters. An absent exact-site Google row remains unresolved even
when its stored money is zero.

The immediate main successor of verified audit release
`d64d4b6cdb086a38ab8388764bb324898a8304a5` runs the standalone
`verify-historical-correction.php` before any production transfer. The protected
deploy checks current main and that first parent, streams the incoming reader over
pinned SSH, and holds the existing deploy lock while verifying the current immutable
release and pinned artifact SHA-256
`f9872bdd99095ef08723288c32b4beab42988f61f9d645f20cb181385bf60e6f`.
Later releases skip this bounded diagnostic. The separate financial activation
workflow, manifest, base, operation and digest remain unchanged.

The reader first revalidates the original fixed operation, inventory, all receipts,
all twelve core proofs and the exact 59-zero/two-nonzero stored-counter profile.
Only those two unchanged, zero-money `NO_EXACT_SITE_ROW` facts are eligible. It
reports each counter's zero/nonzero/unknown **row counts**, grouped by opaque source
and month ordinals from the full fixed census. It never outputs source or fact IDs,
dates, domains, counter values, monetary amounts, job IDs, report URLs or raw errors.
A failed selection or changed source scope cannot widen the two-fact selection.

A target needs a fresh report only if a directly comparable AdX or ActiveView field
is nonzero. If all its nonzero fields are unrequested video or unfilled metrics,
that target is explicitly counted as `unsupported_only_skipped`; its fresh supported
fields and exact-site evidence remain unknown. Each eligible target can start one
one-day selected-ad-unit report using the exact-site report query and AdX columns.
ActiveView columns are requested only when needed for a stored nonzero ActiveView
counter. There are at most two actual Google report starts, with no fallback,
restart, alternate unit/site or Total metric query. The scheduling budget is 150
monotonic seconds, with at most three polls per target; no new call starts after the
budget. An in-flight call retains the existing client's transport/download timeout,
and the unchanged six-minute audit step is the outer process bound.

Fresh output contains only bounded row counts: completed reports, exact-site
observed or absent, selected-unit day observed, and `nonmatching_site_observed`.
The last count includes blank or unattributed Site labels as well as named Site
labels that do not match; it is not proof of another verified website. For each
counter, fresh zero/nonzero/unknown/unavailable states count rows and never reveal
values. Absent or unfinished exact-site evidence leaves supported fields unknown,
including ActiveView. The four unrequested video/unfilled counters are unavailable;
ActiveView can also be unavailable on a completed exact row with an explicit null
for an unsupported or unrequested optional column. Fresh revenue output classifies
the existing parsed `gross_revenue_minor` field as zero/nonzero/unknown. This uses
the existing daily ledger minor-unit basis, not unrounded Google micros, and never
prints an amount. Within one field the mutually exclusive states sum to the group's
row count; counts across different fields can overlap and must not be summed.

After any report attempt, the reader obtains a new read-only snapshot, repeats all
twelve core proofs, and checks unchanged targets and source scope. Top-level
`status`, `counts` and `checks` describe this final snapshot. A Google timeout or
failure can leave `counter_probe.status` INCONCLUSIVE while the final core proof
remains OK; changed facts, receipts or source scope make the top-level result FAILED.
COMPLETE means the bounded diagnostic finished, including explicit unsupported-only
skips (`UNSUPPORTED_COUNTERS`); it does not prove missing rows are zero or authorize
any correction. Completed reports plus unsupported-only skips plus still-inconclusive
rows account for exactly two targets.

The runner accepts only the full closed version-2 diagnostic schema for success,
including all twelve true core checks, the fixed census/count profile, five true
probe checks and consistent bounded group counts. Original version-1 sanitized
failures remain supported. Incomplete successful payloads, unknown fields/enums,
unbounded ordinals, duplicate keys, inconsistent exit status and malformed output
are rejected without printing their contents. Raw stdout and stderr are suppressed.
The diagnostic neither advances the financial operation nor writes source facts,
allocations, receipts or its manifest. Existing Google operational audit/authentication
bookkeeping may occur through the ordinary report client; all financial protections
and application permission checks remain intact.

Run `node --test tests/Browser/historical-correction-audit-workflow.test.js` for the
audit trust, deployment lock, release-marker, schema and public privacy boundary tests.
Run `php artisan test --filter=HistoricalCorrectionReadOnlyAuditTest` for the fixed census,
target selection, bounded report orchestration, absence, unsupported fields and
final-snapshot drift cases.

Run `php artisan test --filter=GamHistoricalOperationTest` and the existing correction
service suites against SQLite and the project's MySQL contract runtime. Run
`node --test tests/Browser/private-historical-correction-workflow.test.js` for trust,
argument and output-boundary tests. PHP execution requires the supported CI runtime
when PHP/vendor are unavailable locally; static inspection is not a PHP test pass.
Production diagnosis and financial application are separate operations; the diagnostic
automatically applies no historical correction.

## Finite observed-day coverage

The one-time operation freezes its original fact census through the manifest's
historical date bound. Later reporting days are outside that authorization and
cannot enter it on a retry. The original census and every full-window candidate
remain private and auditable.

When a completed full-window query lacks exact-site days, that candidate stays
BLOCKED. The operation may create strict contiguous child ranges only from its
actually observed days that also have valid original facts and allocations. Each
child binds its ancestors' immutable evidence hashes, digests and exact fact IDs,
then obtains its own fresh Google query, snapshot, independent review and digest.
The original candidate is never rewritten as a smaller report. An absent or
invalid day remains untouched and explicitly unresolved, including an old zero.

Private fact-level coverage accounts for every original fact as forward-scoped,
already corrected, pending, ready, or blocked. Applied child receipts mark those
same IDs corrected. Preserved parent windows are excluded from leaf-window totals,
so their children and unresolved days are not counted twice. An automatic run with
remaining blocked coverage reports INCOMPLETE even if other windows committed;
it is never a claim that all historical accounts have been corrected.

The existing six-active-candidate limit remains unchanged. Preserved completed
BLOCKED parents can occupy it until their one-hour expiry. CAPACITY_REACHED stops
the bounded invocation with committed progress retained. After those completed
parents expire, a permitted retry of the same activation release can prepare the
remaining NEW children using fresh queries. Their expired parent evidence selects
only the allowed days; it never substitutes for fresh child evidence. In-flight
or ambiguous submissions are never restarted. Every financial write still passes
the unchanged correction-service locks, current evidence and settlement checks.
