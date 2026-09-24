import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
const root = new URL('../../', import.meta.url);
const theme = await readFile(new URL('public/assets/dashboard-theme.js', root), 'utf8');
const app = (await readFile(new URL('resources/js/app.js', root), 'utf8')).replace(/^import .*;$/gm, '');
const css = (await Promise.all(['brand-tokens', 'components', 'app', 'ux-launch', 'interface-density', 'dashboard-theme', 'reporting-experience'].map(name => readFile(new URL(`resources/css/${name}.css`, root), 'utf8')))).join('\n').replace(/^@import .*;$/gm, '');
const markup = `<!doctype html><html data-hm-theme="dark"><head><meta name="viewport" content="width=device-width,initial-scale=1"><script src="/theme.js"></script><link rel="stylesheet" href="/dashboard.css"></head><body><div class="admin-shell"><aside class="sidebar" id="control-navigation"><strong>Horus Media</strong><nav class="navigation-links"><a class="active" href="/">Reports & earnings</a><a href="/finance">Statements</a></nav></aside><button class="sidebar-scrim" data-nav-close aria-label="Close navigation"></button><main><header class="topbar"><button class="mobile-nav-toggle" data-nav-toggle aria-controls="control-navigation" aria-expanded="false">Menu</button><div class="topbar-title"><h1>Reports & earnings</h1></div><button class="hm-button-secondary theme-toggle" data-theme-toggle hidden>White Mode</button></header><section class="hero"><h2>Your earnings at a glance</h2></section><div class="report-period"><form class="report-filter"><label>From<input type="date" class="hm-input" value="2026-09-01"></label><label>Website<select class="hm-input"><option>All websites</option></select></label><button class="hm-button-primary">Update report</button></form></div><section class="report-metrics"><article><p class="eyebrow">Reported earnings</p><strong class="metric">USD 125.50</strong><span class="muted">Your share, including estimates</span></article><article><p class="eyebrow">Impressions</p><strong class="metric">12,500</strong></article><article><p class="eyebrow">Clicks</p><strong class="metric">250</strong></article><article><p class="eyebrow">Paid to date</p><strong class="metric">USD 90.00</strong></article></section><article><h2>Statement and invoice</h2><span class="status-badge-success">Accepted</span><span class="status-badge-danger">Action required</span><details><summary>Daily report details</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Earnings</th></tr></thead><tbody><tr><td>2026-09-23</td><td>USD 25.00</td></tr></tbody></table></div></details></article></main></div><script src="/app.js"></script></body></html>`;
async function open(page) {
    await page.route('https://dashboard.test/**', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/dashboard.css') return route.fulfill({ contentType: 'text/css', body: css });
        return route.fulfill({ contentType: url.pathname.endsWith('.js') ? 'application/javascript' : 'text/html', body: url.pathname === '/theme.js' ? theme : url.pathname === '/app.js' ? app : markup });
    });
    await page.goto('https://dashboard.test/');
}
test('dashboard light/dark round trip preserves controls, mobile navigation and stored preference', async ({ page }, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await open(page);
    // Capture a painted initial frame before checking WebKit's computed child styles.
    await page.screenshot({ path: info.outputPath('dashboard-initial.png'), fullPage: true });
    // Assert the initial palette as well as returning to it after a toggle.
    await expect(page.locator('body')).toHaveCSS('color', 'rgb(246, 248, 255)');
    const original = await page.locator('html').evaluate(el => getComputedStyle(el).getPropertyValue('--hm-bg-page'));
    const originalField = await page.locator('input').evaluate(el => getComputedStyle(el).backgroundColor);
    await expect(page.locator('html')).toHaveAttribute('data-hm-theme', 'dark');
    const buttonBox = await page.getByRole('button', { name: 'Update report' }).boundingBox();
    expect(buttonBox.height).toBeLessThanOrEqual(48);
    expect(buttonBox.height).toBeGreaterThanOrEqual(36);
    await expect(page.locator('input')).toHaveCSS('border-top-style', 'solid');
    await page.getByRole('button', { name: 'Switch to White Mode' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-hm-theme', 'light');
    await expect(page.locator('input')).toHaveValue('2026-09-01');
    await expect(page.locator('select')).toHaveValue('All websites');
    await page.getByText('Daily report details').click();
    await expect(page.getByRole('table')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.locator('input').evaluate(el => getComputedStyle(el).backgroundColor)).toBe('rgb(255, 255, 255)');
    expect(await page.locator('.muted').evaluate(el => getComputedStyle(el).color)).toBe('rgb(77, 93, 118)');
    if (info.project.name.includes('mobile')) {
        await page.getByRole('button', { name: 'Menu', exact: true }).click();
        await expect(page.locator('#control-navigation')).toHaveClass(/is-open/);
        await page.keyboard.press('Escape');
        await expect(page.locator('[data-nav-toggle]')).toHaveAttribute('aria-expanded', 'false');
    }
    await page.screenshot({ path: info.outputPath('dashboard-white.png'), fullPage: true });
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-hm-theme', 'light');
    await page.getByRole('button', { name: 'Switch to Dark Mode' }).click();
    await expect(page.locator('body')).toHaveCSS('color', 'rgb(246, 248, 255)');
    expect(await page.locator('html').evaluate(el => getComputedStyle(el).getPropertyValue('--hm-bg-page'))).toBe(original);
    expect(await page.locator('input').evaluate(el => getComputedStyle(el).backgroundColor)).toBe(originalField);
    await page.screenshot({ path: info.outputPath('dashboard-dark.png'), fullPage: true });
    expect(errors).toEqual([]);
});
test('blocked browser storage keeps both themes and forms usable', async ({ page }) => {
    await page.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Blocked', 'SecurityError'); } }));
    await open(page);
    await page.getByRole('button', { name: 'Switch to White Mode' }).click();
    await page.locator('input').fill('2026-08-01');
    await page.getByRole('button', { name: 'Switch to Dark Mode' }).click();
    await expect(page.locator('input')).toHaveValue('2026-08-01');
});
