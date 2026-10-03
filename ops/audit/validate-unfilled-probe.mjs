import fs from 'node:fs';

const statuses = new Set(['INCONCLUSIVE', 'UNSUPPORTED', 'ACCEPTED', 'COMPLETED']);
const reasons = new Set(['COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'INVALID_DIMENSION_FILTERS',
    'NETWORK_METADATA_MISMATCH', 'INVALID_JOB', 'GOOGLE_REPORT_FAILED', 'INVALID_CSV', 'DUPLICATE_ROWS',
    'INVALID_CASE', 'CAPACITY_EXCEEDED', 'EVIDENCE_WRITE_FAILED', 'PROBE_FAILED', 'TIME_BUDGET']);
const flags = ['valid_csv', 'nonempty_rows', 'exact_site_observed', 'exact_site_days_complete', 'nonmatching_site_observed'];
const check = (truth) => { if (!truth) throw new Error('INVALID'); };
export function validate(input) {
    check(input && input.schema_version === 1 && input.metric === 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS');
    const result = {schema_version: 1, metric: 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS'};
    if (input.reason !== undefined) {
        check(reasons.has(input.reason));
        return {...result, reason: input.reason};
    }
    check(input.scope === 'AD_UNIT_AND_EXACT_SITE' && input.period === 'LAST_SEVEN_COMPLETE_DAYS');
    check(Number.isInteger(input.bindings) && input.bindings >= 0 && input.bindings <= 25);
    check(Array.isArray(input.probes) && input.probes.length === input.bindings);
    const sanitized = {...result, scope: 'AD_UNIT_AND_EXACT_SITE', period: 'LAST_SEVEN_COMPLETE_DAYS', bindings: input.bindings,
        probes: input.probes.map((probe) => {
            check(statuses.has(probe.query_status));
            check(probe.reason === null || reasons.has(probe.reason));
            const clean = {query_status: probe.query_status, reason: probe.reason};
            for (const flag of flags) {
                check(probe[flag] === null || typeof probe[flag] === 'boolean');
                clean[flag] = probe[flag];
            }
            return clean;
        })};
    if (input.rest !== undefined) sanitized.rest = validateRest(input.rest, input.bindings);
    return sanitized;
}

const restStatuses = new Set(['INCONCLUSIVE', 'DRY_RUN', 'ACCEPTED', 'COMPLETED', 'INCOMPATIBLE', 'ACCESS_BLOCKED']);
const definitionStatuses = new Set(['NOT_SELECTED', 'REUSED', 'WOULD_CREATE', 'CREATED']);
const restReasons = new Set(['CAPACITY_EXCEEDED', 'INVALID_CASE', 'NETWORK_METADATA_MISMATCH', 'TIME_BUDGET', 'INVALID_RESPONSE',
    'INVALID_REPORT', 'REPORT_DEFINITION_MISMATCH', 'LIST_INCOMPLETE', 'INVALID_OPERATION', 'INVALID_RESULT',
    'RESULT_DATE_RANGE_MISMATCH', 'INVALID_ROWS', 'DUPLICATE_ROWS', 'ROWS_INCOMPLETE', 'EVIDENCE_WRITE_FAILED',
    'REST_ACCESS_BLOCKED', 'REST_INCOMPATIBLE', 'REST_INVALID_ARGUMENT', 'REST_RESOURCE_EXHAUSTED',
    'REST_TIMEOUT', 'REST_NOT_FOUND', 'REST_UNAVAILABLE', 'REST_FAILED', 'DRY_RUN', 'NO_ROWS', 'POLL_LIMIT']);
export function validateRest(input, bindings) {
    check(Number.isInteger(bindings) && bindings >= 0 && bindings <= 25);
    check(input && input.schema_version === 1 && input.metric === 'UNFILLED_IMPRESSIONS'
        && input.scope === 'AD_UNIT_AND_EXACT_SITE' && input.period === 'LAST_SEVEN_COMPLETE_DAYS'
        && typeof input.dry_run === 'boolean' && input.bindings === bindings
        && Array.isArray(input.probes) && input.probes.length === bindings);
    return {schema_version:1, metric:'UNFILLED_IMPRESSIONS', scope:'AD_UNIT_AND_EXACT_SITE',
        period:'LAST_SEVEN_COMPLETE_DAYS', dry_run:input.dry_run, bindings,
        probes:input.probes.map(probe => {
            check(restStatuses.has(probe.query_status) && definitionStatuses.has(probe.definition_status)
                && (probe.reason === null || restReasons.has(probe.reason)) && typeof probe.list_exhausted === 'boolean');
            const clean = {query_status:probe.query_status, definition_status:probe.definition_status,
                reason:probe.reason, list_exhausted:probe.list_exhausted};
            for (const flag of ['valid_rows', ...flags.filter(flag => flag !== 'valid_csv')]) {
                check(probe[flag] === null || typeof probe[flag] === 'boolean'); clean[flag] = probe[flag];
            }
            return clean;
        })};
}
if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    const path = process.argv[2];
    check(typeof path === 'string' && fs.statSync(path).size <= 32768);
    console.log(JSON.stringify(validate(JSON.parse(fs.readFileSync(path, 'utf8')))));
}
