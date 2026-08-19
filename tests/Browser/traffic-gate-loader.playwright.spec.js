import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';

const baseLoader = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const loader = applyTrafficGateTransform(baseLoader);
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
            provider: 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY',
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
        if (url.origin === GATE) {
            if (url.pathname === '/traffic-gate/' || url.pathname === '/traffic-gate') {
                return route.fulfill({
                    status: 200,
                    contentType: 'text/html; charset=utf-8',
                    headers: { 'Content-Security-Policy': "default-src 'none'; script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors https:" },
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
    expect(requests.some(item => item.url.includes('securepubads.g.doubleclick.net'))).toBe(false);
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
