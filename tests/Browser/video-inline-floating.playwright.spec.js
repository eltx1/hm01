import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

// Generated 12-second, silent H.264 baseline fixture; no third-party media or ads.
const contentBytes = Buffer.from(await readFile(new URL('./fixtures/content-playback.mp4.base64', import.meta.url), 'utf8'), 'base64');
const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const loader = applyPlacementPresetTransform(await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8'));

// Exercise the real loader + video DOM with a deterministic IMA boundary.
// No live auctions, impression pixels, credentials, or paid inventory are used.
async function openPlayer(page, options = {}) {
    const resourceRequests = { runtime: 0, content: 0 };
    await page.route('https://reader.example/**', route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/player.js') { resourceRequests.runtime++; return route.fulfill({ contentType: 'application/javascript', body: runtime }); }
        if (path === '/ad.js') return route.fulfill({ contentType: 'application/javascript', body: '' });
        if (path === '/broken-ad.mp4') return route.fulfill({ status: 404, body: '' });
        if (path === '/content.mp4') {
            resourceRequests.content++;
            if (!options.content) return route.fulfill({ status: 404, body: '' });
            const range = /^bytes=(\d+)-(\d*)$/.exec(route.request().headers().range || '');
            const start = range ? Number(range[1]) : 0;
            const end = Math.min(contentBytes.length - 1, range && range[2] ? Number(range[2]) : contentBytes.length - 1);
            return route.fulfill({ status: range ? 206 : 200, contentType: 'video/mp4',
                headers: { 'Accept-Ranges': 'bytes', ...(range ? { 'Content-Range': `bytes ${start}-${end}/${contentBytes.length}` } : {}) },
                body: contentBytes.subarray(start, end + 1) });
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
        window.managerInits = 0; window.managerResizes = [];
        window.lastAdTagUrl = null; window.lastRenderingSettings = null;
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
            HTMLMediaElement.prototype.play = function () { window.contentPlayCalls++; return Promise.resolve(); };
            HTMLMediaElement.prototype.pause = function () { window.contentPauseCalls++; };
        }
        class Manager {
            constructor() { this.events = {}; window.videoManager = this; }
            addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
            emit(name) { (this.events[name] || []).forEach(fn => fn()); }
            getCuePoints() { return []; }
            init(width, height) { window.managerInits++; this.dimensions = [width, height]; }
            setVolume() {}
            start() { window.adStarts++; this.emit('content-pause'); this.emit('started'); }
            resize(width, height) { window.managerResizes.push([width, height]); this.dimensions = [width, height]; }
            destroy() { window.adDestroys++; if (options.pauseOnDestroy) document.querySelector('video')?.pause(); }
        }
        window.installIma = () => { window.google = { ima: {
            AdDisplayContainer: class {
                constructor(layer) {
                    const iframe = document.createElement('iframe');
                    iframe.title = 'Deterministic IMA advertisement fixture';
                    iframe.style.cssText = 'display:block;width:100%;height:100%;border:0';
                    iframe.srcdoc = '<html><head><style>*{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden}body{display:grid;place-items:center;background:radial-gradient(ellipse at 75% 10%,#224b7a,transparent 65%),linear-gradient(150deg,#050b1e,#102b49);color:#f6f8ff;font:14px system-ui,sans-serif;text-align:center}small{display:block;margin-bottom:14px;color:#ffd66b;font-size:10px;letter-spacing:.18em}strong{font-weight:500;letter-spacing:.02em}span{display:block;margin-top:12px;color:#9da9c2;font-size:10px}</style></head><body><div><small>HORUS MEDIA</small><strong>Every story has a next chapter</strong><span>Local ad fixture · No live inventory</span></div></body></html>';
                    iframe.dataset.testIma = '1'; iframe.addEventListener('load', () => window.imaFrameLoads++);
                    layer.appendChild(iframe); this.frame = iframe;
                }
                initialize() {}
                destroy() { this.frame.remove(); }
            },
            AdsLoader: class {
                constructor() { this.events = {}; }
                addEventListener(name, callback) { this.events[name] = callback; }
                requestAds(request) {
                    window.adRequests++;
                    window.lastAdTagUrl = request.adTagUrl || null;
                    window.lastAdDimensions = [request.linearAdSlotWidth, request.linearAdSlotHeight];
                    this.events.manager({ getAdsManager: (_video, settings) => {
                        window.lastRenderingSettings = {
                            enablePreloading: settings.enablePreloading,
                            loadVideoTimeout: settings.loadVideoTimeout,
                            prerollLoadVideoTimeout: settings.prerollLoadVideoTimeout,
                        };
                        return new Manager();
                    } });
                }
                destroy() {}
                contentComplete() {}
            },
            AdsRequest: class { setAdWillAutoPlay() {} setAdWillPlayMuted() {} setContinuousPlayback() {} },
            AdsRenderingSettings: class {}, AdsManagerLoadedEvent: { Type: { ADS_MANAGER_LOADED: 'manager' } },
            AdErrorEvent: { Type: { AD_ERROR: 'ad-error' } }, ViewMode: { NORMAL: 'normal' },
            AdEvent: { Type: { LOADED: 'loaded', STARTED: 'started', COMPLETE: 'complete', SKIPPED: 'skipped', ALL_ADS_COMPLETED: 'all-completed', CONTENT_PAUSE_REQUESTED: 'content-pause', CONTENT_RESUME_REQUESTED: 'content-resume' } },
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
        if (options.content) attributes['data-hm-video-content-url'] = 'https://reader.example/content.mp4';
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

test('records inline visibility before a delayed IMA SDK and starts once after floating', async ({ page }) => {
    await openPlayer(page, { delayedSdk: true, vastUrl: 'https://pubads.g.doubleclick.net/gampad/ads?iu=/123/video&sz=300x250%7C640x480' });
    await expectEnlargedInlineGeometry(page, [320, 180]);
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate(el => el.__hmVideoPlayer.wasInlineVisible)).toBe(true);
    await page.evaluate(() => window.scrollTo(0, 1800));
    await assertFloating(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await page.evaluate(() => { window.installIma(); document.querySelector('[data-hm-ima-sdk]').dispatchEvent(new Event('load')); });
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    await expectCompactFloatingGeometry(page, [320, 180]);
    const request = await page.evaluate(() => {
        const box = document.querySelector('[data-hm-video-direct]').getBoundingClientRect();
        return { tag: window.lastAdTagUrl, size: window.lastAdDimensions, actual: [Math.round(box.width), Math.round(box.height)] };
    });
    expect(request.size).toEqual(request.actual);
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

test('real decoded content keeps its source and playback time across both transition directions', async ({ page }, testInfo) => {
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
        await assertFloating(page);
        await expect.poll(() => page.locator('video').evaluate(video => video.currentTime)).toBeGreaterThan(previousTime + 0.15);
        if (cycle === 0) await attachLayout(page, testInfo, 'decoded-content-floating');
        previousTime = await page.locator('video').evaluate(video => video.currentTime);
        await scrollPage(page, 0);
        await expectInline(page, original);
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
    expect(request.size).toEqual(request.actual);
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
