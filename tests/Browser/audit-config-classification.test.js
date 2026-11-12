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
    documentPreparation = false,
    hideReferrer = false,
    deferVerification = false,
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
    let releaseVerification;
    let clock = 1000;

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
        referrer: hideReferrer ? '' : parentOrigin + '/',
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
        Date: class extends Date { static now() { return clock; } },
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
                const response = { ok: next.status === 200, status: next.status, json: async () => next.body };
                if (deferVerification) return new Promise(resolve => { releaseVerification = () => resolve(response); });
                return response;
            }
            const response = { ok: config !== null, json: async () => config };
            if (deferConfig) return new Promise(resolve => { releaseConfig = () => resolve(response); });
            return response;
        },
    });
    context.window = {
        crypto: { randomUUID: () => 'd4b420a9-217b-413b-bf1d-8bf0a1825a7d' },
        location: { origin: GATE_ORIGIN, hash: documentPreparation ? '#prepare' : '' },
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
        releaseVerification: () => releaseVerification(),
        elapse: ms => { clock += ms; },
        get renderCount() { return renderCount; },
        get resetCount() { return resetCount; },
        get renderOptions() { return renderOptions; },
    };
}


test('audit lifecycle: missing static gate config is classified as DENIED, not retryable configuration failure',async()=>{
 const h=createHarness({config:null});await h.hello();
 assert.equal(h.messages.at(-1).payload.type,'HORUS_TRAFFIC_GATE_DENIED');
 assert.equal(h.verificationCalls.length,0);
});
