import { test, expect } from '@playwright/test';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';

// Actual authenticated Blade responses from SiteVideoInventoryTest. PHP tests
// verify authorization, validation, persistence and static publication; this
// browser harness verifies the real controls, submissions and responsive states.
const root = process.cwd();
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
const fixture = name => readFileSync(path.join(root, `storage/framework/testing/dashboard-layout/${name}.html`), 'utf8');

async function open(page, initial = 'admin-1') {
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

for (const role of ['admin', 'publisher']) {
    test(`${role} pagination stays compact and usable in mobile and desktop themes`, async ({ page }, info) => {
        for (const pageNumber of [1, 2]) {
            if (pageNumber > 1) await page.unrouteAll({ behavior: 'wait' });
            await open(page, `${role}-${pageNumber}`);
            const pagination = page.getByRole('navigation', { name: 'Pagination Navigation' });
            await pagination.scrollIntoViewIfNeeded();
            for (const theme of ['dark', 'light']) {
                if (await page.locator('html').getAttribute('data-hm-theme') !== theme) {
                    await page.getByRole('button', { name: theme === 'light' ? 'Switch to White Mode' : 'Switch to Dark Mode' }).click();
                }
                const box = await pagination.boundingBox();
                expect(box.height).toBeLessThan(160);
                expect(box.x).toBeGreaterThanOrEqual(0);
                expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
                for (const link of await pagination.locator('a').all()) {
                    if (!(await link.isVisible())) continue;
                    const rect = await link.boundingBox();
                    expect(rect.height).toBeGreaterThanOrEqual(44);
                    expect(rect.width).toBeGreaterThanOrEqual(44);
                }
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                await expect(pagination.locator('svg')).toHaveCount(0);
                if (pageNumber === 1) await expect(pagination.locator('[rel="next"]')).toHaveAttribute('href', /page=2/);
                else await expect(pagination.locator('[rel="prev"]')).toHaveAttribute('href', /page=1/);
                await pagination.screenshot({ path: info.outputPath(`pagination-${role}-${pageNumber}-${theme}.png`) });
            }
        }
    });
}

for (const name of readdirSync(path.join(root, 'storage/framework/testing/dashboard-layout')).filter(name => name.startsWith('nav-') && name.endsWith('.html'))) {
    test(`workspace geometry: ${name}`, async ({ page }, info) => {
        await open(page, name.slice(0, -5));
        await expect(page.locator('#main-content')).toBeVisible();
        for (const theme of ['dark', 'light']) {
            if (await page.locator('html').getAttribute('data-hm-theme') !== theme) await page.getByRole('button', { name: theme === 'light' ? 'Switch to White Mode' : 'Switch to Dark Mode' }).click();
            const overflowing = await page.locator('#main-content > *').evaluateAll(elements => elements
                .filter(el => el.getBoundingClientRect().width && el.getBoundingClientRect().right > innerWidth + 2)
                .map(el => ({ tag: el.tagName, class: el.className, width: el.getBoundingClientRect().width })));
            expect(overflowing).toEqual([]);
            const giantIcons = await page.locator('#main-content svg').evaluateAll(elements => elements
                .filter(el => !el.closest('[data-chart], .chart, .report-chart') && el.getBoundingClientRect().height > 120)
                .map(el => ({ class: el.getAttribute('class'), height: el.getBoundingClientRect().height })));
            expect(giantIcons).toEqual([]);
            await page.screenshot({ path: info.outputPath(`workspace-${name}-${theme}.png`) });
        }
    });
}
