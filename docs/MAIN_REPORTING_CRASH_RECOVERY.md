# Main reporting crash recovery

## Cause and change

Laravel's default `withoutOverlapping()` launch lease lasts 1,440 minutes. A PHP
crash, OOM, or SIGKILL can prevent normal cleanup while cron and the independent
Video reporting command continue running. The main reporting command now owns a
MySQL session advisory lock on its financial writer PDO for its complete run.
Only its scheduler throttle changes to `withoutOverlapping(10)`. Every other
scheduled command, including Video, retains its existing protection.

The scheduler command/mutex name is unchanged. **Deployment does not shorten an
existing 1,440-minute lease.** Never run a global cache clear, schedule clear, or
force-release an opaque owner. Existing incidents need separate verified recovery
or natural expiry. This change prevents new day-long stranded launch leases.

## Supported topology and fail-closed behavior

- All application hosts must connect directly to one authoritative writable MySQL
  or MariaDB server for the same database and table prefix. Standard PHP 8.2+
  shared/cloud hosting works; no Redis, daemon or permanent worker is required.
- The key is independent of hostname, deploy path, app key, connection alias and
  `--site`. Full and site-specific main-command runs contend for the same lock.
- Nonpersistent PDO is required. Read/write splitting, host arrays, a separate
  read PDO, pre-existing transactions, non-MySQL drivers, and replacement database
  connections fail closed. There is no SQLite or local-filesystem production
  fallback. Tests using SQLite explicitly mock only the command's guard.
- During the guarded command, database-backed cache and cache-lock operations
  must use that same Laravel connection. A separate `DB_CACHE_CONNECTION` or
  `DB_CACHE_LOCK_CONNECTION` is rejected when first used, before its query.
- Session-multiplexing proxies, independent writable primaries, and a proxy that
  silently moves a session between servers are **not supported**. Ownership
  probes detect session changes, but are not a substitute for single-writer
  topology. Confirm the actual deployment topology before release.

The guard checks the exact manager connection object, writer/read PDO identity,
MySQL connection ID and current named-lock owner before database queries,
transaction starts/commits and reporting provider calls. Queries use the pinned
PDO; Laravel's implicit reconnect is disabled for the guarded run. Any loss
permanently poisons that run. Catch paths cannot clear checkpoints, write failure
rows, continue to another binding, or start Unfilled work after that loss.
A late provider response cannot resume financial writes on a replacement session.
Cleanup rolls back the raw PDO before releasing only that session's lock. This
also covers Laravel's commit-listener exception transaction-counter edge case.
Healthy cleanup restores the prior timeout and exact reconnect callback;
failed cleanup discards the connection and reports a command failure.

## Recovery bound and limitations

The owning session has `SESSION wait_timeout=120`. Ownership probes and normal
queries keep a live worker active; total job duration is not limited to 120
seconds or to the ten-minute launch throttle. A terminated PHP process normally
closes its session immediately. An abandoned **idle** server session expires
within 120 seconds of its last activity. A newly stranded scheduler throttle
expires after ten minutes, followed by the next five-minute cron opportunity.
This is a normal-case recovery bound for idle/crashed workers, not a guarantee
against a running/hung SQL query, database/server outage, missing cron, or an
unsupported proxy. Provider requests retain their existing bounded timeouts.

The new exclusion covers cooperating `reporting:sync-site-gam` command workers.
It does not expand the lock to admin retries, one-time import runners, or the
independent Unfilled command. Existing per-source 300-second locks, exact-site
validation, import idempotency, revenue calculations, financial closure rules,
and historical-correction approvals remain unchanged. No historical financial
correction is introduced by this patch.

## Verification

The regular MySQL CI job must execute `MainReportSyncLockTest`, using real MySQL
connections and separate PHP processes, not mocked locks. It covers contention,
real database scheduler-lease expiry with a simulated clock while a separate
PHP/MySQL owner remains alive, preservation of legacy lease ownership, SIGKILL without finally,
database-session death, idle expiry, late responses, live jobs, poison state,
transactions/commit loss, reconnect/purge rejection and healthy restoration.
SQLite jobs cover command wiring with an explicit mock and the unchanged main/
Video/import financial regressions. `SchedulerHeartbeatRecoveryTest` verifies
that only the main reporting throttle changed. The process fixture refuses to
run unless passed an explicit synthetic test connection under `APP_ENV=testing`.

Before deployment, require green PHP 8.2–8.4 and MySQL tests. After deployment,
verify the exact release, main-import freshness and subsequent scheduled passes;
a fresh general cron heartbeat alone does not establish report health.
