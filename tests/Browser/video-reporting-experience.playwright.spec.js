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
        await expect(video).toContainText('Missing days remain gaps, not zero.');
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
            await video.screenshot({ path: info.outputPath(`video-report-${name}-${theme}.png`) });
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
            await expect(video).toContainText('Awaiting imported statistics');
            await expect(video.getByRole('link', { name: 'Export Video CSV' })).toHaveCount(0);
        } else if (name === 'publisher-failed') await expect(video).toContainText('refresh');
        else await expect(video).toContainText('disabled');
        await video.screenshot({ path: info.outputPath(`video-report-${name}.png`) });
        expect(await video.evaluate(el => el.getBoundingClientRect().right <= document.documentElement.clientWidth + 1)).toBe(true);
    });
}
for (const name of ['publisher-before', 'admin-before']) {
    test(`${name}: preserve original deployed presentation for visual comparison`, async ({ page }, info) => {
        await open(page, name);
        await page.locator('.publisher-report-details').screenshot({ path: info.outputPath(`video-report-${name}.png`) });
    });
}
