import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';
import { applyVideoPreparationTransform } from '../../scripts/transform-loader-video-preparation.mjs';

const raw = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const composed = [applyTrafficGateTransform, applyShadowClickGuardTransform, applyPlacementPresetTransform,
    applyDirectPreparationTransform, applyVideoPreparationTransform].reduce((s, f) => f(s), raw);
const minified = await readFile(new URL('../../public/assets/hm-loader.min.js', import.meta.url), 'utf8');
const gateHtml = await readFile(new URL('../../public/traffic-gate/index.html', import.meta.url), 'utf8');
const gateJs = await readFile(new URL('../../public/assets/traffic-gate/horus-traffic-gate.js', import.meta.url), 'utf8');
const P = 'https://publisher-early.example', C = 'https://cdn.horusmedia.net', G = 'https://verify.horusmedia.net';
const KEY = 'EARLY_VERIFICATION';

function config() {
    return {
        schemaVersion: 4, siteKey: KEY, configVersion: 1, status: 'active', immediatePause: false,
        allowedHostnames: ['publisher-early.example'], servingMode: 'HORUS_GAM', gamNetworkCode: '123',
        loader: { version: '2.0.0' }, controls: {},
        privacy: { mode: 'AUTO', requireConsentBeforeAds: true, cmp: { timeoutMs: 100, actionOnTimeout: 'LIMITED_ADS' } },
        clickGuard: { enabled: false }, prebid: { enabled: false }, directDemand: {enabled: false},
        trafficGate: { enabled: true, readiness: 'READY', provider: 'CLOUDFLARE_TURNSTILE_SERVER_VERIFIED',
            gateOrigin: G, siteKey: '1x00000000000000000000BB', policy: 'BALANCED',
            timings: {initialWaitMs: 500, maxWaitMs: 10000, retryIntervalMs: 500} },
        placements: [{code: 'display', type: 'DISPLAY', enabled: true, status: 'active', renderer: 'GAM',
            adUnitPath: '/123/display', gamEnabled: true, sizes: [[300,250]], responsiveMappings: [],
            lazyLoad: {enabled: false}, refresh: {enabled: false}, targeting: {}}],
    };
}

const gpt = `(() => {
    const pending = window.googletag?.cmd || [];
    const ads = {setPrivacySettings(){},setTargeting(){},disableInitialLoad(){},enableSingleRequest(){},addEventListener(){},
        refresh(){window.adCalls=(window.adCalls||0)+1;}};
    const slot = {addService(){return this;},setTargeting(){return this;},defineSizeMapping(){return this;},setForceSafeFrame(){return this;},setCollapseEmptyDiv(){return this;}};
    window.googletag={apiReady:true,pubadsReady:true,cmd:{push(f){f();}},pubads(){return ads;},setConfig(){},
        defineSlot(){return slot;},sizeMapping(){return {addSize(){return this;},build(){return [];}};},enableServices(){},display(){}};
    pending.forEach(f=>f());
})();`;

async function open(page, options = {}) {
    const selected = config();
    if (options.recaptcha) selected.recaptchaObservation = {enabled:true,siteKey:"6LcObservationBrowserTest_12345"};
    if (options.timings) Object.assign(selected.trafficGate.timings, options.timings);
    if (options.denied) selected.allowedHostnames = ['another.example'];
    if (options.gateDisabled) selected.trafficGate.enabled = false;
    if (options.privacyBlocked) selected.privacy = {mode: 'STRICT', requireConsentBeforeAds: true,
        cmp: {timeoutMs:100,actionOnTimeout:'BLOCK_ADS'}};
    const counts = {documents:0, configs:0, gateConfigs:0, sdkDownloads:0, verifies:0, gateScripts:0, recaptcha:0};
    let releaseConfig, releaseVerification, releaseSdk, releaseDocument, releaseParser;
    const parserHold = new Promise(r=>{releaseParser=r;});
    if (!options.holdParser) releaseParser();
    const documentHold = new Promise(r => {releaseDocument=r;});
    if (!options.holdDocument && !options.holdAllDocuments) releaseDocument();
    const configHold = new Promise(r => {releaseConfig=r;});
    const verificationHold = new Promise(r => {releaseVerification=r;});
    const sdkHold = new Promise(r => {releaseSdk=r;});
    if (!options.holdConfig) releaseConfig();
    if (!options.holdVerification) releaseVerification();
    if (!options.holdSdk) releaseSdk();
    const sleep = ms => new Promise(r => setTimeout(r, ms || 0));
    await page.route('**/*', async route => {
        const req = route.request(), u = new URL(req.url());
        const cache = {'Cache-Control':'public, max-age=3600','Access-Control-Allow-Origin':'*'};
        if (u.origin === P && u.pathname === '/parser-delay.js') { await parserHold; return route.fulfill({contentType:'application/javascript',body:'window.parserFinished=true;'}); }
        if (u.origin === P) return route.fulfill({contentType:'text/html', body:`<!doctype html><html><head>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <script>window.cmpCalls=0; window.__tcfapi=function(){window.cmpCalls++;}; window.__gpp=function(){window.cmpCalls++;};</script>
            ${options.noReferrer ? '<meta name="referrer" content="no-referrer">' : ''}</head><body>
            <div class="hm-ad" data-placement="display"></div>
            <script src="${C}/hm-loader.js" data-site-key="${KEY}" data-config-version="1"></script>
            ${options.duplicate ? `<script src="${C}/hm-loader.js" data-site-key="${KEY}" data-config-version="1"></script>` : ''}
            <script src="/parser-delay.js"></script></body></html>`});
        if (u.origin === C && u.pathname === '/hm-loader.js') return route.fulfill({contentType:'application/javascript',body: options.loader || minified});
        if (u.origin === C) {
            if (!u.pathname.includes('_global')) counts.configs++;
            await configHold;
            await sleep(options.configMs);
            return route.fulfill({headers:cache, json: u.pathname.includes('_global') ? {controls:options.kill ? {adServingDisabled:true} : {}} : selected});
        }
        if (u.origin === G) {
            if (u.pathname === '/traffic-gate/') {
                counts.documents++;
                const first = counts.documents === 1;
                if (options.holdAllDocuments || (options.holdDocument && first)) await documentHold;
                if (options.failWarm && first) return route.abort('failed');
                await sleep(options.documentMs);
                const html=options.inline ? gateHtml.replace('<script src="/assets/traffic-gate/horus-traffic-gate.js?v=2" defer></script>','').replace('</body>',`<script data-hm-gate-runtime="/assets/traffic-gate/horus-traffic-gate.js">${gateJs}</script></body>`) : gateHtml;
                const hash=createHash('sha256').update(gateJs).digest('base64');
                return route.fulfill({contentType:'text/html',headers:{'Content-Security-Policy':`default-src 'none'; script-src 'self' https://challenges.cloudflare.com 'sha256-${hash}'; frame-src https://challenges.cloudflare.com; connect-src 'self' https://siteverify.horusmedia.net https://challenges.cloudflare.com; style-src 'unsafe-inline'; frame-ancestors https:`},body:html});
            }
            if (u.pathname.includes('/configs/')) {
                counts.gateConfigs++;
                return route.fulfill({json:selected});
            }
            counts.gateScripts++; await sleep(options.gateScriptMs);
            return route.fulfill({contentType:'application/javascript',body:options.legacyGate ? gateJs.replace("if (window.location.origin === GATE_ORIGIN && window.location.hash === '#prepare')", 'if (false)') : gateJs});
        }
        if (u.origin === 'https://www.google.com' && u.pathname === '/recaptcha/api.js') {
            counts.recaptcha++;
            if (options.recaptcha === 'hang') return new Promise(() => {});
            if (options.recaptcha === 'fail') return route.abort('failed');
            return route.fulfill({contentType:'application/javascript',body:`const badge=document.createElement('div');badge.className='grecaptcha-badge';badge.innerHTML='<iframe src="https://www.google.com/recaptcha/api2/anchor?k=6LcObservationBrowserTest_12345"></iframe>';document.body.appendChild(badge);window.grecaptcha={ready:f=>f(),execute:(key,options)=>{window.observedAction=options.action;window.observationExecutions=(window.observationExecutions||0)+1;return Promise.resolve('unused-token');}};`});
        }
        if (u.origin === 'https://challenges.cloudflare.com') {
            counts.sdkDownloads++;
            await sdkHold;
            await sleep(options.sdkMs);
            return route.fulfill({headers:cache,contentType:'application/javascript',body:`
                window.cfExecutions=(window.cfExecutions||0)+1;
                window.turnstile={render(n,o){window.cfRenders=(window.cfRenders||0)+1;queueMicrotask(()=>o.callback('offline-token'));return 'widget';},remove(){},reset(){}};`});
        }
        if (u.origin === 'https://siteverify.horusmedia.net') {
            const headers = {'Access-Control-Allow-Origin':G,'Access-Control-Allow-Headers':'Content-Type','Access-Control-Allow-Methods':'POST'};
            if (req.method()==='OPTIONS') return route.fulfill({status:204,headers});
            counts.verifies++;
            await verificationHold;
            return route.fulfill({status:options.serverReject?422:200,headers,json:{success:!options.serverReject,pageNonce:req.postDataJSON().pageNonce}});
        }
        if (u.origin === 'https://securepubads.g.doubleclick.net') return route.fulfill({headers:cache,contentType:'application/javascript',body:gpt});
        return route.abort('blockedbyclient'); // No external ad/challenge/tracking requests.
    });
    await page.goto(P, {waitUntil:options.holdParser?'commit':'domcontentloaded'});
    return {counts,releaseConfig,releaseVerification,releaseSdk,releaseDocument,releaseParser};
}


for (const inline of [false,true]) {
    for (const denied of [false,true]) {
        test(`compiled startup with parser and CMP pending: inline=${inline}, serverReject=${denied}`, async ({page})=>{
            const run=await open(page,{inline,holdParser:true,holdVerification:true,serverReject:denied,privacyBlocked:true});
            await expect.poll(()=>run.counts.verifies).toBe(1);
            expect(await page.evaluate(()=>document.readyState)).toBe('loading');
            expect(await page.evaluate(()=>window.cmpCalls)).toBe(0);
            expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
            run.releaseVerification();
            if(denied) {
                await expect.poll(()=>page.evaluate(()=>window.HorusMediaLoader.getTrafficGateState().state)).toBe('ERROR');
                expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
            } else {
                // Assert our scheduling boundary, independently of a browser's
                // evaluation order for async scripts while a parser script is held.
                await expect.poll(()=>page.evaluate(()=>window.HorusMediaLoader.getStartupTrace().events.some(e=>e.phase==='GPT load'))).toBe(true);
                expect(await page.locator('script[data-hm-gpt="1"]').count()).toBe(1);
                expect(await page.evaluate(()=>document.readyState)).toBe('loading');
                const trace=await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace());
                console.log('PARSER_HELD_STARTUP',JSON.stringify({inline,trace,adCalls:await page.evaluate(()=>window.adCalls||0)}));
                expect(trace.events.find(e=>e.phase==='Horus start').ms).toBeGreaterThanOrEqual(trace.events.find(e=>e.phase==='CF pass').ms);
            }
            expect(run.counts.gateScripts).toBe(inline?0:1);
            run.releaseParser(); await page.waitForLoadState('domcontentloaded');
            await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(denied?0:1);
            expect(await page.evaluate(()=>window.cmpCalls)).toBe(0);
        });
    }
}

test('controlled gate document comparison removes one delayed script fetch, not verification',async ({browser})=>{
    const times=[];
    for(const inline of [false,true]) {
        const context=await browser.newContext(); const page=await context.newPage();
        const run=await open(page,{inline,gateScriptMs:1200});
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
        const trace=await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace());
        times.push({inline,passMs:trace.events.find(e=>e.phase==='CF pass').ms,counts:run.counts});
        expect(run.counts.verifies).toBe(1); await context.close();
    }
    console.log('CONTROLLED_GATE_TRANSPORT',JSON.stringify(times));
    expect(times[1].passMs).toBeLessThan(times[0].passMs-500);
});

for (const mode of ['success','fail','hang']) {
    test(`optional reCAPTCHA ${mode} never delays ads after verified PASS`, async ({page}) => {
        const run = await open(page, {recaptcha:mode,holdVerification:true,inline:true});
        await expect.poll(()=>run.counts.verifies).toBe(1);
        expect(run.counts.recaptcha).toBe(0);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        run.releaseVerification();
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
        await expect.poll(()=>run.counts.recaptcha).toBe(1);
        expect(await page.evaluate(()=>window.HorusMediaLoader.getTrafficGateState().state)).toBe('PASSED');
        if (mode==='success') {
            await expect.poll(()=>page.evaluate(()=>window.observationExecutions||0)).toBe(1);
            expect(await page.evaluate(()=>window.observedAction)).toBe('horus_cf_pass');
            await expect(page.locator('.grecaptcha-badge')).toHaveCSS('visibility','hidden');
            await expect(page.locator('.grecaptcha-badge')).toHaveAttribute('data-hm-recaptcha-badge','hidden');
        }
        expect(run.counts.verifies).toBe(1);
        expect(await page.evaluate(()=>window.adCalls)).toBe(1);
    });
}
for (const mode of ['denied','disabled','default']) {
    test(`reCAPTCHA has zero requests when ${mode}`, async ({page}) => {
        const run = await open(page, {recaptcha:mode!=='default',serverReject:mode==='denied',gateDisabled:mode==='disabled',inline:true});
        await expect.poll(()=>page.evaluate(()=>window.HorusMediaLoader.getTrafficGateState().state)).toBe(mode==='denied'?'ERROR':mode==='disabled'?'DISABLED':'PASSED');
        await page.waitForTimeout(1300);
        expect(run.counts.recaptcha).toBe(0);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(mode==='denied'?0:1);
    });
}
