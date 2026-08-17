import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';

const baseLoaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const loaderSource = applyTrafficGateTransform(baseLoaderSource);
const GATE_ORIGIN = 'https://verify.horusmedia.net';

const openControls = () => ({
    adServingDisabled: false,
    gamDisabled: false,
    prebidDisabled: false,
    directJsDisabled: false,
    nativeDemandDisabled: false,
    trafficGateDisabled: false,
});

function placement(code, renderer, overrides = {}) {
    return {
        code,
        type: 'DISPLAY',
        status: 'active',
        enabled: true,
        renderer,
        rendererConflict: false,
        gamEnabled: renderer === 'GAM',
        prebidStandaloneEnabled: renderer === 'PREBID_STANDALONE',
        directJsEnabled: renderer === 'DIRECT_JS',
        nativeEnabled: renderer === 'DIRECT_JS',
        adUnitPath: renderer === 'GAM' ? `/123456789/${code}` : null,
        sizes: [[300, 250]],
        responsiveMappings: [],
        targeting: {},
        lazyLoad: { enabled: false },
        refresh: { enabled: false, intervalSeconds: null, limit: null },
        collapseEmptyDiv: true,
        safeFrame: false,
        outOfPageFormat: null,
        ...overrides,
    };
}

function directCandidate(code = 'direct_slot') {
    return {
        network: 'MGID',
        priority: 10,
        gamManaged: false,
        tag: {
            executionMode: 'STRUCTURED',
            scripts: [{
                url: `https://ads.example.com/${code}.js`,
                async: true,
                defer: false,
                dedupeKey: code,
                attributes: {},
            }],
            container: { element: 'div', id: `${code}-container`, class: 'provider-zone', attributes: {} },
            initialization: { type: 'MGID_QUEUE_LOAD', parameters: {} },
            render: { timeoutMs: 1, assumeLoadedIsSuccess: true },
        },
    };
}

function trafficGate(policy = 'STRICT', overrides = {}) {
    const enabled = overrides.enabled ?? true;
    return {
        enabled,
        provider: 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY',
        gateOrigin: GATE_ORIGIN,
        siteKey: '1x00000000000000000000AA',
        policy,
        timings: {
            initialWaitMs: 500,
            maxWaitMs: 2000,
            retryIntervalMs: 500,
            ...(overrides.timings || {}),
        },
        activityRecoveryEnabled: overrides.activityRecoveryEnabled ?? true,
        readiness: enabled ? 'READY' : 'DISABLED',
        ...overrides,
    };
}

function baseConfig({ gam = true, standalone = false, direct = false, bridgePrebid = false, policy = 'STRICT', gate = true, refresh = false } = {}) {
    const placements = [];
    if (gam) placements.push(placement('gam_slot', 'GAM', refresh ? { refresh: { enabled: true, intervalSeconds: 30, limit: 2 } } : {}));
    if (standalone) placements.push(placement('prebid_slot', 'PREBID_STANDALONE'));
    if (direct) placements.push(placement('direct_slot', 'DIRECT_JS'));
    const prebidEnabled = standalone || bridgePrebid;
    const prebidCode = standalone ? 'prebid_slot' : 'gam_slot';
    const directPlacements = direct ? {
        direct_slot: { enabled: true, candidates: [directCandidate()], house: null },
    } : {};

    return {
        schemaVersion: 4,
        siteKey: 'HM_GATE_TEST',
        servingMode: standalone && !gam ? 'HORUS_DIRECT' : 'HORUS_GAM',
        gamNetworkCode: gam ? '123456789' : null,
        configVersion: 50,
        status: 'active',
        immediatePause: false,
        debug: true,
        controls: openControls(),
        allowedHostnames: ['publisher.example'],
        loader: { version: '2.0.0', cacheBust: 50 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: false },
        pageTargeting: {},
        trafficGate: trafficGate(policy, { enabled: gate }),
        prebid: {
            enabled: prebidEnabled,
            deliveryMode: standalone ? 'STANDALONE' : 'GAM_BRIDGE',
            build: { version: '11.15.0', url: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js' },
            auction: { timeoutMs: 10, priceGranularity: 'medium', currency: 'USD', bidderSequence: 'fixed' },
            delivery: { gamFallback: true, refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } },
            directRender: { implemented: standalone, supportedMediaTypes: ['banner'], sandbox: ['allow-scripts'] },
            adUnits: prebidEnabled ? [{
                code: prebidCode,
                mediaTypes: { banner: { sizes: [[300, 250]] } },
                bids: [{ bidder: 'msft', params: { placement_id: 'task-50' } }],
            }] : [],
        },
        directDemand: { enabled: direct, fallbackOrder: ['MGID'], placements: directPlacements },
        nativeDemand: { enabled: direct, fallbackOrder: ['MGID'], placements: directPlacements },
        placements,
    };
}

function domElement(code) {
    const attributes = { 'data-placement': code };
    const children = [];
    const node = {
        id: '',
        tagName: 'DIV',
        className: 'hm-ad',
        style: { values: {}, setProperty(name, value) { this.values[name] = String(value); } },
        childNodes: children,
        children,
        parentNode: null,
        innerHTML: '',
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(child) { child.parentNode = node; children.push(child); return child; },
        removeChild(child) {
            const index = children.indexOf(child);
            if (index >= 0) children.splice(index, 1);
            child.parentNode = null;
            return child;
        },
        contains(child) { return children.includes(child); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
    };
    return node;
}

function createHarness(config, {
    globalControls: initialGlobalControls = openControls(),
    gateAutoResponse = null,
    gateAutoDelayMs = 0,
    deferredTcf = false,
    timerScale = 0.02,
    iframeFailure = false,
} = {}) {
    const metrics = {
        fetches: [],
        configFetches: 0,
        globalFetches: 0,
        gateFrames: 0,
        gateFrameRemovals: 0,
        hellos: [],
        gptScripts: 0,
        prebidScripts: 0,
        directScripts: 0,
        gamSlots: 0,
        gamRequests: 0,
        prebidAuctions: 0,
        prebidRenders: 0,
        providerInitializations: 0,
        localStorageWrites: 0,
        privacyStarted: 0,
        privacyResolved: 0,
    };
    const elements = config.placements.map((item) => domElement(item.code));
    let globalControls = structuredClone(initialGlobalControls);
    let gateFrame = null;
    let mutationCallback = null;
    let intervalSequence = 0;
    const intervals = new Map();
    const listeners = {};
    const nativeSetTimeout = setTimeout;
    const nativeClearTimeout = clearTimeout;
    const scaledSetTimeout = (callback, delay = 0, ...args) => nativeSetTimeout(callback, Math.max(0, Number(delay) * timerScale), ...args);
    const loaderScript = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: {
            siteKey: config.siteKey,
            configBase: 'https://cdn.horusmedia.net/configs',
            environment: 'production',
            configVersion: String(config.configVersion),
        },
        attributes: {
            'data-site-key': config.siteKey,
            'data-config-base': 'https://cdn.horusmedia.net/configs',
            'data-environment': 'production',
            'data-config-version': String(config.configVersion),
        },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
    };

    const immediateQueue = { push(callback) { callback(); return 1; } };
    const pubads = {
        disableInitialLoad() {},
        enableSingleRequest() {},
        setTargeting() { return this; },
        setPrivacySettings() { return this; },
        refresh() { metrics.gamRequests += 1; },
        addEventListener() {},
    };
    const googletag = {
        cmd: immediateQueue,
        apiReady: false,
        pubadsReady: false,
        pubads() { return pubads; },
        sizeMapping() { return { addSize() { return this; }, build() { return []; } }; },
        defineSlot() {
            metrics.gamSlots += 1;
            const slot = {
                setTargeting() { return slot; },
                defineSizeMapping() { return slot; },
                setForceSafeFrame() { return slot; },
                setCollapseEmptyDiv() { return slot; },
                addService() { return slot; },
            };
            return slot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { googletag.apiReady = true; },
        display() {},
        enums: { OutOfPageFormat: {} },
    };

    function dispatchGateMessage(type, nonce, source, extra = {}) {
        const event = {
            type: 'message',
            origin: GATE_ORIGIN,
            source,
            data: { type, protocolVersion: 1, pageNonce: nonce, ...extra },
        };
        (listeners.message || []).slice().forEach((callback) => callback(event));
    }

    function genericNode(tagName) {
        const attributes = {};
        const children = [];
        const node = {
            id: '',
            tagName: String(tagName).toUpperCase(),
            className: '',
            style: { values: {}, setProperty(name, value) { this.values[name] = String(value); } },
            attributes,
            childNodes: children,
            children,
            parentNode: null,
            async: false,
            defer: false,
            src: '',
            title: '',
            onload: null,
            onerror: null,
            innerHTML: '',
            setAttribute(name, value) { attributes[name] = String(value); },
            getAttribute(name) { return attributes[name] ?? null; },
            addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            removeEventListener() {},
            appendChild(child) { child.parentNode = this; children.push(child); return child; },
            removeChild(child) {
                const index = children.indexOf(child);
                if (index >= 0) children.splice(index, 1);
                child.parentNode = null;
                return child;
            },
            querySelector() { return null; },
            querySelectorAll() { return []; },
        };
        if (String(tagName).toLowerCase() === 'iframe') {
            const frameWindow = {
                document: {},
                postMessage(payload, targetOrigin) {
                    if (node.getAttribute('data-hm-traffic-gate') !== '1') return;
                    metrics.hellos.push({ payload: structuredClone(payload), targetOrigin });
                    if (gateAutoResponse) {
                        scaledSetTimeout(() => dispatchGateMessage(`HORUS_TRAFFIC_GATE_${gateAutoResponse}`, payload.pageNonce, frameWindow), gateAutoDelayMs);
                    }
                },
            };
            node.contentWindow = frameWindow;
            node.sandbox = { add() {} };
        }
        return node;
    }

    const root = genericNode('html');
    const originalRootAppend = root.appendChild.bind(root);
    root.appendChild = (node) => {
        originalRootAppend(node);
        if (node.getAttribute?.('data-hm-traffic-gate') === '1') {
            metrics.gateFrames += 1;
            gateFrame = node;
            queueMicrotask(() => {
                if (iframeFailure) node.onerror?.(new Error('blocked'));
                else node.onload?.();
            });
        }
        return node;
    };
    const originalRootRemove = root.removeChild.bind(root);
    root.removeChild = (node) => {
        if (node.getAttribute?.('data-hm-traffic-gate') === '1') metrics.gateFrameRemovals += 1;
        return originalRootRemove(node);
    };

    const document = {
        currentScript: loaderScript,
        readyState: 'complete',
        visibilityState: 'visible',
        documentElement: root,
        body: root,
        activeElement: null,
        head: {
            appendChild(node) {
                if (node.getAttribute?.('data-hm-gpt') === '1') {
                    metrics.gptScripts += 1;
                    queueMicrotask(() => node.onload?.());
                } else if (node.getAttribute?.('data-hm-prebid') === '1') {
                    metrics.prebidScripts += 1;
                    const winner = {
                        adId: `winner-${metrics.prebidScripts}`,
                        auctionId: `auction-${metrics.prebidScripts}`,
                        mediaType: 'banner',
                        ttl: 300,
                        responseTimestamp: Date.now(),
                        width: 300,
                        height: 250,
                    };
                    sandbox.pbjs = {
                        que: immediateQueue,
                        setConfig() {},
                        onEvent() {},
                        removeAdUnit() {},
                        addAdUnits() {},
                        requestBids(options) {
                            metrics.prebidAuctions += 1;
                            const code = options.adUnitCodes[0];
                            queueMicrotask(() => options.bidsBackHandler({ [code]: { bids: [winner] } }, false, winner.auctionId));
                        },
                        setTargetingForGPTAsync() {},
                        getBidResponsesForAdUnitCode() { return { bids: [winner] }; },
                        getHighestCpmBids() { return [winner]; },
                        renderAd() { metrics.prebidRenders += 1; },
                    };
                    queueMicrotask(() => node.onload?.());
                } else if (node.getAttribute?.('data-hm-direct-script')) {
                    metrics.directScripts += 1;
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            },
        },
        createElement(tagName) { return genericNode(tagName); },
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return elements;
            if (selector === '.hm-native[data-placement]') return [];
            if (selector === 'script[data-site-key]') return [loaderScript];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver {
        constructor(callback) { mutationCallback = callback; }
        observe() {}
        disconnect() {}
    }
    class Event {
        constructor(type) { this.type = type; this.isTrusted = false; this.key = ''; }
    }

    const sandbox = {
        console,
        URL,
        Promise,
        Object,
        JSON,
        Math,
        Date,
        Number,
        String,
        Boolean,
        Array,
        WeakSet,
        Uint8Array,
        setTimeout: scaledSetTimeout,
        clearTimeout: nativeClearTimeout,
        setInterval(callback) { const id = ++intervalSequence; intervals.set(id, callback); return id; },
        clearInterval(id) { intervals.delete(id); },
        queueMicrotask,
        MutationObserver,
        Event,
        googletag,
        document,
        navigator: { globalPrivacyControl: false },
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { state: null, pushState() {}, replaceState() {} },
        scrollX: 0,
        scrollY: 0,
        pageXOffset: 0,
        pageYOffset: 0,
        crypto: {
            getRandomValues(bytes) {
                for (let index = 0; index < bytes.length; index += 1) bytes[index] = (index * 17 + 11) % 256;
                return bytes;
            },
        },
        localStorage: {
            getItem() { return null; },
            setItem() { metrics.localStorageWrites += 1; },
        },
        fetch: async (url) => {
            metrics.fetches.push(String(url));
            if (String(url).includes('/_global/control.json')) {
                metrics.globalFetches += 1;
                return { ok: true, json: async () => ({ schemaVersion: 2, controls: structuredClone(globalControls) }) };
            }
            metrics.configFetches += 1;
            return { ok: true, json: async () => structuredClone(config) };
        },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        removeEventListener(name, callback) {
            if (!listeners[name]) return;
            listeners[name] = listeners[name].filter((candidate) => candidate !== callback);
        },
        dispatchEvent(event) { (listeners[event.type] || []).slice().forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: true,
    };
    if (deferredTcf) {
        sandbox.__tcfapi = (command, version, callback) => {
            assert.equal(command, 'addEventListener');
            assert.equal(version, 2);
            metrics.privacyStarted += 1;
            metrics.tcfCallback = callback;
        };
    }
    Object.defineProperty(sandbox, '_mgq', {
        configurable: true,
        get() { return this.__mgq; },
        set(value) {
            this.__mgq = value;
            const originalPush = value.push.bind(value);
            value.push = (...args) => { metrics.providerInitializations += 1; return originalPush(...args); };
        },
    });
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader-task-50.js' });

    return {
        sandbox,
        metrics,
        elements,
        get gateFrame() { return gateFrame; },
        setGlobalControls(value) { globalControls = structuredClone(value); },
        sendGate(type, extra = {}, overrides = {}) {
            assert.ok(gateFrame, 'gate frame must exist before sending a result');
            const hello = metrics.hellos.at(-1);
            assert.ok(hello, 'HELLO must be sent before a gate result');
            const origin = overrides.origin ?? GATE_ORIGIN;
            const source = overrides.source ?? gateFrame.contentWindow;
            const nonce = overrides.nonce ?? hello.payload.pageNonce;
            const event = {
                type: 'message', origin, source,
                data: { type: `HORUS_TRAFFIC_GATE_${type}`, protocolVersion: overrides.protocolVersion ?? 1, pageNonce: nonce, ...extra },
            };
            (listeners.message || []).slice().forEach((callback) => callback(event));
        },
        dispatchTrusted(type, extra = {}) {
            const event = { type, isTrusted: true, key: type === 'keydown' ? 'A' : '', ...extra };
            (listeners[type] || []).slice().forEach((callback) => callback(event));
        },
        dispatchSynthetic(type) { sandbox.dispatchEvent(new sandbox.Event(type)); },
        addPlacement(code, renderer = 'GAM') {
            const node = domElement(code);
            elements.push(node);
            config.placements.push(placement(code, renderer));
            return node;
        },
        triggerMutation(nodes) { mutationCallback?.([{ addedNodes: nodes, removedNodes: [] }]); },
        runRefreshTimers() { [...intervals.values()].forEach((callback) => callback()); },
        reevaluateLoader() { vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader-task-50-duplicate.js' }); },
        async flush() { await new Promise((resolve) => setImmediate(resolve)); },
    };
}

function assertNoMonetization(metrics) {
    assert.equal(metrics.gptScripts, 0);
    assert.equal(metrics.prebidScripts, 0);
    assert.equal(metrics.directScripts, 0);
    assert.equal(metrics.gamSlots, 0);
    assert.equal(metrics.gamRequests, 0);
    assert.equal(metrics.prebidAuctions, 0);
    assert.equal(metrics.providerInitializations, 0);
}

test('gate disabled preserves normal Loader behavior without creating an iframe', async () => {
    const runtime = createHarness(baseConfig({ gate: false, gam: true }));
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gateFrames, 0);
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.gamSlots, 1);
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'DISABLED');
});

test('before PASS every monetization engine remains at zero, then one PASS releases GAM, standalone Prebid and Direct JS', async () => {
    const config = baseConfig({ gam: true, standalone: true, direct: true, policy: 'STRICT' });
    const runtime = createHarness(config);
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    assert.equal(runtime.metrics.hellos.length, 1);
    assertNoMonetization(runtime.metrics);

    runtime.sendGate('PASS');
    await boot;
    await runtime.flush();
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.prebidScripts, 1);
    assert.ok(runtime.metrics.prebidAuctions >= 1);
    assert.equal(runtime.metrics.directScripts, 1);
    assert.equal(runtime.metrics.providerInitializations, 1);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PASSED');
});

test('PASS separately releases GAM, standalone Prebid, and Direct JS paths', async (t) => {
    await t.test('GAM', async () => {
        const runtime = createHarness(baseConfig({ gam: true }), { gateAutoResponse: 'PASS' });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts, 1);
        assert.equal(runtime.metrics.gamRequests, 1);
    });
    await t.test('standalone Prebid', async () => {
        const runtime = createHarness(baseConfig({ gam: false, standalone: true }), { gateAutoResponse: 'PASS' });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.prebidScripts, 1);
        assert.equal(runtime.metrics.prebidAuctions, 1);
        assert.equal(runtime.metrics.gptScripts, 0);
    });
    await t.test('Direct JS', async () => {
        const runtime = createHarness(baseConfig({ gam: false, direct: true }), { gateAutoResponse: 'PASS' });
        await runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        assert.equal(runtime.metrics.directScripts, 1);
        assert.equal(runtime.metrics.providerInitializations, 1);
    });
});

test('DENIED blocks monetization under every policy, including PERMISSIVE', async () => {
    for (const policy of ['STRICT', 'BALANCED', 'PERMISSIVE']) {
        const runtime = createHarness(baseConfig({ policy }), { gateAutoResponse: 'DENIED' });
        await runtime.sandbox.HorusMediaLoader.boot();
        assertNoMonetization(runtime.metrics);
        assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'BLOCKED');
    }
});

test('STRICT technical ERROR never fails open', async () => {
    const runtime = createHarness(baseConfig({ policy: 'STRICT' }), { gateAutoResponse: 'ERROR' });
    await runtime.sandbox.HorusMediaLoader.boot();
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'ERROR');
});

test('BALANCED ERROR waits for meaningful trusted activity and synthetic dispatchEvent cannot recover it', async () => {
    const runtime = createHarness(baseConfig({ policy: 'BALANCED' }), { gateAutoResponse: 'ERROR' });
    await runtime.sandbox.HorusMediaLoader.boot();
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'WAITING_FOR_ACTIVITY');

    runtime.dispatchSynthetic('pointerdown');
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'WAITING_FOR_ACTIVITY');

    runtime.dispatchTrusted('keydown', { key: 'A' });
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'SOFT_ALLOWED');
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('BALANCED meaningful trusted scroll can recover but disabled activity recovery cannot', async (t) => {
    await t.test('trusted scroll', async () => {
        const runtime = createHarness(baseConfig({ policy: 'BALANCED' }), { gateAutoResponse: 'ERROR' });
        await runtime.sandbox.HorusMediaLoader.boot();
        runtime.sandbox.scrollY = 40;
        runtime.dispatchTrusted('scroll');
        await runtime.flush();
        assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'SOFT_ALLOWED');
        assert.equal(runtime.metrics.gptScripts, 1);
    });
    await t.test('recovery disabled', async () => {
        const config = baseConfig({ policy: 'BALANCED' });
        config.trafficGate.activityRecoveryEnabled = false;
        const runtime = createHarness(config, { gateAutoResponse: 'ERROR' });
        await runtime.sandbox.HorusMediaLoader.boot();
        runtime.dispatchTrusted('pointerdown');
        await runtime.flush();
        assertNoMonetization(runtime.metrics);
        assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'ERROR');
    });
});

test('PERMISSIVE technical timeout soft-allows only after bounded maxWaitMs', async () => {
    const config = baseConfig({ policy: 'PERMISSIVE' });
    config.trafficGate.timings.maxWaitMs = 2000;
    const runtime = createHarness(config, { gateAutoResponse: 'TIMEOUT', timerScale: 0.01 });
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    await boot;
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'SOFT_ALLOWED');
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('BALANCED stalled gate enters activity recovery at initialWaitMs without classifying the visitor', async () => {
    const runtime = createHarness(baseConfig({ policy: 'BALANCED' }), { timerScale: 0.01 });
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await new Promise((resolve) => setTimeout(resolve, 15));
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'WAITING_FOR_ACTIVITY');
    assertNoMonetization(runtime.metrics);
    runtime.dispatchTrusted('pointerdown');
    await boot;
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'SOFT_ALLOWED');
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('iframe/CSP-style unavailability is a technical state and follows BALANCED recovery', async () => {
    const runtime = createHarness(baseConfig({ policy: 'BALANCED' }), { iframeFailure: true });
    await runtime.sandbox.HorusMediaLoader.boot();
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'WAITING_FOR_ACTIVITY');
    runtime.dispatchTrusted('touchstart');
    await runtime.flush();
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('fast PASS does not wait for initialWaitMs or maxWaitMs', async () => {
    const config = baseConfig({ policy: 'STRICT' });
    config.trafficGate.timings = { initialWaitMs: 5000, maxWaitMs: 15000, retryIntervalMs: 10000 };
    const runtime = createHarness(config, { gateAutoResponse: 'PASS', timerScale: 1 });
    const started = Date.now();
    await runtime.sandbox.HorusMediaLoader.boot();
    const elapsed = Date.now() - started;
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.ok(elapsed < 500, `PASS should release immediately, elapsed=${elapsed}ms`);
});

test('gate runs exactly once per document across multiple placements, refresh, SPA navigation, and duplicate Loader evaluation', async () => {
    const config = baseConfig({ gam: true, direct: true, refresh: true });
    const runtime = createHarness(config, { gateAutoResponse: 'PASS' });
    await runtime.sandbox.HorusMediaLoader.boot();
    const initialFrames = runtime.metrics.gateFrames;
    assert.equal(initialFrames, 1);

    runtime.runRefreshTimers();
    await runtime.flush();
    runtime.sandbox.history.pushState({}, '', '/next');
    runtime.sandbox.dispatchEvent(new runtime.sandbox.Event('horus:navigation'));
    await runtime.flush();
    await runtime.sandbox.HorusMediaLoader.refresh();
    runtime.reevaluateLoader();
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gateFrames, 1);
});

test('global AD_SERVING kill wins and does not bother creating a gate', async () => {
    const runtime = createHarness(baseConfig(), { globalControls: { ...openControls(), adServingDisabled: true } });
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gateFrames, 0);
    assertNoMonetization(runtime.metrics);
});

test('trafficGateDisabled emergency control bypasses a pending gate and releases the normal serving lifecycle on refresh', async () => {
    const runtime = createHarness(baseConfig({ policy: 'STRICT' }));
    const firstBoot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    assertNoMonetization(runtime.metrics);

    runtime.setGlobalControls({ ...openControls(), trafficGateDisabled: true });
    await runtime.sandbox.HorusMediaLoader.refresh();
    await firstBoot;
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'DISABLED');
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.ok(runtime.metrics.gateFrameRemovals >= 1);
});

test('effective Site DISABLED bypasses the gate and preserves serving', async () => {
    const config = baseConfig();
    config.trafficGate = trafficGate('BALANCED', { enabled: false });
    const runtime = createHarness(config);
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gateFrames, 0);
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('parent validation requires exact gate origin, exact iframe source, protocol version, and nonce', async () => {
    const runtime = createHarness(baseConfig({ policy: 'STRICT' }));
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    const stranger = {};
    runtime.sendGate('PASS', {}, { origin: 'https://evil.example' });
    runtime.sendGate('PASS', {}, { source: stranger });
    runtime.sendGate('PASS', {}, { protocolVersion: 2 });
    runtime.sendGate('PASS', {}, { nonce: 'wrong-nonce-000000000000' });
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PENDING');
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gptScripts, 1);
});

test('nonce comes from browser crypto, HELLO is bounded, iframe is non-visible, and no PASS/token is persisted', async () => {
    const runtime = createHarness(baseConfig({ policy: 'STRICT' }));
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    const hello = runtime.metrics.hellos[0];
    assert.deepEqual(Object.keys(hello.payload).sort(), ['pageNonce', 'protocolVersion', 'sitePublicKey', 'type']);
    assert.match(hello.payload.pageNonce, /^[a-f0-9]{48}$/);
    assert.equal(hello.targetOrigin, GATE_ORIGIN);
    assert.equal(runtime.gateFrame.src, `${GATE_ORIGIN}/traffic-gate/`);
    assert.equal(runtime.gateFrame.style.values.left, '-10000px');
    assert.equal(runtime.gateFrame.style.values['pointer-events'], 'none');

    runtime.sendGate('PASS', { token: 'must-never-be-used' });
    await boot;
    assert.equal(runtime.metrics.localStorageWrites, 0);
    assert.equal(runtime.metrics.hellos.length, 1);
    assert.ok(!JSON.stringify(runtime.metrics.hellos).includes('must-never-be-used'));
});

test('Traffic Gate outcomes create no Laravel/analytics/reporting request', async () => {
    const runtime = createHarness(baseConfig(), { gateAutoResponse: 'PASS' });
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.fetches.some((url) => url.includes('app.horusmedia.net')), false);
    assert.equal(runtime.metrics.fetches.some((url) => /analytics|beacon|traffic-gate.*report/i.test(url)), false);
});

test('boot architecture starts gate and privacy in parallel and does not refetch static config after PASS', async () => {
    const config = baseConfig({ policy: 'STRICT' });
    config.privacy = {
        mode: 'STRICT',
        requireConsentBeforeAds: true,
        cmp: { timeoutMs: 5000, actionOnTimeout: 'LIMITED_ADS' },
    };
    const runtime = createHarness(config, { gateAutoResponse: 'PASS', deferredTcf: true, timerScale: 1 });
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    for (let attempt = 0; attempt < 20 && (!runtime.metrics.hellos.length || !runtime.metrics.tcfCallback); attempt += 1) await runtime.flush();

    assert.equal(runtime.metrics.gateFrames, 1);
    assert.equal(runtime.metrics.hellos.length, 1);
    assert.equal(typeof runtime.metrics.tcfCallback, 'function');
    assert.equal(runtime.metrics.gptScripts, 0, 'privacy remains an independent prerequisite after fast PASS');
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.configFetches, 1);

    runtime.metrics.tcfCallback({ eventStatus: 'tcloaded', gdprApplies: false, purpose: { consents: { 1: true } } }, true);
    await boot;
    await runtime.sandbox.HorusMediaLoader.scan();
    runtime.sandbox.dispatchEvent(new runtime.sandbox.Event('horus:navigation'));
    await runtime.flush();
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.configFetches, 1);
});

test('privacy can resolve while Turnstile is still pending, but monetization stays blocked until PASS', async () => {
    const config = baseConfig({ policy: 'STRICT' });
    config.privacy = {
        mode: 'STRICT', requireConsentBeforeAds: true,
        cmp: { timeoutMs: 5000, actionOnTimeout: 'LIMITED_ADS' },
    };
    const runtime = createHarness(config, { deferredTcf: true, timerScale: 1 });
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    for (let attempt = 0; attempt < 20 && (!runtime.metrics.hellos.length || !runtime.metrics.tcfCallback); attempt += 1) await runtime.flush();
    runtime.metrics.tcfCallback({ eventStatus: 'tcloaded', gdprApplies: false, purpose: { consents: { 1: true } } }, true);
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gptScripts, 1);
});
