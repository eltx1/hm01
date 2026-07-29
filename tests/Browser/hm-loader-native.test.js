import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function selectedConfig({ gam = false, candidates, house = null, debug = true } = {}) {
    return {
        siteKey: 'HM_NATIVE',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        configVersion: 9,
        status: 'active',
        immediatePause: false,
        debug,
        allowedHostnames: ['publisher.example'],
        loader: { version: '1.2.0', cacheBust: 9 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        pageTargeting: {},
        placements: [{
            code: 'article_native',
            name: 'Article Native',
            type: 'NATIVE',
            status: 'active',
            enabled: true,
            gamEnabled: gam,
            nativeEnabled: true,
            adUnitPath: gam ? '/123456789/article_native' : null,
            sizes: [[1, 1]],
            responsiveMappings: [],
            targeting: {},
            lazyLoad: { enabled: false },
            refresh: { enabled: false },
            collapseEmptyDiv: true,
            safeFrame: true,
            outOfPageFormat: null,
        }],
        prebid: { enabled: false, build: null, auction: {}, delivery: { gamFallback: true }, adUnits: [] },
        nativeDemandEnabled: true,
        nativeDemand: {
            enabled: true,
            fallbackOrder: ['GAM', 'MGID', 'TABOOLA', 'SPEAKOL', 'HOUSE'],
            placements: {
                article_native: {
                    enabled: true,
                    candidates: candidates ?? [{
                        network: 'MGID',
                        mode: 'DIRECT_JS',
                        priority: 10,
                        gamManaged: false,
                        tag: {
                            scriptUrl: 'https://jsc.mgid.com/publisher.example/article.js',
                            containerId: 'mgid-widget-1',
                            containerClass: 'mgbox',
                            attributes: { 'data-type': '_mgwidget' },
                            renderTimeoutMs: 1,
                            successSelector: null,
                        },
                    }],
                    house,
                },
            },
        },
    };
}

function harness(config, { gamEmpty = false, failingOrigins = [] } = {}) {
    const metrics = { gptLoads: 0, nativeLoads: [], refreshes: 0, events: {}, logs: [] };
    const placement = {
        id: '',
        innerHTML: '',
        childNodes: [],
        attributes: { 'data-placement': 'article_native' },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        appendChild(node) { this.childNodes.push(node); node.parentNode = this; },
    };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: config.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        attributes: { 'data-site-key': config.siteKey },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    let activeSlot = null;
    const pubads = {
        disableInitialLoad() {}, enableSingleRequest() {}, setTargeting() { return this; },
        addEventListener(name, callback) { metrics.events[name] = callback; },
        refresh(slots) {
            metrics.refreshes += 1;
            queueMicrotask(() => metrics.events.slotRenderEnded?.({ slot: slots[0], isEmpty: gamEmpty }));
        },
    };
    const googletag = {
        cmd: immediateQueue,
        pubads() { return pubads; },
        sizeMapping() { return { addSize() { return this; }, build() { return []; } }; },
        defineSlot() {
            activeSlot = {
                setTargeting() { return activeSlot; }, defineSizeMapping() { return activeSlot; },
                setForceSafeFrame() { return activeSlot; }, setCollapseEmptyDiv() { return activeSlot; },
                addService() { return activeSlot; },
            };
            return activeSlot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { googletag.apiReady = true; }, display() {}, enums: { OutOfPageFormat: {} },
    };

    const allNodes = [];
    const document = {
        currentScript: script,
        readyState: 'complete', visibilityState: 'visible', documentElement: {},
        head: {
            appendChild(node) {
                allNodes.push(node);
                if (node.getAttribute?.('data-hm-gpt') === '1') {
                    metrics.gptLoads += 1;
                    queueMicrotask(() => node.onload?.());
                    return;
                }
                const network = node.getAttribute?.('data-hm-native-script');
                if (network) {
                    metrics.nativeLoads.push({ network, src: node.src });
                    if (failingOrigins.some((origin) => node.src.startsWith(origin))) {
                        queueMicrotask(() => node.onerror?.(new Error('blocked')));
                        return;
                    }
                    const container = placement.childNodes.at(-1);
                    if (container) container.innerHTML = '<a href="https://example.test">native rendered</a>';
                    queueMicrotask(() => node.onload?.());
                }
            },
        },
        createElement(tagName) {
            const attributes = {};
            return {
                tagName: String(tagName).toUpperCase(), attributes, id: '', className: '', innerHTML: '', childNodes: [],
                async: false, src: '', onload: null, onerror: null,
                setAttribute(name, value) { attributes[name] = String(value); },
                getAttribute(name) { return attributes[name] ?? null; },
                addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
                appendChild(node) { this.childNodes.push(node); },
            };
        },
        querySelector(selector) {
            if (selector === 'script[data-hm-gpt="1"]') return null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return [placement];
            if (selector === '.hm-native[data-placement]') return [];
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };
    class MutationObserver { observe() {} }
    class Event { constructor(type) { this.type = type; } }
    const sandbox = {
        console: { info(...args) { metrics.logs.push(args); }, error: console.error },
        URL, Promise, Object, JSON, setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        MutationObserver, Event, googletag, document,
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        fetch: async () => ({ ok: true, json: async () => structuredClone(config) }),
        addEventListener() {}, dispatchEvent() {}, __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return { sandbox, metrics, placement };
}

test('direct-JS native placement renders without loading GPT', async () => {
    const { sandbox, metrics, placement } = harness(selectedConfig());
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.nativeLoads.length, 1);
    assert.equal(metrics.nativeLoads[0].network, 'MGID');
    assert.equal(placement.getAttribute('data-hm-native'), 'MGID');
    assert.equal(placement.getAttribute('data-hm-status'), 'rendered');
});

test('empty GAM slot starts the direct native fallback sequence', async () => {
    const { sandbox, metrics, placement } = harness(selectedConfig({ gam: true }), { gamEmpty: true });
    await sandbox.HorusMediaLoader.boot();
    await new Promise((resolve) => setTimeout(resolve, 10));

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.refreshes, 1);
    assert.equal(metrics.nativeLoads.length, 1);
    assert.equal(placement.getAttribute('data-hm-gam'), 'empty');
    assert.equal(placement.getAttribute('data-hm-native'), 'MGID');
});

test('failed MGID script advances to Taboola and records debug-safe state', async () => {
    const candidates = [
        {
            network: 'MGID', mode: 'DIRECT_JS', priority: 10, gamManaged: false,
            tag: { scriptUrl: 'https://jsc.mgid.com/fail.js', containerId: 'mgid', renderTimeoutMs: 1, attributes: {} },
        },
        {
            network: 'TABOOLA', mode: 'DIRECT_JS', priority: 20, gamManaged: false,
            tag: { scriptUrl: 'https://cdn.taboola.com/success.js', containerId: 'taboola', renderTimeoutMs: 1, attributes: {} },
        },
    ];
    const { sandbox, metrics, placement } = harness(selectedConfig({ candidates }), { failingOrigins: ['https://jsc.mgid.com'] });
    await sandbox.HorusMediaLoader.boot();

    assert.deepEqual(metrics.nativeLoads.map((item) => item.network), ['MGID', 'TABOOLA']);
    assert.equal(placement.getAttribute('data-hm-native'), 'TABOOLA');
    assert.equal(sandbox.__HM_DIAGNOSTICS__.nativeDemandEnabled, true);
});

test('GAM-managed native candidate is not injected directly', async () => {
    const candidates = [{ network: 'MGID', mode: 'GAM_THIRD_PARTY_CREATIVE', priority: 10, gamManaged: true }];
    const { sandbox, metrics, placement } = harness(selectedConfig({ gam: true, candidates }), { gamEmpty: false });
    await sandbox.HorusMediaLoader.boot();
    await new Promise((resolve) => setTimeout(resolve, 5));

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.nativeLoads.length, 0);
    assert.equal(placement.getAttribute('data-hm-gam'), 'rendered');
});

test('exhausted network candidates render sanitized house content', async () => {
    const candidates = [{
        network: 'MGID', mode: 'DIRECT_JS', priority: 10, gamManaged: false,
        tag: { scriptUrl: 'https://jsc.mgid.com/fail.js', containerId: 'mgid', renderTimeoutMs: 1, attributes: {} },
    }];
    const { sandbox, placement } = harness(selectedConfig({ candidates, house: { html: '<p>House story</p>' } }), { failingOrigins: ['https://jsc.mgid.com'] });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(placement.getAttribute('data-hm-native'), 'HOUSE');
    assert.match(placement.innerHTML, /House story/);
});
