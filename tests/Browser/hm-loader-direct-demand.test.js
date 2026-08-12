import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function recipe({ url = 'https://ads.example.com/shared.js', dedupeKey = 'shared-loader', scripts, executionMode = 'STRUCTURED', isolation = null } = {}) {
    const selectedScripts = scripts ?? [{ url, async: true, defer: false, dedupeKey, attributes: {} }];
    return {
        recipeVersion: 1,
        executionMode,
        format: 'DISPLAY',
        scripts: selectedScripts,
        container: { element: 'div', id: 'zone', class: 'provider-zone', attributes: { 'data-zone-id': '100' } },
        publicPlacementId: '100',
        initialization: { type: 'NONE', parameters: {} },
        render: { timeoutMs: 1, successSelector: null, assumeLoadedIsSuccess: true, allowedFormats: ['DISPLAY'], allowedSizes: [[300, 250]] },
        isolation,
        scriptUrl: selectedScripts[0]?.url || '',
        containerId: 'zone',
        containerClass: 'provider-zone',
        attributes: { 'data-zone-id': '100' },
        renderTimeoutMs: 1,
        assumeLoadedIsSuccess: true,
    };
}

function candidate(network, tag, priority = 10) {
    return { network, mode: 'DIRECT_JS', priority, gamManaged: false, tag };
}

function placement(code, overrides = {}) {
    return {
        code, name: code, type: 'DISPLAY', status: 'active', enabled: true,
        renderer: 'DIRECT_JS', rendererConflict: false,
        gamEnabled: false, prebidStandaloneEnabled: false, directJsEnabled: true, nativeEnabled: true,
        adUnitPath: null, sizes: [[300, 250]], responsiveMappings: [], targeting: {},
        lazyLoad: { enabled: false, fetchMarginPercent: 100, renderMarginPercent: 50, mobileScaling: 1 },
        refresh: { enabled: false, intervalSeconds: null, limit: null },
        collapseEmptyDiv: true, safeFrame: false,
        ...overrides,
    };
}

function config(definitions, overrides = {}) {
    const placements = Object.keys(definitions).map((code) => placement(code));
    return {
        schemaVersion: 4,
        siteKey: 'HM_DIRECT_DEMAND', servingMode: 'HORUS_DIRECT', gamNetworkCode: null,
        configVersion: 17, status: 'active', immediatePause: false, debug: false,
        allowedHostnames: ['publisher.example'],
        controls: { adServingDisabled: false, gamDisabled: false, prebidDisabled: false, directJsDisabled: false, directDemandDisabled: false },
        loader: { version: '2.0.0', cacheBust: 17 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: false, maxClicks: 3, windowHours: 6, blockHours: 12 },
        pageTargeting: {}, prebidEnabled: false,
        prebid: { enabled: false, build: null, auction: {}, delivery: { gamFallback: false }, adUnits: [] },
        directDemandEnabled: true,
        directDemand: { enabled: true, engine: 'DIRECT_DEMAND', recipeVersion: 1, fallbackOrder: ['ONE', 'TWO', 'HOUSE'], placements: definitions },
        nativeDemandEnabled: true,
        nativeDemand: { enabled: true, fallbackOrder: ['ONE', 'TWO', 'HOUSE'], placements: definitions },
        placements,
        ...overrides,
    };
}

function element(code) {
    const attrs = { 'data-placement': code };
    const children = [];
    return {
        id: '', className: 'hm-ad', tagName: 'DIV', parentNode: null,
        childNodes: children, children, innerHTML: '',
        getAttribute(name) { return attrs[name] ?? null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        appendChild(child) {
            child.parentNode = this; children.push(child);
            if (String(child.tagName).toUpperCase() === 'IFRAME') queueMicrotask(() => child.onload?.());
            return child;
        },
        removeChild(child) { const i = children.indexOf(child); if (i >= 0) children.splice(i, 1); child.parentNode = null; },
        contains(child) { return children.includes(child); },
        querySelectorAll(selector) { return selector === 'iframe' ? children.filter((item) => item.tagName === 'IFRAME') : []; },
        _attrs: attrs,
    };
}

function harness(selectedConfig, options = {}) {
    const metrics = { directLoads: [], gptLoads: 0, frames: [], mutationObservers: [] };
    const elements = options.elements || selectedConfig.placements.map((p) => element(p.code));
    const headChildren = [];
    let mutationCallback = null;

    const loaderScript = {
        tagName: 'SCRIPT', src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: selectedConfig.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        attributes: { 'data-site-key': selectedConfig.siteKey },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); },
        addEventListener() {},
    };

    function scriptNode() {
        const attrs = {};
        return {
            tagName: 'SCRIPT', attributes: attrs, async: false, defer: false, src: '', onload: null, onerror: null, parentNode: null,
            setAttribute(name, value) { attrs[name] = String(value); },
            getAttribute(name) { return attrs[name] ?? null; },
            addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
        };
    }

    function divNode(tagName = 'DIV') {
        const attrs = {};
        return {
            tagName, attributes: attrs, id: '', className: '', parentNode: null, childNodes: [], innerHTML: '',
            setAttribute(name, value) { attrs[name] = String(value); },
            getAttribute(name) { return attrs[name] ?? null; },
            appendChild(child) { child.parentNode = this; this.childNodes.push(child); return child; },
            removeChild(child) { const i = this.childNodes.indexOf(child); if (i >= 0) this.childNodes.splice(i, 1); child.parentNode = null; },
            contains(child) { return this.childNodes.includes(child); }, querySelectorAll() { return []; },
        };
    }

    function iframeNode() {
        const attrs = {};
        const sandboxTokens = [];
        const frame = {
            tagName: 'IFRAME', attributes: attrs, parentNode: null, srcdoc: '', onload: null, onerror: null,
            sandbox: { add(token) { if (!sandboxTokens.includes(token)) sandboxTokens.push(token); } },
            setAttribute(name, value) { attrs[name] = String(value); }, getAttribute(name) { return attrs[name] ?? null; },
            _sandboxTokens: sandboxTokens,
        };
        metrics.frames.push(frame);
        return frame;
    }

    const document = {
        currentScript: loaderScript, readyState: 'complete', visibilityState: 'visible', documentElement: {},
        head: {
            appendChild(node) {
                node.parentNode = this; headChildren.push(node);
                if (node.getAttribute?.('data-hm-gpt') === '1') { metrics.gptLoads += 1; queueMicrotask(() => node.onload?.()); return node; }
                if (node.getAttribute?.('data-hm-direct-script')) {
                    metrics.directLoads.push({ network: node.getAttribute('data-hm-direct-script'), src: node.src });
                    if ((options.hangingUrls || []).includes(node.src)) return node;
                    if ((options.failingUrls || []).includes(node.src)) queueMicrotask(() => node.onerror?.(new Error('blocked')));
                    else queueMicrotask(() => node.onload?.());
                }
                return node;
            },
        },
        createElement(tag) {
            if (tag === 'script') return scriptNode();
            if (tag === 'iframe') return iframeNode();
            return divNode(String(tag).toUpperCase());
        },
        querySelector(selector) {
            if (selector === 'script[data-hm-gpt="1"]') return null;
            if (selector === 'script[data-hm-prebid="1"]') return null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return elements.filter((e) => e.className === 'hm-ad');
            if (selector === '.hm-native[data-placement]') return [];
            if (selector === 'script[data-site-key]') return [loaderScript];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver {
        constructor(callback) { mutationCallback = callback; metrics.mutationObservers.push(this); }
        observe() {} disconnect() {}
    }
    class Event { constructor(type) { this.type = type; } }
    const localStore = new Map();
    const sandbox = {
        console, URL, Promise, Object, JSON, Math, Date, Number, String, Boolean, Array, WeakSet,
        setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        MutationObserver, Event, document,
        navigator: { globalPrivacyControl: false },
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        localStorage: { getItem(k) { return localStore.get(k) ?? null; }, setItem(k, v) { localStore.set(k, String(v)); }, removeItem(k) { localStore.delete(k); } },
        fetch: async (url) => {
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            return { ok: true, json: async () => structuredClone(selectedConfig) };
        },
        addEventListener() {}, removeEventListener() {}, dispatchEvent() {}, __HM_DISABLE_AUTOBOOT__: true,
    };
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return {
        sandbox, metrics, elements,
        triggerMutation(addedNodes = []) { mutationCallback?.([{ addedNodes, removedNodes: [] }]); },
        evaluateAgain() { vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader-duplicate.js' }); },
    };
}

async function settle(ms = 20) { await new Promise((resolve) => setTimeout(resolve, ms)); }

test('structured Direct Demand loads multiple approved scripts and renders without GPT', async () => {
    const tag = recipe({ scripts: [
        { url: 'https://ads.example.com/base.js', async: true, defer: false, dedupeKey: 'base', attributes: {} },
        { url: 'https://ads.example.com/widget.js', async: false, defer: true, dedupeKey: 'widget', attributes: {} },
    ] });
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', tag)], house: null } });
    const { sandbox, metrics, elements } = harness(selected);
    await sandbox.HorusMediaLoader.boot();

    assert.deepEqual(metrics.directLoads.map((x) => x.src), ['https://ads.example.com/base.js', 'https://ads.example.com/widget.js']);
    assert.equal(metrics.gptLoads, 0);
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'ONE');
    assert.equal(elements[0].getAttribute('data-hm-status'), 'rendered');
});

test('multiple zones share one provider loader but initialize independently', async () => {
    const tagA = recipe({ dedupeKey: 'provider-loader' });
    const tagB = { ...recipe({ dedupeKey: 'provider-loader' }), container: { element: 'div', id: 'zone-b', class: 'provider-zone', attributes: { 'data-zone-id': '200' } }, containerId: 'zone-b' };
    const selected = config({
        header: { enabled: true, candidates: [candidate('ONE', tagA)], house: null },
        sidebar: { enabled: true, candidates: [candidate('ONE', tagB)], house: null },
    });
    const { sandbox, metrics, elements } = harness(selected);
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.directLoads.length, 1);
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'ONE');
    assert.equal(elements[1].getAttribute('data-hm-direct'), 'ONE');
});

test('script error advances to the next independent Direct Demand candidate', async () => {
    const first = recipe({ url: 'https://ads.example.com/fail.js', dedupeKey: 'fail' });
    const second = recipe({ url: 'https://two.example.com/success.js', dedupeKey: 'success' });
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', first, 10), candidate('TWO', second, 20)], house: null } });
    const { sandbox, metrics, elements } = harness(selected, { failingUrls: ['https://ads.example.com/fail.js'] });
    await sandbox.HorusMediaLoader.boot();

    assert.deepEqual(metrics.directLoads.map((x) => x.network), ['ONE', 'TWO']);
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'TWO');
});

test('hung provider script times out without breaking the page', async () => {
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', recipe({ url: 'https://ads.example.com/hang.js', dedupeKey: 'hang' }))], house: null } });
    const { sandbox, metrics, elements } = harness(selected, { hangingUrls: ['https://ads.example.com/hang.js'] });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.directLoads.length, 1);
    assert.equal(elements[0].getAttribute('data-hm-status'), 'empty');
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'exhausted');
});

test('SPA insertion gets a new runtime key and reuses the shared provider loader', async () => {
    const shared = recipe({ dedupeKey: 'spa-shared' });
    const selected = config({
        header: { enabled: true, candidates: [candidate('ONE', shared)], house: null },
        sidebar: { enabled: true, candidates: [candidate('ONE', { ...shared, container: { ...shared.container, id: 'zone-sidebar' }, containerId: 'zone-sidebar' })], house: null },
    });
    const first = element('header');
    const elements = [first];
    const { sandbox, metrics, triggerMutation } = harness(selected, { elements });
    await sandbox.HorusMediaLoader.boot();
    const firstId = first.id;

    const inserted = element('sidebar');
    elements.push(inserted);
    triggerMutation([inserted]);
    await settle(80);

    assert.notEqual(inserted.id, firstId);
    assert.equal(metrics.directLoads.length, 1);
    assert.equal(inserted.getAttribute('data-hm-direct'), 'ONE');
});

test('duplicate loader evaluation and boot calls do not duplicate a Direct Demand render', async () => {
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', recipe())], house: null } });
    const runtime = harness(selected);
    runtime.evaluateAgain();
    await Promise.all([runtime.sandbox.HorusMediaLoader.boot(), runtime.sandbox.HorusMediaLoader.boot()]);
    await runtime.sandbox.HorusMediaLoader.scan();

    assert.equal(runtime.metrics.directLoads.length, 1);
    assert.equal(runtime.elements[0].children.filter((child) => child.getAttribute?.('data-hm-direct-network') === 'ONE').length, 1);
});

test('renderer conflict and paused site fail closed before any provider script', async () => {
    const definition = { header: { enabled: true, candidates: [candidate('ONE', recipe())], house: null } };
    const conflict = config(definition);
    conflict.placements[0] = placement('header', { enabled: false, renderer: 'CONFLICT', rendererConflict: true });
    const conflictRuntime = harness(conflict);
    await conflictRuntime.sandbox.HorusMediaLoader.boot();
    assert.equal(conflictRuntime.metrics.directLoads.length, 0);

    const paused = config(definition, { status: 'paused' });
    const pausedRuntime = harness(paused);
    await pausedRuntime.sandbox.HorusMediaLoader.boot();
    assert.equal(pausedRuntime.metrics.directLoads.length, 0);
});

test('custom third-party execution stays in an opaque script-only iframe', async () => {
    const isolated = recipe({
        executionMode: 'ISOLATED_IFRAME',
        scripts: [],
        isolation: {
            html: '<script src="https://ads.example.com/custom.js"></script>',
            csp: "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com;",
            sandbox: ['allow-scripts'],
        },
    });
    isolated.render.assumeLoadedIsSuccess = true;
    const selected = config({ header: { enabled: true, candidates: [candidate('ONE', isolated)], house: null } });
    const { sandbox, metrics, elements } = harness(selected);
    await sandbox.HorusMediaLoader.boot();
    await settle();

    assert.equal(metrics.directLoads.length, 0);
    assert.equal(metrics.frames.length, 1);
    assert.deepEqual(metrics.frames[0]._sandboxTokens, ['allow-scripts']);
    assert.match(metrics.frames[0].srcdoc, /Content-Security-Policy/);
    assert.doesNotMatch(metrics.frames[0].srcdoc, /app\.horusmedia\.net/);
    assert.equal(elements[0].getAttribute('data-hm-direct'), 'ONE');
});

test('Click Guard block prevents Direct Demand script requests', async () => {
    const definition = { header: { enabled: true, candidates: [candidate('ONE', recipe())], house: null } };
    const selected = config(definition, { clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 } });
    const runtime = harness(selected);
    runtime.sandbox.localStorage.setItem('hm:click-guard:v1:' + selected.siteKey, JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + 60_000 }));
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.directLoads.length, 0);
});

test('consent timeout with BLOCK_ADS prevents Direct Demand script requests', async () => {
    const definition = { header: { enabled: true, candidates: [candidate('ONE', recipe())], house: null } };
    const selected = config(definition, {
        privacy: { mode: 'STRICT', cmp: { timeoutMs: 1, actionOnTimeout: 'BLOCK_ADS' }, requireConsentBeforeAds: true },
    });
    const runtime = harness(selected);
    await runtime.sandbox.HorusMediaLoader.boot();
    assert.equal(runtime.metrics.directLoads.length, 0);
});
