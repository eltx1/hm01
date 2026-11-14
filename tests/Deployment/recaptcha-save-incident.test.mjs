import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
const script = path.resolve('ops/audit/recaptcha-save-incident.php');
const php = process.env.PHP_BINARY || 'php';
test('production inspection remains main-only, validated and environment-protected', () => {
    const yaml = readFileSync('.github/workflows/recaptcha-save-incident.yml', 'utf8');
    assert.match(yaml, /if: github\.ref == 'refs\/heads\/main' && github\.event_name != 'pull_request'/);
    assert.match(yaml, /needs: validate/);
    assert.match(yaml, /environment: production/);
    assert.match(yaml, /HORUS_PRODUCTION_KNOWN_HOSTS/);
    assert.doesNotMatch(yaml, /StrictHostKeyChecking[= ]no|set -x/);
});
test('missing application root fails without leaking process or application data', () => {
    const root = mkdtempSync(path.join(tmpdir(), 'hm-recaptcha-audit-'));
    try {
        const r = spawnSync(php, [script, root], {encoding: 'utf8'});
        assert.equal(r.status, 2); assert.equal(r.stdout, ''); assert.equal(r.stderr, '');
    } finally { rmSync(root, {recursive: true, force: true}); }
});
test('bootstrap failure redacts sensitive exception text', () => {
    const root = mkdtempSync(path.join(tmpdir(), 'hm-recaptcha-audit-'));
    try {
        mkdirSync(path.join(root, 'vendor'));
        writeFileSync(path.join(root, 'vendor/autoload.php'), '<?php throw new RuntimeException("PRIVATE_TEST_VALUE_DO_NOT_PRINT");');
        const r = spawnSync(php, [script, root], {encoding: 'utf8'});
        assert.equal(r.status, 1); assert.equal(r.stdout, '');
        assert.equal(r.stderr, 'Read-only reCAPTCHA audit failed.\n');
        assert.equal((r.stdout+r.stderr).includes('PRIVATE_TEST_VALUE'), false);
    } finally { rmSync(root, {recursive: true, force: true}); }
});
test('probe has no model write or publication calls', () => {
    const source = readFileSync(script, 'utf8');
    assert.doesNotMatch(source, /(?:->|::)(?:save|update|delete|insert|create|publish|forceFill)\s*\(/);
});
