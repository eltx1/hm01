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
const base = '86b8d6dcfdc5fb6ad6fc2b7733430743ff283acb';
const artifact = 'f96a1f40c54f86e5ee79e383dae338749a298e496e28170ab05ca215ea13edf0';
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
const counterFields = ['ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks',
    'video_starts', 'completed_views', 'active_view_viewable_impressions', 'active_view_measurable_impressions',
    'unfilled_impressions'];
const unsupportedFields = ['unfilled_requests', 'video_starts', 'completed_views', 'unfilled_impressions'];
const probeCheckKeys = ['initial_core_verified', 'targets_verified', 'core_rechecked', 'targets_unchanged', 'scope_rechecked'];
const groupResult = (rows = 2, source = 1, month = 1) => ({ source_ordinal: source, month_ordinal: month, rows,
    stored: Object.fromEntries(counterFields.map(key => [key, { zero: key === 'impressions' ? 0 : rows,
        nonzero: key === 'impressions' ? rows : 0, unknown: 0 }])),
    fresh: { reports_completed: rows, exact_site_observed: rows, exact_site_absent: 0, unit_day_observed: rows,
        nonmatching_site_observed: 0, unsupported_only_skipped: 0,
        fields: Object.fromEntries(counterFields.map(key => [key, { zero: unsupportedFields.includes(key) ? 0 : rows,
            nonzero: 0, unknown: 0, unavailable: unsupportedFields.includes(key) ? rows : 0 }])),
        revenue: { zero: rows, nonzero: 0, unknown: 0 } } });
const safeResult = () => ({ schema_version: 2, status: 'OK', reason: 'NONE',
    counts: { ...Object.fromEntries(countKeys.map(key => [key, 0])), initial_daily_facts: 96, sources: 3,
        applied_windows: 6, unique_receipts: 6, corrected_facts: 35, blocked_facts: 61, covered_facts: 96,
        remaining_facts: 61, remaining_money_zero: 61, remaining_counters_zero: 59, remaining_counters_nonzero: 2 },
    checks: Object.fromEntries(checkKeys.map(key => [key, true])),
    counter_probe: { status: 'COMPLETE', reason: 'NONE', target_rows: 2, reports_started: 2, reports_completed: 2,
        reports_skipped: 0, polls: 2, checks: Object.fromEntries(probeCheckKeys.map(key => [key, true])), groups: [groupResult()] } });
const failureResult = () => {
    const result = safeResult();
    result.schema_version = 1; result.status = 'FAILED'; result.reason = 'AUDIT_MISMATCH';
    result.checks.receipts_match = false;
    delete result.counter_probe;
    return result;
};
const unknownFresh = (group, completed = 0) => {
    group.fresh.reports_completed = completed;
    group.fresh.exact_site_observed = 0; group.fresh.exact_site_absent = completed;
    group.fresh.unit_day_observed = 0; group.fresh.nonmatching_site_observed = 0;
    for (const key of counterFields) if (!unsupportedFields.includes(key)) {
        group.fresh.fields[key] = { zero: 0, nonzero: 0, unknown: group.rows, unavailable: 0 };
    }
    group.fresh.revenue = { zero: 0, nonzero: 0, unknown: group.rows };
};

test('streamed PHP reader and runner output boundary agree on the closed public protocol', () => {
    const reader = readFileSync(path.join(root, 'ops/audit/verify-historical-correction.php'), 'utf8');
    const keys = name => [...reader.match(new RegExp(`public const ${name} = \\[([^;]+)\\];`))[1]
        .matchAll(/'([a-z_]+)'/g)].map(match => match[1]);
    assert.deepEqual(keys('COUNT_KEYS'), countKeys);
    assert.deepEqual(keys('CHECK_KEYS'), checkKeys);
    assert.deepEqual(keys('COUNTER_FIELDS'), counterFields);
    assert.deepEqual(keys('PROBE_CHECKS'), probeCheckKeys);
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

test('validator accepts only the full fixed-profile v2 probe and preserves opaque group counts', () => {
    const result = safeResult();
    result.counter_probe.groups = [groupResult(1, 1, 1), groupResult(1, 3, 96)];
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('validator preserves original sanitized v1 failure counts including wider drift', () => {
    const result = failureResult(); result.counts.other_record_drift = 97;
    assert.deepEqual(publicResult(validate(result, '10'), 'AUDIT_MISMATCH'), result);
});

test('validator retains overlapping unknown and known nonzero classifications on failed core evidence', () => {
    const result = failureResult();
    result.counts.remaining_money_zero = 0;
    result.counts.remaining_money_nonzero = 0;
    result.counts.remaining_money_unknown = 61;
    result.counts.remaining_money_known_nonzero = 61;
    result.counts.remaining_counters_zero = 0;
    result.counts.remaining_counters_nonzero = 61;
    result.counts.remaining_counters_unknown = 61;
    assert.deepEqual(publicResult(validate(result, '10'), 'AUDIT_MISMATCH'), result);
});

test('completed exact-site absence is unknown and can coexist with other-site rows', () => {
    const result = safeResult(), group = result.counter_probe.groups[0];
    unknownFresh(group, 2);
    group.fresh.unit_day_observed = 2; group.fresh.nonmatching_site_observed = 2;
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('unsupported optional ActiveView is unavailable only for completed exact rows', () => {
    const result = safeResult();
    for (const key of ['active_view_viewable_impressions', 'active_view_measurable_impressions']) {
        result.counter_probe.groups[0].fresh.fields[key] = { zero: 0, nonzero: 0, unknown: 0, unavailable: 2 };
    }
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('Google timeout remains inconclusive while the final core proof is current and sound', () => {
    const result = safeResult();
    result.counter_probe.status = 'INCONCLUSIVE'; result.counter_probe.reason = 'REPORT_TIMEOUT';
    result.counter_probe.reports_completed = 1; result.counter_probe.polls = 6;
    unknownFresh(result.counter_probe.groups[0], 1);
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

test('completed unsupported-only diagnosis keeps exact-site evidence unknown without queries', () => {
    const result = safeResult(), probe = result.counter_probe, group = probe.groups[0];
    probe.status = 'COMPLETE'; probe.reason = 'UNSUPPORTED_COUNTERS';
    probe.reports_started = 0; probe.reports_completed = 0; probe.reports_skipped = 2; probe.polls = 0;
    group.stored.impressions = { zero: 2, nonzero: 0, unknown: 0 };
    group.stored.video_starts = { zero: 0, nonzero: 2, unknown: 0 };
    unknownFresh(group); group.fresh.unsupported_only_skipped = 2;
    assert.deepEqual(publicResult(validate(result), 'NONE', 0), result);
});

for (const reason of ['CORE_AUDIT_FAILED', 'TARGET_SCOPE_MISMATCH', 'SOURCE_SCOPE_CHANGED']) {
    test(`probe safely skips ${reason} with explicit failed core evidence`, () => {
        const result = safeResult(), probe = result.counter_probe;
        result.status = 'FAILED'; result.reason = 'AUDIT_MISMATCH'; result.checks.inventory_matches = false;
        probe.status = 'SKIPPED'; probe.reason = reason;
        probe.reports_started = 0; probe.reports_completed = 0; probe.polls = 0;
        probe.checks = Object.fromEntries(probeCheckKeys.map(key => [key, false]));
        probe.checks.initial_core_verified = reason !== 'CORE_AUDIT_FAILED';
        if (reason === 'SOURCE_SCOPE_CHANGED') {
            probe.checks.targets_verified = true;
            unknownFresh(probe.groups[0]);
        } else { probe.target_rows = 0; probe.groups = []; }
        assert.deepEqual(publicResult(validate(result, '10'), 'AUDIT_MISMATCH'), result);
    });
}

for (const completed of [0, 2]) {
    test(`final core drift fails even after ${completed} completed Google reports`, () => {
        const result = safeResult(), probe = result.counter_probe;
        result.status = 'FAILED'; result.reason = 'AUDIT_MISMATCH'; result.checks.remaining_hashes_match = false;
        result.counts.hash_issues = 1;
        probe.status = 'INCONCLUSIVE'; probe.reason = 'RECHECK_FAILED'; probe.checks.core_rechecked = false;
        probe.reports_completed = completed;
        if (completed === 0) unknownFresh(probe.groups[0]);
        assert.deepEqual(publicResult(validate(result, '10'), 'AUDIT_MISMATCH'), result);
    });
}

test('transport preserves genuine failed audit counts and checks with exactly one public failure', () => {
    const fixture = shellFixture();
    try {
        const result = failureResult(); result.counts.missing_receipts = 1;
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
    ['duplicate key', r => JSON.stringify(r).replace('"schema_version":2', '"schema_version":2,"schema_version":2')],
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

for (const [label, mutate, transport = '0'] of [
    ['legacy successful reader', r => { r.schema_version = 1; delete r.counter_probe; }],
    ['missing probe', r => { delete r.counter_probe; }],
    ['wrong successful source profile', r => { r.counts.sources = 2; }],
    ['wrong successful fact profile', r => { r.counts.initial_daily_facts = 97; }],
    ['nonzero money masquerading as this fixed zero-money probe', r => { r.counts.remaining_money_zero = 60; r.counts.remaining_money_nonzero = 1; }],
    ['wrong successful counter profile', r => { r.counts.remaining_counters_zero = 58; r.counts.remaining_counters_nonzero = 3; }],
    ['successful informational drift', r => { r.counts.other_record_drift = 1; }],
    ['extra raw probe field', r => { r.counter_probe.private = privateText; }],
    ['missing probe field', r => { delete r.counter_probe.polls; }],
    ['unknown probe status', r => { r.counter_probe.status = privateText; }],
    ['unknown probe reason', r => { r.counter_probe.reason = privateText; }],
    ['COMPLETE with error reason', r => { r.counter_probe.reason = 'GOOGLE_REPORT_FAILED'; }],
    ['INCONCLUSIVE with NONE reason', r => { r.counter_probe.status = 'INCONCLUSIVE'; }],
    ['SKIPPED with successful core', r => { r.counter_probe.status = 'SKIPPED'; r.counter_probe.reason = 'SOURCE_SCOPE_CHANGED'; }],
    ['unsupported reason without skipped rows', r => { r.counter_probe.reason = 'UNSUPPORTED_COUNTERS'; }],
    ['missing initial core proof', r => { r.counter_probe.checks.initial_core_verified = false; }],
    ['missing final core proof', r => { r.counter_probe.checks.core_rechecked = false; }],
    ['missing final target proof', r => { r.counter_probe.checks.targets_unchanged = false; }],
    ['missing final source proof', r => { r.counter_probe.checks.scope_rechecked = false; }],
    ['string probe boolean', r => { r.counter_probe.checks.core_rechecked = 'true'; }],
    ['extra raw probe check', r => { r.counter_probe.checks[privateText] = true; }],
    ['third target', r => { r.counter_probe.target_rows = 3; }],
    ['single target widening', r => { r.counter_probe.target_rows = 1; }],
    ['third fresh report', r => { r.counter_probe.reports_started = 3; }],
    ['extra polling round', r => { r.counter_probe.polls = 7; }],
    ['completion without polling', r => { r.counter_probe.polls = 0; }],
    ['more completions than starts', r => { r.counter_probe.reports_started = 1; }],
    ['excess skipped rows', r => { r.counter_probe.reports_skipped = 3; }],
    ['started and skipped overlap', r => { r.counter_probe.reports_skipped = 1; }],
    ['missing groups', r => { r.counter_probe.groups = []; }],
    ['nonarray groups', r => { r.counter_probe.groups = {}; }],
    ['third group', r => { r.counter_probe.groups = [groupResult(1, 1, 1), groupResult(1, 2, 1), groupResult(1, 3, 1)]; }],
    ['duplicate ordinal pair', r => { r.counter_probe.groups = [groupResult(1), groupResult(1)]; }],
    ['unsorted ordinal pairs', r => { r.counter_probe.groups = [groupResult(1, 3, 1), groupResult(1, 1, 1)]; }],
    ['zero source ordinal', r => { r.counter_probe.groups[0].source_ordinal = 0; }],
    ['unbounded source ordinal', r => { r.counter_probe.groups[0].source_ordinal = 4; }],
    ['string ordinal', r => { r.counter_probe.groups[0].source_ordinal = '1'; }],
    ['zero month ordinal', r => { r.counter_probe.groups[0].month_ordinal = 0; }],
    ['unbounded month ordinal', r => { r.counter_probe.groups[0].month_ordinal = 97; }],
    ['date instead of month ordinal', r => { r.counter_probe.groups[0].month_ordinal = privateText; }],
    ['group size mismatch', r => { r.counter_probe.groups[0].rows = 1; }],
    ['zero group rows', r => { r.counter_probe.groups[0].rows = 0; }],
    ['raw group identity', r => { r.counter_probe.groups[0].source_id = privateText; }],
    ['missing stored counter', r => { delete r.counter_probe.groups[0].stored.clicks; }],
    ['extra stored counter', r => { r.counter_probe.groups[0].stored[privateText] = { zero: 2, nonzero: 0, unknown: 0 }; }],
    ['raw stored value', r => { r.counter_probe.groups[0].stored.impressions.nonzero = privateText; }],
    ['negative stored count', r => { r.counter_probe.groups[0].stored.impressions.nonzero = -1; }],
    ['fractional stored count', r => { r.counter_probe.groups[0].stored.impressions.nonzero = 1.5; }],
    ['excess stored count', r => { r.counter_probe.groups[0].stored.impressions.nonzero = 3; }],
    ['invalid stored partition', r => { r.counter_probe.groups[0].stored.impressions.zero = 1; }],
    ['stored unknown within selected fixed targets', r => { r.counter_probe.groups[0].stored.clicks = { zero: 1, nonzero: 0, unknown: 1 }; }],
    ['all zero selected counters', r => { r.counter_probe.groups[0].stored.impressions = { zero: 2, nonzero: 0, unknown: 0 }; }],
    ['raw fresh payload', r => { r.counter_probe.groups[0].fresh.csv = privateText; }],
    ['missing exact-site classification', r => { delete r.counter_probe.groups[0].fresh.exact_site_absent; }],
    ['inconsistent completion census', r => { r.counter_probe.groups[0].fresh.reports_completed = 1; }],
    ['exact and absent overlapping', r => { r.counter_probe.groups[0].fresh.exact_site_absent = 1; }],
    ['exact rows without unit-day evidence', r => { r.counter_probe.groups[0].fresh.unit_day_observed = 0; }],
    ['other-site evidence without source rows', r => { const g = r.counter_probe.groups[0]; unknownFresh(g, 2); g.fresh.nonmatching_site_observed = 1; }],
    ['unreported group skip', r => { r.counter_probe.groups[0].fresh.unsupported_only_skipped = 1; }],
    ['missing fresh counter', r => { delete r.counter_probe.groups[0].fresh.fields.impressions; }],
    ['extra fresh counter', r => { r.counter_probe.groups[0].fresh.fields[privateText] = {}; }],
    ['raw fresh counter value', r => { r.counter_probe.groups[0].fresh.fields.clicks.zero = privateText; }],
    ['extra fresh field state', r => { r.counter_probe.groups[0].fresh.fields.clicks[privateText] = 1; }],
    ['invalid fresh partition', r => { r.counter_probe.groups[0].fresh.fields.clicks.unknown = 1; }],
    ['unavailable required AdX field', r => { r.counter_probe.groups[0].fresh.fields.impressions = { zero: 0, nonzero: 0, unknown: 0, unavailable: 2 }; }],
    ['observed unrequested video field', r => { r.counter_probe.groups[0].fresh.fields.video_starts = { zero: 2, nonzero: 0, unknown: 0, unavailable: 0 }; }],
    ['absent report interpreted as zero counters', r => { const g = r.counter_probe.groups[0]; g.fresh.exact_site_observed = 0; g.fresh.exact_site_absent = 2; }],
    ['absent report interpreted as unavailable ActiveView', r => { const g = r.counter_probe.groups[0]; unknownFresh(g, 2); g.fresh.fields.active_view_viewable_impressions = { zero: 0, nonzero: 0, unknown: 0, unavailable: 2 }; }],
    ['absent report interpreted as zero money', r => { const g = r.counter_probe.groups[0]; unknownFresh(g, 2); g.fresh.revenue = { zero: 2, nonzero: 0, unknown: 0 }; }],
    ['money amount leakage', r => { r.counter_probe.groups[0].fresh.revenue.amount = privateText; }],
    ['missing fresh money classification', r => { delete r.counter_probe.groups[0].fresh.revenue; }],
    ['successful core with failed final recheck', r => { r.counter_probe.status = 'INCONCLUSIVE'; r.counter_probe.reason = 'RECHECK_FAILED'; r.counter_probe.checks.targets_unchanged = false; }],
    ['failed core with COMPLETE probe', r => { r.status = 'FAILED'; r.reason = 'AUDIT_MISMATCH'; r.checks.receipts_match = false; }, '10'],
    ['timeout after all reports completed', r => { r.counter_probe.status = 'INCONCLUSIVE'; r.counter_probe.reason = 'REPORT_TIMEOUT'; }],
]) test(`closed v2 probe rejects ${label} without private output`, () => {
    const result = safeResult(); mutate(result);
    const execution = validate(result, transport);
    assert.equal(execution.status, 2);
    assert.equal(execution.stdout, '');
    assert.equal(execution.stderr, '');
});

test('transport suppresses private content hidden at the deepest probe boundary', () => {
    const fixture = shellFixture();
    try {
        const result = safeResult(); result.counter_probe.groups[0].fresh.fields.impressions.value = privateText;
        writeFileSync(path.join(fixture.directory, 'expected.json'), JSON.stringify(result));
        assert.deepEqual(publicResult(fixture.run(), 'RESULT_INVALID'), { schema_version: 1, status: 'FAILED', reason: 'RESULT_INVALID' });
        assert.deepEqual(readdirSync(path.join(fixture.directory, 'runner')), []);
    } finally { fixture.cleanup(); }
});
