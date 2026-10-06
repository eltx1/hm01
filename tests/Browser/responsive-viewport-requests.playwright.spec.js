import { test, expect } from '@playwright/test';
import { readFile, writeFile } from 'node:fs/promises';

// No real Google demand: every request is intercepted. Exercise the exact
// built loader and direct-GPT adapter, including loader-owned authorization.
const loader = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const runtime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const CDN = 'https://cdn.horusmedia.net';
const SITE = 'RESPONSIVE_VIEWPORT';
const GATE = 'https://verify.horusmedia.net';
const GPT = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
const hostClasses = ['joeredirect-ad-top', 'joeredirect-ad-after-bar', 'joeredirect-ad-after-button', 'joeredirect-special-content', 'joeredirect-ad-before-bottom', 'joeredirect-ad-after-bottom'];
const codes = Array.from({ length: 6 }, (_, index) => 'quick_responsive_display' + (index ? '_' + (index + 1) : ''));
const mobile = [[300, 250], [336, 280], [320, 100], [320, 50], [300, 100], [300, 50], [250, 250], [200, 200], [240, 400], [250, 360]];
const tablet = [[728, 90], [468, 60], ...mobile, [300, 600]];
const desktop = [[970, 250], [970, 90], ...tablet];
const mappings = [
    { viewport: [0, 0], maxViewport: [767, 65535], device: 'MOBILE', sizes: mobile },
    { viewport: [768, 0], maxViewport: [1023, 65535], device: 'TABLET', sizes: tablet },
    { viewport: [1024, 0], maxViewport: [null, null], device: 'DESKTOP', sizes: desktop },
];
const expectedSizes = width => [...(width < 768 ? mobile : width < 1024 ? tablet : desktop).filter(size => size[0] <= width), 'fluid'];
const maximumCreative = width => expectedSizes(width).filter(Array.isArray).sort((a, b) => b[0] - a[0] || b[1] - a[1])[0];

function configuration({ gated = false, lazy = false } = {}) {
    const sizes = [...desktop, 'fluid'];
    return {
        schemaVersion: 4, siteKey: SITE, configVersion: 1, status: 'active', servingMode: 'HORUS_DIRECT',
        allowedHostnames: ['publisher.example'], immediatePause: false,
        controls: { adServingDisabled: false, gamDisabled: true, prebidDisabled: true, directJsDisabled: false, nativeDemandDisabled: false, trafficGateDisabled: false },
        privacy: { mode: 'AUTO', cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' }, requireConsentBeforeAds: false },
        trafficGate: { enabled: gated, provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED', gateOrigin: GATE,
            siteKey: '1x00000000000000000000BB', policy: 'BALANCED', readiness: gated ? 'READY' : 'DISABLED',
            timings: { initialWaitMs: 2000, maxWaitMs: 10000, retryIntervalMs: 500 } },
        prebid: { enabled: false }, nativeDemand: { enabled: false, placements: {} },
        placements: codes.map(code => ({ code, type: 'DISPLAY', enabled: true, status: 'active', renderer: 'DIRECT_JS',
            directJsEnabled: true, rendererConflict: false, sizes, responsiveMappings: mappings,
            lazyLoad: { enabled: lazy }, refresh: { enabled: false },
            format: { code: 'display_banner', settings: { autoMount: false, reserveSpace: true, contentAlignment: 'center' } },
        })),
        directDemand: { enabled: true, placements: Object.fromEntries(codes.map((code, index) => {
            const id = 'hm-viewport-member-' + index;
            return [code, { enabled: true, candidates: [{ network: 'CUSTOM_THIRD_PARTY_TAG', mode: 'MANUAL_TAG', tag: {
                recipeVersion: 1, executionMode: 'STRUCTURED', format: 'DISPLAY',
                scripts: [{ url: CDN + '/runtime/gpt/viewport.js', async: true, dedupeKey: 'gpt-runtime' }],
                container: { element: 'div', id, attributes: {
                    'data-hm-gpt-direct': '1', 'data-hm-gpt-ad-unit-path': '/123/shared',
                    'data-hm-gpt-sizes': JSON.stringify(sizes), 'data-hm-gpt-size-map': JSON.stringify(mappings),
                    'data-hm-gpt-inner-id': 'same-provider-id', 'data-hm-gpt-fit-container': '1', 'data-hm-gpt-responsive-fluid': '1',
                } },
                initialization: { type: 'NONE' },
                render: { timeoutMs: 15000, successSelector: '#' + id + '[data-hm-gpt-status="rendered"]', assumeLoadedIsSuccess: false, allowedFormats: ['DISPLAY'], allowedSizes: sizes },
            } }] }];
        })) },
    };
}

const google = `(() => {
    const queue = window.googletag?.cmd || [];
    const listeners = new Set();
    const slots = window.testSlots = [];
    window.testDisplays = []; window.testDestroyedSlots = [];
    const pubads = { addEventListener(name, fn) { listeners.add(fn); }, removeEventListener(name, fn) { listeners.delete(fn); } };
    window.googletag = { cmd: { push(fn) { fn(); } }, apiReady: true, pubadsReady: true,
        pubads() { return pubads; }, enableServices() {},
        destroySlots(items) { window.testDestroyedSlots.push(...items.map(slot => slot.id)); },
        defineSlot(path, sizes, id) { const slot = { path, id, sizes, addService() { return slot; } }; slots.push(slot); return slot; },
        display(id) {
            window.testDisplays.push(id);
            const slot = slots.find(s => s.id === id);
            const index = Number(id.split('-').pop());
            const size = window.testCreativeSizes?.[index] || slot.sizes.filter(Array.isArray).slice().sort((a, b) => b[0] - a[0] || b[1] - a[1])[0];
            const frame = document.createElement('iframe');
            frame.title = 'Mock advertisement ' + (index + 1);
            frame.style.cssText = size === 'fluid' ? 'display:block;width:100%;height:420px;border:0' : 'display:block;width:' + size[0] + 'px;height:' + size[1] + 'px;border:0';
            frame.srcdoc = '<!doctype html><style>html,body{margin:0;height:100%;background:#123c59;color:white;font:24px sans-serif;display:grid;place-items:center}</style><body>Mock creative ' + (size === 'fluid' ? 'fluid' : size.join(' × ')) + '</body>';
            document.getElementById(id).appendChild(frame);
            queueMicrotask(() => { for (const fn of [...listeners]) fn({ slot, isEmpty: false, size }); });
        },
    };
    queue.forEach(fn => fn());
})();`;

function fixture({ narrow = false, hiddenOnly = false, siblings = false, rightAligned = false, clipping = false } = {}) {
    // Recorded Natega host widths; conditional .joeredirect #4 starts hidden.
    // The article and sibling stress arrangement are representative fixtures,
    // not a claim that the publisher's complete DOM was reproduced.
    const widths = narrow ? [240, 600, 600, 600, 605, 605] : [718, 600, 600, 600, 605, 605];
    const indices = hiddenOnly ? [3] : siblings || rightAligned || clipping ? [0] : [0, 1, 2, 3, 4, 5];
    const slots = indices.map(index => `<section class="slot-position ${hostClasses[index]}" id="position-${index}" style="width:${widths[index]}px;${rightAligned ? 'margin-left:auto;margin-right:0;' : ''}${clipping ? 'overflow:hidden;' : ''}${index === 3 ? 'display:none' : ''}"><h2>Conditional joeredirect ${index + 1}</h2><div class="joeredirect"><div class="hm-ad" data-placement="${codes[index]}" style="${narrow && index === 4 ? 'padding:0 16px;box-sizing:border-box' : ''}"></div></div><p class="below-ad">Following publisher content ${index + 1}</p></section>`).join('');
    return `<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
        body{margin:0;color:#18283c;background:#edf3f8;font:16px/1.4 system-ui,sans-serif}
        article{width:calc(100% - 32px);max-width:1100px;margin:auto}h1{font-size:24px}h2{font-size:16px}
        .slot-position{max-width:100%;margin:16px auto;display:flow-root;background:white;outline:1px solid #c4d4e4}
        .joeredirect{display:flow-root}.hm-ad{float:left;text-align:left;margin-left:0}.hm-direct-google-gpt{margin-right:0}
        iframe{max-width:100%}.below-ad{clear:both;background:#dcecd8;padding:8px;margin:0}
        .stress-grid{display:grid;grid-template-columns:minmax(0,240px) minmax(0,1fr);gap:24px}.stress-grid .slot-position{margin:0}
        .side-content{min-width:0;background:#ffdada;outline:2px solid #b92727;min-height:500px;padding:4px;box-sizing:border-box}
    </style><body><article><h1>Responsive viewport inventory fixture</h1>${siblings ? '<div class="stress-grid">' + slots + '<aside class="side-content">Publisher sibling column</aside></div>' : slots}</article><script src="${CDN}/hm-loader.js" data-site-key="${SITE}" data-config-base="${CDN}/configs" data-environment="production" data-config-version="1"></script></body></html>`;
}

async function open(page, options = {}) {
    const requests = [];
    const unexpected = [];
    const config = configuration(options);
    page.on('request', request => requests.push(request.url()));
    if (options.creativeSizes) await page.addInitScript(sizes => { window.testCreativeSizes = sizes; }, options.creativeSizes);
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin === 'https://publisher.example') return route.fulfill({ contentType: 'text/html', body: fixture(options) });
        if (url.origin === CDN) {
            if (url.pathname === '/hm-loader.js') return route.fulfill({ contentType: 'application/javascript', body: loader });
            if (url.pathname === '/runtime/gpt/viewport.js') return route.fulfill({ contentType: 'application/javascript', body: runtime });
            if (url.pathname === '/configs/' + SITE + '/production.json') return route.fulfill({ contentType: 'application/json', body: JSON.stringify(config) });
            if (url.pathname === '/configs/_global/control.json') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ schemaVersion: 2, controls: config.controls }) });
            return route.fulfill({ status: 404, body: '' });
        }
        if (url.origin === GATE) return route.fulfill({ contentType: 'text/html', body: `<!doctype html><script>addEventListener('message', event => { if(event.data?.type === 'HORUS_TRAFFIC_GATE_HELLO') { window.reply = type => parent.postMessage({...event.data, type, serverVerified: true}, event.origin); document.documentElement.setAttribute('data-test-ready', '1'); } });</script>` });
        if (url.href === GPT) {
            if (options.gptReady) await options.gptReady;
            return route.fulfill({ contentType: 'application/javascript', body: google });
        }
        unexpected.push(url.href);
        return route.abort('blockedbyclient');
    });
    await page.goto('https://publisher.example/article', { waitUntil: options.gptReady ? 'domcontentloaded' : 'load' });
    await expect.poll(() => page.evaluate(() => window.HorusMediaLoader?.getConfig?.()?.configVersion)).toBe(1);
    return { requests, unexpected };
}

async function capture(page, testInfo, name) {
    const observations = await page.evaluate(() => {
        const rect = node => { const b = node.getBoundingClientRect(); return { x: b.x, y: b.y, width: b.width, height: b.height, right: b.right, bottom: b.bottom }; };
        const sibling = document.querySelector('.side-content');
        return {
            viewport: innerWidth, usableWidth: document.documentElement.clientWidth, pageWidth: document.documentElement.scrollWidth,
            slots: [...document.querySelectorAll('[data-hm-gpt-direct="1"]')].map(node => {
                const frame = node.querySelector('iframe');
                const root = node.closest('.hm-ad');
                const below = node.closest('.slot-position').querySelector('.below-ad');
                const a = frame && rect(frame); const b = sibling && rect(sibling);
                return { id: node.id, sizes: JSON.parse(node.getAttribute('data-hm-gpt-eligible-sizes') || '[]'),
                    state: node.getAttribute('data-hm-gpt-status'), layout: node.getAttribute('data-hm-gpt-layout'),
                    container: rect(node), root: rect(root), creative: a, below: rect(below), sibling: b,
                    siblingOverlap: a && b ? Math.max(0, Math.min(a.right,b.right)-Math.max(a.x,b.x)) * Math.max(0, Math.min(a.bottom,b.bottom)-Math.max(a.y,b.y)) : 0 };
            }),
        };
    });
    const geometryPath = testInfo.outputPath(name + '-geometry.json');
    await writeFile(geometryPath, JSON.stringify(observations, null, 2));
    await testInfo.attach(name + '-geometry', { path: geometryPath, contentType: 'application/json' });
    const path = testInfo.outputPath(name + '.png');
    await page.screenshot({ path, fullPage: true });
    await testInfo.attach(name, { path, contentType: 'image/png' });
    return observations;
}

async function expectBuckets(page, width, count = 5) {
    const slots = await page.evaluate(() => window.testSlots.map(({ id, path, sizes }) => ({ id, path, sizes })));
    expect(slots).toHaveLength(count);
    expect(new Set(slots.map(slot => slot.id)).size).toBe(count);
    for (const slot of slots) {
        expect(slot.path).toBe('/123/shared');
        expect(slot.sizes).toEqual(expectedSizes(width));
        expect(slot.sizes.filter(size => size === 'fluid')).toHaveLength(1);
    }
}

for (const narrow of [false, true]) test(`all visible ${narrow ? '240px and padded' : 'Natega-width'} DIVs request the full viewport bucket and preserve the widest fixed pixels`, async ({ page }, testInfo) => {
    const { requests, unexpected } = await open(page, { narrow });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(5);
    await expectBuckets(page, page.viewportSize().width);
    const observations = await capture(page, testInfo, narrow ? 'narrow-padded-fixed' : 'natega-fixed');
    const creativeSize = maximumCreative(page.viewportSize().width);
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
    for (const slot of observations.slots) {
        expect([slot.creative.width, slot.creative.height]).toEqual(creativeSize);
        expect([slot.container.width, slot.container.height]).toEqual(creativeSize);
        expect(slot.creative.x).toBeGreaterThanOrEqual(0);
        expect(slot.creative.right).toBeLessThanOrEqual(observations.usableWidth);
        expect(slot.below.y).toBeGreaterThanOrEqual(slot.creative.bottom);
        if (slot.creative.width > slot.root.width) expect(slot.layout).toBe('publisher-container-too-narrow');
    }
    expect(await page.evaluate(() => window.testDisplays.includes('hm-viewport-member-3'))).toBe(false);
    expect(requests.filter(url => url === GPT)).toHaveLength(1);
    expect(requests.filter(url => url.endsWith('/runtime/gpt/viewport.js'))).toHaveLength(1);
    expect(unexpected).toEqual([]);
});

test('fluid spans the available content width and its late height remains in flow', async ({ page }, testInfo) => {
    await open(page, { narrow: true, creativeSizes: Array(6).fill('fluid') });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(5);
    await expectBuckets(page, page.viewportSize().width);
    for (const index of [0, 1, 2, 4, 5]) {
        const root = page.locator('[data-placement="' + codes[index] + '"]');
        const frame = root.locator('iframe');
        const available = await root.evaluate(node => node.clientWidth - parseFloat(getComputedStyle(node).paddingLeft) - parseFloat(getComputedStyle(node).paddingRight));
        expect((await frame.boundingBox()).width).toBeCloseTo(available, 0);
        await frame.evaluate(node => { node.style.height = '650px'; });
        await expect.poll(async () => (await root.boundingBox()).height).toBeGreaterThanOrEqual(650);
    }
    const observations = await capture(page, testInfo, 'fluid-grown');
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
    for (const slot of observations.slots) {
        expect(slot.creative.height).toBe(650);
        expect(slot.below.y).toBeGreaterThanOrEqual(slot.creative.bottom);
    }
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(5);
});

test('a pending GPT request rechecks the viewport, and rendered slots never redefine on resize', async ({ page }, testInfo) => {
    let release;
    const gptReady = new Promise(resolve => { release = resolve; });
    const { requests } = await open(page, { narrow: true, gptReady });
    await expect.poll(() => requests.filter(url => url === GPT).length).toBe(1);
    expect(await page.evaluate(() => window.testSlots || [])).toHaveLength(0);
    const nextWidth = page.viewportSize().width < 768 ? 1280 : 390;
    await page.setViewportSize({ width: nextWidth, height: 900 });
    release();
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(5);
    await expectBuckets(page, nextWidth);
    const before = await page.evaluate(() => window.testSlots.map(({ id, sizes }) => ({ id, sizes })));
    await page.setViewportSize({ width: nextWidth === 390 ? 1280 : 1024, height: 900 });
    await page.evaluate(() => {
        for (let index = 0; index < 8; index++) document.querySelector('article').appendChild(document.createElement('p'));
        dispatchEvent(new Event('resize'));
    });
    await page.waitForTimeout(250);
    expect(await page.evaluate(() => window.testSlots.map(({ id, sizes }) => ({ id, sizes })))).toEqual(before);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(5);
    expect(await page.evaluate(() => window.testDestroyedSlots)).toEqual([]);
    const observations = await capture(page, testInfo, 'resized-after-render');
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
});

test('a hidden conditional lazy DIV makes no provider request until it is revealed', async ({ page }, testInfo) => {
    const { requests } = await open(page, { hiddenOnly: true, lazy: true });
    await page.waitForTimeout(350);
    expect(requests.filter(url => url === GPT || url.endsWith('/runtime/gpt/viewport.js'))).toEqual([]);
    expect(await page.evaluate(() => window.testSlots || [])).toHaveLength(0);
    await page.locator('#position-3').evaluate(node => { node.style.display = 'flow-root'; });
    await page.locator('#position-3').scrollIntoViewIfNeeded();
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(1);
    await expectBuckets(page, page.viewportSize().width, 1);
    await capture(page, testInfo, 'hidden-revealed');
    expect(await page.evaluate(() => window.testDisplays)).toEqual(['hm-viewport-member-3']);
});

test('Traffic Gate held state prevents all provider requests, then one verified pass releases visible DIVs', async ({ page }, testInfo) => {
    const { requests } = await open(page, { gated: true });
    const gate = page.frameLocator('iframe[data-hm-traffic-gate]');
    await expect(gate.locator('html[data-test-ready="1"]')).toHaveCount(1);
    await page.evaluate(() => window.postMessage({ type: 'HORUS_TRAFFIC_GATE_PASS', protocolVersion: 1, pageNonce: 'forged' }, '*'));
    await page.waitForTimeout(250);
    expect(requests.filter(url => url === GPT || url.endsWith('/runtime/gpt/viewport.js'))).toEqual([]);
    expect(await page.evaluate(() => window.testSlots || [])).toHaveLength(0);
    await capture(page, testInfo, 'gate-held');
    await gate.locator('html').evaluate(() => window.reply('HORUS_TRAFFIC_GATE_PASS'));
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(5);
    await expectBuckets(page, page.viewportSize().width);
});

test('a narrow side-by-side publisher layout exposes overlap without shrinking the creative', async ({ page }, testInfo) => {
    await open(page, { narrow: true, siblings: true });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(1);
    await expectBuckets(page, page.viewportSize().width, 1);
    const observations = await capture(page, testInfo, 'sibling-overlap-warning');
    const slot = observations.slots[0];
    expect([slot.creative.width, slot.creative.height]).toEqual(maximumCreative(page.viewportSize().width));
    expect(slot.layout).toBe('publisher-container-too-narrow');
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
    // Viewport-only eligibility deliberately cannot guarantee safe placement
    // alongside publisher content. Keep this visible risk in test evidence.
    expect(slot.siblingOverlap).toBeGreaterThan(0);
});


test('a right-aligned 240px host does not retain horizontal layout overflow after creative clamping', async ({ page }, testInfo) => {
    await open(page, { narrow: true, rightAligned: true });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(1);
    await expectBuckets(page, page.viewportSize().width, 1);
    const observations = await capture(page, testInfo, 'right-aligned-narrow');
    const slot = observations.slots[0];
    expect([slot.creative.width, slot.creative.height]).toEqual(maximumCreative(page.viewportSize().width));
    expect(slot.creative.x).toBeGreaterThanOrEqual(0);
    expect(slot.creative.right).toBeLessThanOrEqual(observations.usableWidth);
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
});

test('a publisher clipping ancestor remains observable even when a requested creative fits the viewport', async ({ page }, testInfo) => {
    await open(page, { narrow: true, clipping: true });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(1);
    await expectBuckets(page, page.viewportSize().width, 1);
    const observations = await capture(page, testInfo, 'publisher-clipping-warning');
    const slot = observations.slots[0];
    const host = await page.locator('#position-0').boundingBox();
    const visibleWidth = Math.max(0, Math.min(host.x + host.width, slot.creative.right) - Math.max(host.x, slot.creative.x));
    expect(visibleWidth).toBeLessThan(slot.creative.width);
    expect(slot.layout).toBe('publisher-container-too-narrow');
    expect(observations.pageWidth).toBeLessThanOrEqual(observations.usableWidth);
});

test('shrinking a rendered desktop viewport documents the too-wide creative without scaling or another paid request', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await open(page, { narrow: true, rightAligned: true });
    await expect(page.locator('[data-hm-status="rendered"]')).toHaveCount(1);
    await expectBuckets(page, 1280, 1);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-hm-gpt-direct="1"]')).toHaveAttribute('data-hm-gpt-layout', 'viewport-too-narrow');
    const observations = await capture(page, testInfo, 'desktop-creative-mobile-resize-warning');
    const slot = observations.slots[0];
    expect([slot.creative.width, slot.creative.height]).toEqual([970, 250]);
    expect(slot.creative.right).toBeGreaterThan(observations.usableWidth);
    expect(observations.pageWidth).toBeGreaterThan(observations.usableWidth);
    expect(await page.evaluate(() => window.testDisplays.length)).toBe(1);
    expect(await page.evaluate(() => window.testDestroyedSlots)).toEqual([]);
});
