import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

// Real Blade responses from ImpersonationAuditTest with synthetic identities.
// Backend identity, CSRF, permission, MFA and revocation checks run in PHP;
// these previews exercise the actual responsive controls and repeated clicks.
const root = process.cwd();
const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const styles = [...new Set([manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])])];
const types = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
async function setup(page, initial) {
    const requests = [];
    await page.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (request.method() === 'POST' && url.pathname.startsWith('/admin/impersonate')) {
            requests.push({ path: url.pathname, body: request.postData() });
            await new Promise(resolve => setTimeout(resolve, 150));
            return route.fulfill({ status: 303, headers: { location: url.pathname === '/admin/impersonate' ? '/admin-return' : '/publisher-preview' } });
        }
        const name = url.pathname === '/publisher-preview' ? 'publisher' : url.pathname === '/admin-return' ? 'admin-single' : initial;
        if (['/preview', '/publisher-preview', '/admin-return'].includes(url.pathname)) {
            return route.fulfill({ contentType: 'text/html', headers: { 'cache-control': 'no-store' }, body: readFileSync(path.join(root, `storage/framework/testing/impersonation/${name}.html`), 'utf8') });
        }
        if (url.pathname === '/fixture.css') return route.fulfill({ contentType: 'text/css', body: styles.map(file => `@import url("/build/${file}");`).join('\n') });
        if (url.pathname === '/fixture.js') return route.fulfill({ contentType: 'application/javascript', body: `import '/build/${manifest['resources/js/app.js'].file}';` });
        if (/^\/(assets|build)\//.test(url.pathname) && !url.pathname.includes('..')) {
            try { return route.fulfill({ contentType: types[path.extname(url.pathname)] || 'application/octet-stream', body: readFileSync(path.join(root, 'public', url.pathname)) }); }
            catch { return route.fulfill({ status: 204 }); }
        }
        return route.fulfill({ status: 204 });
    });
    await page.goto('http://localhost/preview');
    return requests;
}

async function withinViewport(locator, page) {
    const rect = await locator.boundingBox();
    expect(rect).not.toBeNull();
    expect(rect.x).toBeGreaterThanOrEqual(-1);
    expect(rect.x + rect.width).toBeLessThanOrEqual(page.viewportSize().width + 1);
}

test('single user: named login and return controls submit once and remain legible', async ({ page }, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const requests = await setup(page, 'admin-single');
    const hero = page.locator('#overview');
    await expect(hero).toContainText('alex@example.test');
    await expect(hero).toContainText('all tabs');
    const start = hero.getByRole('button', { name: 'Log in as publisher', exact: true });
    await withinViewport(start, page);
    await hero.screenshot({ path: info.outputPath('impersonation-start.png') });
    // Two synchronous clicks while the same real app form is submitting.
    await start.evaluate(button => { button.click(); button.click(); });
    const banner = page.getByRole('region', { name: 'Temporary publisher login' });
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Example Publishing');
    await expect(banner).toContainText('alex@example.test');
    await expect(banner).toContainText('all tabs');
    expect(requests).toHaveLength(1);
    expect(new URLSearchParams(requests[0].body).has('_token')).toBe(true);
    for (const theme of ['dark', 'light']) {
        await withinViewport(banner, page);
        await withinViewport(banner.getByRole('button', { name: 'Return to admin' }), page);
        await banner.screenshot({ path: info.outputPath(`impersonation-banner-${theme}.png`) });
        if (theme === 'dark') await page.getByRole('button', { name: 'Switch to White Mode' }).click();
    }
    await banner.getByRole('button', { name: 'Return to admin' }).evaluate(button => { button.click(); button.click(); });
    await expect(page.locator('#overview')).toBeVisible();
    await expect(page.locator('.impersonation-banner')).toHaveCount(0);
    expect(requests).toHaveLength(2);
    expect(new URLSearchParams(requests[1].body).get('_method')).toBe('DELETE');
    expect(errors).toEqual([]);
});

test('multiple users: choose exact identity, leave and return without a switch', async ({ page }, info) => {
    const requests = await setup(page, 'admin-multiple');
    await page.getByRole('link', { name: 'Choose publisher user' }).click();
    await expect(page.locator('#users')).toBeInViewport();
    await expect(page.getByRole('button', { name: 'Log in as Alex Publisher', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in as Sam Publisher', exact: true })).toBeVisible();
    await page.locator('#users').screenshot({ path: info.outputPath('impersonation-choose-user.png') });
    await page.goBack();
    await page.goForward();
    await expect(page.getByRole('button', { name: 'Log in as Sam Publisher', exact: true })).toBeEnabled();
    expect(requests).toHaveLength(0);
});

test('interrupted publisher: error page keeps a usable return action', async ({ page }, info) => {
    const requests = await setup(page, 'interrupted');
    await expect(page.getByRole('heading', { name: 'Access not available' })).toBeVisible();
    const banner = page.getByRole('region', { name: 'Temporary publisher login' });
    await expect(banner).toBeVisible();
    await withinViewport(banner, page);
    await withinViewport(banner.getByRole('button', { name: 'Return to admin' }), page);
    await page.screenshot({ path: info.outputPath('impersonation-interrupted.png'), fullPage: true });
    await banner.getByRole('button', { name: 'Return to admin' }).click();
    await expect(page.locator('#overview')).toBeVisible();
    expect(requests).toHaveLength(1);
});
