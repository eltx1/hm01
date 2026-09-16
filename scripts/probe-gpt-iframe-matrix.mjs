import { chromium } from '@playwright/test';

const target = process.argv[2] || 'https://lordai.net/';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });

const adResponses = [];
page.on('response', async res => {
  if (!/securepubads\.g\.doubleclick\.net\/gampad\/ads/i.test(res.url())) return;
  let body = '';
  try { body = (await res.text()).slice(0, 20000); } catch {}
  adResponses.push({ status: res.status(), url: res.url(), body });
});

await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 });

const cases = [
  { name: 'google-sample', path: '/6355419/Travel/Europe', sizes: [[728, 90]], pageUrl: target },
  { name: 'lordai-no-page-url', path: '/23055873217/lordai.net', sizes: [[320,50],[970,90],[728,90],[320,100]], pageUrl: null },
  { name: 'lordai-explicit-page-url', path: '/23055873217/lordai.net', sizes: [[320,50],[970,90],[728,90],[320,100]], pageUrl: target },
];

const results = await page.evaluate(async cases => {
  function runCase(c, index) {
    return new Promise(resolve => {
      const token = 'hm-probe-' + index + '-' + Math.random().toString(36).slice(2);
      const timer = setTimeout(() => {
        window.removeEventListener('message', handler);
        resolve({ name: c.name, result: { type: 'timeout' } });
      }, 15000);
      function handler(event) {
        const data = event.data || {};
        if (data.token !== token) return;
        clearTimeout(timer);
        window.removeEventListener('message', handler);
        resolve({ name: c.name, result: data.result });
      }
      window.addEventListener('message', handler);

      const iframe = document.createElement('iframe');
      iframe.style.cssText = 'position:fixed;left:0;top:' + (index * 110) + 'px;width:' + c.sizes[0][0] + 'px;height:' + c.sizes[0][1] + 'px;z-index:2147483000;background:white;border:1px solid #ccc';
      const tokenJson = JSON.stringify(token);
      const pathJson = JSON.stringify(c.path);
      const sizesJson = JSON.stringify(c.sizes);
      const pageUrlConfig = c.pageUrl
        ? `googletag.setConfig({adsenseAttributes:{page_url:${JSON.stringify(c.pageUrl)}}});`
        : '';
      iframe.srcdoc = `<!doctype html><html><head><meta charset="utf-8">
<script>window.googletag=window.googletag||{cmd:[]};googletag.cmd.push(function(){
${pageUrlConfig}
var slot=googletag.defineSlot(${pathJson},${sizesJson},'slot');
if(!slot){parent.postMessage({token:${tokenJson},result:{type:'defineSlot-failed'}},'*');return;}
var pubads=googletag.pubads();
pubads.addEventListener('slotRenderEnded',function(e){if(e.slot!==slot)return;parent.postMessage({token:${tokenJson},result:{type:'slotRenderEnded',isEmpty:!!e.isEmpty,size:e.size||null,advertiserId:e.advertiserId??null,campaignId:e.campaignId??null,creativeId:e.creativeId??null,lineItemId:e.lineItemId??null,isBackfill:e.isBackfill??null,responseIdentifier:e.responseIdentifier??null}},'*');});
slot.addService(pubads);googletag.enableServices();googletag.display('slot');
});<\/script>
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"><\/script></head><body><div id="slot"></div></body></html>`;
      document.body.appendChild(iframe);
    });
  }
  const out = [];
  for (let i = 0; i < cases.length; i++) out.push(await runCase(cases[i], i));
  return out;
}, cases);

await page.waitForTimeout(1500);
console.log('GPT_IFRAME_MATRIX=' + JSON.stringify(results));
console.log('GPT_IFRAME_AD_RESPONSES=' + JSON.stringify(adResponses));
await browser.close();

const sample = results.find(x => x.name === 'google-sample')?.result;
if (!sample || sample.type !== 'slotRenderEnded' || sample.isEmpty) process.exit(2);
