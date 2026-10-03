import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';

const root = fileURLToPath(new URL('../..', import.meta.url));
const command = path.join(root, 'app/Console/Commands/RunGamHistoricalOperation.php');
const activationBytes = readFileSync(path.join(root, 'ops/audit/historical-gam-correction-once.json'));
const activation = JSON.parse(activationBytes);
const checksum = createHash('sha256').update(activationBytes).digest('hex');
const php = process.env.PHP_BIN || 'php';
const hasPhp = spawnSync(php, ['-v']).status === 0;
const phpOnly = { skip: !hasPhp && 'PHP runtime unavailable locally; required in release CI' };
const countKeys = ['sources', 'daily_facts', 'hourly_facts', 'forward_facts', 'windows', 'eligible_windows', 'blocked_facts', 'corrected_facts', 'pending', 'ready', 'applied', 'blocked'];
const result = changes => ({ schema_version: 1, operation: activation.operation, outcome: 'OK', reason: 'NONE', digest: null,
    counts: Object.fromEntries(countKeys.map(key => [key, 0])), reasons: {}, ...changes });
const progress = changes => result({ counts: { ...result().counts, pending: 1, ...changes } });

// Execute the actual command with only its framework/service boundary substituted.
// A controlled monotonic clock exercises the full budget without a 15-minute test.
const harness = `<?php
namespace Illuminate\\Console {
    class Command {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public object $output;
        public function __construct() { $this->output = new class { public function write($value): void { echo $value; } }; }
        public function argument($name) { return $GLOBALS['fixture']['arguments'][$name] ?? null; }
        public function option($name) { return $GLOBALS['fixture']['options'][$name] ?? null; }
    }
}
namespace App\\Services\\Reporting {
    class GamHistoricalOperation {
        public function advanceOneTime(array $contract): array {
            $GLOBALS['calls'][] = $contract;
            $step = array_shift($GLOBALS['fixture']['steps']);
            if (isset($step['diagnostic'])) echo $step['diagnostic'];
            if (isset($step['throw'])) throw new \\RuntimeException($step['throw']);
            return $step['result'];
        }
        public function run(...$arguments): array { throw new \\RuntimeException('WRONG_SERVICE_METHOD'); }
    }
}
namespace App\\Console\\Commands {
    function hrtime(bool $asNumber = false): int { return $GLOBALS['clock']; }
    function sleep(int $seconds): int { $GLOBALS['clock'] += $GLOBALS['fixture']['sleep_advance_ns'] ?? $seconds * 1000000000; return 0; }
}
namespace {
    function base_path($path = '') { return $GLOBALS['fixture']['project'].'/'.$path; }
    function storage_path($path = '') { return base_path('storage/'.$path); }
    function public_path($path = '') { return base_path('public/'.$path); }
    $GLOBALS['fixture'] = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $GLOBALS['clock'] = 0;
    $GLOBALS['calls'] = [];
    require $GLOBALS['fixture']['command'];
    $exit = (new \\App\\Console\\Commands\\RunGamHistoricalOperation)->handle(new \\App\\Services\\Reporting\\GamHistoricalOperation);
    file_put_contents($argv[2], json_encode($GLOBALS['calls'], JSON_THROW_ON_ERROR));
    exit($exit);
}
`;

function exerciseCommand(steps, changes = {}) {
    const directory = mkdtempSync(path.join(tmpdir(), 'hm-command-'));
    try {
        mkdirSync(path.join(directory, 'ops/audit'), { recursive: true });
        writeFileSync(path.join(directory, 'ops/audit/historical-gam-correction-once.json'), changes.activationBytes ?? activationBytes);
        writeFileSync(path.join(directory, 'harness.php'), harness);
        const fixture = {
            command, project: directory, arguments: { mode: 'execute-once' },
            options: { operation: activation.operation, 'actor-selector': activation.actor_selector, 'activation-sha256': checksum,
                'actor-fingerprint': '', digest: '', limit: '1', ...changes.options },
            steps, sleep_advance_ns: changes.sleepAdvance,
        };
        writeFileSync(path.join(directory, 'input.json'), JSON.stringify(fixture));
        const execution = spawnSync(php, ['-n', path.join(directory, 'harness.php'), path.join(directory, 'input.json'), path.join(directory, 'calls.json')], { encoding: 'utf8' });
        assert.equal(execution.stderr, '');
        assert.equal(execution.stdout.trim().split('\n').length, 1);
        assert.equal(execution.stdout.includes('PRIVATE_RAW_ERROR'), false);
        return { status: execution.status, output: JSON.parse(execution.stdout), calls: JSON.parse(readFileSync(path.join(directory, 'calls.json'))) };
    } finally { rmSync(directory, { recursive: true, force: true }); }
}

test('one-time command completes an internally reviewed service result with the fixed bounded contract', phpOnly, () => {
    const execution = exerciseCommand([{ result: result() }]);
    assert.equal(execution.status, 0);
    assert.deepEqual(execution.output, result());
    assert.deepEqual(execution.calls, [{ schema_version: 1, operation: activation.operation, actor_selector: activation.actor_selector, through: '2026-10-02' }]);
});

test('one-time command preserves one operation across steps and hides service diagnostics', phpOnly, () => {
    const finished = progress({ applied: 2, pending: 0 });
    const execution = exerciseCommand([{ result: progress({ applied: 1 }), diagnostic: 'PRIVATE_RAW_ERROR' }, { result: finished }]);
    assert.equal(execution.status, 0);
    assert.deepEqual(execution.output, finished);
    assert.equal(execution.calls.length, 2);
    assert.deepEqual(execution.calls[0], execution.calls[1]);
});

test('one-time budget exhaustion fails with honest checkpoint counts for the same-operation rerun', phpOnly, () => {
    const before = progress({ applied: 2 });
    const execution = exerciseCommand([{ result: before }], { sleepAdvance: 900_000_000_000 });
    assert.equal(execution.status, 1);
    assert.deepEqual(execution.output, { ...before, outcome: 'FAILED', reason: 'INCOMPLETE' });
    assert.equal(execution.calls.length, 1);
});

test('one-time terminal blocked coverage cannot appear as a successful workflow', phpOnly, () => {
    const before = result({ outcome: 'BLOCKED', counts: { ...result().counts, blocked_facts: 2, applied: 1 }, reasons: { INCOMPLETE_DAY: 2 } });
    const execution = exerciseCommand([{ result: before }]);
    assert.equal(execution.status, 1);
    assert.deepEqual(execution.output, { ...before, outcome: 'FAILED', reason: 'INCOMPLETE' });
});

test('one-time explicit service blocker retains its reason and stops immediately', phpOnly, () => {
    const before = progress({ applied: 2 });
    before.outcome = 'BLOCKED';
    before.reason = 'CANDIDATE_UNCERTAIN';
    const execution = exerciseCommand([{ result: before }]);
    assert.equal(execution.status, 1);
    assert.deepEqual(execution.output, { ...before, outcome: 'FAILED' });
    assert.equal(execution.calls.length, 1);
});

test('an unexpected later one-time exception preserves prior committed progress and hides raw errors', phpOnly, () => {
    const before = progress({ applied: 2 });
    const execution = exerciseCommand([{ result: before }, { throw: 'PRIVATE_RAW_ERROR' }]);
    assert.equal(execution.status, 1);
    assert.deepEqual(execution.output, { ...before, outcome: 'FAILED', reason: 'OPERATION_FAILED' });
});

for (const [label, changes] of [
    ['untrusted manifest checksum', { options: { 'activation-sha256': '0'.repeat(64) } }],
    ['changed local manifest bytes', { activationBytes: Buffer.from('{}') }],
    ['wrong operation', { options: { operation: '0'.repeat(64) } }],
    ['wrong actor selector', { options: { 'actor-selector': '0'.repeat(64) } }],
    ['unbounded batch', { options: { limit: '6' } }],
    ['reusable login fingerprint', { options: { 'actor-fingerprint': '0'.repeat(64) } }],
    ['external reviewed digest', { options: { digest: '0'.repeat(64) } }],
]) {
    test(`one-time command rejects ${label} before service execution`, phpOnly, () => {
        const execution = exerciseCommand([], changes);
        assert.equal(execution.status, 1);
        assert.equal(execution.output.reason, 'CONFIGURATION_INVALID');
        assert.deepEqual(execution.calls, []);
    });
}
