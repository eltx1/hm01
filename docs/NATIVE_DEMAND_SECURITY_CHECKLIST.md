# Native Demand Security and Operations Checklist

Use this checklist before approving or enabling a native or alternative demand account, website mapping, placement, or widget.

## Credential boundary

- Store only `env:` or private `file:` references in `demand_account_credentials`.
- Keep credential files outside the public web root.
- Never place tokens, passwords, client secrets, private keys, credential references, revenue shares, or private API endpoints in static configuration.
- Test the account through the dashboard after every credential rotation.

## Public JavaScript tags

- Approve only HTTPS script URLs.
- Add the exact provider origin to the connector or account allowlist.
- Treat `src`, `srcdoc`, `href`, `integrity`, `nonce`, event-handler attributes, and secret-like attribute names as reserved. Horus rejects or ignores them in public tag attributes so they cannot replace the reviewed script URL or execute inline handlers.
- Keep public attributes limited to provider widget identifiers and non-sensitive `data-*` values.
- Use a bounded render timeout and a provider-specific success selector when available.

## Approval and activation

- Require `APPROVED` status at the network account, website mapping, placement mapping, and widget levels.
- Confirm the network, account, website, placement, and widget are enabled before publishing.
- Verify that rejected, suspended, or disabled mappings are absent from the production static configuration.
- Republish the website configuration after every activation, rejection, suspension, priority, mode, or widget change.

## GAM deployment

- Confirm the website resolves to the intended GAM connection; `HORUS_GAM` remains the default.
- Synchronize every targeted ad unit to that exact GAM connection before deployment.
- Review the dry-run plan before confirming external writes.
- Confirm company, order, line item, creative, association, inventory targeting, sizes, dates, and lifecycle status.
- Verify repeated deployment reports no pending unchanged objects.
- On creative replacement, verify the old creative is archived, the old association is removed, and the existing local mapping row is updated rather than deleted.
- Test pause and resume against the remote line item before publishing the new configuration.

## Loader and fallback

- Confirm native-only placements render without loading GPT.
- Confirm GAM placements try Prebid and GAM first, then approved direct candidates by priority, then sanitized house content.
- Confirm a failed or no-render provider advances to the next candidate without breaking the publisher page.
- Confirm GAM-managed candidates never expose or inject a direct tag.
- Enable debug output only when troubleshooting; diagnostics must contain public delivery state only.

## Reporting and retention

- Import aggregated daily rows only.
- Validate report dates, impressions, clicks, and revenue minor units.
- Use checksum-based duplicate protection for CSV fallback.
- Disable delivery without deleting historical reports, ads.txt history, errors, synchronization logs, or remote mappings.

## Release validation

Before merging a native-demand change, require:

- PHP syntax checks for changed PHP files.
- Laravel feature tests on PHP 8.2, 8.3, and 8.4.
- MySQL migration and test execution.
- Horus Loader browser tests.
- Production asset and minified Loader rebuild.
- Verification that no temporary bootstrap or transport files remain in the final tree.
