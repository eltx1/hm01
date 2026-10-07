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
const videoRuntime = await readFile(process.env.HORUS_VIDEO_RUNTIME_PATH || new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');
const sdk = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
const internalSdk = 'https://securepubads.g.doubleclick.net/pagead/managed/js/gpt/offline/pubads_impl.js';

function installGptFixture() {
    // Only the provider boundary is fake. Both Horus adapters and the Loader,
    // its real gate frame, Click Guard, layout and lazy eligibility run intact.
    const queue = window.googletag?.cmd || [], slots = new Map(), listeners = new Map();
    const pubads = { addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); } };
    const emit = (name, event) => [...(listeners.get(name) || [])].forEach(fn => fn(event));
    window.googletag = { apiReady: true, cmd: { push(fn) { fn(); } }, pubads: () => pubads,
        defineSlot(path, sizes, id) { const slot = { id, addService() { return this; } }; slots.set(id, slot); return slot; },
        enableServices() {}, destroySlots() {}, display(id) {
            window.dependencyMetrics.requests++;
            window.dependencyMetrics.requestAt = performance.now();
            const event = { slot: slots.get(id), isEmpty: false, size: [300, 250] };
            emit('slotRequested', event);
            queueMicrotask(() => { emit('slotResponseReceived', event); emit('slotRenderEnded', event); emit('slotOnload', event); });
        },
    };
    window.dependencyMetrics.readyAt = performance.now();
    queue.forEach(fn => fn());
}

async function open(page, options = {}) {
    const settings = { content: true, deferManager: true, ...options };
    const config = securityConfig(settings);
    config.placements.push({ code: 'display', type: options.autoMount ? 'STICKY' : 'DISPLAY', enabled: true, status: 'active',
        renderer: 'DIRECT_JS', sizes: [[300, 250]], lazyLoad: { enabled: !!options.belowFoldGpt, fetchMarginPercent: 0 },
        format: { settings: { autoMount: !!options.autoMount, autoMountTarget: 'body_end', position: 'bottom' } } });
    config.directDemand.placements.display = { enabled: true, candidates: [{ network: 'TEST_GPT', tag: {
        executionMode: 'STRUCTURED', scripts: [{ url: 'https://cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.0123456789abcdef.js' }],
        container: { element: 'div', id: 'display-runtime', attributes: { 'data-hm-gpt-direct': '1',
            'data-hm-gpt-ad-unit-path': '/123/display', 'data-hm-gpt-inner-id': 'display-provider', 'data-hm-gpt-sizes': '[[300,250]]' } },
        initialization: { type: 'NONE' },
        render: { timeoutMs: 20000, successSelector: '#display-runtime[data-hm-gpt-runtime-state="rendered"]' },
    } }] };
    const counts = { verifies: 0, gpt: 0, internal: 0 };
    let release; const hold = new Promise(resolve => { release = resolve; });
    if (!options.delay) release();
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        const headers = { 'Cache-Control': 'public,max-age=3600', 'Access-Control-Allow-Origin': '*' };
        if (url.origin === 'https://reader.example') return route.fulfill({ contentType: 'text/html', body: publisherPage(settings) });
        if (url.href === sdk) {
            counts.gpt++;
            return route.fulfill({ headers, contentType: 'application/javascript', body:
                'window.dependencyMetrics.sdkExecutions++;var s=document.createElement("script");s.async=true;s.src=' + JSON.stringify(internalSdk) + ';document.head.appendChild(s);' });
        }
        if (url.href === internalSdk) {
            counts.internal++;
            if (options.delay === 'internal') await hold;
            return route.fulfill({ headers, contentType: 'application/javascript', body: '(' + installGptFixture.toString() + ')();' });
        }
        if (url.origin === 'https://cdn.horusmedia.net') {
            if (url.pathname.includes('/runtime/gpt/')) {
                if (options.delay === 'adapter') await hold;
                return route.fulfill({ headers, contentType: 'application/javascript', body: gptRuntime });
            }
            if (url.pathname.includes('/runtime/video/')) {
                if (options.delay === 'video') await hold;
                return route.fulfill({ headers, contentType: 'application/javascript', body: videoRuntime });
            }
            return route.fulfill({ headers, json: url.pathname.includes('_global') ? { controls: {} } : config });
        }
        if (url.origin === 'https://verify.horusmedia.net') {
            if (url.pathname.includes('/configs/')) return route.fulfill({ json: config });
            return route.fulfill(url.pathname.endsWith('.js') ? { contentType: 'application/javascript', body: gateJs } : { contentType: 'text/html', body: gateHtml });
        }
        if (url.origin === 'https://challenges.cloudflare.com') return route.fulfill({ contentType: 'application/javascript',
            body: 'window.turnstile={render(n,o){queueMicrotask(()=>o.callback("fixture-token"));return "fixture";},remove(){},reset(){}};' });
        if (url.origin === 'https://siteverify.horusmedia.net') {
            const cors = { 'Access-Control-Allow-Origin': 'https://verify.horusmedia.net', 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type' };
            if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
            counts.verifies++; if (options.delay === 'verification') await hold;
            return route.fulfill({ status: options.denied ? 422 : 200, headers: cors,
                json: { success: !options.denied, pageNonce: request.postDataJSON().pageNonce } });
        }
        if (url.origin === 'https://imasdk.googleapis.com') return route.fulfill({ headers, contentType: 'application/javascript', body: imaFixture(settings) });
        if (url.origin === 'https://creative.example') return route.fulfill({ contentType: 'text/html', body: '<html><body>Offline fixture</body></html>' });
        // Never transmit paid auctions, impressions, clicks or arbitrary traffic.
        return route.abort('blockedbyclient');
    });
    await page.goto('https://reader.example/article');
    await page.evaluate(initializeTestMedia, settings);
    await page.evaluate(options => {
        window.dependencyMetrics = { sdkExecutions: 0, requests: 0, requestAt: null, readyAt: null };
        if (!options.autoMount) {
            const node = document.createElement('div'); node.className = 'hm-ad'; node.dataset.placement = 'display';
            node.style.cssText = 'width:320px;min-height:250px;';
            if (options.belowFoldGpt) node.style.marginTop = '20000px';
            document.querySelector('[data-placement]').after(node);
        }
    }, options);
    await page.addScriptTag({ content: options.minified ? minified : composed });
    await page.evaluate(() => { window.HorusMediaLoader.boot({ script: document.getElementById('loader') }); });
    return { counts, release };
}

for (const mode of ['composed', 'minified']) {
    test(`${mode}: GPT internal dependency starts while its Horus adapter is held`, async ({ page }) => {
        const run = await open(page, { delay: 'adapter', minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe('PASSED');
        // Old runtime fails here: preloading GPT is not executing it or fetching
        // its internal SDK. No ad may be requested by the missing adapter.
        await expect.poll(() => run.counts.internal).toBe(1);
        await expect.poll(() => page.evaluate(() => !!window.googletag?.apiReady)).toBe(true);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(0);
        expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.dependencyMetrics.sdkExecutions)).toBe(1);
        expect(run.counts.gpt).toBe(1);
        expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
    });

    test(`${mode}: automatic display starts with video entirely held`, async ({ page }) => {
        const run = await open(page, { delay: 'video', autoMount: true, quickEmbed: true, emptyDiv: true, minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
    });

    test(`${mode}: held GPT internal SDK does not block video`, async ({ page }) => {
        const run = await open(page, { delay: 'internal', deferManager: false, minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(0);
        await expect.poll(() => run.counts.internal).toBe(1);
        run.release();
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
    });

    test(`${mode}: delayed VAST 303 never gates independent display`, async ({ page }) => {
        await open(page, { minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.videoMetrics.contentPlays)).toBe(0);
        await page.evaluate(() => window.videoLoaders[0].events.error({ getError: () => ({ message: 'No ads after VAST wrappers',
            getErrorCode: () => 303, getVastErrorCode: () => 303 }) }));
        const video = page.locator('[data-hm-video-direct]');
        await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(2);
        expect(await page.evaluate(() => window.videoMetrics.contentPlays)).toBe(0);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        await expect(video).toHaveAttribute('data-hm-video-status', 'requesting-preroll');
        await page.evaluate(() => window.videoLoaders[1].events.error({ getError: () => ({ message: 'No ads after retry',
            getErrorCode: () => 303, getVastErrorCode: () => 303 }) }));
        await expect(video).toHaveAttribute('data-hm-video-status', 'content-playing');
        await expect(video).toHaveAttribute('data-hm-video-error-code', '303');
        await expect(video).toHaveAttribute('data-hm-video-error-stage', 'request-preroll');
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(2);
    });

    test(`${mode}: preload does not execute GPT or internal SDK before PASS`, async ({ page }) => {
        const run = await open(page, { delay: 'verification', minified: mode === 'minified' });
        await expect.poll(() => run.counts.verifies).toBe(1);
        await expect.poll(() => run.counts.gpt).toBe(1);
        expect(run.counts.internal).toBe(0);
        expect(await page.evaluate(() => window.dependencyMetrics.sdkExecutions)).toBe(0);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(0);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.dependencyMetrics.sdkExecutions)).toBe(1);
        expect(run.counts.gpt).toBe(1);
        await expect(page.locator('script[src="' + sdk + '"]')).toHaveCount(1);
    });

    test(`${mode}: lazy GPT retains its own below-fold eligibility`, async ({ page }) => {
        await open(page, { belowFoldGpt: true, minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        expect(await page.evaluate(() => window.dependencyMetrics.sdkExecutions)).toBe(0);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(0);
        await page.locator('[data-placement="display"]').scrollIntoViewIfNeeded();
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
    });
}

for (const mode of ['composed', 'minified']) {
    test(`${mode}: local startup trace separates token, verified pass and actual display request while video is held`, async ({ page }) => {
        const run = await open(page, { delay: 'video', minified: mode === 'minified' });
        await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getStartupTrace().events.some(e => e.phase === 'GPT onload'))).toBe(true);
        const trace = await page.evaluate(() => window.HorusMediaLoader.getStartupTrace());
        const phases = trace.events.map(e => e.phase);
        for (const name of ['Horus init','CFG ready','CF start','CF token','CF verify','CF pass','Horus start','GPT call','GPT request','GPT response','GPT render','GPT onload']) expect(phases).toContain(name);
        for (const [a,b] of [['CF token','CF verify'],['CF verify','CF pass'],['CF pass','Horus start'],['Horus start','GPT request'],['GPT request','GPT response'],['GPT response','GPT render'],['GPT render','GPT onload']]) {
            expect(phases.indexOf(a)).toBeLessThan(phases.indexOf(b));
        }
        expect(phases).not.toContain('Video start');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        const events = trace.events.filter(e => ['GPT call','GPT request','GPT response','GPT render','GPT onload'].includes(e.phase));
        expect(new Set(events.map(e => e.slot)).size).toBe(1);
        expect(JSON.stringify(trace)).not.toContain('fixture-token');
        expect(JSON.stringify(trace)).not.toContain('pageNonce');
        expect(JSON.stringify(trace)).not.toContain('http');
        run.release();
    });
    test(`${mode}: no local PASS or monetization event before server verification`, async ({ page }) => {
        const run = await open(page, { delay: 'verification', minified: mode === 'minified' });
        await expect.poll(() => run.counts.verifies).toBe(1);
        const phases = await page.evaluate(() => window.HorusMediaLoader.getStartupTrace().events.map(e => e.phase));
        expect(phases).toContain('CF token'); expect(phases).toContain('CF verify');
        for (const name of ['CF pass','Horus start','GPT request','VAST call']) expect(phases).not.toContain(name);
        expect(await page.evaluate(() => window.dependencyMetrics.requests)).toBe(0);
        run.release();
        await expect.poll(() => page.evaluate(() => window.dependencyMetrics.requests)).toBe(1);
    });
}
