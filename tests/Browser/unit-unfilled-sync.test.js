import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {validate} from '../../ops/audit/validate-unit-unfilled-sync.mjs';
const read = path => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
test('unit Unfilled deployment evidence is closed and counter-only', () => {
    const input = {schema_version:1,scope:'AD_UNIT_ALL_SITES_V1',metric:'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS',bindings:3,statuses:['CURRENT','CURRENT','CURRENT'],observed_days:30,projected_days:30,projection_checks:['PASS','PASS','PASS'],financial_writes:false};
    assert.deepEqual(validate({...input, raw:'PRIVATE'}), input);
    for (const patch of [{financial_writes:true},{metric:'TOTAL_UNMATCHED_AD_REQUESTS'},{statuses:['private.example']},{bindings:26},{observed_days:-1}]) assert.throws(()=>validate({...input,...patch}));
    const workflow=read('.github/workflows/deploy-production.yml');
    assert.ok(workflow.includes("commit.parents?.[0]?.sha === '7f2683155c79442792e9bdf5f7b995942930bc4f'"));
    assert.match(workflow, /reporting:sync-unit-unfilled --wait=180/);
    assert.match(workflow, /timeout 240/);
    assert.ok(workflow.indexOf('Restore and verify original SOAP') > workflow.indexOf('- name: Deploy atomically'));
    const sync=read('app/Services/Reporting/SiteGamUnfilledSynchronizer.php');
    assert.doesNotMatch(sync,/DailyReport::|HourlyReport::|RevenueCorrection|ReportImportService|runPerformanceReport/);
    assert.match(sync,/\['DATE', 'AD_UNIT_ID'\]/);
    assert.match(sync,/SiteGamUnfilledReport::withoutGlobalScopes\(\)->updateOrCreate/);
});
