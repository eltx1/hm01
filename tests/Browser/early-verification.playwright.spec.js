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
    if (options.timings) Object.assign(selected.trafficGate.timings, options.timings);
    if (options.denied) selected.allowedHostnames = ['another.example'];
    if (options.gateDisabled) selected.trafficGate.enabled = false;
    if (options.privacyBlocked) selected.privacy = {mode: 'STRICT', requireConsentBeforeAds: true,
        cmp: {timeoutMs:100,actionOnTimeout:'BLOCK_ADS'}};
    const counts = {documents:0, configs:0, gateConfigs:0, sdkDownloads:0, verifies:0};
    let releaseConfig, releaseVerification, releaseSdk, releaseDocument;
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
        if (u.origin === P) return route.fulfill({contentType:'text/html', body:`<!doctype html><html><head>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            ${options.noReferrer ? '<meta name="referrer" content="no-referrer">' : ''}</head><body>
            <div class="hm-ad" data-placement="display"></div>
            <script src="${C}/hm-loader.js" data-site-key="${KEY}" data-config-version="1"></script>
            ${options.duplicate ? `<script src="${C}/hm-loader.js" data-site-key="${KEY}" data-config-version="1"></script>` : ''}
            </body></html>`});
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
                return route.fulfill({contentType:'text/html',body:options.gateHtml || gateHtml});
            }
            if (u.pathname.includes('/configs/')) {
                counts.gateConfigs++;
                return route.fulfill({json:selected});
            }
            return route.fulfill({contentType:'application/javascript',body:options.legacyGate ? gateJs.replace("if (window.location.origin === GATE_ORIGIN && window.location.hash === '#prepare')", 'if (false)') : gateJs});
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
    await page.goto(P, {waitUntil:'domcontentloaded'});
    return {counts,releaseConfig,releaseVerification,releaseSdk,releaseDocument};
}

for (const mode of ['composed','minified']) {
    test(`${mode}: parked document loads during config; no SDK execution, HELLO, challenge, verification or ads`, async ({page}) => {
        const run = await open(page, {loader:mode==='composed'?composed:minified,holdConfig:true,holdVerification:true,duplicate:true});
        await expect.poll(()=>page.evaluate(()=>window.__HORUS_MEDIA_LOADER_STATE__.trafficGateDocumentPreparation?.ready===true)).toBe(true);
        expect(run.counts.sdkDownloads).toBe(0);
        await expect.poll(()=>page.frames().some(f=>f.url().startsWith(G))).toBe(true);
        const frame = page.frames().find(f=>f.url().startsWith(G));
        expect(await frame.evaluate(()=>({executions:window.cfExecutions||0,renders:window.cfRenders||0,state:document.documentElement.dataset.gateState})))
            .toEqual({executions:0,renders:0,state:'BOOTING'});
        expect(run.counts.gateConfigs).toBe(0);
        expect(run.counts.verifies).toBe(0);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        expect(run.counts.documents).toBe(1);
        // Unsolicited PASS from the very same parked origin/frame is not authorization.
        await frame.evaluate(()=>parent.postMessage({type:'HORUS_TRAFFIC_GATE_PASS',protocolVersion:2,pageNonce:'not-authorized',serverVerified:true},'https://publisher-early.example'));
        expect(await page.evaluate(()=>window.HorusMediaLoader.getTrafficGateState().state)).toBe('BOOTING');
        run.releaseConfig();
        await expect.poll(()=>run.counts.verifies).toBe(1);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        expect(run.counts.documents).toBe(1);
        expect(run.counts.sdkDownloads).toBe(1);
        run.releaseVerification();
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
        expect(run.counts.configs).toBe(1);
    });

    for (const variant of ['denied','kill','gateDisabled','serverReject','privacyBlocked','failWarm']) {
        test(`${mode}: early gate preserves ${variant} behavior`, async ({page}) => {
            const run = await open(page,{loader:mode==='composed'?composed:minified,[variant]:true});
            if (variant==='gateDisabled' || variant==='failWarm') {
                await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
                expect(run.counts.verifies).toBe(variant==='gateDisabled'?0:1);
                if (variant==='failWarm') expect(run.counts.documents).toBe(2);
            } else {
                await expect.poll(()=>page.evaluate(()=>!window.__HORUS_MEDIA_LOADER_STATE__.booting)).toBe(true);
                expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
                if (variant==='kill' || variant==='denied') expect(run.counts.verifies).toBe(0);
            }
            await expect(page.locator('[data-hm-traffic-gate-document]')).toHaveCount(0);
        });
    }

    test(`${mode}: pending prepared document completes after config without cancellation or duplicate verification`, async ({page}) => {
        const run = await open(page,{loader:mode==='composed'?composed:minified,holdDocument:true,
            holdVerification:true,duplicate:true,timings:{initialWaitMs:3000}});
        await expect.poll(()=>page.evaluate(()=>window.HorusMediaLoader.getStartupTrace().events
            .some(e=>e.phase==='CF adopt' && e.mode==='pending'))).toBe(true);
        expect(run.counts.documents).toBe(1); expect(run.counts.sdkDownloads).toBe(0);
        const before = await page.evaluate(()=>({start:window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.startedAt,
            timer:window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.maxTimer}));
        run.releaseDocument();
        await expect.poll(()=>run.counts.verifies).toBe(1);
        expect(run.counts.documents).toBe(1); expect(run.counts.gateConfigs).toBe(1); expect(run.counts.sdkDownloads).toBe(1);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        expect(await page.evaluate(()=>({start:window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.startedAt,
            timer:window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.maxTimer}))).toEqual(before);
        run.releaseVerification();
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
        const trace = await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace());
        expect(trace.events.some(e=>e.phase==='CF transport')).toBe(false);
        expect(trace.build).toBe('startup-trace-2');
        if (mode==='minified') expect(trace.runtimeBuild).toMatch(/^sha256:[a-f0-9]{64}$/);
        else expect(trace.runtimeBuild).toBe('source-inflight-1');
    });

    test(`${mode}: silent pending document falls back once and its late readiness cannot request ads again`, async ({page}) => {
        const run = await open(page,{loader:mode==='composed'?composed:minified,holdDocument:true,holdVerification:true});
        await expect.poll(()=>run.counts.verifies).toBe(1);
        expect(run.counts.documents).toBe(2); expect(run.counts.sdkDownloads).toBe(1);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        const events = await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace().events);
        expect(events.filter(e=>e.phase==='CF start')).toHaveLength(1);
        expect(events.filter(e=>e.phase==='CF transport' && e.reason==='pending_deadline')).toHaveLength(1);
        run.releaseDocument(); run.releaseVerification();
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
        expect(run.counts.verifies).toBe(1); expect(run.counts.documents).toBe(2);
    });

    for (const compatibility of ['legacyGate','noReferrer']) {
        test(`${mode}: ${compatibility} uses one ordinary handshake without duplicate challenges`, async ({page}) => {
            const run = await open(page,{loader:mode==='composed'?composed:minified,[compatibility]:true,holdVerification:true});
            await expect.poll(()=>run.counts.verifies).toBe(1);
            expect(run.counts.documents).toBe(2); expect(run.counts.sdkDownloads).toBe(1);
            expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
            run.releaseVerification();
            await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
            expect(run.counts.verifies).toBe(1);
        });
    }

    test(`${mode}: both transports pending still time out without authorizing ads`, async ({page}) => {
        const run = await open(page,{loader:mode==='composed'?composed:minified,holdAllDocuments:true,
            timings:{maxWaitMs:2000}});
        await expect.poll(()=>page.evaluate(()=>window.HorusMediaLoader.getTrafficGateState().state)).toBe('TIMEOUT');
        expect(run.counts.documents).toBe(2); expect(run.counts.verifies).toBe(0); expect(run.counts.sdkDownloads).toBe(0);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        expect(await page.evaluate(()=>window.__HORUS_MEDIA_LOADER_STATE__.trafficGate.documentListener===null)).toBe(true);
        run.releaseDocument();
    });

    test(`${mode}: slow SDK cannot prevent the existing config handshake`, async ({page}) => {
        const run = await open(page,{loader:mode==='composed'?composed:minified,holdSdk:true});
        await expect.poll(()=>run.counts.gateConfigs).toBe(1);
        expect(run.counts.verifies).toBe(0);
        expect(await page.evaluate(()=>window.adCalls||0)).toBe(0);
        run.releaseSdk();
        await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
    });
}

// An isolated Actions comparison supplies the *old* compiled asset. This is a
// controlled critical-path experiment, not a claim about real Cloudflare time.
if (process.env.HM_EARLY_BASELINE) {
    test('old/new controlled config+document critical path comparison', async ({browser}) => {
        const baseline = await readFile(process.env.HM_EARLY_BASELINE, 'utf8');
        const oldHtml = await readFile(process.env.HM_EARLY_BASELINE_HTML, 'utf8');
        const timings = [];
        for (const [name,loader,html] of [['baseline',baseline,oldHtml],['candidate',minified,gateHtml]]) {
            const context = await browser.newContext();
            const page = await context.newPage();
            const run = await open(page,{loader,gateHtml:html,configMs:800,documentMs:500,sdkMs:200});
            await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
            const trace = await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace());
            const phase = name => trace.events.find(e=>e.phase===name)?.ms;
            timings.push({name,configReady:phase('CFG ready'),cfPrepare:phase('CF prepare'),cfReady:phase('CF ready'),cfPass:phase('CF pass'),horusStart:phase('Horus start'),counts:run.counts});
            await context.close();
        }
        console.log('EARLY_CF_CONTROLLED_TIMINGS',JSON.stringify(timings));
        expect(timings[1].cfReady).toBeLessThan(timings[0].cfReady-250);
        expect(timings[1].counts.documents).toBe(1);
    });
}

// Compare the actual pending-navigation case with an explicit, separately
// supplied PR219 artifact. All providers are mocked; these are not field times.
if (process.env.HM_PENDING_BASELINE) {
    test('controlled pending navigation removes cancellation rather than shortening authorization', async ({browser}) => {
        const baseline = await readFile(process.env.HM_PENDING_BASELINE, 'utf8');
        const results = [];
        for (const [name,loader] of [['baseline',baseline],['candidate',minified]]) {
            const context = await browser.newContext(); const page = await context.newPage();
            const run = await open(page,{loader,configMs:500,documentMs:800,sdkMs:100});
            await expect.poll(()=>page.evaluate(()=>window.adCalls||0)).toBe(1);
            const trace = await page.evaluate(()=>window.HorusMediaLoader.getStartupTrace());
            results.push({name,counts:run.counts,trace}); await context.close();
        }
        console.log('PENDING_NAVIGATION_COMPARISON', JSON.stringify(results));
        expect(results[0].counts.documents).toBe(2);
        expect(results[1].counts.documents).toBe(1);
        expect(results[1].counts.verifies).toBe(1);
        for (const result of results) {
            const pass = result.trace.events.find(e=>e.phase==='CF pass').ms;
            expect(result.trace.events.find(e=>e.phase==='Horus start').ms).toBeGreaterThanOrEqual(pass);
        }
    });
}
