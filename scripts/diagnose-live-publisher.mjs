import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const outDir = path.resolve('live-diagnostic');
fs.mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1200 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 Horus-Live-E2E/1.0',
});
const page = await context.newPage();

const consoleMessages = [];
const pageErrors = [];
const failedRequests = [];
const responses = [];
const requests = [];

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
  if (interesting(res.url()) || res.status() >= 400) {
    responses.push({ status: res.status(), url: res.url(), contentType: res.headers()['content-type'] || '' });
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
  await page.waitForTimeout(5000);
} catch (error) {
  navigationError = String(error?.stack || error);
}

const dom = await page.evaluate(() => {
  const scripts = [...document.scripts].map(s => ({ src: s.src || '', text: s.src ? '' : (s.textContent || '').slice(0, 500) }));
  const iframes = [...document.querySelectorAll('iframe')].map(f => ({ src: f.src || '', id: f.id || '', name: f.name || '', title: f.title || '' }));
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

const critical = {
  pageLoaded: !navigationError,
  loaderSeen: loaderResponses.some(r => r.status >= 200 && r.status < 400) || dom.scripts.some(s => /hm-loader/i.test(s.src)),
  configSeen: configResponses.length > 0,
  configAll2xx: configResponses.length > 0 && configResponses.every(r => r.status >= 200 && r.status < 300),
  runtimeSeen: runtimeResponses.length > 0,
  runtimeAll2xx: runtimeResponses.length > 0 && runtimeResponses.every(r => r.status >= 200 && r.status < 300),
  noHorus4xx5xx: !horusResponses.some(r => r.status >= 400),
  googleAdTrafficSeen: googleAdResponses.length > 0,
  adIframeSeen: dom.iframes.some(f => /google|doubleclick|googlesyndication|ad/i.test(`${f.src} ${f.id} ${f.name} ${f.title}`)),
};

const result = {
  target,
  navigationError,
  critical,
  requests,
  responses,
  failedRequests,
  consoleMessages,
  pageErrors,
  dom,
};

fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(result, null, 2));
fs.writeFileSync(path.join(outDir, 'page.html'), await page.content());

console.log('LIVE_DIAGNOSTIC_SUMMARY=' + JSON.stringify(critical));
console.log('HORUS_RESPONSES=' + JSON.stringify(horusResponses));
console.log('GOOGLE_AD_RESPONSES=' + JSON.stringify(googleAdResponses.slice(0, 50)));
console.log('FAILED_REQUESTS=' + JSON.stringify(failedRequests.slice(0, 50)));
console.log('CONSOLE_ERRORS=' + JSON.stringify(consoleMessages.filter(x => x.type === 'error').slice(0, 50)));

await browser.close();

if (!critical.pageLoaded) process.exit(2);
