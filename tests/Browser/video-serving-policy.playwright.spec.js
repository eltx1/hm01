import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { securityConfig, publisherPage, initializeTestMedia, imaFixture } from './helpers/video-serving-policy-fixture.js';

const loader = applyPlacementPresetTransform(applyShadowClickGuardTransform(applyTrafficGateTransform(await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8'))));
const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');

async function openSecurityPlayer(page, options = {}) {
    const config = securityConfig(options);
    const counts = { sdk: 0, verifies: 0, runtime: 0 };
    let releaseVerification, releaseSdk;
    const verification = new Promise(resolve => { releaseVerification = resolve; });
    const sdk = new Promise(resolve => { releaseSdk = resolve; });
    if (!options.delayVerification) releaseVerification();
    if (!options.delaySdk) releaseSdk();
    await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.origin === 'https://reader.example') {
            if (url.pathname === '/player.js') { counts.runtime++; return route.fulfill({ contentType: 'application/javascript', body: runtime }); }
            return route.fulfill({ contentType: 'text/html', body: publisherPage(options) });
        }
        if (url.origin === 'https://cdn.horusmedia.net') return route.fulfill({ json: url.pathname.includes('control') ? { adServingDisabled: false } : config });
        if (url.origin === 'https://verify.horusmedia.net') {
            if (url.pathname.includes('/configs/')) return route.fulfill({ json: config });
            return route.fulfill(url.pathname.includes('.js') ? { contentType: 'application/javascript', body: gateJs } : { contentType: 'text/html', body: gateHtml });
        }
        if (url.origin === 'https://challenges.cloudflare.com') return route.fulfill({ contentType: 'application/javascript', body: `window.turnstile={render(node,options){${options.timeout ? '' : 'queueMicrotask(()=>options.callback("test-token"));'}return 'test';},remove(){},reset(){}};` });
        if (url.origin === 'https://siteverify.horusmedia.net') {
            const headers = { 'Access-Control-Allow-Origin': 'https://verify.horusmedia.net', 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type' };
            if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
            counts.verifies++; await verification;
            return route.fulfill({ status: options.serverDeny ? 422 : 200, headers, json: { success: !options.serverDeny, pageNonce: request.postDataJSON().pageNonce } });
        }
        if (url.origin === 'https://imasdk.googleapis.com') {
            counts.sdk++; await sdk;
            return route.fulfill({ contentType: 'application/javascript', body: imaFixture(options) });
        }
        if (url.origin === 'https://creative.example') return route.fulfill({ contentType: 'text/html', body: '<html><body>Offline SDK fixture</body></html>' });
        return route.abort('blockedbyclient');
    });
    await page.goto('https://reader.example/article');
    await page.evaluate(initializeTestMedia, options);
    await page.addScriptTag({ content: loader });
    await page.evaluate(() => { window.HorusMediaLoader.boot({ script: document.getElementById('loader') }); });
    if (!options.waiting) await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-runtime-state', /.+/);
    return { counts, releaseVerification, releaseSdk };
}

async function storeDeny(page, notify = false) {
    await page.evaluate(notify => {
        const key = 'hm:click-guard:v2:VIDEO_SECURITY_MATRIX';
        const value = JSON.stringify({ v: 2, clicks: [], blockedUntil: Date.now() + 3600000 });
        localStorage.setItem(key, value);
        if (notify) window.dispatchEvent(new StorageEvent('storage', { key, newValue: value }));
    }, notify);
}

for (const options of [{ serverDeny: true }, { timeout: true }, { blockedInitially: true }]) {
    test(`no ad runtime or SDK before central admission: ${JSON.stringify(options)}`, async ({ page }) => {
        const { counts } = await openSecurityPlayer(page, { ...options, waiting: true });
        await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe(options.timeout ? 'TIMEOUT' : options.serverDeny ? 'ERROR' : 'PASSED');
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
        expect(counts.runtime).toBe(0); expect(counts.sdk).toBe(0);
    });
}

test('forged PASS and notification payloads cannot unlock pending Cloudflare verification', async ({ page }) => {
    const run = await openSecurityPlayer(page, { delayVerification: true, waiting: true });
    await expect.poll(() => run.counts.verifies).toBe(1);
    await page.evaluate(() => {
        window.postMessage({ type: 'HORUS_TRAFFIC_GATE_PASS', protocolVersion: 2, pageNonce: window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.pageNonce, serverVerified: true }, '*');
        window.dispatchEvent(new CustomEvent('horus:serving-policy-change', { detail: { allowed: true } }));
    });
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
    expect(run.counts.sdk).toBe(0);
    run.releaseVerification();
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
});

for (const options of [{}, { content: true }, { content: true, contentFailure: true }]) {
    test(`VAST and Floating survive allowed content availability variations: ${JSON.stringify(options)}`, async ({ page }) => {
        const run = await openSecurityPlayer(page, { ...options, transformed: true });
        await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
        await page.evaluate(() => { window.originalAd = document.querySelector('[data-test-ima]'); window.scrollTo(0, 1800); });
        await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'floating');
        expect(await page.evaluate(() => window.originalAd === document.querySelector('[data-test-ima]'))).toBe(true);
        expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        expect(run.counts.verifies).toBe(1);
    });
}

test('same-page probable click blocks VMAP immediately, preserves content and ignores stale callbacks', async ({ page }) => {
    await openSecurityPlayer(page, { content: true, vmap: true, transformed: true });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    await expect.poll(() => page.evaluate(() => window.__HORUS_MEDIA_LOADER_STATE__.clickGuard.trackedIframeEntries.length)).toBe(1);
    await page.evaluate(() => window.scrollTo(0, 1800));
    await expect(page.locator('[data-placement="video"]')).toHaveAttribute('data-hm-video-floating-state', 'floating');
    await page.evaluate(() => {
        const frame = document.querySelector('[data-test-ima]');
        frame.dispatchEvent(new PointerEvent('pointerenter')); frame.focus();
        window.dispatchEvent(new Event('blur'));
    });
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-policy-state', 'blocked');
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
    await page.evaluate(() => {
        const video = document.querySelector('video'); video.currentTime = 70; video.dispatchEvent(new Event('timeupdate'));
        window.videoManagers[0].emit('ready'); window.videoManagers[0].emit('all-completed');
        window.dispatchEvent(new CustomEvent('horus:serving-policy-change', { detail: { allowed: true } }));
    });
    const metrics = await page.evaluate(() => window.videoMetrics);
    expect(metrics.requests).toBe(1); expect(metrics.starts).toBe(1);
    expect(metrics.internalRequests).toBe(0); expect(metrics.completions).toBe(0);
    await page.evaluate(() => document.querySelector('video').dispatchEvent(new Event('ended')));
    expect(await page.evaluate(() => window.videoMetrics.internalRequests)).toBe(0);
});

test('storage denial before delayed IMA arrival makes no VAST request', async ({ page }) => {
    const run = await openSecurityPlayer(page, { delaySdk: true });
    await storeDeny(page, true); run.releaseSdk();
    await expect(page.locator('[data-placement="video"]')).toBeHidden();
    expect(await page.evaluate(() => window.videoMetrics.requests)).toBe(0);
});

for (const content of [false, true]) {
    test(`deferred SDK response is checked again after an unnotified storage change: content=${content}`, async ({ page }) => {
        await openSecurityPlayer(page, { content, deferManager: true });
        await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
        await storeDeny(page);
        await page.evaluate(() => window.videoLoaders[0].deliver());
        expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
    });
}

test('viewability release rechecks policy instead of trusting the earlier request', async ({ page }) => {
    await openSecurityPlayer(page, { deferManager: true, inlineOnly: true });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.requests)).toBe(1);
    await page.evaluate(() => window.scrollTo(0, 1800));
    await expect.poll(() => page.locator('#video-runtime').evaluate(el => el.__hmVideoPlayer.visibleRatio)).toBe(0);
    await page.evaluate(() => window.videoLoaders[0].deliver());
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'waiting-ad-viewability');
    await storeDeny(page);
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(page.locator('[data-placement="video"]')).toBeHidden();
    expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
});

test('manual pre/mid/post use the central decision but do not repeat Turnstile', async ({ page }) => {
    const run = await openSecurityPlayer(page, { content: true });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    await page.evaluate(() => window.videoManagers[0].emit('all-completed'));
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
    await page.evaluate(() => { const video = document.querySelector('video'); video.currentTime = 70; video.dispatchEvent(new Event('timeupdate')); });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(2);
    await page.evaluate(() => window.videoManagers[1].emit('all-completed'));
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-status', 'content-playing');
    await page.evaluate(() => document.querySelector('video').dispatchEvent(new Event('ended')));
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(3);
    expect(run.counts.verifies).toBe(1);
});

for (const change of ['kill-switch', 'privacy', 'gate-denied', 'placement-disabled']) {
    test(`a later central revocation also blocks VMAP: ${change}`, async ({ page }) => {
        await openSecurityPlayer(page, { content: true, vmap: true, deferBreak: true });
        await expect.poll(() => page.evaluate(() => window.videoManagers.length)).toBe(1);
        await page.evaluate(change => {
            const state = window.__HORUS_MEDIA_LOADER_STATE__;
            if (change === 'kill-switch') state.config.controls.adServingDisabled = true;
            if (change === 'privacy') state.privacyDecision.blocked = true;
            if (change === 'gate-denied') state.trafficGate.status = 'BLOCKED';
            if (change === 'placement-disabled') state.config.placements[0].enabled = false;
            window.videoManagers[0].emit('ready');
        }, change);
        expect(await page.evaluate(() => window.videoMetrics.starts)).toBe(0);
        await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-policy-state', 'blocked');
    });
}

test('cross-tab storage revocation cancels scheduling without another server verification', async ({ page, context }) => {
    const run = await openSecurityPlayer(page, { content: true, vmap: true });
    await expect.poll(() => page.evaluate(() => window.videoMetrics.starts)).toBe(1);
    const other = await context.newPage();
    await other.route('**/*', route => route.fulfill({ contentType: 'text/html', body: '<html><body>Other reader tab</body></html>' }));
    await other.goto('https://reader.example/other');
    await storeDeny(other);
    await expect(page.locator('#video-runtime')).toHaveAttribute('data-hm-video-policy-state', 'blocked');
    expect(await page.evaluate(() => window.videoLoaders[0].destroyed)).toBe(true);
    expect(await page.evaluate(() => window.videoMetrics.completions)).toBe(0);
    expect(run.counts.verifies).toBe(1);
    await other.close();
});
