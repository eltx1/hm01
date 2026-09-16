import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });

await page.waitForFunction(() => Boolean(window.__HORUS_GPT_DIRECT_RUNTIME_V1__), null, { timeout: 15000 });

const id = 'hm-gpt-google-sample-probe';
await page.evaluate(({ id }) => {
  document.getElementById(id)?.remove();
  const node = document.createElement('div');
  node.id = id;
  node.setAttribute('data-hm-gpt-direct', '1');
  node.setAttribute('data-hm-gpt-ad-unit-path', '/6355419/Travel/Europe/France/Paris');
  node.setAttribute('data-hm-gpt-inner-id', 'hm-google-sample-inner');
  node.setAttribute('data-hm-gpt-sizes', '[[300,250]]');
  node.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483646;background:#fff';
  document.body.appendChild(node);
}, { id });

let outcome = null;
try {
  await page.waitForFunction(({ id }) => {
    const node = document.getElementById(id);
    const status = node?.getAttribute('data-hm-gpt-status');
    return status === 'rendered' || status === 'empty' || status === 'failed';
  }, { id }, { timeout: 15000 });
  outcome = await page.evaluate(({ id }) => {
    const node = document.getElementById(id);
    return {
      runtimeState: node?.getAttribute('data-hm-gpt-runtime-state') || null,
      status: node?.getAttribute('data-hm-gpt-status') || null,
      width: node?.getAttribute('data-hm-gpt-rendered-width') || null,
      height: node?.getAttribute('data-hm-gpt-rendered-height') || null,
      shadowIframe: Boolean(node?.shadowRoot?.querySelector('iframe[data-hm-gpt-direct-frame="1"]')),
    };
  }, { id });
} catch (error) {
  outcome = await page.evaluate(({ id }) => {
    const node = document.getElementById(id);
    return {
      runtimeState: node?.getAttribute('data-hm-gpt-runtime-state') || null,
      status: node?.getAttribute('data-hm-gpt-status') || null,
      shadowIframe: Boolean(node?.shadowRoot?.querySelector('iframe[data-hm-gpt-direct-frame="1"]')),
    };
  }, { id });
  outcome.waitError = String(error);
}

console.log('HORUS_GOOGLE_SAMPLE_PROBE=' + JSON.stringify(outcome));
await browser.close();

if (outcome?.status !== 'rendered') process.exit(2);
