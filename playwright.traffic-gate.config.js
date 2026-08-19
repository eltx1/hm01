import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: 'traffic-gate.playwright.spec.js',
    timeout: 15000,
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
