import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {summarizeProtectionEvidence} from '../../scripts/protection-evidence-summary.mjs';

const sample = (phases = [], extra = {}) => ({site: 'site_one', device: 'android', browser: 'chrome',
    capturedAt: '2026-11-16T12:00:00Z', trace: {schema: 1, events: phases.map(phase => ({phase, ms: 1}))}, ...extra});
const summary = samples => summarizeProtectionEvidence({schema: 1, samples});
test('snapshot denominators preserve pass, reject, timeout, error, pending and unknown separately', () => {
    const report = summary([sample(['CF start', 'CF pass']), sample(['CF reject']), sample(['CF timeout']), sample(['CF error']),
        sample(['CF reject', 'CF retry']), sample([]), sample(['CF error', 'CF retry', 'CF pass'])]);
    assert.equal(report.included_snapshots, 7);
    assert.equal(report.groups[0].snapshots, 7);
    assert.deepEqual(report.groups[0].cloudflare, {reported_pass: 2, reported_reject: 1, timeout: 1, error: 1, pending: 1, unknown: 1});
    assert.match(report.scope, /not requests, visitors/);
    assert.match(report.coverage, /duplicate submissions/);
});
test('truncated traces and missing debug state stay unknown; no retry or click totals inferred', () => {
    const report = summary([sample([], {trace: {schema: 1, dropped: 3, events: [{phase: 'CF pass'}]}}),
        sample(['CF pass'], {clickGuard: {enabled: true, blocked: true, clicksInWindow: 900, token: 'PRIVATE'}}),
        sample([], {clickGuard: {enabled: true, blocked: false}}), sample([], {clickGuard: {enabled: false}})]);
    assert.deepEqual(report.groups[0].click_guard, {reported_blocked: 1, reported_clear: 1, disabled: 1, unknown: 1});
    assert.equal(report.groups[0].cloudflare.unknown, 3);
    assert.doesNotMatch(JSON.stringify(report), /PRIVATE|clicksInWindow|900/);
});
test('site, device, browser and capture-day groups do not silently combine evidence', () => {
    const report = summary([sample(['CF pass']), sample(['CF reject'], {site: 'site_two'}), sample([], {device: 'ios', browser: 'safari'}),
        sample([], {capturedAt: '2026-11-15T23:00:00Z'}), sample([], {device: 'SECRET_AGENT', browser: '<script>', capturedAt: '2026-02-30T12:00:00Z'})]);
    assert.equal(report.groups.length, 5);
    assert.equal(report.groups.find(group => group.device === 'unknown').capture_day_utc, 'undated');
    assert.doesNotMatch(JSON.stringify(report), /SECRET_AGENT|<script>/);
    assert.ok(report.groups.every(group => Object.values(group.cloudflare).reduce((sum, count) => sum + count, 0) === group.snapshots));
});
test('strict input bounds reject malformed schemas, identifiers and oversized event batches', () => {
    for (const data of [{}, {schema: 1, samples: []}, {schema: 2, samples: [sample()]}, {schema: 1, samples: Array(101).fill(sample())},
        {schema: 1, samples: [sample([], {site: ['abc']})]}, {schema: 1, samples: [sample([], {site: '<script>'})]},
        {schema: 1, samples: [sample([], {trace: {schema: 1, events: Array(161).fill({phase: 'CF pass'})}})]}]) assert.throws(() => summarizeProtectionEvidence(data));
});
const source = readFileSync(new URL('../../resources/js/protection-diagnostics.js', import.meta.url), 'utf8').replace(/^import[^\n]+\n/, '');
function page(value) {
    let click;
    const input = {value}, output = {textContent: ''}, button = {addEventListener(name, fn) {assert.equal(name, 'click'); click = fn;}};
    vm.runInNewContext(source, {summarizeProtectionEvidence, document: {querySelector: key => ({'[data-protection-input]': input, '[data-protection-analyze]': button, '[data-protection-result]': output})[key]}});
    return {click, output};
}
test('UI analyzes only locally and renders sanitized text', () => {
    const value = sample(['CF pass']); value.trace.events[0].token = '<script>PRIVATE</script>';
    const ui = page(JSON.stringify({schema: 1, samples: [value]})); ui.click();
    assert.equal(JSON.parse(ui.output.textContent).included_snapshots, 1);
    assert.doesNotMatch(ui.output.textContent, /PRIVATE|<script>/);
});
test('UI rejects over-limit/invalid input without echoing and tolerates other admin pages', () => {
    for (const value of ['PRIVATE invalid', 'x'.repeat(262145), '{"schema":2}']) {
        const ui = page(value); ui.click(); assert.match(ui.output.textContent, /^Evidence could not be analyzed/); assert.doesNotMatch(ui.output.textContent, /PRIVATE/);
    }
    assert.doesNotThrow(() => vm.runInNewContext(source, {summarizeProtectionEvidence, document: {querySelector: () => null}}));
});
