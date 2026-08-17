import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const gateSource = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');
const ALWAYS_PASS_INVISIBLE = '1x00000000000000000000BB';
const ALWAYS_FAIL_INVISIBLE = '2x00000000000000000000BB';
const NONCE = 'Task49Nonce_0123456789abcdef';
const SITE_KEY = 'HM_TASK49_SITE';
const GATE_ORIGIN = 'https://verify.horusmedia.net';
const ADMIN_ORIGIN = 'https://app.horusmedia.net';

function configFor(hostname, turnstileSiteKey = '0x4AAAAA_task49_public_key') {
    return {
        siteKey: SITE_KEY,
        allowedHostnames: [hostname],
        trafficGate: {
            enabled: true,
            provider: 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY',
            gateOrigin: GATE_ORIGIN,
            siteKey: turnstileSiteKey,
            policy: 'BALANCED',
            readiness: 'READY',
            timings: {
                initialWaitMs: 1500,
                maxWaitMs: 6000,
                retryIntervalMs: 1500,
            },
            activityRecoveryEnabled: true,
        },
    };
}

function createHarness({
    parentOrigin = 'https://publisher.example',
    allowedHostname = 'publisher.example',
    behavior = 'pass',
    config = configFor(allowedHostname),
} = {}) {
    const messages = [];
    const timers = [];
    const elements = new Map();
    let messageHandler = null;
    let renderOptions = null;
    let renderCount = 0;
    let resetCount = 0;

    const parent = {
        postMessage(payload, targetOrigin) {
            messages.push({ payload, targetOrigin });
        },
    };

    const turnstile = {
        render(_container, options) {
            renderCount += 1;
            renderOptions = options;
            queueMicrotask(() => {
                if (behavior === 'pass') options.callback('TOKEN_MUST_NOT_LEAVE_FRAME');
                if (behavior === 'fail') options['error-callback']('300001');
                if (behavior === 'timeout') options['timeout-callback']();
                if (behavior === 'unsupported') options['unsupported-callback']();
            });
            return 'task49-widget';
        },
        reset() {
            resetCount += 1;
            queueMicrotask(() => {
                if (behavior === 'fail') renderOptions['error-callback']('300001');
            });
        },
        remove() {},
    };

    const document = {
        documentElement: { dataset: {} },
        head: {
            appendChild(script) {
                elements.set(script.id, script);
                context.window.turnstile = turnstile;
                script.listeners.load?.();
                return script;
            },
        },
        getElementById(id) {
            if (id === 'horus-turnstile') return { id };
            return elements.get(id) ?? null;
        },
        createElement(tag) {
            assert.equal(tag, 'script');
            return {
                listeners: {},
                addEventListener(type, callback) { this.listeners[type] = callback; },
            };
        },
    };

    function fakeSetTimeout(callback, delay) {
        const timer = { callback, delay, active: true };
        timers.push(timer);
        return timer;
    }

    function fakeClearTimeout(timer) {
        if (timer) timer.active = false;
    }

    const context = vm.createContext({
        console,
        document,
        URL,
        encodeURIComponent,
        Number,
        String,
        Promise,
        queueMicrotask,
        setTimeout: fakeSetTimeout,
        clearTimeout: fakeClearTimeout,
        fetch: async () => ({
            ok: config !== null,
            async json() { return config; },
        }),
    });
    context.window = {
        location: { origin: GATE_ORIGIN },
        parent,
        turnstile: undefined,
        addEventListener(type, callback) {
            if (type === 'message') messageHandler = callback;
        },
    };

    vm.runInContext(gateSource, context, { filename: 'horus-traffic-gate.js' });

    async function flush() {
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
    }

    async function hello(overrides = {}) {
        assert.ok(messageHandler, 'gate must install a message listener');
        await messageHandler({
            source: parent,
            origin: parentOrigin,
            data: {
                type: 'HORUS_TRAFFIC_GATE_HELLO',
                protocolVersion: 1,
                pageNonce: NONCE,
                sitePublicKey: SITE_KEY,
                ...overrides,
            },
        });
        await flush();
    }

    async function runTimer(delay) {
        const timer = timers.find((candidate) => candidate.active && candidate.delay === delay);
        assert.ok(timer, `expected active ${delay}ms timer`);
        timer.active = false;
        timer.callback();
        await flush();
    }

    return {
        messages,
        timers,
        hello,
        runTimer,
        get renderCount() { return renderCount; },
        get resetCount() { return resetCount; },
        get renderOptions() { return renderOptions; },
    };
}

test('authorized Site origin receives READY then PASS with the exact nonce and no token transport', async () => {
    const harness = createHarness({ behavior: 'pass' });
    await harness.hello();

    assert.equal(harness.renderCount, 1);
    assert.deepEqual(harness.messages.map(({ payload }) => payload.type), [
        'HORUS_TRAFFIC_GATE_READY',
        'HORUS_TRAFFIC_GATE_PASS',
    ]);
    for (const { payload, targetOrigin } of harness.messages) {
        assert.equal(payload.protocolVersion, 1);
        assert.equal(payload.pageNonce, NONCE);
        assert.equal(targetOrigin, 'https://publisher.example');
        assert.equal(JSON.stringify(payload).includes('TOKEN_MUST_NOT_LEAVE_FRAME'), false);
    }
    assert.equal(harness.renderOptions['response-field'], false);
    assert.equal(harness.renderOptions.retry, 'never');
});

test('unauthorized parent origin is denied before Turnstile is loaded or rendered', async () => {
    const harness = createHarness({
        parentOrigin: 'https://attacker.example',
        allowedHostname: 'publisher.example',
        behavior: 'pass',
    });
    await harness.hello();

    assert.equal(harness.renderCount, 0);
    assert.equal(harness.messages.length, 1);
    assert.equal(harness.messages[0].payload.type, 'HORUS_TRAFFIC_GATE_DENIED');
    assert.equal(harness.messages[0].payload.state, 'DENIED');
    assert.equal(harness.messages[0].targetOrigin, 'https://attacker.example');
});

test('Cloudflare Invisible always-fail test key exercises one bounded retry then ERROR', async () => {
    const harness = createHarness({ parentOrigin: ADMIN_ORIGIN, behavior: 'fail' });
    await harness.hello({
        testMode: true,
        candidateSiteKey: ALWAYS_FAIL_INVISIBLE,
    });

    assert.equal(harness.renderOptions.sitekey, ALWAYS_FAIL_INVISIBLE);
    assert.equal(harness.messages[0].payload.type, 'HORUS_TRAFFIC_GATE_READY');
    assert.equal(harness.messages.some(({ payload }) => payload.type === 'HORUS_TRAFFIC_GATE_ERROR'), false);

    await harness.runTimer(1500);

    assert.equal(harness.resetCount, 1);
    const error = harness.messages.at(-1);
    assert.equal(error.payload.type, 'HORUS_TRAFFIC_GATE_ERROR');
    assert.equal(error.payload.state, 'ERROR');
    assert.equal(error.payload.code, '300001');
    assert.equal(error.targetOrigin, ADMIN_ORIGIN);
});

test('Turnstile timeout callback produces a bounded TIMEOUT message', async () => {
    const harness = createHarness({ behavior: 'timeout' });
    await harness.hello();

    const timeout = harness.messages.at(-1);
    assert.equal(timeout.payload.type, 'HORUS_TRAFFIC_GATE_TIMEOUT');
    assert.equal(timeout.payload.state, 'TIMEOUT');
    assert.equal(timeout.payload.pageNonce, NONCE);
});

test('Admin test mode is denied outside canonical Admin origin and accepts official Invisible pass key at Admin origin', async () => {
    const denied = createHarness({ parentOrigin: 'https://publisher.example', behavior: 'pass' });
    await denied.hello({ testMode: true, candidateSiteKey: ALWAYS_PASS_INVISIBLE });
    assert.equal(denied.renderCount, 0);
    assert.equal(denied.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_DENIED');
    assert.equal(denied.messages.at(-1).payload.category, 'ADMIN_TEST_ORIGIN_REQUIRED');

    const allowed = createHarness({ parentOrigin: ADMIN_ORIGIN, behavior: 'pass' });
    await allowed.hello({ testMode: true, candidateSiteKey: ALWAYS_PASS_INVISIBLE });
    assert.equal(allowed.renderOptions.sitekey, ALWAYS_PASS_INVISIBLE);
    assert.equal(allowed.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_PASS');
    assert.equal(allowed.messages.at(-1).targetOrigin, ADMIN_ORIGIN);
});

test('invalid or mismatched Site configuration never authorizes a challenge', async () => {
    const wrongSite = configFor('publisher.example');
    wrongSite.siteKey = 'OTHER_SITE';
    const harness = createHarness({ config: wrongSite, behavior: 'pass' });
    await harness.hello();

    assert.equal(harness.renderCount, 0);
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_DENIED');
});
