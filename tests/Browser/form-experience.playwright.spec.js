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
    await expect(page.locator(name.startsWith('reports-') ? '.reports-page' : '.ui-page').first()).toBeVisible();
}

for (const name of ['reports-publisher', 'reports-admin']) {
    test(`${name}: custom performance metrics fit both themes and preserve selected CSV columns`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        const publisher = name === 'reports-publisher';
        const daily = page.getByRole('region', { name: publisher ? 'Daily publisher performance' : 'Daily admin performance', exact: true });
        await expect(daily).toBeVisible();
        if (publisher) {
            await expect(page.locator('.publisher-earnings')).toHaveCount(1);
            await expect(page.locator('.publisher-earnings-breakdown')).not.toHaveAttribute('open', '');
            await expect(page.locator('.report-column-picker')).not.toHaveAttribute('open', '');
            await expect(page.getByText('Gross revenue', { exact: false })).toHaveCount(0);
            await expect(page.getByText('Paid to date', { exact: false })).toHaveCount(0);
            const hero = await page.locator('.publisher-earnings-value').boundingBox();
            expect(hero.y + hero.height).toBeLessThan(page.viewportSize().height);
        }
        if (!publisher || page.viewportSize().width > 600) {
            expect((await daily.locator('tbody th').first().boundingBox()).height).toBeLessThan(55);
        }
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            await page.screenshot({ path: info.outputPath(`${name}-${theme}.png`), fullPage: true });
            const overflow = await page.evaluate(() => ({
                width: document.documentElement.scrollWidth, viewport: innerWidth,
                containers: [...document.querySelectorAll('.reports-page, .reports-page > *, .report-performance-table')]
                    .map(el => ({ tag: el.tagName, class: el.className, right: el.getBoundingClientRect().right }))
                    .filter(el => el.right > innerWidth + 1),
            }));
            expect(overflow.width, JSON.stringify(overflow)).toBeLessThanOrEqual(overflow.viewport + 1);
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        if (publisher) {
            await page.getByText('How this total is calculated', { exact: true }).click();
            await expect(page.locator('.publisher-earnings-breakdown')).toContainText('USD 70.00');
            await expect(page.locator('.publisher-earnings-breakdown')).toContainText('USD 0.00');
            if (page.viewportSize().width <= 600) {
                await daily.locator('summary').first().click();
                await expect(daily.getByText('Active View', { exact: true })).toBeVisible();
                await expect(daily.getByText('60.00%', { exact: true })).toBeVisible();
            }
        }
        await page.getByText('Customize columns', { exact: false }).click();
        await page.getByLabel('Clicks', { exact: true }).uncheck();
        const form = page.getByRole('form', { name: 'Reporting period' });
        const selected = await form.evaluate(el => new FormData(el).getAll('metrics[]'));
        expect(selected).not.toContain('clicks');
        expect(selected).toContain('viewability_bp');
        expect(selected).toContain('unfilled_impressions');
        const [download] = await Promise.all([
            page.waitForRequest(request => new URL(request.url()).searchParams.get('export') === 'csv'),
            page.getByRole('button', { name: 'Download CSV' }).click(),
        ]);
        const query = new URL(download.url()).searchParams;
        expect(query.getAll('metrics[]')).toEqual(selected);
        expect(query.get('from')).toBe('2026-09-01');
        expect(query.get('to')).toBe('2026-09-21');
        expect(errors).toEqual([]);
    });
}

test('publisher report explains mixed and missing data without showing gross revenue', async ({ page }, info) => {
    await open(page, 'reports-publisher-mixed');
    await expect(page.locator('.publisher-earnings-value')).toContainText('105.00');
    await expect(page.locator('.publisher-earnings-topline')).toContainText('Includes estimates');
    await expect(page.locator('.publisher-ad-metric--unavailable')).toHaveCount(2);
    await expect(page.locator('.publisher-ad-metric--unavailable').first()).toContainText('Incomplete source data');
    await expect(page.getByText('Gross revenue', { exact: false })).toHaveCount(0);
    for (const theme of ['dark', 'light']) {
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        if (page.viewportSize().width <= 600) await page.screenshot({ path: info.outputPath(`reports-publisher-mixed-${theme}.png`), fullPage: true });
        if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
    }
    await page.getByText('How this total is calculated', { exact: true }).click();
    await expect(page.locator('.publisher-earnings-breakdown')).toContainText('USD 70.00');
    await expect(page.locator('.publisher-earnings-breakdown')).toContainText('USD 35.00');
});

test('publisher report handles empty and zero data with working dates and columns', async ({ page }) => {
    for (const state of ['empty', 'zero']) {
        await open(page, `reports-publisher-${state}`);
        if (state === 'empty') {
            await expect(page.locator('.publisher-earnings')).toHaveCount(0);
            await expect(page.getByText('No reports for these dates yet')).toBeVisible();
        } else {
            await expect(page.locator('.publisher-earnings-value')).toContainText('0.00');
            await expect(page.locator('.publisher-earnings-topline')).toContainText('Includes estimates');
            await expect(page.locator('.publisher-ad-metric--unavailable')).toHaveCount(3);
            await expect(page.getByText('No measurable impressions', { exact: true })).toBeVisible();
        }
        await page.getByText('Change dates', { exact: true }).click();
        await page.getByLabel('From', { exact: true }).fill('2026-09-15');
        await page.getByText('Customize columns', { exact: false }).click();
        await page.getByLabel('Clicks', { exact: true }).uncheck();
        const data = await page.getByRole('form', { name: 'Reporting period' }).evaluate(form => [...new FormData(form)]);
        expect(data).toContainEqual(['from', '2026-09-15']);
        expect(data).not.toContainEqual(['metrics[]', 'clicks']);
        expect(data).toContainEqual(['metrics[]', 'viewability_bp']);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.unroute('**/*');
    }
});

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
