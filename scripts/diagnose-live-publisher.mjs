import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const outDir = path.resolve('live-diagnostic');
fs.mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1200 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 Horus-Live-E2E/1.1',
});
const page = await context.newPage();

// Register GPT instrumentation before any publisher/runtime script executes.
// Playwright injects this into the top page and every subsequently-created frame,
// which is important because Quick Monetize isolates trusted provider tags.
await page.addInitScript(() => {
  window.__hmGptEvents = [];
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
      pubads.addEventListener('slotRequested', event => {
        window.__hmGptEvents.push({ type: 'slotRequested', ...slotInfo(event.slot) });
      });
      pubads.addEventListener('slotResponseReceived', event => {
        window.__hmGptEvents.push({ type: 'slotResponseReceived', ...slotInfo(event.slot) });
      });
      pubads.addEventListener('slotRenderEnded', event => {
        window.__hmGptEvents.push({
          type: 'slotRenderEnded',
          ...slotInfo(event.slot),
          isEmpty: Boolean(event.isEmpty),
          size: event.size || null,
          advertiserId: event.advertiserId ?? null,
          campaignId: event.campaignId ?? null,
          creativeId: event.creativeId ?? null,
          lineItemId: event.lineItemId ?? null,
          sourceAgnosticCreativeId: event.sourceAgnosticCreativeId ?? null,
          sourceAgnosticLineItemId: event.sourceAgnosticLineItemId ?? null,
          yieldGroupIds: event.yieldGroupIds || null,
          companyIds: event.companyIds || null,
        });
      });
      pubads.addEventListener('impressionViewable', event => {
        window.__hmGptEvents.push({ type: 'impressionViewable', ...slotInfo(event.slot) });
      });
    } catch (error) {
      window.__hmGptEvents.push({ type: 'instrumentationError', error: String(error) });
    }
  });
});

const consoleMessages = [];
const pageErrors = [];
const failedRequests = [];
const responses = [];
const requests = [];
const capturedBodies = [];
const pendingCaptures = [];

const interesting = (url) => /horusmedia|hm-loader|\/configs\/|\/runtime\/|googletag|gpt\.js|doubleclick|googlesyndication|securepubads|pagead/i.test(url);

page.on('console', msg => {
  consoleMessages.push({ type: msg.type(), text: msg.text() });
});
page.on('pageerror', err => pageErrors.push(String(err?.stack || err)));
page.on('request', req => {
  if (interesting(req.url())) requests.push({ method: req.method(), resourceType: req.resourceType(), url: req.url() });
});
page.on('requestfailed', req => {
  failedRequests.push({ url: req.url(), resourceType: req.resourceType(), error: req.failure()?.errorText || 'unknown' });
});
page.on('response', res => {
  const url = res.url();
  if (interesting(url) || res.status() >= 400) {
    responses.push({ status: res.status(), url, contentType: res.headers()['content-type'] || '' });
  }
  if (/\/configs\/.*\.json(?:\?|$)/i.test(url) || /securepubads\.g\.doubleclick\.net\/gampad\/ads/i.test(url)) {
    const capture = (async () => {
      try {
        const text = await res.text();
        capturedBodies.push({ status: res.status(), url, body: text.slice(0, 120000) });
      } catch (error) {
        capturedBodies.push({ status: res.status(), url, bodyError: String(error) });
      }
    })();
    pendingCaptures.push(capture);
  }
});

let navigationError = null;
try {
  const response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  if (!response) navigationError = 'No navigation response';
  await page.waitForTimeout(12_000);
  await page.evaluate(async () => {
    window.scrollTo(0, document.body.scrollHeight);
    await new Promise(r => setTimeout(r, 2500));
    window.scrollTo(0, 0);
  });
  await page.waitForTimeout(7000);
} catch (error) {
  navigationError = String(error?.stack || error);
}

await Promise.allSettled(pendingCaptures);

const frameDiagnostics = [];
for (const frame of page.frames()) {
  try {
    frameDiagnostics.push({
      url: frame.url(),
      name: frame.name(),
      gptEvents: await frame.evaluate(() => window.__hmGptEvents || []),
      htmlSample: (await frame.content()).slice(0, 12000),
    });
  } catch (error) {
    frameDiagnostics.push({ url: frame.url(), name: frame.name(), error: String(error) });
  }
}

const dom = await page.evaluate(() => {
  const scripts = [...document.scripts].map(s => ({ src: s.src || '', text: s.src ? '' : (s.textContent || '').slice(0, 500) }));
  const iframes = [...document.querySelectorAll('iframe')].map(f => ({ src: f.src || '', id: f.id || '', name: f.name || '', title: f.title || '', width: f.getBoundingClientRect().width, height: f.getBoundingClientRect().height }));
  const candidates = [...document.querySelectorAll('div,ins,section')]
    .filter(el => /(^|[-_])(ad|ads|advert|gpt|google|hm|horus)([-_]|$)/i.test(`${el.id} ${el.className}`))
    .slice(0, 200)
    .map(el => ({
      tag: el.tagName,
      id: el.id || '',
      className: typeof el.className === 'string' ? el.className : '',
      text: (el.textContent || '').trim().slice(0, 200),
      width: el.getBoundingClientRect().width,
      height: el.getBoundingClientRect().height,
      status: el.getAttribute('data-hm-status'),
      nativeError: el.getAttribute('data-hm-native-last-error'),
      directError: el.getAttribute('data-hm-direct-last-error'),
      nativeState: el.getAttribute('data-hm-native'),
      directState: el.getAttribute('data-hm-direct'),
    }));
  const perf = performance.getEntriesByType('resource').map(e => e.name).filter(u => /horusmedia|hm-loader|\/configs\/|\/runtime\/|googletag|gpt\.js|doubleclick|googlesyndication|securepubads|pagead/i.test(u));
  return {
    title: document.title,
    href: location.href,
    readyState: document.readyState,
    scripts,
    iframes,
    candidates,
    perf,
    bodyTextSample: (document.body?.innerText || '').slice(0, 1000),
  };
});

await page.screenshot({ path: path.join(outDir, 'page.png'), fullPage: true });

const horusResponses = responses.filter(r => /horusmedia|hm-loader|\/configs\/|\/runtime\//i.test(r.url));
const configResponses = horusResponses.filter(r => /\/configs\//i.test(r.url));
const loaderResponses = horusResponses.filter(r => /hm-loader/i.test(r.url));
const runtimeResponses = horusResponses.filter(r => /\/runtime\//i.test(r.url));
const googleAdResponses = responses.filter(r => /doubleclick|googlesyndication|securepubads|pagead/i.test(r.url));
const gptEvents = frameDiagnostics.flatMap(f => (f.gptEvents || []).map(event => ({ frameUrl: f.url, ...event })));
const renderEvents = gptEvents.filter(e => e.type === 'slotRenderEnded');

const critical = {
  pageLoaded: !navigationError,
  loaderSeen: loaderResponses.some(r => r.status >= 200 && r.status < 400) || dom.scripts.some(s => /hm-loader/i.test(s.src)),
  configSeen: configResponses.length > 0,
  configAll2xx: configResponses.length > 0 && configResponses.every(r => r.status >= 200 && r.status < 300),
  runtimeSeen: runtimeResponses.length > 0,
  runtimeAll2xx: runtimeResponses.length > 0 && runtimeResponses.every(r => r.status >= 200 && r.status < 300),
  noHorus4xx5xx: !horusResponses.some(r => r.status >= 400),
  googleAdTrafficSeen: googleAdResponses.length > 0,
  gptRenderEventSeen: renderEvents.length > 0,
  gptNonEmptyRenderSeen: renderEvents.some(e => e.isEmpty === false),
  adIframeSeen: dom.iframes.some(f => /google|doubleclick|googlesyndication|ad/i.test(`${f.src} ${f.id} ${f.name} ${f.title}`)),
};

const result = {
  target,
  navigationError,
  critical,
  requests,
  responses,
  capturedBodies,
  failedRequests,
  consoleMessages,
  pageErrors,
  frameDiagnostics,
  gptEvents,
  dom,
};

fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(result, null, 2));
fs.writeFileSync(path.join(outDir, 'page.html'), await page.content());

console.log('LIVE_DIAGNOSTIC_SUMMARY=' + JSON.stringify(critical));
console.log('GPT_EVENTS=' + JSON.stringify(gptEvents.slice(0, 100)));
console.log('HORUS_RESPONSES=' + JSON.stringify(horusResponses));
console.log('GOOGLE_AD_RESPONSES=' + JSON.stringify(googleAdResponses.slice(0, 50)));
console.log('CAPTURED_AD_BODIES=' + JSON.stringify(capturedBodies.filter(x => /gampad\/ads/i.test(x.url)).map(x => ({ status: x.status, url: x.url, body: x.body?.slice(0, 12000), bodyError: x.bodyError }))));
console.log('FAILED_REQUESTS=' + JSON.stringify(failedRequests.slice(0, 50)));
console.log('CONSOLE_ERRORS=' + JSON.stringify(consoleMessages.filter(x => x.type === 'error').slice(0, 50)));

await browser.close();

if (!critical.pageLoaded) process.exit(2);
