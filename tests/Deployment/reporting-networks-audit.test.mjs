import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {validate} from '../../ops/audit/validate-reporting-networks.mjs';

const source = readFileSync('ops/audit/reporting-network-health.php', 'utf8');
const workflow = readFileSync('.github/workflows/diagnose-reporting-networks.yml', 'utf8');

test('classifies failures without disclosing the provider message', () => {
    const samples = {
        '': 'NONE',
        'invalid_grant credential secret@example.test': 'AUTHORIZATION',
        'The Google GAM report did not prove that converted revenue is in the canonical currency.': 'CURRENCY_PROOF',
        'The Google network identity or timezone changed.': 'NETWORK_IDENTITY',
        'The report dates or connection do not match this website reporting binding.': 'REPORT_WINDOW',
        'ReportError.COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS secret': 'REPORT_DIMENSIONS',
        'private unknown data 12345': 'OTHER',
    };
    const code = 'define("HORUS_REPORT_DIAGNOSTIC_TEST_ONLY", true); require "ops/audit/reporting-network-health.php"; '
        + '$samples=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); '
        + 'echo json_encode(array_map("horusReportErrorClass", array_keys($samples)));';
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', code], {input: JSON.stringify(samples), encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(result.stdout), Object.values(samples));
});

test('rejects extra fields, raw messages, identifiers and unbounded counts', () => {
    const good = () => ({schema_version: 1, release: 'a'.repeat(40), groups: {'MAIN|GBP|ACTIVE|CURRENT|CURRENCY_PROOF|OLDER': 1}});
    assert.deepEqual(validate(good()), good());
    for (const bad of [
        {...good(), raw_error: 'secret'},
        {...good(), groups: {'MAIN|12345|ACTIVE|CURRENT|NONE|TODAY': 1}},
        {...good(), groups: {'MAIN|GBP|ACTIVE|CURRENT|private error|TODAY': 1}},
        {...good(), groups: {'MAIN|GBP|ACTIVE|CURRENT|NONE|TODAY': 2001}},
        {...good(), groups: []},
    ]) assert.throws(() => validate(bad));
});

test('probe refuses an unverified release before application bootstrap', () => {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['ops/audit/reporting-network-health.php'], {
        encoding: 'utf8', env: {...process.env, HM_EXPECTED_SHA: ''},
    });
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr, 'Read-only reporting diagnosis unavailable.\n');
});

test('production diagnosis retains read-only bootstrap and verified-release guards', () => {
    assert.ok(source.indexOf('SET TRANSACTION READ ONLY') < source.indexOf("$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap()"));
    assert.ok(source.includes('$db->rollBack()'));
    assert.ok(source.includes('Cached configuration required'));
    assert.ok(source.includes("config(['cache.default' => 'array'])"));
    assert.ok(workflow.includes('validateVerificationRun'));
    assert.ok(workflow.includes('verifiedRelease'));
    assert.ok(workflow.includes('StrictHostKeyChecking=yes'));
    assert.ok(workflow.includes('environment: production'));
    assert.ok(workflow.includes("github.event_name == 'push'"));
    assert.ok(workflow.includes('node ops/audit/validate-reporting-networks.mjs'));
    assert.ok(!workflow.includes('cat "$work/raw.json"'));
});
