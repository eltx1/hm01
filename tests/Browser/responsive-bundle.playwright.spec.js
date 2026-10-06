import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// Exercise the exact deployed loader and GPT adapter. Only provider/gate
// network boundaries are stubbed; no paid inventory or real challenges run.
const loader = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const CDN = 'https://cdn.horusmedia.net';
const SITE = 'RESPONSIVE_SIX';
const GATE = 'https://verify.horusmedia.net';
const codes = ['quick_responsive_display', 'quick_responsive_display_2', 'quick_responsive_display_3', 'quick_responsive_display_4', 'quick_responsive_display_5', 'quick_responsive_display_6'];
const mobileSizes = [[300, 250], [336, 280], [320, 100], [320, 50], [300, 100], [300, 50], [250, 250], [200, 200], [240, 400], [250, 360]];
const tabletSizes = [[728, 90], [468, 60], ...mobileSizes, [300, 600]];
const desktopSizes = [[970, 250], [970, 90], ...tabletSizes];
const sizeMappings = [
    { viewport: [0, 0], maxViewport: [767, 65535], device: 'MOBILE', sizes: mobileSizes },
    { viewport: [768, 0], maxViewport: [1023, 65535], device: 'TABLET', sizes: tabletSizes },
    { viewport: [1024, 0], maxViewport: [null, null], device: 'DESKTOP', sizes: desktopSizes },
];

function config(gated, expanded = false, fluid = false) {
    const sizes = [...(expanded ? desktopSizes : [[300, 250]]), ...(fluid ? ['fluid'] : [])];
    return {
        schemaVersion: 4, siteKey: SITE, configVersion: 1, status: 'active', servingMode: 'HORUS_DIRECT',
        allowedHostnames: ['publisher.example'], immediatePause: false,
        controls: { adServingDisabled: false, gamDisabled: true, prebidDisabled: true, directJsDisabled: false, nativeDemandDisabled: false, trafficGateDisabled: false },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: false },
        clickGuard: { enabled: true, maxClicks: 3, windowHours: 6, blockHours: 12 },
        trafficGate: { enabled: gated, provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED', gateOrigin: GATE,
            siteKey: '1x00000000000000000000BB', policy: 'BALANCED', readiness: gated ? 'READY' : 'DISABLED',
            timings: { initialWaitMs: 2000, maxWaitMs: 10000, retryIntervalMs: 500 } },
        prebid: { enabled: false }, nativeDemand: { enabled: false, placements: {} },
        placements: codes.map(code => ({ code, type: 'DISPLAY', enabled: true, status: 'active', renderer: 'DIRECT_JS',
            directJsEnabled: true, rendererConflict: false, sizes, responsiveMappings: expanded ? sizeMappings : [],
            lazyLoad: { enabled: false }, refresh: { enabled: false },
            format: { code: 'display_banner', settings: { autoMount: false, reserveSpace: true, contentAlignment: 'center' } },
        })),
        directDemand: { enabled: true, placements: Object.fromEntries(codes.map((code, i) => {
            const id = `hm-gpt-member-${i}`;
            return [code, { enabled: true, candidates: [{ network: 'CUSTOM_THIRD_PARTY_TAG', mode: 'MANUAL_TAG', tag: {
                recipeVersion: 1, executionMode: 'STRUCTURED', format: 'DISPLAY',
                scripts: [{ url: `${CDN}/runtime/gpt/test.js`, async: true, dedupeKey: 'gpt-runtime' }],
                container: { element: 'div', id, attributes: { 'data-hm-gpt-direct': '1', 'data-hm-gpt-ad-unit-path': '/123/shared', 'data-hm-gpt-sizes': JSON.stringify(sizes), 'data-hm-gpt-inner-id': 'same-provider-id', ...(expanded ? { 'data-hm-gpt-fit-container': '1' } : {}), ...(fluid ? { 'data-hm-gpt-responsive-fluid': '1' } : {}) } },
                initialization: { type: 'NONE' },
                render: { timeoutMs: 15000, successSelector: `#${id}[data-hm-gpt-status="rendered"]`, assumeLoadedIsSuccess: false, allowedFormats: ['DISPLAY'], allowedSizes: sizes },
            } }] }];
        })) },
    };
}

const gpt = `(() => {
    const queue = window.googletag?.cmd || [];
    const listeners = new Set();
    const slots = window.testSlots = [];
    window.testDisplays = [];
    window.testDestroyedSlots = [];
    const pubads = { addEventListener(name, fn) { listeners.add(fn); }, removeEventListener(name, fn) { listeners.delete(fn); } };
    window.googletag = { cmd: { push(fn) { fn(); } }, apiReady: true, pubadsReady: true,
        pubads() { return pubads; }, enableServices() {}, destroySlots(slots) { window.testDestroyedSlots.push(...slots.map(slot => slot.id)); },
        defineSlot(path, sizes, id) { const slot = { path, id, sizes, addService() { return slot; } }; slots.push(slot); return slot; },
        display(id) {
            window.testDisplays.push(id);
            const slot = slots.find(s => s.id === id);
            const size = window.testCreativeSizes?.[slots.indexOf(slot)] || slot.sizes[0];
            const frame = document.createElement('iframe');
            frame.style.cssText = size === 'fluid' ? 'display:block;width:100%;height:420px;border:0' : 'width:' + size[0] + 'px;height:' + size[1] + 'px;border:0';
            frame.title = 'Advertisement'; document.getElementById(id).appendChild(frame);
            queueMicrotask(() => { for (const fn of [...listeners]) fn({ slot, isEmpty: false, size }); });
        },
    };
    queue.forEach(fn => fn());
})();`;

async function open(page, { count = 6, gated = false, blocked = false, expanded = false, fluid = false, creativeSizes = null, gateDocumentReady = null } = {}) {
    const requests = [];
    page.on('request', request => requests.push(request.url()));
    if (creativeSizes) await page.addInitScript(sizes => { window.testCreativeSizes = sizes; }, creativeSizes);
    if (blocked) await page.addInitScript(site => {
        localStorage.setItem('hm:click-guard:v2:' + site, JSON.stringify({ v: 2, clicks: [], blockedUntil: Date.now() + 3600000 }));
    }, SITE);
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin === 'https://publisher.example') return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}article{width:calc(100% - 32px);max-width:${expanded ? 1100 : 760}px;margin:auto}.hm-ad{float:left;text-align:left;margin-left:0}.hm-direct-google-gpt{margin-right:0}.slot-position{display:flow-root;max-width:100%}</style><body><article><h1>Publisher article</h1>${codes.slice(0, count).map((code, i) => `<section class="slot-position" style="width:${expanded ? [1000, 700, 350, 240, 600, 320][i] + 'px' : '100%'}"><p>Content at chosen position</p><div class="hm-ad" data-placement="${code}" style="${expanded && i === 2 ? 'padding:0 16px' : ''}"></div></section>`).join('')}</article><script src="${CDN}/hm-loader.js" data-site-key="${SITE}" data-config-base="${CDN}/configs" data-environment="production" data-config-version="1"></script></body></html>` });
        if (url.origin === CDN) {
            if (url.pathname === '/hm-loader.js') return route.fulfill({ contentType: 'application/javascript', body: loader });
            if (url.pathname === '/runtime/gpt/test.js') return route.fulfill({ contentType: 'application/javascript', body: runtime });
            if (url.pathname === `/configs/${SITE}/production.json`) return route.fulfill({ contentType: 'application/json', body: JSON.stringify(config(gated, expanded, fluid)) });
            if (url.pathname === '/configs/_global/control.json') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ schemaVersion: 2, controls: config(gated, expanded, fluid).controls }) });
            return route.fulfill({ status: 404, body: '' });
        }
        if (url.origin === GATE && gateDocumentReady) await gateDocumentReady;
        if (url.origin === GATE) return route.fulfill({ contentType: 'text/html', body: `<!doctype html><script>addEventListener('message', event => { if(event.data?.type === 'HORUS_TRAFFIC_GATE_HELLO') { window.reply = type => parent.postMessage({...event.data, type, serverVerified: true}, event.origin); document.documentElement.setAttribute('data-hm-test-reply-ready', '1'); } });</script>` });
        if (url.href === 'https://securepubads.g.doubleclick.net/tag/js/gpt.js') return route.fulfill({ contentType: 'application/javascript', body: gpt });
        return route.abort('blockedbyclient');
    });
    await page.goto('https://publisher.example/article', { waitUntil: gateDocumentReady ? 'domcontentloaded' : 'load' });
    await expect.poll(() => page.evaluate(() => window.HorusMediaLoader?.getConfig?.()?.configVersion)).toBe(1);
    return requests;
}

test('six manually installed units share demand, stay centered and never redefine slots on DOM changes', async ({ page }) => {
    const requests = await open(page);
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    expect(await page.evaluate(() => window.testSlots.map(s => s.path))).toEqual(Array(6).fill('/123/shared'));
    expect(await page.evaluate(() => new Set(window.testSlots.map(s => s.id)).size)).toBe(6);
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
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(6);
    expect(requests.filter(url => url.endsWith('/tag/js/gpt.js'))).toHaveLength(1);
    expect(requests.filter(url => url.endsWith('/runtime/gpt/test.js'))).toHaveLength(1);
});

test('uninstalled units stay absent and make no ad requests', async ({ page }) => {
    await open(page, { count: 2 });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(2);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(2);
    await expect(page.locator('.hm-ad')).toHaveCount(2);
});

test('expanded responsive units request only device-appropriate sizes fitting each actual publisher DIV', async ({ page }) => {
    await open(page, { expanded: true });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    const slots = await page.evaluate(() => window.testSlots.map(({ id, path, sizes }) => ({ id, path, sizes })));
    expect(new Set(slots.map(slot => slot.id)).size).toBe(6);
    expect(new Set(slots.map(slot => slot.path)).size).toBe(1);
    expect(slots.find(slot => slot.id === 'hm-gpt-member-3').sizes).toEqual([[200, 200], [240, 400]]);
    const paddedSizes = slots.find(slot => slot.id === 'hm-gpt-member-2').sizes;
    expect(paddedSizes).toContainEqual([300, 250]);
    expect(paddedSizes).not.toContainEqual([336, 280]);
    expect(paddedSizes).not.toContainEqual([320, 100]);

    for (const slot of slots) {
        const creative = page.locator('#' + slot.id);
        const available = await creative.evaluate(node => {
            const root = node.closest('.hm-ad');
            const css = getComputedStyle(root);
            return root.clientWidth - parseFloat(css.paddingLeft) - parseFloat(css.paddingRight);
        });
        for (const size of slot.sizes) {
            expect(size[0]).toBeLessThanOrEqual(available);
            if (page.viewportSize().width < 768) expect(size[1]).toBeLessThanOrEqual(400);
        }
        const bounds = await creative.locator('iframe').boundingBox();
        const parent = await creative.evaluate(node => {
            const rect = node.closest('.hm-ad').getBoundingClientRect();
            return { x: rect.x, width: rect.width };
        });
        expect(Math.abs(bounds.x + bounds.width / 2 - parent.x - parent.width / 2)).toBeLessThan(2);
    }
    if (page.viewportSize().width >= 1024) {
        expect(slots.find(slot => slot.id === 'hm-gpt-member-0').sizes).toContainEqual([970, 250]);
        expect(slots.find(slot => slot.id === 'hm-gpt-member-1').sizes).not.toContainEqual([728, 90]);
        expect(slots.find(slot => slot.id === 'hm-gpt-member-1').sizes).toContainEqual([468, 60]);
    }
});

test('portrait creatives render at exact dimensions alongside fluid without duplicate requests', async ({ page }) => {
    const creativeSizes = [[240, 400], [250, 360], [240, 400], [240, 400], [250, 360], 'fluid'];
    await open(page, { expanded: true, fluid: true, creativeSizes });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    const slots = await page.evaluate(() => window.testSlots.map(({ sizes }) => sizes));
    for (let index = 0; index < 5; index++) {
        expect(slots[index]).toContainEqual(creativeSizes[index]);
        expect(slots[index]).toContain('fluid');
        const frame = await page.locator('#hm-gpt-member-' + index + ' iframe').boundingBox();
        expect([frame.width, frame.height]).toEqual(creativeSizes[index]);
    }
    expect(slots[3]).toContainEqual([250, 360]);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(6);
    expect(await page.evaluate(() => window.testDestroyedSlots.length)).toBe(0);
});

test('six filled creatives with different returned sizes remain rendered, centered and requested only once', async ({ page }) => {
    const creativeSizes = page.viewportSize().width >= 1024
        ? [[728, 600], [640, 600], [300, 600], [200, 600], [468, 600], [300, 600]]
        : [[300, 600], [300, 600], [300, 600], [200, 600], [300, 600], [300, 600]];
    await open(page, { expanded: true, creativeSizes });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    const slots = await page.evaluate(() => window.testSlots.map(({ id, sizes }) => ({ id, sizes })));
    expect(new Set(slots.map(slot => slot.id)).size).toBe(6);
    // Requested inventory remains constrained even when GPT renders a taller ad.
    expect(slots[3].sizes).toEqual([[200, 200], [240, 400]]);
    for (const [index, code] of codes.entries()) {
        const root = page.locator(`[data-placement="${code}"]`);
        const bounds = await root.boundingBox();
        const frame = await root.locator('iframe').boundingBox();
        expect([frame.width, frame.height]).toEqual(creativeSizes[index]);
        expect(bounds.height).toBeGreaterThanOrEqual(frame.height);
        expect(Math.abs(frame.x + frame.width / 2 - bounds.x - bounds.width / 2)).toBeLessThan(2);
        expect(frame.x).toBeGreaterThanOrEqual(bounds.x);
        expect(frame.x + frame.width).toBeLessThanOrEqual(bounds.x + bounds.width);
        expect(frame.x + frame.width).toBeLessThanOrEqual(page.viewportSize().width);
        await expect(root.locator('[data-hm-gpt-status="rendered"]')).toHaveAttribute('data-hm-gpt-rendered-height', '600');
    }
    await page.evaluate(() => {
        for (let i = 0; i < 10; i++) document.querySelector('article').appendChild(document.createElement('p'));
        window.dispatchEvent(new Event('resize'));
    });
    await page.waitForTimeout(300);
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(6);
    expect(await page.evaluate(() => window.testDestroyedSlots)).toEqual([]);
});

test('Click Guard blocks provider loading for all six units', async ({ page }) => {
    const requests = await open(page, { blocked: true });
    await page.waitForTimeout(600);
    expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
    expect(await page.evaluate(() => window.testSlots || [])).toHaveLength(0);
});

for (const outcome of ['PASS', 'DENIED']) for (const holdDocument of [false, true]) {
    test(`Traffic Gate ${outcome} governs all six units and rejects a forged parent message${holdDocument ? ' with pending gate document' : ''}`, async ({ page }) => {
        let releaseDocument;
        const gateDocumentReady = holdDocument ? new Promise(resolve => { releaseDocument = resolve; }) : null;
        const requests = await open(page, { gated: true, gateDocumentReady });
        await expect(page.locator('iframe[data-hm-traffic-gate]')).toHaveCount(1);
        // Resolve the current iframe on every operation, not a possibly absent
        // Frame captured before navigation commits. Authorization assertions stay unchanged.
        const frame = page.frameLocator('iframe[data-hm-traffic-gate]').locator('html');
        if (holdDocument) {
            // An iframe element may exist while its network document is still pending.
            expect(page.frames().some(frame => frame.url().startsWith(GATE))).toBe(false);
            expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
            releaseDocument();
        }
        // The legacy stub has no readiness ping, so a pending frame may be
        // replaced before HELLO. Locator assertions retry that navigation; a
        // captured evaluate Promise instead fails permanently on detachment.
        await expect(page.frameLocator('iframe[data-hm-traffic-gate]')
            .locator('html[data-hm-test-reply-ready="1"]')).toHaveCount(1);
        await page.evaluate(() => window.postMessage({ type: 'HORUS_TRAFFIC_GATE_PASS', protocolVersion: 1, pageNonce: 'forged' }, '*'));
        await page.waitForTimeout(200);
        expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
        await frame.evaluate((_html, outcome) => window.reply('HORUS_TRAFFIC_GATE_' + outcome), outcome);
        if (outcome === 'PASS') {
            await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
        } else {
            await expect.poll(() => page.evaluate(() => window.HorusMediaLoader.getTrafficGateState().state)).toBe('BLOCKED');
            expect(requests.filter(url => /runtime\/gpt|doubleclick/.test(url))).toHaveLength(0);
        }
    });
}


test('Responsive GAM paths render fluid at full column width and fixed banners at their own size', async ({ page }) => {
    await open(page, { expanded: true, fluid: true, creativeSizes: ['fluid', [320, 50], 'fluid', 'fluid', [300, 250], 'fluid'] });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    const slots = await page.evaluate(() => window.testSlots);
    expect(slots).toHaveLength(6);
    for (const slot of slots) expect(slot.sizes.filter(size => size === 'fluid')).toHaveLength(1);
    for (const index of [0, 2, 3, 5]) {
        const root = page.locator(`[data-placement="${codes[index]}"]`);
        const container = root.locator('[data-hm-gpt-direct="1"]');
        const frame = root.locator('iframe');
        const contentWidth = await root.evaluate(node => node.clientWidth - parseFloat(getComputedStyle(node).paddingLeft) - parseFloat(getComputedStyle(node).paddingRight));
        const bounds = await container.boundingBox();
        const creative = await frame.boundingBox();
        expect(Math.abs(creative.width - contentWidth)).toBeLessThan(2);
        expect(bounds.height).toBeGreaterThanOrEqual(420);
        expect(creative.x).toBeGreaterThanOrEqual(0);
        expect(creative.x + creative.width).toBeLessThanOrEqual(page.viewportSize().width);
        // GPT may finish loading/resizing native content after slotRenderEnded.
        await frame.evaluate(node => { node.style.height = '650px'; });
        await expect.poll(async () => (await container.boundingBox()).height).toBeGreaterThanOrEqual(650);
    }
    const fixed = await page.locator(`[data-placement="${codes[1]}"] [data-hm-gpt-direct="1"]`).boundingBox();
    expect(fixed.width).toBe(320);
    expect(fixed.height).toBe(50);
    await page.evaluate(() => { window.dispatchEvent(new Event('resize')); document.querySelector('article').appendChild(document.createElement('p')); });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(6);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(6);
    expect(await page.evaluate(() => window.testDestroyedSlots)).toEqual([]);
});
