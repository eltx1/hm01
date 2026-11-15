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
function parse(lines) {
    const code='require $argv[1]; $lines=json_decode(stream_get_contents(STDIN),true); echo json_encode(array_map("hmIncidentLogRecord",$lines));';
    const r=spawnSync(php,['-r',code,script],{encoding:'utf8',input:JSON.stringify(lines)});
    assert.equal(r.status,0,r.stderr);assert.equal(r.stderr,'');return JSON.parse(r.stdout);
}
test('production JSON records are parsed with UTC time and private messages discarded',()=>{
    const [record]=parse([JSON.stringify({datetime:'2026-11-14T18:20:30+02:00',level_name:'ERROR',message:'RecaptchaObservation PRIVATE_TOKEN',context:{exception:{class:'Illuminate\\Database\\QueryException',message:'SQLSTATE deadlock PRIVATE_SQL',trace:'PRIVATE_STACK'}}})]);
    assert.equal(record.at,'2026-11-14 16:20:30');assert.equal(record.format,'json');
    assert.equal(record.level,'ERROR');assert.deepEqual(record.categories,['db_lock']);
    assert.equal(record.class,'Illuminate\\Database\\QueryException');assert.equal(record.mentions_observation,true);
    assert.equal(JSON.stringify(record).includes('PRIVATE'),false);
});
test('text errors and valid non-error JSON remain distinguishable from unrecognized records',()=>{
    const records=parse(['[2026-11-14 17:00:00] production.ERROR: Allowed memory size exhausted PRIVATE_VALUE',JSON.stringify({datetime:'2026-11-15T01:00:00Z',level_name:'INFO',message:'PRIVATE_VALUE'}),'not a record',JSON.stringify({datetime:'not a date',level_name:'ERROR',message:'PRIVATE_VALUE'})]);
    assert.deepEqual(records[0].categories,['memory']);assert.equal(records[0].format,'text');
    assert.equal(records[1].level,'INFO');assert.equal(records[1].at,'2026-11-15 01:00:00');
    assert.equal(records[2],null);assert.equal(records[3],null);assert.equal(JSON.stringify(records).includes('PRIVATE'),false);
});
