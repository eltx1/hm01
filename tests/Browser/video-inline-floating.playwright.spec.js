import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const loader = applyPlacementPresetTransform(applyShadowClickGuardTransform(applyTrafficGateTransform(await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8'))));

// Exercise the real loader + video DOM with a deterministic IMA boundary.
// No live auctions, impression pixels, credentials, or paid inventory are used.
async function openPlayer(page, options = {}) {
    await page.route('https://reader.example/**', route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/player.js') return route.fulfill({ contentType: 'application/javascript', body: runtime });
        if (path === '/ad.js') return route.fulfill({ contentType: 'application/javascript', body: '' });
        if (path === '/content.mp4') return route.fulfill({ status: 404, body: '' });
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
            html,body{margin:0;overflow-anchor:none} article{max-width:640px;margin:0 auto} #before{height:${options.belowFold ? 1600 : 80}px} #tail{height:4000px}
            [data-placement="video"]{width:320px;max-width:100%;min-height:180px} ${options.transformed ? 'article{transform:translateZ(0);contain:paint;overflow:hidden}' : ''}
            </style></head><body><article><div id="before"></div><div class="hm-ad" data-placement="video"></div><div id="tail">Publisher article</div></article>
            <script id="loader" data-site-key="VIDEO_LAYOUT" data-config-version="1"></script></body></html>` });
    });
    await page.goto('https://reader.example/article');
    await page.evaluate(options => {
        window.__HM_DISABLE_AUTOBOOT__ = true;
        window.adRequests = 0; window.adStarts = 0; window.adDestroys = 0; window.imaFrameLoads = 0;
        HTMLMediaElement.prototype.play = function () { return Promise.resolve(); };
        HTMLMediaElement.prototype.pause = function () {};
        class Manager {
            constructor() { this.events = {}; window.videoManager = this; }
            addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
            emit(name) { (this.events[name] || []).forEach(fn => fn()); }
            getCuePoints() { return []; }
            init(width, height) { this.dimensions = [width, height]; }
            setVolume() {}
            start() { window.adStarts++; this.emit('content-pause'); this.emit('started'); }
            resize(width, height) { this.dimensions = [width, height]; }
            destroy() { window.adDestroys++; }
        }
        window.installIma = () => { window.google = { ima: {
            AdDisplayContainer: class {
                constructor(layer) {
                    const iframe = document.createElement('iframe'); iframe.srcdoc = '<html><body>SDK fixture</body></html>';
                    iframe.dataset.testIma = '1'; iframe.addEventListener('load', () => window.imaFrameLoads++);
                    layer.appendChild(iframe); this.frame = iframe;
                }
                initialize() {}
                destroy() { this.frame.remove(); }
            },
            AdsLoader: class {
                constructor() { this.events = {}; }
                addEventListener(name, callback) { this.events[name] = callback; }
                requestAds() { window.adRequests++; this.events.manager({ getAdsManager: () => new Manager() }); }
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
            'data-hm-video-direct': '1', 'data-hm-video-width': '320', 'data-hm-video-height': '180',
            'data-hm-vast-url': btoa('https://ads.example/vast'),
        };
        // Deliberately omit the child floating attribute: cached pre-fix recipes
        // must still work through the loader's authoritative placement metadata.
        if (options.content) attributes['data-hm-video-content-url'] = 'https://reader.example/content.mp4';
        const videoSettings = { autoMount: false, position: options.inlineOnly ? 'inline' : 'inline_to_bottom_right', floatingPosition: options.inlineOnly ? null : 'bottom_right', closeable: true, closeOutside: true };
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
            placements: placements.map(p => ({ ...p, enabled: true, status: 'active', renderer: 'DIRECT_JS', sizes: [[320, 180]] })),
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
}

async function assertFloating(page) {
    const surface = page.locator('[data-placement="video"]');
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
    await expect.poll(async () => surface.evaluate(el => {
        const r = el.getBoundingClientRect();
        return getComputedStyle(el).position === 'fixed' && r.top >= 0 && r.bottom <= innerHeight && r.width > 0 && r.height > 0;
    })).toBe(true);
    return surface;
}

for (const options of [{}, { content: true }, { noObserver: true }, { transformed: true }, { transformed: true, content: true }]) {
    test(`inline to floating keeps the playing ad and iframe: ${JSON.stringify(options)}`, async ({ page }) => {
        await openPlayer(page, options);
        await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
        await expect.poll(() => page.evaluate(() => window.imaFrameLoads)).toBe(1);
        await page.evaluate(() => { window.originalFrame = document.querySelector('[data-test-ima]'); window.originalVideo = document.querySelector('video'); window.scrollTo(0, 1800); });
        const surface = await assertFloating(page);
        expect(await page.evaluate(() => window.adRequests)).toBe(1);
        expect(await page.evaluate(() => window.adDestroys)).toBe(0);
        expect(await page.evaluate(() => document.querySelector('[data-test-ima]') === window.originalFrame && document.querySelector('video') === window.originalVideo)).toBe(true);
        await page.evaluate(() => window.scrollTo(0, 2500));
        await assertFloating(page);
        expect(await page.evaluate(() => window.imaFrameLoads)).toBe(1);
        await surface.locator('[data-hm-placement-close]').click();
        await expect(surface).toBeHidden();
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
        await page.evaluate(() => window.scrollTo(0, 0));
        expect(await page.evaluate(() => window.adRequests)).toBe(1);
    });
}

test('records inline visibility before a delayed IMA SDK and starts once after floating', async ({ page }) => {
    await openPlayer(page, { delayedSdk: true });
    await expect.poll(() => page.locator('[data-hm-video-direct]').evaluate(el => el.__hmVideoPlayer.wasInlineVisible)).toBe(true);
    await page.evaluate(() => window.scrollTo(0, 1800));
    await assertFloating(page);
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
    await page.evaluate(() => { window.installIma(); document.querySelector('[data-hm-ima-sdk]').dispatchEvent(new Event('load')); });
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
});

test('inline-only inventory does not float and an unseen placement never starts offscreen', async ({ page }) => {
    await openPlayer(page, { inlineOnly: true, content: true });
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    await page.evaluate(() => window.scrollTo(0, 1800));
    await expect(page.locator('[data-placement="video"]')).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
    await openPlayer(page, { belowFold: true, noObserver: true });
    await page.evaluate(() => window.scrollTo(0, 3000));
    await expect(page.locator('[data-placement="video"]')).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
    expect(await page.evaluate(() => window.adRequests)).toBe(0);
});

test('floating VAST stays above a dynamically resized sticky and both close controls work', async ({ page }) => {
    await openPlayer(page, { sticky: true, transformed: true });
    await expect.poll(() => page.evaluate(() => window.adStarts)).toBe(1);
    await page.evaluate(() => window.scrollTo(0, 1800));
    const video = await assertFloating(page), bottom = page.locator('[data-placement="bottom"]');
    const gap = async () => { const a = await bottom.boundingBox(), b = await video.boundingBox(); return a.y - b.y - b.height; };
    await expect.poll(gap).toBeGreaterThanOrEqual(15);
    await bottom.evaluate(el => { el.style.height = '130px'; });
    await expect.poll(gap).toBeGreaterThanOrEqual(15);
    await bottom.locator('[data-hm-placement-close]').click();
    await expect.poll(() => video.evaluate(el => parseFloat(getComputedStyle(el).bottom))).toBe(16);
    expect(await page.evaluate(() => window.adRequests)).toBe(1);
    expect(await page.evaluate(() => window.imaFrameLoads)).toBe(1);
    await video.locator('[data-hm-placement-close]').click();
    await expect(video).toBeHidden();
});
