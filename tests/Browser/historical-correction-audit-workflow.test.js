import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, chmodSync, rmSync, readdirSync, symlinkSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('../..', import.meta.url));
const workflow = readFileSync(path.join(root, '.github/workflows/deploy-production.yml'), 'utf8');
const wrapper = path.join(root, 'ops/audit/historical-correction-audit.sh');
const validator = path.join(root, 'ops/audit/validate-historical-correction-audit.mjs');
const base = '708ab326a5241b3ee098dd622a5f8e2e1266d978';
const artifact = '16a8869b50351e095c2b9943a50ec58c0cb7cc598149226bd9585f50bf6aa222';
const sha = 'a'.repeat(40);
const privateText = 'PRIVATE-PUBLISHER.example person@example.test 2030-01-02 9876.54 RAW-SQL-ERROR';
const countKeys = ['initial_daily_facts', 'sources', 'applied_windows', 'unique_receipts', 'corrected_facts',
    'blocked_facts', 'pending_facts', 'covered_facts', 'remaining_facts', 'remaining_money_zero',
    'remaining_money_nonzero', 'remaining_money_unknown', 'remaining_money_known_nonzero', 'remaining_counters_zero', 'remaining_counters_nonzero',
    'remaining_counters_unknown', 'missing_facts', 'duplicate_facts', 'missing_receipts', 'duplicate_receipts',
    'currency_issues', 'hash_issues', 'provenance_issues', 'other_record_drift'];
const checkKeys = ['manifest_identity', 'manifest_digest', 'inventory_matches', 'coverage_complete',
    'receipts_match', 'corrected_hashes_match', 'remaining_hashes_match', 'remaining_money_valid',
    'currency_valid', 'provenance_valid', 'admin_reporting_parity', 'publisher_reporting_parity'];
const safeResult = () => ({ schema_version: 1, status: 'OK', reason: 'NONE',
    counts: { ...Object.fromEntries(countKeys.map(key => [key, 0])), initial_daily_facts: 96, sources: 2,
        applied_windows: 6, unique_receipts: 6, corrected_facts: 35, blocked_facts: 61, covered_facts: 96,
        remaining_facts: 61, remaining_money_zero: 40, remaining_money_nonzero: 21, remaining_money_known_nonzero: 21,
        remaining_counters_nonzero: 61 },
    checks: Object.fromEntries(checkKeys.map(key => [key, true])) });

test('streamed PHP reader and runner output boundary agree on the closed public protocol', () => {
    const reader = readFileSync(path.join(root, 'ops/audit/verify-historical-correction.php'), 'utf8');
    const keys = name => [...reader.match(new RegExp(`public const ${name} = \\[([^;]+)\\];`))[1]
        .matchAll(/'([a-z_]+)'/g)].map(match => match[1]);
    assert.deepEqual(keys('COUNT_KEYS'), countKeys);
    assert.deepEqual(keys('CHECK_KEYS'), checkKeys);
    assert.match(reader, /exit\(\$result\['status'\] === 'OK' \? 0 : 1\)/);
});

test('audit preserves the protected release path and runs before all production changes', () => {
    assert.match(workflow, /environment: production/);
    assert.match(workflow, /group: horus-production-deploy/);
    assert.match(workflow, /permissions:\n  contents: read\n  actions: read/);
    assert.ok(workflow.indexOf('Require successful main production validation') < workflow.indexOf('Checkout trusted deployment tooling'));
    assert.ok(workflow.indexOf('Verify validated artifact checksum') < workflow.indexOf('Require bounded historical receipt audit release'));
    assert.ok(workflow.indexOf('Require bounded historical receipt audit release') < workflow.indexOf('Configure pinned SSH trust'));
    const audit = workflow.indexOf('Audit historical correction receipts before production changes');
    assert.ok(audit < workflow.indexOf('Verify exact Site GAM reporting compatibility'));
    assert.ok(audit < workflow.indexOf('Transfer validated release'));
    assert.ok(audit < workflow.indexOf('Deploy atomically'));
    assert.match(workflow, /if: steps\.historical_audit\.outputs\.active == 'true'/);
    assert.match(workflow, /fetch-depth: 2\n          persist-credentials: false/);
    assert.match(workflow, /run: bash ops\/audit\/historical-correction-audit\.sh/);
    const source = readFileSync(wrapper, 'utf8');
    assert.doesNotMatch(source, /artisan|reporting:gam-historical|execute-once|historical-gam-correction-once|scp|curl/);
    assert.match(source, /exec 9<"\$lock"/);
    assert.match(source, /flock -n 9/);
    assert.match(source, /<"\$reader"/);
    const hash = file => createHash('sha256').update(readFileSync(path.join(root, file))).digest('hex');
    assert.equal(hash('ops/audit/historical-gam-correction-once.json'), '9e8a62704cc8b322f1ef0ecc8b6baacb2220ea41740f83a685c5c11f58808f26');
    assert.equal(hash('.github/workflows/private-historical-correction.yml'), 'd9ed2434af6285ae585b84732e36e0fe01f6901fa76d3e6e06b8bfc7787d2c22');
});

const trustScript = workflow.split('      - name: Require bounded historical receipt audit release')[1]
    .split('          script: |\n')[1].split('\n      - name: Configure pinned SSH trust')[0]
    .split('\n').map(line => line.startsWith('            ') ? line.slice(12) : line).join('\n');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const trust = new AsyncFunction('github', 'context', 'core', 'process', trustScript);
async function exerciseTrust(changes = {}) {
    const outputs = {}, failures = [], requests = [];
    const fixture = { sha, ref: 'refs/heads/main', repo: { owner: 'eltx1', repo: 'hm01' },
        repository: { id: 1315038901, full_name: 'eltx1/hm01' }, commit: { sha, parents: [{ sha: base }] }, main: sha, ...changes };
    const github = { rest: { repos: {
        getCommit: async request => { requests.push(request); if (fixture.error) throw new Error(privateText); return { data: fixture.commit }; },
        getBranch: async request => { requests.push(request); return { data: { commit: { sha: fixture.main } } }; },
    } } };
    await trust(github, { ref: fixture.ref, repo: fixture.repo, payload: { repository: fixture.repository } },
        { setOutput: (key, value) => { outputs[key] = value; }, setFailed: message => { failures.push(message); } },
        { env: { RELEASE_SHA: fixture.sha } });
    return { outputs, failures, requests };
}

test('audit admits only the reviewed base immediate successor on exact current main', async () => {
    const result = await exerciseTrust();
    assert.deepEqual(result.outputs, { release_sha: sha, active: 'true' });
    assert.deepEqual(result.failures, []);
    assert.deepEqual(result.requests, [{ owner: 'eltx1', repo: 'hm01', ref: sha }, { owner: 'eltx1', repo: 'hm01', branch: 'main' }]);
    assert.equal((await exerciseTrust({ commit: { sha, parents: [{ sha: base }, { sha: 'b'.repeat(40) }] } })).outputs.active, 'true');
});

for (const [label, changes] of [
    ['future release', { commit: { sha, parents: [{ sha: 'b'.repeat(40) }] } }],
    ['base only as second parent', { commit: { sha, parents: [{ sha: 'b'.repeat(40) }, { sha: base }] } }],
    ['already deployed correction base itself', { sha: base, commit: { sha: base, parents: [{ sha: 'b'.repeat(40) }] } }],
]) test(`audit skips ${label} without enabling any financial action`, async () => {
    const result = await exerciseTrust(changes);
    assert.deepEqual(result.outputs, { active: 'false' });
    assert.deepEqual(result.failures, []);
});

for (const [label, changes] of [
    ['non-main dispatch', { ref: 'refs/heads/feature' }],
    ['main advanced', { main: 'b'.repeat(40) }],
    ['mismatched commit response', { commit: { sha: 'b'.repeat(40), parents: [{ sha: base }] } }],
    ['missing parents', { commit: { sha } }],
    ['empty parents', { commit: { sha, parents: [] } }],
    ['octopus merge', { commit: { sha, parents: [{ sha: base }, {}, {}] } }],
    ['SHA injection', { sha: privateText }],
    ['API error', { error: true }],
    ['fork destination', { repo: { owner: 'fork', repo: 'hm01' } }],
    ['renamed repository', { repository: { id: 1315038901, full_name: 'eltx1/other' } }],
    ['replacement repository', { repository: { id: 999, full_name: 'eltx1/hm01' } }],
]) test(`audit trust rejects ${label} with a fixed public reason`, async () => {
    const result = await exerciseTrust(changes);
    assert.deepEqual(result.outputs, {});
    assert.deepEqual(result.failures, ['HISTORICAL_AUDIT_TRUST_INVALID']);
});

function shellFixture() {
    const directory = mkdtempSync(path.join(tmpdir(), 'hmaudit-'));
    for (const child of ['home/.ssh', 'runner', 'bin', 'remote-bin', 'server/htdocs', `server/releases/${base}`, 'ops/audit']) mkdirSync(path.join(directory, child), { recursive: true });
    const release = path.join(directory, 'server/releases', base);
    writeFileSync(path.join(release, '.horus-release'), `release_id=${base}\nartifact_sha256=${artifact}\ndeployed_at=2026-10-03T00:00:00Z\n`);
    writeFileSync(path.join(directory, 'server/.horus-deploy.lock'), 'existing-lock-unchanged');
    symlinkSync(release, path.join(directory, 'server/htdocs/app.horusmedia.net'));
    writeFileSync(path.join(directory, 'home/.ssh/id_ed25519'), 'existing-key');
    writeFileSync(path.join(directory, 'home/.ssh/known_hosts'), 'existing-host');
    writeFileSync(path.join(directory, 'ops/audit/historical-correction-audit.sh'), readFileSync(wrapper));
    writeFileSync(path.join(directory, 'ops/audit/validate-historical-correction-audit.mjs'), readFileSync(validator));
    writeFileSync(path.join(directory, 'ops/audit/verify-historical-correction.php'), '<?php /* incoming standalone source */');
    writeFileSync(path.join(directory, 'expected.json'), JSON.stringify(safeResult()) + '\n');
    const executable = (name, body) => { const filename = path.join(directory, name); writeFileSync(filename, body); chmodSync(filename, 0o700); };
    executable('bin/git', `#!/usr/bin/env bash
if [[ "$*" == 'rev-parse HEAD' ]]; then printf '%s\\n' "\${CHECKOUT_SHA:-${sha}}"; else printf '%s\\n' "\${CHECKOUT_BASE:-${base}}"; fi
`);
    executable('bin/ssh', `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$@" > "$FIXTURE/ssh-args"
if [[ "\${SSH_FIXTURE_MODE:-}" == transport-failure ]]; then printf '%s\\n' "$PRIVATE_SENTINEL"; printf '%s\\n' "$PRIVATE_SENTINEL" >&2; exit 255; fi
if [[ "\${SSH_FIXTURE_MODE:-}" == startup-noise ]]; then printf '%s\\n' "$PRIVATE_SENTINEL"; fi
PATH="$FIXTURE/remote-bin:$PATH" bash -c "\${!#}"
`);
    executable('remote-bin/php', `#!/usr/bin/env bash
set -eu
pwd > "$FIXTURE/php-cwd"
cat > "$FIXTURE/php-stdin"
if flock -n "$FIXTURE/server/.horus-deploy.lock" true; then exit 98; fi
printf 'held' > "$FIXTURE/lock-held"
printf '%s\\n' "$PRIVATE_SENTINEL" >&2
if [[ "\${PHP_FIXTURE_MODE:-}" == bootstrap-failure ]]; then printf '%s\\n' "$PRIVATE_SENTINEL"; exit 1; fi
cat "$FIXTURE/expected.json"
if [[ "\${PHP_FIXTURE_MODE:-}" == handled-failure ]]; then exit 1; fi
`);
    const env = { ...process.env, FIXTURE: directory, PRIVATE_SENTINEL: privateText,
        PATH: `${directory}/bin:${process.env.PATH}`, HOME: path.join(directory, 'home'),
        RUNNER_TEMP: path.join(directory, 'runner'), REMOTE_HOME: path.join(directory, 'server'),
        HOST: 'production.example', SSH_USER: 'horusapp', SSH_PORT: '22', RELEASE_SHA: sha,
        HISTORICAL_AUDIT_TRUSTED_SHA: sha, GITHUB_REF: 'refs/heads/main', GITHUB_EVENT_NAME: 'workflow_run',
        GITHUB_REPOSITORY: 'eltx1/hm01', GITHUB_REPOSITORY_ID: '1315038901' };
    return { directory, release, env,
        run: (changes = {}) => spawnSync('bash', [path.join(directory, 'ops/audit/historical-correction-audit.sh')], { env: { ...env, ...changes }, encoding: 'utf8' }),
        cleanup: () => rmSync(directory, { recursive: true, force: true }) };
}

function publicResult(result, reason, status = 1) {
    assert.equal(result.status, status, result.stderr);
    assert.equal(result.stderr, '');
    assert.equal(result.stdout.includes(privateText), false);
    assert.equal(result.stdout.trim().split('\n').length, 1);
    const parsed = JSON.parse(result.stdout);
    assert.equal(parsed.reason, reason);
    return parsed;
}

test('transport streams incoming source to exact deployed app under unchanged deploy lock and pinned SSH', () => {
    const fixture = shellFixture();
    try {
        assert.deepEqual(publicResult(fixture.run(), 'NONE', 0), safeResult());
        const args = readFileSync(path.join(fixture.directory, 'ssh-args'), 'utf8');
        for (const option of ['StrictHostKeyChecking=yes', 'BatchMode=yes', 'IdentitiesOnly=yes', 'UserKnownHostsFile=']) assert.ok(args.includes(option));
        assert.equal(readFileSync(path.join(fixture.directory, 'php-cwd'), 'utf8').trim(), fixture.release);
        assert.equal(readFileSync(path.join(fixture.directory, 'php-stdin'), 'utf8'), '<?php /* incoming standalone source */');
        assert.equal(readFileSync(path.join(fixture.directory, 'lock-held'), 'utf8'), 'held');
        assert.equal(readFileSync(path.join(fixture.directory, 'server/.horus-deploy.lock'), 'utf8'), 'existing-lock-unchanged');
        assert.deepEqual(readdirSync(path.join(fixture.directory, 'runner')), []);
        assert.deepEqual(readdirSync(fixture.release), ['.horus-release']);
    } finally { fixture.cleanup(); }
});

for (const [label, changes, reason = 'CONFIGURATION_INVALID'] of [
    ['untrusted SHA', { HISTORICAL_AUDIT_TRUSTED_SHA: 'b'.repeat(40) }],
    ['non-main ref', { GITHUB_REF: 'refs/heads/feature' }],
    ['pull-request event', { GITHUB_EVENT_NAME: 'pull_request' }],
    ['fork repository', { GITHUB_REPOSITORY: 'fork/hm01' }],
    ['replacement repository', { GITHUB_REPOSITORY_ID: '999' }],
    ['checkout SHA mismatch', { CHECKOUT_SHA: 'b'.repeat(40) }, 'TRUST_PROOF_INVALID'],
    ['checkout first-parent mismatch', { CHECKOUT_BASE: 'b'.repeat(40) }, 'TRUST_PROOF_INVALID'],
    ['host option injection', { HOST: '-oProxyCommand=env' }],
    ['user injection', { SSH_USER: 'root@host' }],
    ['remote path injection', { REMOTE_HOME: "/home/x'; env; #" }],
    ['remote path traversal', { REMOTE_HOME: '/home/../root' }],
    ['invalid port', { SSH_PORT: '65536' }],
]) test(`transport rejects ${label} before SSH`, () => {
    const fixture = shellFixture();
    try { publicResult(fixture.run(changes), reason); assert.equal(existsSync(path.join(fixture.directory, 'ssh-args')), false); }
    finally { fixture.cleanup(); }
});

for (const [label, mutate, reason = 'RELEASE_MISMATCH'] of [
    ['wrong release marker', f => writeFileSync(path.join(f.release, '.horus-release'), `release_id=${sha}\nartifact_sha256=${artifact}\n`)],
    ['wrong artifact marker', f => writeFileSync(path.join(f.release, '.horus-release'), `release_id=${base}\nartifact_sha256=${'b'.repeat(64)}\n`)],
    ['duplicate release marker', f => writeFileSync(path.join(f.release, '.horus-release'), `release_id=${base}\nrelease_id=${base}\nartifact_sha256=${artifact}\n`)],
    ['missing checksum', f => writeFileSync(path.join(f.release, '.horus-release'), `release_id=${base}\n`)],
    ['marker symlink', f => { rmSync(path.join(f.release, '.horus-release')); symlinkSync(path.join(f.directory, 'expected.json'), path.join(f.release, '.horus-release')); }],
    ['current release changed', f => { rmSync(path.join(f.directory, 'server/htdocs/app.horusmedia.net')); symlinkSync(f.directory, path.join(f.directory, 'server/htdocs/app.horusmedia.net')); }],
    ['deploy lock missing', f => rmSync(path.join(f.directory, 'server/.horus-deploy.lock')), 'DEPLOY_LOCK_UNAVAILABLE'],
    ['deploy lock symlink', f => { rmSync(path.join(f.directory, 'server/.horus-deploy.lock')); symlinkSync(path.join(f.directory, 'expected.json'), path.join(f.directory, 'server/.horus-deploy.lock')); }, 'DEPLOY_LOCK_UNAVAILABLE'],
]) test(`transport rejects ${label} before PHP`, () => {
    const fixture = shellFixture();
    try { mutate(fixture); publicResult(fixture.run(), reason); assert.equal(existsSync(path.join(fixture.directory, 'php-cwd')), false); }
    finally { fixture.cleanup(); }
});

test('transport fails safely while another process holds the existing deployment lock', () => {
    const fixture = shellFixture();
    try {
        const result = spawnSync('flock', ['-n', path.join(fixture.directory, 'server/.horus-deploy.lock'),
            'bash', path.join(fixture.directory, 'ops/audit/historical-correction-audit.sh')], { env: fixture.env, encoding: 'utf8' });
        publicResult(result, 'DEPLOY_LOCK_BUSY');
        assert.equal(existsSync(path.join(fixture.directory, 'php-cwd')), false);
    } finally { fixture.cleanup(); }
});

for (const [label, changes, reason] of [
    ['SSH raw errors', { SSH_FIXTURE_MODE: 'transport-failure' }, 'TRANSPORT_FAILED'],
    ['SSH startup noise', { SSH_FIXTURE_MODE: 'startup-noise' }, 'RESULT_INVALID'],
    ['PHP bootstrap raw errors', { PHP_FIXTURE_MODE: 'bootstrap-failure' }, 'RESULT_INVALID'],
    ['nonzero PHP paired with success output', { PHP_FIXTURE_MODE: 'handled-failure' }, 'RESULT_INVALID'],
]) test(`transport suppresses ${label}`, () => {
    const fixture = shellFixture();
    try { publicResult(fixture.run(changes), reason); assert.deepEqual(readdirSync(path.join(fixture.directory, 'runner')), []); }
    finally { fixture.cleanup(); }
});

function validate(raw, transport = '0') {
    const directory = mkdtempSync(path.join(tmpdir(), 'hmaudit-result-'));
    try {
        const file = path.join(directory, 'result.json');
        writeFileSync(file, typeof raw === 'string' ? raw : JSON.stringify(raw));
        return spawnSync(process.execPath, [validator, file, transport], { encoding: 'utf8' });
    } finally { rmSync(directory, { recursive: true, force: true }); }
}

test('validator permits sound structural evidence with nonzero stored unresolved money and informational drift', () => {
    const result = safeResult();
    result.counts.other_record_drift = 1;
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('validator preserves failed evidence as nonzero without requiring all remaining money to be zero', () => {
    const result = safeResult(); result.status = 'FAILED'; result.reason = 'AUDIT_MISMATCH'; result.checks.receipts_match = false;
    assert.deepEqual(publicResult(validate(result, '10'), 'AUDIT_MISMATCH'), result);
});

test('validator preserves overlapping unknown and known nonzero money or counters', () => {
    const result = safeResult();
    result.counts.remaining_money_zero = 0;
    result.counts.remaining_money_nonzero = 0;
    result.counts.remaining_money_unknown = 61;
    result.counts.remaining_money_known_nonzero = 61;
    result.counts.remaining_counters_zero = 0;
    result.counts.remaining_counters_nonzero = 61;
    result.counts.remaining_counters_unknown = 61;
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('transport preserves genuine failed audit counts and checks with exactly one public failure', () => {
    const fixture = shellFixture();
    try {
        const result = safeResult(); result.status = 'FAILED'; result.reason = 'AUDIT_MISMATCH'; result.checks.receipts_match = false;
        result.counts.missing_receipts = 1;
        writeFileSync(path.join(fixture.directory, 'expected.json'), JSON.stringify(result) + '\n');
        assert.deepEqual(publicResult(fixture.run({ PHP_FIXTURE_MODE: 'handled-failure' }), 'AUDIT_MISMATCH'), result);
        assert.deepEqual(readdirSync(path.join(fixture.directory, 'runner')), []);
    } finally { fixture.cleanup(); }
});

for (const [label, change, transport = '0'] of [
    ['extra raw field', r => ({ ...r, private: privateText })],
    ['extra raw count key', r => ({ ...r, counts: { ...r.counts, [privateText]: 1 } })],
    ['extra raw check key', r => ({ ...r, checks: { ...r.checks, [privateText]: true } })],
    ['raw reason', r => ({ ...r, reason: privateText })],
    ['string count', r => ({ ...r, counts: { ...r.counts, blocked_facts: privateText } })],
    ['negative count', r => ({ ...r, counts: { ...r.counts, blocked_facts: -1 } })],
    ['fraction count', r => ({ ...r, counts: { ...r.counts, blocked_facts: 1.5 } })],
    ['unsafe count', r => ({ ...r, counts: { ...r.counts, blocked_facts: Number.MAX_SAFE_INTEGER + 1 } })],
    ['missing count', r => { delete r.counts.sources; return r; }],
    ['array counts', r => ({ ...r, counts: [] })],
    ['string boolean', r => ({ ...r, checks: { ...r.checks, coverage_complete: 'true' } })],
    ['false OK check', r => ({ ...r, checks: { ...r.checks, coverage_complete: false } })],
    ['failed with NONE', r => ({ ...r, status: 'FAILED' })],
    ['OK with error reason', r => ({ ...r, reason: 'AUDIT_FAILED' })],
    ['duplicate key', r => JSON.stringify(r).replace('"schema_version":1', '"schema_version":1,"schema_version":1')],
    ['multiple documents', r => JSON.stringify(r) + '\n' + JSON.stringify(r)],
    ['raw prefix', r => privateText + JSON.stringify(r)],
    ['oversized input', () => ' '.repeat(16385)],
    ['OK after nonzero PHP', r => r, '10'],
    ['FAILED after zero PHP', r => ({ ...r, status: 'FAILED', reason: 'AUDIT_MISMATCH' })],
    ['unexpected SSH exit', r => r, '255'],
    ['transport failure with raw reason', () => ({ schema_version: 1, status: 'FAILED', reason: privateText }), '10'],
]) test(`validator rejects ${label} silently`, () => {
    const execution = validate(change(safeResult()), transport);
    assert.equal(execution.status, 2);
    assert.equal(execution.stdout, '');
    assert.equal(execution.stderr, '');
});
