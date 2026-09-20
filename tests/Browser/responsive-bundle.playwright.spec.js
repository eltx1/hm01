import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// Exercise the exact deployed loader and GPT adapter. Only provider/gate
// network boundaries are stubbed; no paid inventory or real challenges run.
const loader = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const CDN = 'https://cdn.horusmedia.net';
const SITE = 'RESPONSIVE_FOUR';
const GATE = 'https://verify.horusmedia.net';
const codes = ['quick_responsive_display', 'quick_responsive_display_2', 'quick_responsive_display_3', 'quick_responsive_display_4'];

function config(gated) {
    return {
        schemaVersion: 4, siteKey: SITE, configVersion: 1, status: 'active', servingMode: 'HORUS_DIRECT',
        allowedHostnames: ['publisher.example'], immediatePause: false,
        controls: { adServingDisabled: false, gamDisabled: true, prebidDisabled: true, directJsDisabled: false, nativeDemandDisabled: false, trafficGateDisabled: false },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: false },
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        trafficGate: { enabled: gated, provider: 'CLOUDFLARE_TURNSTILE_CLIENT_ONLY', gateOrigin: GATE,
            siteKey: '1x00000000000000000000BB', policy: 'BALANCED', readiness: gated ? 'READY' : 'DISABLED',
            timings: { initialWaitMs: 2000, maxWaitMs: 10000, retryIntervalMs: 500 } },
        prebid: { enabled: false }, nativeDemand: { enabled: false, placements: {} },
        placements: codes.map(code => ({ code, type: 'DISPLAY', enabled: true, status: 'active', renderer: 'DIRECT_JS',
            directJsEnabled: true, rendererConflict: false, sizes: [[300, 250]], responsiveMappings: [],
            lazyLoad: { enabled: false }, refresh: { enabled: false },
            format: { code: 'display_banner', settings: { autoMount: false, reserveSpace: true, contentAlignment: 'center' } },
        })),
        directDemand: { enabled: true, placements: Object.fromEntries(codes.map((code, i) => {
            const id = `hm-gpt-member-${i}`;
            return [code, { enabled: true, candidates: [{ network: 'CUSTOM_THIRD_PARTY_TAG', mode: 'MANUAL_TAG', tag: {
                recipeVersion: 1, executionMode: 'STRUCTURED', format: 'DISPLAY',
                scripts: [{ url: `${CDN}/runtime/gpt/test.js`, async: true, dedupeKey: 'gpt-runtime' }],
                container: { element: 'div', id, attributes: { 'data-hm-gpt-direct': '1', 'data-hm-gpt-ad-unit-path': '/123/shared', 'data-hm-gpt-sizes': '[[300,250]]' } },
                initialization: { type: 'NONE' },
                render: { timeoutMs: 15000, successSelector: `#${id}[data-hm-gpt-status="rendered"]`, assumeLoadedIsSuccess: false, allowedFormats: ['DISPLAY'], allowedSizes: [[300, 250]] },
            } }] }];
        })) },
    };
}

const gpt = `(() => {
    const queue = window.googletag?.cmd || [];
    const listeners = new Set();
    const slots = window.testSlots = [];
    window.testDisplays = [];
    const pubads = { addEventListener(name, fn) { listeners.add(fn); }, removeEventListener(name, fn) { listeners.delete(fn); } };
    window.googletag = { cmd: { push(fn) { fn(); } }, apiReady: true, pubadsReady: true,
        pubads() { return pubads; }, enableServices() {}, destroySlots() {},
        defineSlot(path, sizes, id) { const slot = { path, id, addService() { return slot; } }; slots.push(slot); return slot; },
        display(id) {
            window.testDisplays.push(id);
            const frame = document.createElement('iframe');
            frame.style.cssText = 'width:300px;height:250px;border:0';
            frame.title = 'Advertisement'; document.getElementById(id).appendChild(frame);
            queueMicrotask(() => { for (const fn of [...listeners]) fn({ slot: slots.find(s => s.id === id), isEmpty: false, size: [300, 250] }); });
        },
    };
    queue.forEach(fn => fn());
})();`;

async function open(page, { count = 4, gated = false, blocked = false } = {}) {
    const requests = [];
    page.on('request', request => requests.push(request.url()));
    if (blocked) await page.addInitScript(site => {
        localStorage.setItem('hm:click-guard:v2:' + site, JSON.stringify({ v: 2, clicks: [], blockedUntil: Date.now() + 3600000 }));
    }, SITE);
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === 'https://publisher.example') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}article{width:calc(100% - 32px);max-width:760px;margin:auto}.hm-ad{float:left;text-align:left;margin-left:0}.hm-direct-google-gpt{margin-right:0}</style><body><article><h1>Publisher article</h1>${codes.slice(0, count).map(code => `<p>Content at chosen position</p><div class="hm-ad" data-placement="${code}"></div>`).join('')}</article><script src="${CDN}/hm-loader.js" data-site-key="${SITE}" data-config-base="${CDN}/configs" data-environment="production" data-config-version="1"></script></body></html>` });
        if (url.origin === CDN) {
            if (url.pathname === '/hm-loader.js') return route.fulfill({ contentType: 'application/javascript', body: loader });
            if (url.pathname === '/runtime/gpt/test.js') return route.fulfill({ contentType: 'application/javascript', body: runtime });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ contentType: 'application/json', body: JSON.stringify(config(gated)) });
            if (url.pathname === '/configs/_global/control.json') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ schemaVersion: 2, controls: config(gated).controls }) });
            return route.fulfill({ status: 404, body: '' });
        }
        if (url.origin === GATE) return route.fulfill({ contentType: 'text/html', body: `<!doctype html><script>addEventListener('message', event => { if(event.data?.type === 'HORUS_TRAFFIC_GATE_HELLO') window.reply = type => parent.postMessage({...event.data, type}, event.origin); });</script>` });
        if (url.href === 'https://securepubads.g.doubleclick.net/tag/js/gpt.js') return route.fulfill({ contentType: 'application/javascript', body: gpt });
        return route.abort('blockedbyclient');
    });
    await page.goto('https://publisher.example/article');
    await expect.poll(() => page.evaluate(() => window.HorusMediaLoader?.getConfig?.()?.configVersion)).toBe(1);
    return requests;
}

test('four manually installed units share demand, stay centered and never redefine slots on DOM changes', async ({ page }) => {
    const requests = await open(page);
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(4);
    expect(await page.evaluate(() => window.testSlots.map(s => s.path))).toEqual(Array(4).fill('/123/shared'));
    expect(await page.evaluate(() => new Set(window.testSlots.map(s => s.id)).size)).toBe(4);
    for (const code of codes) {
        const root = page.locator(`[data-placement="${code}"]`);
        const bounds = await root.boundingBox();
        const creative = await root.locator('iframe').boundingBox();
        expect(Math.abs((creative.x + creative.width / 2) - (bounds.x + bounds.width / 2))).toBeLessThan(2);
        expect(creative.x).toBeGreaterThanOrEqual(0);
        expect(creative.x + creative.width).toBeLessThanOrEqual(page.viewportSize().width);
    }
    await page.evaluate(() => { for (let i = 0; i < 10; i++) document.querySelector('article').appendChild(document.createElement('p')); window.dispatchEvent(new Event('resize')); });
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(4);
    expect(requests.filter(url => url.endsWith('/tag/js/gpt.js'))).toHaveLength(1);
    expect(requests.filter(url => url.endsWith('/runtime/gpt/test.js'))).toHaveLength(1);
});

test('uninstalled units stay absent and make no ad requests', async ({ page }) => {
    await open(page, { count: 2 });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(2);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(2);
    await expect(page.locator('.hm-ad')).toHaveCount(2);
});

test('Click Guard blocks provider loading for all four units', async ({ page }) => {
    const requests = await open(page, { blocked: true });
    await page.waitForTimeout(600);
    expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
    expect(await page.evaluate(() => window.testSlots || [])).toHaveLength(0);
});

for (const outcome of ['PASS', 'DENIED']) {
    test(`Traffic Gate ${outcome} governs all four units and rejects a forged parent message`, async ({ page }) => {
        const requests = await open(page, { gated: true });
        await expect(page.locator('iframe[data-hm-traffic-gate]')).toHaveCount(1);
        const frame = page.frames().find(frame => frame.url().startsWith(GATE));
        await expect.poll(() => frame.evaluate(() => typeof window.reply)).toBe('function');
        await page.evaluate(() => window.postMessage({ type: 'HORUS_TRAFFIC_GATE_PASS', protocolVersion: 1, pageNonce: 'forged' }, '*'));
        await page.waitForTimeout(200);
        expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
        await frame.evaluate(outcome => window.reply('HORUS_TRAFFIC_GATE_' + outcome), outcome);
        if (outcome === 'PASS') {
            await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(4);
        } else {
            await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe('BLOCKED');
            expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
        }
    });
}
