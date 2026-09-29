import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';
import { applyVideoPreparationTransform } from '../../scripts/transform-loader-video-preparation.mjs';
import { securityConfig, publisherPage, initializeTestMedia, imaFixture } from './helpers/startup-preparation-fixture.js';

const source = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const composed = [applyTrafficGateTransform, applyShadowClickGuardTransform, applyPlacementPresetTransform,
    applyDirectPreparationTransform, applyVideoPreparationTransform].reduce((s, f) => f(s), source);
const minified = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const gptRuntime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');

function parallelDisplayConfig(config) {
    for (const code of ['parallel_display', 'late_display']) {
        config.placements.push({ code, type: 'DISPLAY', enabled: true, status: 'active', renderer: 'DIRECT_JS',
            sizes: [[300, 250]], lazyLoad: { enabled: false }, format: { settings: { autoMount: false } } });
        config.directDemand.placements[code] = { enabled: true, candidates: [{ network: 'TEST_GPT', tag: {
            executionMode: 'STRUCTURED', scripts: [{ url: 'https://cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.0123456789abcdef.js' }],
            container: { element: 'div', id: code + '-runtime', attributes: { 'data-hm-gpt-direct': '1',
                'data-hm-gpt-ad-unit-path': '/123/' + code, 'data-hm-gpt-sizes': '[[300,250]]', 'data-hm-gpt-inner-id': code + '-provider' } },
            initialization: { type: 'NONE' }, render: { timeoutMs: 20000, successSelector: '#' + code + '-runtime[data-hm-gpt-runtime-state="rendered"]' },
        } }] };
    }
}
function installGptFixture() {
    // Provider boundary only. The production Loader, GPT adapter, gates and
    // DOM observer run unchanged; no auction/tracking URL is ever requested.
    const queued = window.googletag?.cmd || [], slots = new Map(), listeners = new Set();
    const pubads = { addEventListener(name, fn) { if (name === 'slotRenderEnded') listeners.add(fn); },
        removeEventListener(name, fn) { listeners.delete(fn); } };
    window.googletag = { apiReady: true, cmd: { push(fn) { fn(); } }, pubads: () => pubads,
        defineSlot(path, sizes, id) { const slot = { id, addService() { return this; } }; slots.set(id, slot); return slot; },
        display(id) {
            window.displayMetrics.requests++; window.displayMetrics.ids.push(id);
            queueMicrotask(() => [...listeners].forEach(fn => fn({ slot: slots.get(id), isEmpty: false, size: [300, 250] })));
        }, enableServices() {}, destroySlots() {},
    };
    queued.forEach(fn => fn());
}

async function open(page, options = {}) {
    const config = securityConfig(options), counts = { runtime: 0, sdk: 0, verifies: 0 };
    if (options.parallel) parallelDisplayConfig(config);
    let release; const hold = new Promise(resolve => { release = resolve; });
    if (!options.delay) release();
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        const headers = { 'Cache-Control': 'public, max-age=3600' };
        if (url.origin === 'https://reader.example') return route.fulfill({ contentType: 'text/html', body: publisherPage(options) });
        if (url.origin === 'https://securepubads.g.doubleclick.net' && url.pathname === '/tag/js/gpt.js') {
            return route.fulfill({ headers, contentType: 'application/javascript', body: '(' + installGptFixture.toString() + ')();' });
        }
        if (url.origin === 'https://cdn.horusmedia.net') {
            if (url.pathname.includes('/runtime/gpt/')) return route.fulfill({ headers, contentType: 'application/javascript', body: gptRuntime });
            if (url.pathname.includes('/runtime/video/')) {
                counts.runtime++; if (options.delay === 'runtime') await hold;
                return route.fulfill({ headers, contentType: 'application/javascript', body: 'window.videoMetrics.runtimeExecutions++;\n' + runtime });
            }
            if (options.delay === 'config') await hold;
            return route.fulfill({ headers, json: url.pathname.includes('_global') ? { controls: {} } : config });
        }
        if (url.origin === 'https://verify.horusmedia.net') {
            if (url.pathname.includes('/configs/')) return route.fulfill({ json: config });
            return route.fulfill(url.pathname.includes('.js') ? { contentType: 'application/javascript', body: gateJs } : { contentType: 'text/html', body: gateHtml });
        }
        if (url.origin === 'https://challenges.cloudflare.com') return route.fulfill({ contentType: 'application/javascript', body: 'window.turnstile={render(n,o){queueMicrotask(()=>o.callback("test-token"));return "test";},remove(){},reset(){}};' });
        if (url.origin === 'https://siteverify.horusmedia.net') {
            const cors = { 'Access-Control-Allow-Origin': 'https://verify.horusmedia.net', 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type' };
            if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
            counts.verifies++; if (options.delay === 'verification') await hold;
            return route.fulfill({ status: options.denied ? 422 : 200, headers: cors, json: { success: !options.denied, pageNonce: request.postDataJSON().pageNonce } });
        }
        if (url.origin === 'https://imasdk.googleapis.com') {
            counts.sdk++; if (options.delay === 'sdk') await hold;
            return route.fulfill({ headers, contentType: 'application/javascript', body: imaFixture(options) });
        }
        if (url.origin === 'https://creative.example') return route.fulfill({ contentType: 'text/html', body: '<html><body>Offline creative</body></html>' });
        return route.abort('blockedbyclient');
    });
    await page.goto('https://reader.example/article');
    await page.evaluate(initializeTestMedia, options);
    await page.evaluate(parallel => {
        window.displayMetrics = { requests: 0, ids: [] };
        if (parallel) {
            const node = document.createElement('div'); node.className = 'hm-ad'; node.dataset.placement = 'parallel_display';
            node.style.width = '320px'; document.querySelector('[data-placement]').after(node);
        }
    }, options.parallel);
    if (options.noObserver) await page.evaluate(() => { window.IntersectionObserver = undefined; });
    await page.addScriptTag({ content: options.minified ? minified : composed });
    await page.evaluate(() => { window.HorusMediaLoader.boot({ script: document.getElementById('loader') }); });
    return { counts, release };
}

for (const mode of ['composed', 'minified']) for (const delay of ['config', 'verification', 'runtime', 'sdk']) {
    test(`${mode}: scroll before ${delay} readiness starts one floating ad without returning to the slot`, async ({ page }) => {
        const run = await open(page, { delay, transformed: true, minified: mode === 'minified' });
        const surface = page.locator('[data-placement="video"]');
        await expect.poll(() => surface.evaluate(el => !!el.__hmInlineVideoHistory?.wasInlineVisible || !!el.querySelector('[data-hm-video-direct]')?.__hmVideoPlayer?.wasInlineVisible)).toBe(true);
        await page.evaluate(() => window.scrollTo(0, 1800));
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        expect(run.counts.verifies).toBe(1);
        await page.evaluate(() => { window.originalFrame = document.querySelector('[data-test-ima]'); window.scrollTo(0, 2600); });
        expect(await page.evaluate(() => document.querySelector('[data-test-ima]') === window.originalFrame)).toBe(true);
        await surface.locator('[data-hm-placement-close]').click();
        await expect(surface).toBeHidden();
        await expect(page.locator('[data-hm-video-placeholder]')).toHaveCount(0);
    });
}
for (const options of [{ content: true }, { content: true, contentFailure: true }, { noObserver: true }, { emptyDiv: true }]) {
    test(`early scroll keeps content fail-open and observer fallback: ${JSON.stringify(options)}`, async ({ page }) => {
        const run = await open(page, { ...options, delay: 'verification' });
        await expect.poll(() => page.locator('[data-placement="video"]').evaluate(el => !!(el.__hmInlineVideoHistory?.wasInlineVisible || el.__hmInlineVideoHistory?.anchorWasVisible))).toBe(true);
        await page.evaluate(() => window.scrollTo(0, 1800)); run.release();
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(1);
    });
}
test('preload overlaps verification but executes neither runtime nor IMA until verified', async ({ page }) => {
    const run = await open(page, { delay: 'verification' });
    await expect.poll(() => run.counts.runtime).toBeGreaterThan(0);
    await expect.poll(() => run.counts.sdk).toBeGreaterThan(0);
    expect(await page.evaluate(() => window.videoMetrics.runtimeExecutions)).toBe(0);
    expect(await page.evaluate(() => window.videoMetrics.sdkExecutions)).toBe(0);
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
    run.release(); await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    expect(await page.evaluate(() => window.videoMetrics.runtimeExecutions)).toBe(1);
    expect(await page.evaluate(() => window.videoMetrics.sdkExecutions)).toBe(1);
});
for (const options of [{ denied: true }, { blockedInitially: true }]) {
    test(`early scroll cannot authorize against gate/Click Guard: ${JSON.stringify(options)}`, async ({ page }) => {
        const run = await open(page, { ...options, parallel: true, delay: 'verification' });
        await expect.poll(() => run.counts.verifies).toBe(1);
        await page.evaluate(() => window.scrollTo(0, 1800)); run.release();
        await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe(options.denied ? 'ERROR' : 'PASSED');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        expect(await page.evaluate(() => window.videoMetrics.runtimeExecutions)).toBe(0);
        expect(await page.evaluate(() => window.displayMetrics.requests)).toBe(0);
    });
}
for (const options of [{ belowFold: true }, { inlineOnly: true }]) {
    test(`never float an unseen or inline-only slot: ${JSON.stringify(options)}`, async ({ page }) => {
        const run = await open(page, { ...options, parallel: true, delay: 'verification' });
        await expect.poll(() => run.counts.verifies).toBe(1);
        await page.evaluate(() => window.scrollTo(0, 3000)); run.release();
        await expect(page.locator('[data-hm-video-direct]')).toHaveAttribute('data-hm-video-runtime-state', /.+/);
        await expect(page.locator('[data-placement="video"]')).not.toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
    });
}
test('a delayed manager starts in the existing floated player, not a second player', async ({ page }) => {
    await open(page, { deferManager: true });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
    await page.evaluate(() => { window.originalFrame = document.querySelector('[data-test-ima]'); window.scrollTo(0, 1800); });
    await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'floating');
    await page.evaluate(() => window.videoLoaders[0].deliver());
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    expect(await page.evaluate(() => document.querySelector('[data-test-ima]') === window.originalFrame)).toBe(true);
});


test('the publisher permanent empty DIV preserves a pre-config scroll', async ({ page }) => {
    const run = await open(page, { delay: 'config', quickEmbed: true, emptyDiv: true });
    const surface = page.locator('[data-placement="quick_video_floating"]');
    await expect.poll(() => surface.evaluate(el => el.__hmInlineVideoHistory?.anchorWasVisible)).toBe(true);
    expect(await surface.evaluate(el => el.getBoundingClientRect().height)).toBeLessThanOrEqual(1);
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
    await page.evaluate(() => window.scrollTo(0, 1800)); run.release();
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(1);
});

test('a denied page cleans up the empty Quick embed without running any ad library', async ({ page }) => {
    const run = await open(page, { delay: 'verification', quickEmbed: true, emptyDiv: true, denied: true });
    await expect.poll(() => run.counts.verifies).toBe(1); run.release();
    await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe('ERROR');
    expect(await page.evaluate(() => window.videoMetrics.runtimeExecutions)).toBe(0);
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
});

for (const mode of ['composed', 'minified']) {
    test(`${mode}: identical config refetch during verification retains early scroll history`, async ({ page }) => {
        const run = await open(page, { delay: 'verification', transformed: true, minified: mode === 'minified' });
        const surface = page.locator('[data-placement="video"]');
        await expect.poll(() => run.counts.verifies).toBe(1);
        await expect.poll(() => surface.evaluate(el => el.__hmInlineVideoHistory?.wasInlineVisible)).toBe(true);
        await page.evaluate(() => { window.startupSnapshot = window.__HORUS_MEDIA_LOADER_STATE__.config; window.scrollTo(0, 1800); });
        await expect.poll(() => surface.evaluate(el => el.__hmInlineVideoHistory?.scrolled)).toBe(true);
        await page.evaluate(() => { window.HorusMediaLoader.boot({ script: document.getElementById('loader'), force: true }); });
        await expect.poll(() => page.evaluate(() => window.__HORUS_MEDIA_LOADER_STATE__.config !== window.startupSnapshot)).toBe(true);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        expect(run.counts.verifies).toBe(1);
    });
}


for (const mode of ['composed', 'minified']) for (const delay of ['runtime', 'sdk']) {
    test(`${mode}: independent display starts while video ${delay} is pending, even during continuous DOM activity`, async ({ page }) => {
        const run = await open(page, { parallel: true, delay, minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.displayMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe('PASSED');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        await page.evaluate(() => {
            window.domBusyStill = true;
            const pulse = document.createElement('span'); document.getElementById('tail').appendChild(pulse);
            let counter = 0; window.domBusyTimer = setInterval(() => { pulse.textContent = String(counter++); }, 8);
            window.domBusyStop = setTimeout(() => { clearInterval(window.domBusyTimer); window.domBusyStill = false; }, 2500);
            const node = document.createElement('div'); node.className = 'hm-ad'; node.dataset.placement = 'late_display';
            node.style.width = '320px'; document.querySelector('[data-placement]').after(node);
        });
        await expect.poll(() => page.evaluate(() => window.displayMetrics.requests)).toBe(2);
        expect(await page.evaluate(() => window.domBusyStill)).toBe(true);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        await page.evaluate(() => { clearInterval(window.domBusyTimer); clearTimeout(window.domBusyStop); });
        run.release(); await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        expect(await page.evaluate(() => window.displayMetrics.ids)).toEqual(['parallel_display-runtime', 'late_display-runtime']);
        expect(run.counts.verifies).toBe(1);
    });
}
for (const mode of ['composed', 'minified']) {
    test(`${mode}: collapsed Quick video anchor reserves no blank space while verification is pending`, async ({ page }) => {
        const run = await open(page, { parallel: true, quickEmbed: true, emptyDiv: true, delay: 'verification', minified: mode === 'minified' });
        await expect.poll(() => run.counts.verifies).toBe(1);
        const surface = page.locator('[data-placement="quick_video_floating"]');
        expect(await surface.evaluate(el => el.getBoundingClientRect().height)).toBeLessThanOrEqual(1);
        expect(await page.evaluate(() => window.displayMetrics.requests)).toBe(0);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        await page.evaluate(() => window.scrollTo(0, 1800)); run.release();
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        await expect.poll(() => page.evaluate(() => window.displayMetrics.requests)).toBe(1);
        await expect(surface).toHaveAttribute('data-hm-video-floating-state', 'floating');
    });
}
