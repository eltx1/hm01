(function (w, d) {
'use strict';
const V='1.1.0', K='__HORUS_MEDIA_LOADER_STATE__';
const S=w[K]=w[K]||{cfg:null,script:null,gpt:null,pb:null,services:false,initial:false,slots:{},units:{},timers:{},boot:null,observer:null,nav:false};

const debug=(c,...a)=>{if(c&&c.debug&&w.console&&console.info)console.info(`[Horus Loader ${V}]`,...a)};
const data=(s,n)=>s&&((s.dataset&&s.dataset[n]!==undefined)?s.dataset[n]:s.getAttribute(`data-${n.replace(/[A-Z]/g,x=>'-'+x.toLowerCase())}`));
const script=()=>S.script||(S.script=d.currentScript&&data(d.currentScript,'siteKey')?d.currentScript:[...d.querySelectorAll('script[data-site-key]')].pop());
const base=s=>data(s,'configBase')||(()=>{try{return new URL(s.src,w.location.href).origin+'/configs'}catch(e){return'https://cdn.horusmedia.net/configs'}})();
const host=()=>String(w.location&&w.location.hostname||'').toLowerCase().replace(/\.$/,'');
const allowed=(h,list)=>Array.isArray(list)&&list.some(x=>{x=String(x||'').toLowerCase().replace(/^https?:\/\//,'').split('/')[0].split(':')[0].replace(/\.$/,'');return x.startsWith('*.')?h.endsWith(x.slice(1))&&h!==x.slice(2):h===x});
const configUrl=(s,key,force)=>{const env=String(data(s,'environment')||'production').toLowerCase(),v=data(s,'configVersion')||(force?Date.now():null),u=`${base(s).replace(/\/$/,'')}/${encodeURIComponent(key)}/${env}.json`;return v?`${u}?v=${encodeURIComponent(v)}`:u};
const fetchCfg=async(s,key,force)=>{const r=await w.fetch(configUrl(s,key,force),{mode:'cors',credentials:'omit',cache:force?'reload':'default'});if(!r||!r.ok)throw Error('Static configuration unavailable');const c=await r.json();if(!c||c.siteKey!==key)throw Error('Static configuration site key mismatch');return c};

const gt=()=>{w.googletag=w.googletag||{cmd:[]};w.googletag.cmd=w.googletag.cmd||[];return w.googletag};
const pb=()=>{w.pbjs=w.pbjs||{que:[]};w.pbjs.que=w.pbjs.que||[];return w.pbjs};
const load=(src,marker)=>new Promise((ok,no)=>{const q=`script[${marker}="1"]`,old=d.querySelector(q);if(old){old.addEventListener('load',ok,{once:true});old.addEventListener('error',no,{once:true});return}const s=d.createElement('script');s.async=true;s.src=src;s.setAttribute(marker,'1');s.onload=ok;s.onerror=()=>no(Error(`${marker} failed to load`));(d.head||d.documentElement).appendChild(s)});
const loadGpt=c=>{const g=gt();if(g.apiReady||g.pubadsReady)return Promise.resolve(g);return S.gpt||(S.gpt=load(c.gpt&&c.gpt.url||'https://securepubads.g.doubleclick.net/tag/js/gpt.js','data-hm-gpt').then(gt))};
const loadPb=c=>{const p=pb(),x=c.prebid||{};if(!x.enabled||!x.build||!x.build.assetUrl)return Promise.reject(Error('Prebid disabled'));if(typeof p.requestBids==='function')return Promise.resolve(p);return S.pb||(S.pb=load(x.build.assetUrl,'data-hm-prebid').then(()=>{const z=pb();if(typeof z.requestBids!=='function')throw Error('Invalid Prebid build');return z}).catch(e=>{S.pb=null;throw e}))};

const sizes=x=>Array.isArray(x)?x.filter(y=>y==='fluid'||Array.isArray(y)&&y.length===2&&+y[0]>0&&+y[1]>0):[];
const target=(o,v)=>{if(!o||!v)return;Object.keys(v).sort().forEach(k=>o.setTargeting&&o.setTargeting(k,(Array.isArray(v[k])?v[k]:[v[k]]).map(String)))};
const mapping=(g,m)=>{if(!g.sizeMapping||!Array.isArray(m)||!m.length)return null;const b=g.sizeMapping();m.forEach(x=>{const z=sizes(x.sizes);if(z.length)b.addSize(Array.isArray(x.viewport)?x.viewport:[0,0],z)});return b.build()};
const elementId=(e,c,p)=>e.id||(e.id=`hm-${String(c.siteKey).replace(/\W/g,'')}-${String(p.code).replace(/[^\w-]/g,'')}-${Object.keys(S.slots).length}`);
const eligible=c=>{const map={};(c.placements||[]).forEach(p=>map[p.code]=p);return[...d.querySelectorAll('.hm-ad[data-placement]')].map(e=>({e,p:map[e.getAttribute('data-placement')]})).filter(x=>x.p&&x.p.enabled&&x.p.status==='active'&&x.p.adUnitPath&&x.e.getAttribute('data-hm-defined')!=='1')};
const lazy=items=>{const a=items.map(x=>x.p.lazyLoad||{}).filter(x=>x.enabled);return a.length?{fetchMarginPercent:Math.max(...a.map(x=>+x.fetchMarginPercent||0)),renderMarginPercent:Math.max(...a.map(x=>+x.renderMarginPercent||0)),mobileScaling:Math.max(...a.map(x=>+x.mobileScaling||1))}:null};

const cmd=(g,fn)=>new Promise(r=>g.cmd.push(()=>{try{r(fn())}catch(e){r(null)}}));
const define=async(c,items)=>{
 if(!items.length)return[];
 try{
  const g=await loadGpt(c);
  const entries=await cmd(g,()=>{
   const ads=g.pubads();target(ads,c.pageTargeting||{});const l=lazy(items);if(l&&ads.enableLazyLoad)ads.enableLazyLoad(l);
   if(c.gpt&&c.gpt.singleRequest&&ads.enableSingleRequest&&!S.services)ads.enableSingleRequest();
   if(!S.initial&&ads.disableInitialLoad){ads.disableInitialLoad();S.initial=true}
   const out=[];
   items.forEach(({e,p})=>{const id=elementId(e,c,p);let slot=null;
    if(p.outOfPageFormat&&g.defineOutOfPageSlot&&g.enums&&g.enums.OutOfPageFormat)slot=g.defineOutOfPageSlot(p.adUnitPath,g.enums.OutOfPageFormat[p.outOfPageFormat]);
    else if(g.defineSlot)slot=g.defineSlot(p.adUnitPath,sizes(p.sizes),id);
    if(!slot)return;const m=mapping(g,p.responsiveMappings);if(m&&slot.defineSizeMapping)slot.defineSizeMapping(m);target(slot,p.targeting||{});
    slot.setForceSafeFrame&&slot.setForceSafeFrame(!!p.safeFrame);slot.setCollapseEmptyDiv&&slot.setCollapseEmptyDiv(!!p.collapseEmptyDiv,!!p.collapseEmptyDiv);slot.addService&&slot.addService(ads);
    e.setAttribute('data-hm-defined','1');e.setAttribute('data-hm-status','defined');S.slots[id]={slot,p,e,count:0};out.push(S.slots[id]);
   });
   if(!S.services&&g.enableServices){g.enableServices();S.services=true}
   out.forEach(x=>g.display(x.p.outOfPageFormat?x.slot:x.e.id));return out;
  })||[];
  await request(c,entries,false);entries.forEach(x=>schedule(c,x));diag(c,entries);return entries;
 }catch(e){debug(c,'GPT unavailable',e);return[]}
};

const gam=(c,entries,refresh)=>{if(!entries.length)return Promise.resolve([]);const g=gt();return cmd(g,()=>{try{const a=g.pubads(),sl=entries.map(x=>x.slot);a.refresh&&a.refresh(sl,refresh?{changeCorrelator:false}:undefined);entries.forEach(x=>x.e.setAttribute('data-hm-status',refresh?'refreshed':'requested'))}catch(e){debug(c,'GAM request failed safely',e)}return entries})};

const clearHb=x=>{const s=x.slot;if(s&&s.getTargetingKeys&&s.clearTargeting)s.getTargetingKeys().filter(k=>String(k).startsWith('hb_')).forEach(k=>s.clearTargeting(k))};
const configure=(c,p)=>{if(S.pbVersion===c.configVersion)return;const x=c.prebid||{},v={bidderSequence:x.bidderSequence||'random',priceGranularity:x.priceGranularity||'medium',enableSendAllBids:false};if(x.currency&&x.currency.adServerCurrency)v.currency=x.currency;if(x.consentManagement&&Object.keys(x.consentManagement).length)v.consentManagement=x.consentManagement;p.setConfig(v);S.pbVersion=c.configVersion};
const adUnit=x=>({code:x.e.id,mediaTypes:x.p.prebid.mediaTypes||{},bids:(x.p.prebid.bids||[]).map(b=>({bidder:b.bidder,params:b.params||{}}))});
const timeoutEvent=(c,codes)=>{if(!(c.prebid&&c.prebid.timeoutReporting))return;debug(c,'Prebid timeout',codes);try{w.dispatchEvent(typeof w.CustomEvent==='function'?new CustomEvent('horus:prebid-timeout',{detail:{siteKey:c.siteKey,adUnitCodes:codes}}):new Event('horus:prebid-timeout'))}catch(e){}};

const auction=async(c,entries)=>{
 const p=await loadPb(c);
 return new Promise(resolve=>{
  const t=Math.max(300,Math.min(5000,+c.prebid.auctionTimeoutMs||1200)),codes=entries.map(x=>x.e.id);let done=false;
  const finish=x=>{if(done)return;done=true;clearTimeout(timer);if(x.timedOut)timeoutEvent(c,codes);resolve(x)},timer=setTimeout(()=>finish({hasBid:false,failed:false,timedOut:true}),t+100);
  p.que.push(()=>{try{configure(c,p);entries.forEach(x=>{if(S.units[x.e.id])return;const u=adUnit(x);if(u.bids.length){p.addAdUnits(u);S.units[x.e.id]=true}});
   p.requestBids({adUnitCodes:codes,timeout:t,bidsBackHandler:(r,to)=>{try{p.setTargetingForGPTAsync&&p.setTargetingForGPTAsync(codes);const h=p.getHighestCpmBids?p.getHighestCpmBids(codes):[];finish({hasBid:Array.isArray(h)&&h.length>0,failed:false,timedOut:!!to})}catch(e){finish({hasBid:false,failed:true,timedOut:!!to})}}})
  }catch(e){finish({hasBid:false,failed:true,timedOut:false})}})
 });
};

const request=async(c,entries,refresh)=>{
 if(!entries.length)return[];
 const pe=entries.filter(x=>c.prebid&&c.prebid.enabled&&x.p.prebid&&x.p.prebid.enabled&&!x.p.outOfPageFormat);
 if(!pe.length)return gam(c,entries,refresh);pe.forEach(clearHb);
 try{const r=await auction(c,pe),send=!r.hasBid&&c.prebid.gamFallback===false&&!r.failed?entries.filter(x=>!pe.includes(x)):entries;await gam(c,send,refresh);return r}
 catch(e){debug(c,'Prebid failed; GAM fallback',e);await gam(c,entries,refresh);return{hasBid:false,failed:true,timedOut:false}}
};

const schedule=(c,x)=>{const r=x.p.refresh||{},pr=c.prebid&&c.prebid.refresh||{},sec=+r.intervalSeconds||0;if(!r.enabled||sec<30)return;const key=x.e.id,limit=+r.limit||0;if(S.timers[key])clearInterval(S.timers[key]);S.timers[key]=setInterval(()=>{if(d.visibilityState&&d.visibilityState!=='visible')return;if(limit&&x.count>=limit){clearInterval(S.timers[key]);delete S.timers[key];return}x.count++;x.p.prebid&&x.p.prebid.enabled&&pr.enabled!==false&&pr.auctionBeforeRefresh!==false?request(c,[x],true):gam(c,[x],true)},sec*1000)};
const diag=(c,e)=>{if(c.debug)w.__HM_DIAGNOSTICS__={loaderVersion:V,configVersion:c.configVersion,siteKey:c.siteKey,hostname:host(),servingMode:c.servingMode,gamNetworkCode:c.gamNetworkCode,prebidEnabled:!!(c.prebid&&c.prebid.enabled),prebidBuild:c.prebid&&c.prebid.build&&c.prebid.build.version,prebidBidders:c.prebid&&c.prebid.activeBidders||[],definedPlacements:e.map(x=>x.p.code)}};
const scan=c=>!c||c.status!=='active'||c.immediatePause?Promise.resolve([]):define(c,eligible(c));

const spa=()=>{if(!S.observer&&w.MutationObserver&&d.documentElement){S.observer=new MutationObserver(()=>{clearTimeout(S.scan);S.scan=setTimeout(()=>scan(S.cfg),25)});S.observer.observe(d.documentElement,{childList:true,subtree:true})}if(S.nav||!w.history)return;S.nav=true;['pushState','replaceState'].forEach(m=>{const f=w.history[m];if(typeof f==='function')w.history[m]=function(){const r=f.apply(this,arguments);w.dispatchEvent(new Event('horus:navigation'));return r}});w.addEventListener('popstate',()=>w.dispatchEvent(new Event('horus:navigation')));w.addEventListener('horus:navigation',()=>setTimeout(()=>scan(S.cfg),0))};
const delegate=(c,s)=>{const x=c.loader||{};if(!x.assetUrl||!x.version||x.version===V||w.__HM_RELEASE_DELEGATED__)return false;w.__HM_RELEASE_DELEGATED__=true;const n=d.createElement('script');n.async=true;n.src=x.assetUrl+(x.assetUrl.includes('?')?'&':'?')+'v='+encodeURIComponent(x.version);n.setAttribute('data-site-key',c.siteKey);n.setAttribute('data-config-base',base(s));n.setAttribute('data-environment',String(data(s,'environment')||'production'));(d.head||d.documentElement).appendChild(n);return true};

const boot=(o={})=>{const s=o.script||script(),key=o.siteKey||data(s,'siteKey');if(!key||!w.fetch)return Promise.resolve([]);if(S.boot&&!o.force)return S.boot;S.boot=fetchCfg(s,key,!!o.force).then(c=>{S.cfg=c;if(!allowed(host(),c.allowedHostnames)){debug(c,'Hostname rejected');return[]}if(c.status!=='active'||c.immediatePause){debug(c,'Site paused');return[]}if(delegate(c,s))return[];spa();return scan(c)}).catch(e=>{debug({debug:!!data(s,'debug')},'Loader stopped safely',e);return[]}).finally(()=>S.boot=null);return S.boot};

w.HorusMediaLoader={version:V,boot,refresh:()=>boot({force:true}),scan:()=>scan(S.cfg),getConfig:()=>S.cfg,_resetForTests:()=>{Object.values(S.timers).forEach(clearInterval);Object.assign(S,{cfg:null,script:null,gpt:null,pb:null,services:false,initial:false,slots:{},units:{},timers:{},boot:null,pbVersion:null})}};
if(!w.__HM_DISABLE_AUTOBOOT__)(d.readyState==='loading'?d.addEventListener('DOMContentLoaded',()=>boot(),{once:true}):boot());
})(window,document);
