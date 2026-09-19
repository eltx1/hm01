import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');
const gptRuntime = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

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
    }, options);
    await page.addScriptTag({content: gptRuntime});
}

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
