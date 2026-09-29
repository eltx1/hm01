from pathlib import Path
r=Path('.')
p=r/'tests/Browser/hm-loader-traffic-gate.test.js';s=p.read_text()
s=s.replace("['pageNonce', 'protocolVersion', 'sitePublicKey', 'type']", "['pageNonce', 'protocolVersion', 'sitePublicKey', 'startupTrace', 'type']")
assert s.count('    deferFirstConfig = false,\n')==1
s=s.replace('    deferFirstConfig = false,\n','    deferFirstConfig = false,\n    holdFrameLoad = false,\n')
assert s.count('                else node.onload?.();')==1
s=s.replace('                else node.onload?.();','                else if (!holdFrameLoad) node.onload?.();')
s+='''
// Readiness can precede the iframe load event; readiness never authorizes ads.
for (const boundary of ['valid', 'origin', 'source', 'protocol']) {
    test(`frame readiness before load preserves ${boundary} authorization boundary`, async () => {
        const runtime = createHarness(baseConfig(), { holdFrameLoad: true, timerScale: 1 });
        const boot = runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        const event = { type: 'message', origin: GATE_ORIGIN, source: runtime.gateFrame.contentWindow,
            data: { type: 'HORUS_TRAFFIC_GATE_BOOT', protocolVersion: 2 } };
        if (boundary === 'origin') event.origin = 'https://attacker.example';
        if (boundary === 'source') event.source = {};
        if (boundary === 'protocol') event.data.protocolVersion = 1;
        runtime.sandbox.dispatchEvent(event); await runtime.flush();
        assert.equal(runtime.metrics.hellos.length, boundary === 'valid' ? 1 : 0);
        assertNoMonetization(runtime.metrics);
        runtime.gateFrame.onload();
        assert.equal(runtime.metrics.hellos.length, 1);
        runtime.sandbox.dispatchEvent({ ...event, origin: GATE_ORIGIN, source: runtime.gateFrame.contentWindow,
            data: { type: 'HORUS_TRAFFIC_GATE_BOOT', protocolVersion: 2 } });
        assert.equal(runtime.metrics.hellos.length, 1, 'only one HELLO per current frame');
        runtime.sendGate('PASS', { serverVerified: false }); assertNoMonetization(runtime.metrics);
        runtime.sendGate('PASS'); await boot;
        assert.equal(runtime.metrics.gamRequests, 1);
        const codes = runtime.sandbox.HorusMediaLoader.getStartupTrace().map(row => row.code);
        assert.ok(codes.indexOf('CF start') < codes.indexOf('CF pass'));
        assert.ok(codes.indexOf('CF pass') < codes.indexOf('Horus start'));
    });
}
test('bound-frame progress never authorizes ads or leaks arbitrary payloads', async () => {
    const runtime = createHarness(baseConfig(), { timerScale: 1 });
    const boot = runtime.sandbox.HorusMediaLoader.boot(); await runtime.flush();
    runtime.sendGate('PROGRESS', { stage: 'token', token: 'DO_NOT_LOG_TOKEN' });
    runtime.sendGate('PROGRESS', { stage: 'verify' });
    runtime.sendGate('PROGRESS', { stage: 'pass' });
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PENDING');
    assertNoMonetization(runtime.metrics);
    let trace = runtime.sandbox.HorusMediaLoader.getStartupTrace();
    assert.ok(trace.some(row => row.code === 'CF token'));
    assert.equal(trace.some(row => row.code === 'CF pass'), false);
    assert.equal(JSON.stringify(trace).includes('DO_NOT_LOG_TOKEN'), false);
    runtime.sendGate('DENIED'); await boot;
    trace = runtime.sandbox.HorusMediaLoader.getStartupTrace();
    assert.ok(trace.some(row => row.code === 'CF reject')); assertNoMonetization(runtime.metrics);
});
'''
p.write_text(s)
p=r/'tests/Browser/hm-startup-trace.test.js';p.write_text('''import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
function harness(brokenConsole = false) {
    const lines = [], events = {};
    const document = { readyState: 'loading', addEventListener(name, cb) { events[name] = cb; } };
    const sandbox = { document, URL, __HM_DISABLE_AUTOBOOT__: true, performance: { now: () => 1250.5 },
        console: { log(line) { if (brokenConsole) throw new Error('console unavailable'); lines.push(line); } } };
    sandbox.window = sandbox; vm.runInNewContext(source, sandbox);
    return { sandbox, lines, events, trace: sandbox.__HORUS_STARTUP_TRACE_V1__ };
}
test('trace records time, DOM boundary and defensive copies without identifiers', () => {
    const h = harness(); assert.equal(h.lines[0], '[HM +1251ms] H init');
    assert.ok(h.lines.some(s => s.includes('H wait DOM'))); h.events.DOMContentLoaded();
    const rows = h.sandbox.HorusMediaLoader.getStartupTrace(); rows[0].code = 'mutated';
    assert.equal(h.trace.snapshot()[0].code, 'H init');
    assert.ok(h.trace.snapshot().some(row => row.code === 'H dom'));
});
test('trace is capped, idempotent and cannot print arbitrary strings or objects', () => {
    const h = harness(); const n = h.lines.length;
    h.trace.emit('https://example.com/token=SECRET'); h.trace.emit('CF token', { secret: true });
    assert.equal(h.lines.length, n + 1); assert.equal(h.trace.snapshot().at(-1).n, undefined);
    for (let i = 0; i < 1000; i++) h.trace.emit('V req');
    assert.equal(h.lines.length, 160); assert.equal(h.trace.snapshot().length, 160);
    vm.runInNewContext(source, h.sandbox); assert.equal(h.lines.length, 160);
});
test('throwing console cannot stop recording or alter Loader startup API', () => {
    const h = harness(true); h.trace.emit('CF start'); h.trace.emit('CF pass');
    assert.equal(h.trace.snapshot().at(-1).code, 'CF pass');
    assert.equal(h.sandbox.__HORUS_MEDIA_LOADER_STATE__.adInitializationStarted, false);
    assert.equal(typeof h.sandbox.HorusMediaLoader.boot, 'function');
});
test('only registered-slot events produce G req; SDK readiness is not a request', () => {
    const h = harness(), listeners = {}, slot = {}, other = {};
    const pubads = { addEventListener(name, fn) { (listeners[name] ||= []).push(fn); } };
    h.trace.watchSlot(slot, pubads); h.trace.watchSlot(slot, pubads);
    assert.equal(listeners.slotRequested.length, 1);
    assert.equal(h.trace.snapshot().some(r => r.code === 'G req'), false);
    listeners.slotRequested[0]({ slot: other });
    assert.equal(h.trace.snapshot().some(r => r.code === 'G req'), false);
    listeners.slotRequested[0]({ slot }); listeners.slotResponseReceived[0]({ slot });
    listeners.slotRenderEnded[0]({ slot, isEmpty: true }); listeners.slotOnload[0]({ slot });
    assert.equal(h.trace.snapshot().slice(-4).map(r => r.code).join(','), 'G req,G res,G empty,G load');
    assert.ok(h.trace.snapshot().slice(-4).every(r => r.n === 1));
});
''')
p=r/'tests/Browser/hm-loader-native.test.js';s=p.read_text()
s=s.replace('addEventListener(name, callback) { metrics.events[name] = callback; },','// GPT supports multiple independent event subscribers.\n        addEventListener(name, callback) { (metrics.events[name] ||= []).push(callback); },')
s=s.replace('queueMicrotask(() => metrics.events.slotRenderEnded?.({ slot: slots[0], isEmpty: gamEmpty }));','queueMicrotask(() => (metrics.events.slotRenderEnded || []).forEach(callback => callback({ slot: slots[0], isEmpty: gamEmpty })));')
p.write_text(s)
p=r/'public/assets/hm-gpt-direct.js';s=p.read_text().replace('                startupTraceSlot(slot, pubads);\n            slot.addService(pubads);','                startupTraceSlot(slot, pubads);\n                slot.addService(pubads);');p.write_text(s)
p=r/'tests/Browser/gpt-dependencies.playwright.spec.js';s=p.read_text()
s=s.replace('const queue = window.googletag?.cmd || [], slots = new Map(), listeners = new Set();','const queue = window.googletag?.cmd || [], slots = new Map(), listeners = new Map();')
s=s.replace("const pubads = { addEventListener(name, fn) { if (name === 'slotRenderEnded') listeners.add(fn); },\n        removeEventListener(name, fn) { listeners.delete(fn); } };","const pubads = { addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },\n        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); } };\n    const emit = (name, event) => [...(listeners.get(name) || [])].forEach(fn => fn(event));")
s=s.replace('queueMicrotask(() => [...listeners].forEach(fn => fn({ slot: slots.get(id), isEmpty: false, size: [300, 250] })));',"emit('slotRequested', { slot: slots.get(id) });\n            queueMicrotask(() => { emit('slotResponseReceived', { slot: slots.get(id) });\n                emit('slotRenderEnded', { slot: slots.get(id), isEmpty: false, size: [300, 250] }); });")
s=s.replace('if (!options.delay) release();','if (!options.delay && !options.holdGateLoad) release();')
s=s.replace("        if (url.origin === 'https://verify.horusmedia.net') {\n","        if (url.origin === 'https://verify.horusmedia.net') {\n            if (url.pathname === '/pending-image.png') { await hold; return route.fulfill({ status: 204 }); }\n")
s=s.replace("{ contentType: 'text/html', body: gateHtml });","{ contentType: 'text/html', body: options.holdGateLoad ? gateHtml.replace('</body>', '<img src=\"/pending-image.png\"></body>') : gateHtml });")
s+='''
for (const minified of [false, true]) {
    test(`${minified ? 'minified' : 'composed'}: verified readiness starts ads before gate frame load completes`, async ({ page }) => {
        const run = await open(page, { minified, holdGateLoad: true });
        try {
            await expect.poll(() => run.counts.verifies).toBe(1);
            await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
            const trace = await page.evaluate(() => window.HorusMediaLoader.getStartupTrace());
            const codes = trace.map(row => row.code);
            expect(codes).toContain('CF token'); expect(codes).toContain('CF verify');
            expect(codes.indexOf('CF pass')).toBeGreaterThan(codes.indexOf('CF verify'));
            expect(codes.indexOf('G req')).toBeGreaterThan(codes.indexOf('Horus start'));
            expect(JSON.stringify(trace)).not.toContain('fixture-token');
            expect(JSON.stringify(trace)).not.toContain('/123/display');
        } finally { run.release(); }
    });
    test(`${minified ? 'minified' : 'composed'}: token or pending verification is not CF pass or Horus start`, async ({ page }) => {
        const run = await open(page, { minified, delay: 'verification' });
        try {
            await expect.poll(() => run.counts.verifies).toBe(1);
            let codes = await page.evaluate(() => window.HorusMediaLoader.getStartupTrace().map(row => row.code));
            expect(codes).toContain('CF token'); expect(codes).toContain('CF verify');
            expect(codes).not.toContain('CF pass'); expect(codes).not.toContain('Horus start');
            expect(codes).not.toContain('G req');
            run.release();
            await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
            codes = await page.evaluate(() => window.HorusMediaLoader.getStartupTrace().map(row => row.code));
            expect(codes).toContain('CF pass'); expect(codes).toContain('G req');
        } finally { run.release(); }
    });
}
'''
p.write_text(s)
(r/'docs/AD_STARTUP_DIAGNOSTICS.md').write_text('''# Local ad startup timing\n\nConsole prints a bounded [HM +Nms] timeline, local to the document. It is not a secret: anyone opening DevTools can see it. No tokens, nonces, URLs, cookies, inventory IDs, local storage or telemetry requests are used. Up to 160 records are retained; HorusMediaLoader.getStartupTrace() returns copies.\n\nH init/prep/boot, H wait DOM/dom, H cfg start/ok/error and H ctl start/ok/error identify startup waits. An ok means that orchestration promise fulfilled, including any existing handled fallback. H privacy start/ok/reject is the existing privacy prerequisite. CF start/frame/cfg/sdk/challenge/token/verify show verification stages. Token and verify are NOT authorization. CF pass requires the existing server-verified nonce-bound outcome. CF reject/error/timeout are distinct outcomes. Horus start is admitted engine dispatch; H handoff is release delegation. G sdk start/ok/error is script loading, not an ad request. G req/res/render/empty/load are official GPT events for registered Horus slots; #N is a document-local alias. Render is markup delivery, not guaranteed viewability. V req/start/error indicate the IMA invocation, STARTED event and numeric error.\n\nThe current ready gate script can announce BOOT to its referring HTTPS parent without waiting for unrelated iframe resources to complete. Only the current exact-origin frame and protocol can trigger its one-time HELLO. BOOT and PROGRESS never authorize ads. Nonce, server verification, config/privacy/Click Guard controls and deadlines remain unchanged. Older/no-referrer frames retain onload fallback.\n\nThis narrow loading correction is reproducible with a held child resource. It does not establish the cause of the user's five-minute session. DOM readiness, slow static responses, SDK readiness and release handoff are now observable, not bypassed. Video demand/303 remains separate. No WAF or monetization setting was changed.\n''')
