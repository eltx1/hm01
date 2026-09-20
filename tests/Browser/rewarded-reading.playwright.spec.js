import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';

const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const gptRuntime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const placementLoader = applyPlacementPresetTransform(await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8'));

test('floating video clears rendered bottom anchors, follows resize and stops after dismissal', async ({ page }) => {
    await page.route('https://reader.example/**', route => route.fulfill(route.request().url().endsWith('/ad.js')
        ? { contentType: 'application/javascript', body: 'window.providerLoads = (window.providerLoads || 0) + 1;' }
        : { contentType: 'text/html', body: '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body><article>Content</article><script id="loader" data-site-key="GEOMETRY" data-config-version="1"></script></body>' }));
    await page.goto('https://reader.example/geometry');
    await page.evaluate(() => {
        window.__HM_DISABLE_AUTOBOOT__ = true;
        const placements = [
            { code: 'bottom', type: 'STICKY', format: { settings: { autoMount: true, position: 'bottom', closeable: true } } },
            { code: 'floating', type: 'VIDEO', format: { settings: { autoMount: true, position: 'bottom_right', closeable: true } } },
        ].map(p => ({ ...p, enabled: true, status: 'active', renderer: 'DIRECT_JS', sizes: [[320, 90]] }));
        const config = {
            siteKey: 'GEOMETRY', configVersion: 1, status: 'active', allowedHostnames: ['reader.example'],
            controls: { gamDisabled: true, prebidDisabled: true }, placements,
            directDemand: { enabled: true, placements: Object.fromEntries(placements.map(p => [p.code, {
                enabled: true, candidates: [{ network: 'TEST', tag: { scripts: [{ url: 'https://reader.example/ad.js' }], initialization: { type: 'NONE' }, assumeLoadedIsSuccess: true } }],
            }])) },
        };
        window.fetch = async () => ({ ok: true, json: async () => config });
    });
    await page.addScriptTag({ content: placementLoader });
    await page.evaluate(() => window.HorusMediaLoader.boot({ script: document.getElementById('loader') }));
    const bottom = page.locator('[data-placement="bottom"]');
    const floating = page.locator('[data-placement="floating"]');
    await expect(bottom).toHaveAttribute('data-hm-status', 'rendered');
    await bottom.evaluate(el => { el.style.width = '100vw'; el.style.height = '90px'; });
    await expect.poll(async () => {
        const a = await bottom.boundingBox(), b = await floating.boundingBox();
        return a.y - b.y - b.height;
    }).toBeGreaterThanOrEqual(15);
    // A later render/presentation pass must not undo the measured clearance.
    await floating.evaluate(el => el.style.setProperty('bottom', '16px', 'important'));
    await expect.poll(async () => {
        const a = await bottom.boundingBox(), b = await floating.boundingBox();
        return a.y - b.y - b.height;
    }).toBeGreaterThanOrEqual(15);
    await bottom.evaluate(el => { el.style.height = '130px'; });
    await expect.poll(async () => {
        const a = await bottom.boundingBox(), b = await floating.boundingBox();
        return a.y - b.y - b.height;
    }).toBeGreaterThanOrEqual(15);
    // Provider-owned iframe extending above a collapsed wrapper.
    await bottom.evaluate(el => {
        el.style.height = '0px';
        const frame = document.createElement('iframe');
        frame.setAttribute('data-test-anchor', '1');
        frame.style.cssText = 'position:absolute;bottom:0;left:0;width:100%;height:110px;border:0';
        el.appendChild(frame);
    });
    await expect.poll(async () => {
        const a = await bottom.locator('[data-test-anchor]').boundingBox(), b = await floating.boundingBox();
        return a.y - b.y - b.height;
    }).toBeGreaterThanOrEqual(15);
    await bottom.locator('[data-hm-placement-close]').click();
    await expect.poll(() => floating.evaluate(el => parseFloat(getComputedStyle(el).bottom))).toBe(16);
    // Count writes after layout settles: observers must not trigger themselves.
    await floating.evaluate(el => {
        window.geometryWrites = 0;
        new MutationObserver(records => { window.geometryWrites += records.length; }).observe(el, { attributes: true, attributeFilter: ['style'] });
    });
    await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 250)));
    expect(await page.evaluate(() => window.geometryWrites)).toBe(0);
    expect(await page.evaluate(() => window.providerLoads)).toBe(1);
    await floating.locator('[data-hm-placement-close]').click();
    await expect.poll(() => floating.evaluate(el => el.__hmClearance.stopped)).toBe(true);
});

// No paid inventory or external requests: exercise the real DOM/runtime using
// a deterministic IMA boundary. Real demand/no-fill remains a publisher smoke test.
async function openReading(page, language) {
    await page.route('https://reader.example/**', route => route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><html lang="${language}"><meta name="viewport" content="width=device-width,initial-scale=1"><body>
          <article><h1>Publisher article</h1><button id="previous">Reading position</button><p>Original content stays available.</p></article>
          <div id="reward" data-hm-video-direct="1" data-hm-video-rewarded="1" data-hm-reward-experience="continue-reading"
           data-hm-vast-url="${Buffer.from('https://ads.example/vast').toString('base64')}" data-hm-reward-cooldown-seconds="900"></div></body></html>`,
    }));
    await page.goto('https://reader.example/article');
    await page.locator('#previous').focus();
    await page.evaluate(() => {
        window.requests = 0;
        window.grants = 0;
        window.addEventListener('horus:rewarded-granted', () => window.grants++);
        class Manager {
            constructor() { this.events = {}; window.manager = this; }
            addEventListener(name, fn) { this.events[name] = fn; }
            init() {}
            start() { this.events.started?.(); }
            setVolume() {}
            destroy() {}
            resize() {}
        }
        window.google = { ima: {
            AdDisplayContainer: class { initialize() {} },
            AdsLoader: class {
                constructor() { this.events = {}; }
                addEventListener(name, fn) { this.events[name] = fn; }
                requestAds() { window.requests++; this.events.manager({ getAdsManager: () => new Manager() }); }
            },
            AdsRequest: class {}, AdsRenderingSettings: class {},
            AdsManagerLoadedEvent: { Type: { ADS_MANAGER_LOADED: 'manager' } },
            AdErrorEvent: { Type: { AD_ERROR: 'error' } },
            AdEvent: { Type: { LOADED: 'loaded', STARTED: 'started', COMPLETE: 'complete', SKIPPED: 'skipped', ALL_ADS_COMPLETED: 'all' } },
            ViewMode: { NORMAL: 'normal' },
        } };
    });
    await page.addScriptTag({ content: runtime });
}

for (const language of ['en', 'ar']) {
    test(`reading prompt: ${language}, accessible dismissal and mobile fit`, async ({ page }, testInfo) => {
        await openReading(page, language);
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        const bounds = await dialog.boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width);
        expect(await page.evaluate(() => window.requests)).toBe(0);
        await expect(page.getByRole('button', { name: language === 'ar' ? 'شاهد الإعلان واستكمل القراءة' : 'Watch ad and continue reading', exact: true })).toBeFocused();
        await testInfo.attach(`reading-${language}`, { body: await page.screenshot(), contentType: 'image/png' });
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(page.locator('#previous')).toBeFocused();
        expect(await page.evaluate(() => window.grants)).toBe(0);
    });
}

for (const outcome of ['complete', 'error', 'close']) {
    test(`reading resumes after ${outcome}`, async ({ page }) => {
        await openReading(page, 'en');
        await page.getByRole('button', { name: 'Watch ad and continue reading', exact: true }).click();
        expect(await page.evaluate(() => window.requests)).toBe(1);
        if (outcome === 'close') await page.getByRole('button', { name: 'Close rewarded video' }).click();
        else await page.evaluate(outcome => {
            if (outcome === 'complete') { window.manager.events.complete(); window.manager.events.all(); }
            else window.manager.events.error({ getError: () => new Error('no-fill') });
        }, outcome);
        await expect(page.locator('#reward')).toBeHidden();
        await expect(page.locator('#previous')).toBeFocused();
        expect(await page.evaluate(() => window.grants)).toBe(outcome === 'complete' ? 1 : 0);
        await expect(page.locator('article')).toBeVisible();
    });
}

async function openGptRewarded(page, options = {}) {
    await page.route('https://reader.example/**', route => route.fulfill({ contentType: 'text/html', body:
        '<!doctype html><html lang="ar"><meta name="viewport" content="width=device-width,initial-scale=1"><body><button id="previous">Read</button><article>Publisher content</article><div id="reward" data-hm-gpt-direct="1" data-hm-gpt-rewarded="1" data-hm-gpt-ad-unit-path="/123/rewarded"></div></body></html>' }));
    await page.goto('https://reader.example/gpt');
    await page.locator('#previous').focus();
    await page.evaluate(options => {
        window.events = {}; window.grants = 0; window.visibleCalls = 0; window.destroyed = 0; window.refreshes = 0;
        window.addEventListener('horus:rewarded-granted', () => window.grants++);
        window.slot = { addService() { return this; } };
        window.service = {
            addEventListener(name, fn) { (window.events[name] ||= []).push(fn); },
            removeEventListener(name, fn) { window.events[name] = window.events[name].filter(x => x !== fn); },
            refresh(slots) { if (slots.length === 1 && slots[0] === window.slot) window.refreshes++; },
        };
        window.emitGpt = (name, extra = {}) => (window.events[name] || []).slice().forEach(fn => fn({slot: window.slot, ...extra}));
        window.googletag = {
            apiReady: true, cmd: {push: fn => fn()}, enums: {OutOfPageFormat: {REWARDED: 'rewarded'}},
            defineOutOfPageSlot(path, format) { window.definition = {path, format}; return options.unsupported ? null : window.slot; },
            pubads() { return window.service; }, enableServices() {}, display(slot) { window.displayedOwnSlot = slot === window.slot; },
            getConfig() { return {disableInitialLoad: !!options.disableInitialLoad}; },
            destroySlots(slots) { if (slots.length === 1 && slots[0] === window.slot) window.destroyed++; },
        };
        if (options.capped) localStorage.setItem('hm:gpt:rewarded:v1:/123/rewarded', String(Date.now()));
        if (options.grantAge) localStorage.setItem('hm:gpt:rewarded:v1:/123/rewarded', String(Date.now() - options.grantAge));
    }, options);
    await page.addScriptTag({content: gptRuntime});
}

test('GPT rewarded permits a new page after one minute, not fifteen', async ({page}) => {
    await openGptRewarded(page, {grantAge: 61000});
    expect(await page.evaluate(() => window.displayedOwnSlot)).toBe(true);
    expect(await page.evaluate(() => window.visibleCalls)).toBe(0);
    await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { window.visibleCalls++; return true; }}));
    await expect(page.getByRole('dialog')).toBeVisible();
});

test('GPT rewarded uses official slot and grants only on Google grant, once', async ({page}) => {
    await openGptRewarded(page, {disableInitialLoad: true});
    expect(await page.evaluate(() => window.definition)).toEqual({path:'/123/rewarded', format:'rewarded'});
    expect(await page.evaluate(() => window.refreshes)).toBe(1);
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { window.visibleCalls++; return true; }}));
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(await page.evaluate(() => window.visibleCalls)).toBe(0);
    await page.getByRole('button', {name:'شاهد الإعلان واستكمل القراءة'}).click();
    expect(await page.evaluate(() => window.visibleCalls)).toBe(1);
    await page.evaluate(() => window.emitGpt('rewardedSlotVideoCompleted'));
    expect(await page.evaluate(() => window.grants)).toBe(0);
    await page.evaluate(() => { window.emitGpt('rewardedSlotGranted'); window.emitGpt('rewardedSlotGranted'); window.emitGpt('rewardedSlotClosed'); });
    expect(await page.evaluate(() => window.grants)).toBe(1);
    expect(await page.evaluate(() => window.destroyed)).toBe(1);
    await expect(page.locator('#previous')).toBeFocused();
});

for (const mode of ['unsupported', 'capped', 'empty', 'decline', 'closed']) {
    test(`GPT rewarded ${mode} leaves content usable without granting`, async ({page}) => {
        await openGptRewarded(page, {[mode]: true});
        if (mode === 'empty') await page.evaluate(() => window.emitGpt('slotRenderEnded', {isEmpty: true}));
        if (mode === 'decline' || mode === 'closed') {
            await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { return true; }}));
            if (mode === 'decline') await page.getByRole('button', {name: 'متابعة القراءة الآن'}).click();
            else {
                await page.getByRole('button', {name:'شاهد الإعلان واستكمل القراءة'}).click();
                await page.evaluate(() => window.emitGpt('rewardedSlotClosed'));
            }
        }
        await expect(page.locator('#reward')).toBeHidden();
        await expect(page.locator('article')).toBeVisible();
        expect(await page.evaluate(() => window.grants)).toBe(0);
        expect(await page.evaluate(() => Object.values(window.events).flat().length)).toBe(0);
    });
}
