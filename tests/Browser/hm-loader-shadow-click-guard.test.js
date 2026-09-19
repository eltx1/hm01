import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';

const baseLoaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const loaderSource = applyShadowClickGuardTransform(applyTrafficGateTransform(baseLoaderSource));

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

function treeNode(tagName = 'DIV') {
    const children = [];
    const node = eventTarget({
        tagName,
        parentNode: null,
        children,
        childNodes: children,
        shadowRoot: null,
        appendChild(child) {
            child.parentNode = node;
            children.push(child);
            return child;
        },
        contains(candidate) {
            let current = candidate;
            while (current) {
                if (current === node) return true;
                current = current.parentNode;
            }
            return false;
        },
        getRootNode() {
            let current = node;
            while (current.parentNode) current = current.parentNode;
            return current;
        },
    });
    return node;
}

function shadowRoot(host) {
    const root = treeNode('#shadow-root');
    root.host = host;
    root.getRootNode = () => root;
    host.shadowRoot = root;
    return root;
}

function frame() {
    return treeNode('IFRAME');
}

function placementElement(code) {
    const node = treeNode('DIV');
    const attributes = { 'data-placement': code };
    node.className = 'hm-ad';
    node.id = '';
    node.style = {};
    node.getAttribute = (name) => attributes[name] ?? null;
    node.setAttribute = (name, value) => { attributes[name] = String(value); };
    return node;
}

function config() {
    return {
        schemaVersion: 4,
        siteKey: 'HM_SHADOW_CLICK_TEST',
        servingMode: 'HORUS_DIRECT',
        gamNetworkCode: null,
        configVersion: 1,
        status: 'active',
        immediatePause: false,
        debug: false,
        allowedHostnames: ['publisher.example'],
        controls: {
            adServingDisabled: false,
            gamDisabled: false,
            prebidDisabled: false,
            directJsDisabled: false,
            directDemandDisabled: false,
            nativeDemandDisabled: false,
            trafficGateDisabled: false,
        },
        trafficGate: { enabled: false, readiness: 'DISABLED' },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: true },
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        prebid: { enabled: false, delivery: { refreshBehavior: { enabled: false, minimumIntervalSeconds: 30 } } },
        directDemand: { enabled: false, placements: {} },
        nativeDemand: { enabled: false, placements: {} },
        placements: [{
            code: 'quick_gpt_slot',
            type: 'DISPLAY',
            status: 'active',
            enabled: true,
            renderer: 'DIRECT_JS',
            adUnitPath: null,
            sizes: [[300, 250]],
            lazyLoad: { enabled: false },
            refresh: { enabled: false, intervalSeconds: null, limit: null },
        }],
    };
}

function createHarness() {
    const siteConfig = config();
    const container = placementElement('quick_gpt_slot');
    const storage = new Map();
    let mutationCallback = null;

    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: {
            siteKey: siteConfig.siteKey,
            configBase: 'https://cdn.horusmedia.net/configs',
            environment: 'production',
            configVersion: String(siteConfig.configVersion),
        },
        getAttribute(name) {
            const values = {
                'data-site-key': this.dataset.siteKey,
                'data-config-base': this.dataset.configBase,
                'data-environment': this.dataset.environment,
                'data-config-version': this.dataset.configVersion,
            };
            return values[name] ?? null;
        },
    };

    class MutationObserver {
        constructor(callback) { mutationCallback = callback; }
        observe() {}
        disconnect() {}
    }
    class Event { constructor(type) { this.type = type; } }
    class PointerEvent extends Event {}

    const document = eventTarget({
        currentScript: script,
        readyState: 'complete',
        visibilityState: 'visible',
        activeElement: null,
        documentElement: treeNode('HTML'),
        body: treeNode('BODY'),
        querySelector() { return null; },
        querySelectorAll(selector) {
            if (selector === 'script[data-site-key]') return [script];
            if (selector === '.hm-ad[data-placement]') return [container];
            if (selector === '.hm-native[data-placement]') return [];
            return [];
        },
    });

    const sandbox = eventTarget({
        console,
        URL,
        Promise,
        structuredClone,
        queueMicrotask,
        Event,
        PointerEvent,
        MutationObserver,
        document,
        navigator: {},
        location: { hostname: 'publisher.example', href: 'https://publisher.example/article' },
        history: { pushState() {}, replaceState() {} },
        __HM_DISABLE_AUTOBOOT__: true,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        localStorage: {
            getItem(key) { return storage.has(key) ? storage.get(key) : null; },
            setItem(key, value) { storage.set(key, String(value)); },
            removeItem(key) { storage.delete(key); },
        },
        fetch: async (url) => {
            if (String(url).includes('/_global/control.json')) {
                return { ok: true, json: async () => ({ controls: {} }) };
            }
            return { ok: true, json: async () => structuredClone(siteConfig) };
        },
    });
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader-shadow-click-guard.js' });

    const key = `hm:click-guard:v1:${siteConfig.siteKey}`;
    return {
        sandbox,
        container,
        mutate(node) { mutationCallback?.([{ addedNodes: [node], removedNodes: [] }]); },
        enter(iframe) { iframe.dispatchEvent(new PointerEvent('pointerenter')); },
        blur(activeElement = undefined) {
            if (activeElement !== undefined) document.activeElement = activeElement;
            sandbox.dispatchEvent(new Event('blur'));
        },
        state() { return storage.has(key) ? JSON.parse(storage.get(key)) : null; },
    };
}

test('production transform composes Traffic Gate with shadow-aware Click Guard', () => {
    assert.match(loaderSource, /function composedClickGuardParent\(node\)/);
    assert.match(loaderSource, /TRAFFIC_GATE_PROTOCOL_VERSION = 1/);
    assert.equal(applyShadowClickGuardTransform(loaderSource), loaderSource);
});

test('Quick GPT iframe inside an open ShadowRoot is tracked as a managed ad click surface', async () => {
    const runtime = createHarness();
    await runtime.sandbox.HorusMediaLoader.boot();

    const host = treeNode('DIV');
    const root = shadowRoot(host);
    const iframe = frame();
    root.appendChild(iframe);
    runtime.container.appendChild(host);
    runtime.mutate(host);

    runtime.enter(iframe);
    root.activeElement = iframe;
    runtime.blur(host);

    assert.equal(runtime.state()?.clicks.length, 1);
    assert.equal(runtime.state()?.blockedUntil, 0);
});

test('ordinary light-DOM managed iframe remains protected', async () => {
    const runtime = createHarness();
    await runtime.sandbox.HorusMediaLoader.boot();

    const iframe = frame();
    runtime.container.appendChild(iframe);
    runtime.mutate(iframe);
    runtime.enter(iframe);
    runtime.blur(iframe);

    assert.equal(runtime.state()?.clicks.length, 1);
});

test('shadow iframe outside a managed placement is ignored', async () => {
    const runtime = createHarness();
    await runtime.sandbox.HorusMediaLoader.boot();

    const host = treeNode('DIV');
    const root = shadowRoot(host);
    const iframe = frame();
    root.appendChild(iframe);
    runtime.mutate(host);
    runtime.enter(iframe);
    root.activeElement = iframe;
    runtime.blur(host);

    assert.equal(runtime.state(), null);
});
