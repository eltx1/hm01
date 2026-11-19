import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validate} from '../../ops/audit/validate-ad-format-breakdown.mjs';

const workflow = readFileSync(new URL('../../.github/workflows/diagnose-ad-format-breakdown.yml', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../ops/audit/ad-format-breakdown-diagnostic.php', import.meta.url), 'utf8');

test('workflow is manual, main-only, pinned and read-only', () => {
  assert.match(workflow, /on:\n  workflow_dispatch:\n\npermissions:\n  contents: read/);
  assert.match(workflow, /github.ref == 'refs\/heads\/main' && github.repository == 'eltx1\/hm01'/);
  assert.match(workflow, /StrictHostKeyChecking=yes/);
  assert.doesNotMatch(workflow, /StrictHostKeyChecking=no|ssh-keyscan|artisan|migrate|cache:clear/);
  assert.match(workflow, /node ops\/audit\/validate-ad-format-breakdown.mjs/);
});

test('diagnostic script never writes or calls Google', () => {
  assert.doesNotMatch(script, /->(update|create|save|delete|insert|forceFill|increment|decrement)\(|DB::(statement|update|insert|delete)|->sync\(|GamAdUnitReportClient|Http::/);
  assert.match(script, /RELEASE_MISMATCH/);
});

test('validator only passes closed codes and counts', () => {
  const ok = {schema_version: 1, diagnostic: 'AD_FORMAT_BREAKDOWN', reason: 'OK', active_bindings: 3, states: {CURRENT: 2, BACKFILLING: 1},
    errors: {}, google_accepted_format_dimension: true, rows_last_14_days: 42, formats_by_website_count: {BANNER: 3, INTERSTITIAL: 2},
    completed_days_compared_with_ledger: 20, days_exact_match: 19, days_within_one_cent: 1, days_different: 0};
  assert.deepEqual(validate(JSON.stringify(ok)), ok);
  assert.equal(validate(JSON.stringify({...ok, hostname: 'example.com'})), null);
  assert.equal(validate(JSON.stringify({...ok, formats_by_website_count: {'example.com': 1}})), null);
  assert.equal(validate(JSON.stringify({...ok, days_exact_match: 1.5})), null);
  assert.equal(validate(JSON.stringify(ok) + '\nextra'), null);
  assert.equal(validate('not json'), null);
});

test('mismatched release is refused before booting the application', {skip: spawnSync('php', ['-v']).status !== 0}, () => {
  const run = spawnSync('php', [new URL('../../ops/audit/ad-format-breakdown-diagnostic.php', import.meta.url).pathname],
    {cwd: '/tmp', env: {...process.env, HORUS_EXPECTED_RELEASE: 'a'.repeat(40)}, encoding: 'utf8'});
  assert.equal(validate(run.stdout)?.reason, 'RELEASE_MISMATCH');
});
