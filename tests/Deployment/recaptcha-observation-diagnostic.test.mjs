import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validate} from '../../ops/audit/validate-recaptcha-observation.mjs';

const workflow = readFileSync(new URL('../../.github/workflows/diagnose-recaptcha-observation.yml', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../ops/audit/recaptcha-observation-diagnostic.php', import.meta.url), 'utf8');

test('workflow is manual, main-only, pinned and read-only', () => {
  assert.match(workflow, /on:\n  workflow_dispatch:\n\npermissions:\n  contents: read/);
  assert.match(workflow, /github.ref == 'refs\/heads\/main' && github.repository == 'eltx1\/hm01'/);
  assert.match(workflow, /StrictHostKeyChecking=yes/);
  assert.doesNotMatch(workflow, /StrictHostKeyChecking=no|ssh-keyscan|artisan|migrate|cache:clear/);
  assert.match(workflow, /node ops\/audit\/validate-recaptcha-observation.mjs/);
});

test('diagnostic never writes, never calls Google and never prints keys or hosts', () => {
  assert.doesNotMatch(script, /->(update|create|save|delete|insert|forceFill|increment|decrement)\(|DB::(statement|update|insert|delete)|google\.com|recaptcha_observation_secret/);
  assert.match(script, /RELEASE_MISMATCH/);
  assert.match(script, /withoutRedirecting\(\)/);
  assert.doesNotMatch(script, /'(domain|host|site_key|siteKey|site_id)' =>/);
});

const site = {site_active: true, config_active: true, cloudflare_gate_enabled: false, cloudflare_gate_readiness: 'READY', saved_key_valid: true,
  published_enabled: true, published_key_valid: true, published_key_matches_saved: true, published_delivery: 'SUCCEEDED',
  page: {checked: true, http: 'OK', has_horus_loader: true, has_publisher_recaptcha: false}};

test('validator only passes closed codes and booleans', () => {
  const ok = {schema_version: 1, diagnostic: 'RECAPTCHA_OBSERVATION', reason: 'OK', enabled_sites: 1, sites: [site]};
  assert.deepEqual(validate(JSON.stringify(ok)), ok);
  assert.equal(validate(JSON.stringify({...ok, sites: [{...site, domain: 'example.com'}]})), null);
  assert.equal(validate(JSON.stringify({...ok, sites: [{...site, published_delivery: 'example.com'}]})), null);
  assert.equal(validate(JSON.stringify({...ok, sites: [{...site, page: {...site.page, html: '<script>'}}]})), null);
  assert.equal(validate(JSON.stringify({...ok, sites: [{...site, cloudflare_gate_readiness: '6LcSecretLookingKey_1234567890'}]})), null);
  assert.equal(validate(JSON.stringify({...ok, site_key: '6Lc'})), null);
  assert.equal(validate(JSON.stringify(ok) + '\nextra'), null);
  assert.equal(validate('not json'), null);
});

test('mismatched release is refused before booting the application', {skip: spawnSync('php', ['-v']).status !== 0}, () => {
  const run = spawnSync('php', [new URL('../../ops/audit/recaptcha-observation-diagnostic.php', import.meta.url).pathname],
    {cwd: '/tmp', env: {...process.env, HORUS_EXPECTED_RELEASE: 'a'.repeat(40)}, encoding: 'utf8'});
  assert.equal(validate(run.stdout)?.reason, 'RELEASE_MISMATCH');
});
