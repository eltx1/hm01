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

const unfilled = 'Unfilled impressions';
const unmatched = 'Ad Exchange unmatched requests';
const defaultLabels = ['Impressions', 'Clicks', 'CTR', 'CPM (eCPM)', 'Active View', unmatched, unfilled];
for (const name of ['publisher', 'publisher-legacy', 'admin', 'website']) {
    test(`${name}: true unfilled impressions remain visible with truthful missing values in both themes`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        const publisher = name.startsWith('publisher');
        const cards = page.locator(publisher ? '.publisher-ad-metrics' : '.report-quality-metrics');
        const card = cards.locator('article').filter({ has: page.getByText(unfilled, { exact: true }) });
        await expect(card).toHaveCount(1);
        await expect(card.locator('strong')).toHaveText(publisher ? 'Unavailable' : '—');
        await expect(cards.getByText(unmatched, { exact: true })).toBeVisible();
        const unmatchedCard = cards.locator('article').filter({ has: page.getByText(unmatched, { exact: true }) });
        await expect(unmatchedCard.locator('strong')).toHaveText(name === 'publisher-legacy' ? 'Unavailable' : '20');
        await expect(page.locator('.report-unmatched-help')).toContainText('Unfilled impressions are a source-reported metric');
        await expect(page.locator('.report-unmatched-help')).toContainText('Missing values remain unavailable, never estimated from requests');
        const caption = publisher ? 'Daily publisher performance' : name === 'website' ? 'Website daily performance' : 'Daily admin performance';
        const details = page.locator(`[aria-label="${caption}"]`);
        if (name !== 'admin' && page.viewportSize().width <= 600) {
            for (const row of await details.locator('.publisher-report-mobile-row').all()) {
                await expect(row.locator('dt')).toHaveText(defaultLabels);
                await expect(row.locator('dd').last()).toHaveText('Unavailable');
            }
        } else {
            await expect(details.getByRole('columnheader', { name: unfilled, exact: true })).toBeVisible();
            await expect(details.getByRole('columnheader', { name: unmatched, exact: true })).toBeVisible();
            for (const row of await details.locator('tbody tr').all()) {
                await expect(row.locator('td').nth(6)).toHaveText(name === 'admin' ? '—' : 'Unavailable');
            }
        }
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            await expect(card).toBeVisible();
            const overflow = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth }));
            expect(overflow.width).toBeLessThanOrEqual(overflow.viewport + 1);
            const bounds = await card.boundingBox();
            expect(bounds.x).toBeGreaterThanOrEqual(-1);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
            await page.screenshot({ path: info.outputPath(`reports-unfilled-${name}-${theme}.png`), fullPage: true });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        await page.getByText('Customize columns', { exact: false }).click();
        await expect(page.getByLabel(unmatched, { exact: true })).toBeChecked();
        await expect(page.getByLabel(unfilled, { exact: true })).toBeChecked();
        const [download] = await Promise.all([
            page.waitForRequest(request => new URL(request.url()).searchParams.get('export') === 'csv'),
            page.getByRole('button', { name: 'Download CSV' }).click(),
        ]);
        const query = new URL(download.url()).searchParams;
        expect(query.getAll('metrics[]')).toContain('ad_exchange_unmatched_requests');
        expect(query.getAll('metrics[]')).toContain('unfilled_impressions');
        expect(errors).toEqual([]);
    });
}

for (const name of ['publisher', 'admin', 'website']) {
    test(`${name}: explicit detail selection preserves both distinct default KPIs`, async ({ page }) => {
        await open(page, `${name}-unmatched`);
        const publisher = name === 'publisher';
        const cards = page.locator(publisher ? '.publisher-ad-metrics' : '.report-quality-metrics');
        await expect(cards.getByText(unfilled, { exact: true })).toBeVisible();
        await expect(cards.getByText(unmatched, { exact: true })).toBeVisible();
        const caption = publisher ? 'Daily publisher performance' : name === 'website' ? 'Website daily performance' : 'Daily admin performance';
        const details = page.locator(`[aria-label="${caption}"]`);
        if (name !== 'admin' && page.viewportSize().width <= 600) {
            await expect(details.locator('.publisher-report-mobile-row dt')).toHaveText([unmatched, unmatched]);
            await expect(details.locator('.publisher-report-mobile-row dd')).toHaveText(['0', '20']);
        } else {
            await expect(details.getByRole('columnheader', { name: unmatched, exact: true })).toBeVisible();
            await expect(details.getByRole('columnheader', { name: unfilled, exact: true })).toHaveCount(0);
            const values = await details.locator('tbody tr td:first-of-type').allTextContents();
            expect(values.sort()).toEqual(['0', '20']);
        }
        await page.getByText('Customize columns', { exact: false }).click();
        await expect(page.getByLabel(unmatched, { exact: true })).toBeChecked();
        await expect(page.getByLabel(unfilled, { exact: true })).not.toBeChecked();
    });
}

for (const name of ['directory', 'directory-mixed', 'directory-mixed-selected']) {
    test(`${name}: website directory preserves unfilled and all distinct source values`, async ({ page }, info) => {
        await open(page, name);
        const cards = page.locator('.admin-website-card');
        const adx = cards.filter({ has: page.getByRole('heading', { name: 'Synthetic request website', exact: true }) });
        const unfilledValue = adx.locator('dl > div').filter({ has: page.getByText(unfilled, { exact: true }) }).locator('dd');
        await expect(unfilledValue).toHaveText('Unavailable');
        const selected = name.endsWith('-selected');
        await expect(adx.locator('dt')).toHaveText(selected ? [unmatched, unfilled] : defaultLabels);
        if (selected) {
            await expect(adx.locator('dd')).toHaveText(['20', 'Unavailable']);
        }
        if (name.includes('mixed')) {
            const other = cards.filter({ has: page.getByRole('heading', { name: 'Synthetic other-source website', exact: true }) });
            await expect(other.locator('dl > div').filter({ has: page.getByText(unfilled, { exact: true }) }).locator('dd')).toHaveText('9');
            if (selected) await expect(other.locator('dd')).toHaveText(['Unavailable', '9']);
        }
        for (const theme of ['dark', 'light']) {
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            await page.screenshot({ path: info.outputPath(`reports-unfilled-${name}-${theme}.png`), fullPage: true });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
    });
}
