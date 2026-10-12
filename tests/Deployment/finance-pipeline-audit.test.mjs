import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {validate} from '../../ops/audit/validate-finance-pipeline.mjs';
const fixture = () => ({schema_version:2, release:'a'.repeat(40), periods:[{period:'2026-09', currency:'USD', status:'OPEN', ready:false, counts:{nonFinalRows:2}, blockers:['NON_FINAL_REPORTING'], subject_reasons:{}, statement_count:0, demand_evidence:[], fact_groups:[{connection_type:'SITE_GAM_AD_UNIT', finality:'ESTIMATED', settlement_eligible:0, rows_count:2, publisher_rows:2, first_date:'2026-09-01', last_date:'2026-09-02'}]}]});
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
    assert.ok(source.includes('hash_equals($expected, $release)'));
    assert.ok(source.includes('$db->rollBack()'));
    assert.doesNotMatch(source,/->(?:update|delete|insert|create|close)\(/);
    assert.doesNotMatch(source,/gross_revenue_minor|publisher_earnings_minor|subject_name|account_reference/);
});

test('source evidence cannot expose tags, IDs or arbitrary provider strings', () => {
    const evidence = {provider:'CUSTOM_THIRD_PARTY_TAG',mode:'MANUAL_TAG',quick_managed:true,created_month:'2026-09',historical_account_attribution:'UNVERIFIED',current_other_kinds:{},site_provider_other_kinds:{},mappings_before_end:1,mappings_after_end:0,widgets_before_end:1,widgets_changed_after_end:0,current_google_paths:1,current_exact_report_matches:0,current_other_or_unparseable:0,site_provider_snapshots:1,site_provider_candidates:1,site_provider_google_paths:1,site_provider_exact_report_matches:0};
    const report=fixture(); report.periods[0].demand_evidence=[evidence]; validate(report);
    for (const key of ['tag','account_id','ad_unit_path','name']) {
        assert.throws(()=>validate({...report,periods:[{...report.periods[0],demand_evidence:[{...evidence,[key]:'PRIVATE'}]}]}));
    }
    evidence.provider='PRIVATE ACCOUNT'; assert.throws(()=>validate(report));
});

// Production can lag several main commits after an unsuccessful release. The
// diagnostic must follow immutable live proof, never an arbitrary older SHA.
import {validateVerificationRun, verifiedRelease} from '../../ops/audit/finance-release-proof.mjs';
const verification = () => ({id:123,run_attempt:2,name:'Verify production live',path:'.github/workflows/verify-production-live.yml',status:'completed',conclusion:'success',head_branch:'main',head_repository:{full_name:'eltx1/hm01',id:1315038901},event:'workflow_run'});
const proof = () => `schema_version=1\nrelease_sha=${'b'.repeat(40)}\ndeploy_run_id=456\nmanifest_hash=${'c'.repeat(64)}\npages_deployment_url=https://example.pages.dev\nlordai_config_path=config.json\nlordai_config_sha256=${'d'.repeat(64)}\nlordai_loader_sha256=${'e'.repeat(64)}\nverify_run_id=123\nverify_run_attempt=2\n`;
test('accepts only immutable proof from a trusted successful production verification', () => {
    assert.deepEqual(validateVerificationRun(verification()), {id:123,attempt:2});
    assert.equal(verifiedRelease(proof(),123,2),'b'.repeat(40));
    for (const patch of [{conclusion:'failure'},{status:'in_progress'},{head_branch:'feature'},{event:'pull_request'},{path:'.github/workflows/tests.yml'},{head_repository:{full_name:'attacker/hm01',id:1315038901}}]) {
        assert.throws(()=>validateVerificationRun({...verification(),...patch}));
    }
    assert.throws(()=>verifiedRelease(proof(),124,2));
    assert.throws(()=>verifiedRelease(proof(),123,3));
    assert.throws(()=>verifiedRelease(proof()+'release_sha='+'a'.repeat(40),123,2));
    assert.throws(()=>verifiedRelease(proof().replace('b'.repeat(40),'unknown'),123,2));
});
