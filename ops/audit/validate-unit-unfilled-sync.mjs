import fs from 'node:fs';
export function validate(input) {
    const statuses = new Set(['DISABLED', 'NO_DATES', 'CURRENT', 'PENDING', 'COMPLETED', 'SOURCE_UNAVAILABLE']);
    if (input?.schema_version !== 1 || input.scope !== 'AD_UNIT_ALL_SITES_V1'
        || input.metric !== 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS' || input.financial_writes !== false
        || !Number.isInteger(input.bindings) || input.bindings < 0 || input.bindings > 25
        || !Number.isInteger(input.observed_days) || input.observed_days < 0
        || !Number.isInteger(input.projected_days) || input.projected_days < 0 || input.projected_days > 31 * input.bindings
        || !Array.isArray(input.projection_checks) || input.projection_checks.length !== input.bindings
        || input.projection_checks.some(value => !['PASS', 'NO_FACTS', 'MISMATCH'].includes(value))
        || !Array.isArray(input.statuses) || input.statuses.length > input.bindings
        || input.statuses.some(value => !statuses.has(value))) throw new Error('INVALID');
    return {schema_version:1, scope:input.scope, metric:input.metric, bindings:input.bindings,
        statuses:input.statuses, observed_days:input.observed_days, projected_days:input.projected_days,
        projection_checks:input.projection_checks, financial_writes:false};
}
if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    if (fs.statSync(process.argv[2]).size > 32768) throw new Error('INVALID');
    console.log(JSON.stringify(validate(JSON.parse(fs.readFileSync(process.argv[2], 'utf8')))));
}
