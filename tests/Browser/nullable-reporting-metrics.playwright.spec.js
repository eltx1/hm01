import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

// These are actual Blade renders from NullableReportingMetricsViewTest, generated
// with HORUS_UI_FIXTURES=1. All data is synthetic and all browser requests stay local.
const root = process.cwd();
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2' };

async function open(page, name) {
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/preview') return route.fulfill({
            contentType: 'text/html',
            body: readFileSync(path.join(root, `storage/framework/testing/nullable-reporting/${name}.html`), 'utf8'),
        });
        if (url.pathname === '/fixture.css') return route.fulfill({ contentType: 'text/css', body: styles.map(file => `@import url("/build/${file}");`).join('\n') });
        if (url.pathname === '/fixture.js') return route.fulfill({ contentType: 'application/javascript', body: `import '/build/${manifest['resources/js/app.js'].file}';` });
        if (/^\/(assets|build)\//.test(url.pathname) && !url.pathname.includes('..')) {
            let body;
            try {
                body = readFileSync(path.join(root, 'public', url.pathname));
            } catch {
                return route.fulfill({ status: 204 });
            }
            return route.fulfill({ contentType: types[path.extname(url.pathname)] || 'application/octet-stream', body });
        }
        return route.fulfill({ status: 204 });
    });
    await page.goto('http://localhost/preview');
    await expect(page.locator('#main-content')).toBeVisible();
}

const cases = [
    ['admin-reports', '.report-metrics', 'Managed impressions', '—', '123.45'],
    ['admin-website', '.report-metrics', 'Impressions', '—', '123.45'],
    ['admin-publisher', '#reporting', 'Impressions', '—', '86.42'],
    ['admin-dashboard', '[aria-label="Platform summary"]', 'Managed impressions', '—', '123.45'],
    ['admin-site', '#today-report', 'Impressions', '—', '123.45'],
    ['publisher-dashboard', '.publisher-key-metrics', 'Impressions this month', 'Unavailable', '86.42'],
    ['publisher-finance', '.publisher-reporting-primary', 'Impressions', 'Unavailable', '86.42'],
];

for (const [name, selector, label, expected, earnings] of cases) {
    test(`${name}: unavailable counters stay legible and money stays visible`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        const region = page.locator(selector);
        const value = region.getByText(label, { exact: true })
            .locator('xpath=following-sibling::*[contains(@class,"metric") or contains(@class,"report-kpi-value")][1]');
        await expect(value).toHaveText(expected);
        await expect(region).toContainText(earnings);
        if (name === 'publisher-dashboard') await expect(region).toContainText('Impressions unavailable');
        if (name === 'publisher-finance') {
            await expect(region.getByText('Clicks', { exact: true }).locator('xpath=following-sibling::strong[1]')).toHaveText('Unavailable');
        }
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            await expect(value).toBeVisible();
            expect(await value.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
            const bounds = await region.boundingBox();
            expect(bounds.x).toBeGreaterThanOrEqual(-1);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
            await region.screenshot({ path: info.outputPath(`${name}-${theme}.png`) });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        expect(errors).toEqual([]);
    });
}
