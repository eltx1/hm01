import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

// Run the shipped form controller against a small, server-rendered form fixture.
// PHP feature tests cover the real Blade markup, validation and persistence.
const blade = await readFile(new URL('../../resources/views/admin/demand/quick.blade.php', import.meta.url), 'utf8');
const scripts = [...blade.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1])
    .filter(script => script.includes("getElementById('quick-site')"));
if (scripts.length !== 1) throw new Error('Expected one Quick Monetize form controller.');
const blockedExpression = "{{ $hasBlockingReason ? 'true' : 'false' }}";
if (!scripts[0].includes(blockedExpression)) throw new Error('Expected the server-rendered activation control.');
const controller = scripts[0].replace(blockedExpression, 'false');

const unitPath = '/1234567/publisher/display';
const vastUrl = 'https://video.example/vast?slot=outstream&format=xml';

async function open(page) {
    await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
        <form id="quick-monetize-form">
            <input type="hidden" name="placement_mode" id="quick-placement-mode" value="new">
            <select name="site_id" id="quick-site"><option value="site-one" selected>First site</option><option value="site-two">Second site</option></select>
            <label id="quick-preset-wrap">Ad format
                <select name="placement_preset" id="quick-preset">
                    <option value="responsive_display" data-placement-type="DISPLAY">Responsive Display</option>
                    <option value="sticky_top" data-placement-type="STICKY">Sticky Top</option>
                    <option value="sticky_bottom" data-placement-type="STICKY">Sticky Bottom</option>
                    <option value="rewarded" data-placement-type="REWARDED">Rewarded Video</option>
                    <option value="video_floating" data-placement-type="VIDEO">Floating Video</option>
                </select>
            </label>
            <input type="checkbox" id="quick-use-existing">
            <label id="quick-existing-wrap">Existing placement
                <select name="placement_id" id="quick-placement">
                    <option value="">Select placement</option>
                    <option value="existing-display" data-site-id="site-one" data-placement-type="DISPLAY" data-preset="responsive_display">Responsive member</option>
                    <option value="existing-sticky" data-site-id="site-one" data-placement-type="STICKY" data-preset="sticky_bottom">Sticky Bottom</option>
                    <option value="existing-rewarded" data-site-id="site-one" data-placement-type="REWARDED" data-preset="rewarded">Rewarded</option>
                    <option value="existing-video" data-site-id="site-one" data-placement-type="VIDEO" data-preset="video_floating">Video</option>
                    <option value="second-display" data-site-id="site-two" data-placement-type="DISPLAY" data-preset="responsive_display">Other site's responsive</option>
                </select>
                <span id="quick-placement-help"></span>
            </label>
            <label id="quick-input-type-wrap">Ad input
                <select name="tag_input_type" id="quick-input-type">
                    <option value="PROVIDER_TAG">Full provider tag</option>
                    <option value="GAM_AD_UNIT_PATH">GAM ad unit path</option>
                </select>
            </label>
            <label><span id="quick-tag-label"></span><textarea name="tag" id="quick-tag" rows="12"></textarea></label>
            <p id="quick-tag-help"></p><p id="quick-size-help"></p>
            <button id="quick-submit">Activate Ad</button>
        </form>
    </body></html>`);
    await page.addScriptTag({ content: controller });
    await expect(page.locator('#quick-submit')).toBeEnabled();
}

async function formData(page) {
    return page.locator('#quick-monetize-form').evaluate(form => Object.fromEntries(new FormData(form)));
}

test('a responsive GAM path stays responsive and submits an explicit path input', async ({ page }) => {
    await open(page);
    await page.locator('#quick-input-type').selectOption('GAM_AD_UNIT_PATH');
    await page.locator('#quick-tag').fill(unitPath);

    await expect(page.locator('#quick-preset')).toHaveValue('responsive_display');
    await expect(page.locator('#quick-tag')).toHaveAttribute('rows', '2');
    await expect(page.locator('#quick-tag-label')).toHaveText('Google Ad Manager ad unit path');
    await expect(page.locator('#quick-size-help')).toContainText('container');
    expect(await formData(page)).toMatchObject({
        placement_mode: 'new', placement_preset: 'responsive_display',
        tag_input_type: 'GAM_AD_UNIT_PATH', tag: unitPath,
    });
    expect(await formData(page)).not.toHaveProperty('placement_id');

    const providerTag = '<div id="provider-slot"></div><script src="https://provider.example/ad.js"></script>';
    await page.locator('#quick-input-type').selectOption('PROVIDER_TAG');
    await page.locator('#quick-tag').fill(providerTag);
    await expect(page.locator('#quick-tag')).toHaveAttribute('rows', '12');
    expect(await formData(page)).toMatchObject({
        placement_preset: 'responsive_display', tag_input_type: 'PROVIDER_TAG', tag: providerTag,
    });
});

test('changing between sticky surfaces and Rewarded retains the path and selected format', async ({ page }) => {
    await open(page);
    await page.locator('#quick-input-type').selectOption('GAM_AD_UNIT_PATH');
    await page.locator('#quick-tag').fill(unitPath);

    for (const preset of ['sticky_top', 'sticky_bottom', 'rewarded']) {
        await page.locator('#quick-preset').selectOption(preset);
        await expect(page.locator('#quick-input-type')).toBeEnabled();
        await expect(page.locator('#quick-input-type')).toHaveValue('GAM_AD_UNIT_PATH');
        await expect(page.locator('#quick-tag')).toHaveValue(unitPath);
        expect(await formData(page)).toMatchObject({ placement_preset: preset, tag_input_type: 'GAM_AD_UNIT_PATH', tag: unitPath });
        if (preset === 'rewarded') {
            await expect(page.locator('#quick-tag-help')).toContainText('official GPT Rewarded');
            await expect(page.locator('#quick-tag-help')).toContainText('opts in');
        } else {
            await expect(page.locator('#quick-size-help')).toContainText('close control');
        }
    }
});

test('floating video keeps the VAST workflow and restores the chosen input when returning to display', async ({ page }) => {
    await open(page);
    await page.locator('#quick-input-type').selectOption('GAM_AD_UNIT_PATH');
    await page.locator('#quick-tag').fill(unitPath);
    await page.locator('#quick-preset').selectOption('video_floating');

    await expect(page.locator('#quick-input-type-wrap')).toBeHidden();
    await expect(page.locator('#quick-input-type')).toBeDisabled();
    await expect(page.locator('#quick-tag-label')).toContainText('VAST URL');
    await expect(page.locator('#quick-tag')).toHaveAttribute('rows', '12');
    await page.locator('#quick-tag').fill(vastUrl);
    expect(await formData(page)).toMatchObject({ placement_preset: 'video_floating', tag: vastUrl });
    expect(await formData(page)).not.toHaveProperty('tag_input_type');

    await page.locator('#quick-preset').selectOption('responsive_display');
    await expect(page.locator('#quick-input-type-wrap')).toBeVisible();
    await expect(page.locator('#quick-input-type')).toBeEnabled();
    await expect(page.locator('#quick-input-type')).toHaveValue('GAM_AD_UNIT_PATH');
    await page.locator('#quick-tag').fill(unitPath);
    expect(await formData(page)).toMatchObject({ placement_preset: 'responsive_display', tag_input_type: 'GAM_AD_UNIT_PATH', tag: unitPath });
});

test('pasting a provider VAST URL retains the existing automatic floating-video selection', async ({ page }) => {
    await open(page);
    await page.locator('#quick-tag').fill(vastUrl);
    await expect(page.locator('#quick-preset')).toHaveValue('video_floating');
    await expect(page.locator('#quick-input-type-wrap')).toBeHidden();
    expect(await formData(page)).toMatchObject({ placement_preset: 'video_floating', tag: vastUrl });
    expect(await formData(page)).not.toHaveProperty('tag_input_type');

    // An explicitly chosen non-video format must not be silently reassigned.
    await page.locator('#quick-preset').selectOption('sticky_top');
    await page.locator('#quick-tag').fill('https://provider.example/tag');
    await expect(page.locator('#quick-preset')).toHaveValue('sticky_top');
});

test('existing placements use their own surface and stay scoped to the selected website', async ({ page }) => {
    await open(page);
    await page.locator('#quick-input-type').selectOption('GAM_AD_UNIT_PATH');
    await page.locator('#quick-tag').fill(unitPath);
    await page.locator('#quick-use-existing').check();
    await expect(page.locator('#quick-preset')).toBeDisabled();

    for (const placement of ['existing-display', 'existing-sticky', 'existing-rewarded']) {
        await page.locator('#quick-placement').selectOption(placement);
        expect(await formData(page)).toMatchObject({ placement_mode: 'existing', placement_id: placement, tag_input_type: 'GAM_AD_UNIT_PATH', tag: unitPath });
        expect(await formData(page)).not.toHaveProperty('placement_preset');
    }
    await expect(page.locator('#quick-tag-help')).toContainText('official GPT Rewarded');
    await page.locator('#quick-placement').selectOption('existing-video');
    await expect(page.locator('#quick-input-type')).toBeDisabled();
    await expect(page.locator('#quick-tag-label')).toContainText('VAST URL');

    await page.locator('#quick-site').selectOption('site-two');
    await expect(page.locator('#quick-placement')).toHaveValue('second-display');
    await expect(page.locator('#quick-input-type')).toBeEnabled();
    await expect(page.locator('#quick-placement option[value="existing-video"]')).toBeDisabled();
    expect(await formData(page)).toMatchObject({ site_id: 'site-two', placement_id: 'second-display', tag_input_type: 'GAM_AD_UNIT_PATH' });
});
