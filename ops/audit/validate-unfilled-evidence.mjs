import fs from 'node:fs';
const reasons = new Set(['SERVICE_DISABLED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'IAM_PERMISSION_DENIED', 'CONSUMER_INVALID', 'PROJECT_DELETED', 'BILLING_DISABLED', 'SECURITY_POLICY_VIOLATED', 'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'CREDENTIALS_MISSING', 'UNCLASSIFIED']);
const failures = new Set(['SOURCE_RELEASE_MISMATCH', 'EVIDENCE_NOT_FOUND', 'EVIDENCE_CAPACITY', 'EVIDENCE_AMBIGUOUS', 'EVIDENCE_INVALID']);
const categories = new Set(['NOT_APPLICABLE', 'UNKNOWN', 'EMPTY', 'OTHER_HOSTNAME', 'OTHER_LABEL']);
const check = truth => { if (!truth) throw new Error('INVALID'); };
const list = (value, allowed) => { check(Array.isArray(value) && value.length > 0 && value.length <= allowed.size && new Set(value).size === value.length && value.every(x => allowed.has(x))); return [...value]; };
export function validate(input) {
    check(input?.schema_version === 1 && input.source === 'PR242_SAVED_EVIDENCE');
    const base = {schema_version: 1, source: 'PR242_SAVED_EVIDENCE'};
    if (input.reason !== undefined) { check(failures.has(input.reason)); return {...base, reason: input.reason}; }
    check(input.new_google_requests === false && Array.isArray(input.bindings) && input.bindings.length === 3);
    return {...base, new_google_requests: false, bindings: input.bindings.map(row => {
        check(['UNKNOWN', 'GET_NETWORK', 'LIST_REPORTS'].includes(row.rest_stage));
        check(['UNKNOWN', 'PERMISSION_DENIED', 'UNAUTHENTICATED'].includes(row.rest_status));
        check(typeof row.structured_error_present === 'boolean');
        return {soap_site_categories: list(row.soap_site_categories, categories), rest_stage: row.rest_stage,
            rest_status: row.rest_status, structured_error_present: row.structured_error_present, rest_reasons: list(row.rest_reasons, reasons)};
    })};
}
if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    const path = process.argv[2]; check(typeof path === 'string' && fs.statSync(path).size <= 8192);
    console.log(JSON.stringify(validate(JSON.parse(fs.readFileSync(path, 'utf8')))));
}
