import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const outDir = path.resolve('live-diagnostic');
fs.mkdirSync(outDir, { recursive: true });

const runtimeUrl = 'https://raw.githubusercontent.com/eltx1/hm01/main/public/assets/hm-gpt-direct.js';
const runtimeResponse = await fetch(runtimeUrl, { headers: { 'user-agent': 'Horus-Diagnostic/1.0' } });
if (!runtimeResponse.ok) throw new Error(`Could not fetch current main GPT runtime: ${runtimeResponse.status}`);
const runtime = await runtimeResponse.text();

const declaredSizes = [[300,50],[120,90],[960,90],[320,100],[950,90],[300,100],[980,90],[220,90],[970,90],[320,50],[728,90]];
const sizeMap = [
  { viewport: [0,0], maxViewport: [767,65535], sizes: [[300,50],[300,100],[320,50],[320,100]] },
  { viewport: [768,0], maxViewport: [0,0], sizes: [[728,90],[950,90],[960,90],[970,90],[980,90]] },
];

const escapeHtml = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
<div id="hm-gpt-probe" data-hm-gpt-direct="1" data-hm-gpt-ad-unit-path="/23055873217/lordai.net" data-hm-gpt-sizes="${escapeHtml(JSON.stringify(declaredSizes))}" data-hm-gpt-size-map="${escapeHtml(JSON.stringify(sizeMap))}" data-hm-gpt-inner-id="gpt-passback"></div>
<script>${runtime.replace(/<\/script/gi, '<\\/script')}</script>
</body></html>`;

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140 Safari/537.36',
});
const page = await context.newPage();
const events = [];
const responses = [];
const failures = [];

await page.route('https://lordai.net/__hm_gpt_probe__', async route => {
  await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
});

await page.addInitScript(() => {
  window.__hmProbeEvents = [];
  window.googletag = window.googletag || { cmd: [] };
  window.googletag.cmd = window.googletag.cmd || [];
  window.googletag.cmd.push(() => {
    try {
      const pubads = window.googletag.pubads();
      const slotInfo = slot => ({
        adUnitPath: slot?.getAdUnitPath?.() || '',
        elementId: slot?.getSlotElementId?.() || '',
        sizes: slot?.getSizes?.().map?.(s => String(s)) || [],
      });
      for (const type of ['slotRequested','slotResponseReceived','impressionViewable']) {
        pubads.addEventListener(type, event => window.__hmProbeEvents.push({ type, ...slotInfo(event.slot) }));
      }
      pubads.addEventListener('slotRenderEnded', event => window.__hmProbeEvents.push({
        type: 'slotRenderEnded', ...slotInfo(event.slot), isEmpty: Boolean(event.isEmpty), size: event.size || null,
        advertiserId: event.advertiserId ?? null, campaignId: event.campaignId ?? null,
        creativeId: event.creativeId ?? null, lineItemId: event.lineItemId ?? null,
      }));
    } catch (error) {
      window.__hmProbeEvents.push({ type: 'instrumentationError', error: String(error) });
    }
  });
});

page.on('response', async res => {
  const url = res.url();
  if (/securepubads|doubleclick|googlesyndication|pagead/i.test(url)) responses.push({ status: res.status(), url });
});
page.on('requestfailed', req => failures.push({ url: req.url(), error: req.failure()?.errorText || 'unknown' }));

await page.goto('https://lordai.net/__hm_gpt_probe__', { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(12000);

events.push(...await page.evaluate(() => window.__hmProbeEvents || []));
const state = await page.evaluate(() => {
  const el = document.getElementById('hm-gpt-probe');
  return {
    runtimeVersion: el?.getAttribute('data-hm-gpt-runtime-version') || null,
    context: el?.getAttribute('data-hm-gpt-document-context') || null,
    runtimeState: el?.getAttribute('data-hm-gpt-runtime-state') || null,
    status: el?.getAttribute('data-hm-gpt-status') || null,
    eligibleSizes: el?.getAttribute('data-hm-gpt-eligible-sizes') || null,
    renderedWidth: el?.getAttribute('data-hm-gpt-rendered-width') || null,
    renderedHeight: el?.getAttribute('data-hm-gpt-rendered-height') || null,
    childCount: el?.childNodes?.length ?? null,
    innerHTML: (el?.innerHTML || '').slice(0, 3000),
  };
});

await page.screenshot({ path: path.join(outDir, 'gpt-publisher-probe.png'), fullPage: true });
const result = { origin: page.url(), state, events, responses, failures };
fs.writeFileSync(path.join(outDir, 'gpt-publisher-probe.json'), JSON.stringify(result, null, 2));
console.log('GPT_PUBLISHER_PROBE=' + JSON.stringify(result));

await browser.close();

if (!['rendered','empty'].includes(state.runtimeState)) process.exit(3);
