import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';

const baseLoader = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const loader = applyTrafficGateTransform(baseLoader);
const productionLoader = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');

const PUBLISHER = 'https://publisher-a.example';
const CDN = 'https://cdn.horusmedia.net';
const GATE = 'https://verify.horusmedia.net';
const PASS = '1x00000000000000000000BB';
const SITE = 'HM_GATE_BRIDGE_52';

function publisherHtml() {
    return `<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<div class="hm-ad" data-placement="gam_slot"></div>
<script src="${CDN}/hm-loader.js" data-site-key="${SITE}" data-config-base="${CDN}/configs" data-environment="production" data-config-version="52"></script>
</body></html>`;
}

function config() {
    return {
        schemaVersion: 4,
        siteKey: SITE,
        configVersion: 52,
        status: 'active',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        immediatePause: false,
        debug: true,
        allowedHostnames: ['publisher-a.example'],
        loader: { version: '2.0.0', cacheBust: 52 },
        controls: {
            adServingDisabled: false,
            gamDisabled: false,
            prebidDisabled: false,
            directJsDisabled: false,
            nativeDemandDisabled: false,
            trafficGateDisabled: false,
        },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: false },
        clickGuard: { enabled: false },
        pageTargeting: {},
        trafficGate: {
            enabled: true,
            provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED',
            gateOrigin: GATE,
            siteKey: PASS,
            policy: 'BALANCED',
            timings: { initialWaitMs: 500, maxWaitMs: 2000, retryIntervalMs: 500 },
            activityRecoveryEnabled: true,
            readiness: 'READY',
        },
        prebid: {
            enabled: true,
            deliveryMode: 'GAM_BRIDGE',
            build: { version: '11.15.0', url: `${CDN}/assets/prebid/horus-prebid.min.js` },
            auction: { timeoutMs: 100, priceGranularity: 'medium', currency: 'USD', bidderSequence: 'fixed' },
            delivery: { gamFallback: true, refreshBehavior: { enabled: false, minimumIntervalSeconds: 30 } },
            directRender: { implemented: false, supportedMediaTypes: ['banner'], sandbox: ['allow-scripts'] },
            adUnits: [{
                code: 'gam_slot',
                mediaTypes: { banner: { sizes: [[300, 250]] } },
                bids: [{ bidder: 'msft', params: { placement_id: 'task-52' } }],
            }],
        },
        directDemand: { enabled: false, fallbackOrder: [], placements: {} },
        nativeDemand: { enabled: false, fallbackOrder: [], placements: {} },
        placements: [{
            code: 'gam_slot',
            type: 'DISPLAY',
            status: 'active',
            enabled: true,
            renderer: 'GAM',
            rendererConflict: false,
            gamEnabled: true,
            prebidStandaloneEnabled: false,
            directJsEnabled: false,
            nativeEnabled: false,
            adUnitPath: '/123456789/gam_slot',
            sizes: [[300, 250]],
            responsiveMappings: [],
            targeting: {},
            lazyLoad: { enabled: false },
            refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true,
            safeFrame: false,
            outOfPageFormat: null,
        }],
    };
}

function gptStub() {
    return `(() => {
        const metrics = window.__task52Engines = window.__task52Engines || { gptLoads: 0, gamSlots: 0, gamRequests: 0, prebidLoads: 0, prebidAuctions: 0, bridgeTargeting: 0 };
        metrics.gptLoads += 1;
        const pubads = {
            refresh(slots) { metrics.gamRequests += Array.isArray(slots) ? slots.length : 1; },
            setPrivacySettings() {}, setTargeting() {}, addEventListener() {}, enableSingleRequest() {}, disableInitialLoad() {},
        };
        const immediate = { push(callback) { callback(); return 1; } };
        window.googletag = {
            cmd: immediate, apiReady: true, pubadsReady: true,
            pubads() { return pubads; },
            setConfig() {},
            sizeMapping() { return { addSize() { return this; }, build() { return []; } }; },
            defineSlot() {
                metrics.gamSlots += 1;
                const slot = { setTargeting() { return slot; }, defineSizeMapping() { return slot; }, setForceSafeFrame() { return slot; }, setCollapseEmptyDiv() { return slot; }, addService() { return slot; } };
                return slot;
            },
            defineOutOfPageSlot() { return null; },
            enableServices() {}, display() {}, enums: { OutOfPageFormat: {}, TagForAgeTreatment: {} },
        };
    })();`;
}

function prebidStub() {
    return `(() => {
        const metrics = window.__task52Engines;
        metrics.prebidLoads += 1;
        const immediate = { push(callback) { callback(); return 1; } };
        window.pbjs = window.pbjs || {};
        Object.assign(window.pbjs, {
            que: immediate,
            setConfig() {}, onEvent() {}, removeAdUnit() {}, addAdUnits() {},
            requestBids(options) {
                metrics.prebidAuctions += 1;
                queueMicrotask(() => options.bidsBackHandler({}, false, 'task52-auction'));
            },
            setTargetingForGPTAsync() { metrics.bridgeTargeting += 1; },
            getBidResponsesForAdUnitCode() { return { bids: [{ cpm: 1 }] }; },
        });
    })();`;
}

function turnstileSlowPassStub() {
    return `(() => {
        window.turnstile = {
            render(container, options) {
                const frame = document.createElement('iframe');
                frame.src = 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/task52';
                frame.onload = () => setTimeout(() => options.callback('XXXX.DUMMY.TOKEN.XXXX'), 800);
                container.appendChild(frame);
                return 'task52';
            },
            reset() {}, remove() {},
        };
    })();`;
}

function turnstileTechnicalErrorStub() {
    return `(() => {
        window.turnstile = {
            render(container, options) {
                const frame = document.createElement('iframe');
                frame.src = 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/task52-error';
                frame.onload = () => setTimeout(() => options['error-callback']?.('110200'), 20);
                container.appendChild(frame);
                return 'task52-error';
            },
            reset() {}, remove() {},
        };
    })();`;
}

for (const { serverPass, requiresConsent, consentBlocked } of [
    { serverPass: true, requiresConsent: false },
    { serverPass: false, requiresConsent: false },
    { serverPass: true, requiresConsent: true },
    { serverPass: false, requiresConsent: true },
    { serverPass: true, requiresConsent: true, consentBlocked: true },
]) {
    test(`early verification and parallel Turnstile respect late CMP: server=${serverPass}, consent-required=${requiresConsent}, blocked=${Boolean(consentBlocked)}`, async ({ page }) => {
        const selected = config();
        selected.privacy.requireConsentBeforeAds = requiresConsent;
        selected.privacy.cmp = { timeoutMs: consentBlocked ? 100 : 10000, actionOnTimeout: requiresConsent ? 'BLOCK_ADS' : 'LIMITED_ADS' };
        selected.trafficGate.timings.maxWaitMs = 10000;
        let releaseParser;
        let releaseVerification;
        let releaseGateConfig;
        const parserReady = new Promise(resolve => { releaseParser = resolve; });
        const verificationReady = new Promise(resolve => { releaseVerification = resolve; });
        const gateConfigReady = new Promise(resolve => { releaseGateConfig = resolve; });
        let gateConfigRequested = false;
        let gateLibraryRequested = false;
        const counts = { configs: 0, controls: 0, library: 0, verifies: 0 };
        const unexpected = [];
        await page.route('**/*', async route => {
            const request = route.request();
            const url = new URL(request.url());
            if (url.origin === PUBLISHER && url.pathname === '/') {
                return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head>
                    <script src="${CDN}/hm-loader.js" data-site-key="${SITE}" data-config-version="52"></script>
                    <script src="/parser-block.js"></script></head><body><div class="hm-ad" data-placement="gam_slot"></div></body></html>` });
            }
            if (url.origin === PUBLISHER && url.pathname === '/parser-block.js') {
                await parserReady;
                return route.fulfill({ contentType: 'application/javascript', body: `
                    window.__tcfapi = (command, version, callback) => { window.releaseConsent = () => callback({eventStatus:"tcloaded", gdprApplies:false}, true); };
                    document.addEventListener('DOMContentLoaded', () => { window.initialBootForTest = window.HorusMediaLoader.boot(); }, {once:true});
                ` });
            }
            if (url.origin === CDN && url.pathname === '/hm-loader.js') return route.fulfill({ contentType: 'application/javascript', body: productionLoader });
            if (url.origin === CDN && url.pathname === `/configs/${SITE}/production.json`) {
                counts.configs++;
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify(selected) });
            }
            if (url.origin === CDN && url.pathname === '/configs/_global/control.json') {
                counts.controls++;
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ controls: selected.controls }) });
            }
            if (url.origin === CDN && url.pathname === '/assets/prebid/horus-prebid.min.js') return route.fulfill({ contentType: 'application/javascript', body: prebidStub() });
            if (url.origin === GATE && url.pathname === '/traffic-gate/') return route.fulfill({ contentType: 'text/html', body: gateHtml });
            if (url.origin === GATE && url.pathname === '/assets/traffic-gate/horus-traffic-gate.js') return route.fulfill({ contentType: 'application/javascript', body: gateJs });
            if (url.origin === GATE && url.pathname === `/configs/${SITE}/production.json`) {
                gateConfigRequested = true;
                await gateConfigReady;
                return route.fulfill({ contentType: 'application/json', body: JSON.stringify(selected) });
            }
            if (url.origin === 'https://challenges.cloudflare.com' && url.pathname === '/turnstile/v0/api.js') {
                gateLibraryRequested = true;
                return route.fulfill({ contentType: 'application/javascript', body: 'window.turnstile = { render(node, options) { queueMicrotask(() => options.callback("synthetic-token")); return "widget"; }, remove() {} };' });
            }
            if (url.origin === 'https://siteverify.horusmedia.net' && url.pathname === '/verify') {
                const headers = { 'Access-Control-Allow-Origin': GATE, 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Max-Age': '600' };
                if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
                counts.verifies++;
                await verificationReady;
                return route.fulfill({ status: serverPass ? 200 : 422, headers, contentType: 'application/json', body: JSON.stringify({ success: serverPass, pageNonce: request.postDataJSON().pageNonce }) });
            }
            if (url.href === selected.gpt.url) {
                counts.library++;
                return route.fulfill({ contentType: 'application/javascript', headers: { 'Cache-Control': 'public, max-age=3600' }, body: gptStub() });
            }
            unexpected.push(url.href);
            return route.abort('blockedbyclient');
        });
        await page.goto(PUBLISHER + '/', { waitUntil: 'commit' });
        await expect.poll(() => counts.configs).toBe(1);
        await expect.poll(() => page.locator('link[rel="preconnect"][data-hm-preparation]').count()).toBe(3);
        await expect.poll(() => gateConfigRequested && gateLibraryRequested).toBe(true);
        if (!requiresConsent) await expect.poll(() => counts.library).toBe(1);
        expect(await page.evaluate(() => document.readyState)).toBe('loading');
        expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
        expect(counts).toEqual({ configs: 1, controls: 1, library: requiresConsent ? 0 : 1, verifies: 0 });
        expect(await page.locator('link[rel="preconnect"][data-hm-preparation]').count()).toBe(3);
        // Both the parent parser and gate config are blocked. The library has
        // already downloaded, but no challenge can produce a verification call.
        releaseGateConfig();
        await expect.poll(() => counts.verifies).toBe(1);
        releaseVerification();
        await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe(serverPass ? 'PASSED' : 'ERROR');
        expect(await page.evaluate(() => document.readyState)).toBe('loading');
        expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
        expect(await page.evaluate(() => window.HorusMediaLoader.getConfig())).toBeNull();
        releaseParser();
        await expect.poll(() => page.evaluate(() => document.readyState)).not.toBe('loading');
        await expect.poll(() => page.evaluate(() => typeof window.releaseConsent)).toBe('function');
        expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
        if (requiresConsent && !consentBlocked) {
            await page.evaluate(() => window.releaseConsent());
            await expect.poll(() => counts.library).toBe(1);
        }
        if (!requiresConsent) {
            await page.evaluate(() => window.HorusMediaLoader.scan());
            expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
            await page.evaluate(() => window.releaseConsent());
        }
        await page.evaluate(() => window.initialBootForTest);
        if (serverPass && !consentBlocked) {
            await expect.poll(() => page.evaluate(() => window.__task52Engines?.gamRequests)).toBe(1);
            expect(await page.evaluate(() => window.__task52Engines.gptLoads)).toBe(1);
        } else {
            expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
            expect(await page.locator('script[data-hm-gpt]').count()).toBe(0);
        }
        expect(counts.library).toBe(consentBlocked ? 0 : 1); // Successful boot reuses the preload.
        expect(counts.configs).toBe(1);
        expect(counts.controls).toBe(1);
        expect(unexpected).toEqual([]);
    });
}

test('BALANCED late PASS after initial recovery starts GAM + Prebid GAM bridge only after PASS and keeps one slot owner', async ({ page }) => {
    const requests = [];
    page.on('request', request => requests.push({ url: request.url(), at: Date.now() }));

    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin === PUBLISHER) {
            return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: publisherHtml() });
        }
        if (url.origin === CDN) {
            if (url.pathname === '/hm-loader.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: loader });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(config()) });
            if (url.pathname === '/configs/_global/control.json') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ schemaVersion: 2, controls: config().controls }) });
            if (url.pathname === '/assets/prebid/horus-prebid.min.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: prebidStub() });
            return route.fulfill({ status: 404, body: 'not found' });
        }
        if (url.origin === 'https://siteverify.horusmedia.net') {
            const headers = { 'Access-Control-Allow-Origin': GATE, 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type' };
            if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
            return route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ success: true, pageNonce: route.request().postDataJSON().pageNonce }) });
        }
        if (url.origin === GATE) {
            if (url.pathname === '/traffic-gate/' || url.pathname === '/traffic-gate') {
                return route.fulfill({
                    status: 200,
                    contentType: 'text/html; charset=utf-8',
                    headers: { 'Content-Security-Policy': "default-src 'none'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://siteverify.horusmedia.net; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors https:" },
                    body: gateHtml,
                });
            }
            if (url.pathname === '/assets/traffic-gate/horus-traffic-gate.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: gateJs });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(config()) });
            return route.fulfill({ status: 404, body: 'not found' });
        }
        if (url.origin === 'https://challenges.cloudflare.com') {
            if (url.pathname === '/turnstile/v0/api.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: turnstileSlowPassStub() });
            if (url.pathname.includes('/cdn-cgi/challenge-platform/')) return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>challenge</title>' });
        }
        if (url.origin === 'https://securepubads.g.doubleclick.net' && url.pathname === '/tag/js/gpt.js') {
            return route.fulfill({ status: 200, contentType: 'application/javascript', body: gptStub() });
        }
        return route.abort('blockedbyclient');
    });

    await page.goto(PUBLISHER + '/');
    await page.waitForTimeout(650);
    expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
    expect(requests.filter(item => item.url.includes('securepubads.g.doubleclick.net')).every(item => new URL(item.url).pathname === '/tag/js/gpt.js')).toBe(true);
    expect(await page.locator('link[rel="preload"][as="script"][data-hm-preparation]').count()).toBe(1);
    expect(requests.some(item => item.url.includes('horus-prebid.min.js'))).toBe(false);

    await expect.poll(() => page.evaluate(() => window.__task52Engines?.gamRequests || 0)).toBeGreaterThan(0);
    const metrics = await page.evaluate(() => window.__task52Engines);
    expect(metrics.gptLoads).toBe(1);
    expect(metrics.gamSlots).toBe(1);
    expect(metrics.prebidLoads).toBe(1);
    expect(metrics.prebidAuctions).toBe(1);
    expect(metrics.bridgeTargeting).toBe(1);
    expect(metrics.gamRequests).toBe(1);
    expect(await page.locator('.hm-ad[data-placement="gam_slot"]').count()).toBe(1);
    expect(await page.locator('.hm-ad[data-placement="gam_slot"][data-hm-defined="1"]').count()).toBe(1);
    expect(requests.some(item => item.url.startsWith('https://app.horusmedia.net/'))).toBe(false);
    expect(requests.some(item => /analytics|reporting|beacon/i.test(item.url))).toBe(false);
});


test('BALANCED technical failure leaves content available and suppresses monetization beyond deadline', async ({ page }) => {
    const requests = [];
    page.on('request', request => requests.push(request.url()));

    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin === PUBLISHER) {
            return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: publisherHtml() });
        }
        if (url.origin === CDN) {
            if (url.pathname === '/hm-loader.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: loader });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(config()) });
            if (url.pathname === '/configs/_global/control.json') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ schemaVersion: 2, controls: config().controls }) });
            if (url.pathname === '/assets/prebid/horus-prebid.min.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: prebidStub() });
            return route.fulfill({ status: 404, body: 'not found' });
        }
        if (url.origin === 'https://siteverify.horusmedia.net') {
            const headers = { 'Access-Control-Allow-Origin': GATE, 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Content-Type' };
            if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
            return route.fulfill({ status: 200, headers, contentType: 'application/json', body: JSON.stringify({ success: true, pageNonce: route.request().postDataJSON().pageNonce }) });
        }
        if (url.origin === GATE) {
            if (url.pathname === '/traffic-gate/' || url.pathname === '/traffic-gate') {
                return route.fulfill({
                    status: 200,
                    contentType: 'text/html; charset=utf-8',
                    headers: { 'Content-Security-Policy': "default-src 'none'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com https://siteverify.horusmedia.net; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors https:" },
                    body: gateHtml,
                });
            }
            if (url.pathname === '/assets/traffic-gate/horus-traffic-gate.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: gateJs });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(config()) });
            return route.fulfill({ status: 404, body: 'not found' });
        }
        if (url.origin === 'https://challenges.cloudflare.com') {
            if (url.pathname === '/turnstile/v0/api.js') return route.fulfill({ status: 200, contentType: 'application/javascript', body: turnstileTechnicalErrorStub() });
            if (url.pathname.includes('/cdn-cgi/challenge-platform/')) return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>challenge</title>' });
        }
        if (url.origin === 'https://securepubads.g.doubleclick.net' && url.pathname === '/tag/js/gpt.js') {
            return route.fulfill({ status: 200, contentType: 'application/javascript', body: gptStub() });
        }
        return route.abort('blockedbyclient');
    });

    await page.goto(PUBLISHER + '/');

    // The gate stays invisible, and before the bounded fallback no monetization
    // request is allowed.
    await page.waitForTimeout(600);
    expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
    expect(requests.filter(url => url.includes('securepubads.g.doubleclick.net')).every(url => new URL(url).pathname === '/tag/js/gpt.js')).toBe(true);

    await expect.poll(
        () => page.evaluate(() => window.HorusMediaLoader?.getTrafficGateState?.().state),
        { timeout: 3500 }
    ).toBe('TIMEOUT');

    await page.waitForTimeout(2200);
    expect(await page.evaluate(() => window.__task52Engines?.gamRequests || 0)).toBe(0);
    expect(await page.evaluate(() => window.__task52Engines || null)).toBeNull();
    expect(await page.locator('script[data-hm-gpt]').count()).toBe(0);
    expect(requests.filter(url => url.includes('securepubads.g.doubleclick.net')).every(url => new URL(url).pathname === '/tag/js/gpt.js')).toBe(true);
    const gate = await page.evaluate(() => window.HorusMediaLoader.getTrafficGateState());
    expect(['MAX_WAIT', 'TURNSTILE_TIMEOUT']).toContain(gate.reason);
    expect(await page.locator('iframe[data-hm-traffic-gate="1"]').count()).toBe(0);
});
