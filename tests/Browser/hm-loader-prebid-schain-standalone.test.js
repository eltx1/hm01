import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function config() {
    const schain = {
        complete: 1,
        ver: '1.0',
        nodes: [{ asi: 'horusmedia.net', sid: 'canonical-seller-100', hp: 1 }],
    };

    return {
        siteKey: 'HM_SCHAIN_STANDALONE',
        servingMode: 'HORUS_DIRECT',
        gamNetworkCode: null,
        configVersion: 36,
        status: 'active',
        immediatePause: false,
        debug: false,
        allowedHostnames: ['publisher.example'],
        loader: { version: '2.0.0', cacheBust: 36 },
        controls: { adServingDisabled: false, gamDisabled: false, prebidDisabled: false, directJsDisabled: false },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: false, maxClicks: 3, windowHours: 6, blockHours: 12 },
        pageTargeting: {},
        supplyChain: { schain },
        prebidEnabled: true,
        prebid: {
            enabled: true,
            deliveryMode: 'STANDALONE',
            build: { version: '11.15.0', url: 'https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js', checksum: 'abc' },
            auction: {
                timeoutMs: 100,
                priceGranularity: 'medium',
                currency: 'USD',
                bidderSequence: 'fixed',
                consent: {},
                allowActivities: {},
                ortb2: {
                    site: {
                        domain: 'publisher.example',
                        publisher: { name: 'Canonical Publisher', domain: 'publisher.example' },
                    },
                },
            },
            delivery: { mode: 'STANDALONE', bidderTimeoutReporting: true, gamFallback: false, lazyLoading: { enabled: true }, refreshBehavior: { enabled: false } },
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
}

function harness(selectedConfig) {
    const metrics = { prebidLoads: 0, gptLoads: 0, requests: 0, prebidConfig: null };
    const attrs = { 'data-placement': 'slot_a' };
    const element = {
        id: '', className: 'hm-ad', children: [], childNodes: [],
        getAttribute(name) { return attrs[name] ?? null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
        removeChild(child) { this.children = this.children.filter((item) => item !== child); child.parentNode = null; },
        contains(child) { return this.children.includes(child); },
        querySelectorAll() { return []; },
        addEventListener() {}, removeEventListener() {},
    };
    const script = {
        tagName: 'SCRIPT', src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: selectedConfig.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        attributes: { 'data-site-key': selectedConfig.siteKey },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
        addEventListener() {},
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const headChildren = [];

    function installPbjs() {
        sandbox.pbjs = {
            que: immediateQueue,
            setConfig(value) { metrics.prebidConfig = structuredClone(value); },
            onEvent() {},
            removeAdUnit() {},
            addAdUnits() {},
            requestBids(request) {
                metrics.requests += 1;
                queueMicrotask(() => request.bidsBackHandler({}, false, 'auction-36'));
                return Promise.resolve({});
            },
            getHighestCpmBids() { return []; },
            getBidResponsesForAdUnitCode() { return { bids: [] }; },
            renderAd() { throw new Error('No-bid test must not render.'); },
        };
    }

    function scriptNode() {
        const nodeAttrs = {};
        return {
            tagName: 'SCRIPT', async: false, src: '', onload: null, onerror: null, parentNode: null,
            setAttribute(name, value) { nodeAttrs[name] = String(value); },
            getAttribute(name) { return nodeAttrs[name] ?? null; },
            hasAttribute(name) { return Object.hasOwn(nodeAttrs, name); },
            addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            removeEventListener() {},
        };
    }

    const document = {
        currentScript: script, readyState: 'complete', visibilityState: 'visible',
        documentElement: { clientWidth: 1024, clientHeight: 768 },
        head: {
            appendChild(node) {
                node.parentNode = this;
                headChildren.push(node);
                if (node.getAttribute?.('data-hm-gpt') === '1') {
                    metrics.gptLoads += 1;
                    queueMicrotask(() => node.onload?.());
                }
                if (node.getAttribute?.('data-hm-prebid') === '1') {
                    metrics.prebidLoads += 1;
                    installPbjs();
                    queueMicrotask(() => node.onload?.());
                }
                return node;
            },
        },
        createElement(tag) {
            if (tag === 'script') return scriptNode();
            return { tagName: tag.toUpperCase(), style: { setProperty() {} }, sandbox: { add() {} }, setAttribute() {}, getAttribute() { return null; } };
        },
        querySelector(selector) {
            if (selector === 'script[data-hm-prebid="1"]') return headChildren.find((node) => node.getAttribute?.('data-hm-prebid') === '1') || null;
            if (selector === 'script[data-hm-gpt="1"]') return headChildren.find((node) => node.getAttribute?.('data-hm-gpt') === '1') || null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return [element];
            if (selector === '.hm-native[data-placement]') return [];
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver { constructor() {} observe() {} disconnect() {} }
    class IntersectionObserver { constructor() {} observe() {} disconnect() {} }
    class Event { constructor(type) { this.type = type; } }
    const store = new Map();
    const sandbox = {
        console, URL, Promise, Object, JSON, Math, Date, Number, String, Boolean, Array, WeakSet,
        setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        MutationObserver, IntersectionObserver, Event, document,
        navigator: { globalPrivacyControl: false },
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        localStorage: { getItem(key) { return store.get(key) ?? null; }, setItem(key, value) { store.set(key, String(value)); }, removeItem(key) { store.delete(key); } },
        fetch: async (url) => {
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            return { ok: true, json: async () => structuredClone(selectedConfig) };
        },
        addEventListener() {}, removeEventListener() {}, dispatchEvent() {},
        __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return { sandbox, metrics };
}

test('STANDALONE receives the canonical Horus schain through the single ORTB2 setConfig path', async () => {
    const selected = config();
    const { sandbox, metrics } = harness(selected);

    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.prebidLoads, 1);
    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.requests, 1);
    assert.deepEqual(metrics.prebidConfig.ortb2.source.ext.schain, selected.supplyChain.schain);
    assert.equal(metrics.prebidConfig.ortb2.source.ext.schain.nodes[0].sid, 'canonical-seller-100');
    assert.equal(metrics.prebidConfig.ortb2.site.publisher.id, undefined);
    assert.equal(metrics.prebidConfig.ortb2.site.publisher.domain, 'publisher.example');
});
