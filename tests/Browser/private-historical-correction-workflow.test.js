import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, chmodSync, rmSync, readdirSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn, spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('../..', import.meta.url));
const workflow = readFileSync(path.join(root, '.github/workflows/private-historical-correction.yml'), 'utf8');
const wrapper = path.join(root, 'ops/audit/private-historical-correction.sh');
const validator = path.join(root, 'ops/audit/validate-private-correction-result.php');
const require = createRequire(import.meta.url);
const token = '1'.repeat(64);
const actor = '2'.repeat(64);
const digest = '3'.repeat(64);
const sha = 'a'.repeat(40);
const artifactHash = 'b'.repeat(64);
const countKeys = ['sources', 'daily_facts', 'hourly_facts', 'forward_facts', 'windows', 'eligible_windows', 'blocked_facts', 'corrected_facts', 'pending', 'ready', 'applied', 'blocked'];
const safeResult = (changes = {}) => ({ schema_version: 1, outcome: 'OK', reason: 'NONE', operation: token, digest, counts: Object.fromEntries(countKeys.map(key => [key, 0])), reasons: {}, ...changes });
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const sensitive = 'PRIVATE-PUBLISHER.example user@example.test RAW-SQL-ERROR 2030-01-02 9876.54';

test('private workflow is manual main-only, read-only on GitHub, and has no result publication', () => {
    const trigger = workflow.split('\non:\n')[1].split('\npermissions:')[0];
    assert.match(trigger, /^  workflow_dispatch:/m);
    assert.doesNotMatch(trigger, /^  (push|pull_request|workflow_run|schedule):/m);
    assert.match(workflow, /if: \$\{\{ github\.event_name == 'workflow_dispatch' && github\.ref == 'refs\/heads\/main' \}\}/);
    assert.match(workflow, /permissions:\n  contents: read\n  actions: read\n/);
    assert.match(workflow, /environment: production/);
    assert.doesNotMatch(workflow, /actions\/upload-artifact|issues: write|createComment|core\.summary/);
    assert.match(workflow, /protection rules and whether dispatch is enabled[\s\S]*have not been verified/);
    assert.ok(workflow.indexOf('Require exact trusted production proof chain') < workflow.indexOf('actions/checkout@'));
    assert.ok(workflow.indexOf('Require exact trusted production proof chain') < workflow.indexOf('secrets.HORUS_PRODUCTION_'));
    assert.match(workflow, /persist-credentials: false/);
    assert.match(workflow, /run: bash ops\/audit\/private-historical-correction\.sh/);
});

const trustScript = workflow.split('          script: |\n')[1].split('\n      - name: Checkout')[0]
    .split('\n').map(line => line.startsWith('            ') ? line.slice(12) : line).join('\n');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const trust = new AsyncFunction('require', 'github', 'context', 'core', 'process', trustScript);

async function exerciseTrust(mutate = () => {}) {
    const directory = mkdtempSync(path.join(tmpdir(), 'hmtrust-'));
    try {
        const repo = { id: 1315038901, full_name: 'eltx1/hm01' };
        const baseRun = { status: 'completed', conclusion: 'success', head_branch: 'main', head_sha: sha, run_attempt: 2, repository: repo, head_repository: repo, run_started_at: '2026-10-01T10:00:00Z', updated_at: '2026-10-01T10:10:00Z' };
        const runs = {
            100: { ...baseRun, id: 100, name: 'Verify production live', path: '.github/workflows/verify-production-live.yml', event: 'workflow_dispatch' },
            200: { ...baseRun, id: 200, name: 'Deploy production', path: '.github/workflows/deploy-production.yml', event: 'workflow_run' },
            300: { ...baseRun, id: 300, name: 'Production release validation', path: '.github/workflows/production-release.yml', event: 'push' },
        };
        const release = Buffer.from('validated production package');
        const releaseHash = hash(release);
        const proofs = {
            live: `schema_version=1\nrelease_sha=${sha}\ndeploy_run_id=200\nverify_run_id=100\nverify_run_attempt=2\n`,
            deploy: `schema_version=1\nrelease_sha=${sha}\nvalidation_run_id=300\nartifact_sha256=${releaseHash}\ndeploy_run_id=200\ndeploy_run_attempt=2\n`,
            checksums: `${releaseHash}  release/horus-media-platform.zip\n`,
        };
        const fixture = { runs, proofs, artifacts: {}, downloads: {}, context: { repo: { owner: 'eltx1', repo: 'hm01' }, payload: { repository: repo }, eventName: 'workflow_dispatch', ref: 'refs/heads/main', sha }, env: { MODE: 'discover', OPERATION: token, ACTOR_FINGERPRINT: actor, DIGEST: '', BATCH_LIMIT: '1', VERIFY_RUN_ID: '100' }, laterRuns: {} };
        mutate(fixture, 'proofs');
        const createArtifact = (runId, name, entries) => {
            const staging = path.join(directory, String(runId));
            mkdirSync(staging);
            for (const [name, value] of Object.entries(entries)) writeFileSync(path.join(staging, name), value);
            const archive = path.join(directory, `${runId}.zip`);
            const packed = spawnSync('zip', ['-q', '-j', archive, ...Object.keys(entries).map(name => path.join(staging, name))]);
            assert.equal(packed.status, 0, packed.stderr?.toString());
            const bytes = readFileSync(archive);
            fixture.downloads[runId] = bytes;
            fixture.artifacts[runId] = [{ id: runId, name, expired: false, size_in_bytes: bytes.length, digest: `sha256:${hash(bytes)}`, created_at: '2026-10-01T10:05:00Z', workflow_run: { id: runId, head_sha: sha, head_branch: 'main', repository_id: 1315038901, head_repository_id: 1315038901 } }];
        };
        createArtifact(100, 'horus-production-live-proof', { 'live-proof.env': proofs.live });
        createArtifact(200, 'horus-production-deploy-proof', { 'deploy-proof.env': proofs.deploy });
        createArtifact(300, `horus-media-platform-${sha}`, { 'CHECKSUMS.txt': proofs.checksums, 'horus-media-platform.zip': release });
        mutate(fixture, 'artifacts');
        const outputs = {};
        const errors = [];
        const calls = new Map();
        let artifactDownloads = 0;
        const github = { rest: { actions: {
            getWorkflowRun: async ({ run_id }) => {
                calls.set(run_id, (calls.get(run_id) ?? 0) + 1);
                return { data: calls.get(run_id) > 1 && fixture.laterRuns[run_id] ? fixture.laterRuns[run_id] : fixture.runs[run_id] };
            },
            getWorkflowRunAttempt: async ({ run_id }) => ({ data: fixture.runs[run_id] }),
            listWorkflowRunArtifacts: async () => {},
            downloadArtifact: async ({ artifact_id }) => { artifactDownloads++; return { data: fixture.downloads[artifact_id] }; },
        } }, paginate: async (_method, { run_id }) => fixture.artifacts[run_id] };
        await trust(require, github, fixture.context, { setFailed: value => errors.push(value), setOutput: (key, value) => { outputs[key] = value; } }, { env: fixture.env });
        return { outputs, errors, releaseHash, queriedRuns: calls.size, artifactDownloads };
    } finally { rmSync(directory, { recursive: true, force: true }); }
}

test('trust chain accepts only matched current attempts and checks the actual release package checksum', async () => {
    const result = await exerciseTrust();
    assert.deepEqual(result.errors, []);
    assert.deepEqual(result.outputs, { release_sha: sha, artifact_sha256: result.releaseHash });
});

for (const [label, owner, name, id] of [
    ['foreign repository', 'other', 'foreign', 101],
    ['fork', 'attacker', 'hm01', 102],
    ['recreated matching repository name', 'eltx1', 'hm01', 103],
]) {
    test(`trust chain rejects a fully self-consistent ${label} before any API call or proof download`, async () => {
        const result = await exerciseTrust(fixture => {
            const repository = { id, full_name: `${owner}/${name}` };
            fixture.context.repo = { owner, repo: name };
            fixture.context.payload.repository = repository;
            for (const run of Object.values(fixture.runs)) {
                run.repository = repository;
                run.head_repository = repository;
            }
            for (const artifacts of Object.values(fixture.artifacts)) {
                for (const artifact of artifacts) Object.assign(artifact.workflow_run, { repository_id: id, head_repository_id: id });
            }
        });
        assert.deepEqual(result.outputs, {});
        assert.deepEqual(result.errors, ['TRUST_PROOF_INVALID']);
        assert.equal(result.queriedRuns, 0);
        assert.equal(result.artifactDownloads, 0);
    });
}

for (const [label, mutate] of [
    ['non-main dispatch', f => { f.context.ref = 'refs/heads/untrusted'; }],
    ['push invocation', f => { f.context.eventName = 'push'; }],
    ['apply without reviewed digest', f => { f.env.MODE = 'apply'; }],
    ['shell text in mode', f => { f.env.MODE = 'discover; env'; }],
    ['unbounded batch', f => { f.env.BATCH_LIMIT = '7'; }],
    ['unsafe run ID', f => { f.env.VERIFY_RUN_ID = '99999999999999999999'; }],
    ['mismatched verify release', f => { f.runs[100].head_sha = 'c'.repeat(40); }],
    ['mismatched deployment release', f => { f.runs[200].head_sha = 'c'.repeat(40); }],
    ['foreign head repository', f => { f.runs[200].head_repository = { id: 101, full_name: 'attacker/hm01' }; }],
    ['foreign base repository', f => { f.runs[300].repository = { id: 101, full_name: 'attacker/hm01' }; }],
    ['wrong workflow path', f => { f.runs[100].path = '.github/workflows/untrusted.yml'; }],
    ['pull-request validation', f => { f.runs[300].event = 'pull_request'; }],
    ['unsuccessful validation', f => { f.runs[300].conclusion = 'failure'; }],
    ['stale verification proof attempt', f => { f.proofs.live = f.proofs.live.replace('verify_run_attempt=2', 'verify_run_attempt=1'); }],
    ['stale deployment proof attempt', f => { f.proofs.deploy = f.proofs.deploy.replace('deploy_run_attempt=2', 'deploy_run_attempt=1'); }],
    ['duplicate proof key', f => { f.proofs.live += `release_sha=${sha}\n`; }],
    ['wrong linked run', f => { f.proofs.live = f.proofs.live.replace('deploy_run_id=200', 'deploy_run_id=301'); }],
    ['wrong validated package checksum', f => { f.proofs.checksums = `${artifactHash}  release/horus-media-platform.zip\n`; }],
    ['attempt changes while downloading', f => { f.laterRuns[200] = { ...f.runs[200], run_attempt: 3 }; }],
]) {
    test(`trust chain rejects ${label} without exposing raw proof errors`, async () => {
        const result = await exerciseTrust((fixture, phase) => { if (phase === 'proofs') mutate(fixture); });
        assert.deepEqual(result.outputs, {});
        assert.deepEqual(result.errors, ['TRUST_PROOF_INVALID']);
    });
}

for (const [label, mutate] of [
    ['expired artifact', f => { f.artifacts[100][0].expired = true; }],
    ['duplicate artifact', f => { f.artifacts[100].push(f.artifacts[100][0]); }],
    ['unbound artifact run', f => { f.artifacts[200][0].workflow_run.id = 500; }],
    ['unbound artifact repository', f => { f.artifacts[200][0].workflow_run.repository_id = 500; }],
    ['artifact from earlier attempt', f => { f.artifacts[300][0].created_at = '2026-10-01T09:00:00Z'; }],
    ['archive digest mismatch', f => { f.artifacts[100][0].digest = `sha256:${artifactHash}`; }],
    ['archive missing digest', f => { delete f.artifacts[100][0].digest; }],
    ['corrupt archive bytes', f => { f.downloads[100] = Buffer.from(sensitive); }],
]) {
    test(`trust chain rejects ${label}`, async () => {
        const result = await exerciseTrust((fixture, phase) => { if (phase === 'artifacts') mutate(fixture); });
        assert.deepEqual(result.outputs, {});
        assert.deepEqual(result.errors, ['TRUST_PROOF_INVALID']);
    });
}

function shellFixture() {
    const directory = mkdtempSync(path.join(tmpdir(), 'hmprivate-'));
    for (const child of ['home', 'runner', 'bin', 'remote-bin', 'server', 'server/releases', 'server/htdocs', `server/releases/${sha}`]) mkdirSync(path.join(directory, child));
    const release = path.join(directory, 'server/releases', sha);
    writeFileSync(path.join(release, '.horus-release'), `release_id=${sha}\nartifact_sha256=${artifactHash}\n`);
    symlinkSync(release, path.join(directory, 'server/htdocs/app.horusmedia.net'));
    writeFileSync(path.join(directory, 'expected.json'), JSON.stringify(safeResult()) + '\n');
    const executable = (name, body) => { const filename = path.join(directory, name); writeFileSync(filename, body); chmodSync(filename, 0o700); };
    executable('bin/ssh', `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$@" > "$FIXTURE/ssh-args"
if [[ "\${SSH_FIXTURE_MODE:-}" == transport-failure ]]; then printf '%s\\n' "$PRIVATE_SENTINEL"; printf '%s\\n' "$PRIVATE_SENTINEL" >&2; exit 255; fi
if [[ "\${SSH_FIXTURE_MODE:-}" == startup-noise ]]; then printf '%s\\n' "$PRIVATE_SENTINEL"; fi
PATH="$FIXTURE/remote-bin:$PATH" bash -c "\${!#}"
`);
    // Isolate the shell transport contract from PHP runtime availability. The
    // actual PHP validator is exercised separately below when PHP is installed.
    executable('bin/php', `#!${process.execPath}
const fs = require('fs');
fs.writeFileSync(process.env.FIXTURE + '/validator-called', 'yes');
try {
 const result = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
 if (!['OK','BLOCKED','FAILED'].includes(result.outcome) || Object.keys(result).length !== 7 || result.operation !== process.argv[5]) throw new Error('invalid');
 process.stdout.write(JSON.stringify(result) + '\\n');
 process.exit(result.outcome === 'FAILED' ? 1 : 0);
} catch { process.stdout.write(process.env.PRIVATE_SENTINEL); process.stderr.write(process.env.PRIVATE_SENTINEL); process.exit(2); }
`);
    executable('remote-bin/php', `#!/usr/bin/env bash
set -eu
printf '%s\\n' "$PRIVATE_SENTINEL"
printf '%s\\n' "$PRIVATE_SENTINEL" >&2
printf '%s\\n' "$@" > "$FIXTURE/php-args"
# An independent process must fail to acquire the exact deploy lock.
if flock -n "$FIXTURE/server/.horus-deploy.lock" true; then exit 98; fi
printf 'held' > "$FIXTURE/lock-held"
if [[ "\${PHP_FIXTURE_MODE:-}" == bootstrap-failure ]]; then exit 1; fi
for arg in "$@"; do case "$arg" in --result-file=*) result_file="\${arg#--result-file=}";; esac; done
cp "$FIXTURE/expected.json" "$result_file"
if [[ "\${PHP_FIXTURE_MODE:-}" == handled-failure ]]; then exit 1; fi
`);
    const env = { ...process.env, FIXTURE: directory, PRIVATE_SENTINEL: sensitive, PATH: `${directory}/bin:${process.env.PATH}`, HOME: path.join(directory, 'home'), RUNNER_TEMP: path.join(directory, 'runner'), REMOTE_HOME: path.join(directory, 'server'), HOST: 'production.example', SSH_USER: 'horusapp', SSH_PORT: '22', SSH_KEY: 'fixture-private-key', KNOWN_HOSTS: 'fixture-known-host', MODE: 'discover', OPERATION: token, ACTOR_FINGERPRINT: actor, DIGEST: '', BATCH_LIMIT: '1', RELEASE_SHA: sha, ARTIFACT_SHA256: artifactHash, GITHUB_EVENT_NAME: 'workflow_dispatch', GITHUB_REF: 'refs/heads/main', GITHUB_SHA: sha };
    return { directory, env, run: (changes = {}) => spawnSync('bash', [wrapper], { env: { ...env, ...changes }, encoding: 'utf8' }), cleanup: () => rmSync(directory, { recursive: true, force: true }) };
}

function publicResult(result, reason, expectedStatus = 1) {
    assert.equal(result.status, expectedStatus, result.stderr);
    assert.equal(result.stderr, '');
    assert.equal(result.stdout.trim().split('\n').length, 1, 'emit exactly one JSON object on every terminal path');
    const parsed = JSON.parse(result.stdout);
    assert.equal(parsed.reason, reason);
    assert.equal(result.stdout.includes(sensitive), false);
    return parsed;
}

test('transport holds deploy lock, uses pinned SSH, and keeps PHP stdout/stderr only on the private server', () => {
    const fixture = shellFixture();
    try {
        publicResult(fixture.run(), 'NONE', 0);
        const args = readFileSync(path.join(fixture.directory, 'ssh-args'), 'utf8');
        assert.match(args, /StrictHostKeyChecking=yes/);
        assert.match(args, /BatchMode=yes/);
        assert.match(args, /IdentitiesOnly=yes/);
        assert.match(args, /UserKnownHostsFile=/);
        assert.equal(readFileSync(path.join(fixture.directory, 'lock-held'), 'utf8'), 'held');
        const privateRoot = path.join(fixture.directory, 'server/.horus-private-historical');
        const request = readdirSync(privateRoot)[0];
        const log = readFileSync(path.join(privateRoot, request, 'command.log'), 'utf8');
        assert.equal(log.split(sensitive).length - 1, 2);
        assert.deepEqual(readdirSync(path.join(fixture.directory, 'runner')), []);
        assert.match(readFileSync(path.join(fixture.directory, 'php-args'), 'utf8'), /reporting:gam-historical-operation\ndiscover/);
    } finally { fixture.cleanup(); }
});

for (const [label, changes] of [
    ['mode injection', { MODE: 'discover; printf secret' }],
    ['operation injection', { OPERATION: "'; env; #" }],
    ['actor missing', { ACTOR_FINGERPRINT: '' }],
    ['digest injection', { DIGEST: '$(env)' }],
    ['unreviewed apply', { MODE: 'apply' }],
    ['unbounded batch', { BATCH_LIMIT: '7' }],
    ['SSH option injection', { HOST: '-oProxyCommand=env' }],
    ['SSH user injection', { SSH_USER: 'root@host' }],
    ['remote HOME injection', { REMOTE_HOME: "/home/horus'; env; #" }],
    ['remote HOME traversal', { REMOTE_HOME: '/home/../root' }],
    ['local HOME injection', { HOME: "/tmp/home'; env; #" }],
    ['non-main dispatch', { GITHUB_REF: 'refs/heads/feature' }],
    ['wrong dispatch SHA', { GITHUB_SHA: 'c'.repeat(40) }],
    ['invalid port', { SSH_PORT: '65536' }],
]) {
    test(`transport rejects ${label} before SSH with one canonical object`, () => {
        const fixture = shellFixture();
        try {
            publicResult(fixture.run(changes), 'CONFIGURATION_INVALID');
            assert.equal(readdirSync(path.join(fixture.directory, 'runner')).length, 0);
            assert.equal(readdirSync(fixture.directory).includes('ssh-args'), false);
        } finally { fixture.cleanup(); }
    });
}

test('SSH failures hide stdout and stderr and do not invoke the validator', () => {
    const fixture = shellFixture();
    try {
        publicResult(fixture.run({ SSH_FIXTURE_MODE: 'transport-failure' }), 'TRANSPORT_FAILED');
        assert.equal(readdirSync(fixture.directory).includes('validator-called'), false);
    } finally { fixture.cleanup(); }
});

test('unexpected SSH startup output is rejected and validator diagnostics stay hidden', () => {
    const fixture = shellFixture();
    try { publicResult(fixture.run({ SSH_FIXTURE_MODE: 'startup-noise' }), 'RESULT_INVALID'); }
    finally { fixture.cleanup(); }
});

test('bootstrap failures never print their raw exception and emit one command failure', () => {
    const fixture = shellFixture();
    try { publicResult(fixture.run({ PHP_FIXTURE_MODE: 'bootstrap-failure' }), 'COMMAND_FAILED'); }
    finally { fixture.cleanup(); }
});

test('a handled failed result is preserved once instead of becoming a second shell error', () => {
    const fixture = shellFixture();
    try {
        writeFileSync(path.join(fixture.directory, 'expected.json'), JSON.stringify(safeResult({ outcome: 'FAILED', reason: 'OPERATION_FAILED' })));
        publicResult(fixture.run({ PHP_FIXTURE_MODE: 'handled-failure' }), 'OPERATION_FAILED');
    } finally { fixture.cleanup(); }
});

test('release marker mismatch blocks PHP before bootstrap', () => {
    const fixture = shellFixture();
    try {
        writeFileSync(path.join(fixture.directory, 'server/releases', sha, '.horus-release'), `release_id=${sha}\nartifact_sha256=${'c'.repeat(64)}\n`);
        publicResult(fixture.run(), 'RELEASE_MISMATCH');
        assert.equal(readdirSync(fixture.directory).includes('php-args'), false);
    } finally { fixture.cleanup(); }
});

test('a current symlink pointing at a different release blocks PHP', () => {
    const fixture = shellFixture();
    try {
        const current = path.join(fixture.directory, 'server/htdocs/app.horusmedia.net');
        rmSync(current);
        symlinkSync(path.join(fixture.directory, 'server'), current);
        publicResult(fixture.run(), 'RELEASE_MISMATCH');
        assert.equal(readdirSync(fixture.directory).includes('php-args'), false);
    } finally { fixture.cleanup(); }
});

test('an in-progress deployment blocks the command using the shared lock', async () => {
    const fixture = shellFixture();
    const holder = spawn('flock', ['-n', path.join(fixture.directory, 'server/.horus-deploy.lock'), 'bash', '-c', 'printf ready; read -r release_lock']);
    try {
        await new Promise((resolve, reject) => {
            holder.stdout.once('data', resolve);
            holder.once('error', reject);
            holder.once('exit', code => reject(new Error(`lock holder exited early: ${code}`)));
        });
        publicResult(fixture.run(), 'DEPLOY_LOCK_BUSY');
        assert.equal(readdirSync(fixture.directory).includes('php-args'), false);
    } finally {
        holder.stdin.end('release\n');
        await new Promise(resolve => holder.once('exit', resolve));
        fixture.cleanup();
    }
});

const php = process.env.PHP_BIN || 'php';
const hasPhp = spawnSync(php, ['-v']).status === 0;
function validateResult(value, expectedDigest = '', transportStatus = '0') {
    const directory = mkdtempSync(path.join(tmpdir(), 'hmjson-'));
    try {
        const file = path.join(directory, 'result.json');
        writeFileSync(file, typeof value === 'string' ? value : JSON.stringify(value));
        return spawnSync(php, ['-n', validator, file, token, expectedDigest, transportStatus], { encoding: 'utf8' });
    } finally { rmSync(directory, { recursive: true, force: true }); }
}

test('real PHP validator reconstructs a typed allowlisted result', { skip: !hasPhp && 'PHP runtime unavailable locally; required in release CI' }, () => {
    const result = validateResult(safeResult());
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(result.stdout), safeResult());
});

test('real PHP validator permits only blocked digest-mismatch reports to carry the existing digest', { skip: !hasPhp && 'PHP runtime unavailable locally; required in release CI' }, () => {
    const value = safeResult({ outcome: 'BLOCKED', reason: 'DIGEST_MISMATCH' });
    const result = validateResult(value, actor);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(JSON.parse(result.stdout), value);
});

for (const [label, value, expectedDigest, transportStatus] of [
    ['unknown top-level private field', safeResult({ account: sensitive })],
    ['unknown count key', safeResult({ counts: { ...safeResult().counts, publisher: 1 } })],
    ['unknown private reason', safeResult({ reasons: { [sensitive]: 1 } })],
    ['raw reason text', safeResult({ reason: sensitive })],
    ['string count', safeResult({ counts: { ...safeResult().counts, sources: sensitive } })],
    ['boolean count', safeResult({ counts: { ...safeResult().counts, sources: true } })],
    ['negative count', safeResult({ counts: { ...safeResult().counts, sources: -1 } })],
    ['fractional count', safeResult({ counts: { ...safeResult().counts, sources: 1.5 } })],
    ['unsafe integer count', safeResult({ counts: { ...safeResult().counts, sources: 9007199254740992 } })],
    ['missing count', safeResult({ counts: {} })],
    ['array reasons', safeResult({ reasons: [] })],
    ['wrong operation', safeResult({ operation: actor })],
    ['wrong submitted digest', safeResult(), actor],
    ['successful result missing submitted digest', safeResult({ digest: null }), digest],
    ['unrelated blocked digest mismatch', safeResult({ outcome: 'BLOCKED', reason: 'STALE_INVENTORY' }), actor],
    ['success after nonzero command', safeResult(), '', '10'],
    ['PHP bootstrap pollution', sensitive + JSON.stringify(safeResult())],
    ['trailing diagnostic', JSON.stringify(safeResult()) + sensitive],
    ['oversized result', ' '.repeat(20000) + JSON.stringify(safeResult())],
]) {
    test(`real PHP validator rejects ${label} without any output`, { skip: !hasPhp && 'PHP runtime unavailable locally; required in release CI' }, () => {
        const result = validateResult(value, expectedDigest, transportStatus);
        assert.equal(result.status, 2);
        assert.equal(result.stdout, '');
        assert.equal(result.stderr, '');
    });
}
