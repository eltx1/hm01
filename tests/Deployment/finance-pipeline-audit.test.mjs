import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {validate} from '../../ops/audit/validate-finance-pipeline.mjs';
const fixture = () => ({schema_version:1, release:'a'.repeat(40), periods:[{period:'2026-09', currency:'USD', status:'OPEN', ready:false, counts:{nonFinalRows:2}, blockers:['NON_FINAL_REPORTING'], subject_reasons:{}, statement_count:0, fact_groups:[{connection_type:'SITE_GAM_AD_UNIT', finality:'ESTIMATED', settlement_eligible:0, rows_count:2, publisher_rows:2, first_date:'2026-09-01', last_date:'2026-09-02'}]}]});
test('accepts bounded aggregate finance evidence', () => assert.deepEqual(validate(fixture()), fixture()));
test('rejects private identities, amounts, names and unexpected diagnostic output', () => {
    for (const key of ['publisher_id','subject_name','publisher_earnings_minor','payment_profile','error']) {
        const report=fixture(); report.periods[0][key]='PRIVATE'; assert.throws(()=>validate(report));
    }
    const report=fixture(); report.periods[0].fact_groups[0].connection_type='PRIVATE ACCOUNT NAME'; assert.throws(()=>validate(report));
});
test('diagnostic retains read-only transaction and exact release boundary', () => {
    const source=readFileSync(new URL('../../ops/audit/finance-pipeline.php',import.meta.url),'utf8');
    assert.ok(source.includes('SET TRANSACTION READ ONLY'));
    assert.ok(source.includes('[$expected, $previous]'));
    assert.ok(source.includes('$db->rollBack()'));
    assert.doesNotMatch(source,/->(?:update|delete|insert|create|close)\(/);
    assert.doesNotMatch(source,/gross_revenue_minor|publisher_earnings_minor|subject_name|account_reference/);
});
