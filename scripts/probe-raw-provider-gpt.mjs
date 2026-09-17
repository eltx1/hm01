import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const outDir = path.resolve('live-diagnostic');
fs.mkdirSync(outDir, { recursive: true });

const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js" crossorigin="anonymous"></script>
<div id="gpt-passback">
  <script>
    window.googletag = window.googletag || {cmd: []};
    googletag.cmd.push(function() {
      googletag.defineSlot('/23055873217/lordai.net', [[300, 50], [120, 90], [960, 90], [320, 100], [950, 90], [300, 100], [980, 90], [220, 90], [970, 90], [320, 50], [728, 90]], 'gpt-passback').addService(googletag.pubads());
      googletag.enableServices();
      googletag.display('gpt-passback');
    });
  <\/script>
</div>
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

await page.route('https://lordai.net/__hm_raw_provider_probe__', async route => {
  await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
});

await page.addInitScript(() => {
  window.__hmRawEvents = [];
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
        pubads.addEventListener(type, event => window.__hmRawEvents.push({ type, ...slotInfo(event.slot) }));
      }
      pubads.addEventListener('slotRenderEnded', event => window.__hmRawEvents.push({
        type: 'slotRenderEnded', ...slotInfo(event.slot), isEmpty: Boolean(event.isEmpty), size: event.size || null,
        advertiserId: event.advertiserId ?? null, campaignId: event.campaignId ?? null,
        creativeId: event.creativeId ?? null, lineItemId: event.lineItemId ?? null,
      }));
    } catch (error) {
      window.__hmRawEvents.push({ type: 'instrumentationError', error: String(error) });
    }
  });
});

page.on('response', res => {
  const url = res.url();
  if (/securepubads|doubleclick|googlesyndication|pagead/i.test(url)) responses.push({ status: res.status(), url });
});
page.on('requestfailed', req => failures.push({ url: req.url(), error: req.failure()?.errorText || 'unknown' }));

await page.goto('https://lordai.net/__hm_raw_provider_probe__', { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(12000);

events.push(...await page.evaluate(() => window.__hmRawEvents || []));
const dom = await page.evaluate(() => ({
  html: (document.getElementById('gpt-passback')?.innerHTML || '').slice(0, 5000),
  childCount: document.getElementById('gpt-passback')?.childNodes?.length ?? null,
}));
await page.screenshot({ path: path.join(outDir, 'raw-provider-gpt-probe.png'), fullPage: true });
const result = { origin: page.url(), events, responses, failures, dom };
fs.writeFileSync(path.join(outDir, 'raw-provider-gpt-probe.json'), JSON.stringify(result, null, 2));
console.log('RAW_PROVIDER_GPT_PROBE=' + JSON.stringify(result));
await browser.close();

const render = events.find(e => e.type === 'slotRenderEnded' && e.adUnitPath === '/23055873217/lordai.net');
if (!render) process.exit(3);
