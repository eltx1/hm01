import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: ['responsive-viewport-requests.playwright.spec.js'],
    outputDir: 'test-results/responsive-viewport',
    timeout: 30000,
    expect: { timeout: 6000 },
    fullyParallel: true,
    workers: 2,
    reporter: 'line',
    use: { ignoreHTTPSErrors: true, serviceWorkers: 'block', javaScriptEnabled: true },
    projects: ['chromium', 'webkit'].flatMap(browserName => [
        { name: browserName + '-mobile', use: { browserName, viewport: { width: 390, height: 844 } } },
        { name: browserName + '-tablet', use: { browserName, viewport: { width: 820, height: 1180 } } },
        { name: browserName + '-desktop', use: { browserName, viewport: { width: 1280, height: 900 } } },
    ]),
});
