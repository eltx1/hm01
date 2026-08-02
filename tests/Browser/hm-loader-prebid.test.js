import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function config(overrides = {}) {
    return {
        siteKey: 'HM_PREBID',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        configVersion: 7,
        status: 'active',
        immediatePause: false,
        debug: true,
        allowedHostnames: ['publisher.example'],
        loader: { version: '1.1.0', cacheBust: 7 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        pageTargeting: {},
        placements: [{
            code: 'article_top',
            type: 'DISPLAY',
            status: 'active',
            enabled: true,
            adUnitPath: '/123456789/article_top',
            sizes: [[300, 250]],
            responsiveMappings: [],
            targeting: {},
            lazyLoad: { enabled: false },
            refresh: { enabled: false },
            collapseEmptyDiv: true,
            safeFrame: false,
            outOfPageFormat: null,
        }],
        prebid: {
            enabled: true,
            build: { version: '11.14.0', url: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js' },
            auction: { timeoutMs: 25, priceGranularity: 'medium', currency: 'USD', bidderSequence: 'fixed', consent: {} },
            delivery: { bidderTimeoutReporting: true, gamFallback: true, lazyLoading: { enabled: true }, refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } },
            adUnits: [{ code: 'article_top', mediaTypes: { banner: { sizes: [[300, 250]] } }, bids: [{ bidder: 'msft', params: { placement_id: 42 } }] }],
        },
        ...overrides,
    };
}

function harness(selectedConfig, { prebid = 'success' } = {}) {
    const metrics = { gptLoads: 0, prebidLoads: 0, refreshes: 0, addAdUnits: [], targeting: 0, requests: 0 };
    const element = {
        id: '',
        attributes: { 'data-placement': 'article_top' },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
    };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: selectedConfig.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        attributes: { 'data-site-key': selectedConfig.siteKey },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const pubads = {
        disableInitialLoad() {},
        enableSingleRequest() {},
        setTargeting() { return this; },
        refresh() { metrics.refreshes += 1; },
    };
    const googletag = {
        cmd: immediateQueue,
        pubads() { return pubads; },
        sizeMapping() { return { addSize() { return this; }, build() { return []; } }; },
        defineSlot() {
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
    const document = {
        currentScript: script,
        readyState: 'complete',
        visibilityState: 'visible',
        documentElement: {},
        head: {
            appendChild(node) {
                if (node.getAttribute('data-hm-gpt') === '1') {
                    metrics.gptLoads += 1;
                    queueMicrotask(() => node.onload?.());
                    return;
                }
                if (node.getAttribute('data-hm-prebid') === '1') {
                    metrics.prebidLoads += 1;
                    if (prebid === 'failure') {
                        queueMicrotask(() => node.onerror?.(new Error('blocked')));
                        return;
                    }
                    sandbox.pbjs = {
                        que: immediateQueue,
                        setConfig() {},
                        onEvent() {},
                        removeAdUnit() {},
                        addAdUnits(units) { metrics.addAdUnits.push(...units); },
                        requestBids(options) {
                            metrics.requests += 1;
                            if (prebid === 'success') queueMicrotask(() => options.bidsBackHandler());
                        },
                        setTargetingForGPTAsync() { metrics.targeting += 1; },
                        getBidResponsesForAdUnitCode() { return { bids: [{ cpm: 1.2 }] }; },
                    };
                    queueMicrotask(() => node.onload?.());
                }
            },
        },
        createElement() {
            const attributes = {};
            return {
                attributes, async: false, src: '', onload: null, onerror: null,
                setAttribute(name, value) { attributes[name] = String(value); },
                getAttribute(name) { return attributes[name] ?? null; },
                addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            };
        },
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return [element];
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };
    class MutationObserver { observe() {} }
    class Event { constructor(type) { this.type = type; } }
    const sandbox = {
        console, URL, Promise, Object, JSON,
        setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        MutationObserver, Event, googletag, document,
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        fetch: async () => ({ ok: true, json: async () => structuredClone(selectedConfig) }),
        addEventListener() {}, dispatchEvent() {},
        __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return { sandbox, metrics, element };
}

test('browser Prebid auction applies targeting before GAM refresh', async () => {
    const { sandbox, metrics } = harness(config());
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.prebidLoads, 1);
    assert.equal(metrics.requests, 1);
    assert.equal(metrics.addAdUnits.length, 1);
    assert.match(metrics.addAdUnits[0].code, /^hm-HM_PREBID-article_top-/);
    assert.equal(metrics.targeting, 1);
    assert.equal(metrics.refreshes, 1);
});

test('Prebid script failure falls back to GAM safely', async () => {
    const { sandbox, metrics, element } = harness(config(), { prebid: 'failure' });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.prebidLoads, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(element.getAttribute('data-hm-prebid'), 'failed');
});

test('Prebid timeout cannot block GAM fallback', async () => {
    const { sandbox, metrics, element } = harness(config(), { prebid: 'timeout' });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.requests, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(element.getAttribute('data-hm-prebid'), 'timeout');
});

test('disabled Prebid never loads its build and GAM still requests', async () => {
    const selected = config();
    selected.prebid.enabled = false;
    const { sandbox, metrics } = harness(selected);
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.prebidLoads, 0);
    assert.equal(metrics.refreshes, 1);
});
