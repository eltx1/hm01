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
            provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED',
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
    verificationReplies = null,
    config = configFor(allowedHostname),
    deferConfig = false,
    deferLibrary = false,
    libraryError = false,
} = {}) {
    const messages = [];
    const verificationCalls = [];
    const timers = [];
    const elements = new Map();
    let messageHandler = null;
    let renderOptions = null;
    let renderCount = 0;
    let resetCount = 0;
    const scriptRequests = [];
    let releaseConfig;
    let releaseLibrary;

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
                scriptRequests.push(script.src);
                releaseLibrary = () => {
                    if (libraryError) script.listeners.error?.();
                    else {
                        context.window.turnstile = turnstile;
                        script.listeners.load?.();
                    }
                };
                if (!deferLibrary) releaseLibrary();
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
        AbortController,
        document,
        URL,
        encodeURIComponent,
        Number,
        String,
        Promise,
        queueMicrotask,
        setTimeout: fakeSetTimeout,
        clearTimeout: fakeClearTimeout,
        fetch: async (url, options) => {
            if (url.startsWith('https://siteverify.')) {
                verificationCalls.push(JSON.parse(options.body));
                const next = verificationReplies?.shift() ?? { status: 200, body: { success: true, pageNonce: NONCE } };
                return { ok: next.status === 200, status: next.status, json: async () => next.body };
            }
            const response = { ok: config !== null, json: async () => config };
            if (deferConfig) return new Promise(resolve => { releaseConfig = () => resolve(response); });
            return response;
        },
    });
    context.window = {
        crypto: { randomUUID: () => 'd4b420a9-217b-413b-bf1d-8bf0a1825a7d' },
        location: { origin: GATE_ORIGIN },
        parent,
        turnstile: undefined,
        addEventListener(type, callback) {
            if (type === 'message') messageHandler = callback;
        },
    };

    vm.runInContext(gateSource, context, { filename: 'horus-traffic-gate.js' });

    async function flush() {
        for (let index = 0; index < 12; index++) await Promise.resolve();
    }

    async function hello(overrides = {}) {
        assert.ok(messageHandler, 'gate must install a message listener');
        await messageHandler({
            source: parent,
            origin: parentOrigin,
            data: {
                type: 'HORUS_TRAFFIC_GATE_HELLO',
                protocolVersion: 2,
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
        verificationCalls,
        timers,
        hello,
        runTimer,
        flush,
        scriptRequests,
        releaseConfig: () => releaseConfig(),
        releaseLibrary: () => releaseLibrary(),
        get renderCount() { return renderCount; },
        get resetCount() { return resetCount; },
        get renderOptions() { return renderOptions; },
    };
}

test('authorized Site origin receives READY then PASS with the exact nonce and no token exposed to parent', async () => {
    const harness = createHarness({ behavior: 'pass' });
    await harness.hello();

    assert.equal(harness.renderCount, 1);
    assert.deepEqual(harness.messages.map(({ payload }) => payload.type), [
        'HORUS_TRAFFIC_GATE_READY',
        'HORUS_TRAFFIC_GATE_PASS',
    ]);
    for (const { payload, targetOrigin } of harness.messages) {
        assert.equal(payload.protocolVersion, 2);
        assert.equal(payload.pageNonce, NONCE);
        assert.equal(targetOrigin, 'https://publisher.example');
        assert.equal(JSON.stringify(payload).includes('TOKEN_MUST_NOT_LEAVE_FRAME'), false);
    }
    assert.equal(harness.renderOptions['response-field'], false);
    assert.equal(harness.renderOptions.retry, 'never');
});

test('unauthorized parent origin cannot render a challenge or verify despite a prepared library', async () => {
    const harness = createHarness({
        parentOrigin: 'https://attacker.example',
        allowedHostname: 'publisher.example',
        behavior: 'pass',
    });
    await harness.hello();

    assert.equal(harness.renderCount, 0);
    assert.equal(harness.verificationCalls.length, 0);
    assert.equal(harness.messages.length, 1);
    assert.equal(harness.messages[0].payload.type, 'HORUS_TRAFFIC_GATE_DENIED');
    assert.equal(harness.messages[0].payload.state, 'DENIED');
    assert.equal(harness.messages[0].targetOrigin, 'https://attacker.example');
});

test('Turnstile library loads while config is pending but no challenge starts before authorization', async () => {
    const harness = createHarness({ deferConfig: true });
    const boot = harness.hello();
    await harness.flush();
    assert.deepEqual(harness.scriptRequests, ['https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit']);
    assert.equal(harness.renderCount, 0);
    assert.equal(harness.verificationCalls.length, 0);
    assert.equal(harness.messages.length, 0);
    harness.releaseConfig();
    await boot;
    assert.equal(harness.renderCount, 1);
    assert.equal(harness.verificationCalls.length, 1);
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_PASS');
});

test('authorized config still waits for the parallel library before rendering', async () => {
    const harness = createHarness({ deferLibrary: true });
    const boot = harness.hello();
    await harness.flush();
    assert.equal(harness.renderCount, 0);
    assert.equal(harness.verificationCalls.length, 0);
    harness.releaseLibrary();
    await boot;
    assert.equal(harness.renderCount, 1);
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_PASS');
});

test('parallel library rejection is handled while config is pending and never authorizes a challenge', async () => {
    for (const allowedHostname of ['publisher.example', 'other.example']) {
        const harness = createHarness({ deferConfig: true, libraryError: true, allowedHostname });
        const boot = harness.hello();
        await harness.flush();
        harness.releaseConfig();
        await boot;
        assert.equal(harness.renderCount, 0);
        assert.equal(harness.verificationCalls.length, 0);
        assert.equal(harness.messages.at(-1).payload.type,
            allowedHostname === 'publisher.example' ? 'HORUS_TRAFFIC_GATE_ERROR' : 'HORUS_TRAFFIC_GATE_DENIED');
    }
});

test('late config or library completion cannot revive an expired parallel boot', async () => {
    for (const pending of ['config', 'library']) {
        const harness = createHarness({ deferConfig: pending === 'config', deferLibrary: pending === 'library' });
        const boot = harness.hello();
        await harness.flush();
        const deadline = harness.timers.find(timer => timer.active && timer.delay > 4000);
        await harness.runTimer(deadline.delay);
        if (pending === 'config') harness.releaseConfig();
        else harness.releaseLibrary();
        await boot;
        assert.equal(harness.renderCount, 0);
        assert.equal(harness.verificationCalls.length, 0);
        assert.deepEqual(harness.messages.map(({ payload }) => payload.type), ['HORUS_TRAFFIC_GATE_TIMEOUT']);
    }
});

test('malformed HELLO never starts parallel preparation', async () => {
    const harness = createHarness();
    await harness.hello({ pageNonce: 'bad' });
    assert.equal(harness.scriptRequests.length, 0);
    assert.equal(harness.renderCount, 0);
    assert.equal(harness.verificationCalls.length, 0);
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

 test('a rejected server verification never emits PASS even after a client callback', async () => {
    const harness = createHarness({ verificationReplies: [{ status: 422, body: { success: false, retryable: false } }] });
    await harness.hello();
    assert.equal(harness.verificationCalls.length, 1);
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_ERROR');
    assert.equal(harness.messages.some(item => item.payload.type === 'HORUS_TRAFFIC_GATE_PASS'), false);
});
test('transient verification is retried once with the same token and idempotency key', async () => {
    const harness = createHarness({ verificationReplies: [{ status: 503, body: { success: false, retryable: true } }] });
    await harness.hello();
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_READY');
    await harness.runTimer(1500);
    assert.equal(harness.verificationCalls.length, 2);
    assert.deepEqual(harness.verificationCalls[0], harness.verificationCalls[1]);
    assert.equal(harness.messages.at(-1).payload.serverVerified, true);
});
test('two transient failures terminate without an unbounded request loop', async () => {
    const fail = { status: 503, body: { success: false, retryable: true } };
    const harness = createHarness({ verificationReplies: [fail, fail] });
    await harness.hello();
    await harness.runTimer(1500);
    assert.equal(harness.verificationCalls.length, 2);
    assert.equal(harness.messages.at(-1).payload.type, 'HORUS_TRAFFIC_GATE_ERROR');
});
