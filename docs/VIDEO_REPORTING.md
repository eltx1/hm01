# Optional independent Video reporting

## Scope and configuration

An administrator with `reporting.sources.manage` can enable a website's Video
reporting under its Reports section. Select an existing authorized GAM connection
and a **dedicated Video ad unit**. The account can be the same as or different from
the main reporting account. Selecting an inventory unit does not classify mixed
inventory as video: the unit must be dedicated to the intended Video traffic.
The feature never changes the loader, serving account, placement, player or tags.
No existing website is automatically enabled by the migration or deployment.

The main binding remains in `site_gam_report_bindings`. Video uses
`site_gam_video_report_bindings`, source `GAM_VIDEO_AD_UNIT` and connection type
`SITE_GAM_VIDEO_AD_UNIT`. Both use one physical-unit claim registry, keyed by
network plus unit rather than credential ID. A unit cannot be a simultaneous
primary and Video financial source, even with different connection credentials.
A durable database network mutex serializes binds with financial imports even
when they use different credential records. Current locking reads precede that
mutex; binding/history reads follow it. Rebinding closes only its own previous interval and starts after previous facts,
closed periods and prior ownership of that physical unit. New Video bindings
start prospectively, never as an implicit historical financial correction.

Disabling an active binding ends future ownership while allowing the last owned
day to finalize. Disabling a future binding cancels it before it owns any day.
Historical facts and amounts are retained. Re-enabling after disconnection creates
a new non-overlapping effective interval; it does not reopen history.

## Metrics and money

The SOAP AdX query uses `DATE`, `AD_UNIT_ID`, `SITE_NAME`, a FLAT exact-unit filter,
canonical USD and the selected network timezone. CSV rows must prove the exact
selected unit and exact registered hostname. No children, sibling hostnames,
parent domain or `www` aliases are implicitly combined. Different network timezones
retain their own reporting calendar; the UI states this basis.

Publisher Video revenue and eCPM use `publisher_earnings_minor`, after the existing
date-effective revenue share. Publisher responses and CSV omit gross revenue and
platform allocations. Admin Video views expose gross, publisher and Horus amounts.
eCPM is computed from summed money and impressions, not an average of daily rates.
Main performance metrics remain separate. Financial balances, period closure,
statements and payable amounts include both legitimate sources exactly once.

Original `TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS` remains a separate,
nonfinancial SOAP query and Video sidecar table. Its scope is **the selected ad
unit across all sites**, never the exact hostname. It is not inferred by
subtracting AdX requests. Missing observations are unknown, not zero; a failed
refresh preserves verified prior observations and does not block AdX finance.

## Idempotency, attribution and settlement

Video uses the existing report importer and dated revenue-rule resolver.
The durable Google checkpoint and scope fingerprint identify the binding, unit,
hostname, currency, timezone and effective start. Import operation identity includes
the report connection, granularity, finality, date interval and external report ID
with normalized payload hash. The financial fact is upserted by source connection,
report date and dimension identity. A retry or new Google job with identical facts
does not append a second liability, and finalized facts cannot downgrade to estimates.

Legacy network imports exclude units already owned by a bound source. Video does
not claim unrelated primary revenue for the whole website. Ambiguous overlapping
CSV or legacy site-level money without a provably distinct network/unit fails
closed for review rather than silently adding or deleting money. Existing primary
canonical-source behavior is preserved.

Every effective Video day needs a finalized settlement-eligible fact for month
closure. Primary coverage cannot fill a missing Video day, and Video cannot fill
missing primary coverage. Provider attestations cannot hide Video coverage
blockers. Closed periods retain the existing immutability, statement and payment
guards. Enabling this feature never sends a payment or changes payment credentials.

## Operations and verification

`reporting:sync-site-gam-video` runs on the existing five-minute scheduler with
its own overlap lock. Each source keeps its own resumable jobs, hourly estimated
daily snapshot and six-hour completed-day refresh. Original unit Unfilled runs
independently even while AdX preparation fails or is pending. Hostinger-compatible
PHP/cron remains sufficient; no permanent worker is introduced.

Run the full PHP suite on supported PHP versions, browser regression suite,
production asset build and deployment checks. Focused additions cover binding
independence, duplicate physical-unit ownership, source identity, exact-hostname
attribution, shares, retries, close/statement totals, cancelled future intervals,
role-safe projections and original unit Unfilled. No live account/unit is selected
until an administrator explicitly configures a website.

## Report presentation and review

Main and Video reports use the same selected dates but remain separate reporting sections.
The Video section includes revenue/earnings, impressions, weighted eCPM, original unit-wide
Unfilled, a gap-aware daily chart, daily details and website details. Mobile rows carry
the same metrics as the desktop tables. Publisher amounts and eCPM are net of the
publisher revenue share; gross revenue and Horus allocations are staff-only.

The publisher reconciliation sums imported Main and Video earnings before statement
adjustments. It is not a payable balance. The staff reconciliation preserves the
existing combined financial totals, including approved adjustments, without adding
Video a second time.

Availability is explicit: dates before activation, first import pending, failed refresh,
disabled historical sources, genuine zero rows and unavailable counters are distinct.
A source's latest successful import is not proof that all selected days are complete.
Only imported dates are drawn; missing days remain gaps. Admin reports are finalized-only
while publisher performance can include current estimates.

`VideoReportExperienceTest` creates actual route-rendered synthetic previews when
`HORUS_UI_FIXTURES=1`. The release workflow exercises them in Chromium and WebKit on
desktop/mobile, in dark/light themes, and preserves screenshots in the form-experience
artifact. The original deployed component is retained only as a test fixture for
before/after visual comparison. Fixture screenshots are not live production-account proof.
