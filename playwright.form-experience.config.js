import { defineConfig } from '@playwright/test';
import base from './playwright.traffic-gate.config.js';

export default defineConfig({
    ...base,
    testMatch: ['form-experience.playwright.spec.js', 'nullable-reporting-metrics.playwright.spec.js', 'adx-unmatched-requests.playwright.spec.js'],
    outputDir: 'test-results/form-experience',
});
