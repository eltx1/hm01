import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const runtime = await readFile(new URL('../../public/assets/hm-video-direct.js', import.meta.url), 'utf8');

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
