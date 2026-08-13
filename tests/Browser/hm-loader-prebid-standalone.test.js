import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function standaloneConfig(overrides = {}) {
    const base = {
        siteKey: 'HM_STANDALONE',
        servingMode: 'HORUS_DIRECT',
        gamNetworkCode: null,
        configVersion: 15,
        status: 'active',
        immediatePause: false,
        debug: false,
        allowedHostnames: ['publisher.example'],
        loader: { version: '2.0.0', cacheBust: 15 },
        controls: { adServingDisabled: false, gamDisabled: false, prebidDisabled: false, directJsDisabled: false },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: false, maxClicks: 3, windowHours: 6, blockHours: 12 },
        pageTargeting: {},
        prebidEnabled: false,
        prebid: {
            enabled: true,
            deliveryMode: 'STANDALONE',
            build: { version: '11.15.0', url: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js', checksum: 'abc' },
            auction: { timeoutMs: 100, priceGranularity: 'medium', currency: 'USD', bidderSequence: 'fixed', consent: {}, allowActivities: {}, ortb2: {} },
            delivery: { mode: 'STANDALONE', bidderTimeoutReporting: true, gamFallback: false, lazyLoading: { enabled: true }, refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } },
            directRender: {
                implemented: true,
                supportedMediaTypes: ['banner'],
                suppressExpiredRender: true,
                allowTopWindowRenderers: false,
                sandbox: ['allow-forms', 'allow-popups', 'allow-popups-to-escape-sandbox', 'allow-same-origin', 'allow-scripts', 'allow-top-navigation-by-user-activation'],
            },
            adUnits: [{ code: 'slot_a', mediaTypes: { banner: { sizes: [[300, 250]] } }, bids: [{ bidder: 'msft', params: { placement_id: '42' } }] }],
        },
        nativeDemandEnabled: false,
        nativeDemand: { enabled: false, fallbackOrder: [], placements: {} },
        placements: [{
            code: 'slot_a', type: 'DISPLAY', status: 'active', enabled: true,
            renderer: 'PREBID_STANDALONE', rendererConflict: false,
            gamEnabled: false, prebidStandaloneEnabled: true, directJsEnabled: false, nativeEnabled: false,
            adUnitPath: null, sizes: [[300, 250]], responsiveMappings: [], targeting: {},
            lazyLoad: { enabled: false, fetchMarginPercent: 100, renderMarginPercent: 50, mobileScaling: 1 },
            refresh: { enabled: false, intervalSeconds: null, limit: null }, collapseEmptyDiv: true, safeFrame: false,
        }],
    };
    return deepMerge(base, overrides);
}

function deepMerge(target, source) {
    if (!source || typeof source !== 'object' || Array.isArray(source)) return source === undefined ? target : source;
    const result = { ...target };
    for (const [key, value] of Object.entries(source)) {
        if (value && typeof value === 'object' && !Array.isArray(value) && target[key] && typeof target[key] === 'object' && !Array.isArray(target[key])) {
            result[key] = deepMerge(target[key], value);
        } else result[key] = value;
    }
    return result;
}

function element(code, className = 'hm-ad') {
    const attrs = { 'data-placement': code };
    const children = [];
    const listeners = {};
    const node = {
        id: '', className, tagName: 'DIV', parentNode: null, childNodes: children, children,
        getAttribute(name) { return attrs[name] ?? null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        appendChild(child) { child.parentNode = node; children.push(child); return child; },
        removeChild(child) { const i = children.indexOf(child); if (i >= 0) children.splice(i, 1); child.parentNode = null; return child; },
        contains(child) { if (children.includes(child)) return true; return children.some((item) => item.contains?.(child)); },
        querySelectorAll(selector) { return selector === 'iframe' ? children.filter((item) => String(item.tagName).toLowerCase() === 'iframe') : []; },
        addEventListener(name, callback) { listeners[name] = callback; },
        removeEventListener(name) { delete listeners[name]; },
        _attrs: attrs,
    };
    return node;
}

function makeWinner(code, n = 1, overrides = {}) {
    return {
        adId: `ad-${n}`,
        adUnitCode: code,
        auctionId: `auction-${n}`,
        mediaType: 'banner',
        cpm: 1.25,
        ttl: 300,
        responseTimestamp: Date.now(),
        width: 300,
        height: 250,
        ...overrides,
    };
}

function harness(selectedConfig, options = {}) {
    const metrics = {
        gptLoads: 0, prebidLoads: 0, nativeLoads: 0, requests: [], renders: [], addAdUnits: [], removeAdUnits: [],
        prebidConfigs: [], intervals: [], mutationObservers: [], intersectionObservers: [], frames: [],
    };
    const elements = options.elements || selectedConfig.placements.map((placement) => element(placement.code));
    let requestIndex = 0;
    let mutationCallback = null;
    const intervalCallbacks = new Map();
    let intervalId = 0;
    const localStore = new Map(Object.entries(options.localStorage || {}));

    const script = {
        tagName: 'SCRIPT', src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: selectedConfig.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        attributes: { 'data-site-key': selectedConfig.siteKey },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
        addEventListener() {},
    };
    const headChildren = [];
    const immediateQueue = { push(callback) { callback(); return 1; } };

    function scriptNode() {
        const attrs = {};
        const listeners = {};
        return {
            tagName: 'SCRIPT', attributes: attrs, async: false, src: '', onload: null, onerror: null, parentNode: null,
            setAttribute(name, value) { attrs[name] = String(value); },
            getAttribute(name) { return attrs[name] ?? null; },
            hasAttribute(name) { return Object.hasOwn(attrs, name); },
            addEventListener(name, callback) { listeners[name] = callback; if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            removeEventListener(name) { delete listeners[name]; },
        };
    }

    function iframeNode() {
        const attrs = {};
        const tokens = [];
        const styles = {};
        const listeners = {};
        const frame = {
            tagName: 'IFRAME', attributes: attrs, parentNode: null, childNodes: [],
            contentWindow: { document: { __frame: true } },
            sandbox: { add(token) { if (!tokens.includes(token)) tokens.push(token); }, toString() { return tokens.join(' '); } },
            style: { setProperty(name, value) { styles[name] = value; } },
            setAttribute(name, value) { attrs[name] = String(value); },
            getAttribute(name) { return attrs[name] ?? null; },
            addEventListener(name, callback) { listeners[name] = callback; },
            removeEventListener(name) { delete listeners[name]; },
            contains() { return false; }, querySelectorAll() { return []; },
            _sandboxTokens: tokens, _styles: styles,
        };
        metrics.frames.push(frame);
        return frame;
    }

    function divNode() {
        const attrs = {};
        return {
            tagName: 'DIV', id: '', className: '', parentNode: null, childNodes: [], innerHTML: '',
            setAttribute(name, value) { attrs[name] = String(value); }, getAttribute(name) { return attrs[name] ?? null; },
            appendChild(child) { child.parentNode = this; this.childNodes.push(child); return child; },
            removeChild(child) { const i = this.childNodes.indexOf(child); if (i >= 0) this.childNodes.splice(i, 1); child.parentNode = null; },
            contains(child) { return this.childNodes.includes(child); }, querySelectorAll() { return []; },
        };
    }

    function installPbjs() {
        sandbox.pbjs = {
            que: immediateQueue,
            setConfig(value) { metrics.prebidConfigs.push(structuredClone(value)); },
            onEvent() {},
            removeAdUnit(codes) { metrics.removeAdUnits.push(...(Array.isArray(codes) ? codes : [codes])); },
            addAdUnits(units) { metrics.addAdUnits.push(...structuredClone(units)); },
            requestBids(request) {
                requestIndex += 1;
                metrics.requests.push({ n: requestIndex, codes: [...(request.adUnitCodes || [])], timeout: request.timeout });
                const behavior = typeof options.auction === 'function' ? options.auction(requestIndex, request) : (options.auction || 'winner');
                if (behavior === 'throw') throw new Error('bidder exploded');
                if (behavior === 'reject') return Promise.reject(new Error('bidder rejected'));
                if (behavior === 'timeout') return undefined;
                const code = request.adUnitCodes[0];
                let winner;
                if (behavior === 'no-bid') winner = null;
                else if (behavior && typeof behavior === 'object' && 'winner' in behavior) winner = behavior.winner;
                else winner = makeWinner(code, requestIndex);
                sandbox.pbjs.__winner = winner;
                const bids = winner ? { [code]: { bids: [winner] } } : {};
                const timedOut = behavior && typeof behavior === 'object' ? Boolean(behavior.timedOut) : false;
                const auctionId = winner?.auctionId || `auction-${requestIndex}`;
                queueMicrotask(() => request.bidsBackHandler(bids, timedOut, auctionId));
                return Promise.resolve({ bids, timedOut, auctionId });
            },
            getHighestCpmBids() {
                if (typeof options.highest === 'function') return options.highest(requestIndex, sandbox.pbjs.__winner);
                return sandbox.pbjs.__winner ? [sandbox.pbjs.__winner] : [];
            },
            renderAd(doc, adId) {
                if (options.renderThrows) throw new Error('render failed');
                metrics.renders.push({ doc, adId });
            },
            getBidResponsesForAdUnitCode() { return { bids: sandbox.pbjs.__winner ? [sandbox.pbjs.__winner] : [] }; },
        };
    }

    const document = {
        currentScript: script, readyState: 'complete', visibilityState: 'visible', documentElement: { clientWidth: 1024, clientHeight: 768 },
        head: {
            appendChild(node) {
                node.parentNode = this; headChildren.push(node);
                if (node.getAttribute?.('data-hm-gpt') === '1') { metrics.gptLoads += 1; queueMicrotask(() => node.onload?.()); return node; }
                if (node.getAttribute?.('data-hm-prebid') === '1') {
                    metrics.prebidLoads += 1;
                    if (options.prebidScript === 'failure') queueMicrotask(() => node.onerror?.(new Error('blocked')));
                    else { installPbjs(); queueMicrotask(() => node.onload?.()); }
                    return node;
                }
                if (node.getAttribute?.('data-hm-native-script')) {
                    metrics.nativeLoads += 1; queueMicrotask(() => node.onload?.()); return node;
                }
                return node;
            },
        },
        createElement(tag) { if (tag === 'script') return scriptNode(); if (tag === 'iframe') return iframeNode(); return divNode(); },
        querySelector(selector) {
            if (selector === 'script[data-hm-prebid="1"]') return headChildren.find((n) => n.getAttribute?.('data-hm-prebid') === '1') || null;
            if (selector === 'script[data-hm-gpt="1"]') return headChildren.find((n) => n.getAttribute?.('data-hm-gpt') === '1') || null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return elements.filter((e) => e.className === 'hm-ad');
            if (selector === '.hm-native[data-placement]') return elements.filter((e) => e.className === 'hm-native');
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver {
        constructor(callback) { mutationCallback = callback; metrics.mutationObservers.push(this); }
        observe() {} disconnect() {}
    }
    class IntersectionObserver {
        constructor(callback, settings) { this.callback = callback; this.settings = settings; metrics.intersectionObservers.push(this); }
        observe(node) { this.node = node; } disconnect() { this.disconnected = true; }
        trigger(isIntersecting = true) { this.callback([{ target: this.node, isIntersecting, intersectionRatio: isIntersecting ? 1 : 0 }]); }
    }
    class Event { constructor(type) { this.type = type; } }

    const sandbox = {
        console, URL, Promise, Object, JSON, Math, Date, Number, String, Boolean, Array, WeakSet,
        setTimeout, clearTimeout, queueMicrotask,
        setInterval(callback, ms) { const id = ++intervalId; intervalCallbacks.set(id, callback); metrics.intervals.push({ id, ms }); return id; },
        clearInterval(id) { intervalCallbacks.delete(id); },
        MutationObserver, IntersectionObserver, Event, document,
        navigator: { globalPrivacyControl: false },
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        localStorage: { getItem(k) { return localStore.has(k) ? localStore.get(k) : null; }, setItem(k, v) { localStore.set(k, String(v)); }, removeItem(k) { localStore.delete(k); } },
        fetch: async (url) => {
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            return { ok: true, json: async () => structuredClone(selectedConfig) };
        },
        addEventListener() {}, removeEventListener() {}, dispatchEvent() {},
        __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return {
        sandbox, metrics, elements, intervalCallbacks,
        triggerMutation(addedNodes = []) { mutationCallback?.([{ addedNodes, removedNodes: [] }]); },
    };
}

async function settle(ms = 10) { await new Promise((resolve) => setTimeout(resolve, ms)); }

test('no-GAM standalone banner auction renders a valid winner directly', async () => {
    const { sandbox, metrics, elements } = harness(standaloneConfig());
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 1);
    assert.equal(metrics.renders[0].adId, 'ad-1');
    assert.equal(elements[0].getAttribute('data-hm-prebid'), 'standalone-rendered');
});

test('standalone no-bid and bidder errors fail closed without rendering', async () => {
    for (const auction of ['no-bid', 'throw', 'reject']) {
        const { sandbox, metrics } = harness(standaloneConfig(), { auction });
        await sandbox.HorusMediaLoader.boot();
        await settle();
        assert.equal(metrics.renders.length, 0, auction);
    }
});

test('standalone auction timeout stops without rendering', async () => {
    const selected = standaloneConfig({ prebid: { auction: { timeoutMs: 100 } } });
    const { sandbox, metrics, elements } = harness(selected, { auction: 'timeout' });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 0);
    assert.equal(elements[0].getAttribute('data-hm-prebid'), 'timeout');
});

test('Prebid script failure fails closed and never reaches GPT or GAM', async () => {
    const { sandbox, metrics } = harness(standaloneConfig(), { prebidScript: 'failure' });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.prebidLoads, 1);
    assert.equal(metrics.renders.length, 0);
    assert.equal(metrics.gptLoads, 0);
});

test('pure standalone never loads GPT, creates GAM slots, or sets GAM targeting', async () => {
    const { sandbox, metrics } = harness(standaloneConfig());
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.gptLoads, 0);
    assert.equal(sandbox.googletag, undefined);
    assert.equal(metrics.prebidConfigs[0].allowTopWindowRenderers, false);
    assert.equal(metrics.prebidConfigs[0].auctionOptions.suppressExpiredRender, true);
});

test('standalone refresh runs a fresh auction and replaces rather than duplicates the iframe', async () => {
    const selected = standaloneConfig({ placements: [{
        ...standaloneConfig().placements[0],
        refresh: { enabled: true, intervalSeconds: 30, limit: 2 },
    }] });
    const { sandbox, metrics, elements, intervalCallbacks } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.renders.length, 1);
    assert.equal(elements[0].children.filter((c) => c.tagName === 'IFRAME').length, 1);
    const callback = [...intervalCallbacks.values()][0];
    callback();
    await settle();
    assert.equal(metrics.requests.length, 2);
    assert.equal(metrics.renders.length, 2);
    assert.equal(metrics.renders[1].adId, 'ad-2');
    assert.equal(elements[0].children.filter((c) => c.tagName === 'IFRAME').length, 1);
});

test('stale prior winner is not reused by a fresh standalone auction', async () => {
    const selected = standaloneConfig({ placements: [{ ...standaloneConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 2 } }] });
    const firstWinner = makeWinner('hm-HM_STANDALONE-slot_a-0', 1);
    const { sandbox, metrics, intervalCallbacks } = harness(selected, {
        auction(n) { return n === 1 ? { winner: firstWinner } : 'no-bid'; },
        highest() { return [firstWinner]; },
    });
    await sandbox.HorusMediaLoader.boot();
    [...intervalCallbacks.values()][0]();
    await settle();
    assert.equal(metrics.requests.length, 2);
    assert.equal(metrics.renders.length, 1);
});

test('expired winning bid is rejected before renderAd', async () => {
    const expired = makeWinner('hm-HM_STANDALONE-slot_a-0', 1, { ttl: 1, responseTimestamp: Date.now() - 5000 });
    const { sandbox, metrics } = harness(standaloneConfig(), { auction: { winner: expired } });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.renders.length, 0);
});

test('lazy standalone waits for visibility but does not hold Loader boot open', async () => {
    const selected = standaloneConfig({ placements: [{ ...standaloneConfig().placements[0], lazyLoad: { enabled: true, fetchMarginPercent: 100, renderMarginPercent: 50, mobileScaling: 1 } }] });
    const { sandbox, metrics } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 0);
    assert.equal(metrics.intersectionObservers.length, 1);
    metrics.intersectionObservers[0].trigger(true);
    await settle();
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 1);
});

test('SPA insertion is scanned once and rendered once', async () => {
    const elements = [];
    const { sandbox, metrics, triggerMutation } = harness(standaloneConfig(), { elements });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 0);
    const inserted = element('slot_a');
    elements.push(inserted);
    triggerMutation([inserted]);
    await settle(50);
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 1);
});

test('repeated scans and duplicate boot calls never duplicate a standalone placement render', async () => {
    const { sandbox, metrics } = harness(standaloneConfig());
    await Promise.all([sandbox.HorusMediaLoader.boot(), sandbox.HorusMediaLoader.boot()]);
    await sandbox.HorusMediaLoader.scan();
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 1);
});

test('Click Guard block prevents the initial standalone auction', async () => {
    const selected = standaloneConfig({ clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 } });
    const key = 'hm:click-guard:v1:' + selected.siteKey;
    const blocked = JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + 60_000 });
    const { sandbox, metrics } = harness(selected, { localStorage: { [key]: blocked } });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 0);
    assert.equal(metrics.renders.length, 0);
    sandbox.HorusMediaLoader._resetForTests();
});

test('Click Guard block prevents a standalone refresh auction', async () => {
    const selected = standaloneConfig({
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        placements: [{ ...standaloneConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 2 } }],
    });
    const { sandbox, metrics, intervalCallbacks } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 1);
    sandbox.localStorage.setItem('hm:click-guard:v1:' + selected.siteKey, JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + 60_000 }));
    [...intervalCallbacks.values()][0]();
    await settle();
    assert.equal(metrics.requests.length, 1);
    sandbox.HorusMediaLoader._resetForTests();
});

test('consent timeout with BLOCK_ADS prevents standalone auction', async () => {
    const selected = standaloneConfig({ privacy: { mode: 'STRICT', cmp: { timeoutMs: 100, actionOnTimeout: 'BLOCK_ADS' }, requireConsentBeforeAds: true } });
    const { sandbox, metrics } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 0);
    assert.equal(metrics.renders.length, 0);
});

test('standalone banner is isolated in the approved sandbox and has no unrestricted top navigation', async () => {
    const { sandbox, metrics } = harness(standaloneConfig());
    await sandbox.HorusMediaLoader.boot();
    const frame = metrics.frames.find((f) => f.getAttribute('data-hm-prebid-frame') === '1');
    assert.ok(frame);
    assert.deepEqual([...frame._sandboxTokens].sort(), [
        'allow-forms', 'allow-popups', 'allow-popups-to-escape-sandbox', 'allow-same-origin', 'allow-scripts', 'allow-top-navigation-by-user-activation',
    ].sort());
    assert.equal(frame._sandboxTokens.includes('allow-top-navigation'), false);
});

test('multiple standalone placements on one page auction independently', async () => {
    const selected = standaloneConfig({
        prebid: { adUnits: [
            { code: 'slot_a', mediaTypes: { banner: { sizes: [[300, 250]] } }, bids: [{ bidder: 'msft', params: { placement_id: 'a' } }] },
            { code: 'slot_b', mediaTypes: { banner: { sizes: [[728, 90]] } }, bids: [{ bidder: 'msft', params: { placement_id: 'b' } }] },
        ] },
        placements: [
            standaloneConfig().placements[0],
            { ...standaloneConfig().placements[0], code: 'slot_b', sizes: [[728, 90]] },
        ],
    });
    const { sandbox, metrics } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.requests.length, 2);
    assert.equal(metrics.renders.length, 2);
    assert.equal(metrics.gptLoads, 0);
});

test('standalone Prebid and unrelated Direct JS placement remain independent', async () => {
    const directPlacement = {
        ...standaloneConfig().placements[0], code: 'direct_slot', renderer: 'DIRECT_JS',
        prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
    };
    const selected = standaloneConfig({
        nativeDemandEnabled: true,
        nativeDemand: {
            enabled: true, fallbackOrder: ['MGID'], placements: {
                direct_slot: { enabled: true, candidates: [{ network: 'MGID', priority: 10, gamManaged: false, tag: { scriptUrl: 'https://jsc.mgid.com/example.js', containerId: 'mgid-direct', renderTimeoutMs: 1, assumeLoadedIsSuccess: true } }], house: null },
            },
        },
        placements: [standaloneConfig().placements[0], directPlacement],
    });
    const { sandbox, metrics } = harness(selected, { elements: [element('slot_a'), element('direct_slot')] });
    await sandbox.HorusMediaLoader.boot();
    await settle();
    assert.equal(metrics.requests.length, 1);
    assert.equal(metrics.renders.length, 1);
    assert.equal(metrics.nativeLoads, 1);
    assert.equal(metrics.gptLoads, 0);
});


test('Task 19 standalone Prebid and two Direct JS placements operate independently', async () => {
    const directA = {
        ...standaloneConfig().placements[0], code: 'direct_a', renderer: 'DIRECT_JS',
        prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
    };
    const directB = {
        ...standaloneConfig().placements[0], code: 'direct_b', renderer: 'DIRECT_JS',
        prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
    };
    const selected = standaloneConfig({
        nativeDemandEnabled: true,
        nativeDemand: {
            enabled: true, fallbackOrder: ['PROVIDER_A', 'PROVIDER_B'], placements: {
                direct_a: { enabled: true, candidates: [{ network: 'PROVIDER_A', priority: 10, gamManaged: false, tag: { scriptUrl: 'https://a.example.test/a.js', containerId: 'direct-a', renderTimeoutMs: 1, assumeLoadedIsSuccess: true } }], house: null },
                direct_b: { enabled: true, candidates: [{ network: 'PROVIDER_B', priority: 10, gamManaged: false, tag: { scriptUrl: 'https://b.example.test/b.js', containerId: 'direct-b', renderTimeoutMs: 1, assumeLoadedIsSuccess: true } }], house: null },
            },
        },
        placements: [standaloneConfig().placements[0], directA, directB],
    });
    const { sandbox, metrics } = harness(selected, { elements: [element('slot_a'), element('direct_a'), element('direct_b')] });
    await sandbox.HorusMediaLoader.boot();
    await settle();

    assert.equal(metrics.requests.length, 1, 'standalone Prebid starts its own auction');
    assert.equal(metrics.renders.length, 1, 'standalone Prebid renders independently');
    assert.equal(metrics.nativeLoads, 2, 'both Direct JS placements start without waiting for a global auction');
    assert.equal(metrics.gptLoads, 0, 'GAM/GPT is never introduced by parallel no-GAM serving');
});
