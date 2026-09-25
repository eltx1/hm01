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
        provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED',
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
    cryptoUnavailable = false,
    readyState = 'complete',
    autoboot = false,
    deferFirstConfig = false,
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
        preparationHints: [],
    };
    const elements = config.placements.map((item) => domElement(item.code));
    let globalControls = structuredClone(initialGlobalControls);
    let gateFrame = null;
    let mutationCallback = null;
    let intervalSequence = 0;
    const intervals = new Map();
    const listeners = {};
    const documentListeners = {};
    let clockOffset = 0;
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
            data: { type, protocolVersion: 2, pageNonce: nonce, serverVerified: true, ...extra },
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
        readyState,
        visibilityState: 'visible',
        documentElement: root,
        body: root,
        activeElement: null,
        head: {
            appendChild(node) {
                if (node.getAttribute?.('data-hm-preparation') === '1') {
                    metrics.preparationHints.push(node);
                } else if (node.getAttribute?.('data-hm-gpt') === '1') {
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
        addEventListener(name, callback) { (documentListeners[name] ||= []).push(callback); },
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
        Date: class extends Date { static now() { return Date.now() + clockOffset; } },
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
        crypto: cryptoUnavailable ? undefined : {
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
            if (deferFirstConfig && metrics.configFetches === 1) {
                const snapshot = structuredClone(config);
                return new Promise(resolve => { metrics.releaseFirstConfig = () => resolve({ ok: true, json: async () => snapshot }); });
            }
            return { ok: true, json: async () => structuredClone(config) };
        },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        removeEventListener(name, callback) {
            if (!listeners[name]) return;
            listeners[name] = listeners[name].filter((candidate) => candidate !== callback);
        },
        dispatchEvent(event) { (listeners[event.type] || []).slice().forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: !autoboot,
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
        elapse(ms) { clockOffset += ms; },
        domReady() {
            document.readyState = 'interactive';
            (documentListeners.DOMContentLoaded || []).splice(0).forEach(callback => callback());
        },
        sendGate(type, extra = {}, overrides = {}) {
            assert.ok(gateFrame, 'gate frame must exist before sending a result');
            const hello = metrics.hellos.at(-1);
            assert.ok(hello, 'HELLO must be sent before a gate result');
            const origin = overrides.origin ?? GATE_ORIGIN;
            const source = overrides.source ?? gateFrame.contentWindow;
            const nonce = overrides.nonce ?? hello.payload.pageNonce;
            const event = {
                type: 'message', origin, source,
                data: { type: `HORUS_TRAFFIC_GATE_${type}`, protocolVersion: overrides.protocolVersion ?? 2, pageNonce: nonce, serverVerified: true, ...extra },
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

test('pre-DOM preparation fetches static data/GPT bytes once and waits for a CMP installed later', async () => {
    const config = baseConfig({ gam: true, standalone: true, direct: true });
    config.privacy.cmp.timeoutMs = 5000;
    config.privacy.requireConsentBeforeAds = false;
    const runtime = createHarness(config, { readyState: 'loading', autoboot: true, timerScale: 1 });
    await runtime.flush();
    assert.equal(runtime.metrics.configFetches, 1);
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.gateFrames, 0);
    assert.equal(runtime.sandbox.HorusMediaLoader.getConfig(), null);
    assertNoMonetization(runtime.metrics);
    const hints = runtime.metrics.preparationHints.map(node => node.attributes);
    assert.equal(hints.filter(hint => hint.rel === 'preload' && hint.as === 'script' && hint.href === config.gpt.url).length, 1);
    assert.equal(hints.filter(hint => hint.rel === 'preconnect').length, 3);
    let consent;
    runtime.sandbox.__tcfapi = (command, version, callback) => { consent = callback; };
    runtime.domReady();
    await runtime.flush();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    runtime.sendGate('PASS');
    await runtime.flush();
    await runtime.sandbox.HorusMediaLoader.scan();
    assertNoMonetization(runtime.metrics);
    consent({ eventStatus: 'tcloaded', gdprApplies: false }, true);
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.configFetches, 1);
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.preparationHints.length, 4);
});

test('a long parser stall refreshes configuration and emergency controls before monetization', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true });
    await runtime.flush();
    runtime.elapse(6000);
    runtime.setGlobalControls({ ...openControls(), adServingDisabled: true });
    runtime.domReady();
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.configFetches, 2);
    assert.equal(runtime.metrics.globalFetches, 2);
    assert.equal(runtime.metrics.gateFrames, 0);
    assertNoMonetization(runtime.metrics);
});

test('discarded pending preparations cannot add hints after a newer emergency stop', async () => {
    const config = baseConfig();
    config.privacy.requireConsentBeforeAds = false;
    const runtime = createHarness(config, { readyState: 'loading', autoboot: true, deferFirstConfig: true });
    await runtime.flush();
    runtime.setGlobalControls({ ...openControls(), adServingDisabled: true });
    await runtime.sandbox.HorusMediaLoader.refresh();
    runtime.metrics.releaseFirstConfig();
    await runtime.flush();
    assert.equal(runtime.metrics.preparationHints.length, 0);
    assertNoMonetization(runtime.metrics);
});

test('consent-required sites preload Google only after privacy permits it, while verification is pending', async () => {
    const config = baseConfig();
    config.privacy.cmp = { timeoutMs: 10000, actionOnTimeout: 'BLOCK_ADS' };
    const runtime = createHarness(config, { readyState: 'loading', autoboot: true, deferredTcf: true, timerScale: 1 });
    await runtime.flush();
    const googleHints = () => runtime.metrics.preparationHints.filter(hint => hint.getAttribute('rel') === 'preload');
    assert.equal(googleHints().length, 0);
    runtime.domReady();
    await runtime.flush();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(googleHints().length, 0);
    runtime.metrics.tcfCallback({ eventStatus: 'tcloaded', gdprApplies: false }, true);
    await runtime.flush();
    assert.equal(googleHints().length, 1);
    assertNoMonetization(runtime.metrics);
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
});

test('privacy BLOCK_ADS prevents even Google preloading after a successful traffic verification', async () => {
    const config = baseConfig();
    config.privacy.mode = 'STRICT';
    config.privacy.cmp = { timeoutMs: 100, actionOnTimeout: 'BLOCK_ADS' };
    const runtime = createHarness(config, { readyState: 'loading', autoboot: true, gateAutoResponse: 'PASS' });
    await runtime.flush();
    runtime.domReady();
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PASSED');
    assert.equal(runtime.metrics.preparationHints.filter(hint => hint.getAttribute('rel') === 'preload').length, 0);
    assertNoMonetization(runtime.metrics);
});

test('early preparation respects rejected domains, paused sites, invalid gates and engine controls', async () => {
    for (const variant of ['host', 'pause', 'global', 'invalid-gate', 'gam-disabled', 'standalone', 'custom-gpt']) {
        const config = baseConfig({ gam: variant !== 'standalone', standalone: variant === 'standalone' });
        config.privacy.requireConsentBeforeAds = false;
        const controls = openControls();
        if (variant === 'host') config.allowedHostnames = ['another.example'];
        if (variant === 'pause') config.immediatePause = true;
        if (variant === 'global') controls.adServingDisabled = true;
        if (variant === 'invalid-gate') config.trafficGate.readiness = 'INVALID';
        if (variant === 'gam-disabled') controls.gamDisabled = true;
        if (variant === 'custom-gpt') config.gpt.url = 'https://untrusted.example/gpt.js';
        const runtime = createHarness(config, { readyState: 'loading', autoboot: true, globalControls: controls });
        await runtime.flush();
        assertNoMonetization(runtime.metrics);
        assert.equal(runtime.metrics.preparationHints.filter(hint => hint.getAttribute('rel') === 'preload').length, 0, variant);
        if (['host', 'pause', 'global', 'invalid-gate'].includes(variant)) assert.equal(runtime.metrics.preparationHints.length, 0, variant);
    }
});

test('failed early fetch retries through normal boot and forced refresh ignores the early snapshot', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true });
    await runtime.flush();
    runtime.setGlobalControls({ ...openControls(), adServingDisabled: true });
    await runtime.sandbox.HorusMediaLoader.refresh();
    assert.equal(runtime.metrics.globalFetches, 2);
    assertNoMonetization(runtime.metrics);
    const retry = createHarness(baseConfig(), { readyState: 'loading', autoboot: false, gateAutoResponse: 'PASS' });
    const fetch = retry.sandbox.fetch;
    retry.sandbox.fetch = async () => { throw new Error('temporary network failure'); };
    retry.sandbox.__HM_DISABLE_AUTOBOOT__ = false;
    retry.reevaluateLoader();
    await retry.flush();
    retry.sandbox.fetch = fetch;
    retry.domReady();
    await retry.sandbox.HorusMediaLoader.boot();
    assert.equal(retry.metrics.gamRequests, 1);
});

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

for (const policy of ['STRICT', 'BALANCED', 'PERMISSIVE']) {
    test(`${policy}: failures, elapsed time and trusted activity never authorize ads`, async () => {
        const config = baseConfig({ policy });
        config.trafficGate.timings = { initialWaitMs: 500, maxWaitMs: 2000, retryIntervalMs: 500 };
        for (const options of [{ gateAutoResponse: 'ERROR' }, { gateAutoResponse: 'TIMEOUT' }, { iframeFailure: true }, { cryptoUnavailable: true }, {}]) {
            const runtime = createHarness(config, { ...options, timerScale: 0.01 });
            await runtime.sandbox.HorusMediaLoader.boot();
            runtime.dispatchTrusted('pointerdown');
            runtime.dispatchTrusted('keydown', { key: 'A' });
            runtime.sandbox.scrollY = 40;
            runtime.dispatchTrusted('scroll');
            await new Promise(resolve => setTimeout(resolve, 30));
            await runtime.flush();
            assertNoMonetization(runtime.metrics);
            assert.ok(['ERROR', 'TIMEOUT', 'UNAVAILABLE'].includes(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state));
            await runtime.sandbox.HorusMediaLoader.refresh();
            assertNoMonetization(runtime.metrics);
        }
    });
}

test('unverified and old protocol PASS cannot release ads, then verified PASS can', async () => {
    const runtime = createHarness(baseConfig());
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    runtime.sendGate('PASS', { serverVerified: false });
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    runtime.sendGate('PASS', {}, { protocolVersion: 1 });
    await runtime.flush();
    assertNoMonetization(runtime.metrics);
    runtime.sendGate('PASS');
    await boot;
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
    runtime.sendGate('PASS', {}, { protocolVersion: 1 });
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
    assert.equal(runtime.gateFrame.src, `${GATE_ORIGIN}/traffic-gate/?protocol=2`);
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
