import fs from 'node:fs';
import {validateRest} from './validate-unfilled-probe.mjs';
const failures = new Set(['RELEASE_MISMATCH', 'CAPACITY_EXCEEDED', 'INVALID_CASE', 'EVIDENCE_WRITE_FAILED', 'REST_FAILED']);
export function validate(input) {
    if (input?.diagnostic === 'REST_UNFILLED_ONLY') {
        if (input.schema_version !== 1 || !failures.has(input.reason)) throw new Error('INVALID');
        return {schema_version:1, diagnostic:'REST_UNFILLED_ONLY', reason:input.reason};
    }
    const clean = validateRest(input, input?.bindings);
    const allowed = new Set(['SERVICE_DISABLED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'IAM_PERMISSION_DENIED', 'CONSUMER_INVALID',
        'PROJECT_DELETED', 'BILLING_DISABLED', 'SECURITY_POLICY_VIOLATED', 'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'CREDENTIALS_MISSING', 'UNCLASSIFIED']);
    const reasons = input.access_error_reasons;
    if (!Array.isArray(reasons) || reasons.length > allowed.size || new Set(reasons).size !== reasons.length || reasons.some(value => !allowed.has(value))) throw new Error('INVALID');
    return {...clean, access_error_reasons:[...reasons]};
}
if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    const path = process.argv[2];
    if (typeof path !== 'string' || fs.statSync(path).size > 32768) throw new Error('INVALID');
    console.log(JSON.stringify(validate(JSON.parse(fs.readFileSync(path, 'utf8')))));
}
