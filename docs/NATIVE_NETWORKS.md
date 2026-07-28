# Native Networks

Native networks are optional demand connectors. They do not replace
`HORUS_GAM` as the default and are not required for onboarding.

## Future connector contract

Each connector should declare supported capabilities such as reporting read,
campaign write, creative write, placement mapping, and direct browser serving.
Credential storage, health state, rate limits, and last successful
synchronization should be normalized without erasing provider-specific details.

Every external write must support dry-run, use idempotent reconciliation, and
produce a sensitive-operation audit record. Read and write credentials should
be separated where providers allow it.

## Serving options

Native demand can participate through GAM-compatible creatives or line items,
through Prebid-compatible adapters, or through the explicit
`DIRECT_NATIVE_ONLY` website mode. Provider scripts must be loaded only from
allowlisted origins and only when the published site configuration enables them.

## Reporting

Provider reports are imported on a schedule and normalized into aggregate daily
facts. Source totals remain traceable for reconciliation. The backend does not
collect native ad requests or visitor-level events.

No native connector is implemented in the foundation release.
