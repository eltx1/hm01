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
const counterFields = ['ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks',
    'video_starts', 'completed_views', 'active_view_viewable_impressions', 'active_view_measurable_impressions',
    'unfilled_impressions'];
const optionalFields = ['active_view_viewable_impressions', 'active_view_measurable_impressions'];
const unsupportedFields = ['unfilled_requests', 'video_starts', 'completed_views', 'unfilled_impressions'];
const probeChecks = ['initial_core_verified', 'targets_verified', 'core_rechecked', 'targets_unchanged', 'scope_rechecked'];
const readerReasons = ['BOOTSTRAP_FAILED', 'MANIFEST_UNAVAILABLE', 'MANIFEST_INVALID', 'LOCK_UNAVAILABLE',
    'SNAPSHOT_UNAVAILABLE', 'AUDIT_MISMATCH', 'AUDIT_FAILED'];
const transportReasons = ['DEPLOY_LOCK_UNAVAILABLE', 'DEPLOY_LOCK_BUSY', 'RELEASE_MISMATCH', 'INTERNAL_ERROR'];
const probeReasons = ['NONE', 'CORE_AUDIT_FAILED', 'TARGET_SCOPE_MISMATCH', 'SOURCE_SCOPE_CHANGED',
    'REPORT_TIMEOUT', 'GOOGLE_REPORT_FAILED', 'RECHECK_FAILED', 'UNSUPPORTED_COUNTERS'];
const exactKeys = (value, keys) => value !== null && typeof value === 'object' && !Array.isArray(value)
    && Object.keys(value).sort().join('\n') === [...keys].sort().join('\n');
const integer = (value, maximum = Number.MAX_SAFE_INTEGER, minimum = 0) => {
    if (!Number.isSafeInteger(value) || value < minimum || value > maximum) throw new Error();
    return value;
};
const booleans = (value, keys) => {
    if (!exactKeys(value, keys)) throw new Error();
    return Object.fromEntries(keys.map(key => {
        if (typeof value[key] !== 'boolean') throw new Error();
        return [key, value[key]];
    }));
};
const states = (value, keys, rows) => {
    if (!exactKeys(value, keys)) throw new Error();
    const safe = Object.fromEntries(keys.map(key => [key, integer(value[key], rows)]));
    if (Object.values(safe).reduce((sum, count) => sum + count, 0) !== rows) throw new Error();
    return safe;
};
const fixedCounts = { ...Object.fromEntries(counts.map(key => [key, 0])), initial_daily_facts: 96, sources: 3,
    applied_windows: 6, unique_receipts: 6, corrected_facts: 35, blocked_facts: 61, covered_facts: 96,
    remaining_facts: 61, remaining_money_zero: 61, remaining_counters_zero: 59, remaining_counters_nonzero: 2 };

function validateProbe(probe, coreStatus) {
    if (!exactKeys(probe, ['status', 'reason', 'target_rows', 'reports_started', 'reports_completed', 'reports_skipped', 'polls', 'checks', 'groups'])
        || !['COMPLETE', 'INCONCLUSIVE', 'SKIPPED'].includes(probe.status) || !probeReasons.includes(probe.reason)
        || !Array.isArray(probe.groups) || probe.groups.length > 2) throw new Error();
    const targetRows = integer(probe.target_rows, 2);
    const started = integer(probe.reports_started, targetRows);
    const completed = integer(probe.reports_completed, started);
    const skipped = integer(probe.reports_skipped, targetRows);
    const polls = integer(probe.polls, 6);
    const safeChecks = booleans(probe.checks, probeChecks);
    if (![0, 2].includes(targetRows) || started + skipped > targetRows || polls < completed || polls > started * 3) throw new Error();
    let totalRows = 0, totalCompleted = 0, totalSkipped = 0, previous = 0;
    const groups = probe.groups.map(group => {
        if (!exactKeys(group, ['source_ordinal', 'month_ordinal', 'rows', 'stored', 'fresh'])) throw new Error();
        const source = integer(group.source_ordinal, 3, 1), month = integer(group.month_ordinal, 96, 1);
        const ordinal = source * 100 + month, rows = integer(group.rows, 2, 1);
        if (ordinal <= previous || !exactKeys(group.stored, counterFields)) throw new Error();
        previous = ordinal;
        const stored = Object.fromEntries(counterFields.map(key => [key, states(group.stored[key], ['zero', 'nonzero', 'unknown'], rows)]));
        // The selected two facts have complete counters and at least one nonzero
        // field per fact. Counts in different fields can overlap.
        if (counterFields.some(key => stored[key].unknown !== 0)
            || counterFields.reduce((sum, key) => sum + stored[key].nonzero, 0) < rows) throw new Error();
        const fresh = group.fresh;
        const freshKeys = ['reports_completed', 'exact_site_observed', 'exact_site_absent', 'unit_day_observed', 'nonmatching_site_observed', 'unsupported_only_skipped'];
        if (!exactKeys(fresh, [...freshKeys, 'fields', 'revenue']) || !exactKeys(fresh.fields, counterFields)) throw new Error();
        const safeFresh = Object.fromEntries(freshKeys.map(key => [key, integer(fresh[key], rows)]));
        if (safeFresh.exact_site_observed + safeFresh.exact_site_absent !== safeFresh.reports_completed
            || safeFresh.reports_completed + safeFresh.unsupported_only_skipped > rows
            || safeFresh.unit_day_observed > safeFresh.reports_completed
            || safeFresh.unit_day_observed < Math.max(safeFresh.exact_site_observed, safeFresh.nonmatching_site_observed)) throw new Error();
        safeFresh.fields = Object.fromEntries(counterFields.map(key => {
            const field = states(fresh.fields[key], ['zero', 'nonzero', 'unknown', 'unavailable'], rows);
            if (unsupportedFields.includes(key) ? field.unavailable !== rows
                : field.unknown < rows - safeFresh.exact_site_observed
                    || (optionalFields.includes(key) ? field.unavailable > safeFresh.exact_site_observed : field.unavailable !== 0)) throw new Error();
            return [key, field];
        }));
        safeFresh.revenue = states(fresh.revenue, ['zero', 'nonzero', 'unknown'], rows);
        if (safeFresh.revenue.unknown < rows - safeFresh.exact_site_observed) throw new Error();
        totalRows += rows;
        totalCompleted += safeFresh.reports_completed;
        totalSkipped += safeFresh.unsupported_only_skipped;
        return { source_ordinal: source, month_ordinal: month, rows, stored, fresh: safeFresh };
    });
    if (totalRows !== targetRows || totalCompleted !== completed || totalSkipped !== skipped
        || safeChecks.targets_verified !== (targetRows === 2)) throw new Error();
    const pairs = {
        COMPLETE: ['NONE', 'UNSUPPORTED_COUNTERS'],
        SKIPPED: ['CORE_AUDIT_FAILED', 'TARGET_SCOPE_MISMATCH', 'SOURCE_SCOPE_CHANGED'],
        INCONCLUSIVE: ['REPORT_TIMEOUT', 'GOOGLE_REPORT_FAILED', 'RECHECK_FAILED'],
    };
    if (!pairs[probe.status].includes(probe.reason)) throw new Error();
    if (probe.status === 'COMPLETE' && (coreStatus !== 'OK' || completed + skipped !== 2 || started !== completed
        || probe.reason !== (skipped > 0 ? 'UNSUPPORTED_COUNTERS' : 'NONE')
        || Object.values(safeChecks).some(value => !value))) throw new Error();
    if (probe.status === 'SKIPPED') {
        if (coreStatus !== 'FAILED' || started !== 0 || completed !== 0 || skipped !== 0 || polls !== 0
            || safeChecks.core_rechecked || safeChecks.targets_unchanged || safeChecks.scope_rechecked
            || safeChecks.initial_core_verified !== (probe.reason !== 'CORE_AUDIT_FAILED')
            || targetRows !== (probe.reason === 'SOURCE_SCOPE_CHANGED' ? 2 : 0)) throw new Error();
    }
    if (probe.status === 'INCONCLUSIVE' && (targetRows !== 2 || !safeChecks.initial_core_verified)) throw new Error();
    if (coreStatus === 'OK' && (targetRows !== 2 || Object.values(safeChecks).some(value => !value))) throw new Error();
    if (probe.status === 'INCONCLUSIVE' && ((coreStatus === 'FAILED' && probe.reason !== 'RECHECK_FAILED')
        || (['REPORT_TIMEOUT', 'GOOGLE_REPORT_FAILED'].includes(probe.reason) && completed + skipped === 2))) throw new Error();
    if (probe.reason === 'RECHECK_FAILED' && (coreStatus !== 'FAILED'
        || [safeChecks.core_rechecked, safeChecks.targets_unchanged, safeChecks.scope_rechecked].every(Boolean))) throw new Error();
    return { status: probe.status, reason: probe.reason, target_rows: targetRows, reports_started: started,
        reports_completed: completed, reports_skipped: skipped, polls, checks: safeChecks, groups };
}

try {
    const [file, transport] = process.argv.slice(2);
    if (!['0', '10'].includes(transport)) throw new Error();
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > 16384) throw new Error();
    const raw = readFileSync(file, 'utf8').trim();
    const result = JSON.parse(raw);
    // Compact JSON also rejects duplicate keys, stray output, coercions,
    // escaped key aliases and multiple result documents.
    if (JSON.stringify(result) !== raw || ![1, 2].includes(result.schema_version)) throw new Error();
    if (result.schema_version === 1 && exactKeys(result, ['schema_version', 'status', 'reason']) && transport === '10'
        && result.status === 'FAILED' && transportReasons.includes(result.reason)) {
        process.stdout.write(JSON.stringify({ schema_version: 1, status: 'FAILED', reason: result.reason }) + '\n');
        process.exitCode = 1;
    } else {
        const keys = ['schema_version', 'status', 'reason', 'counts', 'checks'];
        if (result.schema_version === 2) keys.push('counter_probe');
        if (!exactKeys(result, keys) || !['OK', 'FAILED'].includes(result.status)
            || (result.status === 'OK' ? result.reason !== 'NONE' || transport !== '0' || result.schema_version !== 2
                : !readerReasons.includes(result.reason) || transport !== '10')
            || !exactKeys(result.counts, counts)) throw new Error();
        const safeCounts = Object.fromEntries(counts.map(key => [key, integer(result.counts[key])]));
        const safeChecks = booleans(result.checks, checks);
        if (result.status === 'OK' && (Object.values(safeChecks).some(value => !value)
            || counts.some(key => safeCounts[key] !== fixedCounts[key]))) throw new Error();
        const safe = { schema_version: result.schema_version, status: result.status, reason: result.reason,
            counts: safeCounts, checks: safeChecks };
        if (result.schema_version === 2) safe.counter_probe = validateProbe(result.counter_probe, result.status);
        process.stdout.write(JSON.stringify(safe) + '\n');
        process.exitCode = result.status === 'OK' ? 0 : 1;
    }
} catch {
    process.exitCode = 2;
}
