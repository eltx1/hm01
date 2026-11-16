import test from 'node:test';
import assert from 'node:assert/strict';
import { summarizeStartupTrace } from '../../scripts/startup-trace-summary.mjs';
const trace = events => ({ schema: 1, events: events.map(([phase, ms, slot]) => ({ phase, ms, slot, resource: phase === 'CFG ready' ? 'config' : null })) });
test('separates configuration, verification, post-PASS and rendering latency', () => {
    const s = summarizeStartupTrace(trace([['Horus init', 20], ['CFG start', 30], ['CFG ready', 80], ['CF start', 90], ['CF retry', 100], ['CF pass', 500], ['GPT load', 510], ['GPT ready', 600], ['GPT request', 620, 1], ['GPT response', 900, 1], ['GPT render', 950, 1]]));
    assert.deepEqual(s.timing_ms, { config: 50, controls: null, boot_preparation: null, cloudflare_total: 410, after_pass_to_first_request: 120, page_init_to_first_nonempty_render: 930, gpt_library: 90 });
    assert.equal(s.slots[0].request_to_response_ms, 280);
    assert.equal(s.slots[0].request_to_nonempty_render_ms, 330);
    assert.equal(s.retries_recorded, 1);
});
test('no-fill and failure never become successful nonempty renders', () => {
    const s = summarizeStartupTrace(trace([['GPT request', 0, 1], ['GPT empty', 100, 1], ['GPT request', 101, 2], ['GPT error', 150, 2], ['VAST no-fill', 200]]));
    assert.equal(s.slots[0].no_fill_recorded, true);
    assert.equal(s.slots[0].nonempty_render_recorded, false);
    assert.equal(s.slots[1].error_recorded, true);
    assert.equal(s.timing_ms.page_init_to_first_nonempty_render, null);
    assert.equal(s.video_no_fill_recorded, 1);
});
test('missing PASS and missing phases remain unknown, including a request before PASS', () => {
    assert.equal(summarizeStartupTrace(trace([['CF start', 1], ['CF timeout', 25001]])).timing_ms.after_pass_to_first_request, null);
    assert.equal(summarizeStartupTrace(trace([['GPT request', 10], ['CF pass', 20]])).timing_ms.after_pass_to_first_request, null);
    assert.equal(summarizeStartupTrace(trace([])).timing_ms.config, null);
});
test('private values and unknown fields never reach the report', () => {
    const s = summarizeStartupTrace({ schema: 1, url: 'PRIVATE_URL', events: [{ ms: 1, phase: 'CF pass', token: 'PRIVATE_TOKEN', error: 'PRIVATE_ERROR', slot: '<script>' }, { ms: 2, phase: 'PRIVATE_PHASE' }] });
    assert.equal(JSON.stringify(s).includes('PRIVATE_'), false);
    assert.equal(s.events_analyzed, 1);
});
for (const input of [null, {}, { schema: 2, events: [] }, { schema: 1, events: Array(161).fill({ phase: 'CF pass', ms: 1 }) }, trace([['CF start', 2], ['CF pass', 1]]), trace([['CF pass', NaN]]), trace([['CF pass', Infinity]]), trace([['CF pass', -1]]), trace([['CF pass', 86400001]])]) {
    test('rejects malformed, oversized or nonmonotonic traces', () => assert.throws(() => summarizeStartupTrace(input)));
}

test('configuration readiness never uses the earlier controls response', () => {
    const s = summarizeStartupTrace({schema: 1, events: [
        {phase: 'CFG start', ms: 0, attempt: 1},
        {phase: 'CFG ready', ms: 10, attempt: 1, resource: 'controls'},
        {phase: 'CFG ready', ms: 500, attempt: 1, resource: 'config'},
    ]});
    assert.equal(s.timing_ms.config, 500);
    assert.equal(s.timing_ms.controls, 10);
    assert.equal(s.timing_ms.boot_preparation, 500);
});
test('late results from an earlier attempt cannot complete the current attempt', () => {
    const s = summarizeStartupTrace({schema: 1, events: [
        {phase: 'CFG start', ms: 0, attempt: 1},
        {phase: 'CFG start', ms: 100, attempt: 2},
        {phase: 'CFG ready', ms: 110, attempt: 2, resource: 'controls'},
        {phase: 'CFG ready', ms: 500, attempt: 1, resource: 'config'},
        {phase: 'CFG error', ms: 600, attempt: 2, resource: 'config'},
    ]});
    assert.equal(s.timing_ms.config, null);
    assert.equal(s.timing_ms.boot_preparation, null);
});
test('a successful retry replaces an earlier timeout, but later decisions do not rewrite an earlier request', () => {
    const s = summarizeStartupTrace(trace([['Horus init',0], ['CF start',10], ['CF timeout',25010], ['CF retry',25020], ['CF pass',26000], ['GPT request',26100,1], ['CF error',27000]]));
    assert.equal(s.cloudflare_outcome, 'CF pass');
    assert.equal(s.timing_ms.cloudflare_total, 25990);
    assert.equal(s.timing_ms.after_pass_to_first_request,100);
});

test('independent engines keep signals, failed candidates and no valid bid separate from GPT impressions', () => {
    const s = summarizeStartupTrace(trace([
        ['Horus init',0], ['CF pass',1], ['Prebid start',2,1], ['Direct start',3,2],
        ['Direct candidate failed',4,2], ['Prebid frame',5,1], ['Direct render signal',6,2],
        ['Prebid start',7,3], ['Prebid no-bid',8,3], ['Direct start',9,4], ['Direct exhausted',10,4], ['House render',11,4],
    ]));
    assert.equal(s.slots.length, 0);
    assert.equal(s.timing_ms.page_init_to_first_nonempty_render, null);
    assert.equal(s.engine_evidence.prebid_standalone[0].start_to_signal_ms,3);
    assert.equal(s.engine_evidence.prebid_standalone[1].no_valid_bid_recorded,true);
    assert.equal(s.engine_evidence.direct_js[0].candidate_failure_recorded,true);
    assert.equal(s.engine_evidence.direct_js[0].signal_recorded,true);
    assert.equal(s.engine_evidence.direct_js[1].candidates_exhausted_recorded,true);
    assert.equal(s.engine_evidence.house_signals_recorded,1);
});
