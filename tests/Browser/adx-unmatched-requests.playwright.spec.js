import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

// These are actual Blade renders from AdExchangeUnmatchedRequestsTest, generated
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
            body: readFileSync(path.join(root, `storage/framework/testing/adx-unmatched/${name}.html`), 'utf8'),
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

const metric = 'Ad Exchange unmatched requests';
for (const name of ['publisher', 'publisher-legacy', 'admin', 'website']) {
    test(`${name}: source-aware unmatched requests are clear in both themes`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        const publisher = name.startsWith('publisher');
        const cards = page.locator(publisher ? '.publisher-ad-metrics' : '.report-quality-metrics');
        const card = cards.locator('article').filter({ has: page.getByText(metric, { exact: true }) });
        await expect(card).toHaveCount(1);
        await expect(card.locator('strong')).toHaveText(name === 'publisher-legacy' ? 'Unavailable' : '20');
        await expect(cards.getByText('Unfilled impressions', { exact: true })).toHaveCount(0);
        await expect(page.locator('.report-unmatched-help')).toContainText('not empty ad slots');
        await expect(page.locator('.report-unmatched-help')).toContainText('Unfilled impressions are a different source metric');
        if (publisher) {
            const details = page.locator('[aria-label="Daily publisher performance"]');
            const visible = page.viewportSize().width <= 600 ? details.locator('.publisher-report-mobile-row') : details.locator('tbody tr');
            await expect(visible.first()).toContainText(name === 'publisher-legacy' ? 'Unavailable' : '0');
            await expect(visible.last()).toContainText('20');
        }
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            await expect(card).toBeVisible();
            const overflow = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth }));
            expect(overflow.width).toBeLessThanOrEqual(overflow.viewport + 1);
            const bounds = await card.boundingBox();
            expect(bounds.x).toBeGreaterThanOrEqual(-1);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
            await page.screenshot({ path: info.outputPath(`reports-adx-${name}-${theme}.png`), fullPage: true });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        await page.getByText('Customize columns', { exact: false }).click();
        await expect(page.getByLabel(metric, { exact: true })).toBeChecked();
        await expect(page.getByLabel('Unfilled impressions', { exact: true })).not.toBeChecked();
        const [download] = await Promise.all([
            page.waitForRequest(request => new URL(request.url()).searchParams.get('export') === 'csv'),
            page.getByRole('button', { name: 'Download CSV' }).click(),
        ]);
        const query = new URL(download.url()).searchParams;
        expect(query.getAll('metrics[]')).toContain('ad_exchange_unmatched_requests');
        expect(query.getAll('metrics[]')).not.toContain('unfilled_impressions');
        expect(errors).toEqual([]);
    });
}
