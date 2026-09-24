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
    await bottom.evaluate(el => {
        el.querySelector('[data-test-anchor]').remove();
        el.style.height = '110px';
    });
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
async function openReading(page, language, browserLanguages = [language]) {
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
    await page.evaluate(languages => {
        Object.defineProperty(navigator, 'languages', { configurable: true, value: languages });
        Object.defineProperty(navigator, 'language', { configurable: true, value: languages[0] || '' });
    }, browserLanguages);
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
        await expect(page.getByRole('button', { name: language === 'ar' ? 'شاهد الإعلان' : 'Watch ad', exact: true })).toBeFocused();
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
        await page.getByRole('button', { name: 'Watch ad', exact: true }).click();
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
    await page.evaluate(languages => {
        Object.defineProperty(navigator, 'languages', { configurable: true, value: languages });
        Object.defineProperty(navigator, 'language', { configurable: true, value: languages[0] || '' });
    }, options.browserLanguages || ['ar']);
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
    await page.getByRole('button', {name:'شاهد الإعلان'}).click();
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
            if (mode === 'decline') await page.getByRole('button', {name: 'المتابعة بدون إعلان'}).click();
            else {
                await page.getByRole('button', {name:'شاهد الإعلان'}).click();
                await page.evaluate(() => window.emitGpt('rewardedSlotClosed'));
            }
        }
        await expect(page.locator('#reward')).toBeHidden();
        await expect(page.locator('article')).toBeVisible();
        expect(await page.evaluate(() => window.grants)).toBe(0);
        expect(await page.evaluate(() => Object.values(window.events).flat().length)).toBe(0);
    });
}

for (const action of ['watch', 'decline']) {
    test(`GPT rewarded ${action} works with inherited pointer-events and throwing cleanup`, async ({page}) => {
        await openGptRewarded(page);
        await page.evaluate(() => {
            document.body.style.pointerEvents = 'none';
            window.service.removeEventListener = () => { throw new Error('provider cleanup failed'); };
            document.querySelector('#previous').focus = () => { throw new Error('focus unavailable'); };
            window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { throw new Error('inventory expired'); }});
        });
        await page.getByRole('button', {name: action === 'watch' ? 'شاهد الإعلان' : 'المتابعة بدون إعلان'}).click();
        await expect(page.locator('#reward')).toBeHidden();
        expect(await page.evaluate(() => window.grants)).toBe(0);
        // A late Google callback must not resurrect the dismissed dialog.
        await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { return true; }}));
        await expect(page.locator('#reward')).toBeHidden();
    });
}

test('GPT ready prompt expires safely without a grant', async ({page}) => {
    await page.clock.install();
    await openGptRewarded(page);
    await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { return true; }}));
    await page.clock.fastForward(120001);
    await expect(page.locator('#reward')).toBeHidden();
    expect(await page.evaluate(() => window.grants)).toBe(0);
});

test('VAST rewarded recovers when IMA never responds', async ({page}) => {
    await page.clock.install();
    await openReading(page, 'en');
    await page.evaluate(() => {
        window.google.ima.AdsLoader.prototype.requestAds = function () { window.requests++; };
        document.body.style.pointerEvents = 'none';
        document.querySelector('#previous').focus = () => { throw new Error('focus unavailable'); };
    });
    await page.getByRole('button', {name: 'Watch ad', exact:true}).click();
    expect(await page.evaluate(() => window.requests)).toBe(1);
    await page.clock.fastForward(15001);
    await expect(page.locator('#reward')).toBeHidden();
    expect(await page.evaluate(() => window.grants)).toBe(0);
});

test('VAST close remains available while SDK cleanup throws', async ({page}) => {
    await openReading(page, 'en');
    await page.getByRole('button', {name:'Watch ad', exact:true}).click();
    await page.evaluate(() => { window.manager.destroy = () => { throw new Error('cleanup failed'); }; });
    await page.getByRole('button', {name:'Close rewarded video'}).click();
    await expect(page.locator('#reward')).toBeHidden();
    expect(await page.evaluate(() => window.grants)).toBe(0);
});

for (const provider of ['gpt', 'vast']) {
    async function openPrompt(page, languages = ['en-US']) {
        if (provider === 'vast') await openReading(page, 'ar', languages);
        else {
            await openGptRewarded(page, {browserLanguages: languages});
            await page.evaluate(() => window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { window.visibleCalls++; return true; }}));
        }
    }

    test(`${provider} neutral prompt survives publisher CSS and X dismisses without opt-in`, async ({page}) => {
        await openPrompt(page);
        // Publisher global styles must not make our close control invisible or unclickable.
        await page.addStyleTag({content: 'button { display:none !important; visibility:hidden !important; pointer-events:none !important; color:transparent !important; background:magenta !important; } strong,p { color:transparent !important; }'});
        const prompt = page.getByRole('dialog');
        const close = prompt.getByRole('button', {name: 'Close', exact: true});
        await expect(prompt).toHaveAttribute('lang', 'en'); // Browser wins over Arabic page.
        await expect(prompt).toHaveAttribute('dir', 'ltr');
        await expect(prompt).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        await expect(prompt.locator('[data-hm-reward-disclosure]')).toHaveText('Watching is entirely optional. Closing this message will not block the content.');
        await expect(close).toBeVisible();
        await expect(close).toHaveCSS('color', 'rgb(17, 24, 39)');
        const box = await close.boundingBox();
        expect(box.width).toBeGreaterThanOrEqual(44);
        expect(box.height).toBeGreaterThanOrEqual(44);
        await close.click();
        await expect(page.locator('#reward')).toBeHidden();
        await expect(page.locator('article')).toBeVisible();
        expect(await page.evaluate(() => window.grants)).toBe(0);
        expect(await page.evaluate(provider => provider === 'gpt' ? window.visibleCalls : window.requests, provider)).toBe(0);
        if (provider === 'gpt') {
            await page.evaluate(() => {
                window.emitGpt('rewardedSlotReady', {makeRewardedVisible() { window.visibleCalls++; return true; }});
                window.emitGpt('rewardedSlotGranted');
            });
            await expect(page.locator('#reward')).toBeHidden();
            expect(await page.evaluate(() => window.grants)).toBe(0);
        }
    });

    test(`${provider} keyboard focus includes X and returns to publisher content`, async ({page}) => {
        await openPrompt(page);
        const watch = page.getByRole('button', {name:'Watch ad', exact:true});
        const close = page.getByRole('button', {name:'Close', exact:true});
        await expect(watch).toBeFocused();
        await page.keyboard.press('Shift+Tab');
        await expect(close).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(watch).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('button', {name:'Continue without an ad'})).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(close).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('#reward')).toBeHidden();
        await expect(page.locator('#previous')).toBeFocused();
        expect(await page.evaluate(() => window.grants)).toBe(0);
    });

    test(`${provider} uses supported browser fallback and keeps X visible in landscape`, async ({page}) => {
        await page.setViewportSize({width: 667, height: 320});
        await openPrompt(page, ['ja-JP', 'fr-FR']);
        const prompt = page.getByRole('dialog');
        await expect(prompt).toHaveAttribute('lang', 'fr');
        const close = page.getByRole('button', {name:'Fermer', exact:true});
        const box = await close.boundingBox();
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(320);
        await close.click();
        await expect(page.locator('#reward')).toBeHidden();
    });

    test(`${provider} falls back to page language with RTL when browser languages are unsupported`, async ({page}) => {
        await openPrompt(page, ['ja-JP']);
        await expect(page.getByRole('dialog')).toHaveAttribute('lang', 'ar');
        await expect(page.getByRole('dialog')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('[data-hm-reward-disclosure]')).toContainText('اختيارية تمامًا');
        await page.getByRole('button', {name:'إغلاق', exact:true}).click();
        await expect(page.locator('#reward')).toBeHidden();
        expect(await page.evaluate(() => window.grants)).toBe(0);
    });
}
