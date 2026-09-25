import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = process.cwd();
const manifest = JSON.parse(await readFile(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2' };

async function open(page, name) {
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/fixture.css') return route.fulfill({ contentType: 'text/css', body: styles.map(file => `@import url("/build/${file}");`).join('\n') });
        if (url.pathname === '/fixture.js') return route.fulfill({ contentType: 'application/javascript', body: `import '/build/${manifest['resources/js/app.js'].file}';` });
        if (url.pathname === '/preview') return route.fulfill({ contentType: 'text/html', body: await readFile(path.join(root, `storage/framework/testing/form-experience/${name}.html`), 'utf8') });
        if (/^\/(assets|build)\//.test(url.pathname) && !url.pathname.includes('..')) {
            try { return await route.fulfill({ contentType: types[path.extname(url.pathname)] || 'application/octet-stream', body: await readFile(path.join(root, 'public', url.pathname)) }); } catch { /* optional branding asset */ }
        }
        return route.fulfill({ status: 204 });
    });
    // Match the fixture application's asset origin; all requests are intercepted.
    await page.goto('http://localhost/preview');
    await expect(page.locator('.ui-page').first()).toBeVisible();
}

for (const name of ['payment-empty', 'payment-verified', 'site-create', 'site-edit', 'support-create', 'account-profile', 'account-branding', 'account-security', 'admin-payment']) {
    test(`${name}: real page fits dark and light desktop/mobile`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        if (name === 'admin-payment') await expect(page.locator('[name="verification_status"]')).toHaveValue('VERIFIED');
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            for (const button of await page.locator('.ui-action-buttons button').all()) {
                const box = await button.boundingBox();
                expect(box.height).toBeGreaterThanOrEqual(40);
                expect(box.height).toBeLessThanOrEqual(70);
            }
            await page.screenshot({ path: info.outputPath(`${name}-${theme}.png`), fullPage: true });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        expect(errors).toEqual([]);
    });
}

test('payment choices keep input and native submit data; saved details stay masked', async ({ page }) => {
    await open(page, 'payment-verified');
    const account = page.locator('[name="account_reference"]');
    await expect(account).toHaveValue('');
    await account.fill('new@example.test');
    await page.getByRole('radio', { name: /PayPal/ }).check();
    await expect(page.getByLabel('PayPal email address', { exact: true })).toHaveValue('new@example.test');
    await expect(page.locator('[data-payment-change-note]')).toBeVisible();
    const data = await page.locator('[data-payment-profile-form]').evaluate(form => Object.fromEntries(new FormData(form)));
    expect(data.payment_method).toBe('PAYPAL');
    expect(data.account_reference).toBe('new@example.test');
    expect(data._method).toBe('PUT');
    expect(data._token).toBeTruthy();
    expect(data.country).toBe('US');
    expect(await page.content()).not.toContain('PRIVATE-ACCOUNT');
});

test('validation identifies the field and viewer has no edit control', async ({ page }) => {
    await open(page, 'payment-error');
    await expect(page.locator('[name="country"]')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('[name="account_reference"]')).toHaveValue('');
    await expect(page.locator('#field-country-error')).toBeVisible();
    await page.unroute('**/*');
    await open(page, 'payment-viewer');
    await expect(page.locator('[data-payment-profile-form]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save payment method' })).toHaveCount(0);
});

test('320px payment footer fits and avoids the floating contact link', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 700 });
    await open(page, 'payment-empty');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const save = page.getByRole('button', { name: 'Save payment method' });
    await save.evaluate(element => element.scrollIntoView({ block: 'end', behavior: 'instant' }));
    const a = await save.boundingBox();
    const b = await page.locator('.hm-whatsapp-contact').boundingBox();
    expect(a.x + a.width <= b.x || a.y + a.height <= b.y || a.y >= b.y + b.height).toBe(true);
});

test('payment form remains usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await open(page, 'payment-empty');
    await page.getByRole('radio', { name: /Wise/ }).check();
    await expect(page.getByRole('radio', { name: /Wise/ })).toBeChecked();
    await expect(page.getByRole('button', { name: 'Save payment method' })).toBeVisible();
    await expect(page.getByLabel('Country or territory')).toBeVisible();
    await context.close();
});
