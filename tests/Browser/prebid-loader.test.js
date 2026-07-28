import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function config(overrides = {}) {
    return {
        siteKey: 'HM_PREBID_TEST',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        configVersion: 8,
        status: 'active',
        immediatePause: false,
        debug: true,
        allowedHostnames: ['publisher.example'],
        loader: { version: '1.1.0' },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        pageTargeting: {},
        prebidEnabled: true,
        prebid: {
            enabled: true,
            build: { version: 'horus-test', assetUrl: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js' },
            auctionTimeoutMs: 50,
            priceGranularity: 'dense',
            currency: 'USD',
            bidderSequence: 'random',
            consentManagement: {},
            userSync: {},
            refresh: { enabled: false, intervalSeconds: null },
            timeoutReportingEnabled: true,
            gamFallbackEnabled: true,
            sendAllBids: false,
            debug: true,
            advancedConfig: {},
            adUnits: {
                article_top: {
                    mediaTypes: { banner: { sizes: [[300, 250]] } },
                    bids: [{ bidder: 'appnexus', params: { placementId: '12345' } }],
                },
            },
        },
        placements: [{
            code: 'article_top', name: 'Article Top', type: 'DISPLAY', status: 'active', enabled: true,
            adUnitPath: '/123456789/article_top', sizes: [[300, 250]], responsiveMappings: [], targeting: {},
            lazyLoad: { enabled: false }, refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true, safeFrame: false, outOfPageFormat: null,
        }],
        ...overrides,
    };
}

function harness(siteConfig, { prebidBehavior = 'success' } = {}) {
    const metrics = {
        gptLoads: 0, prebidLoads: 0, disableInitialLoad: 0, refreshes: 0,
        displays: 0, auctions: 0, targeting: 0, addedAdUnits: [], order: [],
    };
    const attributes = { 'data-placement': 'article_top' };
    const placementElement = {
        id: '',
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
    };
    const loaderScript = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: siteConfig.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute(name) {
            return ({ 'data-site-key': siteConfig.siteKey, 'data-config-base': 'https://cdn.horusmedia.net/configs', 'data-environment': 'production' })[name] ?? null;
        },
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const pubads = {
        setTargeting() { return this; }, enableLazyLoad() {}, enableSingleRequest() {},
        disableInitialLoad() { metrics.disableInitialLoad += 1; metrics.order.push('disable-initial-load'); },
        refresh() { metrics.refreshes += 1; metrics.order.push('gpt-refresh'); },
    };
    const googletag = {
        cmd: immediateQueue, apiReady: false, pubadsReady: false,
        pubads() { return pubads; },
        sizeMapping() { return { addSize() { return this; }, build() { return []; } }; },
        defineSlot(path, sizes, id) {
            return {
                path, sizes, id, setTargeting() { return this; }, defineSizeMapping() { return this; },
                setForceSafeFrame() { return this; }, setCollapseEmptyDiv() { return this; }, addService() { return this; },
            };
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { googletag.apiReady = true; },
        display() { metrics.displays += 1; metrics.order.push('gpt-display'); },
        enums: { OutOfPageFormat: {} },
    };
    const pbjs = {
        que: immediateQueue,
        libLoaded: false,
        setConfig() {},
        addAdUnits(units) { metrics.addedAdUnits.push(...units); },
        removeAdUnit() {},
        onEvent() {},
        requestBids(options) {
            metrics.auctions += 1;
            metrics.order.push('auction');
            if (prebidBehavior === 'success') queueMicrotask(options.bidsBackHandler);
            if (prebidBehavior === 'throw') throw new Error('bidder failure');
        },
        setTargetingForGPTAsync(codes) { metrics.targeting += 1; metrics.order.push('prebid-targeting'); metrics.targetingCodes = codes; },
    };
    const createdScripts = [];
    const document = {
        currentScript: loaderScript, readyState: 'complete', visibilityState: 'visible', documentElement: {},
        head: { appendChild(node) {
            createdScripts.push(node);
            if (node.getAttribute('data-hm-gpt') === '1') {
                metrics.gptLoads += 1;
                queueMicrotask(() => node.onload?.());
            }
            if (node.getAttribute('data-hm-prebid') === '1') {
                metrics.prebidLoads += 1;
                queueMicrotask(() => { pbjs.libLoaded = true; node.onload?.(); });
            }
        } },
        createElement() {
            const attrs = {};
            return {
                src: '', async: false, onload: null, onerror: null,
                setAttribute(name, value) { attrs[name] = String(value); },
                getAttribute(name) { return attrs[name] ?? null; },
                addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            };
        },
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return [placementElement];
            if (selector === 'script[data-site-key]') return [loaderScript];
            return [];
        },
        addEventListener() {},
    };
    class MutationObserver { observe() {} }
    class Event { constructor(type) { this.type = type; } }
    const listeners = {};
    const sandbox = {
        console, URL, Promise, setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        MutationObserver, Event, document, googletag, pbjs,
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        fetch: async () => ({ ok: true, json: async () => structuredClone(siteConfig) }),
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        dispatchEvent(event) { (listeners[event.type] || []).forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });
    return { sandbox, metrics };
}

test('runs Prebid before GPT refresh and loads each library once', async () => {
    const { sandbox, metrics } = harness(config());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.scan();

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.prebidLoads, 1);
    assert.equal(metrics.disableInitialLoad, 1);
    assert.equal(metrics.auctions, 1);
    assert.equal(metrics.targeting, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(metrics.addedAdUnits.length, 1);
    assert.deepEqual(metrics.targetingCodes, [metrics.addedAdUnits[0].code]);
    assert.ok(metrics.order.indexOf('auction') < metrics.order.indexOf('prebid-targeting'));
    assert.ok(metrics.order.indexOf('prebid-targeting') < metrics.order.indexOf('gpt-refresh'));
});

test('Prebid exception falls back to GAM without breaking the page', async () => {
    const { sandbox, metrics } = harness(config(), { prebidBehavior: 'throw' });
    const result = await sandbox.HorusMediaLoader.boot();

    assert.equal(Array.isArray(result), true);
    assert.equal(metrics.auctions, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(sandbox.__HM_DIAGNOSTICS__.auctionFailures, 1);
    assert.equal(sandbox.__HM_DIAGNOSTICS__.gamFallbacks, 1);
});

test('auction safety timeout falls back to GAM', async () => {
    const timed = config();
    timed.prebid.auctionTimeoutMs = 100;
    const { sandbox, metrics } = harness(timed, { prebidBehavior: 'timeout' });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.auctions, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(sandbox.__HM_DIAGNOSTICS__.gamFallbacks, 1);
});

test('disabled Prebid uses normal GAM delivery without loading a build', async () => {
    const disabled = config({ prebidEnabled: false, prebid: { enabled: false } });
    const { sandbox, metrics } = harness(disabled);
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.prebidLoads, 0);
    assert.equal(metrics.disableInitialLoad, 0);
    assert.equal(metrics.displays, 1);
    assert.equal(metrics.refreshes, 0);
});
