import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1200 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/151 Safari/537.36 Horus-Live-Inventory/1.2',
});
const page = await context.newPage();

let resolveConfig;
let rejectConfig;
const configPromise = new Promise((resolve, reject) => { resolveConfig = resolve; rejectConfig = reject; });
const timer = setTimeout(() => rejectConfig(new Error('production config response timeout')), 45000);

page.on('response', async (response) => {
  const url = response.url();
  if (!/cdn\.horusmedia\.net\/configs\/[^/]+\/production\.v\d+\.[0-9a-f]+\.json(?:\?|$)/i.test(url)) return;
  try {
    const config = await response.json();
    clearTimeout(timer);
    resolveConfig({ url, config });
  } catch (error) {
    clearTimeout(timer);
    rejectConfig(error);
  }
});

await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });
const { url: configUrl, config } = await configPromise;

const placements = (config.placements || []).map((p) => ({
  code: p.code ?? null,
  name: p.name ?? null,
  type: p.type ?? null,
  renderer: p.renderer ?? null,
  eligible: p.eligible ?? null,
  sizes: p.sizes ?? null,
  adUnitPath: p.adUnitPath ?? null,
  formatSettings: p.formatSettings ?? null,
}));

const direct = Object.entries(config.directDemand?.placements || {}).map(([code, p]) => ({
  code,
  enabled: p?.enabled ?? null,
  candidates: (p?.candidates || []).map((c) => ({
    network: c.network ?? null,
    mode: c.mode ?? null,
    priority: c.priority ?? null,
    gamManaged: c.gamManaged ?? null,
    publicPlacementId: c.tag?.publicPlacementId ?? null,
    format: c.tag?.format ?? null,
    allowedSizes: c.tag?.render?.allowedSizes ?? null,
    adUnitPath: c.tag?.container?.attributes?.['data-hm-gpt-ad-unit-path'] ?? null,
    containerId: c.tag?.container?.attributes?.['data-hm-gpt-inner-id'] ?? null,
    runtime: c.tag?.scripts?.[0]?.url ?? null,
  })),
}));

console.log('LIVE_CONFIG_SUMMARY=' + JSON.stringify({
  configUrl,
  siteKey: config.siteKey ?? null,
  configVersion: config.configVersion ?? null,
  status: config.status ?? null,
  servingMode: config.servingMode ?? null,
  placements,
  directDemandEnabled: config.directDemandEnabled ?? null,
  direct,
}, null, 2));

await browser.close();
