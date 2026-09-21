import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const controller = await readFile(new URL('../../resources/js/site-gam-reporting.js', import.meta.url), 'utf8');
async function open(page) {
    await page.route('https://admin.example.test/site', route => route.fulfill({ contentType: 'text/html', body: `<!doctype html>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <form data-gam-report-binding data-units-url="/units">
            <label>Account<select name="gam_connection_id"><option value="first">First account</option><option value="second">Second account</option></select></label>
            <label>Ad unit<input name="ad_unit" list="units" required></label>
            <datalist id="units"></datalist><small data-unit-feedback aria-live="polite"></small>
            <button>Connect reports</button>
        </form>` }));
    await page.goto('https://admin.example.test/site');
    await page.addScriptTag({ content: controller });
}

test('search keeps the selected account and submits the unit ID in one form', async ({ page }) => {
    const queries = [];
    await page.route('https://admin.example.test/units?**', route => {
        queries.push(new URL(route.request().url()).searchParams);
        return route.fulfill({ json: { units: [{ id: '12345', name: '<script>bad()</script> Publisher', code: 'publisher_unit' }] } });
    });
    await open(page);
    await page.getByLabel('Ad unit').fill('Publisher');
    await expect(page.locator('datalist option')).toHaveCount(1);
    await expect(page.locator('datalist option')).toHaveAttribute('value', '12345');
    await expect(page.locator('datalist option')).toHaveAttribute('label', '<script>bad()</script> Publisher · publisher_unit');
    expect(await page.locator('datalist script').count()).toBe(0);
    await page.getByLabel('Ad unit').fill('12345');
    const data = await page.locator('form').evaluate(form => Object.fromEntries(new FormData(form)));
    expect(data).toEqual({ gam_connection_id: 'first', ad_unit: '12345' });
    await expect(page.getByRole('button', { name: 'Connect reports' })).toBeEnabled();
    expect(queries.every(query => query.get('gam_connection_id') === 'first')).toBe(true);
    await page.getByLabel('Account').selectOption('second');
    await expect(page.getByLabel('Ad unit')).toHaveValue('');
    await expect.poll(() => queries.at(-1)?.get('gam_connection_id')).toBe('second');
});

test('search failures preserve manual name or ID entry and never block saving', async ({ page }) => {
    await page.route('https://admin.example.test/units?**', route => route.fulfill({ status: 503, json: { message: 'Unavailable' } }));
    await open(page);
    await page.getByLabel('Ad unit').fill('publisher_exact_name');
    await expect(page.locator('[data-unit-feedback]')).toContainText('You can still enter an exact name, code or ID');
    await expect(page.getByLabel('Ad unit')).toHaveValue('publisher_exact_name');
    expect(await page.locator('form').evaluate(form => form.checkValidity())).toBe(true);
    await expect(page.getByRole('button', { name: 'Connect reports' })).toBeEnabled();
});

// Exercise the real handoff template under the application's production CSP.
// Provider requests are intercepted: no real Google account or secrets are used.
const handoffTemplate = await readFile(new URL('../../resources/views/admin/gam/reporting-google-redirect.blade.php', import.meta.url), 'utf8');
const securityConfig = await readFile(new URL('../../config/security.php', import.meta.url), 'utf8');
const policy = securityConfig.match(/'content_security_policy' => env\('SECURITY_CSP', "([^"]+)"\)/)[1];
const googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=test.apps.googleusercontent.com&state=fixture-state&code_challenge=fixture-challenge';
const escapeHtml = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;');
const handoffHtml = handoffTemplate
    .replaceAll('{{ $authorizationUrl }}', escapeHtml(googleUrl))
    .replaceAll('{{ $returnUrl }}', 'https://admin.example.test/site#reporting')
    .replace(/<x-brand\.favicons\s*\/>/, '')
    .replace(/@vite\([^\n]+\)/, '');

async function openGoogleConnect(page, { legacy = false, manual = false } = {}) {
    const posts = [];
    const providerRequests = [];
    await page.route('https://admin.example.test/site', route => route.fulfill({
        headers: { 'content-type': 'text/html', 'content-security-policy': policy },
        body: '<form method="POST" action="/connect"><input name="_token" value="local-csrf" type="hidden"><label>Ad unit<input name="ad_unit"></label><button>Connect with Google</button></form>',
    }));
    await page.route('https://admin.example.test/connect', route => {
        posts.push({ method: route.request().method(), data: new URLSearchParams(route.request().postData()) });
        return route.fulfill(legacy ? { status: 302, headers: { location: googleUrl } } : {
            status: 200,
            headers: { 'content-type': 'text/html', 'content-security-policy': policy, 'referrer-policy': 'no-referrer', 'cache-control': 'private, no-store' },
            body: manual ? handoffHtml.replace(/<meta http-equiv="refresh"[^>]*>/, '') : handoffHtml,
        });
    });
    await page.route('https://accounts.google.com/**', route => {
        providerRequests.push({ method: route.request().method(), body: route.request().postData(), referer: route.request().headers().referer });
        return route.fulfill({ contentType: 'text/html', body: '<h1>Google authorization destination</h1>' });
    });
    await page.goto('https://admin.example.test/site');
    await page.getByLabel('Ad unit').fill('23375345468');
    await page.getByRole('button', { name: 'Connect with Google' }).click();
    return { posts, providerRequests };
}

test('old POST redirect is blocked by the production form-action policy in Chromium', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium', 'Chromium is the browser reporting this failure.');
    const violations = [];
    page.on('console', message => { if (message.text().includes('form-action')) violations.push(message.text()); });
    const { posts, providerRequests } = await openGoogleConnect(page, { legacy: true });
    await expect.poll(() => violations.length).toBeGreaterThan(0);
    expect(posts).toHaveLength(1);
    expect(providerRequests).toHaveLength(0);
});

test('Connect opens Google under strict CSP even without JavaScript and keeps POST data local', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true });
    try {
        const page = await context.newPage();
        const { posts, providerRequests } = await openGoogleConnect(page);
        await expect(page.getByRole('heading', { name: 'Google authorization destination' })).toBeVisible();
        expect(page.url()).toBe(googleUrl);
        expect(posts).toHaveLength(1);
        expect(posts[0].method).toBe('POST');
        expect(posts[0].data.get('ad_unit')).toBe('23375345468');
        expect(posts[0].data.get('_token')).toBe('local-csrf');
        expect(providerRequests).toEqual([{ method: 'GET', body: null, referer: undefined }]);
    } finally {
        await context.close();
    }
});

test('a visible fallback link opens the same authorization when automatic navigation is suppressed', async ({ page }) => {
    const { providerRequests } = await openGoogleConnect(page, { manual: true });
    await expect(page.getByRole('link', { name: 'Back to website reports' })).toHaveAttribute('href', 'https://admin.example.test/site#reporting');
    await page.getByRole('link', { name: 'Continue to Google' }).click();
    await expect(page.getByRole('heading', { name: 'Google authorization destination' })).toBeVisible();
    expect(providerRequests).toEqual([{ method: 'GET', body: null, referer: undefined }]);
});
