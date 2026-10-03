import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {validate} from '../../ops/audit/validate-unfilled-probe.mjs';
const workflow = fs.readFileSync(new URL('../../.github/workflows/deploy-production.yml', import.meta.url), 'utf8');
const source = fs.readFileSync(new URL('../../ops/audit/gam-unfilled-probe.php', import.meta.url), 'utf8');
const valid = () => ({schema_version:1, metric:'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS', scope:'AD_UNIT_AND_EXACT_SITE', period:'LAST_SEVEN_COMPLETE_DAYS', bindings:1,
    probes:[{query_status:'COMPLETED', reason:null, valid_csv:true, nonempty_rows:true, exact_site_observed:false, exact_site_days_complete:false, nonmatching_site_observed:true}]});

test('true metric probe remains isolated and keeps exact scope', () => {
    assert.match(source, /public const METRIC = 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS'/);
    assert.match(source, /public const DIMENSIONS = \['DATE', 'AD_UNIT_ID', 'SITE_NAME'\]/);
    assert.match(source, /WHERE AD_UNIT_ID = :unit/);
    assert.match(source, /\$hostname === \$case\['hostname'\]/);
    assert.doesNotMatch(source, /ReportImport|RevenueCorrection|->ensure\(|->checkpoint\(|->update\(|->delete\(/);
    assert.doesNotMatch(source, /AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE/);
});
test('diagnostic is one reviewed successor, private, bounded and nonblocking', () => {
    const gate = workflow.slice(workflow.indexOf('- name: Require bounded true-unfilled'), workflow.indexOf('- name: Configure pinned SSH'));
    assert.match(gate, /558cc8e069fc188f429f4746ff6bbdcddfe98c4e/);
    assert.match(gate, /commit\.parents\?\.\[0\]\?\.sha === base/);
    assert.match(gate, /main\.commit\.sha === sha/);
    const step = workflow.slice(workflow.indexOf('- name: Probe true unfilled'), workflow.indexOf('- name: Transfer validated release'));
    assert.match(step, /continue-on-error: true/);
    assert.match(step, /if: steps\.unfilled_probe\.outputs\.active == 'true'/);
    assert.match(step, /StrictHostKeyChecking=yes/);
    assert.match(step, /timeout 450 php/);
    assert.match(step, /validate-unfilled-probe\.mjs/);
    assert.doesNotMatch(step, /upload-artifact|cat "\$result"|cat "\$errors"/);
    assert.match(source, /chmod\(\$path, 0600\)/);
});
test('public validator reconstructs a closed protocol without private values', () => {
    const input = valid(); input.private = 'PRIVATE.EXAMPLE'; input.probes[0].csv = 'PRIVATE.EXAMPLE,123456,78';
    assert.deepEqual(validate(input), valid());
    assert.doesNotMatch(JSON.stringify(validate(input)), /PRIVATE|123456/);
});
test('arbitrary strings and malformed booleans are rejected', () => {
    for (const mutation of [v => v.probes[0].reason = 'PRIVATE.EXAMPLE', v => v.probes[0].query_status = 'PRIVATE.EXAMPLE',
        v => v.probes[0].exact_site_observed = 'true', v => v.bindings = 999, v => v.probes = [], v => v.metric = 'unmatched']) {
        const input = valid(); mutation(input); assert.throws(() => validate(input));
    }
    assert.deepEqual(validate({schema_version:1,metric:'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS',reason:'PROBE_FAILED',raw:'PRIVATE'}),
        {schema_version:1,metric:'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS',reason:'PROBE_FAILED'});
});

test('REST public evidence retains only closed status and attribution booleans', () => {
    const input = valid();
    input.rest = {schema_version:1, metric:'UNFILLED_IMPRESSIONS', scope:'AD_UNIT_AND_EXACT_SITE',
        period:'LAST_SEVEN_COMPLETE_DAYS', dry_run:false, bindings:1, raw:'PRIVATE',
        probes:[{query_status:'ACCESS_BLOCKED', definition_status:'NOT_SELECTED', reason:'REST_ACCESS_BLOCKED',
            list_exhausted:false, valid_rows:null, nonempty_rows:null, exact_site_observed:null,
            exact_site_days_complete:null, nonmatching_site_observed:null, raw:'PRIVATE'}]};
    assert.equal(validate(input).rest.probes[0].query_status, 'ACCESS_BLOCKED');
    assert.doesNotMatch(JSON.stringify(validate(input)), /PRIVATE/);
    input.rest.probes[0].reason = 'PRIVATE'; assert.throws(() => validate(input));
});
