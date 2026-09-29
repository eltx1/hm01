import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { startupTraceRuntime } from '../../scripts/loader-startup-trace.mjs';

function harness() {
    const printed = [], timers = new Map(); let clock = 0, id = 0;
    const c = { state: {}, VERSION: '2.0.0', performance: { now: () => clock },
        console: { log: (...args) => printed.push(args) },
        setTimeout: (fn) => { timers.set(++id, fn); return id; },
        clearTimeout: (key) => timers.delete(key) };
    c.window = c; vm.createContext(c); vm.runInContext(startupTraceRuntime, c);
    return { c, printed, timers, tick: n => { clock = n; } };
}
test('trace is allowlisted, bounded, copied on read and stripped of private fields', () => {
    const h = harness(); h.tick(125);
    h.c.startupTrace('CF token', { token: 'secret', nonce: 'nonce', url: 'https://private', code: 303 });
    h.c.startupTrace('unknown secret event', { code: 1 });
    h.c.startupTrace('CF token', { code: 303 });
    assert.equal(h.printed.length, 1);
    const a = h.c.startupSnapshot(); assert.equal(a.events[0].ms, 125);
    assert.equal(JSON.stringify(a).includes('secret'), false);
    assert.equal(Object.hasOwn(a.events[0], 'token'), false);
    a.events[0].phase = 'spoof';
    assert.equal(h.c.startupSnapshot().events[0].phase, 'CF token');
    for (let i = 0; i < 500; i++) h.c.startupTrace('GPT request', { slot: i });
    assert.equal(h.c.startupSnapshot().events.length, 160);
    assert.ok(h.c.startupSnapshot().dropped > 0);
});
test('passive slow-stage observation does not finish an unresolved request or change policy', async () => {
    const h = harness(); let release, done = false;
    const p = new Promise(resolve => { release = resolve; });
    const q = h.c.traceStartupWait(p, 'config', 'CFG ready').then(() => { done = true; });
    h.tick(300000); for (const fn of h.timers.values()) fn();
    await Promise.resolve(); assert.equal(done, false);
    assert.equal(h.c.startupSnapshot().events[0].phase, 'CFG wait');
    release('unchanged value'); await q;
    assert.equal(done, true); assert.equal(h.timers.size, 0);
});
test('one listener set tracks actual GPT events without conflating call, response, injection or onload', () => {
    const h = harness(), listeners = new Map(), element = {}, slot = {};
    h.c.startupSlotNumber(slot, element);
    const service = { addEventListener: (name, fn) => listeners.set(name, fn) };
    h.c.traceGptService(service); h.c.traceGptService(service);
    assert.equal(listeners.size, 4);
    assert.equal(h.c.startupSnapshot().events.length, 0);
    ['slotRequested', 'slotResponseReceived', 'slotRenderEnded', 'slotOnload'].forEach((name, i) => {
        h.tick((i+1)*100); listeners.get(name)({ slot, isEmpty: false, advertiserId: 'private' });
    });
    const events = h.c.startupSnapshot().events;
    assert.deepEqual(Array.from(events, e => e.phase), ['GPT request', 'GPT response', 'GPT render', 'GPT onload']);
    assert.equal(new Set(Array.from(events, e => e.slot)).size, 1);
    assert.equal(events[0].slot, h.c.startupSlotNumber(element));
    assert.equal(JSON.stringify(events).includes('private'), false);
});
