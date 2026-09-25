import { defineConfig } from '@playwright/test';
import base from './playwright.traffic-gate.config.js';

export default defineConfig({
    ...base,
    testMatch: ['form-experience.playwright.spec.js'],
    outputDir: 'test-results/form-experience',
});
