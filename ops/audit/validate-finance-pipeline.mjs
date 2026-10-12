import {readFileSync, writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
export function validate(report) {
    const keys = (value, expected) => assert.deepEqual(Object.keys(value).sort(), expected.sort());
    const integer = value => assert.ok(Number.isSafeInteger(Number(value)) && Number(value) >= 0 && Number(value) <= 1e9);
    keys(report, ['schema_version', 'release', 'periods']);
    assert.equal(report.schema_version, 2);
    assert.match(report.release, /^[a-f0-9]{40}$/);
    assert.ok(Array.isArray(report.periods) && report.periods.length <= 24);
    const blockers = ['PERIOD_NOT_ENDED','MISSING_FINALIZED_DATA','NON_FINAL_REPORTING','SETTLEMENT_INELIGIBLE_FINALIZED_ROWS','MONETIZATION_SOURCE_COVERAGE','FAILED_IMPORTS','IMPORTS_IN_PROGRESS','RECONCILIATION_ISSUES','PENDING_ADJUSTMENTS','CURRENCY_MISMATCH'];
    const countKeys = ['finalizedRows','ineligibleFinalizedRows','nonFinalRows','failedImports','activeImports','reconciliationIssues','pendingAdjustments','currencyMismatchRows','currencyMismatchAdjustments','monetizationCoverageBlockers'];
    const types = ['GAM_CONNECTION','SITE_GAM_AD_UNIT','SITE_GAM_VIDEO_AD_UNIT','MOBILE_APP_GAM','DEMAND_ACCOUNT','BIDDER_ACCOUNT'];
    for (const period of report.periods) {
        keys(period, ['period','currency','status','ready','counts','blockers','subject_reasons','statement_count','fact_groups','demand_evidence']);
        assert.match(period.period, /^20\d{2}-(0[1-9]|1[0-2])$/);
        assert.match(period.currency, /^[A-Z]{3}$/);
        assert.ok(['OPEN','CLOSING','CLOSED'].includes(period.status));
        assert.equal(typeof period.ready, 'boolean');
        for (const [key, value] of Object.entries(period.counts)) { assert.ok(countKeys.includes(key)); integer(value); }
        assert.ok(Array.isArray(period.blockers));
        period.blockers.forEach(code => assert.ok(blockers.includes(code)));
        for (const [key, count] of Object.entries(period.subject_reasons)) { assert.match(key, /^[A-Z_]{1,40}:[A-Z_]{1,100}$/); integer(count); }
        integer(period.statement_count);
        assert.ok(Array.isArray(period.demand_evidence) && period.demand_evidence.length <= 100);
        const evidenceCounts = ['mappings_before_end','mappings_after_end','widgets_before_end','widgets_changed_after_end','current_google_paths','current_exact_report_matches','current_other_or_unparseable','site_provider_snapshots','site_provider_candidates','site_provider_google_paths','site_provider_exact_report_matches'];
        for (const evidence of period.demand_evidence) {
            keys(evidence, ['provider','mode','quick_managed','created_month','historical_account_attribution','current_other_kinds','site_provider_other_kinds', ...evidenceCounts]);
            assert.ok(['MGID','TABOOLA','SPEAKOL','OUTBRAIN','EXOCLICK','CUSTOM_NATIVE','CUSTOM_DISPLAY','CUSTOM_THIRD_PARTY_TAG'].includes(evidence.provider));
            assert.ok(['DIRECT_JS','GAM_THIRD_PARTY_CREATIVE','GAM_LINE_ITEM','MANUAL_TAG','API_INTEGRATION'].includes(evidence.mode));
            assert.equal(typeof evidence.quick_managed, 'boolean');
            assert.equal(evidence.historical_account_attribution, 'UNVERIFIED');
            assert.match(evidence.created_month, /^20\d{2}-(0[1-9]|1[0-2])$/);
            evidenceCounts.forEach(key => integer(evidence[key]));
            for (const kinds of [evidence.current_other_kinds, evidence.site_provider_other_kinds]) {
                for (const [kind, count] of Object.entries(kinds)) {
                    assert.ok(['UNREADABLE','GOOGLE_VAST_EXACT_PATH','GOOGLE_VAST_OTHER_PATH','GOOGLE_OUT_OF_PAGE_EXACT_PATH','GOOGLE_OUT_OF_PAGE_OTHER_PATH','GOOGLE_MARKUP_UNVERIFIED','OTHER_OR_MIXED_MARKUP'].includes(kind));
                    integer(count);
                }
            }
        }
        assert.ok(Array.isArray(period.fact_groups) && period.fact_groups.length <= 100);
        for (const group of period.fact_groups) {
            keys(group, ['connection_type','finality','settlement_eligible','rows_count','publisher_rows','first_date','last_date']);
            assert.ok(types.includes(group.connection_type));
            assert.ok(['ESTIMATED','FINALIZED'].includes(group.finality));
            assert.ok([0,1,'0','1',false,true].includes(group.settlement_eligible));
            integer(group.rows_count); integer(group.publisher_rows);
            assert.match(group.first_date, /^20\d{2}-\d{2}-\d{2}$/);
            assert.match(group.last_date, /^20\d{2}-\d{2}-\d{2}$/);
        }
    }
    return report;
}
if (process.argv[1]?.endsWith('validate-finance-pipeline.mjs')) {
    try {
        const report = validate(JSON.parse(readFileSync(process.argv[2], 'utf8')));
        writeFileSync(process.argv[3], JSON.stringify(report, null, 2));
        console.log('Aggregate-only finance diagnosis validated.');
    } catch { console.error('Finance diagnosis failed disclosure validation; raw output withheld.'); process.exitCode = 1; }
}
