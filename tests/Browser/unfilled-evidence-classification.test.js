import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {validate} from '../../ops/audit/validate-unfilled-evidence.mjs';
const valid = () => ({schema_version:1, source:'PR242_SAVED_EVIDENCE', new_google_requests:false,
    bindings:Array.from({length:3}, () => ({soap_site_categories:['NOT_APPLICABLE'],rest_stage:'GET_NETWORK',rest_status:'PERMISSION_DENIED',structured_error_present:true,rest_reasons:['SERVICE_DISABLED']}))});
test('classification public protocol drops identifiers and accepts only closed values', () => {
    const data = valid(); data.raw = 'PRIVATE'; data.bindings[0].raw = 'PRIVATE';
    assert.deepEqual(validate(data), valid());
    for (const mutate of [d => d.bindings[0].rest_reasons = ['PRIVATE'], d => d.bindings[0].soap_site_categories = ['private.example'],
        d => d.bindings[0].rest_stage = 'private/path', d => d.bindings[0].structured_error_present = 'true',
        d => d.new_google_requests = true, d => d.bindings.pop()]) {
        const copy = valid(); mutate(copy); assert.throws(() => validate(copy));
    }
    assert.deepEqual(validate({schema_version:1, source:'PR242_SAVED_EVIDENCE', reason:'EVIDENCE_AMBIGUOUS',raw:'PRIVATE'}),
        {schema_version:1, source:'PR242_SAVED_EVIDENCE', reason:'EVIDENCE_AMBIGUOUS'});
});
test('saved-evidence classifier has no network framework database or credential path', () => {
    const source = fs.readFileSync(new URL('../../ops/audit/classify-unfilled-evidence.php', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /vendor\/autoload|bootstrap\/app|Http::|curl_|SoapClient|accessToken|GamSecret|DB::|->update\(|->delete\(/);
    assert.match(source, /1791060800/); assert.match(source, /1791060825/);
    assert.match(source, /SOURCE_RELEASE_MISMATCH/); assert.match(source, /EVIDENCE_AMBIGUOUS/);
    const workflow = fs.readFileSync(new URL('../../.github/workflows/deploy-production.yml', import.meta.url), 'utf8');
    const gate = workflow.slice(workflow.indexOf('- name: Require saved Unfilled'),workflow.indexOf('- name: Require bounded historical'));
    assert.match(gate, /7be80e9dff9d53cb82fe9dea9c007ac749adf1f4/);
    assert.match(gate, /commit\.parents\?\.\[0\]\?\.sha === base/); assert.match(gate, /main\.commit\.sha === sha/);
    const step = workflow.slice(workflow.indexOf('- name: Classify saved Unfilled'),workflow.indexOf('- name: Transfer validated'));
    assert.match(step, /StrictHostKeyChecking=yes/); assert.match(step,/timeout 30 php/);
    assert.match(step,/validate-unfilled-evidence\.mjs/);
    assert.doesNotMatch(step,/upload-artifact|cat "\$result"|cat "\$errors"|gam-unfilled-probe\.php/);
});
