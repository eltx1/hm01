import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

const openControls = () => ({
    adServingDisabled: false,
    gamDisabled: false,
    prebidDisabled: false,
    directJsDisabled: false,
    nativeDemandDisabled: false,
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

function baseConfig({ gam = true, prebid = true, standalone = false, direct = true, refresh = false } = {}) {
    const placements = [];
    if (gam) placements.push(placement('gam_slot', 'GAM', refresh ? { refresh: { enabled: true, intervalSeconds: 30, limit: 2 } } : {}));
    if (standalone) placements.push(placement('prebid_slot', 'PREBID_STANDALONE'));
    if (direct) placements.push(placement('direct_slot', 'DIRECT_JS'));
    const prebidCode = standalone ? 'prebid_slot' : 'gam_slot';
    const directPlacements = direct ? {
        direct_slot: { enabled: true, candidates: [directCandidate()], house: null },
    } : {};

    return {
        schemaVersion: 4,
        siteKey: 'HM_GLOBAL_CONTROLS',
        servingMode: standalone ? 'HORUS_DIRECT' : 'HORUS_GAM',
        gamNetworkCode: gam ? '123456789' : null,
        configVersion: 23,
        status: 'active',
        immediatePause: false,
        debug: false,
        controls: openControls(),
        allowedHostnames: ['publisher.example'],
        loader: { version: '2.0.0', cacheBust: 23 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 1, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: false },
        pageTargeting: {},
        prebid: {
            enabled: prebid,
            deliveryMode: standalone ? 'STANDALONE' : 'GAM_BRIDGE',
            build: { version: '11.15.0', url: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js' },
            auction: { timeoutMs: 10, priceGranularity: 'medium', currency: 'USD', bidderSequence: 'fixed' },
            delivery: { gamFallback: true, refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } },
            directRender: { implemented: standalone, supportedMediaTypes: ['banner'], sandbox: ['allow-scripts'] },
            adUnits: prebid ? [{
                code: prebidCode,
                mediaTypes: { banner: { sizes: [[300, 250]] } },
                bids: [{ bidder: 'msft', params: { placement_id: 'task-23' } }],
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
        style: { setProperty() {} },
        childNodes: children,
        children,
        parentNode: null,
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
    };
    return node;
}

function harness(config, initialGlobalControls = openControls()) {
    const metrics = {
        gptScripts: 0,
        prebidScripts: 0,
        directScripts: 0,
        gamSlots: 0,
        gamRequests: 0,
        prebidAuctions: 0,
        prebidRenders: 0,
        directContainers: 0,
        providerInitializations: 0,
    };
    const elements = config.placements.map((item) => domElement(item.code));
    let globalControls = structuredClone(initialGlobalControls);
    let mutationCallback = null;
    let intervalSequence = 0;
    const intervals = new Map();
    const listeners = {};
    const loaderScript = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: {
            siteKey: config.siteKey,
            configBase: 'https://cdn.horusmedia.net/configs',
            environment: 'production',
            configVersion: String(config.configVersion),
        },
        attributes: { 'data-site-key': config.siteKey, 'data-config-version': String(config.configVersion) },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
    };

    const immediateQueue = { push(callback) { callback(); return 1; } };
    const pubads = {
        disableInitialLoad() {},
        enableSingleRequest() {},
        setTargeting() { return this; },
        refresh() { metrics.gamRequests += 1; },
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

    function genericNode(tagName) {
        const attributes = {};
        const children = [];
        return {
            id: '',
            tagName: String(tagName).toUpperCase(),
            className: '',
            style: { setProperty() {} },
            attributes,
            childNodes: children,
            children,
            parentNode: null,
            async: false,
            defer: false,
            src: '',
            onload: null,
            onerror: null,
            innerHTML: '',
            setAttribute(name, value) { attributes[name] = String(value); },
            getAttribute(name) { return attributes[name] ?? null; },
            addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            appendChild(child) { child.parentNode = this; children.push(child); return child; },
            removeChild(child) { const index = children.indexOf(child); if (index >= 0) children.splice(index, 1); return child; },
            querySelector() { return null; },
        };
    }

    const document = {
        currentScript: loaderScript,
        readyState: 'complete',
        visibilityState: 'visible',
        documentElement: {},
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
        createElement(tagName) {
            const node = genericNode(tagName);
            const normalizedTag = String(tagName).toLowerCase();
            if (normalizedTag === 'iframe') {
                const sandboxTokens = [];
                node.sandbox = { add(value) { sandboxTokens.push(value); } };
                node.contentWindow = { document: {} };
            }
            if (['div', 'span', 'aside', 'section', 'ins'].includes(normalizedTag)) metrics.directContainers += 1;
            return node;
        },
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
    class Event { constructor(type) { this.type = type; } }
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
        setTimeout,
        clearTimeout,
        setInterval(callback) { const id = ++intervalSequence; intervals.set(id, callback); return id; },
        clearInterval(id) { intervals.delete(id); },
        queueMicrotask,
        MutationObserver,
        Event,
        googletag,
        document,
        navigator: { globalPrivacyControl: false },
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        fetch: async (url) => {
            if (String(url).includes('/_global/control.json')) {
                return { ok: true, json: async () => ({ schemaVersion: 2, controls: structuredClone(globalControls) }) };
            }
            return { ok: true, json: async () => structuredClone(config) };
        },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        removeEventListener() {},
        dispatchEvent(event) { (listeners[event.type] || []).forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: true,
    };
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
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return {
        sandbox,
        metrics,
        elements,
        setGlobalControls(value) { globalControls = structuredClone(value); },
        addPlacement(code) { const node = domElement(code); elements.push(node); return node; },
        triggerMutation(nodes) { mutationCallback?.([{ addedNodes: nodes, removedNodes: [] }]); },
        runRefreshTimers() { [...intervals.values()].forEach((callback) => callback()); },
    };
}

test('AD_SERVING OFF starts zero advertising engines', async () => {
    const runtime = harness(baseConfig(), { ...openControls(), adServingDisabled: true });
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.deepEqual(runtime.metrics, {
        gptScripts: 0, prebidScripts: 0, directScripts: 0, gamSlots: 0, gamRequests: 0,
        prebidAuctions: 0, prebidRenders: 0, directContainers: 0, providerInitializations: 0,
    });
});

test('GAM OFF preserves standalone Prebid and independent Direct JS', async () => {
    const runtime = harness(baseConfig({ gam: false, standalone: true }), { ...openControls(), gamDisabled: true });
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gptScripts, 0);
    assert.equal(runtime.metrics.gamSlots, 0);
    assert.equal(runtime.metrics.prebidScripts, 1);
    assert.equal(runtime.metrics.prebidAuctions, 1);
    assert.equal(runtime.metrics.directScripts, 1);
});

test('PREBID OFF preserves GAM fallback and independent Direct JS', async () => {
    const runtime = harness(baseConfig(), { ...openControls(), prebidDisabled: true });
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.prebidScripts, 0);
    assert.equal(runtime.metrics.prebidAuctions, 0);
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.metrics.directScripts, 1);
});

test('DIRECT_JS OFF preserves GAM and standalone Prebid independently', async (t) => {
    await t.test('standalone Prebid remains eligible', async () => {
        const runtime = harness(baseConfig({ gam: false, standalone: true }), { ...openControls(), directJsDisabled: true });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.directScripts, 0);
        assert.equal(runtime.metrics.directContainers, 0);
        assert.equal(runtime.metrics.providerInitializations, 0);
        assert.equal(runtime.metrics.prebidScripts, 1);
    });
    await t.test('GAM remains eligible', async () => {
        const runtime = harness(baseConfig({ prebid: false }), { ...openControls(), directJsDisabled: true });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.directScripts, 0);
        assert.equal(runtime.metrics.gptScripts, 1);
        assert.equal(runtime.metrics.gamRequests, 1);
    });
});

test('all engines ON initializes GAM, GAM_BRIDGE Prebid, and independent Direct JS', async () => {
    const runtime = harness(baseConfig());
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gptScripts, 1);
    assert.equal(runtime.metrics.prebidScripts, 1);
    assert.equal(runtime.metrics.prebidAuctions, 1);
    assert.equal(runtime.metrics.gamRequests, 1);
    assert.equal(runtime.metrics.directScripts, 1);
    assert.equal(runtime.metrics.providerInitializations, 1);
});

test('site and placement controls are more restrictive than enabled platform state', async (t) => {
    await t.test('site master stops all engines', async () => {
        const selected = baseConfig();
        selected.controls.adServingDisabled = true;
        const runtime = harness(selected, openControls());
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts + runtime.metrics.prebidScripts + runtime.metrics.directScripts, 0);
    });
    await t.test('one disabled placement does not stop another engine', async () => {
        const selected = baseConfig({ prebid: false });
        selected.placements.find((item) => item.code === 'gam_slot').enabled = false;
        const runtime = harness(selected);
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts, 0);
        assert.equal(runtime.metrics.directScripts, 1);
    });
});

test('connection and demand-network results do not stop unrelated engines', async (t) => {
    await t.test('GAM connection kill leaves Direct JS', async () => {
        const selected = baseConfig({ prebid: false });
        selected.placements.find((item) => item.code === 'gam_slot').enabled = false;
        selected.engines = { gam: { enabled: false }, directJs: { enabled: true } };
        const runtime = harness(selected);
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts, 0);
        assert.equal(runtime.metrics.directScripts, 1);
    });
    await t.test('Demand network kill leaves Prebid and GAM', async () => {
        const selected = baseConfig();
        selected.placements.find((item) => item.code === 'direct_slot').enabled = false;
        selected.directDemand.enabled = false;
        selected.nativeDemand.enabled = false;
        const runtime = harness(selected);
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.directScripts, 0);
        assert.equal(runtime.metrics.gptScripts, 1);
        assert.equal(runtime.metrics.prebidScripts, 1);
    });
});

test('SPA rescans use the newest effective global controls', async () => {
    const selected = baseConfig({ gam: false, standalone: true, direct: false });
    selected.placements = [];
    const runtime = harness(selected);
    await runtime.sandbox.HorusMediaLoader.boot();

    runtime.setGlobalControls({ ...openControls(), prebidDisabled: true });
    await runtime.sandbox.HorusMediaLoader.refresh();
    selected.placements.push(placement('prebid_slot', 'PREBID_STANDALONE'));
    const inserted = runtime.addPlacement('prebid_slot');
    runtime.triggerMutation([inserted]);
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(runtime.metrics.prebidScripts, 0);

    runtime.setGlobalControls(openControls());
    await runtime.sandbox.HorusMediaLoader.refresh();
    await runtime.sandbox.HorusMediaLoader.scan();
    assert.equal(runtime.metrics.prebidScripts, 1);
});

test('refresh timers cannot bypass a later GAM kill', async () => {
    const runtime = harness(baseConfig({ prebid: false, direct: false, refresh: true }));
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.gamRequests, 1);
    runtime.setGlobalControls({ ...openControls(), gamDisabled: true });
    await runtime.sandbox.HorusMediaLoader.refresh();
    runtime.runRefreshTimers();
    assert.equal(runtime.metrics.gamRequests, 1);
});

test('legacy and malformed control fields fail safely without weakening kills', async (t) => {
    await t.test('legacy nativeDemandDisabled still stops Direct JS', async () => {
        const runtime = harness(baseConfig({ gam: false, prebid: false }), { nativeDemandDisabled: true });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.directScripts, 0);
    });
    await t.test('platform false cannot override a site kill', async () => {
        const selected = baseConfig({ gam: false, prebid: false });
        selected.controls.directJsDisabled = true;
        const runtime = harness(selected, openControls());
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.directScripts, 0);
    });
    await t.test('invalid present values never enable the affected engine', async () => {
        const runtime = harness(baseConfig({ prebid: false, direct: false }), { gamDisabled: 'false' });
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts, 0);
    });
    await t.test('a malformed non-object controls contract fails closed', async () => {
        const runtime = harness(baseConfig(), null);
        await runtime.sandbox.HorusMediaLoader.boot();
        assert.equal(runtime.metrics.gptScripts + runtime.metrics.prebidScripts + runtime.metrics.directScripts, 0);
    });
});
