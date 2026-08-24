import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: ['traffic-gate.playwright.spec.js', 'traffic-gate-loader.playwright.spec.js'],
    // WebKit startup can exceed 15 seconds on a cold hosted runner before the
    // test body begins. Keep assertions strict while allowing browser setup.
    timeout: 30000,
    expect: { timeout: 5000 },
    fullyParallel: false,
    workers: 1,
    reporter: 'line',
    use: {
        ignoreHTTPSErrors: true,
        serviceWorkers: 'block',
        javaScriptEnabled: true,
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: { browserName: 'chromium', viewport: { width: 1280, height: 800 } },
        },
        {
            name: 'webkit-desktop',
            use: { browserName: 'webkit', viewport: { width: 1280, height: 800 } },
        },
        {
            name: 'chromium-mobile',
            use: { browserName: 'chromium', ...devices['Pixel 5'] },
        },
        {
            name: 'webkit-mobile',
            use: { browserName: 'webkit', ...devices['iPhone 13'] },
        },
    ],
});
