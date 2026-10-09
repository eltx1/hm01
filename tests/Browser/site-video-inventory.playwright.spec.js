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

for (const fixtureName of ['admin-default', 'admin-overview', 'publisher']) {
    test(`fixed inventory removes the selector on ${fixtureName} in both themes`, async ({ page }, info) => {
        await open(page, fixtureName);
        await expect(page.locator('[name="video_inventory_type"]')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Save video ad type', exact: false })).toHaveCount(0);
        for (const theme of ['dark', 'light']) {
            await expect(page.locator('html')).toHaveAttribute('data-hm-theme', theme);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            await page.screenshot({ path: info.outputPath(`site-video-inventory-${fixtureName}-${theme}.png`) });
            if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
        }
    });
}
