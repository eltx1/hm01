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
