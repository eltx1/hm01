import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

// Actual authenticated application responses, synthetic data, produced by
// HORUS_UI_FIXTURES=1 php artisan test --filter=VideoReportExperienceTest.
const root = process.cwd();
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.woff2': 'font/woff2' };
async function open(page, name) {
    await page.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/preview') return route.fulfill({ contentType: 'text/html', body: readFileSync(path.join(root, `storage/framework/testing/video-reporting/${name}.html`), 'utf8') });
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
}

for (const name of ['publisher-reports', 'publisher-finance', 'admin-reports', 'admin-website']) {
    test(`${name}: complete Video report is legible, scoped and responsive`, async ({ page }, info) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await open(page, name);
        if (name === 'publisher-finance') await page.locator('.publisher-finance-performance > summary').click();
        const video = page.locator('#video-performance');
        await expect(video).toBeVisible();
        await expect(video.getByRole('heading', { name: 'Video daily breakdown' })).toBeVisible();
        await expect(video.getByRole('heading', { name: 'Video website breakdown' })).toBeVisible();
        await expect(video.locator('.report-metrics > article')).toHaveCount(4);
        await expect(video).toContainText('Selected ad unit · all sites');
        await expect(video).toContainText('Video last updated:');
        if (!name.startsWith('publisher')) await expect(video).toContainText('Missing days remain gaps, not zero.');
        await expect(video).toContainText('18 Sep 2026 – 21 Sep 2026');
        const mobile = page.viewportSize().width <= 600;
        await expect(video.locator(mobile ? '.report-chart-mobile' : '.report-chart-desktop')).toBeVisible();
        await expect(video.locator('.video-mobile-rows').first()).toBeVisible({ visible: mobile });
        await expect(video.locator('.video-performance-table').first()).toBeVisible({ visible: !mobile });
        const csv = new URL(await video.getByRole('link', { name: 'Export Video CSV' }).getAttribute('href'));
        expect(csv.searchParams.get('export')).toBe('video_csv');
        expect(csv.searchParams.get('from')).toBe('2026-09-18');
        expect(csv.searchParams.get('to')).toBe('2026-09-21');
        if (name.startsWith('publisher')) {
            await expect(video).not.toContainText('gross');
            await expect(video).not.toContainText('Horus margin');
            await expect(video.locator('.report-kpi-primary')).toContainText('252.00');
            await expect(page.locator('.report-reconciliation')).toContainText('322.00');
        } else {
            await expect(video).toContainText('Video Horus margin');
            await expect(video.locator('.report-kpi-primary')).toContainText(name === 'admin-website' ? '250.00' : '350.00');
        }
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            const dimensions = await video.evaluate(element => ({ x: element.getBoundingClientRect().x, right: element.getBoundingClientRect().right, width: document.documentElement.clientWidth }));
            expect(dimensions.x).toBeGreaterThanOrEqual(-1);
            expect(dimensions.right).toBeLessThanOrEqual(dimensions.width + 1);
            for (const card of await video.locator('.report-kpi').all()) expect(await card.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
            await video.screenshot({ path: info.outputPath(`video-report-${name}-${theme}.png`), scale: 'css' });
            if (theme === 'dark' && info.project.name.startsWith('chromium') && ['publisher-reports', 'admin-website'].includes(name)) {
                await page.evaluate(() => window.scrollTo(0, 0));
                await page.screenshot({ path: info.outputPath(`video-report-${name}-full-page.png`), fullPage: true, scale: 'css' });
            }
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
        await video.locator('summary').click();
        await expect(video).toContainText('Unfilled is not a website fill rate');
        await expect(video.locator('.video-report-basis')).toHaveAttribute('open', '');
        await video.locator('summary').click();
        await expect(video.locator('.video-report-basis')).not.toHaveAttribute('open', '');
        expect(errors).toEqual([]);
    });
}

for (const name of ['publisher-zero', 'publisher-pending', 'publisher-failed', 'publisher-disabled']) {
    test(`${name}: honest availability without layout overflow`, async ({ page }, info) => {
        await open(page, name);
        const video = page.locator('#video-performance');
        await expect(video).toBeVisible();
        if (name === 'publisher-zero') {
            await expect(video.locator('.report-kpi-primary')).toContainText('0.00');
            await expect(video).toContainText('Unavailable');
        } else if (name === 'publisher-pending') {
            await expect(video).toContainText('Awaiting Video data');
            await expect(video.getByRole('link', { name: 'Export Video CSV' })).toHaveCount(0);
        } else if (name === 'publisher-failed') await expect(video).toContainText('temporarily delayed');
        else await expect(video).toContainText('disabled');
        await video.screenshot({ path: info.outputPath(`video-report-${name}.png`), scale: 'css' });
        expect(await video.evaluate(el => el.getBoundingClientRect().right <= document.documentElement.clientWidth + 1)).toBe(true);
    });
}
for (const name of ['publisher-before', 'admin-before']) {
    test(`${name}: preserve original deployed presentation for visual comparison`, async ({ page }, info) => {
        await open(page, name);
        await page.locator('.publisher-report-details').screenshot({ path: info.outputPath(`video-report-${name}.png`), scale: 'css' });
    });
}


test('Video Unfilled distinguishes genuine zero, positive totals and absent denominator', async ({ page }, info) => {
    await open(page, 'publisher-counts');
    const video = page.locator('#video-performance');
    await expect(video.locator('.report-kpi').filter({ hasText: 'Video Unfilled' }).locator('.report-kpi-value')).toHaveText('57');
    const mobile = page.viewportSize().width <= 600;
    const daily = mobile ? video.locator('.video-mobile-rows').first() : video.locator('.video-performance-table').first();
    await expect(daily).toContainText('57');
    await expect(daily).toContainText('0.00');
    await expect(daily).toContainText('Unavailable');
    const zeroRow = mobile ? daily.locator('.publisher-report-mobile-row').filter({ hasText: '19 Sep 2026' }) : daily.locator('tbody tr').filter({ hasText: '2026-09-19' });
    const zeroUnfilled = mobile ? zeroRow.locator('dl > div').filter({ hasText: 'Unfilled' }).locator('dd') : zeroRow.locator('td').nth(1);
    await expect(zeroUnfilled).toHaveText('0');
    await video.screenshot({ path: info.outputPath('video-report-publisher-counts.png'), scale: 'css' });
});

test('Skip link stays hidden during report reading and remains keyboard accessible', async ({ page }, info) => {
    await open(page, 'publisher-reports');
    const skip = page.locator('.skip-link');
    expect(await skip.evaluate(el => getComputedStyle(el).clipPath)).toBe('inset(50%)');
    await skip.focus();
    await expect(skip).toBeFocused();
    expect(await skip.evaluate(el => getComputedStyle(el).clipPath)).toBe('none');
    expect((await skip.boundingBox()).y).toBeGreaterThanOrEqual(0);
    await page.screenshot({ path: info.outputPath('video-report-skip-link-focused.png'), scale: 'css' });
    await skip.press('Enter');
    await expect(page).toHaveURL(/#main-content$/);
    await page.getByRole('heading', { name: 'Video performance', exact: true }).click();
    expect(await skip.evaluate(el => getComputedStyle(el).clipPath)).toBe('inset(50%)');
});

for (const name of ['publisher-reports', 'publisher-finance']) {
    test(`${name}: Video money and eCPM remain visible in every publisher surface`, async ({ page }) => {
        await open(page, name);
        if (name === 'publisher-finance') await page.locator('.publisher-finance-performance > summary').click();
        const video = page.locator('#video-performance');
        const mobile = page.viewportSize().width <= 600;
        const daily = video.locator(mobile ? '.video-mobile-rows' : '.video-performance-table').first();
        const websites = video.locator(mobile ? '.video-mobile-rows' : '.video-performance-table').last();
        const verifyRow = async (region, label, impressions, earnings, ecpm, estimated = false) => {
            const row = region.locator(mobile ? '.publisher-report-mobile-row' : 'tbody tr').filter({ hasText: label });
            await expect(row).toBeVisible();
            const money = mobile ? row.locator('.publisher-row-earnings > strong') : row.locator('td').nth(2);
            const rate = mobile ? row.locator('dl > div').filter({ has: page.locator('dt', { hasText: /^eCPM$/ }) }).locator('dd') : row.locator('td').nth(3);
            const count = mobile ? row.locator('dl > div').filter({ has: page.locator('dt', { hasText: /^Impressions$/ }) }).locator('dd') : row.locator('td').nth(0);
            await expect(money).toBeVisible();
            await expect(rate).toBeVisible();
            await expect(count).toBeVisible();
            await expect(money).toHaveText(earnings + (mobile ? ' USD' : ''));
            await expect(rate).toHaveText(ecpm + (mobile ? ' USD' : ''));
            await expect(count).toHaveText(impressions);
            if (estimated) await expect(row).toContainText('Estimated');
            else await expect(row).not.toContainText('Estimated');
        };
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            for (const [label, value] of [
                ['Video earnings', '252.00 USD'], ['Video impressions', '3,600'],
                ['Video eCPM', '70.00 USD'], ['Video Unfilled', 'Unavailable'],
            ]) {
                const card = video.locator('.report-kpi').filter({ has: page.getByRole('heading', { name: label, exact: true }) });
                await expect(card).toBeVisible();
                await expect(card.locator('.report-kpi-value')).toBeVisible();
                await expect(card.locator('.report-kpi-value')).toHaveText(value);
            }
            await expect(daily).toBeVisible();
            await expect(websites).toBeVisible();
            await verifyRow(daily, mobile ? '21 Sep 2026' : '2026-09-21', '100', '7.00', '70.00', true);
            await verifyRow(daily, mobile ? '20 Sep 2026' : '2026-09-20', '3,000', '105.00', '35.00');
            await verifyRow(daily, mobile ? '18 Sep 2026' : '2026-09-18', '500', '140.00', '280.00');
            await verifyRow(websites, 'natega.example.test', '1,600', '182.00', '113.75', true);
            await verifyRow(websites, 'second.example.test', '2,000', '70.00', '35.00');
            await expect(video).not.toContainText('gross');
            await expect(video).not.toContainText('Horus margin');
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
    });
}
