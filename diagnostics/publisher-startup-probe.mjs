import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { initializeTestMedia, imaFixture } from '../tests/Browser/helpers/startup-preparation-fixture.js';
const root=process.env.SNAPSHOT_DIR;
const app=process.env.APP_DIR;
const config=JSON.parse(fs.readFileSync(path.join(root,'natega.bluekl.com.config.json'),'utf8'));
const files=new Map(fs.readdirSync(root).filter(f=>f.endsWith('.js')).map(f=>[f,fs.readFileSync(path.join(root,f),'utf8')]));
const gateHtml=fs.readFileSync(path.join(app,'public/traffic-gate/index.html'),'utf8');
const gateJs=fs.readFileSync(path.join(app,'public/assets/traffic-gate/horus-traffic-gate.js'),'utf8');
const browser=await chromium.launch({headless:true});
const results=[];
for(const scenario of ['video-held','gpt-adapter-held','normal']){
 const context=await browser.newContext({viewport:{width:1440,height:900},serviceWorkers:'block'});
 const page=await context.newPage();const timeline=[];const start=Date.now();const mark=(kind,detail)=>timeline.push({ms:Date.now()-start,kind,detail});
 let release;const hold=new Promise(r=>release=r);const blocked=[];
 await page.exposeFunction('probeMark',mark);
 await page.addInitScript(()=>{
  window.__HM_DISABLE_AUTOBOOT__=true;
  window.googletag={cmd:[()=>{
   window.probeMark('gpt-ready','api');
   for(const key of ['defineSlot','defineOutOfPageSlot','display','enableServices']){
    const original=window.googletag[key];if(typeof original!=='function')continue;
    window.googletag[key]=function(...args){window.probeMark('gpt-'+key,typeof args[0]==='string'?args[0]:null);return original.apply(this,args);};
   }
   window.googletag.pubads().addEventListener('slotRequested',e=>window.probeMark('gpt-slotRequested',e.slot.getSlotElementId()));
  }]};
 });
 page.on('pageerror',e=>mark('page-error',e.message));
 page.on('console',m=>{if(['error','warning'].includes(m.type()))mark('console-'+m.type(),m.text().slice(0,180));});
 await page.route('**/*',async route=>{
  const request=route.request(),u=new URL(request.url());
  const headers={'Access-Control-Allow-Origin':'*','Cache-Control':'public,max-age=3600'};
  if(u.origin==='https://natega.bluekl.com'&&request.isNavigationRequest())return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0}main{height:2500px}article{height:900px}[data-placement="quick_video_floating"]{width:100%}</style></head><body><div class="hm-ad" data-placement="quick_video_floating"></div><main><article>Publisher content</article></main><script id="loader" type="application/json" src="https://cdn.horusmedia.net/hm-loader.js" data-site-key="'+config.siteKey+'"></script></body></html>'});
  if(u.origin==='https://cdn.horusmedia.net'){
   if(u.pathname.includes('/configs/'))return route.fulfill({headers,json:u.pathname.includes('_global')?{controls:{}}:config});
   const filename=u.pathname.split('/').pop();
   if(files.has(filename)){
    mark('runtime-fetch',filename);
    if((scenario==='video-held'&&filename.includes('hm-video-direct'))||(scenario==='gpt-adapter-held'&&filename.includes('hm-gpt-direct')))await hold;
    return route.fulfill({headers,contentType:'application/javascript',body:files.get(filename)});
   }
  }
  if(u.origin==='https://verify.horusmedia.net'){
   if(u.pathname.includes('/configs/'))return route.fulfill({headers,json:config});
   return route.fulfill(u.pathname.endsWith('.js')?{headers,contentType:'application/javascript',body:gateJs}:{headers,contentType:'text/html',body:gateHtml});
  }
  if(u.origin==='https://challenges.cloudflare.com')return route.fulfill({headers,contentType:'application/javascript',body:'window.turnstile={render(n,o){setTimeout(()=>o.callback("fixture-only"),700);return "fixture";},remove(){},reset(){}};'});
  if(u.origin==='https://siteverify.horusmedia.net'){
   const cors={'Access-Control-Allow-Origin':'https://verify.horusmedia.net','Access-Control-Allow-Methods':'POST','Access-Control-Allow-Headers':'Content-Type'};
   if(request.method()==='OPTIONS')return route.fulfill({status:204,headers:cors});
   mark('verification-response','PASS');return route.fulfill({headers:cors,json:{success:true,pageNonce:request.postDataJSON().pageNonce}});
  }
  if(u.origin==='https://imasdk.googleapis.com'&&u.pathname==='/js/sdkloader/ima3.js'){
   mark('ima-fetch','fixture');return route.fulfill({headers,contentType:'application/javascript',body:imaFixture({deferManager:true})});
  }
  // Only Google SDK JavaScript, never auctions/measurement/creatives, reaches the network.
  if(request.resourceType()==='script'&&u.hostname==='securepubads.g.doubleclick.net'&&(u.pathname==='/tag/js/gpt.js'||/^\/pagead\/managed\/js\/gpt\/[^?]+\.js$/.test(u.pathname))){mark('real-gpt-script',u.pathname);return route.continue();}
  if(u.origin==='https://creative.example')return route.fulfill({contentType:'text/html',body:'<!doctype html><html><body>Offline fixture</body></html>'});
  blocked.push(u.origin+u.pathname);mark('blocked-external',u.origin+u.pathname);return route.abort('blockedbyclient');
 });
 await page.goto('https://natega.bluekl.com/',{waitUntil:'domcontentloaded'});
 await page.evaluate(initializeTestMedia,{});
 await page.addScriptTag({content:files.get('hm-loader.js')});
 await page.evaluate(()=>{window.HorusMediaLoader.boot({script:document.getElementById('loader')});});
 await page.waitForTimeout(5500);
 const snapshot=await page.evaluate(()=>({gate:window.HorusMediaLoader.getTrafficGateState(),video:window.videoMetrics,slots:Array.from(document.querySelectorAll('[data-placement]')).map(el=>({code:el.dataset.placement,status:el.dataset.hmStatus,direct:el.dataset.hmDirect,html:el.innerHTML.slice(0,650)})),scripts:Array.from(document.scripts).map(s=>s.src).filter(Boolean)}));
 mark('snapshot',snapshot);release();await page.waitForTimeout(1000);
 results.push({scenario,timeline,blocked});await context.close();
}
await browser.close();fs.writeFileSync('publisher-startup-probe.json',JSON.stringify(results,null,2));
for(const r of results){console.log('SCENARIO',r.scenario);for(const e of r.timeline)if(e.kind!=='snapshot')console.log(JSON.stringify(e));console.log(JSON.stringify(r.timeline.find(e=>e.kind==='snapshot')));}
