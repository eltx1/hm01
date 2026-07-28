import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function activeConfig(overrides = {}) {
    return {
        siteKey: 'HM_TEST',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        configVersion: 5,
        status: 'active',
        immediatePause: false,
        debug: false,
        allowedHostnames: ['publisher.example'],
        loader: { version: '1.0.0', cacheBust: 5 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', tagVersion: '1.0.0', singleRequest: true },
        pageTargeting: { site: ['test'] },
        placements: [{
            code: 'article_top',
            name: 'Article Top',
            type: 'DISPLAY',
            status: 'active',
            enabled: true,
            adUnitPath: '/123456789/article_top',
            sizes: [[300, 250], [728, 90]],
            responsiveMappings: [{ viewport: [768, 0], device: 'DESKTOP', sizes: [[728, 90]] }],
            targeting: { position: ['article_top'] },
            lazyLoad: { enabled: true, fetchMarginPercent: 500, renderMarginPercent: 200, mobileScaling: 2 },
            refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true,
            safeFrame: false,
            outOfPageFormat: null,
        }],
        ...overrides,
    };
}

function element(code) {
    const attributes = { 'data-placement': code };
    return {
        id: '',
        attributes,
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
    };
}

function createHarness(config, { hostname = 'publisher.example', placementCodes = ['article_top'] } = {}) {
    const metrics = {
        fetches: [], gptLoads: 0, defined: [], displayed: [], services: 0,
        pageTargeting: {}, slotTargeting: {}, lazy: null, singleRequest: 0,
    };
    const elements = placementCodes.map(element);
    const scriptAttributes = {
        'data-site-key': config.siteKey,
        'data-config-base': 'https://cdn.horusmedia.net/configs',
        'data-environment': 'production',
    };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: config.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute(name) { return scriptAttributes[name] ?? null; },
        setAttribute(name, value) { scriptAttributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(scriptAttributes, name); },
    };

    const pubads = {
        setTargeting(key, values) { metrics.pageTargeting[key] = values; return this; },
        enableLazyLoad(value) { metrics.lazy = value; },
        enableSingleRequest() { metrics.singleRequest += 1; },
        refresh() {},
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const googletag = {
        cmd: immediateQueue,
        apiReady: false,
        pubadsReady: false,
        pubads() { return pubads; },
        sizeMapping() {
            const mappings = [];
            return { addSize(viewport, sizes) { mappings.push({ viewport, sizes }); return this; }, build() { return mappings; } };
        },
        defineSlot(path, sizes, id) {
            metrics.defined.push({ path, sizes, id });
            const slot = {
                setTargeting(key, values) { metrics.slotTargeting[key] = values; return slot; },
                defineSizeMapping(value) { slot.mapping = value; return slot; },
                setForceSafeFrame(value) { slot.safeFrame = value; return slot; },
                setCollapseEmptyDiv(value) { slot.collapse = value; return slot; },
                addService() { return slot; },
            };
            return slot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { metrics.services += 1; googletag.apiReady = true; },
        display(value) { metrics.displayed.push(typeof value === 'string' ? value : 'out-of-page'); },
        enums: { OutOfPageFormat: { INTERSTITIAL: 'INTERSTITIAL', REWARDED: 'REWARDED' } },
    };

    const document = {
        currentScript: script,
        readyState: 'complete',
        visibilityState: 'visible',
        documentElement: {},
        head: {
            appendChild(node) {
                if (node.getAttribute && node.getAttribute('data-hm-gpt') === '1') {
                    metrics.gptLoads += 1;
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
        querySelector(selector) { return selector === 'script[data-hm-gpt="1"]' ? null : null; },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return elements;
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver { observe() {} disconnect() {} }
    class Event { constructor(type) { this.type = type; } }
    const listeners = {};
    const history = { pushState() {}, replaceState() {} };
    const sandbox = {
        console,
        URL,
        Promise,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        queueMicrotask,
        MutationObserver,
        Event,
        googletag,
        document,
        location: { hostname, href: `https://${hostname}/article` },
        history,
        fetch: async (url) => {
            metrics.fetches.push(String(url));
            return { ok: true, json: async () => structuredClone(config) };
        },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        dispatchEvent(event) { (listeners[event.type] || []).forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return { sandbox, metrics, elements };
}

test('loads GPT once, defines the slot, applies mappings and targeting', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.scan();

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.defined.length, 1);
    assert.equal(metrics.defined[0].path, '/123456789/article_top');
    assert.deepEqual(metrics.pageTargeting.site, ['test']);
    assert.deepEqual(metrics.slotTargeting.position, ['article_top']);
    assert.equal(metrics.singleRequest, 1);
    assert.equal(metrics.services, 1);
    assert.equal(metrics.displayed.length, 1);
    assert.equal(metrics.fetches.length, 1);
});

test('paused sites never load GPT or define an advertising slot', async () => {
    const { sandbox, metrics } = createHarness(activeConfig({ status: 'paused', immediatePause: true }));
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
    assert.equal(metrics.displayed.length, 0);
});

test('disabled placements generate no Google advertising calls', async () => {
    const config = activeConfig();
    config.placements[0].enabled = false;
    config.placements[0].status = 'disabled';
    const { sandbox, metrics } = createHarness(config);
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
});

test('unauthorized hostnames cannot load valid placements', async () => {
    const { sandbox, metrics } = createHarness(activeConfig(), { hostname: 'attacker.example' });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.fetches.length, 1);
    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
});

test('force refresh cache-busts the static configuration without a Laravel request', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.refresh();

    assert.equal(metrics.fetches.length, 2);
    assert.match(metrics.fetches[0], /\/configs\/HM_TEST\/production\.json$/);
    assert.match(metrics.fetches[1], /production\.json\?v=\d+$/);
    assert.ok(metrics.fetches.every((url) => url.startsWith('https://cdn.horusmedia.net/configs/')));
    assert.equal(metrics.gptLoads, 1);
});
