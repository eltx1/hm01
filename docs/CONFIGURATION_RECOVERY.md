# Configuration recovery with current supply

Recovery restores historical placement order, status, targeting, lazy-load,
refresh and collapse behavior, plus page targeting and diagnostic/house flags.
The candidate starts from the current public configuration. Provider recipes,
Prebid bidders/build/configuration, GAM network, inventory paths, format, renderer
ownership, loader, supply chain and all admission/observation controls remain
current. Deleted placements and obsolete historical payload fields are omitted;
placement kill switches remain authoritative. A current provider configuration
that cannot be built causes a fixed validation error instead of publishing an
unvalidated candidate.

The history screen links to an authorized private/no-store preview with a selected
field projection. Preview queues nothing and exposes no recipe/secret payload.
Applying uses the existing audited rollback/outbox path, recomputes current supply,
and preserves current Cloudflare, Click Guard, domains and pause. It is a recovery
of advertising presentation, not a mechanism to restore revoked provider access.

Normal publication and recovery serialize version allocation using the site row
inside their database transaction. Identical immediate recovery retries reuse the
latest matching source/candidate, ignoring only generated time, version number and
loader cache bust metadata. Changed current policy produces a new candidate rather
than reusing stale safety settings. Unique version and delivery constraints remain.
ConfigurationRecoveryConcurrencyTest adds two real MySQL processes to verify
publish/recovery serialization in both directions. It refuses non-testing databases
and requires a database name ending in _test. Its two cases intentionally skip on
SQLite; their MySQL CI result is still pending. Sequential replay alone is not
counted as concurrency evidence.

Validation: PHP 8.4 DirectDemandAdminControlCenterTest, InventoryConfigurationTest
PrebidModeControlHardeningTest, StandalonePrebidRuntimeTest and ConfigurationRollbackPolicyTest: 36 tests / 270 assertions passed. Regressions
include a disabled provider remaining disabled, current network/renderer paths,
placement kill switches, deleted inventory, obsolete historical script removal,
read-only preview, unchanged source version, current protection preservation and
replay reuse of one outbox item, and a fresh recovery version after a terminal delivery failure. Required CI and deployment remain pending.

Paused presentation is resolved in memory for recovery in the current provider builders.
DISABLED inventory is never promoted. Regressions cover both Direct JS and standalone
Prebid recovery without changing their stored placement status.
