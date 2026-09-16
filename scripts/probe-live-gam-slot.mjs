import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const adUnitPath = process.argv[3] || '/23055873217/lordai.net';
const sizes = [[320, 50], [970, 90], [728, 90], [320, 100]];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });

const result = await page.evaluate(async ({ adUnitPath, sizes }) => {
  const id = 'hm-gam-independent-probe-' + Math.random().toString(36).slice(2);
  const node = document.createElement('div');
  node.id = id;
  node.style.cssText = 'position:fixed;left:0;top:0;width:320px;height:50px;z-index:-1;opacity:.01;pointer-events:none';
  document.body.appendChild(node);

  window.googletag = window.googletag || { cmd: [] };

  const outcome = new Promise(resolve => {
    const timer = setTimeout(() => resolve({ type: 'timeout' }), 12000);
    window.googletag.cmd.push(() => {
      const slot = window.googletag.defineSlot(adUnitPath, sizes, id);
      if (!slot) {
        clearTimeout(timer);
        resolve({ type: 'defineSlot-failed' });
        return;
      }
      const pubads = window.googletag.pubads();
      pubads.addEventListener('slotRenderEnded', event => {
        if (event.slot !== slot) return;
        clearTimeout(timer);
        resolve({
          type: 'slotRenderEnded',
          isEmpty: Boolean(event.isEmpty),
          size: event.size || null,
          isBackfill: event.isBackfill ?? null,
          advertiserId: event.advertiserId ?? null,
          campaignId: event.campaignId ?? null,
          creativeId: event.creativeId ?? null,
          lineItemId: event.lineItemId ?? null,
          sourceAgnosticCreativeId: event.sourceAgnosticCreativeId ?? null,
          sourceAgnosticLineItemId: event.sourceAgnosticLineItemId ?? null,
          responseIdentifier: event.responseIdentifier ?? null,
        });
      });
      slot.addService(pubads);
      window.googletag.enableServices();
      window.googletag.display(id);
    });
  });

  if (!window.googletag.apiReady) {
    await new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.async = true;
      script.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
      script.onload = resolve;
      script.onerror = () => reject(new Error('GPT script failed to load'));
      document.head.appendChild(script);
    });
  }

  return await outcome;
}, { adUnitPath, sizes });

console.log('INDEPENDENT_GAM_PROBE=' + JSON.stringify({ target, adUnitPath, sizes, result }));
await browser.close();

if (result.type !== 'slotRenderEnded') process.exit(2);
