import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const isolatedSource = await readFile(new URL('../../public/assets/hm-isolated-direct.js', import.meta.url), 'utf8');
const gptSource = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const videoSource = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');

function container(attributes, id = '') {
    const frames = [];
    const childNodes = [];
    return {
        id,
        frames,
        childNodes,
        innerHTML: '',
        shadowRoot: null,
        style: {},
        get firstChild() { return childNodes[0] || null; },
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(frame) {
            frame.parentNode = this;
            childNodes.push(frame);
            frames.push(frame);
            queueMicrotask(() => frame.onload?.());
            return frame;
        },
        removeChild(frame) {
            const index = childNodes.indexOf(frame);
            if (index >= 0) childNodes.splice(index, 1);
            const frameIndex = frames.indexOf(frame);
            if (frameIndex >= 0) frames.splice(frameIndex, 1);
            frame.parentNode = null;
            return frame;
        },
    };
}

function iframe() {
    const attributes = {};
    const sandboxValues = [];
    return {
        tagName: 'IFRAME',
        attributes,
        style: {},
        srcdoc: '',
        onload: null,
        onerror: null,
        contentWindow: {},
        parentNode: null,
        sandbox: { add(value) { sandboxValues.push(String(value)); } },
        sandboxValues,
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return attributes[name] ?? null; },
    };
}

function runIsolated(selectedContainer) {
    const createdFrames = [];
    const selectedContainers = Array.isArray(selectedContainer) ? selectedContainer : [selectedContainer];
    const windowListeners = {};
    const document = {
        documentElement: {},
        querySelectorAll(query) { return query === '[data-hm-isolated-direct="1"]' ? selectedContainers : []; },
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const frame = iframe();
            createdFrames.push(frame);
            return frame;
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = {
        document,
        MutationObserver,
        queueMicrotask,
        setTimeout,
        clearTimeout,
        console,
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
        addEventListener(name, callback) { (windowListeners[name] ||= []).push(callback); },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(isolatedSource, sandbox, { filename: 'hm-isolated-direct.js' });
    return {
        sandbox,
        createdFrames,
        emitMessage(source, data) {
            (windowListeners.message || []).forEach((callback) => callback({ source, data }));
        },
    };
}

function scriptElement() {
    const attributes = {};
    return {
        tagName: 'SCRIPT',
        async: false,
        src: '',
        onerror: null,
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return attributes[name] ?? null; },
    };
}

function runGpt(selectedContainer, options = {}) {
    const selectedContainers = Array.isArray(selectedContainer) ? selectedContainer : [selectedContainer];
    const definitions = [];
    const displayCalls = [];
    const destroyedSlots = [];
    const listeners = [];
    const appendedScripts = [];
    let enableServicesCalls = 0;

    const pubads = {
        addEventListener(name, callback) {
            if (name === 'slotRenderEnded') listeners.push(callback);
        },
        removeEventListener(name, callback) {
            if (name !== 'slotRenderEnded') return;
            const index = listeners.indexOf(callback);
            if (index >= 0) listeners.splice(index, 1);
        },
    };
    const googletag = {
        apiReady: true,
        cmd: { push(callback) { callback(); } },
        defineSlot(path, slotSizes, id) {
            const slot = {
                path,
                sizes: slotSizes,
                id,
                service: null,
                addService(service) { slot.service = service; return slot; },
            };
            definitions.push(slot);
            return slot;
        },
        pubads() { return pubads; },
        enableServices() { enableServicesCalls += 1; },
        display(id) { displayCalls.push(id); },
        destroySlots(slots) { destroyedSlots.push(...slots); return true; },
    };

    const head = {
        appendChild(script) { appendedScripts.push(script); return script; },
    };
    const width = options.width ?? 1024;
    const height = options.height ?? 900;
    const document = {
        documentElement: { clientWidth: width, clientHeight: height },
        head,
        body: { appendChild(script) { appendedScripts.push(script); return script; } },
        querySelectorAll(query) { return query === '[data-hm-gpt-direct="1"]' ? selectedContainers : []; },
        getElementsByTagName(tag) {
            if (tag === 'script') return appendedScripts;
            if (tag === 'head') return [head];
            return [];
        },
        createElement(tag) {
            assert.equal(tag, 'script');
            return scriptElement();
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = { document, MutationObserver, console, innerWidth: width, innerHeight: height };
    if (options.ready !== false) sandbox.googletag = googletag;
    sandbox.window = sandbox;
    vm.runInNewContext(gptSource, sandbox, { filename: 'hm-gpt-direct.js' });

    return {
        sandbox,
        definitions,
        displayCalls,
        destroyedSlots,
        appendedScripts,
        get enableServicesCalls() { return enableServicesCalls; },
        emit(slot, event) {
            listeners.slice().forEach((callback) => callback({ slot, isEmpty: false, size: null, ...event }));
        },
    };
}

function legacyLoaderWouldTreatGptAsRendered(target, attributes) {
    if (attributes['data-hm-gpt-status'] === 'rendered') return true;
    if (target.childNodes && target.childNodes.length) return true;
    return Boolean(typeof target.innerHTML === 'string' && target.innerHTML.replace(/\s/g, '') !== '');
}

function chunkedAttributes(baseAttribute, value, chunkSize = 1800) {
    const encoded = Buffer.from(value, 'utf8').toString('base64');
    const parts = [];
    for (let offset = 0; offset < encoded.length; offset += chunkSize) parts.push(encoded.slice(offset, offset + chunkSize));
    const attributes = { [`${baseAttribute}-parts`]: String(parts.length) };
    parts.forEach((part, index) => { attributes[`${baseAttribute}-${index}`] = part; });
    return attributes;
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

function runVideo(selectedContainer, options = {}) {
    const requested = [];
    const managers = [];
    const loaders = [];
    const displays = [];
    const created = [];
    const windowListeners = {};
    const dispatched = [];
    const storage = new Map(Object.entries(options.storage || {}));

    function mediaElement(tag) {
        const attributes = {};
        const listeners = {};
        const childNodes = [];
        return {
            tagName: tag.toUpperCase(),
            attributes,
            childNodes,
            style: {},
            textContent: '',
            type: '',
            disabled: false,
            muted: false,
            autoplay: false,
            playsInline: false,
            paused: false,
            setAttribute(name, value) { attributes[name] = String(value); },
            getAttribute(name) { return attributes[name] ?? null; },
            appendChild(child) { child.parentNode = this; childNodes.push(child); return child; },
            addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
            click() { (listeners.click || []).forEach((callback) => callback({ isTrusted: options.trustedClick !== false, preventDefault() {}, stopPropagation() {} })); },
            pause() { this.paused = true; },
        };
    }

    const adEventTypes = {
        LOADED: 'loaded',
        STARTED: 'started',
        COMPLETE: 'complete',
        SKIPPED: 'skipped',
        ALL_ADS_COMPLETED: 'all-ads-completed',
    };
    class AdsManager {
        constructor() {
            this.listeners = {};
            this.destroyed = false;
            this.started = false;
            managers.push(this);
        }
        addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
        emit(name, event = {}) { (this.listeners[name] || []).forEach((callback) => callback(event)); }
        init(width, height, mode) { this.initialized = [width, height, mode]; }
        setVolume(volume) { this.volume = volume; }
        start() {
            if (options.managerStartThrows) throw new Error('manager-start-failed');
            this.started = true;
            this.emit(adEventTypes.LOADED);
            this.emit(adEventTypes.STARTED);
        }
        resize(width, height, mode) { this.resized = [width, height, mode]; }
        destroy() { this.destroyed = true; }
    }
    class AdsLoader {
        constructor() { this.listeners = {}; loaders.push(this); }
        destroy() { this.destroyed = true; }
        addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
        requestAds(request) {
            requested.push(request);
            const manager = new AdsManager();
            (this.listeners['ads-manager-loaded'] || []).forEach((callback) => callback({
                getAdsManager() { return manager; },
            }));
        }
    }
    class AdsRequest {
        setAdWillAutoPlay(value) { this.willAutoPlay = value; }
        setAdWillPlayMuted(value) { this.willPlayMuted = value; }
    }
    class IntersectionObserver {
        constructor(callback, options) { this.callback = callback; this.options = options; this.disconnected = false; }
        observe(target) { this.target = target; this.callback([{ isIntersecting: true, intersectionRatio: 0.6 }]); }
        disconnect() { this.disconnected = true; }
    }
    class MutationObserver {
        observe() {}
    }

    const document = {
        documentElement: { clientWidth: 1280, clientHeight: 720, lang: options.lang || 'en' },
        head: { appendChild(node) { created.push(node); return node; } },
        querySelector() { return null; },
        querySelectorAll(query) { return query === '[data-hm-video-direct="1"]' ? [selectedContainer] : []; },
        createElement(tag) {
            const element = mediaElement(tag);
            created.push(element);
            return element;
        },
    };
    const ima = {
        AdDisplayContainer: class {
            constructor(layer, video) { this.layer = layer; this.video = video; displays.push(this); }
            initialize() { this.initialized = true; }
            destroy() { this.destroyed = true; }
        },
        AdsLoader,
        AdsRequest,
        AdsRenderingSettings: class {},
        AdsManagerLoadedEvent: { Type: { ADS_MANAGER_LOADED: 'ads-manager-loaded' } },
        AdErrorEvent: { Type: { AD_ERROR: 'ad-error' } },
        AdEvent: { Type: adEventTypes },
        ViewMode: { NORMAL: 'normal' },
    };
    const sandbox = {
        document,
        URL,
        location: { href: 'https://publisher.example/article#section' },
        google: { ima },
        MutationObserver,
        IntersectionObserver,
        Promise,
        console,
        innerWidth: 1280,
        innerHeight: 720,
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
        setTimeout,
        clearTimeout,
        localStorage: {
            getItem(key) { return storage.has(key) ? storage.get(key) : null; },
            setItem(key, value) { storage.set(key, String(value)); },
        },
        CustomEvent: class {
            constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
        },
        navigator: { userActivation: { isActive: options.userActivation !== false } },
        dispatchEvent(event) {
            dispatched.push(event);
            (windowListeners[event.type] || []).forEach((callback) => callback(event));
            return true;
        },
        addEventListener(name, callback) { (windowListeners[name] ||= []).push(callback); },
        removeEventListener(name, callback) {
            const listeners = windowListeners[name] || [];
            const index = listeners.indexOf(callback);
            if (index >= 0) listeners.splice(index, 1);
        },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(videoSource, sandbox, { filename: 'hm-video-direct.js' });
    return { sandbox, requested, managers, loaders, displays, created, dispatched, storage };
}

test('isolated Direct Demand runtime preserves placement dimensions and sandboxing', async () => {
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad"></div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html': Buffer.from(html, 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from(csp, 'utf8').toString('base64'),
        'data-hm-isolated-width': '728',
        'data-hm-isolated-height': '90',
    };
    const target = container(attributes, 'isolated-zone');
    const runtime = runIsolated(target);
    await tick();

    const { createdFrames } = runtime;
    assert.equal(createdFrames.length, 1);
    const frame = createdFrames[0];
    assert.equal(frame.getAttribute('width'), '728');
    assert.equal(frame.getAttribute('height'), '90');
    assert.deepEqual(frame.sandboxValues, ['allow-scripts']);
    assert.match(frame.srcdoc, /Content-Security-Policy/);
    assert.ok(frame.srcdoc.includes(csp));
    assert.ok(frame.srcdoc.includes(html));
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
    assert.equal(attributes['data-hm-isolated-status'], 'loaded');
    assert.equal(frame.getAttribute('allow'), 'autoplay; fullscreen');
    assert.match(frame.srcdoc, /hm-isolated-rendered/);

    const token = Object.keys(runtime.sandbox.__HORUS_ISOLATED_DIRECT_RUNTIME_V1__.frames)[0];
    runtime.emitMessage(frame.contentWindow, { type: 'hm-isolated-rendered', token });
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'rendered');
    assert.equal(attributes['data-hm-isolated-status'], 'rendered');

    target.__hmDestroy('dismissed');
    assert.equal(frame.src, 'about:blank');
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'dismissed');
    assert.equal(runtime.sandbox.__HORUS_ISOLATED_DIRECT_RUNTIME_V1__.frames[token], undefined);
});

test('isolated Direct Demand runtime reassembles payloads larger than public attribute limits', async () => {
    const marker = 'LONG-PROVIDER-PAYLOAD-';
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad">' + marker.repeat(180) + '</div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-width': '300',
        'data-hm-isolated-height': '250',
        ...chunkedAttributes('data-hm-isolated-html', html),
        ...chunkedAttributes('data-hm-isolated-csp', csp),
    };
    const target = container(attributes, 'chunked-isolated-zone');
    const { createdFrames } = runIsolated(target);
    await tick();

    assert.ok(Object.keys(attributes).filter((key) => key.startsWith('data-hm-isolated-html-')).length > 2);
    assert.ok(Object.values(attributes).every((value) => String(value).length <= 2000));
    assert.equal(createdFrames.length, 1);
    assert.ok(createdFrames[0].srcdoc.includes(html));
    assert.ok(createdFrames[0].srcdoc.includes(csp));
    assert.deepEqual(createdFrames[0].sandboxValues, ['allow-scripts']);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
});

test('isolated Direct Demand runtime rejects incomplete chunked payloads', () => {
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html-parts': '2',
        'data-hm-isolated-html-0': Buffer.from('<div>', 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from("default-src 'none'; script-src https://ads.example.com;", 'utf8').toString('base64'),
    };
    const target = container(attributes, 'broken-isolated-zone');
    const { createdFrames } = runIsolated(target);

    assert.equal(createdFrames.length, 0);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'invalid');
});

test('Horus video runtime plays a VAST URL only after viewability and exposes deterministic lifecycle state', async () => {
    const vastUrl = 'https://video.example.com/vast?slot=floating';
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from(vastUrl, 'utf8').toString('base64'),
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
        'data-hm-video-sizes': '[[400,225],[320,180]]',
        'data-hm-video-size-map': '[{"minWidth":0,"maxWidth":767,"width":320,"height":180},{"minWidth":768,"width":400,"height":225}]',
        'data-hm-video-muted': '1',
        'data-hm-video-autoplay': '1',
    };
    const target = container(attributes, 'hm-video-placement-1');
    target.clientWidth = 400;
    const runtime = runVideo(target);
    await tick();

    assert.equal(runtime.requested.length, 1);
    assert.equal(runtime.requested[0].adTagUrl, vastUrl);
    assert.equal(runtime.requested[0].willAutoPlay, true);
    assert.equal(runtime.requested[0].willPlayMuted, true);
    assert.equal(runtime.requested[0].linearAdSlotWidth, 400);
    assert.equal(runtime.requested[0].linearAdSlotHeight, 225);
    assert.equal(attributes['data-hm-video-status'], 'started');
    assert.equal(attributes['data-hm-video-runtime-state'], 'started');

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    assert.ok(video);
    assert.equal(video.muted, true);
    assert.equal(video.autoplay, true);
    assert.equal(video.playsInline, true);
    assert.equal(runtime.managers[0].volume, 0);
    assert.equal(runtime.managers[0].started, true);

    const floatingSurfaceAttributes = { 'data-hm-floating-video-active': '1' };
    const floatingSurface = {
        style: {},
        parentNode: null,
        getAttribute(name) { return floatingSurfaceAttributes[name] ?? null; },
        setAttribute(name, value) { floatingSurfaceAttributes[name] = String(value); },
    };
    target.parentNode = floatingSurface;
    runtime.managers[0].emit('complete');
    assert.equal(runtime.managers[0].destroyed, false);
    assert.notEqual(floatingSurface.style.display, 'none');
    runtime.managers[0].emit('all-ads-completed');
    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(video.paused, true);
    assert.equal(attributes['data-hm-video-status'], 'completed');
    assert.equal(floatingSurface.style.display, 'none');
    assert.equal(floatingSurfaceAttributes['data-hm-placement-dismissed'], '1');
});

test('GAM VAST templates resolve page macros and declare actual floating playback', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&url=[referrer_url]&description_url=[description_url]&correlator=[timestamp]&sz=400x300').toString('base64'),
    };
    const runtime = runVideo(container(attributes, 'floating-gam'));
    await tick();
    const url = new URL(runtime.requested[0].adTagUrl);
    assert.equal(url.searchParams.get('url'), 'https://publisher.example/article');
    assert.equal(url.searchParams.get('description_url'), 'https://publisher.example/article');
    assert.match(url.searchParams.get('correlator'), /^\d+$/);
    assert.equal(url.searchParams.get('iu'), '/123/video');
    assert.equal(url.searchParams.get('vpmute'), '1');
    assert.equal(url.searchParams.get('vpa'), 'auto');
    assert.equal(url.searchParams.get('plcmt'), '4');
    assert.equal(url.searchParams.get('sz'), '400x300');
    assert.equal(runtime.requested[0].linearAdSlotWidth, 400);
    assert.equal(runtime.requested[0].linearAdSlotHeight, 225);
});

test('Horus video runtime rejects non-HTTPS VAST URLs before requesting ads', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('http://video.example.com/vast', 'utf8').toString('base64'),
    };
    const runtime = runVideo(container(attributes, 'hm-video-invalid'));
    await tick();

    assert.equal(runtime.requested.length, 0);
    assert.equal(attributes['data-hm-video-status'], 'invalid');
});

test('Horus floating video removes its surface immediately after a terminal IMA error', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=floating-error', 'utf8').toString('base64'),
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
        'data-hm-video-sizes': '[[400,225],[320,180]]',
        'data-hm-video-muted': '1',
        'data-hm-video-autoplay': '1',
    };
    const target = container(attributes, 'hm-video-error');
    const floatingSurfaceAttributes = { 'data-hm-floating-video-active': '1' };
    const floatingSurface = {
        style: {},
        parentNode: null,
        getAttribute(name) { return floatingSurfaceAttributes[name] ?? null; },
        setAttribute(name, value) { floatingSurfaceAttributes[name] = String(value); },
    };
    target.parentNode = floatingSurface;

    const runtime = runVideo(target);
    await tick();
    runtime.managers[0].emit('ad-error', { getError() { return Object.assign(new Error('vast-no-fill'), {
        getErrorCode: () => 1009, getVastErrorCode: () => 303,
    }); } });

    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(attributes['data-hm-video-status'], 'error');
    assert.match(attributes['data-hm-video-error'], /vast-no-fill/);
    assert.equal(floatingSurface.style.display, 'none');
    assert.equal(floatingSurfaceAttributes['data-hm-placement-dismissed'], '1');
    assert.equal(floatingSurfaceAttributes['data-hm-video-error-code'], '1009');
    assert.equal(floatingSurfaceAttributes['data-hm-video-vast-error-code'], '303');
    assert.equal(floatingSurfaceAttributes['data-hm-video-error-stage'], 'playback');
    assert.match(floatingSurfaceAttributes['data-hm-video-error'], /vast-no-fill/);
    assert.equal(runtime.loaders[0].destroyed, true);
    assert.equal(runtime.displays[0].destroyed, true);
});

test('asynchronous IMA manager initialization failure is retained and fully cleaned up', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example/vast').toString('base64'),
    };
    const runtime = runVideo(container(attributes, 'manager-failure'), { managerStartThrows: true });
    await tick();
    assert.equal(attributes['data-hm-video-status'], 'error');
    assert.equal(attributes['data-hm-video-error-stage'], 'manager');
    assert.match(attributes['data-hm-video-error'], /manager-start-failed/);
    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(runtime.loaders[0].destroyed, true);
    assert.equal(runtime.displays[0].destroyed, true);
    assert.equal(runtime.requested.length, 1);
});

test('Horus rewarded VAST waits for explicit opt-in and grants only after an unskipped completion', async () => {
    const vastUrl = 'https://video.example.com/vast?slot=rewarded';
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-vast-url': Buffer.from(vastUrl, 'utf8').toString('base64'),
        'data-hm-video-width': '640',
        'data-hm-video-height': '360',
        'data-hm-video-sizes': '[[640,360],[320,180]]',
        'data-hm-video-muted': '0',
        'data-hm-video-autoplay': '0',
        'data-hm-reward-cooldown-seconds': '900',
    };
    const target = container(attributes, 'hm-rewarded-placement-1');
    attributes['data-hm-reward-experience'] = 'continue-reading';
    const runtime = runVideo(target);
    await tick();

    assert.equal(runtime.requested.length, 0);
    assert.equal(attributes['data-hm-video-status'], 'reward-ready');
    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-ready').length, 1);

    const button = runtime.created.find((node) => node.tagName === 'BUTTON');
    assert.ok(button);
    assert.equal(button.disabled, false);
    button.click();

    assert.equal(runtime.requested.length, 1);
    assert.equal(runtime.requested[0].adTagUrl, vastUrl);
    assert.equal(runtime.requested[0].willAutoPlay, false);
    assert.equal(runtime.requested[0].willPlayMuted, false);
    assert.equal(runtime.managers[0].volume, 1);
    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-opened').length, 1);

    runtime.managers[0].emit('complete');
    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-granted').length, 0);
    runtime.managers[0].emit('all-ads-completed');

    const granted = runtime.dispatched.filter((event) => event.type === 'horus:rewarded-granted');
    const closed = runtime.dispatched.filter((event) => event.type === 'horus:rewarded-closed');
    assert.equal(granted.length, 1);
    assert.equal(granted[0].detail.reward.type, 'horus_video_completion');
    assert.equal(closed.length, 1);
    assert.equal(closed[0].detail.granted, true);
    assert.equal(attributes['data-hm-reward-granted'], '1');
    assert.equal(attributes['data-hm-video-status'], 'completed');
    assert.equal(target.style.display, 'none');
    assert.ok(runtime.storage.has('hm:rewarded:v1:hm-rewarded-placement-1'));
});

test('Continue reading prompt is optional and dismissal requests no ad or reward', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-reward-experience': 'continue-reading',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast', 'utf8').toString('base64'),
    };
    const target = container(attributes, 'reading');
    const runtime = runVideo(target);
    await tick();
    assert.equal(runtime.requested.length, 0);
    const buttons = runtime.created.filter((node) => node.tagName === 'BUTTON');
    assert.equal(buttons[0].textContent, 'Watch ad and continue reading');
    assert.equal(buttons[1].textContent, 'Continue reading now');
    buttons[1].click();
    assert.equal(target.style.display, 'none');
    assert.equal(runtime.requested.length, 0);
    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-granted').length, 0);
    buttons[0].click();
    assert.equal(runtime.requested.length, 0);
});

test('Continue reading localizes Arabic and Escape closes without granting', async () => {
    const attributes = {
        'data-hm-video-direct': '1', 'data-hm-video-rewarded': '1',
        'data-hm-reward-experience': 'continue-reading',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast').toString('base64'),
    };
    const target = container(attributes, 'arabic-reading');
    const runtime = runVideo(target, { lang: 'ar-EG' });
    await tick();
    const prompt = runtime.created.find(node => node.attributes['data-hm-reward-prompt'] === '1');
    assert.equal(prompt.attributes.dir, 'rtl');
    assert.equal(prompt.attributes.role, 'dialog');
    assert.equal(runtime.created.find(node => node.tagName === 'BUTTON').textContent, 'شاهد الإعلان واستكمل القراءة');
    runtime.sandbox.dispatchEvent({ type: 'keydown', key: 'Escape', preventDefault() {} });
    assert.equal(target.style.display, 'none');
    target.__hmDestroy('dismissed');
    assert.equal(runtime.dispatched.filter(event => event.type === 'horus:rewarded-closed').length, 1);
    assert.equal(runtime.requested.length, 0);
});

test('Continue reading completion cooldown does not interrupt reading again', async () => {
    const attributes = {
        'data-hm-video-direct': '1', 'data-hm-video-rewarded': '1',
        'data-hm-reward-experience': 'continue-reading', 'data-hm-reward-cooldown-seconds': '900',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast').toString('base64'),
    };
    const target = container(attributes, 'reading-capped');
    const runtime = runVideo(target, { storage: { 'hm:rewarded:v1:reading-capped': String(Date.now()) } });
    await tick();
    assert.equal(attributes['data-hm-video-status'], 'reward-capped');
    assert.equal(target.style.display, 'none');
    assert.equal(runtime.requested.length, 0);
});

test('Horus rewarded VAST never grants after skip', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=rewarded-skip', 'utf8').toString('base64'),
        'data-hm-video-muted': '0',
        'data-hm-video-autoplay': '0',
    };
    const runtime = runVideo(container(attributes, 'hm-rewarded-skip'));
    await tick();
    runtime.created.find((node) => node.tagName === 'BUTTON').click();
    runtime.managers[0].emit('skipped');
    runtime.managers[0].emit('all-ads-completed');

    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-granted').length, 0);
    const closed = runtime.dispatched.filter((event) => event.type === 'horus:rewarded-closed');
    assert.equal(closed.length, 1);
    assert.equal(closed[0].detail.granted, false);
    assert.equal(attributes['data-hm-video-status'], 'closed');
});

test('Horus rewarded VAST closes without granting after an IMA error', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=rewarded-error', 'utf8').toString('base64'),
        'data-hm-video-muted': '0',
        'data-hm-video-autoplay': '0',
    };
    const target = container(attributes, 'hm-rewarded-error');
    const runtime = runVideo(target);
    await tick();
    runtime.created.find((node) => node.tagName === 'BUTTON').click();
    runtime.managers[0].emit('ad-error', { getError() { return new Error('rewarded-no-fill'); } });

    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:rewarded-granted').length, 0);
    const closed = runtime.dispatched.filter((event) => event.type === 'horus:rewarded-closed');
    assert.equal(closed.length, 1);
    assert.equal(closed[0].detail.granted, false);
    assert.equal(closed[0].detail.reason, 'error');
    assert.equal(attributes['data-hm-video-status'], 'error');
    assert.equal(target.style.display, 'none');
});

test('Horus rewarded VAST rejects programmatic activation outside a user gesture', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=rewarded-activation', 'utf8').toString('base64'),
    };
    const runtime = runVideo(container(attributes, 'hm-rewarded-activation'), { userActivation: false });
    await tick();

    const ready = runtime.dispatched.find((event) => event.type === 'horus:rewarded-ready');
    assert.ok(ready);
    assert.equal(ready.detail.makeRewardedVisible(), false);
    assert.equal(runtime.requested.length, 0);
    assert.equal(attributes['data-hm-video-status'], 'activation-required');
});

test('Horus rewarded VAST applies its per-placement completion cooldown', async () => {
    const id = 'hm-rewarded-capped';
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-video-rewarded': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=rewarded-capped', 'utf8').toString('base64'),
        'data-hm-reward-cooldown-seconds': '900',
    };
    const runtime = runVideo(container(attributes, id), {
        storage: { [`hm:rewarded:v1:${id}`]: String(Date.now()) },
    });
    await tick();

    assert.equal(attributes['data-hm-video-status'], 'reward-capped');
    assert.equal(runtime.requested.length, 0);
    const button = runtime.created.find((node) => node.tagName === 'BUTTON');
    assert.ok(button);
    assert.equal(button.disabled, true);
    const ready = runtime.dispatched.find((event) => event.type === 'horus:rewarded-ready');
    assert.equal(ready.detail.capped, true);
    assert.ok(ready.detail.cooldownRemainingSeconds > 0);
});

test('Google GPT direct runtime executes the slot in the publisher document and waits for slotRenderEnded', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250],[320,100]]',
        'data-hm-gpt-inner-id': 'div-gpt-ad-lordai-header',
    };
    const target = container(attributes, 'hm-gpt-placement-1');
    const runtime = runGpt(target);

    assert.equal(runtime.definitions.length, 1);
    assert.equal(runtime.definitions[0].path, '/1234567/lordai_header');
    assert.deepEqual(JSON.parse(JSON.stringify(runtime.definitions[0].sizes)), [[300, 250], [320, 100]]);
    assert.equal(runtime.definitions[0].id, 'hm-gpt-placement-1');
    assert.deepEqual(runtime.displayCalls, ['hm-gpt-placement-1']);
    assert.equal(runtime.enableServicesCalls, 1);
    assert.equal(attributes['data-hm-gpt-document-context'], 'publisher');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'requested');
    assert.equal(attributes['data-hm-gpt-status'], undefined);
    assert.equal(target.shadowRoot, null);
    assert.equal(target.childNodes.length, 0);
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), false);
    assert.doesNotMatch(gptSource, /srcdoc/i);
    assert.doesNotMatch(gptSource, /setForceSafeFrame/);
    assert.doesNotMatch(gptSource, /attachShadow/);

    runtime.emit(runtime.definitions[0], { isEmpty: false, size: [320, 100] });

    assert.equal(attributes['data-hm-gpt-status'], 'rendered');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'rendered');
    assert.equal(attributes['data-hm-gpt-rendered-width'], '320');
    assert.equal(attributes['data-hm-gpt-rendered-height'], '100');
    assert.equal(target.style.width, '320px');
    assert.equal(target.style.height, '100px');
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), true);
});

test('Google GPT no-fill remains a failure path and destroys the empty slot', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/no_fill',
        'data-hm-gpt-sizes': '[[320,50],[320,100]]',
        'data-hm-gpt-inner-id': 'provider-no-fill',
    };
    const target = container(attributes, 'hm-gpt-placement-empty');
    const runtime = runGpt(target);
    const slot = runtime.definitions[0];

    runtime.emit(slot, { isEmpty: true, size: null });

    assert.equal(attributes['data-hm-gpt-status'], 'empty');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'empty');
    assert.deepEqual(runtime.destroyedSlots, [slot]);
    assert.equal(legacyLoaderWouldTreatGptAsRendered(target, attributes), false);
});

test('Google GPT runtime resizes to the actual declared creative and rejects undeclared render sizes', () => {
    const goodAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/mixed_shape',
        'data-hm-gpt-sizes': '[[970,90],[300,600]]',
        'data-hm-gpt-inner-id': 'provider-mixed-shape',
    };
    const good = container(goodAttributes, 'hm-gpt-placement-mixed');
    const goodRuntime = runGpt(good);
    assert.equal(good.style.width, '970px');
    assert.equal(good.style.height, '90px');
    goodRuntime.emit(goodRuntime.definitions[0], { isEmpty: false, size: [300, 600] });
    assert.equal(goodAttributes['data-hm-gpt-status'], 'rendered');
    assert.equal(good.style.width, '300px');
    assert.equal(good.style.height, '600px');

    const badAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/mixed_shape_bad',
        'data-hm-gpt-sizes': '[[970,90],[300,600]]',
        'data-hm-gpt-inner-id': 'provider-mixed-shape-bad',
    };
    const bad = container(badAttributes, 'hm-gpt-placement-mixed-invalid');
    const badRuntime = runGpt(bad);
    const badSlot = badRuntime.definitions[0];
    badRuntime.emit(badSlot, { isEmpty: false, size: [970, 600] });
    assert.equal(badAttributes['data-hm-gpt-status'], 'failed');
    assert.equal(badAttributes['data-hm-gpt-rendered-width'], undefined);
    assert.equal(badAttributes['data-hm-gpt-rendered-height'], undefined);
    assert.deepEqual(badRuntime.destroyedSlots, [badSlot]);
});

test('Google GPT runtime uses unique Horus outer ids when provider container ids repeat', () => {
    const firstAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_a',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const secondAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_b',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const first = container(firstAttributes, 'hm-gpt-placement-a');
    const second = container(secondAttributes, 'hm-gpt-placement-b');
    const runtime = runGpt([first, second]);

    assert.equal(runtime.definitions.length, 2);
    assert.equal(runtime.definitions[0].id, 'hm-gpt-placement-a');
    assert.equal(runtime.definitions[1].id, 'hm-gpt-placement-b');
    assert.notEqual(runtime.definitions[0].id, runtime.definitions[1].id);
    assert.deepEqual(runtime.displayCalls, ['hm-gpt-placement-a', 'hm-gpt-placement-b']);
});

test('Google GPT direct runtime rejects non-normalized outer or provider identifiers', () => {
    const invalidOuterAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-inner',
    };
    const invalidOuter = container(invalidOuterAttributes, 'bad.id');
    const invalidInnerAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'bad.inner',
    };
    const invalidInner = container(invalidInnerAttributes, 'hm-gpt-placement-valid');
    const runtime = runGpt([invalidOuter, invalidInner]);

    assert.equal(runtime.definitions.length, 0);
    assert.equal(invalidOuterAttributes['data-hm-gpt-runtime-state'], 'invalid');
    assert.equal(invalidInnerAttributes['data-hm-gpt-runtime-state'], 'invalid');
});

test('Google GPT runtime injects the GPT library once into the publisher document when it is not already present', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/library_bootstrap',
        'data-hm-gpt-sizes': '[[320,50]]',
        'data-hm-gpt-inner-id': 'provider-library-bootstrap',
    };
    const target = container(attributes, 'hm-gpt-placement-library');
    const runtime = runGpt(target, { ready: false, width: 390 });

    assert.equal(attributes['data-hm-gpt-runtime-state'], 'queued');
    assert.equal(attributes['data-hm-gpt-document-context'], 'publisher');
    assert.equal(runtime.appendedScripts.length, 1);
    assert.equal(runtime.appendedScripts[0].src, 'https://securepubads.g.doubleclick.net/tag/js/gpt.js');
    assert.equal(runtime.appendedScripts[0].async, true);
    assert.equal(runtime.appendedScripts[0].getAttribute('crossorigin'), 'anonymous');
    assert.equal(runtime.appendedScripts[0].getAttribute('data-hm-gpt-library'), '1');

    vm.runInNewContext(gptSource, runtime.sandbox, { filename: 'hm-gpt-direct-second-load.js' });
    assert.equal(runtime.appendedScripts.length, 1, 'reloading the Horus runtime must not inject GPT twice');
});
