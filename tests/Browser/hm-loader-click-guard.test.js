import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const HOUR = 60 * 60 * 1000;

function eventTarget(target = {}) {
    const listeners = new Map();
    target.addEventListener = (name, callback) => {
        const selected = listeners.get(name) || [];
        selected.push(callback);
        listeners.set(name, selected);
    };
    target.removeEventListener = (name, callback) => {
        listeners.set(name, (listeners.get(name) || []).filter((item) => item !== callback));
    };
    target.dispatchEvent = (event) => {
        for (const callback of [...(listeners.get(event.type) || [])]) callback.call(target, event);
        return true;
    };
    return target;
}

function frame() {
    return eventTarget({ tagName: 'IFRAME', parentNode: null, querySelectorAll() { return []; } });
}

function placementElement(code, className = 'hm-ad') {
    const attributes = { 'data-placement': code };
    const children = [];
    return {
        tagName: 'DIV', className, id: '', parentNode: null, children,
        style: {}, childNodes: children,
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        contains(node) {
            let current = node;
            while (current) { if (current === this) return true; current = current.parentNode; }
            return false;
        },
        appendChild(node) { node.parentNode = this; children.push(node); return node; },
        querySelectorAll(selector) {
            if (selector !== 'iframe') return [];
            const found = [];
            const visit = (node) => {
                for (const child of node.children || []) {
                    if (String(child.tagName || '').toLowerCase() === 'iframe') found.push(child);
                    visit(child);
                }
            };
            visit(this);
            return found;
        },
    };
}

function activeConfig(overrides = {}) {
    return {
        siteKey: 'HM_TEST', servingMode: 'HORUS_GAM', gamNetworkCode: '123456789', configVersion: 7,
        status: 'active', immediatePause: false, debug: false, allowedHostnames: ['publisher.example'],
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        loader: { version: '2.0.0', cacheBust: 7 },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        prebid: { enabled: false, delivery: { refreshBehavior: { enabled: true, minimumIntervalSeconds: 30 } } },
        nativeDemand: { enabled: false, placements: {} }, pageTargeting: {},
        placements: [{
            code: 'article_top', name: 'Article Top', type: 'DISPLAY', status: 'active', enabled: true,
            adUnitPath: '/123456789/article_top', sizes: [[300, 250]], responsiveMappings: [], targeting: {},
            lazyLoad: { enabled: false }, refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true, safeFrame: false, outOfPageFormat: null,
        }],
        ...overrides,
    };
}

function memoryStorage(seed = {}, throws = false) {
    const data = new Map(Object.entries(seed));
    return {
        getItem(key) { if (throws) throw new DOMException('Denied', 'SecurityError'); return data.has(key) ? data.get(key) : null; },
        setItem(key, value) { if (throws) throw new DOMException('Denied', 'SecurityError'); data.set(key, String(value)); },
        removeItem(key) { if (throws) throw new DOMException('Denied', 'SecurityError'); data.delete(key); },
        raw(key) { return data.get(key); },
    };
}

function createHarness(config, { storage = memoryStorage(), containers = null, storageAccessThrows = false } = {}) {
    const selectedContainers = containers || [placementElement('article_top')];
    const metrics = { fetches: [], gptLoads: 0, prebidLoads: 0, nativeLoads: 0, defined: 0, displayed: 0, refreshes: 0, intervals: new Map(), clearedIntervals: [] };
    let observerCallback = null;
    let intervalId = 0;
    const scriptAttributes = { 'data-site-key': config.siteKey, 'data-config-base': 'https://cdn.horusmedia.net/configs', 'data-environment': 'production' };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js', dataset: { siteKey: config.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute(name) { return scriptAttributes[name] ?? null; }, setAttribute(name, value) { scriptAttributes[name] = String(value); },
    };
    const pubads = {
        setTargeting() { return this; }, enableLazyLoad() {}, enableSingleRequest() {}, setPrivacySettings() {}, addEventListener() {},
        refresh(entries) { metrics.refreshes += entries.length; },
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const googletag = {
        cmd: immediateQueue, apiReady: false, pubadsReady: false,
        pubads() { return pubads; },
        defineSlot() {
            metrics.defined += 1;
            const slot = { setTargeting() { return slot; }, defineSizeMapping() { return slot; }, setForceSafeFrame() { return slot; }, setCollapseEmptyDiv() { return slot; }, addService() { return slot; } };
            return slot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { googletag.apiReady = true; },
        display() { metrics.displayed += 1; },
        enums: { OutOfPageFormat: {} },
    };
    class MutationObserver {
        constructor(callback) { observerCallback = callback; }
        observe() {}
        disconnect() {}
    }
    class Event { constructor(type) { this.type = type; } }
    class PointerEvent extends Event {}
    const document = eventTarget({
        currentScript: script, readyState: 'complete', visibilityState: 'visible', activeElement: null, documentElement: {},
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === 'script[data-site-key]') return [script];
            if (selector === '.hm-ad[data-placement]') return selectedContainers.filter((item) => item.className === 'hm-ad');
            if (selector === '.hm-native[data-placement]') return selectedContainers.filter((item) => item.className === 'hm-native');
            return [];
        },
    });
    const sandbox = eventTarget({
        console, URL, Promise, DOMException, structuredClone, queueMicrotask, Event, PointerEvent, MutationObserver, document, googletag,
        navigator: {}, location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} }, __HM_DISABLE_AUTOBOOT__: true,
        setTimeout(callback, delay, ...args) { if (Number(delay) > 5000) return { __hmLongTimer: true }; return setTimeout(callback, delay, ...args); },
        clearTimeout(id) { if (!id?.__hmLongTimer) clearTimeout(id); },
        setInterval(callback) { const id = ++intervalId; metrics.intervals.set(id, callback); return id; },
        clearInterval(id) { metrics.clearedIntervals.push(id); metrics.intervals.delete(id); },
        fetch: async (url) => {
            metrics.fetches.push(String(url));
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            if (String(url).includes('/manifest.json')) return { ok: true, json: async () => ({ siteKey: config.siteKey, environments: { production: { version: config.configVersion, path: `/configs/${config.siteKey}/production.v${config.configVersion}.${'a'.repeat(16)}.json`, sha256: 'a'.repeat(64) } } }) };
            return { ok: true, json: async () => structuredClone(config) };
        },
    });
    document.head = {
        appendChild(node) {
            const marker = node.getAttribute?.('data-hm-gpt');
            const prebid = node.getAttribute?.('data-hm-prebid');
            const native = node.getAttribute?.('data-hm-native-script');
            if (marker === '1') { metrics.gptLoads += 1; queueMicrotask(() => node.onload?.()); }
            if (prebid === '1') { metrics.prebidLoads += 1; queueMicrotask(() => node.onload?.()); }
            if (native) { metrics.nativeLoads += 1; queueMicrotask(() => node.onload?.()); }
            return node;
        },
    };
    document.createElement = (tag) => {
        const node = eventTarget({ tagName: String(tag).toUpperCase(), attributes: {}, async: false, src: '', onload: null, onerror: null, style: {}, childNodes: [], children: [] });
        node.setAttribute = (name, value) => { node.attributes[name] = String(value); };
        node.getAttribute = (name) => node.attributes[name] ?? null;
        node.appendChild = (child) => { child.parentNode = node; node.children.push(child); node.childNodes = node.children; return child; };
        return node;
    };
    if (storageAccessThrows) {
        Object.defineProperty(sandbox, 'localStorage', {
            configurable: true,
            get() { throw new DOMException('Denied', 'SecurityError'); },
        });
    } else {
        sandbox.localStorage = storage;
    }
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });
    return {
        sandbox, document, metrics, storage, containers: selectedContainers,
        mutate(record) { observerCallback?.([record]); },
        enter(iframe) { iframe.dispatchEvent(new PointerEvent('pointerenter')); },
        leave(iframe) { iframe.dispatchEvent(new PointerEvent('pointerleave')); },
        blur() { sandbox.dispatchEvent(new Event('blur')); },
        state() { const raw = storage.raw(`hm:click-guard:v1:${config.siteKey}`); return raw ? JSON.parse(raw) : null; },
    };
}

async function bootWithFrame(config, options = {}) {
    const harness = createHarness(config, options);
    await harness.sandbox.HorusMediaLoader.boot();
    const iframe = frame();
    harness.containers[0].appendChild(iframe);
    harness.mutate({ addedNodes: [iframe], removedNodes: [] });
    return { ...harness, iframe };
}

test('disabled Click Guard preserves existing ad behavior and does not touch storage', async () => {
    const storage = memoryStorage();
    const config = activeConfig({ clickGuard: { enabled: false, maxClicks: 3, windowHours: 6, blockHours: 12 } });
    const harness = createHarness(config, { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
    assert.equal(storage.raw('hm:click-guard:v1:HM_TEST'), undefined);
});

test('below threshold records clicks without blocking', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - HOUR], blockedUntil: 0 }) });
    const { sandbox, iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.equal(state().clicks.length, 2);
    assert.equal(state().blockedUntil, 0);
    assert.equal((await sandbox.HorusMediaLoader.scan()).length, 0);
});

test('exact threshold creates a future block and clears the click window', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - 2 * HOUR, now - HOUR], blockedUntil: 0 }) });
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.deepEqual(state().clicks, []);
    assert.ok(state().blockedUntil > Date.now() + 11 * HOUR);
});

test('rolling window prunes expired clicks before counting', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - 7 * HOUR, now - HOUR], blockedUntil: 0 }) });
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig(), { storage });
    enter(iframe); blur();
    assert.equal(state().clicks.length, 2);
    assert.equal(state().blockedUntil, 0);
    assert.ok(state().clicks.every((value) => value >= now - 6 * HOUR));
});

test('existing future block stops before GPT, Prebid, native, slot, display, and refresh initialization', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [], blockedUntil: now + HOUR }) });
    const config = activeConfig({
        prebid: { enabled: true, build: { url: 'https://cdn.horusmedia.net/prebid.js' }, delivery: { gamFallback: true } },
        nativeDemand: { enabled: true, placements: { article_top: { enabled: true, candidates: [{ network: 'TEST', tag: { scriptUrl: 'https://native.example/tag.js' } }] } } },
    });
    const harness = createHarness(config, { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 0);
    assert.equal(harness.metrics.prebidLoads, 0);
    assert.equal(harness.metrics.nativeLoads, 0);
    assert.equal(harness.metrics.defined, 0);
    assert.equal(harness.metrics.displayed, 0);
    assert.equal(harness.metrics.refreshes, 0);
    assert.equal(harness.metrics.fetches.length, 3);
});

test('expired block resets stale clicks and resumes normal advertising', async () => {
    const now = Date.now();
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': JSON.stringify({ v: 1, clicks: [now - HOUR], blockedUntil: now - 1000 }) });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.deepEqual(harness.state(), { v: 1, clicks: [], blockedUntil: 0 });
});

test('corrupt localStorage fails open and is normalized without breaking the loader', async () => {
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_TEST': '{broken' });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.deepEqual(harness.state(), { v: 1, clicks: [], blockedUntil: 0 });
});

test('localStorage SecurityError fails open and ads continue', async () => {
    const harness = createHarness(activeConfig(), { storage: memoryStorage({}, true) });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});

test('localStorage property access SecurityError also fails open', async () => {
    const harness = createHarness(activeConfig(), { storageAccessThrows: true });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});

test('dynamic eligible iframe is tracked and unrelated iframe is ignored', async () => {
    const harness = createHarness(activeConfig());
    await harness.sandbox.HorusMediaLoader.boot();
    const unrelated = frame();
    harness.mutate({ addedNodes: [unrelated], removedNodes: [] });
    harness.enter(unrelated); harness.blur();
    assert.equal(harness.state(), null);

    const eligible = frame();
    harness.containers[0].appendChild(eligible);
    harness.mutate({ addedNodes: [eligible], removedNodes: [] });
    harness.enter(eligible); harness.blur();
    assert.equal(harness.state().clicks.length, 1);
});

test('window blur without an armed Horus iframe does not count', async () => {
    const harness = createHarness(activeConfig());
    await harness.sandbox.HorusMediaLoader.boot();
    harness.blur();
    assert.equal(harness.state(), null);
});

test('eligible iframe blur counts once and duplicate blur is deduplicated', async () => {
    const { iframe, enter, blur, state } = await bootWithFrame(activeConfig());
    enter(iframe); blur(); blur();
    assert.equal(state().clicks.length, 1);
});

test('mid-page threshold clears refresh timers and future scans cannot request new ads', async () => {
    const config = activeConfig({
        clickGuard: { enabled: true, maxClicks: 1, windowHours: 6, blockHours: 12 },
        placements: [{ ...activeConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 0 } }],
    });
    const harness = await bootWithFrame(config);
    assert.equal(harness.metrics.intervals.size, 1);
    const beforeDefined = harness.metrics.defined;
    harness.enter(harness.iframe); harness.blur();
    assert.equal(harness.metrics.intervals.size, 0);
    assert.ok(harness.metrics.clearedIntervals.length >= 1);

    const second = placementElement('article_top');
    harness.containers.push(second);
    await harness.sandbox.HorusMediaLoader.scan();
    assert.equal(harness.metrics.defined, beforeDefined);
});

test('storage event from another tab activates block and cancels future activity', async () => {
    const config = activeConfig({ placements: [{ ...activeConfig().placements[0], refresh: { enabled: true, intervalSeconds: 30, limit: 0 } }] });
    const harness = createHarness(config);
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.intervals.size, 1);
    const blocked = JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + HOUR });
    harness.sandbox.dispatchEvent({ type: 'storage', key: 'hm:click-guard:v1:HM_TEST', newValue: blocked });
    assert.equal(harness.metrics.intervals.size, 0);
    const before = harness.metrics.defined;
    await harness.sandbox.HorusMediaLoader.scan();
    assert.equal(harness.metrics.defined, before);
});

test('site-key namespacing prevents another Horus site block from leaking into this site', async () => {
    const storage = memoryStorage({ 'hm:click-guard:v1:HM_OTHER': JSON.stringify({ v: 1, clicks: [], blockedUntil: Date.now() + HOUR }) });
    const harness = createHarness(activeConfig(), { storage });
    await harness.sandbox.HorusMediaLoader.boot();
    assert.equal(harness.metrics.gptLoads, 1);
    assert.equal(harness.metrics.defined, 1);
});
