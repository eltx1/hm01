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
    deferFirstControls = false,
    deferGateDocument = false,
    warmFrameFailure = false,
} = {}) {
    const metrics = {
        fetches: [],
        configFetches: 0,
        globalFetches: 0,
        gateFrames: 0,
        warmFrames: 0,
        warmFrameRemovals: 0,
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
    let nonceSequence = 0;
    const nativeSetTimeout = setTimeout;
    const nativeClearTimeout = clearTimeout;
    const activeTimers = new Map();
    const scaledSetTimeout = (callback, delay = 0, ...args) => {
        const id = nativeSetTimeout(() => { activeTimers.delete(id); callback(...args); }, Math.max(0, Number(delay) * timerScale));
        activeTimers.set(id, () => callback(...args));
        return id;
    };
    const cancelTimeout = id => { activeTimers.delete(id); nativeClearTimeout(id); };
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
            setAttribute(name, value) {
                // A parked document becomes the SAME active frame, not a new
                // append/navigation. Account for that DOM attribute transition.
                if (name === 'data-hm-traffic-gate' && String(value) === '1'
                    && attributes[name] !== '1' && this.parentNode) {
                    metrics.gateFrames += 1;
                    gateFrame = this;
                }
                attributes[name] = String(value);
            },
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
        const parked = node.getAttribute?.('data-hm-traffic-gate-document') === '1';
        const active = node.getAttribute?.('data-hm-traffic-gate') === '1';
        if (active || parked) {
            if (active) { metrics.gateFrames += 1; gateFrame = node; }
            if (parked) { metrics.warmFrames += 1; metrics.warmFrame = node; }
            const finish = () => {
                if (iframeFailure || (parked && warmFrameFailure)) node.onerror?.(new Error('blocked'));
                else {
                    if (parked) sandbox.dispatchEvent({type:'message', origin:GATE_ORIGIN, source:node.contentWindow,
                        data:{type:'HORUS_TRAFFIC_GATE_DOCUMENT_READY', protocolVersion:2}});
                    node.onload?.();
                }
            };
            if (deferGateDocument && parked) metrics.releaseGateDocument = finish;
            else queueMicrotask(finish);
        }
        return node;
    };
    const originalRootRemove = root.removeChild.bind(root);
    root.removeChild = (node) => {
        if (node.getAttribute?.('data-hm-traffic-gate') === '1') metrics.gateFrameRemovals += 1;
        else if (node.getAttribute?.('data-hm-traffic-gate-document') === '1') metrics.warmFrameRemovals += 1;
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
        clearTimeout: cancelTimeout,
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
                nonceSequence += 1;
                for (let index = 0; index < bytes.length; index += 1) bytes[index] = (index * 17 + 11 + nonceSequence) % 256;
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
                if (deferFirstControls && metrics.globalFetches === 1) {
                    return new Promise(resolve => { metrics.releaseFirstControls = () => resolve({ ok: true, json: async () => ({ controls: structuredClone(globalControls) }) }); });
                }
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
        fireTimer(id) {
            const callback = activeTimers.get(id);
            assert.ok(callback, 'timer must be live before deterministic firing');
            cancelTimeout(id); callback();
        },
        messageListenerCount() { return (listeners.message || []).length; },
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

test('pre-DOM verification can PASS but all engines wait for DOM and a CMP installed later', async () => {
    const config = baseConfig({ gam: true, standalone: true, direct: true });
    config.privacy.cmp.timeoutMs = 5000;
    config.privacy.requireConsentBeforeAds = false;
    const runtime = createHarness(config, { readyState: 'loading', autoboot: true, timerScale: 1 });
    await runtime.flush();
    assert.equal(runtime.metrics.configFetches, 1);
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.gateFrames, 1);
    assert.equal(runtime.sandbox.HorusMediaLoader.getConfig(), null);
    runtime.sendGate('PASS');
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PASSED');
    await runtime.sandbox.HorusMediaLoader.scan();
    assertNoMonetization(runtime.metrics);
    const hints = runtime.metrics.preparationHints.map(node => node.attributes);
    assert.equal(hints.filter(hint => hint.rel === 'preload' && hint.as === 'script' && hint.href === config.gpt.url).length, 1);
    assert.equal(hints.filter(hint => hint.rel === 'preconnect').length, 3);
    let consent;
    runtime.sandbox.__tcfapi = (command, version, callback) => { consent = callback; };
    runtime.domReady();
    await runtime.flush();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    await runtime.sandbox.HorusMediaLoader.scan();
    assertNoMonetization(runtime.metrics);
    consent({ eventStatus: 'tcloaded', gdprApplies: false }, true);
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.configFetches, 1);
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.gateFrames, 1, 'DOM boot adopts the same verified attempt');
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
    assert.equal(runtime.metrics.gateFrames, 1);
    assert.equal(runtime.metrics.gateFrameRemovals, 1);
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
    assert.equal(runtime.metrics.gateFrames, 0);
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
        if (['host', 'pause', 'global', 'invalid-gate'].includes(variant)) {
            assert.equal(runtime.metrics.preparationHints.length, 0, variant);
            assert.equal(runtime.metrics.gateFrames, 0, variant);
        }
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

test('a head-loaded gate uses the available document root and reuses its pending attempt at DOM readiness', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, timerScale: 1 });
    runtime.sandbox.document.body = null;
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    assertNoMonetization(runtime.metrics);
    runtime.sandbox.document.body = runtime.sandbox.document.documentElement;
    runtime.domReady();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
});

test('DOM readiness never retries an explicit denial or rejected server verification', async () => {
    for (const result of ['DENIED', 'VERIFICATION_REJECTED']) {
        const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true });
        await runtime.flush();
        runtime.sendGate(result === 'DENIED' ? 'DENIED' : 'ERROR', { category: result });
        const state = runtime.sandbox.HorusMediaLoader.getTrafficGateState().state;
        runtime.domReady();
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, state, result);
        assert.equal(runtime.metrics.gateFrames, 1, result);
        assertNoMonetization(runtime.metrics);
    }
});

test('every pre-DOM loading failure gets one normal attempt with a new nonce and still requires PASS', async () => {
    for (const result of ['DEADLINE', 'TIMEOUT', 'TURNSTILE_SCRIPT_ERROR', 'STATIC_CONFIG_UNAVAILABLE', 'VERIFICATION_UNAVAILABLE', 'ERROR', 'GATE_NOT_READY']) {
        const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true });
        await runtime.flush();
        const oldNonce = runtime.metrics.hellos.at(-1).payload.pageNonce;
        if (result === 'DEADLINE') await new Promise(resolve => setTimeout(resolve, 60));
        else runtime.sendGate(result === 'TIMEOUT' ? 'TIMEOUT' : 'ERROR', { category: result });
        runtime.domReady();
        const boot = runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        assert.equal(runtime.metrics.gateFrames, 2, result);
        assert.notEqual(runtime.metrics.hellos.at(-1).payload.pageNonce, oldNonce);
        assertNoMonetization(runtime.metrics);
        runtime.sendGate('PASS');
        await boot;
        assert.equal(runtime.metrics.gamRequests, 1, result);
    }
});

test('an unavailable early runtime can recover when the normal page environment is ready', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, cryptoUnavailable: true });
    await runtime.flush();
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'UNAVAILABLE');
    assert.equal(runtime.metrics.gateFrames, 0);
    runtime.sandbox.crypto = { getRandomValues(bytes) { bytes.fill(17); return bytes; } };
    runtime.domReady();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    assertNoMonetization(runtime.metrics);
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
});

test('an adopted pending preparation that fails after DOM still gets exactly one normal attempt', async () => {
    for (const recovered of [true, false]) {
        const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, timerScale: 1 });
        await runtime.flush();
        runtime.domReady();
        const boot = runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        assert.equal(runtime.metrics.gateFrames, 1);
        runtime.sendGate('ERROR', { category: 'TURNSTILE_SCRIPT_ERROR' });
        await runtime.flush();
        assert.equal(runtime.metrics.gateFrames, 2);
        assertNoMonetization(runtime.metrics);
        runtime.sendGate(recovered ? 'PASS' : 'TIMEOUT');
        await boot;
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gateFrames, 2);
        if (recovered) assert.equal(runtime.metrics.gamRequests, 1);
        else assertNoMonetization(runtime.metrics);
    }
});

test('a failed normal attempt after early failure cannot create an unbounded restart loop', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true });
    await runtime.flush();
    runtime.sendGate('ERROR', { category: 'TURNSTILE_SCRIPT_ERROR' });
    runtime.domReady();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    runtime.sendGate('TIMEOUT');
    await boot;
    await runtime.sandbox.HorusMediaLoader.boot();
    await runtime.sandbox.HorusMediaLoader.refresh();
    assert.equal(runtime.metrics.gateFrames, 2);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'TIMEOUT');
    assertNoMonetization(runtime.metrics);
});

test('a fresh unchanged snapshot after a parser stall preserves the early deadline and iframe', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, timerScale: 1 });
    await runtime.flush();
    runtime.elapse(6000);
    runtime.domReady();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    await runtime.flush();
    assert.equal(runtime.metrics.configFetches, 2);
    assert.equal(runtime.metrics.gateFrames, 1);
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
});

test('changed configuration retires the early attempt and rejects its late messages and PASS', async () => {
    for (const earlyPass of [false, true]) {
        const config = baseConfig();
        const runtime = createHarness(config, { readyState: 'loading', autoboot: true, timerScale: 1 });
        await runtime.flush();
        const previousSource = runtime.gateFrame.contentWindow;
        const previousNonce = runtime.metrics.hellos.at(-1).payload.pageNonce;
        if (earlyPass) runtime.sendGate('PASS');
        config.configVersion += 1;
        config.trafficGate.siteKey = '0x4AAAAA_replacement_key';
        runtime.elapse(6000);
        runtime.domReady();
        const boot = runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        assert.equal(runtime.metrics.gateFrames, 2);
        assert.equal(runtime.metrics.gateFrameRemovals, 1);
        assert.notEqual(runtime.metrics.hellos.at(-1).payload.pageNonce, previousNonce);
        runtime.sendGate('PASS', {}, { source: previousSource, nonce: previousNonce });
        await runtime.flush();
        assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PENDING');
        assertNoMonetization(runtime.metrics);
        runtime.sendGate('PASS');
        await boot;
        assert.equal(runtime.metrics.gamRequests, 1);
    }
});

test('a discarded early PASS never survives a domain revocation or failed config refresh', async () => {
    for (const failure of ['domain', 'fetch']) {
        const config = baseConfig();
        const runtime = createHarness(config, { readyState: 'loading', autoboot: true });
        await runtime.flush();
        runtime.sendGate('PASS');
        runtime.elapse(6000);
        if (failure === 'domain') config.allowedHostnames = ['another.example'];
        else runtime.sandbox.fetch = async () => { throw new Error('unavailable'); };
        runtime.domReady();
        await runtime.sandbox.HorusMediaLoader.boot();
        await runtime.sandbox.HorusMediaLoader.scan();
        assert.notEqual(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PASSED');
        assertNoMonetization(runtime.metrics);
    }
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
    assert.equal(runtime.gateFrame.src, `${GATE_ORIGIN}/traffic-gate/?protocol=2#prepare`);
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

function scanClock(h) {
    let now = 0, sequence = 0;
    const pending = new Map();
    const originalSet = h.sandbox.setTimeout, originalClear = h.sandbox.clearTimeout;
    h.sandbox.setTimeout = (callback, delay, ...args) => {
        if (delay !== 25) return originalSet(callback, delay, ...args);
        const id = { scan: ++sequence }; pending.set(id, { at: now + 25, callback }); return id;
    };
    h.sandbox.clearTimeout = id => { if (!pending.delete(id)) originalClear(id); };
    return { async advance(ms) {
        now += ms;
        for (const [id, task] of [...pending]) if (task.at <= now) { pending.delete(id); task.callback(); }
        await h.flush();
    } };
}

test('continuous video/SPA DOM activity cannot postpone a newly mounted independent ad', async () => {
    const c = baseConfig({ gam: true });
    c.placements.push(placement('late_display', 'GAM'));
    const h = createHarness(c), late = h.elements.pop(), clock = scanClock(h);
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    assertNoMonetization(h.metrics); h.sendGate('PASS'); await boot;
    assert.equal(h.metrics.gamSlots, 1);
    h.elements.push(late); h.triggerMutation([late]);
    // Deterministically simulate a video SDK appending nodes every 10 ms.
    // A trailing-edge debounce starves the new display for this entire burst.
    for (let elapsed = 0; elapsed < 120; elapsed += 10) {
        await clock.advance(10); h.triggerMutation([]);
    }
    assert.equal(h.metrics.gamSlots, 2, 'new display must start while video DOM work is still ongoing');
    for (let i = 0; i < 10; i++) { h.triggerMutation([late]); await clock.advance(30); }
    assert.equal(h.metrics.gamSlots, 2, 'fair scanning must still keep one renderer per physical slot');
    h.sandbox.HorusMediaLoader._resetForTests();
});

test('pending video runtime never blocks GAM, standalone Prebid or another Direct slot after PASS', async () => {
    const c = baseConfig({ gam: true, standalone: true, direct: true });
    c.placements.unshift(placement('direct_second', 'DIRECT_JS'));
    c.directDemand.placements.direct_second = { enabled: true, candidates: [directCandidate('direct_second')] };
    c.directDemand.placements.direct_slot.candidates[0].network = 'PENDING_VIDEO';
    c.directDemand.placements.direct_slot.candidates[0].tag.container.attributes = { 'data-hm-video-direct': '1' };
    const h = createHarness(c), held = [];
    const append = h.sandbox.document.head.appendChild;
    h.sandbox.document.head.appendChild = node => {
        if (node.getAttribute?.('data-hm-direct-script') === 'PENDING_VIDEO') { h.metrics.directScripts++; held.push(node); return node; }
        return append(node);
    };
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush(); assertNoMonetization(h.metrics);
    h.sendGate('PASS'); for (let i = 0; i < 5; i++) await h.flush();
    assert.equal(held.length, 1); assert.equal(h.metrics.gamRequests, 1);
    assert.equal(h.metrics.prebidAuctions, 1); assert.equal(h.metrics.directScripts, 2);
    assert.equal(h.metrics.providerInitializations, 1, 'other Direct slot initializes before video script onload');
    held.forEach(script => script.onload?.()); await boot;
    h.sandbox.HorusMediaLoader._resetForTests();
});

test('one placement initialization exception cannot cancel the other independent engines', async () => {
    const c = baseConfig({ gam: true, standalone: true, direct: true });
    c.placements.find(p => p.code === 'direct_slot').lazyLoad = { enabled: true };
    const h = createHarness(c);
    h.sandbox.IntersectionObserver = class { observe() { throw new Error('Publisher observer failed'); } disconnect() {} };
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush(); h.sendGate('PASS'); await boot;
    assert.equal(h.metrics.gamRequests, 1); assert.equal(h.metrics.prebidAuctions, 1);
    assert.equal(h.metrics.directScripts, 0, 'failed slot is not retried as a side effect of another engine');
    h.sandbox.HorusMediaLoader._resetForTests();
});

test('duplicate permanent embeds share one pre-DOM config read and one verification attempt', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, timerScale: 1 });
    runtime.reevaluateLoader();
    await runtime.flush();
    assert.equal(runtime.metrics.configFetches, 1, 'duplicate evaluation must not start a second static boot');
    assert.equal(runtime.metrics.globalFetches, 1);
    assert.equal(runtime.metrics.gateFrames, 1);
    runtime.sendGate('PASS');
    runtime.domReady();
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.gamRequests, 1);
});


test('local trace distinguishes verified PASS from token progress and ignores spoofed progress', async () => {
    const h = createHarness(baseConfig(), { timerScale: 1 });
    const boot = h.sandbox.HorusMediaLoader.boot();
    await h.flush();
    const phases = () => h.sandbox.HorusMediaLoader.getStartupTrace().events.map(e => e.phase);
    h.sendGate('PROGRESS', { phase: 'token', token: 'never-log-me' }, { origin: 'https://attacker.example' });
    h.sendGate('PROGRESS', { phase: 'verify' }, { nonce: 'wrong-nonce' });
    assert.equal(phases().includes('CF token'), false);
    assert.equal(phases().includes('CF verify'), false);
    h.sendGate('PROGRESS', { phase: 'token', token: 'never-log-me' });
    h.sendGate('PROGRESS', { phase: 'verify', success: true });
    assert.equal(phases().includes('CF pass'), false);
    assert.equal(h.metrics.gamRequests, 0);
    assert.equal(h.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PENDING');
    h.sendGate('PASS');
    await boot;
    assert.equal(h.metrics.gamRequests, 1);
    const names = phases();
    assert.ok(names.indexOf('CF start') < names.indexOf('CF token'));
    assert.ok(names.indexOf('CF token') < names.indexOf('CF verify'));
    assert.ok(names.indexOf('CF verify') < names.indexOf('CF pass'));
    assert.ok(names.indexOf('CF pass') < names.indexOf('Horus start'));
    assert.equal(JSON.stringify(h.sandbox.HorusMediaLoader.getStartupTrace()).includes('never-log-me'), false);
    h.sandbox.HorusMediaLoader._resetForTests();
});

test('throwing console does not block verification, requests or content admission', async () => {
    const h = createHarness(baseConfig(), { timerScale: 1 });
    h.sandbox.console = { info() {}, log() { throw new Error('console unavailable'); } };
    const boot = h.sandbox.HorusMediaLoader.boot();
    await h.flush();
    h.sendGate('PASS');
    await boot;
    assert.equal(h.metrics.gamRequests, 1);
    assert.equal(h.sandbox.HorusMediaLoader.getTrafficGateState().state, 'PASSED');
    h.sandbox.HorusMediaLoader._resetForTests();
});

test('explicit verification rejection is distinct from a technical timeout in the local trace', async () => {
    const h = createHarness(baseConfig(), { timerScale: 1 });
    const boot = h.sandbox.HorusMediaLoader.boot();
    await h.flush();
    h.sendGate('ERROR', { category: 'VERIFICATION_REJECTED' });
    await boot;
    assert.equal(h.metrics.gamRequests, 0);
    const names = h.sandbox.HorusMediaLoader.getStartupTrace().events.map(e => e.phase);
    assert.equal(names.includes('CF reject'), true);
    assert.equal(names.includes('CF pass'), false);
    assert.equal(names.includes('Horus start'), false);
    h.sandbox.HorusMediaLoader._resetForTests();
});


test('verification document loads alongside pending config without HELLO, nonce, challenge or ads', async () => {
    const runtime = createHarness(baseConfig(), { readyState: 'loading', autoboot: true, deferFirstConfig: true, timerScale: 1 });
    await runtime.flush();
    assert.equal(runtime.metrics.warmFrames, 1);
    assert.equal(runtime.metrics.gateFrames, 0);
    assert.equal(runtime.metrics.hellos.length, 0);
    assertNoMonetization(runtime.metrics);
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().started, false);
    const frame = runtime.metrics.warmFrame;
    // Even a forged verified message from the parked frame cannot release ads.
    runtime.sandbox.dispatchEvent({ type: 'message', origin: GATE_ORIGIN, source: frame.contentWindow,
        data: {type: 'HORUS_TRAFFIC_GATE_PASS', protocolVersion: 2, serverVerified: true, pageNonce: 'fake-nonce'} });
    assert.equal(runtime.sandbox.HorusMediaLoader.getTrafficGateState().state, 'BOOTING');
    runtime.reevaluateLoader();
    assert.equal(runtime.metrics.warmFrames, 1);
    runtime.metrics.releaseFirstConfig();
    await runtime.flush();
    assert.equal(runtime.metrics.gateFrames, 1);
    assert.equal(runtime.gateFrame, frame);
    assert.equal(runtime.metrics.hellos.length, 1);
    assertNoMonetization(runtime.metrics);
    runtime.domReady();
    const boot = runtime.sandbox.HorusMediaLoader.boot();
    runtime.sendGate('PASS');
    await boot;
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.metrics.configFetches, 1);
});

test('slow global controls cannot be bypassed by a warmed frame or expose HELLO early', async () => {
    const runtime = createHarness(baseConfig(), {readyState:'loading', autoboot:true, deferFirstControls:true, timerScale:1});
    await runtime.flush();
    assert.equal(runtime.metrics.warmFrames, 1);
    assert.equal(runtime.metrics.hellos.length, 0);
    runtime.setGlobalControls({...openControls(), adServingDisabled:true});
    runtime.metrics.releaseFirstControls();
    await runtime.flush();
    assert.equal(runtime.metrics.warmFrameRemovals, 1);
    assert.equal(runtime.metrics.hellos.length, 0);
    runtime.domReady();
    await runtime.sandbox.HorusMediaLoader.boot();
    assertNoMonetization(runtime.metrics);
});

test('pending document recovery replaces only one transport within the original deadline', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    const first = h.metrics.warmFrame, startedAt = gate.startedAt, deadline = gate.maxTimer, nonce = gate.pageNonce;
    const oldOnload = first.onload, oldOnerror = first.onerror, fallback = gate.transportFallback;
    assert.equal(h.gateFrame, first);
    assert.equal(h.metrics.hellos.length, 0);
    assertNoMonetization(h.metrics);
    h.fireTimer(gate.initialTimer); await h.flush();
    assert.notEqual(h.gateFrame, first);
    assert.equal(first.parentNode, null);
    assert.equal(h.metrics.gateFrames, 2);
    assert.equal(h.metrics.hellos.length, 1);
    assert.equal(gate.startedAt, startedAt); assert.equal(gate.maxTimer, deadline); assert.equal(gate.pageNonce, nonce);
    assert.equal(gate.transportFallback, null);
    oldOnload(); oldOnerror(); fallback('pending_deadline'); h.metrics.releaseGateDocument();
    h.sendGate('PASS', {}, {source:first.contentWindow});
    assertNoMonetization(h.metrics);
    assert.equal(h.metrics.hellos.length, 1);
    assert.equal(h.metrics.gateFrames, 2, 'late signals cannot create a third frame');
    h.sendGate('PASS'); await boot;
    assert.equal(h.metrics.gamRequests, 1);
    assert.equal(h.messageListenerCount(), 0);
});

test('failed or stale document warmup falls back to the ordinary bounded verification', async () => {
    for (const mode of ['failed','stale','removed','changed-source']) {
        const runtime = createHarness(baseConfig(), {readyState:'loading', autoboot:true,
            deferFirstConfig:true, warmFrameFailure:mode==='failed', timerScale:1});
        await runtime.flush();
        if (mode==='stale') runtime.elapse(6000);
        if (mode==='removed') runtime.metrics.warmFrame.parentNode.removeChild(runtime.metrics.warmFrame);
        if (mode==='changed-source') runtime.metrics.warmFrame.src='https://untrusted.example/';
        runtime.metrics.releaseFirstConfig();
        runtime.domReady();
        const boot = runtime.sandbox.HorusMediaLoader.boot();
        await runtime.flush();
        assert.equal(runtime.metrics.gateFrames, 1, mode);
        assert.notEqual(runtime.gateFrame, runtime.metrics.warmFrame, mode);
        assert.equal(runtime.metrics.hellos.length, 1, mode);
        assertNoMonetization(runtime.metrics);
        runtime.sendGate('PASS');
        await boot;
        assert.equal(runtime.metrics.gamRequests, 1, mode);
        assert.equal(runtime.metrics.warmFrames, 1, mode);
    }
});


test('a pending preparation error recovers once without resetting the active deadline', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, warmFrameFailure:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    const startedAt = gate.startedAt, deadline = gate.maxTimer, oldError = h.gateFrame.onerror;
    h.metrics.releaseGateDocument(); await h.flush(); oldError();
    assert.equal(h.metrics.warmFrames, 1);
    assert.equal(h.metrics.gateFrames, 2);
    assert.equal(h.metrics.hellos.length, 1);
    assert.equal(gate.startedAt, startedAt);
    assert.equal(gate.maxTimer, deadline);
    assertNoMonetization(h.metrics);
    h.sendGate('PASS'); await boot;
    assert.equal(h.metrics.gamRequests, 1);
});

test('pending same-page verification document is retained until readiness, with one HELLO and original deadline', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot();
    await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    const firstFrame = h.metrics.warmFrame, deadline = gate.maxTimer, startedAt = gate.startedAt;
    try {
        assert.equal(h.gateFrame, firstFrame, 'retain the valid in-flight navigation instead of cancelling it');
        assert.equal(h.metrics.hellos.length, 0);
        assertNoMonetization(h.metrics);
        h.metrics.releaseGateDocument();
        await h.flush();
        assert.equal(h.metrics.hellos.length, 1);
        firstFrame.onload?.();
        h.metrics.releaseGateDocument();
        assert.equal(h.metrics.hellos.length, 1, 'readiness and load cannot double-submit HELLO');
        assert.equal(gate.maxTimer, deadline);
        assert.equal(gate.startedAt, startedAt);
        h.sendGate('PASS', {serverVerified:false});
        assertNoMonetization(h.metrics);
        h.sendGate('PASS'); await boot;
        assert.equal(h.metrics.gamRequests, 1);
        assert.equal(h.metrics.gateFrames, 1);
    } finally { h.sandbox.HorusMediaLoader._resetForTests(); }
});


test('legacy gate load without readiness ping falls back before sending any HELLO to that document', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    h.gateFrame.onload();
    assert.equal(h.metrics.hellos.length, 0);
    h.fireTimer(gate.initialTimer); await h.flush();
    assert.equal(h.metrics.hellos.length, 1);
    assert.equal(h.metrics.gateFrames, 2);
    assertNoMonetization(h.metrics);
    h.sendGate('PASS'); await boot;
    assert.equal(h.metrics.gamRequests, 1);
    assert.equal(h.messageListenerCount(), 0);
});

test('pending navigation cannot outlive the original maximum or resume from a late message', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    const oldLoad = h.gateFrame.onload;
    h.elapse(2001); h.metrics.releaseGateDocument(); await boot;
    assert.equal(h.sandbox.HorusMediaLoader.getTrafficGateState().state, 'TIMEOUT');
    assert.equal(gate.iframe, null); assert.equal(gate.documentListener, null); assert.equal(gate.transportFallback, null);
    assert.equal(h.metrics.hellos.length, 0);
    assert.equal(h.messageListenerCount(), 0);
    h.metrics.releaseGateDocument(); oldLoad(); await h.flush();
    assert.equal(h.metrics.gateFrames, 1);
    assertNoMonetization(h.metrics);
});

test('invalid source, origin or protocol cannot wake a pending document or authorize advertising', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true, timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    for (const overrides of [{origin:'https://untrusted.example'}, {source:{}}, {data:{type:'HORUS_TRAFFIC_GATE_DOCUMENT_READY',protocolVersion:1}}]) {
        h.sandbox.dispatchEvent({type:'message',origin:GATE_ORIGIN,source:h.gateFrame.contentWindow,
            data:{type:'HORUS_TRAFFIC_GATE_DOCUMENT_READY',protocolVersion:2},...overrides});
    }
    assert.equal(h.metrics.hellos.length, 0); assertNoMonetization(h.metrics);
    h.metrics.releaseGateDocument(); await h.flush();
    h.sendGate('PASS', {serverVerified:false}); assertNoMonetization(h.metrics);
    h.sendGate('PASS'); await boot; assert.equal(h.metrics.gamRequests, 1);
});


test('a loaded speculative error or legacy document without a readiness ping is not adopted', async () => {
    const h = createHarness(baseConfig(), {readyState:'loading', autoboot:true,
        deferFirstConfig:true,deferGateDocument:true,timerScale:1});
    await h.flush(); h.metrics.warmFrame.onload();
    h.metrics.releaseFirstConfig(); h.domReady();
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    assert.notEqual(h.gateFrame, h.metrics.warmFrame);
    assert.equal(h.metrics.hellos.length, 1);
    assert.ok(h.sandbox.HorusMediaLoader.getStartupTrace().events.some(e=>e.phase==='CF cold' && e.reason==='readiness_missing'));
    assertNoMonetization(h.metrics);
    h.sendGate('PASS'); await boot; assert.equal(h.metrics.gamRequests, 1);
});

test('a pending frame that becomes ready never restarts while its server verification is slow', async () => {
    const h = createHarness(baseConfig(), {deferGateDocument:true,timerScale:1});
    const boot = h.sandbox.HorusMediaLoader.boot(); await h.flush();
    const gate = h.sandbox.__HORUS_MEDIA_LOADER_STATE__.trafficGate;
    h.metrics.releaseGateDocument(); await h.flush();
    const deadline = gate.maxTimer;
    h.sendGate('READY'); h.sendGate('PROGRESS', {phase:'token'});
    h.fireTimer(gate.initialTimer); await h.flush();
    assert.equal(h.metrics.gateFrames, 1); assert.equal(h.metrics.hellos.length, 1);
    assert.equal(gate.maxTimer, deadline); assertNoMonetization(h.metrics);
    h.sendGate('PASS'); await boot; assert.equal(h.metrics.gamRequests, 1);
});
