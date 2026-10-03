// Runner-only closed output boundary. Never echo input or exception details.
import { lstatSync, readFileSync } from 'node:fs';

const counts = [
    'initial_daily_facts', 'sources', 'applied_windows', 'unique_receipts', 'corrected_facts',
    'blocked_facts', 'pending_facts', 'covered_facts', 'remaining_facts', 'remaining_money_zero',
    'remaining_money_nonzero', 'remaining_money_unknown', 'remaining_money_known_nonzero', 'remaining_counters_zero',
    'remaining_counters_nonzero', 'remaining_counters_unknown', 'missing_facts', 'duplicate_facts',
    'missing_receipts', 'duplicate_receipts', 'currency_issues', 'hash_issues', 'provenance_issues',
    'other_record_drift',
];
const checks = [
    'manifest_identity', 'manifest_digest', 'inventory_matches', 'coverage_complete',
    'receipts_match', 'corrected_hashes_match', 'remaining_hashes_match', 'remaining_money_valid',
    'currency_valid', 'provenance_valid', 'admin_reporting_parity', 'publisher_reporting_parity',
];
const readerReasons = ['BOOTSTRAP_FAILED', 'MANIFEST_UNAVAILABLE', 'MANIFEST_INVALID', 'LOCK_UNAVAILABLE',
    'SNAPSHOT_UNAVAILABLE', 'AUDIT_MISMATCH', 'AUDIT_FAILED'];
const transportReasons = ['DEPLOY_LOCK_UNAVAILABLE', 'DEPLOY_LOCK_BUSY', 'RELEASE_MISMATCH', 'INTERNAL_ERROR'];
const exactKeys = (value, keys) => value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).sort().join('\n') === [...keys].sort().join('\n');

try {
    const [file, transport] = process.argv.slice(2);
    if (!['0', '10'].includes(transport)) throw new Error();
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > 16384) throw new Error();
    const raw = readFileSync(file, 'utf8').trim();
    const result = JSON.parse(raw);
    // Reader uses compact JSON. This also rejects duplicate keys, stray output,
    // number coercion, escaped key aliases, and multiple result documents.
    if (JSON.stringify(result) !== raw || result.schema_version !== 1) throw new Error();
    if (exactKeys(result, ['schema_version', 'status', 'reason']) && transport === '10'
        && result.status === 'FAILED' && transportReasons.includes(result.reason)) {
        process.stdout.write(JSON.stringify({ schema_version: 1, status: 'FAILED', reason: result.reason }) + '\n');
        process.exitCode = 1;
    } else {
        if (!exactKeys(result, ['schema_version', 'status', 'reason', 'counts', 'checks'])
            || !['OK', 'FAILED'].includes(result.status)
            || (result.status === 'OK' ? result.reason !== 'NONE' || transport !== '0'
                : !readerReasons.includes(result.reason) || transport !== '10')
            || !exactKeys(result.counts, counts) || !exactKeys(result.checks, checks)) throw new Error();
        const safeCounts = Object.fromEntries(counts.map(key => {
            const value = result.counts[key];
            if (!Number.isSafeInteger(value) || value < 0) throw new Error();
            return [key, value];
        }));
        const safeChecks = Object.fromEntries(checks.map(key => {
            const value = result.checks[key];
            if (typeof value !== 'boolean' || (result.status === 'OK' && !value)) throw new Error();
            return [key, value];
        }));
        process.stdout.write(JSON.stringify({ schema_version: 1, status: result.status, reason: result.reason,
            counts: safeCounts, checks: safeChecks }) + '\n');
        process.exitCode = result.status === 'OK' ? 0 : 1;
    }
} catch {
    process.exitCode = 2;
}
