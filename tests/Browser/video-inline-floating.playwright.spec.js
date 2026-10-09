import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

// Generated 12-second, silent H.264 baseline fixture; no third-party media or ads.
const contentBytes = Buffer.from(await readFile(new URL('./fixtures/content-playback.mp4.base64', import.meta.url), 'utf8'), 'base64');
// Local VP9/Opus fixture with a quiet generated tone for actual sound-capability tests.
const audioContentBytes = Buffer.from(await readFile(new URL('./fixtures/content-playback-audio.webm.base64', import.meta.url), 'utf8'), 'base64');
const runtime = await readFile(process.env.HORUS_VIDEO_RUNTIME_PATH || new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const emblemBytes = await readFile(new URL('../../public/assets/brand/horusmedia-emblem-header.png', import.meta.url));
const loader = applyPlacementPresetTransform(await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8'));

// Exercise the real loader + video DOM with a deterministic IMA boundary.
// No live auctions, impression pixels, credentials, or paid inventory are used.
async function openPlayer(page, options = {}) {
    const resourceRequests = { runtime: 0, content: 0 };
    await page.route('https://horusmedia.net/assets/images/horusmedia-emblem-header.png', route => route.fulfill({ contentType: 'image/png', body: emblemBytes }));
    await page.route('https://reader.example/**', route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/player.js') { resourceRequests.runtime++; return route.fulfill({ contentType: 'application/javascript', body: runtime }); }
        if (path === '/ad.js') return route.fulfill({ contentType: 'application/javascript', body: '' });
        if (path === '/broken-ad.mp4') return route.fulfill({ status: 404, body: '' });
        if (path === '/content.mp4' || path === '/content.webm') {
            resourceRequests.content++;
            if (!options.content) return route.fulfill({ status: 404, body: '' });
            const bytes = options.audibleContent ? audioContentBytes : contentBytes;
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range || '');
            const start = range ? Number(range[1]) : 0;
            const end = Math.min(bytes.length - 1, range && range[2] ? Number(range[2]) : bytes.length - 1);
            return route.fulfill({ status: range ? 206 : 200, contentType: options.audibleContent ? 'video/webm' : 'video/mp4',
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${bytes.length}` } : {}) },
                body: bytes.subarray(start, end + 1) });
        }
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
            *{box-sizing:border-box} html,body{margin:0;overflow-anchor:none;background:#f4f5f7;color:#172237;font:16px/1.7 system-ui,sans-serif}
            article{max-width:${options.narrowArticle ? 368 : options.articleWidth || 720}px;margin:0 auto;padding:0 24px;background:#fff;min-height:100vh}
            #before{height:${options.belowFold ? 1600 : 240}px;padding-top:32px} .section{color:#637085;font:700 11px/1.4 system-ui,sans-serif;letter-spacing:.15em;text-transform:uppercase}
            h1{font:650 30px/1.2 Georgia,serif;letter-spacing:-.025em;margin:12px 0} .byline{font-size:12px;color:#677489}
            [data-placement="video"]{width:${options.master?.[0] || 320}px;max-width:100%;min-height:180px;margin:24px auto}
            #tail{height:4000px;padding-top:24px} #tail p{margin:0 0 28px;color:#4f5c70} #tail h2{font:600 24px/1.3 Georgia,serif}
            ${options.hiddenArticle ? 'article{visibility:hidden}' : ''}
            ${options.transformed ? `article{transform:${options.transformed === 'scaled' ? 'scale(.75)' : 'translateZ(0)'};transform-origin:top center;contain:paint;overflow:hidden}` : ''}
            </style></head><body><article><div id="before"><div class="section">The daily reader / Field notes</div><h1>A quieter way to see the city</h1><div class="byline">September 30, 2026 · 5 minute read</div></div><div class="hm-ad" data-placement="video"></div><div id="tail">
            <p>Good stories make room for a closer look. Along familiar streets, small details reveal how a place changes through the day.</p><h2>Taking the long way home</h2>
            ${Array.from({ length: 12 }, (_, index) => `<p>Chapter ${index + 1}. The morning light reaches the old station first, then moves across the square. We follow the rhythm of the neighborhood, where each corner offers something worth noticing.</p>`).join('')}
            </div></article>
            <script id="loader" data-site-key="VIDEO_LAYOUT" data-config-version="1"></script></body></html>` });
    });
    await page.goto('https://reader.example/article');
    await page.evaluate(options => {
        window.__HM_DISABLE_AUTOBOOT__ = true;
        if (options.backgroundTab) {
            window.testVisibility = 'hidden';
            Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => window.testVisibility });
        }
        window.adRequests = 0; window.adStarts = 0; window.adDestroys = 0; window.imaFrameLoads = 0;
        window.displayInitializations = [];
        window.managerInits = 0; window.managerResizes = []; window.managerStops = 0; window.adClicks = 0; window.contentCompleteCalls = 0; window.videoManagers = []; window.videoLoaders = [];
        window.lastAdTagUrl = null; window.lastRenderingSettings = null;
        window.adRequestHistory = []; window.adStartStates = []; window.adStartedEvents = 0; window.managerVolumeWrites = [];
        window.contentPlayCalls = 0; window.contentPauseCalls = 0; window.contentLoadCalls = 0;
        const nativeLoad = HTMLMediaElement.prototype.load;
        HTMLMediaElement.prototype.load = function (...args) { window.contentLoadCalls++; return nativeLoad.apply(this, args); };
        // Count live viewport subscriptions, preserving normal browser listener
        // semantics (listener identity plus capture) so repeated floats cannot
        // silently accumulate resize/scroll handlers.
        const listenerSets = [];
        for (const target of [window, window.visualViewport].filter(Boolean)) {
            const live = new Map(), add = target.addEventListener, remove = target.removeEventListener;
            listenerSets.push(live);
            target.addEventListener = function (type, callback, options) {
                if (['scroll', 'resize', 'orientationchange'].includes(type)) {
                    const key = `${type}:${typeof options === 'boolean' ? options : !!options?.capture}`;
                    if (!live.has(key)) live.set(key, new Set());
                    live.get(key).add(callback);
                }
                return add.call(this, type, callback, options);
            };
            target.removeEventListener = function (type, callback, options) {
                const key = `${type}:${typeof options === 'boolean' ? options : !!options?.capture}`;
                live.get(key)?.delete(callback);
                return remove.call(this, type, callback, options);
            };
        }
        window.viewportListenerCount = () => listenerSets.reduce((total, live) => total + [...live.values()].reduce((sum, listeners) => sum + listeners.size, 0), 0);
        if (!options.realContent) {
            // Synthetic play() must agree with paused and the media events;
            // production intentionally ignores a fulfilled play on paused media.
            // Keep every real-content test on native playback and native state.
            const mediaStates = new WeakMap();
            const stateFor = media => {
                if (!mediaStates.has(media)) mediaStates.set(media, { paused: true, generation: 0 });
                return mediaStates.get(media);
            };
            Object.defineProperty(HTMLMediaElement.prototype, 'paused', {
                configurable: true, get() { return stateFor(this).paused; },
            });
            HTMLMediaElement.prototype.play = function () {
                window.contentPlayCalls++;
                const state = stateFor(this), wasPaused = state.paused;
                state.paused = false;
                const generation = state.generation;
                return Promise.resolve().then(() => {
                    if (!wasPaused || state.paused || generation !== state.generation) return;
                    this.dispatchEvent(new Event('play'));
                    if (!state.paused && generation === state.generation) this.dispatchEvent(new Event('playing'));
                });
            };
            HTMLMediaElement.prototype.pause = function () {
                window.contentPauseCalls++;
                const state = stateFor(this), wasPaused = state.paused;
                state.paused = true; state.generation++;
                if (!wasPaused) Promise.resolve().then(() => this.dispatchEvent(new Event('pause')));
            };
        }
        if (options.rejectContentAutoplay) {
            const nativePlay = HTMLMediaElement.prototype.play;
            HTMLMediaElement.prototype.play = function (...args) {
                if (!window.allowContentPlay) return Promise.reject(new DOMException('User activation required', 'NotAllowedError'));
                return nativePlay.apply(this, args);
            };
        }
        if (options.rejectUnmutedAutoplay) {
            const nativePlay = HTMLMediaElement.prototype.play;
            HTMLMediaElement.prototype.play = function (...args) {
                if (!this.muted) return Promise.reject(new DOMException('Sound requires user activation', 'NotAllowedError'));
                return nativePlay.apply(this, args);
            };
        }
        class Manager {
            constructor() {
                this.events = {};
                this.index = window.videoManagers.length;
                this.ad = { linear: !options.nonlinear, width: 300, height: 50, minSuggestedDuration: 0, ...(options.ads?.[window.videoManagers.length] || options.ad || {}) };
                window.videoManagers.push(this); window.videoManager = this;
            }
            addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
            getAd() { return this.adApi ||= { isLinear: () => this.ad.linear, getWidth: () => this.ad.width, getHeight: () => this.ad.height, getMinSuggestedDuration: () => this.ad.minSuggestedDuration, getDuration: () => 10 }; }
            emit(name, event = {}) { (this.events[name] || []).slice().forEach(fn => fn({ getAd: () => this.getAd(), ...event })); }
            discardAdBreak() { this.discardCalls = (this.discardCalls || 0) + 1; this.emit('content-resume'); }
            stop() { window.managerStops++; if (!options.noStopEvents) { this.emit('complete'); this.emit('all-completed'); } }
            getCuePoints() { return options.cuePoints || []; }
            restoreMediaAudio() {
                if (!options.restoreMediaAudio) return;
                const video = document.querySelector('video');
                video.muted = options.startMuted !== false; video.volume = 1;
            }
            init(width, height) {
                window.managerInits++; this.dimensions = [width, height]; this.restoreMediaAudio();
                if (!options.noPreload && !options.cuePoints?.length) this.emit('loaded');
            }
            getVolume() { return this.volume; }
            setVolume(value) {
                this.volume = value; window.managerVolumeWrites.push({ manager: this.index, volume: value });
                if (options.echoVolumeEvents) {
                    this.emit('volume-changed');
                    Promise.resolve().then(() => this.emit('volume-changed'));
                }
            }
            start() {
                window.adStarts++;
                const video = document.querySelector('video');
                window.adStartStates.push({ volume: this.volume, mediaMuted: video.muted, mediaVolume: video.volume });
                if (this.ad.linear) this.emit('content-pause');
                this.emit('loaded');
                const code = options.adStartErrors?.[this.index];
                if (code) { this.emit('ad-error', { getError: () => ({ getErrorCode: () => code }) }); return; }
                window.adStartedEvents++; this.emit('started');
            }
            resize(width, height) { window.managerResizes.push([width, height]); this.dimensions = [width, height]; }
            destroy() { window.adDestroys++; this.restoreMediaAudio(); if (options.pauseOnDestroy) document.querySelector('video')?.pause(); }
        }
        window.installIma = () => { window.google = { ima: {
            AdDisplayContainer: class {
                constructor(layer) {
                    const iframe = document.createElement('iframe');
                    iframe.title = 'Deterministic IMA advertisement fixture';
                    iframe.style.cssText = 'display:block;width:100%;height:100%;border:0';
                    iframe.srcdoc = '<html><head><style>*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden}body{display:grid;place-items:center;background:radial-gradient(ellipse at 75% 10%,#224b7a,transparent 65%),linear-gradient(150deg,#050b1e,#102b49);color:#f6f8ff;font:14px system-ui,sans-serif;text-align:center}small{display:block;margin-bottom:14px;color:#ffd66b;font-size:10px;letter-spacing:.18em}strong{font-weight:500;letter-spacing:.02em}span{display:block;margin-top:12px;color:#9da9c2;font-size:10px}</style></head><body><div><small>HORUS MEDIA</small><strong>Every story has a next chapter</strong><span>Local ad fixture · No live inventory</span></div></body></html>';
                    if (options.nonlinear) iframe.srcdoc = `<html><head><style>*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}#creative{position:absolute;bottom:12px;left:50%;transform:translateX(-50%);width:${options.ad?.width || 300}px;height:${options.ad?.height || 50}px;display:flex;align-items:center;justify-content:center;padding-right:30px;border:1px solid #eeb952;border-radius:5px;background:#112c49;color:white;font:12px system-ui,sans-serif;cursor:pointer}#close{position:absolute;right:3px;top:3px;width:24px;height:24px;border:0;border-radius:3px;background:#fff;color:#112c49;cursor:pointer}</style></head><body><div id="creative" role="link" tabindex="0" onclick="parent.adClicks++">Local nonlinear ad · Learn more<button id="close" aria-label="Close ad" onclick="event.stopPropagation();parent.videoManager.emit('user-close')">×</button></div></body></html>`;
                    iframe.dataset.testIma = '1'; iframe.addEventListener('load', () => window.imaFrameLoads++);
                    layer.appendChild(iframe); this.frame = iframe;
                }
                initialize() { window.displayInitializations.push(navigator.userActivation?.isActive === true); }
                destroy() { this.frame.remove(); }
            },
            AdsLoader: class {
                constructor() { this.events = {}; window.videoLoaders.push(this); }
                addEventListener(name, callback) { this.events[name] = callback; }
                requestAds(request) {
                    window.adRequests++;
                    window.lastAdTagUrl = request.adTagUrl || null;
                    window.lastAdPlaybackIntent = { autoPlay: request.willAutoPlay, muted: request.willPlayMuted };
                    const video = document.querySelector('video');
                    // Snapshot what was actually submitted; later mute changes
                    // must not rewrite an earlier request's signaling history.
                    window.adRequestHistory.push({ tag: request.adTagUrl, autoPlay: request.willAutoPlay, muted: request.willPlayMuted,
                        mediaMuted: video.muted, mediaVolume: video.volume, readyState: video.readyState });
                    window.lastAdDimensions = [request.linearAdSlotWidth, request.linearAdSlotHeight];
                    window.lastNonlinearDimensions = [request.nonLinearAdSlotWidth, request.nonLinearAdSlotHeight];
                    this.resolve = () => this.events.manager({ getAdsManager: (_video, settings) => {
                        window.lastRenderingSettings = {
                            enablePreloading: settings.enablePreloading,
                            loadVideoTimeout: settings.loadVideoTimeout,
                            prerollLoadVideoTimeout: settings.prerollLoadVideoTimeout,
                        };
                        return new Manager();
                    } });
                    if (!options.deferResponse) this.resolve();
                }
                destroy() {}
                contentComplete() { window.contentCompleteCalls++; }
            },
            AdsRequest: class { setAdWillAutoPlay(value) { this.willAutoPlay = value; } setAdWillPlayMuted(value) { this.willPlayMuted = value; } setContinuousPlayback() {} },
            AdsRenderingSettings: class {}, AdsManagerLoadedEvent: { Type: { ADS_MANAGER_LOADED: 'manager' } },
            AdErrorEvent: { Type: { AD_ERROR: 'ad-error' } }, ViewMode: { NORMAL: 'normal' },
            AdEvent: { Type: { LOADED: 'loaded', STARTED: 'started', COMPLETE: 'complete', SKIPPED: 'skipped', ALL_ADS_COMPLETED: 'all-completed', CONTENT_PAUSE_REQUESTED: 'content-pause', CONTENT_RESUME_REQUESTED: 'content-resume', LINEAR_CHANGED: 'linear-changed', USER_CLOSE: 'user-close', VOLUME_CHANGED: 'volume-changed', VOLUME_MUTED: 'volume-muted' } },
        } }; };
        if (options.noObserver) window.IntersectionObserver = undefined;
        if (options.delayedSdk) {
            const script = document.createElement('script'); script.dataset.hmImaSdk = '1'; document.head.appendChild(script);
        } else window.installIma();
        const attributes = {
            'data-hm-video-direct': '1', 'data-hm-video-width': String(options.master?.[0] || 320), 'data-hm-video-height': String(options.master?.[1] || 180),
            'data-hm-vast-url': btoa(options.vastUrl || 'https://ads.example/vast'),
        };
        // Deliberately omit the child floating attribute: cached pre-fix recipes
        // must still work through the loader's authoritative placement metadata.
        if (options.content) attributes['data-hm-video-content-url'] = 'https://reader.example/content.' + (options.audibleContent ? 'webm' : 'mp4');
        if (options.fixedVideo) {
            attributes['data-hm-video-fixed-instream'] = '1';
            attributes['data-hm-video-break-schedule'] = 'interval';
            attributes['data-hm-video-mid-roll-interval-seconds'] = '5';
        }
        if (options.contentMode) attributes['data-hm-video-content-mode'] = options.contentMode;
        if (options.startMuted === false) attributes['data-hm-video-muted'] = '0';
        if (options.startAutoplay === false) attributes['data-hm-video-autoplay'] = '0';
        if (options.adFormat) attributes['data-hm-video-ad-format'] = options.adFormat;
        const videoSettings = { autoMount: false, position: options.alwaysFloating ? 'bottom_right' : options.inlineOnly ? 'inline' : 'inline_to_bottom_right', floatingPosition: options.inlineOnly ? null : 'bottom_right', closeable: true, closeOutside: true };
        const placements = [{ code: 'video', type: 'VIDEO', format: { settings: videoSettings } }];
        const directPlacements = { video: { enabled: true, candidates: [{ network: 'TEST', tag: {
            executionMode: 'STRUCTURED', scripts: [{ url: 'https://reader.example/player.js' }],
            container: { element: 'div', id: 'video-runtime', attributes }, initialization: { type: 'NONE' },
            render: { timeoutMs: 20000, successSelector: '[data-hm-video-status="started"], [data-hm-video-status="content-ready"]', assumeLoadedIsSuccess: false },
        } }] } };
        if (options.sticky) {
            placements.push({ code: 'bottom', type: 'STICKY', format: { settings: { autoMount: true, position: 'bottom', closeable: true } } });
            directPlacements.bottom = { enabled: true, candidates: [{ network: 'TEST', tag: { scripts: [{ url: 'https://reader.example/ad.js' }], initialization: { type: 'NONE' }, assumeLoadedIsSuccess: true } }] };
        }
        const config = {
            siteKey: 'VIDEO_LAYOUT', configVersion: 1, status: 'active', allowedHostnames: ['reader.example'],
            controls: { gamDisabled: true, prebidDisabled: true },
            placements: placements.map(p => ({ ...p, enabled: true, status: 'active', renderer: 'DIRECT_JS', sizes: [p.type === 'VIDEO' ? (options.master || [320, 180]) : [320, 50]] })),
            directDemand: { enabled: true, placements: directPlacements },
        };
        window.fetch = async () => ({ ok: true, json: async () => config });
    }, options);
    if (options.primeUserActivation) await page.getByRole('heading', { name: 'A quieter way to see the city' }).click();
    await page.addScriptTag({ content: loader });
    // boot returns a promise that may wait for IMA. Start, but do not block the
    // delayed-SDK test's scroll on that render promise.
    await page.evaluate(() => { window.HorusMediaLoader.boot({ script: document.getElementById('loader') }); });
    await expect(page.locator('[data-hm-video-direct]')).toHaveAttribute('data-hm-video-runtime-state', /.+/);
    if (options.sticky) {
        await expect(page.locator('[data-placement="bottom"]')).toHaveAttribute('data-hm-status', 'rendered');
        await page.locator('[data-placement="bottom"]').evaluate(el => { el.style.width = '100vw'; el.style.height = '90px'; });
    }
    return resourceRequests;
}

async function assertFloating(page) {
    const surface = page.locator('[data-placement="video"]');
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
    await expect(surface).not.toHaveAttribute('data-hm-video-motion', /.+/);
    await expect.poll(async () => surface.evaluate(el => {
        const r = el.getBoundingClientRect();
        return getComputedStyle(el).position === 'fixed' && r.top >= 0 && r.bottom <= innerHeight && r.width > 0 && r.height > 0;
    })).toBe(true);
    if (await page.evaluate(() => window.adStarts > 0)) {
    const close = surface.locator('[data-hm-placement-close]');
    await expect(close).toBeVisible();
    await expect.poll(() => close.evaluate(button => {
        const rect = button.getBoundingClientRect(), media = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
        const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
        return rect.width >= 44 && rect.height >= 44 && rect.bottom <= media.top + 1 && (hit === button || button.contains(hit));
    })).toBe(true);
    }
    return surface;
}

// Scroll and allow both the scroll handler and its next-frame layout write to
// settle. In particular, a negative assertion must not pass before scrolling.
async function scrollPage(page, y) {
    await page.evaluate(y => new Promise(resolve => {
        window.scrollTo(0, y);
        requestAnimationFrame(() => requestAnimationFrame(resolve));
    }), y);
}

async function inlineGeometry(page) {
    return page.locator('[data-placement="video"]').evaluate(surface => {
        const box = surface.getBoundingClientRect(), tail = document.getElementById('tail').getBoundingClientRect();
        const css = getComputedStyle(surface);
        return {
            x: box.x + scrollX, y: box.y + scrollY, width: box.width, height: box.height,
            tailY: tail.y + scrollY,
            styles: Object.fromEntries(['position', 'top', 'right', 'bottom', 'left', 'boxShadow', 'zIndex'].map(key => [key, css[key]])),
        };
    });
}

async function rememberPlayingAd(page) {
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    await expect.poll(() => page.evaluate(() => window.imaFrameLoads)).toBe(1);
    await page.evaluate(() => {
        const surface = document.querySelector('[data-placement="video"]');
        const runtime = document.querySelector('[data-hm-video-direct]');
        const iframe = document.querySelector('[data-test-ima]');
        window.originalPlayer = {
            surface, parent: surface.parentNode, runtime, player: runtime.__hmVideoPlayer,
            video: document.querySelector('video'), layer: document.querySelector('[data-hm-video-ad-layer]'),
            iframe, iframeDocument: iframe.contentDocument, manager: window.videoManager, managerInits: window.managerInits,
            playCalls: window.contentPlayCalls, pauseCalls: window.contentPauseCalls, loadCalls: window.contentLoadCalls,
            src: document.querySelector('video').getAttribute('src'), sourceMutations: 0,
        };
        new MutationObserver(records => { window.originalPlayer.sourceMutations += records.length; })
            .observe(window.originalPlayer.video, { attributes: true, attributeFilter: ['src'] });
    });
}

async function expectSamePlayingAd(page) {
    await expect.poll(() => page.evaluate(() => {
        const saved = window.originalPlayer, runtime = document.querySelector('[data-hm-video-direct]');
        return {
            sameSurface: document.querySelector('[data-placement="video"]') === saved.surface,
            sameParent: saved.surface.parentNode === saved.parent,
            sameRuntime: runtime === saved.runtime && runtime.__hmVideoPlayer === saved.player,
            sameVideo: document.querySelector('video') === saved.video,
            sameSource: saved.video.getAttribute('src') === saved.src, sourceMutations: saved.sourceMutations,
            sameLayer: document.querySelector('[data-hm-video-ad-layer]') === saved.layer,
            sameIframe: document.querySelector('[data-test-ima]') === saved.iframe && saved.iframe.contentDocument === saved.iframeDocument,
            sameManager: window.videoManager === saved.manager, additionalInits: window.managerInits - saved.managerInits,
            requests: window.adRequests, starts: window.adStarts, destroys: window.adDestroys, iframeLoads: window.imaFrameLoads,
            additionalPlays: window.contentPlayCalls - saved.playCalls, additionalPauses: window.contentPauseCalls - saved.pauseCalls,
            additionalLoads: window.contentLoadCalls - saved.loadCalls,
            surfaces: document.querySelectorAll('[data-placement="video"]').length,
            runtimes: document.querySelectorAll('[data-hm-video-direct]').length,
            videos: document.querySelectorAll('video').length, iframes: document.querySelectorAll('[data-test-ima]').length,
        };
    })).toEqual({
        sameSurface: true, sameParent: true, sameRuntime: true, sameVideo: true, sameSource: true, sourceMutations: 0, sameLayer: true, sameIframe: true, sameManager: true, additionalInits: 0,
        requests: 1, starts: 1, destroys: 0, iframeLoads: 1, additionalPlays: 0, additionalPauses: 0, additionalLoads: 0,
        surfaces: 1, runtimes: 1, videos: 1, iframes: 1,
    });
}

async function expectInline(page, original = null) {
    const surface = page.locator('[data-placement="video"]');
    await expect(surface).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
    await expect(surface).not.toHaveAttribute('data-hm-floating-video-active', '1');
    await expect(surface).not.toHaveAttribute('data-hm-video-motion', /.+/);
    await expect(surface).toBeVisible();
    if (original) {
        // Comparing the article below the player catches an orphan placeholder,
        // collapsed reserved slot, doubled margins, and accumulated layout drift.
        await expect.poll(async () => {
            const current = await inlineGeometry(page);
            return ['x', 'y', 'width', 'height', 'tailY'].every(key => Math.abs(current[key] - original[key]) < 1);
        }).toBe(true);
        if (!original.styles) return surface;
        const current = await inlineGeometry(page);
        // A portal's top/left track the anchor in viewport coordinates on every
        // scroll; its document coordinates were checked above.
        if (await surface.getAttribute('data-hm-video-portal') === '1') {
            current.styles.top = original.styles.top;
            current.styles.left = original.styles.left;
            // CSSOM resolves bottom:auto to a viewport-relative used value.
            // Verify the declaration was cleared; document geometry above is
            // the authoritative return-position assertion.
            expect(await surface.evaluate(el => el.style.bottom)).toBe('auto');
            current.styles.bottom = original.styles.bottom;
        }
        expect(current.styles).toEqual(original.styles);
    }
    return surface;
}

async function attachLayout(page, testInfo, state) {
    const viewport = page.viewportSize();
    const name = `video-player-${state}-${viewport.width}x${viewport.height}`;
    const path = testInfo.outputPath(`${name}.png`);
    await page.screenshot({ path, animations: 'disabled', fullPage: false });
    await testInfo.attach(name, { path, contentType: 'image/png' });
}

for (const options of [{}, { content: true }, { noObserver: true }, { transformed: true }, { transformed: true, content: true }, { transformed: 'scaled' }]) {
    test(`repeated inline ↔ floating cycles preserve the ad, iframe, and original slot: ${JSON.stringify(options)}`, async ({ page }, testInfo) => {
        const requests = await openPlayer(page, options);
        await rememberPlayingAd(page);
        const original = await inlineGeometry(page);
        await expectEnlargedInlineGeometry(page, [320, 180]);
        await expectMediaRatioAndManagerSize(page, [320, 180]);
        const resourceCounts = { ...requests };
        if (options.transformed) {
            await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-portal', '1');
            expect(await page.locator('[data-placement="video"]').evaluate(el => el.parentNode === document.body)).toBe(true);
        }
        if (Object.keys(options).length === 0) await attachLayout(page, testInfo, 'inline-before-scroll');

        let firstFloatingListenerCount;
        for (let cycle = 0; cycle < 3; cycle++) {
            await scrollPage(page, original.y + original.height + 80);
            await assertFloating(page);
            await expectCompactFloatingGeometry(page, [320, 180]);
            await expectMediaRatioAndManagerSize(page, [320, 180]);
            await expectSamePlayingAd(page);
            const listenerCount = await page.evaluate(() => window.viewportListenerCount());
            if (cycle === 0) firstFloatingListenerCount = listenerCount;
            expect(listenerCount).toBeLessThanOrEqual(firstFloatingListenerCount);
            // Browsers may range-fetch/retry media independently of scroll.
            // Source mutations, decoder state and ad requests are asserted separately.
            expect(requests.runtime).toBe(resourceCounts.runtime);
            const tailY = await page.locator('#tail').evaluate(el => el.getBoundingClientRect().top + scrollY);
            expect(Math.abs(tailY - original.tailY)).toBeLessThan(1);
            if (cycle === 0 && Object.keys(options).length === 0) await attachLayout(page, testInfo, 'floating-after-scroll');

            // Restore on the first visible slice of the ORIGINAL slot, without
            // requiring the reader to return all the way to the top of the page.
            await scrollPage(page, original.y + original.height - 24);
            await expectInline(page, original);
            await expectSamePlayingAd(page);
            await scrollPage(page, 0);
            await expectInline(page, original);
            if (cycle === 0 && Object.keys(options).length === 0) await attachLayout(page, testInfo, 'inline-returned');
        }

        await scrollPage(page, original.y + original.height + 80);
        const surface = await assertFloating(page);
        await surface.locator('[data-hm-placement-close]').click();
        await expect(surface).toBeHidden();
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
        await scrollPage(page, 0);
        await scrollPage(page, 1800);
        await expect(surface).toBeHidden();
        expect(await page.evaluate(() => window.adRequests)).toBe(1);
        expect(await page.evaluate(() => window.adStarts)).toBe(1);
        // Native byte-range requests do not indicate an application restart.
        expect(requests.runtime).toBe(resourceCounts.runtime);
        expect(await page.evaluate(() => window.viewportListenerCount())).toBeLessThan(firstFloatingListenerCount);
    });
}

test('GAM preserves a manual multi-size target while IMA receives the enlarged actual player size', async ({ page }) => {
    const original = 'https://pubads.g.doubleclick.net/gampad/ads?iu=/23055873217/video-bluekl.com&env=vp&gdfp_req=1&output=vast&sz=300x250%7C640x480&url=https%3A%2F%2Fold.example%2Fpage&correlator=';
    await openPlayer(page, { content: true, vastUrl: original });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);

    const result = await page.evaluate(() => ({
        tag: window.lastAdTagUrl,
        settings: window.lastRenderingSettings,
        requestSize: window.lastAdDimensions,
        measuredSize: (() => { const box = document.querySelector('[data-hm-video-direct]').getBoundingClientRect(); return [Math.round(box.width), Math.round(box.height)]; })(),
    }));
    const tag = new URL(result.tag);

    await expectEnlargedInlineGeometry(page, [320, 180]);
    expect(result.requestSize).toEqual(result.measuredSize);
    expect(tag.searchParams.get('sz')).toBe('300x250|640x480');
    expect(tag.searchParams.get('url')).toBe('https://reader.example/article');
    expect(tag.searchParams.get('description_url')).toBe('https://reader.example/article');
    expect(tag.searchParams.get('plcmt')).toBe('2');
    expect(tag.searchParams.get('vpos')).toBe('preroll');
    expect(result.settings).toEqual({
        enablePreloading: true,
        loadVideoTimeout: 12000,
        prerollLoadVideoTimeout: 12000,
    });
    await rememberPlayingAd(page);
    await scrollPage(page, 1800);
    await assertFloating(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await expectCompactFloatingGeometry(page, [320, 180]);
    await scrollPage(page, 0);
    await expectEnlargedInlineGeometry(page, [320, 180]);
    await expectSamePlayingAd(page);
    expect(await page.evaluate(() => window.lastAdTagUrl)).toBe(result.tag);
});

test('records scroll while SDK is delayed, stays inline without an ad, then floats on a filled response', async ({ page }) => {
    await openPlayer(page, { delayedSdk: true, vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=300x250%7C640x480' });
    await expectEnlargedInlineGeometry(page, [320, 180]);
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate(el => el.__hmVideoPlayer.wasInlineVisible)).toBe(true);
    await scrollPage(page, 1800);
    await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'inline');
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await page.evaluate(() => { window.installIma(); document.querySelector('[data-hm-ima-sdk]').dispatchEvent(new Event('load')); });
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    await expectCompactFloatingGeometry(page, [320, 180]);
    const request = await page.evaluate(() => {
        const box = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
        return { tag: window.lastAdTagUrl, size: window.lastAdDimensions, actual: [Math.round(box.width), Math.round(box.height)] };
    });
    // The response was requested while inline; IMA is resized only after an
    // actual ad is available and the compact surface becomes visible.
    expect(request.size[0]).toBeGreaterThanOrEqual(request.actual[0]);
    expect(new URL(request.tag).searchParams.get('sz')).toBe('300x250|640x480');
    expect(request.actual[0]).toBeLessThanOrEqual(320);
});

test('inline-only inventory never floats on scroll or viewport resize', async ({ page }) => {
    await openPlayer(page, { inlineOnly: true, content: true });
    await rememberPlayingAd(page);
    await expectEnlargedInlineGeometry(page, [320, 180]);
    await scrollPage(page, 1800);
    await expect(page.locator('[data-placement="video"]')).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
    await page.setViewportSize({ width: 375, height: 812 });
    await scrollPage(page, 2200);
    await expect(page.locator('[data-placement="video"]')).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
    await scrollPage(page, 0);
    await expectEnlargedInlineGeometry(page, [320, 180]);
    await expectSamePlayingAd(page);
});

for (const options of [{}, { noObserver: true }, { transformed: true }]) {
    test(`a below-fold slot floats only after being seen and passed above the viewport: ${JSON.stringify(options)}`, async ({ page }) => {
        await openPlayer(page, { ...options, belowFold: true });
        const surface = page.locator('[data-placement="video"]');
        const slot = await inlineGeometry(page);
        await scrollPage(page, 0);
        await expect(surface).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.adRequests)).toBe(0);

        // Jump over the placement entirely: crossing its document coordinate is
        // not proof that it was ever visible, and must not start an auction.
        await scrollPage(page, slot.y + slot.height + 800);
        await expect(surface).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.adRequests)).toBe(0);

        await scrollPage(page, slot.y - 80);
        await rememberPlayingAd(page);
        await scrollPage(page, 0);
        await expect(surface).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
        await expectSamePlayingAd(page);

        await scrollPage(page, slot.y + slot.height + 80);
        await assertFloating(page);
        await scrollPage(page, slot.y + slot.height - 24);
        await expectInline(page, slot);
        await expectSamePlayingAd(page);
    });
}

test('sticky clearance is removed inline and recalculated on every later float', async ({ page }) => {
    await openPlayer(page, { sticky: true, transformed: true });
    await rememberPlayingAd(page);
    const original = await inlineGeometry(page);
    const bottom = page.locator('[data-placement="bottom"]');
    await scrollPage(page, 1800);
    const video = await assertFloating(page);
    const gap = async () => { const a = await bottom.boundingBox(), b = await video.boundingBox(); return a.y - b.y - b.height; };
    await expect.poll(gap).toBeGreaterThanOrEqual(15);
    await bottom.evaluate(el => { el.style.height = '130px'; });
    await expect.poll(gap).toBeGreaterThanOrEqual(15);
    await expectSamePlayingAd(page);

    await scrollPage(page, 0);
    await expectInline(page, original);
    await bottom.evaluate(el => { el.style.height = '70px'; });
    await expectInline(page, original);
    await scrollPage(page, 1800);
    await assertFloating(page);
    await expect.poll(gap).toBeGreaterThanOrEqual(15);
    await bottom.locator('[data-hm-placement-close]').click();
    await expect(bottom).toBeHidden();
    await expect.poll(() => video.evaluate(el => parseFloat(getComputedStyle(el).bottom))).toBe(16);
    await scrollPage(page, 0);
    await expectInline(page, original);
    await scrollPage(page, 1800);
    await assertFloating(page);
    await expect.poll(() => video.evaluate(el => parseFloat(getComputedStyle(el).bottom))).toBe(16);
    await expectSamePlayingAd(page);
    await video.locator('[data-hm-placement-close]').click();
    await expect(video).toBeHidden();
    await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
});

for (const transformed of [false, true]) {
    test(`closing the returned inline player is permanent (portal=${transformed})`, async ({ page }) => {
        await openPlayer(page, { transformed });
        await rememberPlayingAd(page);
        const original = await inlineGeometry(page);
        await scrollPage(page, 1800);
        await assertFloating(page);
        await scrollPage(page, 0);
        const surface = await expectInline(page, original);
        await surface.locator('[data-hm-placement-close]').click();
        await expect(surface).toBeHidden();
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
        await page.setViewportSize({ width: 375, height: 812 });
        await scrollPage(page, 1800);
        await scrollPage(page, 0);
        await expect(surface).toBeHidden();
        expect(await page.evaluate(() => ({ requests: window.adRequests, starts: window.adStarts, loads: window.imaFrameLoads })))
            .toEqual({ requests: 1, starts: 1, loads: 1 });
    });
}

async function expectDecodedContent(page) {
    await expect.poll(() => page.locator('video').evaluate(video => ({
        frame: video.videoWidth > 0 && video.videoHeight > 0 && video.readyState >= 2,
        playing: !video.paused && video.currentTime > 0.25,
    }))).toEqual({ frame: true, playing: true });
    // Verify decoded pixels, not only a successful play() promise or black box.
    const brightness = await page.locator('video').evaluate(video => {
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d'); context.drawImage(video, 0, 0, 1, 1);
        const pixel = context.getImageData(0, 0, 1, 1).data;
        return pixel[0] + pixel[1] + pixel[2];
    });
    expect(brightness).toBeGreaterThan(60);
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
}

test('real decoded content stays inline without ads and keeps its source and playback time during scroll', async ({ page }, testInfo) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, transformed: true });
    await page.evaluate(() => window.videoManager.emit('ad-error'));
    await expectDecodedContent(page);
    const original = await inlineGeometry(page);
    await page.evaluate(() => {
        const video = document.querySelector('video');
        window.contentIdentity = { video, src: video.currentSrc, resets: 0, seeks: 0 };
        video.addEventListener('emptied', () => window.contentIdentity.resets++);
        video.addEventListener('seeking', () => window.contentIdentity.seeks++);
    });
    await attachLayout(page, testInfo, 'decoded-content-inline');
    for (let cycle = 0; cycle < 2; cycle++) {
        let previousTime = await page.locator('video').evaluate(video => video.currentTime);
        await scrollPage(page, 1400);
        await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'inline');
        // WebKit may suspend offscreen media. The new inline-only content
        // contract preserves its time/source, without forcing hidden playback.
        expect(await page.locator('video').evaluate(video => video.currentTime)).toBeGreaterThanOrEqual(previousTime - 0.05);
        if (cycle === 0) await attachLayout(page, testInfo, 'decoded-content-scrolled-inline');
        previousTime = await page.locator('video').evaluate(video => video.currentTime);
        await scrollPage(page, 0);
        await expectInline(page, original);
        if (await page.locator('video').evaluate(video => video.paused)) await page.locator('[data-hm-video-content-control="play"]').click();
        await expect.poll(() => page.locator('video').evaluate(video => video.currentTime)).toBeGreaterThan(previousTime + 0.15);
        await expectDecodedContent(page);
        expect(await page.evaluate(() => {
            const saved = window.contentIdentity, video = document.querySelector('video');
            return { sameVideo: saved.video === video, sameSource: saved.src === video.currentSrc, resets: saved.resets, seeks: saved.seeks, requests: window.adRequests };
        })).toEqual({ sameVideo: true, sameSource: true, resets: 0, seeks: 0, requests: 1 });
        await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'none');
    }
    await attachLayout(page, testInfo, 'decoded-content-returned');
});

test('a native media error belonging to the shared ad element does not close healthy content', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true });
    await page.evaluate(async () => {
        const video = document.querySelector('video');
        const error = new Promise(resolve => video.addEventListener('error', resolve, { once: true }));
        video.src = 'https://reader.example/broken-ad.mp4';
        video.load();
        await error;
        // Match the SDK returning ownership/restoring content after ad failure.
        video.src = 'https://reader.example/content.mp4';
        window.videoManager.emit('ad-error');
    });
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('native play interruption during IMA teardown cannot discard a newer playing video', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, pauseOnDestroy: true });
    await page.evaluate(() => {
        window.videoManager.emit('content-resume');
        window.videoManager.emit('all-completed');
    });
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('autoplay refusal leaves native controls available and a playback gesture never re-auctions', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true });
    await page.evaluate(() => {
        const video = document.querySelector('video');
        video.play = () => Promise.reject(new DOMException('User activation required', 'NotAllowedError'));
        window.videoManager.emit('ad-error');
    });
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-detail', 'user-activation-required');
    expect(await page.locator('video').evaluate(video => video.controls)).toBe(true);
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'none');
    await page.evaluate(() => {
        const video = document.querySelector('video');
        delete video.play;
        // Fixture-owned gesture, with the browser's original native play().
        const button = document.createElement('button'); button.id = 'fixture-play'; button.textContent = 'Play fixture';
        button.onclick = () => video.play(); document.body.prepend(button);
    });
    await page.locator('#fixture-play').click();
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

// The publisher article defines the available inline space; selected master
// dimensions select an aspect ratio and compact size, not the inline width.
async function expectEnlargedInlineGeometry(page, master) {
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate((media, master) => {
        const article = document.querySelector('article'), articleBox = article.getBoundingClientRect();
        const css = getComputedStyle(article), scale = articleBox.width / article.offsetWidth;
        const available = article.clientWidth - parseFloat(css.paddingLeft) - parseFloat(css.paddingRight);
        const expectedWidth = Math.min(available, Math.max(640, master[0])) * scale;
        const box = media.getBoundingClientRect(), surface = document.querySelector('[data-placement="video"]').getBoundingClientRect();
        return Math.abs(box.width - expectedWidth) < 1
            && Math.abs(box.height - expectedWidth * master[1] / master[0]) < 1
            && Math.abs(surface.width - box.width) < 1
            && [...media.querySelectorAll('video, [data-hm-video-ad-layer], [data-test-ima]')].every(child => {
                const childBox = child.getBoundingClientRect();
                return Math.abs(childBox.width - box.width) < 1 && Math.abs(childBox.height - box.height) < 1;
            })
            && Math.abs(surface.height - box.height - 44 * scale) < 1
            && Math.abs((box.left + box.right) / 2 - (articleBox.left + articleBox.right) / 2) < 1
            && box.left >= articleBox.left && box.right <= articleBox.right
            && document.documentElement.scrollWidth <= innerWidth;
    }, master)).toBe(true);
}

// Assert the existing compact sizing contract independently of the runtime's
// chosen CSS width: selected master, viewport gutters and sticky height only.
async function expectCompactFloatingGeometry(page, master) {
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate((media, master) => {
        const viewportWidth = Math.min(innerWidth, window.visualViewport?.width || innerWidth);
        const viewportHeight = Math.min(innerHeight, window.visualViewport?.height || innerHeight);
        const sticky = document.querySelector('[data-placement="bottom"]');
        const stickyBox = sticky?.getBoundingClientRect();
        const occupied = stickyBox?.height && getComputedStyle(sticky).display !== 'none' ? Math.max(0, viewportHeight - stickyBox.top) : 0;
        const bottom = (occupied > 0 ? Math.ceil(occupied) : 0) + 16;
        const availableMediaHeight = Math.max(1, viewportHeight - bottom - 16 - 44);
        const expectedWidth = Math.max(1, Math.min(master[0], viewportWidth - 32, Math.floor(availableMediaHeight * master[0] / master[1])));
        const box = media.getBoundingClientRect();
        return Math.abs(box.width - expectedWidth) < 1 && Math.abs(box.height - expectedWidth * master[1] / master[0]) < 1;
    }, master)).toBe(true);
}

async function expectMediaRatioAndManagerSize(page, master) {
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate((media, master) => {
        const box = media.getBoundingClientRect(), player = media.__hmVideoPlayer;
        const managerBox = player.viewport?.portal && !player.floating ? { width: media.clientWidth, height: media.clientHeight } : box;
        return box.width > 0 && box.height > 0 && Math.abs(box.width / box.height - master[0] / master[1]) < 0.02
            && window.videoManager.dimensions[0] === Math.round(managerBox.width) && window.videoManager.dimensions[1] === Math.round(managerBox.height);
    }, master)).toBe(true);
}

for (const master of [[300,250],[320,180],[336,280],[400,225],[400,300],[640,480]]) {
    test(`master ${master.join('x')} preserves GAM/IMA dimensions through desktop, mobile, and landscape roundtrips`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await openPlayer(page, { master, sticky: true, vastUrl: `https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=${master.join('x')}` });
        await rememberPlayingAd(page);
        await expectEnlargedInlineGeometry(page, master);
        const box = await page.locator('[data-hm-video-direct]').boundingBox();
        if (master[0] < 640) expect(box.width).toBeGreaterThan(master[0]);
        else expect(box.width).toBeCloseTo(master[0], 2);
        const request = await page.evaluate(() => ({ tag: window.lastAdTagUrl, size: window.lastAdDimensions }));
        expect(request.size).toEqual([Math.round(box.width), Math.round(box.height)]);
        expect(new URL(request.tag).searchParams.get('sz')).toBe(master.join('x'));
        expect(new URL(request.tag).searchParams.get('vad_type')).toBe('linear');
        await scrollPage(page, 1800);
        await assertFloating(page);
        await expectMediaRatioAndManagerSize(page, master);
        await expectCompactFloatingGeometry(page, master);

        for (const viewport of [{ width: 375, height: 812 }, { width: 320, height: 640 }, { width: 280, height: 640 }, { width: 844, height: 390 }]) {
            await page.setViewportSize(viewport);
            const surface = await assertFloating(page);
            await expectMediaRatioAndManagerSize(page, master);
            await expectCompactFloatingGeometry(page, master);
            await expect.poll(async () => {
                const sticky = await page.locator('[data-placement="bottom"]').boundingBox(), floating = await surface.boundingBox();
                return sticky.y - floating.y - floating.height;
            }).toBeGreaterThanOrEqual(15);
            await expect.poll(() => surface.evaluate(el => {
                const r = el.getBoundingClientRect();
                return r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight;
            })).toBe(true);
            if (master[0] === 640 && viewport.width === 375) await attachLayout(page, testInfo, '640x480-mobile-floating-sticky');

            const reserved = await page.locator('[data-hm-video-placeholder]').evaluate(anchor => {
                const box = anchor.getBoundingClientRect();
                return { x: box.x + scrollX, y: box.y + scrollY, width: box.width, height: box.height,
                    tailY: document.getElementById('tail').getBoundingClientRect().top + scrollY };
            });
            await scrollPage(page, 0);
            await expectInline(page, reserved);
            await expectEnlargedInlineGeometry(page, master);
            await expectMediaRatioAndManagerSize(page, master);
            const inlineBox = await page.locator('[data-hm-video-direct]').boundingBox();
            if (Math.min(640, viewport.width - 48) > master[0]) expect(inlineBox.width).toBeGreaterThan(master[0]);
            expect(await surface.evaluate(el => {
                const box = el.getBoundingClientRect(), article = document.querySelector('article').getBoundingClientRect();
                return Math.abs((box.left + box.right) / 2 - (article.left + article.right) / 2) < 1
                    && box.left >= article.left && box.right <= article.right && document.documentElement.scrollWidth <= innerWidth;
            })).toBe(true);
            await expectSamePlayingAd(page);
            if (master[0] === 640 && viewport.width === 375) await attachLayout(page, testInfo, '640x480-mobile-inline-returned');
            await scrollPage(page, 1800);
            await assertFloating(page);
        }
        await page.setViewportSize({ width: 1280, height: 900 });
        await scrollPage(page, 0);
        await expectInline(page);
        const restored = await page.locator('[data-hm-video-direct]').boundingBox();
        await expectEnlargedInlineGeometry(page, master);
        expect(Math.abs(restored.width - 640)).toBeLessThan(1);
        expect(Math.abs(restored.height - 640 * master[1] / master[0])).toBeLessThan(1);
        expect(await page.evaluate(() => window.managerResizes.length)).toBeGreaterThan(0);
        await expectSamePlayingAd(page);
    });
}

for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 812 }]) {
    test(`production 400x225 has truthful inline and compact floating measurements at ${viewport.width}px`, async ({ page }, testInfo) => {
        const master = [400, 225], availableInlineWidth = Math.min(720, viewport.width) - 48;
        const expectedInlineWidth = Math.min(640, availableInlineWidth);
        // This is the former sizing rule calculated for the same article. It is
        // deliberately not presented as a screenshot or execution of old code.
        const previousInlineWidth = Math.min(master[0], availableInlineWidth);
        await page.setViewportSize(viewport);
        await openPlayer(page, { master, content: true,
            vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=400x225' });
        await rememberPlayingAd(page);
        await expectEnlargedInlineGeometry(page, master);
        await expectMediaRatioAndManagerSize(page, master);
        const original = await inlineGeometry(page);
        const inline = await page.locator('[data-hm-video-direct]').boundingBox();
        expect(inline.width).toBeCloseTo(expectedInlineWidth, 2);
        expect(inline.height).toBeCloseTo(expectedInlineWidth * 9 / 16, 2);
        if (viewport.width === 1280) expect(inline.width).toBeGreaterThan(previousInlineWidth);
        else expect(inline.width).toBeCloseTo(previousInlineWidth, 2);
        await attachLayout(page, testInfo, 'production400-inline-before-scroll');

        await scrollPage(page, original.y + original.height + 80);
        await assertFloating(page);
        await expectCompactFloatingGeometry(page, master);
        await expectMediaRatioAndManagerSize(page, master);
        const floating = await page.locator('[data-hm-video-direct]').boundingBox();
        expect(floating.width).toBeCloseTo(Math.min(400, viewport.width - 32), 2);
        expect(floating.height).toBeCloseTo(floating.width * 9 / 16, 2);
        await expectSamePlayingAd(page);
        await attachLayout(page, testInfo, 'production400-floating-after-scroll');

        await scrollPage(page, 0);
        await expectInline(page, original);
        await expectEnlargedInlineGeometry(page, master);
        await expectMediaRatioAndManagerSize(page, master);
        await expectSamePlayingAd(page);
        const returned = await page.locator('[data-hm-video-direct]').boundingBox();
        await attachLayout(page, testInfo, 'production400-inline-returned');
        const dimensions = box => ({ width: box.width, height: box.height });
        await testInfo.attach(`video-player-production400-measurements-${viewport.width}x${viewport.height}`, {
            contentType: 'application/json',
            body: Buffer.from(JSON.stringify({
                viewport, selectedMaster: { width: 400, height: 225 },
                previousSizingContract: {
                    evidence: 'Calculated from the previous min(selected master, available article width) rule; old runtime was not executed',
                    inlineMedia: { width: previousInlineWidth, height: previousInlineWidth * 9 / 16 },
                },
                actualCurrentRuntimeMeasurements: { inlineMedia: dimensions(inline), floatingMedia: dimensions(floating), returnedInlineMedia: dimensions(returned) },
                inlineWidthChangePixels: inline.width - previousInlineWidth,
                request: await page.evaluate(() => ({ tag: window.lastAdTagUrl, imaDimensions: window.lastAdDimensions, count: window.adRequests })),
            }, null, 2)),
        });
    });
}

for (const master of [[320, 180], [1200, 675]]) {
    test(`wide articles cap enlarged inline media without reducing a larger custom master: ${master.join('x')}`, async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 1200 });
        await openPlayer(page, { master, articleWidth: 1400 });
        await rememberPlayingAd(page);
        await expectEnlargedInlineGeometry(page, master);
        const inline = await inlineGeometry(page);
        expect(inline.width).toBe(Math.max(640, master[0]));
        await scrollPage(page, inline.y + inline.height + 80);
        await assertFloating(page);
        await expectCompactFloatingGeometry(page, master);
        const anchor = await page.locator('[data-hm-video-placeholder]').boundingBox();
        expect(anchor.width).toBe(inline.width);
        expect(anchor.height).toBe(inline.height);
        await scrollPage(page, 0);
        await expectInline(page, inline);
        await expectSamePlayingAd(page);
    });
}

test('a tall enlarged slot can float after partial exposure without requesting a hidden or under-viewable inline ad', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 260 });
    await openPlayer(page, { master: [300, 250], articleWidth: 1400, belowFold: true,
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=300x250' });
    const slot = await inlineGeometry(page);
    expect(slot.width).toBe(640);
    await scrollPage(page, 0);
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await scrollPage(page, slot.y - 80);
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate(media => {
        const player = media.__hmVideoPlayer;
        return player.visibleRatio > 0 && player.visibleRatio < 0.5 && !player.wasInlineVisible && player.wasInlineAnchorVisible;
    })).toBe(true);
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await scrollPage(page, slot.y + slot.height + 80);
    await assertFloating(page);
    await rememberPlayingAd(page);
    await expectCompactFloatingGeometry(page, [300, 250]);
    const request = await page.evaluate(() => {
        const box = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
        return { size: window.lastAdDimensions, actual: [Math.round(box.width), Math.round(box.height)] };
    });
    // The response was requested while inline; IMA is resized only after an
    // actual ad is available and the compact surface becomes visible.
    expect(request.size[0]).toBeGreaterThanOrEqual(request.actual[0]);
    expect(request.actual[0]).toBeLessThan(300);
    await scrollPage(page, slot.y - 80);
    await expectInline(page, slot);
    await expectSamePlayingAd(page);
});

for (const options of [{ hiddenArticle: true }, { backgroundTab: true }]) {
    test(`hidden or background tall media must be encountered visibly before floating: ${JSON.stringify(options)}`, async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 260 });
        await openPlayer(page, { ...options, master: [300, 250], articleWidth: 1400 });
        const slot = await inlineGeometry(page), surface = page.locator('[data-placement="video"]');
        // Put real media pixels in the viewport while it is still hidden or
        // backgrounded, so the negative encounter assertion is meaningful.
        await scrollPage(page, slot.y - 80);
        expect(await page.locator('[data-hm-video-direct]').evaluate(media => media.__hmVideoPlayer.wasInlineAnchorVisible)).toBe(false);
        await scrollPage(page, slot.y + slot.height + 80);
        await expect(surface).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => ({ requests: window.adRequests, starts: window.adStarts }))).toEqual({ requests: 0, starts: 0 });
        await page.evaluate(() => {
            document.querySelector('article').style.visibility = 'visible';
            window.testVisibility = 'visible';
            document.dispatchEvent(new Event('visibilitychange'));
        });
        await scrollPage(page, slot.y - 80);
        await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate(media => media.__hmVideoPlayer.wasInlineAnchorVisible)).toBe(true);
        expect(await page.evaluate(() => window.adRequests)).toBe(0);
        await scrollPage(page, slot.y + slot.height + 80);
        await assertFloating(page);
        await rememberPlayingAd(page);
        await expectCompactFloatingGeometry(page, [300, 250]);
    });
}

for (const transformed of [false, true, 'scaled']) {
    test(`enlarged inline layout follows publisher reflow using only IMA resize (transform=${transformed})`, async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 1200 });
        await openPlayer(page, { transformed, content: true });
        await rememberPlayingAd(page);
        const initialRequest = await page.evaluate(() => ({ tag: window.lastAdTagUrl, size: window.lastAdDimensions }));
        for (const articleWidth of [368, 520, 1400, 720]) {
            await page.locator('article').evaluate((article, width) => { article.style.maxWidth = `${width}px`; }, articleWidth);
            await expectEnlargedInlineGeometry(page, [320, 180]);
            await expectMediaRatioAndManagerSize(page, [320, 180]);
            await expectSamePlayingAd(page);
        }
        expect(await page.evaluate(() => window.managerResizes.length)).toBeGreaterThan(0);
        expect(await page.evaluate(() => ({ tag: window.lastAdTagUrl, size: window.lastAdDimensions }))).toEqual(initialRequest);
    });
}

for (const target of [null, '', '%%WIDTH%%x%%HEIGHT%%', 'malformed', '0x0', '1x1', '640x480']) {
    test(`GAM targeting remains independent of enlarged render dimensions: sz=${JSON.stringify(target)}`, async ({ page }) => {
        const url = new URL('https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video');
        if (target !== null) url.searchParams.set('sz', target);
        await openPlayer(page, { master: [400, 225], vastUrl: url.href });
        await rememberPlayingAd(page);
        await expectEnlargedInlineGeometry(page, [400, 225]);
        const before = await page.evaluate(() => {
            const box = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
            return { tag: window.lastAdTagUrl, size: window.lastAdDimensions, actual: [Math.round(box.width), Math.round(box.height)] };
        });
        expect(before.size).toEqual(before.actual);
        expect(new URL(before.tag).searchParams.get('sz')).toBe(['1x1', '640x480'].includes(target) ? target : '400x225');
        await scrollPage(page, 1800);
        await assertFloating(page);
        await expectCompactFloatingGeometry(page, [400, 225]);
        await scrollPage(page, 0);
        await expectEnlargedInlineGeometry(page, [400, 225]);
        await expectSamePlayingAd(page);
        expect(await page.evaluate(() => ({ tag: window.lastAdTagUrl, size: window.lastAdDimensions }))).toEqual({ tag: before.tag, size: before.size });
    });
}

test('legacy bottom_right inventory remains fixed and playing from its initial render', async ({ page }) => {
    await openPlayer(page, { alwaysFloating: true, belowFold: true });
    await rememberPlayingAd(page);
    await assertFloating(page);
    await scrollPage(page, 1800);
    await assertFloating(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await scrollPage(page, 0);
    await assertFloating(page);
    await expectMediaRatioAndManagerSize(page, [320, 180]);
    await expectCompactFloatingGeometry(page, [320, 180]);
    await expectSamePlayingAd(page);
    await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
});


test('the return boundary has hysteresis and never jitters or restarts the ad', async ({ page }) => {
    await openPlayer(page);
    await rememberPlayingAd(page);
    const slot = await inlineGeometry(page), bottom = slot.y + slot.height;
    await page.evaluate(() => {
        window.floatingTransitions = [];
        new MutationObserver(records => {
            for (const record of records) window.floatingTransitions.push(record.target.getAttribute('data-hm-video-floating-state'));
        }).observe(document.querySelector('[data-placement="video"]'), { attributes: true, attributeFilter: ['data-hm-video-floating-state'] });
    });
    await scrollPage(page, bottom + 4);
    await assertFloating(page);
    for (const delta of [-2, 2, -3, 3, -1]) {
        await scrollPage(page, bottom + delta);
        await assertFloating(page);
    }
    expect(await page.evaluate(() => window.floatingTransitions)).toEqual(['floating']);
    await scrollPage(page, bottom - 12);
    await expectInline(page, slot);
    for (const delta of [-2, -4, -3]) {
        await scrollPage(page, bottom + delta);
        await expectInline(page, slot);
    }
    await scrollPage(page, bottom + 4);
    await assertFloating(page);
    expect(await page.evaluate(() => window.floatingTransitions)).toEqual(['floating', 'inline', 'floating']);
    await expectSamePlayingAd(page);
});

test('a body-owned portal tracks article width and horizontal reflow on every return', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await openPlayer(page, { master: [640, 480], transformed: true });
    await rememberPlayingAd(page);
    for (const width of [520, 420, 720]) {
        await scrollPage(page, 1800);
        await assertFloating(page);
        await page.locator('article').evaluate((article, width) => { article.style.maxWidth = `${width}px`; }, width);
        await expect.poll(() => page.locator('[data-hm-video-placeholder]').evaluate(anchor => anchor.getBoundingClientRect().width)).toBe(Math.min(640, width - 48));
        const reserved = await page.locator('[data-hm-video-placeholder]').evaluate(anchor => {
            const box = anchor.getBoundingClientRect();
            return { x: box.x + scrollX, y: box.y + scrollY, width: box.width, height: box.height,
                tailY: document.getElementById('tail').getBoundingClientRect().top + scrollY };
        });
        await scrollPage(page, 0);
        const surface = await expectInline(page, reserved);
        expect(await surface.evaluate(el => el.parentNode === document.body)).toBe(true);
        await expectEnlargedInlineGeometry(page, [640, 480]);
        await expectMediaRatioAndManagerSize(page, [640, 480]);
        await expectSamePlayingAd(page);
    }
});


test('portal chrome does not count toward the media viewability needed to start an ad', async ({ page }) => {
    await openPlayer(page, { transformed: true, belowFold: true, delayedSdk: true, narrowArticle: true });
    const slot = await inlineGeometry(page);
    const partialScroll = slot.y - (page.viewportSize().height - 112);
    await scrollPage(page, partialScroll);
    await page.evaluate(() => { window.installIma(); document.querySelector('[data-hm-ima-sdk]').dispatchEvent(new Event('load')); });
    await scrollPage(page, partialScroll);
    // 112 visible shell pixels comprise the 44px chrome and only 68/180 media
    // pixels. A shell-only ratio would incorrectly cross the 50% threshold.
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    expect(await page.evaluate(() => window.adStarts)).toBe(0);
    await scrollPage(page, partialScroll + 60);
    await rememberPlayingAd(page);
    await expectSamePlayingAd(page);
});

// These tests inspect actual browser layout and decoded content while IMA itself
// remains an explicitly deterministic local test double, never a paid auction.
test('mixed nonlinear creative stays clickable over decoded content and external content controls survive repeated inline-floating cycles', async ({ page }, testInfo) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, nonlinear: true, master: [336, 280], transformed: true,
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/mixed-fixture&output=vast&env=vp' });
    await expectDecodedContent(page);
    const video = page.locator('video');
    // Keep native 1x playback: WebKit/GStreamer rate changes can overwrite mute
    // independently of this runtime, invalidating the initial-muted assertion.
    const surface = page.locator('[data-placement="video"]');
    // This enlarged 640x533 inline creative initially extends below a desktop
    // viewport. Do not let iframe/control clicks trigger scrollIntoView on a
    // fixed portal while its rAF handler is repositioning it from the anchor.
    const inlineScroll = Math.max(0, await surface.evaluate(el => el.getBoundingClientRect().top + scrollY) - 64);
    const expectFullyVisibleInline = async () => {
        await expect.poll(() => surface.evaluate(el => {
            const box = el.getBoundingClientRect();
            return Math.abs(box.top - 64) < 1 && box.bottom <= innerHeight - 16
                && getComputedStyle(el).clipPath === 'none';
        })).toBe(true);
    };
    await scrollPage(page, inlineScroll);
    await expectFullyVisibleInline();
    await attachLayout(page, testInfo, 'mixed-nonlinear-before-interaction');
    await testInfo.attach('mixed-nonlinear-before-interaction-geometry', {
        contentType: 'application/json',
        body: JSON.stringify(await surface.evaluate(el => ({
            scrollY, clipPath: getComputedStyle(el).clipPath,
            boxes: Object.fromEntries([
                ['surface', el], ['media', el.querySelector('video')],
                ['play', el.querySelector('[data-hm-video-content-control="play"]')],
                ['mute', el.querySelector('[data-hm-video-content-control="mute"]')],
                ['iframe', el.querySelector('[data-test-ima]')],
            ].map(([name, node]) => [name, node?.getBoundingClientRect().toJSON()])),
        }))),
    });
    // The requested muted autoplay intent must survive native media insertion
    // and loading before any user control can change it, including in WebKit.
    const toggle = page.locator('[data-hm-video-content-control="play"]');
    const mute = page.locator('[data-hm-video-content-control="mute"]');
    await expect.poll(() => video.evaluate(el => ({ muted: el.muted, defaultMuted: el.defaultMuted })))
        .toEqual({ muted: true, defaultMuted: true });
    await expect(mute).toHaveAttribute('aria-label', 'Unmute video content');
    expect(await page.evaluate(() => window.lastAdPlaybackIntent)).toEqual({ autoPlay: true, muted: true });
    expect(await page.evaluate(() => ({
        muted: new URL(window.lastAdTagUrl).searchParams.get('vpmute'),
        autoplay: new URL(window.lastAdTagUrl).searchParams.get('vpa'),
        volume: window.videoManager.volume,
    }))).toEqual({ muted: '1', autoplay: 'auto', volume: 0 });
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'auto');
    const creative = page.frameLocator('[data-test-ima]').locator('#creative');
    await expect(creative).toBeVisible();
    await creative.click({ position: { x: 30, y: 25 } });
    await expect.poll(() => page.evaluate(() => window.adClicks)).toBe(1);
    await expect(toggle).toBeVisible();
    await expect(mute).toBeVisible();
    for (const control of [toggle, mute]) {
        await expect.poll(() => control.evaluate(button => {
            const box = button.getBoundingClientRect(), media = document.querySelector('video').getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            return box.width >= 44 && box.height >= 44 && box.bottom <= media.top + 1 && (hit === button || button.contains(hit));
        })).toBe(true);
    }
    await toggle.click();
    await expect.poll(() => video.evaluate(el => el.paused)).toBe(true);
    await mute.click();
    await expect.poll(() => video.evaluate(el => el.muted)).toBe(false);
    await mute.click();
    await expect.poll(() => video.evaluate(el => el.muted)).toBe(true);
    await toggle.click();
    await expect.poll(() => video.evaluate(el => el.paused)).toBe(false);
    expect(await page.evaluate(() => window.adClicks)).toBe(1);
    await rememberPlayingAd(page);
    const original = await inlineGeometry(page);
    await attachLayout(page, testInfo, 'mixed-nonlinear-decoded-inline');
    for (let cycle = 0; cycle < 2; cycle++) {
        await scrollPage(page, 1400);
        await assertFloating(page);
        await expectCompactFloatingGeometry(page, [336, 280]);
        await expectSamePlayingAd(page);
        await expect(toggle).toBeVisible();
        await expect(mute).toBeVisible();
        if (!cycle) await attachLayout(page, testInfo, 'mixed-nonlinear-decoded-floating');
        await scrollPage(page, inlineScroll);
        await expectInline(page, original);
        await expectFullyVisibleInline();
        await expectSamePlayingAd(page);
    }
    await attachLayout(page, testInfo, 'mixed-nonlinear-decoded-returned');
    await page.frameLocator('[data-test-ima]').getByRole('button', { name: 'Close ad' }).click();
    await expect(page.locator('[data-test-ima]')).toHaveCount(0);
    await expect(page.locator('[data-placement="video"]')).toBeVisible();
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'none');
    await expect(toggle).toBeVisible();
    await expect(mute).toBeVisible();
});

test('mixed nonlinear five-second content EOS retires the overlay and exactly one postroll, including stale duplicate callbacks', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, nonlinear: true, noStopEvents: true, master: [336, 280],
        ads: [{ linear: false, width: 300, height: 50, minSuggestedDuration: 10 }, { linear: true }] });
    await expectDecodedContent(page);
    await page.evaluate(() => {
        const video = document.querySelector('video');
        Object.defineProperty(video, 'duration', { configurable: true, value: 5 });
        video.currentTime = 2.6; video.dispatchEvent(new Event('timeupdate'));
    });
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    await scrollPage(page, 1400);
    await assertFloating(page);
    await page.evaluate(() => {
        const video = document.querySelector('video');
        window.overlayManager = window.videoManager;
        video.currentTime = 5; video.dispatchEvent(new Event('ended'));
    });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
    expect(await page.evaluate(() => window.managerStops)).toBe(1);
    await page.evaluate(() => {
        for (const event of ['user-close', 'complete', 'all-completed', 'content-resume']) window.overlayManager.emit(event);
        document.querySelector('video').dispatchEvent(new Event('ended'));
        window.videoManager.emit('all-completed');
        window.videoManager.emit('all-completed');
    });
    await expect(page.locator('[data-placement="video"]')).toBeHidden();
    expect(await page.evaluate(() => window.adRequests)).toBe(2);
});

test('nonlinear creative that no longer fits after resize is stopped rather than cropped or enlarging compact media', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, nonlinear: true, master: [336, 280], ad: { width: 300, height: 250 } });
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.lastNonlinearDimensions)).toEqual([336, 280]);
    await scrollPage(page, 1400);
    await assertFloating(page);
    await page.setViewportSize({ width: 280, height: 720 });
    await expect.poll(() => page.evaluate(() => window.managerStops)).toBe(1);
    await expect(page.locator('[data-test-ima]')).toHaveCount(0);
    await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'inline');
    await scrollPage(page, 0);
    await expectEnlargedInlineGeometry(page, [336, 280]);
    if (await page.locator('video').evaluate(video => video.paused)) await page.locator('[data-hm-video-content-control="play"]').click();
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('mixed nonlinear autoplay refusal retires the overlay and leaves native gesture controls usable without another auction', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, nonlinear: true, rejectContentAutoplay: true, master: [336, 280] });
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-detail', 'user-activation-required');
    await expect(page.locator('[data-test-ima]')).toHaveCount(0);
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'none');
    expect(await page.locator('video').evaluate(video => video.controls)).toBe(true);
    expect(await page.evaluate(() => window.managerStops)).toBe(1);
    await page.evaluate(() => {
        const button = document.createElement('button');
        button.id = 'fixture-play'; button.textContent = 'Play content fixture';
        button.onclick = () => { window.allowContentPlay = true; document.querySelector('video').play(); };
        document.body.prepend(button);
    });
    await page.locator('#fixture-play').click();
    await expectDecodedContent(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => window.adClicks)).toBe(0);
});

test('a 200px inline rail keeps small third-party nonlinear controls unclipped and outside the creative', async ({ page }, testInfo) => {
    await page.route('**/*', route => route.abort());
    // 248px article minus its two 24px paddings yields exactly 200px media.
    // A small third-party overlay fits this landscape surface; this fixture
    // deliberately makes no claim that GAM's 200x200 square creative fits it.
    await openPlayer(page, {
        content: true, realContent: true, nonlinear: true, inlineOnly: true,
        articleWidth: 248, master: [336, 280], ad: { width: 180, height: 50 },
        vastUrl: 'https://ads.example/vast?fixture=small-third-party-overlay',
    });
    await expectDecodedContent(page);
    const media = page.locator('[data-hm-video-direct]');
    const rail = page.locator('[data-hm-video-chrome]');
    const play = page.locator('[data-hm-video-content-control="play"]');
    const mute = page.locator('[data-hm-video-content-control="mute"]');
    await expect.poll(() => media.evaluate(el => el.getBoundingClientRect().width)).toBe(200);
    await expect(rail.locator('[data-hm-video-label="1"]')).toBeHidden();
    await expect(play).toBeVisible();
    await expect(mute).toBeVisible();
    await expect.poll(() => rail.evaluate(chrome => {
        const railBox = chrome.getBoundingClientRect();
        const mediaBox = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
        const controls = [...document.querySelectorAll('[data-hm-video-content-control], [data-placement="video"] [data-hm-placement-close]')];
        const boxes = controls.map(button => button.getBoundingClientRect());
        return controls.length === 3 && controls.every((button, index) => {
            const box = boxes[index];
            const hitPoints = [
                [box.left + 2, box.top + box.height / 2],
                [box.left + box.width / 2, box.top + box.height / 2],
                [box.right - 2, box.top + box.height / 2],
            ];
            return box.width >= 44 && box.height >= 44
                && box.left >= railBox.left && box.right <= railBox.right
                && box.top >= railBox.top && box.bottom <= railBox.bottom
                && box.bottom <= mediaBox.top + 0.5
                && button.scrollWidth <= button.clientWidth
                && hitPoints.every(([x, y]) => {
                    const hit = document.elementFromPoint(x, y);
                    return hit === button || button.contains(hit);
                });
        }) && boxes.every((box, index) => boxes.slice(index + 1).every(other =>
            box.right <= other.left || other.right <= box.left || box.bottom <= other.top || other.bottom <= box.top));
    })).toBe(true);
    await play.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.paused)).toBe(true);
    await mute.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.muted)).toBe(false);
    await mute.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.muted)).toBe(true);
    await play.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.paused)).toBe(false);
    expect(await page.evaluate(() => ({ requests: window.adRequests, clicks: window.adClicks, stops: window.managerStops })))
        .toEqual({ requests: 1, clicks: 0, stops: 0 });
    await attachLayout(page, testInfo, 'mixed-narrow-third-party-controls');
    await page.locator('article').evaluate(article => { article.style.maxWidth = '348px'; });
    await expect.poll(() => media.evaluate(el => el.getBoundingClientRect().width)).toBe(300);
    await expect(rail.locator('[data-hm-video-label="1"]')).toBeVisible();
    await page.locator('article').evaluate(article => { article.style.maxWidth = '248px'; });
    await expect.poll(() => media.evaluate(el => el.getBoundingClientRect().width)).toBe(200);
    await expect(rail.locator('[data-hm-video-label="1"]')).toBeHidden();
    await page.frameLocator('[data-test-ima]').getByRole('button', { name: 'Close ad' }).click();
    await expect(rail.locator('[data-hm-video-label="1"]')).toBeHidden();
    await expect(play).toBeVisible();
    await expect(mute).toBeVisible();
    await expect(page.locator('[data-placement="video"]')).toBeVisible();
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

for (const transformed of [false, true]) {
    test(`only filled ads float, including a late preroll and later mid/postroll at the existing scroll position (portal=${transformed})`, async ({ page }, testInfo) => {
        await openPlayer(page, { content: true, transformed, deferResponse: true });
        const surface = page.locator('[data-placement="video"]');
        const original = await inlineGeometry(page);
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        await scrollPage(page, 1800);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        expect(await page.evaluate(() => window.adStarts)).toBe(0);
        await page.evaluate(() => window.videoLoaders[0].resolve());
        await assertFloating(page);
        await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
        await attachLayout(page, testInfo, 'ad-only-sticky-preroll');
        await page.evaluate(() => { window.videoManager.emit('complete'); window.videoManager.emit('content-resume'); window.videoManager.emit('all-completed'); });
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        expect(await page.evaluate(() => window.scrollY)).toBe(1800);
        await scrollPage(page, 0);
        await expectInline(page, original);
        await attachLayout(page, testInfo, 'premium-content-controls');
        await scrollPage(page, 1800);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        await page.locator('video').evaluate(video => {
            Object.defineProperty(video, 'duration', { configurable: true, value: 100 });
            Object.defineProperty(video, 'currentTime', { configurable: true, value: 60 });
            video.dispatchEvent(new Event('timeupdate'));
        });
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        await page.evaluate(() => window.videoLoaders[1].resolve());
        await assertFloating(page);
        await page.evaluate(() => window.videoManager.emit('all-completed'));
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        await page.locator('video').evaluate(video => video.dispatchEvent(new Event('ended')));
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(3);
        await page.evaluate(() => window.videoLoaders[2].resolve());
        await assertFloating(page);
        await page.evaluate(() => window.videoManager.emit('all-completed'));
        await expect(surface).toBeHidden();
        expect(await page.evaluate(() => window.adRequests)).toBe(3);
    });
}

for (const secondFilled of [true, false]) {
    test(`empty preroll retries exactly once before content; second filled=${secondFilled}`, async ({ page }) => {
        await openPlayer(page, { content: true, deferResponse: true });
        const surface = page.locator('[data-placement="video"]');
        await scrollPage(page, 1800);
        await page.evaluate(() => {
            window.emptyAd = () => ({ getError: () => ({ getErrorCode: () => 1009, getVastErrorCode: () => 303 }) });
            window.videoLoaders[0].events['ad-error'](window.emptyAd());
        });
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        expect(await page.evaluate(() => window.contentPlayCalls)).toBe(0);
        await page.evaluate(secondFilled => {
            if (secondFilled) window.videoLoaders[1].resolve();
            else window.videoLoaders[1].events['ad-error'](window.emptyAd());
            // Retired response and error cannot resurrect a player or retry.
            window.videoLoaders[0].resolve();
            window.videoLoaders[0].events['ad-error'](window.emptyAd());
        }, secondFilled);
        if (secondFilled) {
            await assertFloating(page);
            expect(await page.evaluate(() => window.contentPlayCalls)).toBe(0);
            await page.evaluate(() => window.videoManager.emit('all-completed'));
        }
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        expect(await page.evaluate(() => window.adRequests)).toBe(2);
        expect(await page.evaluate(() => window.adStarts)).toBe(secondFilled ? 1 : 0);
    });
}

for (const content of [true, false]) {
    test(`confirmed VAST after scroll starts without media preloading; content=${content}`, async ({ page }) => {
        await openPlayer(page, { content, deferResponse: true, noPreload: true });
        const surface = page.locator('[data-placement="video"]');
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        await scrollPage(page, 1800);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        expect(await page.evaluate(() => window.adStarts)).toBe(0);
        await page.evaluate(() => {
            window.originalMedia = document.querySelector('[data-hm-video-direct] video');
            window.videoLoaders[0].resolve();
        });
        await assertFloating(page);
        await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
        await expect(surface).not.toHaveAttribute('data-hm-video-motion', /.+/);
        expect(await page.evaluate(() => ({
            requests: window.adRequests,
            sameMedia: window.originalMedia === document.querySelector('[data-hm-video-direct] video'),
            inert: document.querySelector('[data-hm-video-ad-layer]').hasAttribute('inert'),
        }))).toEqual({ requests: 1, sameMedia: true, inert: false });
    });
}

test('a late VMAP preroll floats after confirmed zero-offset LOADED while future pods stay inline', async ({ page }) => {
    await openPlayer(page, { content: true, cuePoints: [0, 50, -1], deferResponse: true });
    const surface = page.locator('[data-placement="video"]');
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    await scrollPage(page, 1800);
    await page.evaluate(() => window.videoLoaders[0].resolve());
    for (const timeOffset of [50, -1]) {
        await page.evaluate(timeOffset => window.videoManager.emit('loaded', { getAd: () => ({
            isLinear: () => true, getAdPodInfo: () => ({ getTimeOffset: () => timeOffset }),
        }) }), timeOffset);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
        expect(await page.evaluate(() => window.adStarts)).toBe(0);
    }
    await page.evaluate(() => window.videoManager.emit('loaded', { getAd: () => ({
        isLinear: () => true, getAdPodInfo: () => ({ getTimeOffset: () => 0 }),
    }) }));
    await assertFloating(page);
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('VMAP future preload stays inline, actual ad start floats without another scroll, content resume returns inline', async ({ page }) => {
    await openPlayer(page, { content: true, cuePoints: [0, 50, -1] });
    const surface = page.locator('[data-placement="video"]');
    await page.evaluate(() => window.videoManager.emit('content-resume'));
    await scrollPage(page, 1800);
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
    await page.evaluate(() => window.videoManager.emit('loaded'));
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
    await page.evaluate(() => { window.videoManager.emit('content-pause'); window.videoManager.emit('started'); });
    await assertFloating(page);
    await page.evaluate(() => window.videoManager.emit('content-resume'));
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('ad-only completion after scroll removes the returned shell instead of leaving an empty player', async ({ page }) => {
    await openPlayer(page);
    await rememberPlayingAd(page);
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'auto');
    await scrollPage(page, 1800);
    await assertFloating(page);
    await expect(page.locator('[data-hm-video-ad-layer]')).toHaveCSS('pointer-events', 'auto');
    const creative = page.frameLocator('[data-test-ima]').locator('strong');
    await creative.evaluate(el => el.addEventListener('click', () => parent.adClicks++));
    await creative.click();
    expect(await page.evaluate(() => window.adClicks)).toBe(1);
    await page.evaluate(() => { window.videoManager.emit('complete'); window.videoManager.emit('all-completed'); });
    await expect(page.locator('[data-placement="video"]')).toBeHidden();
    await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
    await scrollPage(page, 0);
    await expect(page.locator('[data-placement="video"]')).toBeHidden();
});

async function recordPlayerMotion(page) {
    await page.addInitScript(() => {
        window.playerMotionEvidence = [];
        const animate = Element.prototype.animate;
        Element.prototype.animate = function (frames, options) {
            const animation = animate.call(this, frames, options);
            if (this.hasAttribute('data-hm-video-shell')) {
                const evidence = { frames, duration: options.duration, samples: [] };
                window.playerMotionEvidence.push(evidence);
                const sample = () => {
                    const css = getComputedStyle(this);
                    evidence.samples.push({ opacity: Number(css.opacity), translate: css.translate });
                    if (animation.playState === 'running' || animation.playState === 'pending') requestAnimationFrame(sample);
                };
                requestAnimationFrame(sample);
            }
            return animation;
        };
    });
}

for (const transformed of [false, 'scaled']) {
    test(`real enter/exit/return motion preserves media and article geometry (portal=${transformed})`, async ({ page }) => {
        await recordPlayerMotion(page);
        await openPlayer(page, { transformed });
        await rememberPlayingAd(page);
        const original = await inlineGeometry(page);
        await scrollPage(page, 1800);
        const surface = await assertFloating(page);
        await expectSamePlayingAd(page);
        const entry = await page.evaluate(() => window.playerMotionEvidence.find(m => m.duration === 220));
        expect(entry.frames).toEqual([{ opacity: '0', translate: '0 14px' }, { opacity: '1', translate: '0 0' }]);
        expect(entry.samples.some(s => s.opacity > 0 && s.opacity < 1)).toBe(true);
        expect(entry.samples.some(s => s.translate.split(/\s+/).some(value => Math.abs(parseFloat(value)) > 0.01))).toBe(true);
        expect(Math.abs(await page.locator('#tail').evaluate(el => el.getBoundingClientRect().top + scrollY) - original.tailY)).toBeLessThan(1);
        await scrollPage(page, 0);
        await expectInline(page, original);
        await expectSamePlayingAd(page);
        expect(await page.evaluate(() => window.playerMotionEvidence.map(m => m.duration))).toEqual([220, 140, 160]);
        expect(await surface.evaluate(el => el.getAnimations().length)).toBe(0);
        await scrollPage(page, 1800);
        await assertFloating(page);
        const closing = await surface.evaluate(el => {
            const player = el.querySelector('[data-hm-video-direct]').__hmVideoPlayer;
            el.querySelector('[data-hm-placement-close]').click();
            return { phase: el.getAttribute('data-hm-video-motion'), closing: player.closing, volume: window.videoManager.volume, inert: el.hasAttribute('inert') };
        });
        expect(closing).toEqual({ phase: 'dismiss', closing: true, volume: 0, inert: true });
        await expect(surface).toBeHidden();
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
        expect(await page.evaluate(() => window.adRequests)).toBe(1);
    });

    test(`rapid reversal and closing during entry cannot resurrect or reload the player (portal=${transformed})`, async ({ page }) => {
        await openPlayer(page, { transformed });
        await rememberPlayingAd(page);
        await scrollPage(page, 1800);
        const surface = await assertFloating(page);
        const phases = await surface.evaluate(el => {
            const player = el.querySelector('[data-hm-video-direct]').__hmVideoPlayer;
            window.scrollTo(0, 0); player.viewport.update();
            const leaving = el.getAttribute('data-hm-video-motion');
            window.scrollTo(0, 1800); player.viewport.update();
            return [leaving, el.getAttribute('data-hm-video-motion')];
        });
        expect(phases).toEqual(['exit', 'enter']);
        await assertFloating(page);
        await expectSamePlayingAd(page);
        await surface.evaluate(el => {
            const player = el.querySelector('[data-hm-video-direct]').__hmVideoPlayer;
            window.scrollTo(0, 0); player.viewport.update();
            window.scrollTo(0, 1800); player.viewport.update();
            el.querySelector('[data-hm-placement-close]').click();
            window.scrollTo(0, 0); player.viewport.update();
        });
        await expect(surface).toBeHidden();
        await scrollPage(page, 1800);
        await expect(surface).toBeHidden();
        expect(await page.evaluate(() => window.adRequests)).toBe(1);
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
    });
}

for (const fallback of ['reduced-motion', 'no-animation-api']) {
    test(`${fallback} keeps immediate transitions and functional dismissal`, async ({ page }) => {
        if (fallback === 'reduced-motion') await page.emulateMedia({ reducedMotion: 'reduce' });
        else await page.addInitScript(() => { Element.prototype.animate = undefined; });
        await openPlayer(page);
        await rememberPlayingAd(page);
        const original = await inlineGeometry(page);
        await scrollPage(page, 1800);
        const surface = await assertFloating(page);
        expect(await surface.evaluate(el => el.getAnimations().length)).toBe(0);
        await scrollPage(page, 0);
        await expectInline(page, original);
        await expectSamePlayingAd(page);
        await scrollPage(page, 1800);
        await assertFloating(page);
        await surface.locator('[data-hm-placement-close]').click();
        await expect(surface).toBeHidden();
    });
}

test('new late ad waits for visible entry and a live reduced-motion change settles animation', async ({ page }) => {
    await openPlayer(page, { content: true, deferResponse: true });
    await scrollPage(page, 1800);
    const initial = await page.evaluate(() => {
        window.videoLoaders[0].resolve();
        const el = document.querySelector('[data-placement="video"]');
        return { phase: el.getAttribute('data-hm-video-motion'), starts: window.adStarts, adInert: el.querySelector('[data-hm-video-ad-layer]').hasAttribute('inert') };
    });
    expect(initial).toEqual({ phase: 'enter', starts: 0, adInert: true });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const surface = await assertFloating(page);
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await surface.evaluate(el => el.getAnimations().length)).toBe(0);
    await expect(surface.locator('[data-hm-video-ad-layer]')).not.toHaveAttribute('inert', '');
    await page.evaluate(() => window.videoManager.emit('all-completed'));
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'inline');
    await expect(surface).not.toHaveAttribute('data-hm-video-motion', /.+/);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('external animation cancellation finishes the transition and releases a pending ad exactly once', async ({ page }) => {
    await openPlayer(page, { deferResponse: true });
    await scrollPage(page, 1800);
    await page.evaluate(() => {
        window.videoLoaders[0].resolve();
        document.querySelector('[data-placement="video"]').getAnimations().forEach(animation => animation.cancel());
    });
    const surface = await assertFloating(page);
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    await surface.evaluate(el => {
        window.scrollTo(0, 0);
        el.querySelector('[data-hm-video-direct]').__hmVideoPlayer.viewport.update();
        el.getAnimations().forEach(animation => animation.cancel());
    });
    await expectInline(page);
    expect(await page.evaluate(() => ({ starts: window.adStarts, requests: window.adRequests }))).toEqual({ starts: 1, requests: 1 });
});

test('a pending ad returned inline during entry waits for the visible portal return', async ({ page }) => {
    await openPlayer(page, { content: true, deferResponse: true, transformed: 'scaled' });
    await scrollPage(page, 1800);
    const initial = await page.evaluate(() => {
        window.videoLoaders[0].resolve();
        const el = document.querySelector('[data-placement="video"]');
        const start = window.videoManager.start.bind(window.videoManager);
        window.adStartMotion = [];
        window.videoManager.start = () => { window.adStartMotion.push(el.getAttribute('data-hm-video-motion')); start(); };
        window.scrollTo(0, 0);
        el.querySelector('[data-hm-video-direct]').__hmVideoPlayer.viewport.update();
        return { phase: el.getAttribute('data-hm-video-motion'), starts: window.adStarts };
    });
    expect(initial).toEqual({ phase: 'exit', starts: 0 });
    await expectInline(page);
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await page.evaluate(() => window.adStartMotion)).toEqual([null]);
});

for (const rejectUnmutedAutoplay of [false, true]) {
    test(`explicit instream audible preference uses actual playback outcome: muted fallback ${rejectUnmutedAutoplay}`, async ({ page }) => {
        await openPlayer(page, { content: true, audibleContent: true, contentMode: 'instream', startMuted: false, rejectUnmutedAutoplay,
            vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&ad_type=video' });
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        expect(await page.evaluate(() => {
            const tag = new URL(window.lastAdTagUrl);
            return { plcmt: tag.searchParams.get('plcmt'), vpmute: tag.searchParams.get('vpmute'), vpa: tag.searchParams.get('vpa'),
                imaMuted: window.lastAdPlaybackIntent.muted, volume: window.videoManager.volume, muted: document.querySelector('video').muted };
        })).toEqual({ plcmt: '1', vpmute: rejectUnmutedAutoplay ? '1' : '0', vpa: 'auto',
            imaMuted: rejectUnmutedAutoplay, volume: rejectUnmutedAutoplay ? 0 : 1, muted: rejectUnmutedAutoplay });
    });
}

test('audible and muted autoplay denial leaves Play available without a VAST request', async ({ page }, testInfo) => {
    await openPlayer(page, { content: true, audibleContent: true, contentMode: 'instream', startMuted: false, rejectContentAutoplay: true,
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&ad_type=video' });
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-detail', 'user-activation-required');
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await testInfo.attach('fallback-controls-geometry', {
        contentType: 'application/json',
        body: JSON.stringify(await page.evaluate(() => Object.fromEntries([
            ['surface', '[data-placement="video"]'], ['rail', '[data-hm-video-chrome]'],
            ['label', '[data-hm-video-label]'], ['controls', '[data-hm-video-content-controls]'],
            ['mute', '[data-hm-video-content-control="mute"]'], ['close', '[data-hm-placement-close]'],
        ].map(([name, selector]) => {
            const node = document.querySelector(selector), box = node.getBoundingClientRect(), style = getComputedStyle(node);
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
            return [name, { box: box.toJSON(), display: style.display, position: style.position,
                padding: style.padding, margin: style.margin, boxSizing: style.boxSizing,
                hit: hit?.getAttribute('data-hm-video-content-control') || hit?.getAttribute('data-hm-placement-close') || hit?.tagName }];
        })))),
    });
    await page.evaluate(() => { window.allowContentPlay = true; });
    await page.locator('[data-hm-video-content-control="mute"]').click();
    await page.locator('[data-hm-video-content-control="play"]').click();
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => {
        const tag = new URL(window.lastAdTagUrl);
        return { plcmt: tag.searchParams.get('plcmt'), vpmute: tag.searchParams.get('vpmute'), vpa: tag.searchParams.get('vpa'),
            autoPlay: window.lastAdPlaybackIntent.autoPlay };
    })).toEqual({ plcmt: '1', vpmute: '0', vpa: 'click', autoPlay: false });
});

test('real audio-track content validates audible playback before the first instream ad', async ({ page }) => {
    await openPlayer(page, { content: true, realContent: true, audibleContent: true, contentMode: 'instream',
        startMuted: false, primeUserActivation: true,
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&ad_type=video' });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => ({
        muted: document.querySelector('video').muted,
        volume: document.querySelector('video').volume,
        readyState: document.querySelector('video').readyState >= 2,
        vpmute: new URL(window.lastAdTagUrl).searchParams.get('vpmute'),
        vpa: new URL(window.lastAdTagUrl).searchParams.get('vpa'),
    }))).toEqual({ muted: false, volume: 1, readyState: true, vpmute: '0', vpa: 'auto' });
    await page.evaluate(() => window.videoManager.emit('all-completed'));
    await expectDecodedContent(page);
});

test('real audio-track content continues muted after browser sound denial', async ({ page }) => {
    await openPlayer(page, { content: true, realContent: true, audibleContent: true, contentMode: 'instream',
        startMuted: false, rejectUnmutedAutoplay: true,
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&ad_type=video' });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => ({
        muted: document.querySelector('video').muted, vpmute: new URL(window.lastAdTagUrl).searchParams.get('vpmute'),
        plcmt: new URL(window.lastAdTagUrl).searchParams.get('plcmt'),
    }))).toEqual({ muted: true, vpmute: '1', plcmt: '1' });
    await page.evaluate(() => window.videoManager.emit('all-completed'));
    await expectDecodedContent(page);
});

const audioIntentFixtureTag = 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/audio-intent-fixture&ad_type=video';

async function requestedAudioSignals(page) {
    return page.evaluate(() => window.adRequestHistory.map(request => {
        const tag = new URL(request.tag);
        return { vpmute: tag.searchParams.get('vpmute'), vpa: tag.searchParams.get('vpa'),
            plcmt: tag.searchParams.get('plcmt'), position: tag.searchParams.get('vpos'),
            autoPlay: request.autoPlay, muted: request.muted };
    }));
}

function audioVolumePrecision(value) {
    if (Array.isArray(value)) return value.map(audioVolumePrecision);
    // WebKit exposes native volume as float32. Normalize only volume fields;
    // mute state, request snapshots, event counts and array lengths stay exact.
    return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key,
        ['volume', 'mediaVolume'].includes(key) && typeof entry === 'number' ? Math.round(entry * 1e6) / 1e6 : entry,
    ]));
}

async function currentMediaAudio(page) {
    return audioVolumePrecision(await page.locator('video').evaluate(video => ({ muted: video.muted, volume: video.volume })));
}

for (const choice of [
    { name: 'mute', startMuted: false, muted: true, volume: 1 },
    { name: 'zero volume', startMuted: false, muted: false, volume: 0 },
    { name: 'partial volume', startMuted: false, muted: false, volume: 0.35 },
    { name: 'unmute', startMuted: true, muted: false, volume: 0.6 },
]) {
    test(`pending VAST response starts with the latest viewer ${choice.name}, without rewriting its request`, async ({ page }) => {
        await openPlayer(page, { content: true, contentMode: 'instream', startMuted: choice.startMuted,
            deferResponse: true, restoreMediaAudio: true, echoVolumeEvents: true, vastUrl: audioIntentFixtureTag });
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        const submitted = await page.evaluate(() => window.adRequestHistory[0]);
        expect(await requestedAudioSignals(page)).toEqual([{ vpmute: choice.startMuted ? '1' : '0', vpa: 'auto',
            plcmt: '1', position: 'preroll', autoPlay: true, muted: choice.startMuted }]);
        await page.locator('video').evaluate((video, choice) => {
            video.muted = choice.muted; video.volume = choice.volume;
        }, choice);
        // Native volumechange is asynchronous. Let it run before the delayed
        // response; init() deliberately restores the old recipe audio state.
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await page.evaluate(() => window.videoLoaders[0].resolve());
        await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(1);
        const effectiveVolume = choice.muted ? 0 : choice.volume;
        expect(audioVolumePrecision(await page.evaluate(() => window.adStartStates))).toEqual([
            { volume: effectiveVolume, mediaMuted: choice.muted, mediaVolume: choice.volume },
        ]);
        await expect.poll(() => currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        expect(await page.evaluate(() => window.adRequestHistory)).toEqual([submitted]);
        expect(audioVolumePrecision(await page.evaluate(() => window.managerVolumeWrites))).toEqual([{ manager: 0, volume: effectiveVolume }]);
    });
}

for (const choice of [
    { name: 'mute', startMuted: false, sdkVolume: 0, event: 'volume-muted', muted: true, volume: 1 },
    { name: 'partial volume', startMuted: false, sdkVolume: 0.35, event: 'volume-changed', muted: false, volume: 0.35 },
    { name: 'unmute', startMuted: true, sdkVolume: 0.6, event: 'volume-changed', muted: false, volume: 0.6 },
]) {
    test(`IMA ${choice.name} survives shared-media restoration and signals the next manual break`, async ({ page }) => {
        await openPlayer(page, { content: true, contentMode: 'instream', startMuted: choice.startMuted,
            restoreMediaAudio: true, echoVolumeEvents: true, vastUrl: audioIntentFixtureTag });
        await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(1);
        const submitted = await page.evaluate(() => window.adRequestHistory[0]);
        await page.evaluate(choice => {
            window.firstAudioManager = window.videoManager;
            window.firstAudioManager.volume = choice.sdkVolume;
            window.firstAudioManager.emit(choice.event);
        }, choice);
        await expect.poll(() => currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        await page.evaluate(() => {
            // IMA can restore its pre-ad snapshot before CONTENT_RESUME and
            // again during destroy(). Neither is a new viewer audio choice.
            window.firstAudioManager.emit('complete');
            window.firstAudioManager.restoreMediaAudio();
            window.firstAudioManager.emit('content-resume');
            window.firstAudioManager.emit('all-completed');
        });
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        await expect.poll(() => currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        await page.locator('video').evaluate(video => {
            // Only the editorial timeline is synthetic here; browser-native
            // audio properties and volumechange events remain unmodified.
            Object.defineProperty(video, 'duration', { configurable: true, value: 12 });
            Object.defineProperty(video, 'currentTime', { configurable: true, value: 6 });
            video.dispatchEvent(new Event('timeupdate'));
        });
        await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(2);
        expect((await requestedAudioSignals(page))[1]).toEqual({ vpmute: choice.muted ? '1' : '0', vpa: 'auto',
            plcmt: '1', position: 'midroll', autoPlay: true, muted: choice.muted });
        expect(audioVolumePrecision(await page.evaluate(() => window.adStartStates[1]))).toEqual({ volume: choice.sdkVolume,
            mediaMuted: choice.muted, mediaVolume: choice.volume });
        await page.evaluate(() => {
            window.firstAudioManager.volume = 0.9;
            window.firstAudioManager.emit('volume-changed');
            window.firstAudioManager.volume = 0;
            window.firstAudioManager.emit('volume-muted');
        });
        expect(await currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        expect(await page.evaluate(() => window.videoManager.getVolume())).toBeCloseTo(choice.sdkVolume, 6);
        expect(await page.evaluate(() => window.adRequestHistory[0])).toEqual(submitted);
        expect(await page.evaluate(() => window.adRequests)).toBe(2);
        expect(await page.evaluate(() => window.managerVolumeWrites.length)).toBeLessThanOrEqual(3);
    });
}

for (const choice of [
    { name: 'mute with partial volume', muted: true, volume: 0.35 },
    { name: 'zero volume', muted: false, volume: 0 },
    { name: 'partial volume', muted: false, volume: 0.35 },
]) {
    test(`native ${choice.name} during an active ad survives SDK restoration into the next break`, async ({ page }) => {
        await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false,
            restoreMediaAudio: true, echoVolumeEvents: true, vastUrl: audioIntentFixtureTag });
        await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(1);
        const submitted = await page.evaluate(() => window.adRequestHistory[0]);
        await page.locator('video').evaluate((video, choice) => {
            // Change the shared native media element while IMA owns it. This
            // must survive just like a choice made in IMA's own controls.
            window.firstAudioManager = window.videoManager;
            video.muted = choice.muted; video.volume = choice.volume;
        }, choice);
        const effectiveVolume = choice.muted ? 0 : choice.volume;
        await expect.poll(() => page.evaluate(() => window.videoManager.getVolume())).toBeCloseTo(effectiveVolume, 6);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        expect(await currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        await page.evaluate(() => {
            window.firstAudioManager.emit('complete');
            window.firstAudioManager.restoreMediaAudio();
        });
        // Exercise queued browser volumechange from SDK restoration before
        // its terminal resume callback, not only same-task restoration.
        await expect.poll(() => currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        await page.evaluate(() => {
            window.firstAudioManager.emit('content-resume');
            window.firstAudioManager.emit('all-completed');
        });
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        await expect.poll(() => currentMediaAudio(page))
            .toEqual({ muted: choice.muted, volume: choice.volume });
        await page.locator('video').evaluate(video => {
            Object.defineProperty(video, 'duration', { configurable: true, value: 12 });
            Object.defineProperty(video, 'currentTime', { configurable: true, value: 6 });
            video.dispatchEvent(new Event('timeupdate'));
        });
        await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(2);
        expect((await requestedAudioSignals(page))[1]).toEqual({ vpmute: effectiveVolume === 0 ? '1' : '0', vpa: 'auto',
            plcmt: '1', position: 'midroll', autoPlay: true, muted: effectiveVolume === 0 });
        expect(audioVolumePrecision(await page.evaluate(() => window.adStartStates[1]))).toEqual({ volume: effectiveVolume,
            mediaMuted: choice.muted, mediaVolume: choice.volume });
        expect(await page.evaluate(() => window.adRequestHistory[0])).toEqual(submitted);
        expect(await page.evaluate(() => window.adRequests)).toBe(2);
        expect(await page.evaluate(() => window.managerVolumeWrites.length)).toBeLessThanOrEqual(4);
    });
}

test('visible X cancels a pending midroll after an audio change and a stale manager cannot start', async ({ page }) => {
    await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false, deferResponse: true,
        vastUrl: audioIntentFixtureTag });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    await page.evaluate(() => window.videoLoaders[0].resolve());
    await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(1);
    const surface = page.locator('[data-placement="video"]');
    // The loader exposes X after a rendered player, never on an unresolved
    // initial request. Establish a real filled preroll before the pending break.
    await expect(surface).toHaveAttribute('data-hm-status', 'rendered');
    await expect(surface.locator('[data-hm-placement-close]')).toBeVisible();
    await page.evaluate(() => window.videoManager.emit('all-completed'));
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
    await page.locator('video').evaluate(video => {
        Object.defineProperty(video, 'duration', { configurable: true, value: 12 });
        Object.defineProperty(video, 'currentTime', { configurable: true, value: 6 });
        video.dispatchEvent(new Event('timeupdate'));
    });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
    const submitted = await page.evaluate(() => window.adRequestHistory);
    await page.locator('video').evaluate(video => { video.muted = true; video.volume = 0.25; });
    await expect(surface.locator('[data-hm-placement-close]')).toBeVisible();
    await surface.locator('[data-hm-placement-close]').click();
    await expect(surface).toBeHidden();
    await page.evaluate(() => window.videoLoaders[1].resolve());
    await scrollPage(page, 1400);
    await scrollPage(page, 0);
    expect(await page.evaluate(() => ({ requests: window.adRequests, managers: window.videoManagers.length, starts: window.adStarts })))
        .toEqual({ requests: 2, managers: 1, starts: 1 });
    expect(await page.evaluate(() => window.adRequestHistory)).toEqual(submitted);
});

test('silent content startup followed by IMA 1205 gets exactly one truthful muted preroll fallback', async ({ page }) => {
    await page.route('**/*', route => route.abort());
    await openPlayer(page, { content: true, realContent: true, contentMode: 'instream', startMuted: false,
        deferResponse: true, adStartErrors: [1205], vastUrl: audioIntentFixtureTag });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    const firstRequest = await page.evaluate(() => window.adRequestHistory[0]);
    // The real silent MP4 decoded and play() fulfilled unmuted. That does not
    // establish that a subsequent audio-bearing ad can autoplay audibly.
    expect(firstRequest.readyState).toBeGreaterThanOrEqual(2);
    expect(firstRequest.mediaMuted).toBe(false);
    expect(await requestedAudioSignals(page)).toEqual([{ vpmute: '0', vpa: 'auto', plcmt: '1',
        position: 'preroll', autoPlay: true, muted: false }]);
    await page.evaluate(() => {
        window.originalAudioVideo = document.querySelector('video');
        window.originalAudioSource = window.originalAudioVideo.getAttribute('src');
        window.videoLoaders[0].resolve();
    });
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-preroll-retry', 'autoplay-muted');
    expect(await page.evaluate(() => window.adStartedEvents)).toBe(0);
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
    expect(await requestedAudioSignals(page)).toEqual([
        { vpmute: '0', vpa: 'auto', plcmt: '1', position: 'preroll', autoPlay: true, muted: false },
        { vpmute: '1', vpa: 'auto', plcmt: '1', position: 'preroll', autoPlay: true, muted: true },
    ]);
    expect(await page.evaluate(() => window.adRequestHistory[0])).toEqual(firstRequest);
    await page.evaluate(() => {
        // Old loader/manager callbacks arriving after the new request must not
        // create a third request or turn the muted retry audible again.
        window.videoManagers[0].emit('ad-error', { getError: () => ({ getErrorCode: () => 1205 }) });
        window.videoLoaders[0].resolve();
        window.videoLoaders[1].resolve();
    });
    await expect.poll(() => page.evaluate(() => window.adStartedEvents)).toBe(1);
    expect(audioVolumePrecision(await page.evaluate(() => window.adStartStates))).toEqual([
        { volume: 1, mediaMuted: false, mediaVolume: 1 }, { volume: 0, mediaMuted: true, mediaVolume: 1 },
    ]);
    expect(await page.evaluate(() => ({ requests: window.adRequests, managers: window.videoManagers.length,
        sameVideo: document.querySelector('video') === window.originalAudioVideo,
        sameSource: document.querySelector('video').getAttribute('src') === window.originalAudioSource })))
        .toEqual({ requests: 2, managers: 2, sameVideo: true, sameSource: true });
    await page.evaluate(() => window.videoManager.emit('all-completed'));
    await expectDecodedContent(page);
    expect(await page.locator('video').evaluate(video => video.muted)).toBe(true);
    expect(await page.evaluate(() => window.adRequests)).toBe(2);
});

test('a second IMA 1205 exhausts the shared preroll budget and leaves content usable', async ({ page }) => {
    await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false, deferResponse: true,
        adStartErrors: [1205, 1205], vastUrl: audioIntentFixtureTag });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    await page.evaluate(() => window.videoLoaders[0].resolve());
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(2);
    await page.evaluate(() => window.videoLoaders[1].resolve());
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
    // Observe beyond the retry delay, rather than accepting an immediate count
    // that could conceal a queued third attempt.
    await page.waitForTimeout(1250);
    expect(await page.evaluate(() => ({ requests: window.adRequests, started: window.adStartedEvents, muted: document.querySelector('video').muted })))
        .toEqual({ requests: 2, started: 0, muted: true });
    await expect(page.locator('[data-placement="video"]')).toBeVisible();
    await expect(page.locator('[data-hm-video-content-control="mute"]')).toBeVisible();
    // The loader exposes X only after the placement reports rendered.
    // After both startup attempts fail, verify the real content controls work
    // without inventing a rendered ad or requesting a third one.
    const play = page.locator('[data-hm-video-content-control="play"]');
    await expect(play).toBeVisible();
    await play.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.paused)).toBe(true);
    await play.click();
    await expect.poll(() => page.locator('video').evaluate(video => video.paused)).toBe(false);
    expect(await page.evaluate(() => window.adRequests)).toBe(2);
});

for (const cancel of ['unmute', 'partial volume']) {
    test(`viewer ${cancel} during the IMA 1205 fallback delay cancels its pending request`, async ({ page }) => {
        await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false, deferResponse: true,
            adStartErrors: [1205], vastUrl: audioIntentFixtureTag });
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        const cancelled = await page.evaluate(cancel => {
            window.videoLoaders[0].resolve();
            const video = document.querySelector('video');
            const reason = document.querySelector('#video-runtime').getAttribute('data-hm-video-preroll-retry');
            // Act in the same task as failure, before the bounded retry timer.
            if (cancel === 'unmute') video.muted = false;
            else video.volume = 0.4;
            video.dispatchEvent(new Event('volumechange'));
            return reason;
        }, cancel);
        expect(cancelled).toBe('autoplay-muted');
        await page.waitForTimeout(1250);
        expect(await page.evaluate(() => ({ requests: window.adRequests, started: window.adStartedEvents })))
            .toEqual({ requests: 1, started: 0 });
        await expect(page.locator('[data-placement="video"]')).toBeVisible();
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        expect(await currentMediaAudio(page))
            .toEqual(cancel === 'unmute' ? { muted: false, volume: 1 } : { muted: true, volume: 0.4 });
    });
}

for (const exclusion of ['VMAP', 'click start', 'already started', 'changed viewer intent']) {
    test(`IMA 1205 does not retry or force mute after ${exclusion}`, async ({ page }) => {
        await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false,
            startAutoplay: exclusion !== 'click start', deferResponse: true,
            adStartErrors: exclusion === 'already started' ? [] : [1205],
            cuePoints: exclusion === 'VMAP' ? [0, 5] : [],
            vastUrl: audioIntentFixtureTag + (exclusion === 'VMAP' ? '&output=xml_vmap1&ad_rule=1' : '') });
        if (exclusion === 'click start') {
            await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-detail', 'user-activation-required');
            await page.locator('[data-hm-video-content-control="play"]').click();
        }
        await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
        await page.evaluate(exclusion => {
            if (exclusion === 'changed viewer intent') {
                document.querySelector('video').volume = 0.4;
                document.querySelector('video').dispatchEvent(new Event('volumechange'));
            }
            window.videoLoaders[0].resolve();
            if (exclusion === 'already started') window.videoManager.emit('ad-error', { getError: () => ({ getErrorCode: () => 1205 }) });
        }, exclusion);
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
        await page.waitForTimeout(1250);
        expect(audioVolumePrecision(await page.evaluate(() => ({ requests: window.adRequests, muted: document.querySelector('video').muted,
            volume: document.querySelector('video').volume }))))
            .toEqual({ requests: 1, muted: false, volume: exclusion === 'changed viewer intent' ? 0.4 : 1 });
        await expect(page.locator('#video-runtime')).not.toHaveAttribute('data-hm-video-preroll-retry', 'autoplay-muted');
        expect((await requestedAudioSignals(page))[0].vpa).toBe(exclusion === 'click start' ? 'click' : 'auto');
    });
}

test('fixed platform requests autoplay with constant plcmt and sound-on without a content probe', async ({ page }) => {
    await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false, fixedVideo: true,
        rejectContentAutoplay: true, vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&plcmt=2&vpmute=1' });
    await expect.poll(() => page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => {
        const tag = new URL(window.lastAdTagUrl);
        return { plcmt: tag.searchParams.get('plcmt'), vpmute: tag.searchParams.get('vpmute'), vpa: tag.searchParams.get('vpa'),
            imaMuted: window.lastAdPlaybackIntent.muted, muted: document.querySelector('video').muted };
    })).toEqual({ plcmt: '1', vpmute: '0', vpa: 'auto', imaMuted: false, muted: false });
});

test('fixed sound-on autoplay denial recovers from the existing Play button inside a trusted gesture', async ({ page }) => {
    await openPlayer(page, { content: true, contentMode: 'instream', startMuted: false, fixedVideo: true,
        rejectContentAutoplay: true, adStartErrors: [1205],
        vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&plcmt=2&vpmute=1' });
    const player = page.locator('#video-runtime');
    await expect(player).toHaveAttribute('data-hm-video-status', 'content-ready');
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    await page.getByRole('button', { name: 'Play video content', exact: true }).click();
    await expect(player).toHaveAttribute('data-hm-video-status', 'started');
    expect(await page.evaluate(() => ({ requests: window.adRequests, gestureInitialized: window.displayInitializations.at(-1),
        muted: document.querySelector('video').muted, imaMuted: window.lastAdPlaybackIntent.muted,
        plcmt: new URL(window.lastAdTagUrl).searchParams.get('plcmt'), vpmute: new URL(window.lastAdTagUrl).searchParams.get('vpmute') })))
        .toEqual({ requests: 2, gestureInitialized: true, muted: false, imaMuted: false, plcmt: '1', vpmute: '0' });
});
