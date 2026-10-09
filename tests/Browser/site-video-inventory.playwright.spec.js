import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

// Actual authenticated Blade responses from SiteVideoInventoryTest. PHP tests
// verify authorization, validation, persistence and static publication; this
// browser harness verifies the real controls, submissions and responsive states.
const root = process.cwd();
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
const fixture = name => readFileSync(path.join(root, `storage/framework/testing/site-video-inventory/${name}.html`), 'utf8');

async function open(page, initial = 'admin-default') {
    const requests = [];
    let current = initial;
    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() === 'POST' && url.pathname.endsWith('/configuration/video-inventory')) {
            requests.push(new URLSearchParams(request.postData()));
            await new Promise(resolve => setTimeout(resolve, 150));
            current = 'admin-saved';
            return route.fulfill({ contentType: 'text/html', body: fixture(current) });
        }
        if (url.pathname === '/preview' || url.pathname.endsWith('/configuration/video-inventory')) {
            return route.fulfill({ contentType: 'text/html', headers: { 'cache-control': 'no-store' }, body: fixture(current) });
        }
        if (url.pathname === '/other-site') return route.fulfill({ contentType: 'text/html', body: fixture('other-site') });
        if (url.pathname === '/fixture.css') return route.fulfill({ contentType: 'text/css', body: styles.map(file => `@import url("/build/${file}");`).join('\n') });
        if (url.pathname === '/fixture.js') return route.fulfill({ contentType: 'application/javascript', body: `import '/build/${manifest['resources/js/app.js'].file}';` });
        if (/^\/(assets|build)\//.test(url.pathname) && !url.pathname.includes('..')) {
            try { return route.fulfill({ contentType: types[path.extname(url.pathname)] || 'application/octet-stream', body: readFileSync(path.join(root, 'public', url.pathname)) }); }
            catch { return route.fulfill({ status: 204 }); }
        }
        return route.fulfill({ status: 204 });
    });
    await page.goto('http://localhost/preview');
    await expect(page.locator('#main-content')).toBeVisible();
    return requests;
}

test('site selector has two named choices and fits desktop and mobile themes', async ({ page }, info) => {
    await open(page);
    const card = page.locator('#video-inventory');
    const select = card.getByLabel('Inventory classification', { exact: false });
    await expect(select).toHaveValue('accompanying');
    await expect(select.locator('option')).toHaveText(['Accompanying content (2)', 'Instream (1)']);
    await select.selectOption('instream');
    await expect(select).toHaveValue('instream');
    await select.focus();
    await page.keyboard.press('Tab');
    await expect(card.getByRole('button', { name: 'Save video ad type', exact: false })).toBeFocused();
    for (const theme of ['dark', 'light']) {
        await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
        const box = await card.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(-1);
        expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
        expect(await card.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        expect((await select.boundingBox()).height).toBeGreaterThanOrEqual(44);
        await card.screenshot({ path: info.outputPath(`site-video-inventory-${theme}.png`), scale: 'css' });
        if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
    }
});

test('repeated save submits once, shows real feedback, and leaves the other site at its default', async ({ page }) => {
    const requests = await open(page);
    const card = page.locator('#video-inventory');
    const select = card.getByLabel('Inventory classification', { exact: false });
    await select.selectOption('instream');
    await card.getByRole('button', { name: 'Save video ad type', exact: false }).evaluate(button => { button.click(); button.click(); });
    await expect(page.getByRole('status')).toContainText('Video ad type saved for this website.');
    await expect(select).toHaveValue('instream');
    expect(requests).toHaveLength(1);
    expect(requests[0].get('_method')).toBe('PUT');
    expect(requests[0].has('_token')).toBe(true);
    expect(requests[0].get('video_inventory_type')).toBe('instream');
    // The fixture transport returns the final Blade response directly. Open the
    // canonical GET before testing history, mirroring the real POST/redirect/GET.
    await page.goto('http://localhost/preview');
    await expect(select).toHaveValue('instream');
    await page.goto('http://localhost/other-site');
    await expect(select).toHaveValue('accompanying');
    await page.goBack();
    await expect(select).toHaveValue('instream');
    await expect(card.getByRole('button', { name: 'Save video ad type', exact: false })).toBeEnabled();
    expect(requests).toHaveLength(1);
});

test('validation error is announced and publisher page never exposes the selector', async ({ page }) => {
    await open(page, 'admin-error');
    const card = page.locator('#video-inventory');
    await expect(card.getByRole('alert')).toBeVisible();
    await expect(card.getByLabel('Inventory classification', { exact: false })).toHaveAttribute('aria-invalid', 'true');
    await expect(card.getByRole('button', { name: 'Save video ad type', exact: false })).toBeEnabled();
    await page.unrouteAll({ behavior: 'wait' });
    await open(page, 'publisher');
    await expect(page.locator('#video-inventory')).toHaveCount(0);
    await expect(page.locator('[name="video_inventory_type"]')).toHaveCount(0);
});
