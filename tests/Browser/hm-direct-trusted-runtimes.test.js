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
    const intersectionObservers = [];

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
            controls: false,
            preload: '',
            src: '',
            currentTime: 0,
            duration: Number(options.contentDuration || 100),
            setAttribute(name, value) { attributes[name] = String(value); },
            getAttribute(name) { return attributes[name] ?? null; },
            appendChild(child) { child.parentNode = this; childNodes.push(child); return child; },
            addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
            removeEventListener(name, callback) {
                const list = listeners[name] || [];
                const index = list.indexOf(callback);
                if (index >= 0) list.splice(index, 1);
            },
            emit(name, event = {}) { (listeners[name] || []).slice().forEach((callback) => callback(event)); },
            click() { (listeners.click || []).forEach((callback) => callback({ isTrusted: options.trustedClick !== false, preventDefault() {}, stopPropagation() {} })); },
            pause() { this.paused = true; },
            play() {
                this.paused = false;
                if (options.contentPlay) return options.contentPlay(this);
                if (options.contentPlayRejects) return Promise.reject(new Error('content-play-failed'));
                return Promise.resolve();
            },
        };
    }

    const adEventTypes = {
        LOADED: 'loaded',
        STARTED: 'started',
        COMPLETE: 'complete',
        SKIPPED: 'skipped',
        ALL_ADS_COMPLETED: 'all-ads-completed',
        CONTENT_PAUSE_REQUESTED: 'content-pause-requested',
        CONTENT_RESUME_REQUESTED: 'content-resume-requested',
        LINEAR_CHANGED: 'linear-changed',
        USER_CLOSE: 'user-close',
    };
    class AdsManager {
        constructor() {
            this.listeners = {};
            this.destroyed = false;
            this.started = false;
            this.stopCalls = 0;
            this.ad = { linear: true, width: 300, height: 50, minSuggestedDuration: 0, ...(options.ads?.[managers.length] || options.ad || {}) };
            managers.push(this);
        }
        addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
        getAd() {
            return this.adApi ||= {
                isLinear: () => this.ad.linear,
                getWidth: () => this.ad.width,
                getHeight: () => this.ad.height,
                getMinSuggestedDuration: () => this.ad.minSuggestedDuration,
                getDuration: () => this.ad.duration ?? 10,
            };
        }
        emit(name, event = {}) { (this.listeners[name] || []).slice().forEach((callback) => callback({ getAd: () => this.getAd(), ...event })); }
        discardAdBreak() { this.discardCalls = (this.discardCalls || 0) + 1; this.emit('content-resume-requested'); }
        stop() {
            this.stopCalls++;
            for (const event of options.stopEvents || ['complete', 'all-ads-completed']) this.emit(event);
        }
        init(width, height, mode) { this.initialized = [width, height, mode]; if (options.loadedDuringInit) this.emit(adEventTypes.LOADED); }
        setVolume(volume) { this.volume = volume; }
        start() {
            if (options.managerStartThrows) throw new Error('manager-start-failed');
            this.started = true;
            if (!options.loadedDuringInit) this.emit(adEventTypes.LOADED);
            if (!options.deferMediaStart) this.emit(adEventTypes.STARTED);
        }
        resize(width, height, mode) { this.resized = [width, height, mode]; }
        getCuePoints() { return options.cuePoints || []; }
        destroy() { this.destroyed = true; }
    }
    class AdsLoader {
        constructor() { this.listeners = {}; this.contentCompleteCalled = false; this.contentCompleteCalls = 0; this.pendingManager = null; loaders.push(this); }
        destroy() { this.destroyed = true; }
        contentComplete() { this.contentCompleteCalled = true; this.contentCompleteCalls++; }
        addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
        emitManagerLoaded() {
            const manager = this.pendingManager;
            if (!manager) return;
            this.pendingManager = null;
            (this.listeners['ads-manager-loaded'] || []).forEach((callback) => callback({
                getAdsManager() { return manager; },
            }));
        }
        requestAds(request) {
            requested.push(request);
            this.pendingManager = new AdsManager();
            if (!options.deferManagerLoad) this.emitManagerLoaded();
        }
    }
    class AdsRequest {
        setAdWillAutoPlay(value) { this.willAutoPlay = value; }
        setAdWillPlayMuted(value) { this.willPlayMuted = value; }
        setContinuousPlayback(value) { this.continuousPlayback = value; }
    }
    class IntersectionObserver {
        constructor(callback, observerOptions) {
            this.callback = callback;
            this.options = observerOptions;
            this.disconnected = false;
            intersectionObservers.push(this);
        }
        observe(target) {
            this.target = target;
            if (options.autoIntersect !== false) this.callback([{ target, isIntersecting: true, intersectionRatio: 0.6 }]);
        }
        emit(ratio, isIntersecting = ratio > 0) {
            if (!this.disconnected) this.callback([{ target: this.target, isIntersecting, intersectionRatio: ratio }]);
        }
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
        btoa(value) { return Buffer.from(String(value), 'binary').toString('base64'); },
        setTimeout: options.clock ? options.clock.setTimeout : setTimeout,
        clearTimeout: options.clock ? options.clock.clearTimeout : clearTimeout,
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
    return { sandbox, requested, managers, loaders, displays, created, dispatched, storage, intersectionObservers };
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


test('accompanying content requests pre, mid, and post VAST breaks and declares GAM placement accurately', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&url=https%3A%2F%2Fold.example%2Fpage&description_url=https%3A%2F%2Fold.example%2Fpage&correlator=123&sz=400x225').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-inline-to-floating': '1',
        'data-hm-video-mid-roll-ratio': '0.5',
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
        'data-hm-video-sizes': '[[400,225],[320,180]]',
        'data-hm-video-muted': '1',
        'data-hm-video-autoplay': '1',
    };
    const target = container(attributes, 'hm-content-video');
    target.clientWidth = 400;
    const runtime = runVideo(target, { contentDuration: 100 });
    await tick();

    assert.equal(runtime.requested.length, 1);
    let requestUrl = new URL(runtime.requested[0].adTagUrl);
    assert.equal(requestUrl.searchParams.get('plcmt'), '2');
    assert.equal(requestUrl.searchParams.get('vpos'), 'preroll');
    assert.equal(requestUrl.searchParams.get('vconp'), '1');
    assert.equal(requestUrl.searchParams.get('vpa'), 'auto');
    assert.equal(requestUrl.searchParams.get('vpmute'), '1');
    assert.equal(requestUrl.searchParams.get('url'), 'https://publisher.example/article');
    assert.equal(requestUrl.searchParams.get('description_url'), 'https://publisher.example/article');
    assert.notEqual(requestUrl.searchParams.get('correlator'), '123');
    const pageCorrelator = requestUrl.searchParams.get('correlator');
    assert.match(pageCorrelator, /^\d+$/);

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    assert.ok(video);
    assert.equal(video.src, 'https://cdn.horusmedia.net/content/horus.mp4');
    assert.equal(attributes['data-hm-video-content-mode'], 'accompanying');

    runtime.managers[0].emit('all-ads-completed');
    await tick();
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.loaders[0].contentCompleteCalled, true);
    assert.equal(runtime.requested[0].contentDuration, 100);
    assert.equal(runtime.requested[0].continuousPlayback, false);

    video.currentTime = 50;
    video.emit('timeupdate');
    assert.equal(runtime.requested.length, 2);
    requestUrl = new URL(runtime.requested[1].adTagUrl);
    assert.equal(requestUrl.searchParams.get('vpos'), 'midroll');
    assert.equal(requestUrl.searchParams.get('vid_d'), '100');
    assert.equal(requestUrl.searchParams.get('correlator'), pageCorrelator);

    runtime.managers[1].emit('all-ads-completed');
    await tick();
    video.emit('ended');
    assert.equal(runtime.requested.length, 3);
    requestUrl = new URL(runtime.requested[2].adTagUrl);
    assert.equal(requestUrl.searchParams.get('vpos'), 'postroll');
    assert.equal(requestUrl.searchParams.get('correlator'), pageCorrelator);

    runtime.managers[2].emit('all-ads-completed');
    assert.equal(attributes['data-hm-video-status'], 'completed');
    assert.equal(target.style.display, 'none');
});

test('GAM ad-rules requests keep accompanying metadata but let the ad server own vpos', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&ad_rule=1&output=vmap&sz=400x225').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
    };
    const target = container(attributes, 'hm-content-ad-rules');
    target.clientWidth = 400;
    const runtime = runVideo(target, { contentDuration: 120, cuePoints: [0, 60, -1] });
    await tick();

    assert.equal(runtime.requested.length, 1);
    const url = new URL(runtime.requested[0].adTagUrl);
    assert.equal(url.searchParams.get('plcmt'), '2');
    assert.equal(url.searchParams.get('ad_rule'), '1');
    assert.equal(url.searchParams.get('vpos'), null);
    assert.equal(url.searchParams.get('vid_d'), '120');
    assert.equal(url.searchParams.get('vconp'), '1');
});

test('slow VAST responses cannot autoplay after inline viewability falls below 50 percent', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=slow').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-inline-to-floating': '1',
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
    };
    const target = container(attributes, 'hm-content-slow');
    target.clientWidth = 400;
    const runtime = runVideo(target, { autoIntersect: false, deferManagerLoad: true });
    await tick();

    const observer = runtime.intersectionObservers[0];
    observer.emit(0.6, true);
    assert.equal(runtime.requested.length, 1);
    assert.equal(runtime.managers[0].started, false);

    observer.emit(0.2, true);
    runtime.loaders[0].emitManagerLoaded();
    assert.equal(runtime.managers[0].started, false);
    assert.equal(attributes['data-hm-video-status'], 'waiting-ad-viewability');

    observer.emit(0.6, true);
    assert.equal(runtime.managers[0].started, true);
    assert.equal(attributes['data-hm-video-status'], 'started');
});

test('accompanying content failure never suppresses the VAST preroll request', async () => {
    const vastUrl = 'https://video.example.com/vast?slot=content-failure';
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from(vastUrl).toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/missing.mp4',
        'data-hm-video-content-mode': 'accompanying',
    };
    const target = container(attributes, 'hm-content-failure');
    const runtime = runVideo(target, { autoIntersect: false });
    await tick();

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    assert.ok(video);
    video.emit('error');
    assert.equal(attributes['data-hm-video-content-error'], 'load');
    assert.equal(runtime.requested.length, 0);

    runtime.intersectionObservers[0].emit(0.6, true);
    assert.equal(runtime.requested.length, 1);
    assert.equal(runtime.requested[0].adTagUrl, vastUrl);
    assert.equal(runtime.managers[0].started, true);

    runtime.managers[0].emit('all-ads-completed');
    assert.equal(attributes['data-hm-video-status'], 'content-error');
    assert.equal(target.style.display, 'none');
});

test('pre-request content failure still requests GAM VAST without falsely declaring accompanying content', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&plcmt=2&vpos=preroll&vid_d=60&sz=400x225').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/missing.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
    };
    const target = container(attributes, 'hm-content-fail-open-gam');
    target.clientWidth = 400;
    const runtime = runVideo(target, { autoIntersect: false });
    await tick();

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    video.emit('error');
    runtime.intersectionObservers[0].emit(0.6, true);

    assert.equal(runtime.requested.length, 1);
    const url = new URL(runtime.requested[0].adTagUrl);
    assert.equal(url.searchParams.get('iu'), '/123/video');
    assert.equal(url.searchParams.get('plcmt'), null);
    assert.equal(url.searchParams.get('vpos'), null);
    assert.equal(url.searchParams.get('vid_d'), null);
    assert.equal(runtime.managers[0].started, true);
});

test('late platform video failure closes cleanly after preroll instead of leaving a dead player', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=late-content-failure').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
    };
    const target = container(attributes, 'hm-content-late-failure');
    const runtime = runVideo(target);
    await tick();

    assert.equal(runtime.requested.length, 1);
    runtime.managers[0].emit('all-ads-completed');
    await tick();

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    video.emit('error');

    assert.equal(attributes['data-hm-video-content-error'], 'playback');
    assert.equal(attributes['data-hm-video-status'], 'content-error');
    assert.equal(target.style.display, 'none');
});

test('accompanying content floats only after it was visible inline and then scrolls out of view', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=float').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-inline-to-floating': '1',
        'data-hm-video-width': '400',
        'data-hm-video-height': '225',
    };
    const target = container(attributes, 'hm-content-float');
    target.clientWidth = 400;
    const surfaceAttributes = { 'data-placement': 'quick_video_floating' };
    const surface = {
        style: {},
        parentNode: null,
        getAttribute(name) { return surfaceAttributes[name] ?? null; },
        setAttribute(name, value) { surfaceAttributes[name] = String(value); },
    };
    target.parentNode = surface;

    const runtime = runVideo(target, { autoIntersect: false });
    await tick();
    const observer = runtime.intersectionObservers[0];
    observer.emit(0.6, true);
    assert.equal(runtime.requested.length, 1);
    assert.equal(surfaceAttributes['data-hm-floating-video-active'], undefined);

    observer.emit(0, false);
    assert.equal(surfaceAttributes['data-hm-floating-video-active'], '1');
    assert.equal(surfaceAttributes['data-hm-video-floating-state'], 'floating');
    assert.equal(surface.style.position, 'fixed');
    assert.equal(surface.style.right, 'calc(16px + env(safe-area-inset-right, 0px))');
    assert.match(surface.style.width, /400px/);
    assert.equal(runtime.dispatched.filter((event) => event.type === 'horus:video-floated').length, 1);
    assert.ok(runtime.managers[0].resized);
});

test('IMA ad-rules schedule disables duplicate manual midrolls and receives contentComplete', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vmap').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
        'data-hm-video-mid-roll-ratio': '0.5',
    };
    const target = container(attributes, 'hm-content-vmap');
    const runtime = runVideo(target, { contentDuration: 100, cuePoints: [0, 50, -1] });
    await tick();

    assert.equal(runtime.requested.length, 1);
    assert.equal(attributes['data-hm-video-ad-rules'], '1');
    runtime.managers[0].emit('content-pause-requested');
    runtime.managers[0].emit('content-resume-requested');
    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    video.currentTime = 60;
    video.emit('timeupdate');
    assert.equal(runtime.requested.length, 1);

    video.emit('ended');
    assert.equal(runtime.requested.length, 1);
    assert.equal(runtime.loaders[0].contentCompleteCalled, true);
});



test('IMA ad playback cannot trigger content-ended handling on the shared video element', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=shared-element').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
    };
    const target = container(attributes, 'hm-content-shared-element');
    const runtime = runVideo(target, { contentDuration: 100 });
    await tick();

    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    runtime.managers[0].emit('content-pause-requested');
    video.emit('ended');
    assert.equal(runtime.requested.length, 1);
    assert.equal(target.style.display, 'block');

    runtime.managers[0].emit('content-resume-requested');
    runtime.managers[0].emit('all-ads-completed');
    await tick();
    video.emit('ended');
    assert.equal(runtime.requested.length, 2);
    assert.equal(new URL(runtime.requested[1].adTagUrl).searchParams.get('vpos'), null);
});

test('ad-rules content without a postroll closes when content completes', async () => {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vmap?ad_rule=1').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
        'data-hm-video-content-mode': 'accompanying',
    };
    const target = container(attributes, 'hm-content-vmap-no-post');
    const runtime = runVideo(target, { contentDuration: 100, cuePoints: [0, 50] });
    await tick();

    assert.equal(attributes['data-hm-video-ad-rules'], '1');
    runtime.managers[0].emit('content-pause-requested');
    runtime.managers[0].emit('content-resume-requested');
    const video = runtime.created.find((node) => node.tagName === 'VIDEO');
    video.emit('ended');

    assert.equal(runtime.loaders[0].contentCompleteCalled, true);
    assert.equal(attributes['data-hm-video-status'], 'completed');
    assert.equal(target.style.display, 'none');
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
    assert.equal(url.searchParams.get('plcmt'), null);
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
    assert.equal(buttons[0].textContent, 'Watch ad');
    assert.equal(buttons[1].textContent, 'Continue without an ad');
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
    assert.equal(runtime.created.find(node => node.tagName === 'BUTTON').textContent, 'شاهد الإعلان');
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

test('floating VAST requests on each new page even with a recent reward grant', async () => {
    const storage = { 'hm:rewarded:v1:floating': String(Date.now()) };
    for (let visit = 0; visit < 2; visit++) {
        const attributes = {
            'data-hm-video-direct': '1',
            'data-hm-vast-url': Buffer.from('https://video.example.com/vast').toString('base64'),
            'data-hm-reward-cooldown-seconds': '900',
        };
        const runtime = runVideo(container(attributes, 'floating'), { storage });
        await tick();
        assert.equal(runtime.requested.length, 1);
        assert.notEqual(attributes['data-hm-video-status'], 'reward-capped');
    }
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


test('rewarded collision dismisses its prompt without disrupting the active video', async () => {
    const attributes = {
        'data-hm-video-direct': '1', 'data-hm-video-rewarded': '1',
        'data-hm-reward-experience': 'continue-reading',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast').toString('base64'),
    };
    const target = container(attributes, 'collision');
    const runtime = runVideo(target);
    await tick();
    const activeVideo = {destroyed: false};
    runtime.sandbox.__HORUS_VIDEO_DIRECT_RUNTIME_V1__.active = activeVideo;
    runtime.created.find(node => node.tagName === 'BUTTON').click();
    assert.equal(target.style.display, 'none');
    assert.equal(runtime.requested.length, 0);
    assert.equal(runtime.sandbox.__HORUS_VIDEO_DIRECT_RUNTIME_V1__.active, activeVideo);
    assert.equal(activeVideo.destroyed, false);
    assert.equal(runtime.dispatched.filter(event => event.type === 'horus:rewarded-granted').length, 0);
});

test('rewarded private-mode storage getter failure does not prevent opening or completion', async () => {
    const attributes = {
        'data-hm-video-direct': '1', 'data-hm-video-rewarded': '1',
        'data-hm-reward-experience': 'continue-reading',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast').toString('base64'),
    };
    const target = container(attributes, 'private');
    const runtime = runVideo(target);
    Object.defineProperty(runtime.sandbox, 'localStorage', {get() {throw new Error('Storage denied');}});
    await tick();
    runtime.created.find(node => node.tagName === 'BUTTON').click();
    runtime.managers[0].emit('complete');
    runtime.managers[0].emit('all-ads-completed');
    assert.equal(target.style.display, 'none');
    assert.equal(runtime.dispatched.filter(event => event.type === 'horus:rewarded-granted').length, 1);
});

function contentRecoveryFixture() {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://video.example.com/vast?slot=recovery').toString('base64'),
        'data-hm-video-content-url': 'https://cdn.horusmedia.net/content/horus.mp4',
    };
    const target = container(attributes, 'content-recovery');
    const runtime = runVideo(target);
    return { attributes, target, runtime };
}

test('shared IMA media errors do not poison the accompanying content source', async () => {
    const { attributes, target, runtime } = contentRecoveryFixture();
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    runtime.managers[0].emit('content-pause-requested');
    video.src = 'https://video.example.com/failed-ad.mp4';
    video.emit('error');
    video.src = attributes['data-hm-video-content-url'];
    runtime.managers[0].emit('ad-error', { getError: () => new Error('ad-media-failed') });
    await tick();
    assert.equal(target.__hmVideoPlayer.destroyed, false);
    assert.equal(target.__hmVideoPlayer.contentFailed, false);
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.requested.length, 1, 'content recovery must not request another ad');
});

test('a stale content play rejection cannot close a newer successful resume', async () => {
    const { attributes, target, runtime } = contentRecoveryFixture();
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    let rejectOld;
    let calls = 0;
    video.play = () => ++calls === 1 ? new Promise((_, reject) => { rejectOld = reject; }) : Promise.resolve();
    runtime.managers[0].emit('content-resume-requested');
    runtime.managers[0].emit('all-ads-completed');
    await tick();
    rejectOld(Object.assign(new Error('interrupted by IMA cleanup'), { name: 'AbortError' }));
    await tick();
    assert.equal(target.__hmVideoPlayer.destroyed, false);
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.requested.length, 1);
});

test('an autoplay policy refusal keeps native content playback available without retrying ads', async () => {
    const { attributes, target, runtime } = contentRecoveryFixture();
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.play = () => Promise.reject(Object.assign(new Error('activation needed'), { name: 'NotAllowedError' }));
    runtime.managers[0].emit('ad-error', { getError: () => new Error('no fill') });
    await tick();
    assert.equal(target.__hmVideoPlayer.destroyed, false);
    assert.equal(target.__hmVideoPlayer.contentFailed, false);
    assert.equal(attributes['data-hm-video-status'], 'content-ready');
    assert.equal(attributes['data-hm-video-detail'], 'user-activation-required');
    assert.equal(video.controls, true);
    assert.equal(target.__hmVideoPlayer.adLayer.style.pointerEvents, 'none', 'empty ad layer must not cover native controls');
    video.emit('playing');
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.requested.length, 1);
});

test('a retired IMA manager cannot terminate a subsequent legitimate content break', async () => {
    const { target, runtime } = contentRecoveryFixture();
    await tick();
    const first = runtime.managers[0];
    first.emit('all-ads-completed');
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.currentTime = 60;
    video.emit('timeupdate');
    assert.equal(runtime.requested.length, 2);
    const second = runtime.managers[1];
    first.emit('ad-error', { getError: () => new Error('late old callback') });
    await tick();
    assert.equal(second.destroyed, false);
    assert.equal(target.__hmVideoPlayer.adsManager, second);
    assert.equal(target.__hmVideoPlayer.adBreakPending, true);
    assert.equal(runtime.requested.length, 2);
});

function videoClock() {
    let now = 0, next = 0;
    const timers = new Map();
    return {
        setTimeout(fn, delay) { const id = ++next; timers.set(id, { fn, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        advance(ms) {
            const until = now + ms;
            while (true) {
                const due = [...timers].filter(([, timer]) => timer.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) break;
                now = due[1].at; timers.delete(due[0]); due[1].fn();
            }
            now = until;
        },
    };
}

for (const mode of ['content', 'ad-only', 'rewarded']) {
    test(`${mode} has independently bounded VAST and media phases, with no late callback resurrection`, async () => {
        const clock = videoClock();
        const attrs = {
            'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64'),
            ...(mode === 'content' ? { 'data-hm-video-content-url': 'https://media.example/content.mp4' } : {}),
            ...(mode === 'rewarded' ? { 'data-hm-video-rewarded': '1' } : {}),
        };
        const target = container(attrs, 'phase-player');
        const runtime = runVideo(target, { clock, deferManagerLoad: true, deferMediaStart: true });
        await tick();
        if (mode === 'rewarded') runtime.created.find(node => node.tagName === 'BUTTON' && node.getAttribute('data-hm-reward-activate') === '1')?.click();
        // The prompt's first button is the explicit user activation in this fixture.
        if (mode === 'rewarded' && !runtime.requested.length) runtime.created.find(node => node.tagName === 'BUTTON' && node.textContent === 'Watch ad')?.click();
        assert.equal(runtime.requested.length, 1);
        clock.advance(14000);
        runtime.loaders[0].emitManagerLoaded();
        clock.advance(12000);
        assert.equal(runtime.managers[0].destroyed, false, 'IMA gets its full 12s even after a 14s VAST response');
        clock.advance(3000);
        assert.equal(runtime.managers[0].destroyed, true);
        assert.equal(attrs['data-hm-video-error-stage'], 'media-start-timeout');
        runtime.managers[0].emit('started');
        assert.notEqual(attrs['data-hm-video-status'], 'started');
        assert.equal(runtime.requested.length, 1, 'no timer retries create extra auctions');
        if (mode === 'content') { await tick(); assert.equal(attrs['data-hm-video-status'], 'content-playing'); }
        if (mode === 'rewarded') assert.equal(attrs['data-hm-reward-granted'], undefined);
    });
}

test('VAST request timeout is bounded and a late manager is ignored', async () => {
    const clock = videoClock();
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64') };
    const runtime = runVideo(container(attrs), { clock, deferManagerLoad: true });
    await tick(); clock.advance(15000);
    assert.equal(attrs['data-hm-video-error-stage'], 'request-timeout');
    runtime.loaders[0].emitManagerLoaded();
    assert.equal(runtime.managers[0].started, false);
    assert.equal(attrs['data-hm-video-status'], 'error');
});

test('a late return to viewability still receives a full media startup budget', async () => {
    const clock = videoClock();
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64') };
    const target = container(attrs);
    const runtime = runVideo(target, { clock, deferManagerLoad: true, deferMediaStart: true });
    await tick();
    runtime.intersectionObservers[0].emit(0);
    runtime.loaders[0].emitManagerLoaded();
    clock.advance(14000);
    assert.equal(runtime.managers[0].started, false);
    runtime.intersectionObservers[0].emit(1);
    clock.advance(12000);
    assert.equal(runtime.managers[0].destroyed, false);
    runtime.managers[0].emit('started');
    clock.advance(20000);
    assert.equal(runtime.managers[0].destroyed, false);
    target.__hmDestroy('dismissed');
});

for (const [width, height] of [[300,250],[320,180],[336,280],[400,225],[400,300],[640,480]]) {
    test(`video master ${width}x${height} uses its real dimensions and linear-only GAM tag`, async () => {
        const original = 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=1x1&vad_type=linear_nonlinear&nofb=1&max_ad_duration=15000';
        const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from(original).toString('base64'),
            'data-hm-video-width': String(width), 'data-hm-video-height': String(height), 'data-hm-video-inline-to-floating': '1' };
        const target = container(attrs);
        target.clientWidth = width;
        const runtime = runVideo(target);
        await tick();
        const request = runtime.requested[0], tag = new URL(request.adTagUrl);
        assert.equal(tag.searchParams.get('sz'), '1x1');
        assert.equal(tag.searchParams.get('vad_type'), 'linear');
        assert.equal(tag.searchParams.get('nofb'), '1');
        assert.equal(tag.searchParams.get('max_ad_duration'), '15000');
        assert.equal(request.linearAdSlotWidth, width);
        assert.equal(request.linearAdSlotHeight, height);
        runtime.intersectionObservers[0].emit(0);
        assert.equal(target.style.aspectRatio, `${width} / ${height}`);
        assert.equal(target.getAttribute('data-hm-video-master-height'), String(height));
        target.__hmDestroy('dismissed');
    });
}

test('long VAST attributes roundtrip 10000 URL bytes without changing third-party parameters', async () => {
    const prefix = 'https://ads.example/vast?custom=';
    const url = prefix + 'x'.repeat(10000 - prefix.length);
    const encoded = Buffer.from(url).toString('base64');
    const parts = encoded.match(/.{1,1800}/g);
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url-parts': String(parts.length) };
    parts.forEach((value, index) => attrs[`data-hm-vast-url-${index}`] = value);
    const target = container(attrs), runtime = runVideo(target);
    await tick();
    assert.equal(runtime.requested[0].adTagUrl, url);
    target.__hmDestroy('dismissed');
});

for (const invalid of [
    { 'data-hm-vast-url-parts': '2', 'data-hm-vast-url-0': 'a'.repeat(1800) },
    { 'data-hm-vast-url-parts': '9' },
    { 'data-hm-vast-url-parts': '2', 'data-hm-vast-url': 'abcd' },
    { 'data-hm-vast-url-0': 'abcd' },
    { 'data-hm-vast-url': '%%%=' },
    { 'data-hm-vast-url': Buffer.from('https://ads.example/' + 'x'.repeat(10000)).toString('base64') },
]) {
    test(`invalid VAST transport rejects before SDK/ad request: ${Object.keys(invalid).join(',')}`, async () => {
        const attrs = { 'data-hm-video-direct': '1', ...invalid };
        const runtime = runVideo(container(attrs));
        await tick();
        assert.equal(runtime.requested.length, 0);
        assert.equal(attrs['data-hm-video-status'], 'invalid');
    });
}


test('VMAP without preroll retires the startup timer while keeping future breaks alive', async () => {
    const clock = videoClock();
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://ads.example/vmap').toString('base64'),
        'data-hm-video-content-url': 'https://media.example/content.mp4' };
    const target = container(attrs);
    const runtime = runVideo(target, { clock, cuePoints: [30, -1], deferMediaStart: true });
    await tick();
    runtime.managers[0].emit('content-resume-requested');
    clock.advance(60000);
    await tick();
    assert.equal(runtime.managers[0].destroyed, false);
    assert.equal(attrs['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('waiting for ad viewability expires without forcing a hidden start or retry', async () => {
    const clock = videoClock();
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64'),
        'data-hm-video-content-url': 'https://media.example/content.mp4' };
    const target = container(attrs);
    const runtime = runVideo(target, { clock, deferManagerLoad: true });
    await tick();
    runtime.intersectionObservers[0].emit(0);
    runtime.loaders[0].emitManagerLoaded();
    clock.advance(15000);
    await tick();
    assert.equal(runtime.managers[0].started, false);
    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(attrs['data-hm-video-error-stage'], 'viewability-timeout');
    assert.equal(attrs['data-hm-video-status'], 'content-playing');
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('measured dimensions reflect a constrained player box rather than its master preset', async () => {
    const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video').toString('base64'),
        'data-hm-video-width': '640', 'data-hm-video-height': '480' };
    const target = container(attrs);
    target.getBoundingClientRect = () => ({ x: 0, y: 0, top: 0, left: 0, width: 280, height: 210, right: 280, bottom: 210 });
    const runtime = runVideo(target);
    await tick();
    assert.equal(new URL(runtime.requested[0].adTagUrl).searchParams.get('sz'), '640x480');
    assert.equal(runtime.requested[0].linearAdSlotWidth, 280);
    assert.equal(runtime.requested[0].linearAdSlotHeight, 210);
    target.__hmDestroy('dismissed');
});

for (const [width, height] of [[300,250],[320,180],[336,280],[400,225],[400,300],[640,480]]) {
    for (const configured of [`${width}x${height}`, '300x250|640x480', '1x1', null, '', '[width]x[height]', '0x0', '300x250|oops']) {
        test(`enlarged inline ${width}x${height} keeps independent GAM targeting ${configured}`, async () => {
            const tag = new URL('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&cust_params=section%3Dnews');
            if (configured !== null) tag.searchParams.set('sz', configured);
            const attrs = { 'data-hm-video-direct': '1', 'data-hm-vast-url': Buffer.from(tag.href).toString('base64'),
                'data-hm-video-width': String(width), 'data-hm-video-height': String(height) };
            const target = container(attrs), actualWidth = 640, actualHeight = 640 * height / width;
            target.clientWidth = actualWidth;
            target.getBoundingClientRect = () => ({ top: 0, left: 0, width: actualWidth, height: actualHeight, right: actualWidth, bottom: actualHeight });
            const runtime = runVideo(target);
            await tick();
            const request = runtime.requested[0], resolved = new URL(request.adTagUrl);
            const valid = configured === `${width}x${height}` || configured === '300x250|640x480' || configured === '1x1';
            assert.equal(resolved.searchParams.get('sz'), valid ? configured : `${width}x${height}`);
            assert.equal(resolved.searchParams.get('cust_params'), 'section=news');
            assert.equal(request.linearAdSlotWidth, actualWidth);
            assert.equal(request.linearAdSlotHeight, Math.round(actualHeight));
            assert.equal(target.style.maxWidth, '640px');
            assert.equal(target.style.aspectRatio, `${width} / ${height}`);
            assert.equal(runtime.requested.length, 1);
            target.__hmDestroy('dismissed');
        });
    }
}

test('client-width fallback reports an enlarged real layout instead of capping to the floating master', async () => {
    const target = container({ 'data-hm-video-direct': '1', 'data-hm-video-width': '320', 'data-hm-video-height': '180',
        'data-hm-vast-url': Buffer.from('https://ads.example/vast?sz=unchanged').toString('base64') });
    target.clientWidth = 672;
    const runtime = runVideo(target);
    await tick();
    assert.equal(runtime.requested[0].linearAdSlotWidth, 672);
    assert.equal(runtime.requested[0].linearAdSlotHeight, 378);
    assert.equal(runtime.requested[0].adTagUrl, 'https://ads.example/vast?sz=unchanged');
    target.__hmDestroy('dismissed');
});

for (const gate of ['background', 'under-half-visible']) {
    test(`a pending floating manager waits for actual foreground viewability: ${gate}`, async () => {
        const target = container({ 'data-hm-video-direct': '1',
            'data-hm-vast-url': Buffer.from('https://ads.example/vast').toString('base64') });
        const runtime = runVideo(target, { deferManagerLoad: true });
        await tick();
        assert.equal(runtime.requested.length, 1);
        target.__hmVideoPlayer.floating = true;
        if (gate === 'background') runtime.sandbox.document.visibilityState = 'hidden';
        else runtime.intersectionObservers[0].emit(0.25);
        let starts = 0;
        const manager = runtime.managers[0], originalStart = manager.start.bind(manager);
        manager.start = () => { starts++; originalStart(); };
        runtime.loaders[0].emitManagerLoaded();
        assert.equal(manager.started, false);
        runtime.intersectionObservers[0].emit(gate === 'background' ? 1 : 0.49);
        assert.equal(manager.started, false);
        runtime.sandbox.document.visibilityState = 'visible';
        runtime.intersectionObservers[0].emit(0.6);
        runtime.intersectionObservers[0].emit(1);
        assert.equal(manager.started, true);
        assert.equal(starts, 1);
        assert.equal(runtime.requested.length, 1);
        target.__hmDestroy('dismissed');
    });
}

// Mixed-format regressions use deterministic IMA boundary doubles. They do not
// claim live GAM eligibility, SDK rendering, or paid demand availability.
function mixedContentFixture(overrides = {}, options = {}) {
    const attributes = {
        'data-hm-video-direct': '1',
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=336x280&cust_params=section%3Dnews&gdpr=1&gdpr_consent=fixture-consent&us_privacy=1YNN&npa=1').toString('base64'),
        'data-hm-video-content-url': 'https://media.example/content.mp4',
        'data-hm-video-width': '336', 'data-hm-video-height': '280',
        'data-hm-video-inline-to-floating': '1',
        ...overrides,
    };
    const target = container(attributes, 'mixed-content');
    target.clientWidth = 640;
    if (options.withChrome) {
        const surface = container({ 'data-placement': 'video' });
        surface.appendChild(target);
        surface.insertBefore = function (child, next) {
            const index = this.childNodes.indexOf(next);
            this.childNodes.splice(index < 0 ? this.childNodes.length : index, 0, child);
            child.parentNode = this;
        };
    }
    const runtime = runVideo(target, options);
    return { attributes, target, runtime, video: runtime.created.find(node => node.tagName === 'VIDEO') };
}

for (const format of [undefined, 'mixed', 'video_only']) {
    test(`ordinary content declares its ${format || 'default mixed'} ad format without losing GAM privacy or manual targeting`, async () => {
        const { target, runtime } = mixedContentFixture(format ? { 'data-hm-video-ad-format': format } : {});
        await tick();
        const request = runtime.requested[0], tag = new URL(request.adTagUrl);
        assert.equal(tag.searchParams.get('vad_type'), format === 'video_only' ? 'linear' : null);
        assert.equal(tag.searchParams.get('sz'), '336x280');
        assert.equal(tag.searchParams.get('cust_params'), 'section=news');
        assert.equal(tag.searchParams.get('gdpr'), '1');
        assert.equal(tag.searchParams.get('gdpr_consent'), 'fixture-consent');
        assert.equal(tag.searchParams.get('us_privacy'), '1YNN');
        assert.equal(tag.searchParams.get('npa'), '1');
        assert.equal(tag.searchParams.get('plcmt'), '2');
        assert.equal(tag.searchParams.get('vpos'), 'preroll');
        assert.equal(tag.searchParams.get('vpa'), 'auto');
        assert.equal(tag.searchParams.get('vpmute'), '1');
        assert.equal(request.linearAdSlotWidth, 640);
        assert.equal(request.linearAdSlotHeight, 533);
        target.__hmDestroy('dismissed');
    });
}

for (const [width, height] of [[320, 180], [336, 280], [400, 225]]) {
    test(`nonlinear availability is the full safe compact ${width}x${height} area, never an invented GAM creative size`, async () => {
        const { target, runtime } = mixedContentFixture({ 'data-hm-video-width': String(width), 'data-hm-video-height': String(height) });
        await tick();
        const request = runtime.requested[0], tag = new URL(request.adTagUrl);
        assert.equal(request.nonLinearAdSlotWidth, width);
        assert.equal(request.nonLinearAdSlotHeight, height);
        assert.equal(tag.searchParams.get('vad_type'), null);
        assert.equal(tag.searchParams.has('afvsz'), false);
        assert.notEqual(request.forceNonLinearFullSlot, true);
        target.__hmDestroy('dismissed');
    });
}

test('a nonlinear LOADED event resumes content, retains clickable SDK ownership and content-ended observation even before STARTED', async () => {
    const clock = videoClock();
    const { attributes, target, runtime } = mixedContentFixture({}, { ad: { linear: false, width: 300, height: 50 }, deferMediaStart: true, clock });
    await tick();
    const player = target.__hmVideoPlayer, video = runtime.created.find(node => node.tagName === 'VIDEO');
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(player.adMediaActive, false);
    assert.equal(player.contentEndedAttached, true);
    assert.equal(video.paused, false);
    assert.equal(player.adLayer.style.pointerEvents, 'auto');
    assert.equal(runtime.managers[0].destroyed, false);
    clock.advance(60000);
    await tick();
    assert.equal(runtime.managers[0].destroyed, false, 'nonlinear LOADED retires linear media-start watchdog');
    video.currentTime = 70; video.emit('timeupdate'); video.emit('timeupdate');
    assert.equal(runtime.requested.length, 1, 'an overlay blocks new manual breaks');
    target.__hmDestroy('dismissed');
});

for (const terminal of ['user-close', 'complete', 'all-ads-completed']) {
    test(`nonlinear ${terminal} without another terminal event leaves playable content and rejects duplicate callbacks`, async () => {
        const { target, runtime } = mixedContentFixture({}, { ad: { linear: false, width: 300, height: 50 } });
        await tick();
        const manager = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
        video.currentTime = 1;
        manager.emit(terminal);
        await tick();
        assert.notEqual(target.style.display, 'none');
        assert.equal(video.paused, false);
        assert.equal(target.__hmVideoPlayer.destroyed, false);
        assert.equal(manager.destroyed, true);
        assert.equal(target.__hmVideoPlayer.adLayer.style.pointerEvents, 'none');
        for (const event of ['user-close', 'complete', 'all-ads-completed', 'linear-changed', 'content-resume-requested']) manager.emit(event);
        assert.equal(runtime.requested.length, 1);
        assert.equal(target.__hmVideoPlayer.adMediaActive, false);
        target.__hmDestroy('dismissed');
    });
}

for (const stopEvents of [[], ['complete', 'all-ads-completed', 'all-ads-completed']]) {
    test(`five-second content ending under a nonlinear overlay stops that overlay and requests exactly one postroll (${stopEvents.length} stop callbacks)`, async () => {
        const { attributes, target, runtime } = mixedContentFixture({}, {
            contentDuration: 5, ads: [{ linear: false, width: 300, height: 50, minSuggestedDuration: 10 }, { linear: true }], stopEvents,
        });
        await tick();
        const first = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
        video.currentTime = 2.6; video.emit('timeupdate');
        assert.equal(runtime.requested.length, 1);
        video.currentTime = 5; video.emit('ended');
        await tick();
        assert.equal(first.stopCalls, 1);
        assert.equal(first.destroyed, true);
        assert.equal(runtime.requested.length, 2);
        assert.equal(new URL(runtime.requested[1].adTagUrl).searchParams.get('vpos'), 'postroll');
        for (const event of ['user-close', 'complete', 'all-ads-completed', 'content-resume-requested']) first.emit(event);
        video.emit('ended'); video.emit('timeupdate');
        assert.equal(runtime.requested.length, 2);
        runtime.managers[1].emit('all-ads-completed');
        assert.equal(attributes['data-hm-video-status'], 'completed');
        assert.equal(target.style.display, 'none', 'finished content never leaves a dead pinned surface');
    });
}

test('LINEAR_CHANGED takes back the shared media element and restores nonlinear content ownership on the return transition', async () => {
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false, width: 300, height: 50 } });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
    manager.ad.linear = true;
    manager.emit('linear-changed');
    assert.equal(player.adMediaActive, true);
    assert.equal(video.paused, true);
    assert.equal(player.contentEndedAttached, false);
    video.emit('ended'); video.emit('error');
    assert.equal(player.contentEnded, false);
    assert.equal(player.contentFailed, false);
    assert.equal(runtime.requested.length, 1);
    manager.ad.linear = false;
    manager.emit('linear-changed');
    await tick();
    assert.equal(player.adMediaActive, false);
    assert.equal(player.contentEndedAttached, true);
    assert.equal(player.adLayer.style.pointerEvents, 'auto');
    assert.equal(video.paused, false);
    target.__hmDestroy('dismissed');
});

test('viewport shrink retires an oversized nonlinear creative through IMA and never inflates the compact master', async () => {
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false, width: 300, height: 250 } });
    await tick();
    const manager = runtime.managers[0], player = target.__hmVideoPlayer;
    assert.equal(manager.destroyed, false);
    target.clientWidth = 240;
    target.clientHeight = 200;
    target.getBoundingClientRect = () => ({ top: 0, left: 0, width: 240, height: 200, right: 240, bottom: 200 });
    runtime.sandbox.dispatchEvent(new runtime.sandbox.CustomEvent('resize'));
    await tick();
    assert.equal(manager.stopCalls, 1);
    assert.equal(manager.destroyed, true);
    assert.equal(player.size[0], 336);
    assert.equal(player.size[1], 280);
    assert.equal(target.style.aspectRatio, '336 / 280');
    assert.notEqual(target.style.display, 'none');
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('third-party mixed VAST URLs stay byte-for-byte intact while the IMA lifecycle supports nonlinear content', async () => {
    const url = 'https://ads.example/vast?token=fixture%2Btoken&vad_type=linear_nonlinear&sz=300x50&custom=1';
    const { target, runtime } = mixedContentFixture({ 'data-hm-vast-url': Buffer.from(url).toString('base64') }, { ad: { linear: false } });
    await tick();
    assert.equal(runtime.requested[0].adTagUrl, url);
    assert.equal(target.__hmVideoPlayer.adMediaActive, false);
    target.__hmDestroy('dismissed');
});

test('midroll autoplay and mute declarations reflect content controls rather than stale startup defaults', async () => {
    const { target, runtime } = mixedContentFixture();
    await tick();
    runtime.managers[0].emit('all-ads-completed');
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.muted = false; video.currentTime = 55; video.emit('timeupdate');
    assert.equal(runtime.requested.length, 2);
    const request = runtime.requested[1], tag = new URL(request.adTagUrl);
    assert.equal(tag.searchParams.get('vpmute'), '0');
    assert.equal(tag.searchParams.get('vpa'), 'auto');
    assert.equal(request.willPlayMuted, false);
    assert.equal(request.willAutoPlay, true);
    target.__hmDestroy('dismissed');
});

test('an xml_vmap1 request preserves server-owned scheduling even without ad_rule', async () => {
    const { target, runtime } = mixedContentFixture({ 'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&output=xml_vmap1&vpos=preroll').toString('base64') }, { cuePoints: [0, 20, -1] });
    await tick();
    const tag = new URL(runtime.requested[0].adTagUrl);
    assert.equal(tag.searchParams.get('output'), 'xml_vmap1');
    assert.equal(tag.searchParams.get('vpos'), null);
    runtime.managers[0].emit('content-resume-requested');
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.currentTime = 70; video.emit('timeupdate');
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('a nonlinear postroll cannot resume already ended content or leave a pinned surface', async () => {
    const { attributes, target, runtime } = mixedContentFixture({}, { ads: [{ linear: true }, { linear: false, width: 300, height: 50 }] });
    await tick();
    runtime.managers[0].emit('all-ads-completed');
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.currentTime = 100; video.emit('ended');
    await tick();
    assert.equal(runtime.requested.length, 2);
    assert.equal(new URL(runtime.requested[1].adTagUrl).searchParams.get('vpos'), 'postroll');
    assert.equal(runtime.managers[1].stopCalls, 1);
    assert.equal(attributes['data-hm-video-status'], 'completed');
    assert.equal(target.style.display, 'none');
    assert.equal(video.paused, true);
});

test('content failure during an active nonlinear overlay retires it and closes immediately', async () => {
    const { attributes, target, runtime } = mixedContentFixture({}, { ad: { linear: false } });
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.emit('error');
    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(attributes['data-hm-video-status'], 'content-error');
    assert.equal(target.style.display, 'none');
    assert.equal(runtime.requested.length, 1);
});

test('a manual midroll cue passed during a live overlay is consumed rather than queued after USER_CLOSE', async () => {
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false } });
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.currentTime = 70; video.emit('timeupdate');
    runtime.managers[0].emit('user-close');
    await tick();
    video.currentTime = 71; video.emit('timeupdate');
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('unmuted midroll manager volume matches the request and GAM vpmute declaration', async () => {
    const { target, runtime } = mixedContentFixture();
    await tick();
    runtime.managers[0].emit('all-ads-completed');
    await tick();
    const video = runtime.created.find(node => node.tagName === 'VIDEO');
    video.muted = false; video.currentTime = 60; video.emit('timeupdate');
    assert.equal(runtime.managers[1].volume, 1);
    assert.equal(runtime.requested[1].willPlayMuted, false);
    assert.equal(new URL(runtime.requested[1].adTagUrl).searchParams.get('vpmute'), '0');
    target.__hmDestroy('dismissed');
});

test('nonlinear USER_CLOSE honors an explicit content pause and leaves native play usable', async () => {
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false }, withChrome: true });
    await tick();
    const player = target.__hmVideoPlayer, video = runtime.created.find(node => node.tagName === 'VIDEO');
    assert.ok(player.overlayPlay, 'fixture includes the real external chrome controls');
    player.overlayPlay.click();
    assert.equal(video.paused, true);
    assert.equal(player.contentPausedByUser, true);
    runtime.managers[0].emit('user-close');
    await tick();
    assert.equal(video.paused, true, 'closing the SDK overlay must not undo a content pause');
    assert.equal(video.controls, true);
    await video.play(); video.emit('play'); video.emit('playing');
    assert.equal(player.contentPausedByUser, false);
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('content error while the user paused beneath nonlinear still closes the finished surface', async () => {
    const { attributes, target, runtime } = mixedContentFixture({}, { ad: { linear: false }, withChrome: true });
    await tick();
    target.__hmVideoPlayer.overlayPlay.click();
    runtime.created.find(node => node.tagName === 'VIDEO').emit('error');
    assert.equal(attributes['data-hm-video-status'], 'content-error');
    assert.equal(runtime.managers[0].destroyed, true);
    assert.equal(target.__hmVideoPlayer.destroyed, true);
});

test('a stale nonlinear play rejection after explicit pause cannot mark user-paused content failed', async () => {
    const pending = [];
    const { attributes, target, runtime } = mixedContentFixture({}, {
        ad: { linear: false }, withChrome: true,
        contentPlay: () => new Promise((resolve, reject) => pending.push({ resolve, reject })),
    });
    await tick();
    const player = target.__hmVideoPlayer, video = runtime.created.find(node => node.tagName === 'VIDEO');
    player.overlayPlay.click();
    for (const promise of pending) promise.reject(new Error('obsolete play operation failed'));
    await tick();
    assert.equal(player.destroyed, false);
    assert.equal(player.contentFailed, false);
    assert.equal(video.paused, true);
    assert.notEqual(attributes['data-hm-video-status'], 'content-error');
    target.__hmDestroy('dismissed');
});

test('an unsupported VMAP nonlinear break is discarded once without destroying later scheduled linear breaks', async () => {
    const { target, runtime } = mixedContentFixture({
        'data-hm-vast-url': Buffer.from('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&output=xml_vmap1').toString('base64'),
    }, { ad: { linear: false }, cuePoints: [0, 20, -1] });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
    assert.equal(manager.discardCalls, 1);
    assert.equal(manager.destroyed, false);
    assert.equal(player.adMediaActive, false);
    assert.equal(video.paused, false);
    manager.emit('content-pause-requested'); manager.emit('started');
    assert.equal(manager.discardCalls, 1);
    assert.equal(player.adMediaActive, false);
    manager.ad = { linear: true }; manager.adApi = null;
    manager.emit('loaded'); manager.emit('content-pause-requested'); manager.emit('started');
    assert.equal(player.adMediaActive, true);
    assert.equal(video.paused, true);
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('VMAP preloading a future linear LOADED does not pause content or replace the actual current ad', async () => {
    const { target, runtime } = mixedContentFixture({}, { cuePoints: [0, 20, -1] });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
    manager.emit('content-resume-requested');
    await tick();
    const currentAd = player.currentAd;
    const futureAd = { isLinear: () => true, getWidth: () => 400, getHeight: () => 225, getMinSuggestedDuration: () => 0 };
    manager.emit('loaded', { getAd: () => futureAd });
    assert.equal(video.paused, false);
    assert.equal(player.adMediaActive, false);
    assert.equal(player.currentAd, currentAd);
    manager.emit('content-pause-requested', { getAd: () => futureAd });
    assert.equal(video.paused, true);
    assert.equal(player.adMediaActive, true);
    assert.equal(player.currentAd, futureAd);
    target.__hmDestroy('dismissed');
});

test('a preloaded LOADED for the next pod ad cannot replace a still-active nonlinear creative', async () => {
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false } });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0], video = runtime.created.find(node => node.tagName === 'VIDEO');
    const currentAd = player.currentAd;
    manager.emit('loaded', { getAd: () => ({ isLinear: () => true, getWidth: () => 400, getHeight: () => 225 }) });
    assert.equal(player.currentAd, currentAd);
    assert.equal(player.nonLinearAdActive, true);
    assert.equal(player.adMediaActive, false);
    assert.equal(video.paused, false);
    target.__hmDestroy('dismissed');
});

test('nonlinear LOADED during init is already ready and start cannot rearm the media watchdog', async () => {
    const clock = videoClock();
    const { target, runtime } = mixedContentFixture({}, { ad: { linear: false }, loadedDuringInit: true, deferMediaStart: true, clock });
    await tick();
    assert.equal(runtime.managers[0].started, true);
    assert.equal(target.__hmVideoPlayer.nonLinearAdActive, true);
    clock.advance(20000);
    await tick();
    assert.equal(runtime.managers[0].destroyed, false);
    assert.equal(target.__hmVideoPlayer.destroyed, false);
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

function emptyVastEvent(code = 1009) {
    return { getError: () => ({ getErrorCode: () => code, getVastErrorCode: () => 303, message: 'Empty VAST' }) };
}
function emitLoaderError(loader, event) {
    (loader.listeners['ad-error'] || []).slice().forEach(callback => callback(event));
}

for (const code of [303, 1009]) {
    test(`confirmed preroll no-fill ${code} makes one fresh request before content, preserving the tag and ignoring retired callbacks`, async () => {
        const { attributes, target, runtime, video } = mixedContentFixture({}, { deferManagerLoad: true });
        await tick();
        const first = runtime.loaders[0];
        emitLoaderError(first, emptyVastEvent(code));
        assert.equal(runtime.requested.length, 2);
        assert.equal(runtime.requested[1].adTagUrl, runtime.requested[0].adTagUrl);
        assert.equal(attributes['data-hm-video-preroll-attempts'], '2');
        assert.equal(target.__hmVideoPlayer.contentStarted, false);
        assert.equal(first.destroyed, true);
        first.emitManagerLoaded();
        emitLoaderError(first, emptyVastEvent(code));
        assert.equal(runtime.requested.length, 2);
        runtime.loaders[1].emitManagerLoaded();
        assert.equal(attributes['data-hm-video-status'], 'started');
        runtime.managers[1].emit('all-ads-completed');
        await tick();
        assert.equal(attributes['data-hm-video-status'], 'content-playing');
        assert.equal(video.paused, false);
        target.__hmDestroy('dismissed');
    });
}

test('two empty prerolls fall through to content, while midroll/postroll no-fill never retries', async () => {
    const { attributes, target, runtime, video } = mixedContentFixture({}, { deferManagerLoad: true });
    await tick();
    emitLoaderError(runtime.loaders[0], emptyVastEvent());
    emitLoaderError(runtime.loaders[1], emptyVastEvent());
    await tick();
    assert.equal(runtime.requested.length, 2);
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    assert.equal(target.__hmVideoPlayer.adPresentationActive, false);
    video.currentTime = 60; video.emit('timeupdate');
    assert.equal(runtime.requested.length, 3);
    emitLoaderError(runtime.loaders[2], emptyVastEvent());
    await tick();
    assert.equal(runtime.requested.length, 3);
    video.emit('ended');
    assert.equal(runtime.requested.length, 4);
    emitLoaderError(runtime.loaders[3], emptyVastEvent());
    assert.equal(runtime.requested.length, 4);
    assert.equal(target.__hmVideoPlayer.destroyed, true);
});

for (const code of [100, 301, 402, 403, 1005, 1007, 1012, 1205, 900]) {
    test(`preroll error ${code} is not confirmed no-fill, even with a contradictory VAST wrapper code`, async () => {
        const { target, runtime, attributes } = mixedContentFixture({}, { deferManagerLoad: true });
        await tick();
        emitLoaderError(runtime.loaders[0], emptyVastEvent(code));
        await tick();
        assert.equal(runtime.requested.length, 1);
        assert.equal(attributes['data-hm-video-status'], 'content-playing');
        target.__hmDestroy('dismissed');
    });
}

test('no retry after an ad was loaded/started or after dismissal, and generic completion is not proof of no-fill', async () => {
    for (const event of ['ad-error', 'all-ads-completed', 'dismiss']) {
        const { target, runtime } = mixedContentFixture();
        await tick();
        if (event === 'dismiss') target.__hmDestroy('dismissed');
        runtime.managers[0].emit(event === 'dismiss' ? 'ad-error' : event, emptyVastEvent());
        emitLoaderError(runtime.loaders[0], emptyVastEvent());
        await tick();
        assert.equal(runtime.requested.length, 1);
        target.__hmDestroy('dismissed');
    }
});

test('one retry retains a bounded deadline and never resurrects after close', async () => {
    const clock = videoClock();
    const { target, runtime, attributes } = mixedContentFixture({}, { clock, deferManagerLoad: true });
    await tick();
    emitLoaderError(runtime.loaders[0], emptyVastEvent());
    clock.advance(15000);
    await tick();
    assert.equal(runtime.requested.length, 2);
    assert.equal(attributes['data-hm-video-status'], 'content-playing');
    target.__hmDestroy('dismissed');
    runtime.loaders[1].emitManagerLoaded();
    emitLoaderError(runtime.loaders[1], emptyVastEvent());
    assert.equal(runtime.requested.length, 2);
    assert.equal(attributes['data-hm-video-status'], 'dismissed');
});

test('VMAP preloading is not a current ad and returning content clears presentation without losing later cues', async () => {
    const { target, runtime } = mixedContentFixture({}, { cuePoints: [0, 50, -1] });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0];
    assert.equal(player.adPresentationActive, true);
    manager.emit('content-resume-requested');
    assert.equal(player.adPresentationActive, false);
    manager.emit('loaded');
    assert.equal(player.adPresentationActive, false, 'a future preloaded cue must not float content');
    manager.emit('content-pause-requested');
    assert.equal(player.adPresentationActive, true);
    manager.emit('started');
    manager.emit('complete');
    assert.equal(player.adPresentationActive, false);
    assert.equal(manager.destroyed, false);
    assert.equal(runtime.requested.length, 1);
    target.__hmDestroy('dismissed');
});

test('a break-level pause without an ad does not float an empty response', async () => {
    const { target, runtime } = mixedContentFixture({}, { cuePoints: [0, 50] });
    await tick();
    const player = target.__hmVideoPlayer, manager = runtime.managers[0];
    manager.emit('content-resume-requested');
    manager.emit('content-pause-requested', { getAd: () => null });
    assert.equal(player.adMediaActive, true, 'IMA retains ownership of the shared video');
    assert.equal(player.adPresentationActive, false, 'no ad has become available yet');
    manager.emit('started');
    assert.equal(player.adPresentationActive, true);
    target.__hmDestroy('dismissed');
});
