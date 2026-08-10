# Horus Notification Center, SLA Automation, and Action Center

## Product boundary

The Notification Center records authorized events that happened. The Action
Center independently computes unresolved source conditions that require action
now. Reading or dismissing a notification never resolves an Action Center item;
the authoritative domain state must change.

The implementation uses MySQL/SQLite-compatible database storage, Laravel mail,
and the Laravel Scheduler. It requires no Redis, WebSocket server, Supervisor,
permanent worker, Node.js production runtime, or Docker service.

## Durable storage

`horus_notifications` stores a ULID recipient record with typed category and
severity, bounded title/message, controlled related-entity alias, registered
route name and parameters, read time, email state, and a SHA-256 deduplication
key. The key contains the recipient plus a stable domain event/state key, so
concurrent or repeated producers cannot create the same recipient event twice.
The database unique constraint is the final race-safety boundary.

`notification_preferences` stores one typed channel choice per user/category.
Account-critical communication is mandatory and cannot be disabled. Other
categories default to durable in-app delivery with email opt-in. Preferences
never accept a user ID from the request; they are always written through the
authenticated user relationship.

Email-only events remain durable for delivery evidence but have
`in_app_visible=false`, so disabling the in-app channel actually removes that
category from the user's bell and list without deleting accounting evidence.

No notification payload may contain bank details, tax identifiers, payment
credentials, Support message bodies, internal notes, provider secrets, or raw
operational payloads. Action destinations are stored as registered route names,
not user-supplied URLs. The destination controller still performs its normal
permission and object authorization.

## Audience resolution and event coverage

Recipients are resolved from current active users by exact organization and/or
permission. Initial event integrations are:

- Support: creation, assignment, public Horus/customer reply, resolution,
  close/reopen, first-response warning/breach, and resolution warning/breach.
  Internal notes never create customer notifications.
- Supply chain: Ads.txt non-compliance transitions and compliant recovery. A
  repeated identical verifier snapshot reuses its check and dedupe key.
- Publisher/site: review submissions, approval/rejection/suspension transitions,
  and serving-mode changes.
- Finance: finalized statements/invoice requirement, payment-profile review and
  verification state, approved/scheduled/partial/paid/failed/held payouts, and
  reconciliation warning/failure/recovery.
- Operations: failed report imports, failed static delivery, and failed GAM API
  operations. Only safe summary text is copied; stored provider errors remain on
  their separately authorized operational page.

Publisher-visible payout language preserves accounting finality: approval and
scheduling explicitly do not claim money was paid. Paid/partial notifications
are produced only after the existing settlement service records settlement.

## SLA and email automation

`support:sla-monitor` evaluates indexed open-ticket deadlines and emits a stable
key for each ticket, metric, due timestamp, and warning/breach state. Repeated
runs create neither duplicate notification nor duplicate ticket event. Paused,
resolved, and closed tickets are excluded.

`notifications:deliver-email` delivers bounded batches synchronously through
the configured Laravel mailer. Delivery attempts and timestamps are persisted;
inactive recipients are suppressed and a failure is retried at most three
times. Both commands run every five minutes with scheduler overlap protection.
Hostinger needs only the normal once-per-minute `schedule:run` cron.

## UI and performance

Every signed-in role has an unread bell, five-item preview, paginated Notification
Center, read/unread controls, mark-all-read, exact action links, and preferences.
All mutations use authenticated POST/PATCH/PUT web routes and CSRF middleware.

Action Center providers use bounded aggregate queries. Support priority/SLA
counts share one conditional aggregate, and finance liability/failure/mismatch
counts share one scalar aggregate query. Publisher actions are tenant-scoped and
disappear when the payment profile, Ads.txt state, or Support source state is
resolved.

## Operational commands

```bash
php artisan support:sla-monitor
php artisan notifications:deliver-email --limit=50
php artisan schedule:list
```
