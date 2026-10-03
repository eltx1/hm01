import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {validate} from '../../ops/audit/validate-rest-unfilled-diagnostic.mjs';
const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const valid = () => ({schema_version:1,metric:'UNFILLED_IMPRESSIONS',scope:'AD_UNIT_AND_EXACT_SITE',period:'LAST_SEVEN_COMPLETE_DAYS',dry_run:false,bindings:1,
    probes:[{query_status:'ACCESS_BLOCKED',definition_status:'NOT_SELECTED',reason:'REST_ACCESS_BLOCKED',list_exhausted:false,valid_rows:null,
        nonempty_rows:null,exact_site_observed:null,exact_site_days_complete:null,nonmatching_site_observed:null}],access_error_reasons:['SERVICE_DISABLED']});
test('manual REST workflow never runs automatically or deploys application', () => {
    const workflow = read('.github/workflows/diagnose-unfilled-rest.yml');
    assert.match(workflow, /on:\s+workflow_dispatch:/);
    assert.doesNotMatch(workflow, /workflow_run:|pull_request:|push:|schedule:|inputs:|horus-atomic-deploy\.sh|artisan migrate|upload-artifact/);
    assert.match(workflow, /context\.eventName !== 'workflow_dispatch'/);
    assert.match(workflow, /context\.payload\.repository\?\.id !== 1315038901/);
    assert.match(workflow, /main\.commit\.sha !== context\.sha/);
    assert.match(workflow, /group: horus-production-deploy/);
    assert.match(workflow, /grep -Fxq "release_id=\$EXPECTED_RELEASE"/);
    assert.match(workflow, /\[\[ "\$resolved" == "\$REMOTE_HOME\/releases\/\$EXPECTED_RELEASE" \]\]/);
    assert.match(workflow, /StrictHostKeyChecking=yes/);
    assert.match(workflow, /timeout 240 php/);
    assert.doesNotMatch(workflow, /cat "\$result"|cat "\$errors"/);
});
test('wrapper reuses bounded REST helper only with original scope and private evidence', () => {
    const source = read('ops/audit/gam-rest-unfilled-diagnostic.php');
    assert.match(source, /HorusGamRestUnfilledProbe::run\(\$cases, \$restRequest, \$restEvidence, dryRun: false\)/);
    assert.doesNotMatch(source, /HorusGamUnfilledProbe::run|ReportImport|RevenueCorrection|->ensure\(|->checkpoint\(|->update\(|->delete\(/);
    assert.match(source, /'unit_id' => \(string\) \$binding->ad_unit_id/);
    assert.match(source, /hostname\(\$binding->site->primary_domain\)/);
    assert.match(source, /subDays\(6\)/);
    assert.match(source, /chmod\(\$path, 0600\)/);
    assert.match(source, /allow_redirects' => false/);
    assert.match(source, /config\('gam.rest.base_url'\)/);
    assert.doesNotMatch(source, /https:\/\/admanager\.googleapis\.com\/v1/);
    assert.ok(source.indexOf('RELEASE_MISMATCH') < source.indexOf("vendor/autoload.php"));
});
test('REST public evidence preserves explicit unknown and structured access reason without raw details', () => {
    const input = valid(); input.raw='PRIVATE'; input.probes[0].raw='PRIVATE';
    assert.deepEqual(validate(input), valid());
    for (const mutation of [v=>v.access_error_reasons=['PRIVATE'],v=>v.access_error_reasons=['SERVICE_DISABLED','SERVICE_DISABLED'],
        v=>v.bindings=26,v=>v.probes[0].exact_site_observed='yes',v=>v.probes[0].reason='private.example']) {
        const data=valid();mutation(data);assert.throws(()=>validate(data));
    }
    assert.deepEqual(validate({schema_version:1,diagnostic:'REST_UNFILLED_ONLY',reason:'RELEASE_MISMATCH',raw:'PRIVATE'}),
        {schema_version:1,diagnostic:'REST_UNFILLED_ONLY',reason:'RELEASE_MISMATCH'});
});
