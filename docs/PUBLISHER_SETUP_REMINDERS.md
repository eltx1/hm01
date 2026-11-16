# Publisher setup reminders

Reminder production is limited to publisher-owned DRAFT/PENDING_VERIFICATION sites
unchanged for seven days. Verified active users with ads.txt verification permission
receive the existing next-action guidance via their SITES notification preferences.
Viewer roles, opted-out users, suspended/deleted organizations, inactive publishers,
deleted sites and stages owned by Horus are excluded. Each recipient/site has a
rolling seven-day cooldown and the existing unique notification dedupe key. The
site row lock and outbox/audit write share a transaction. No page GET or ad runtime
runs reminder work. Existing cron executes the daily command and existing email
outbox respects the recipient's channel choice; no new mail integration is added.
CLI defaults to a dry run, with explicit --apply for the scheduled path.

Local PHPUnit including NotificationCenterTest: 16 tests / 73 assertions passed, including no-write dry run,
preference/permission/verification/stage filters, cooldown/replay, deleted and
suspended records, unchanged site records and audited creation. Required CI and
production deployment remain pending. This finishes implementation of the planned
reminder component; complete task acceptance still includes release evidence.
