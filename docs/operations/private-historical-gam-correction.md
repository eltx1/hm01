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

The immediate main successor of reviewed release `708ab326a5241b3ee098dd622a5f8e2e1266d978`
also runs the standalone read-only `verify-historical-correction.php` before any
production transfer. The protected deploy checks current main and that first
parent, streams the incoming reader over pinned SSH, and holds the existing deploy
lock while verifying the current immutable release and its pinned artifact marker.
It reads the existing operation, facts and receipts without advancing an operation
or querying Google. A runner-only validator reconstructs counts and boolean checks;
raw stdout, stderr and invalid output are suppressed. A sound structural audit can
succeed with unresolved exact-site Google evidence: remaining zero, nonzero and
unknown stored values are classifications, never proof of missing Google revenue.
Money zero/nonzero/unknown counts partition the remaining facts. The additional
`remaining_money_known_nonzero` count also detects known nonzero fields on facts
with another unknown monetary field. Counter nonzero and unknown counts can
overlap: a known impression is retained even when another counter is null. Counter
zero requires all ten counter fields to be valid zero; do not sum the overlapping
counter counts as a fact census.
Later releases skip this bounded audit; the financial activation is unchanged.

Run `node --test tests/Browser/historical-correction-audit-workflow.test.js` for the
audit trust, deployment lock, release-marker and closed-output boundary tests.

Run `php artisan test --filter=GamHistoricalOperationTest` and the existing correction
service suites against SQLite and the project's MySQL contract runtime. Run
`node --test tests/Browser/private-historical-correction-workflow.test.js` for trust,
argument and output-boundary tests. PHP execution requires the supported CI runtime
when PHP/vendor are unavailable locally; static inspection is not a PHP test pass.
Production dry-run discovery/preparation and actual application are separate from
source tests and require the exact deployed, trusted main revision.

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
