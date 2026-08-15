import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import test from 'node:test';

const root = resolve(import.meta.dirname, '../..');
const read = (relative) => readFileSync(join(root, relative), 'utf8');
const authDir = join(root, 'resources/views/auth');
const authMarkup = readdirSync(authDir)
    .filter((name) => name.endsWith('.blade.php'))
    .map((name) => readFileSync(join(authDir, name), 'utf8'))
    .join('\n')
    .toLowerCase();

test('authentication surfaces permit password managers and copy paste', () => {
    assert.doesNotMatch(authMarkup, /onpaste\s*=/i);
    assert.doesNotMatch(authMarkup, /oncopy\s*=/i);
    assert.doesNotMatch(authMarkup, /oncut\s*=/i);
    assert.match(authMarkup, /autocomplete="current-password"/i);
    assert.match(authMarkup, /autocomplete="new-password"/i);
    assert.match(authMarkup, /autocomplete="one-time-code"/i);
});

test('primary layouts expose skip navigation and private indexing metadata', () => {
    for (const layout of ['admin', 'guest', 'staff-auth', 'applicant']) {
        const markup = read(`resources/views/layouts/${layout}.blade.php`);
        assert.match(markup, /class="skip-link"/);
        assert.match(markup, /id="main-content"/);
        assert.match(markup, /name="robots" content="noindex, nofollow, noarchive, nosnippet"/);
    }
});

test('launch accessibility stylesheet protects focus, target size, responsive layout and reduced motion', () => {
    const css = read('resources/css/ux-launch.css');
    assert.match(css, /:focus-visible/);
    assert.match(css, /scroll-margin-block/);
    assert.match(css, /min-height:\s*2\.75rem/);
    assert.match(css, /\.table-wrap/);
    assert.match(css, /@media \(max-width: 430px\)/);
    assert.match(css, /@media \(max-width: 768px\)/);
    assert.match(css, /@media \(max-width: 1024px\)/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
});

test('progressive javascript restores keyboard focus and guards duplicate submission', () => {
    const javascript = read('resources/js/app.js');
    assert.match(javascript, /navigationToggle\.focus\(\)/);
    assert.match(javascript, /event\.key === 'Escape'/);
    assert.match(javascript, /form\.dataset\.submitting/);
    assert.match(javascript, /aria-busy/);
    assert.match(javascript, /data-copy-target/);
});

test('safe branded error family is complete', () => {
    const layout = read('resources/views/layouts/error.blade.php');
    assert.match(layout, /Horus Media/);
    assert.match(layout, /noindex, nofollow, noarchive, nosnippet/);

    for (const status of [403, 404, 419, 429, 500, 503]) {
        const markup = read(`resources/views/errors/${status}.blade.php`);
        assert.match(markup, new RegExp(`@section\\('code', '${status}'\\)`));
        assert.doesNotMatch(markup, /SQLSTATE|APP_ENV|stack trace|\/var\/www/i);
    }
});

test('critical first-run workspaces use reusable empty states and normalized statuses', () => {
    const publisherSites = read('resources/views/publisher/sites/index.blade.php');
    const applicationQueue = read('resources/views/admin/publisher-applications/index.blade.php');
    const advertiserCampaigns = read('resources/views/advertiser/campaigns/index.blade.php');

    for (const markup of [publisherSites, applicationQueue, advertiserCampaigns]) {
        assert.match(markup, /<x-empty-state/);
        assert.match(markup, /<x-status-badge/);
    }
});
