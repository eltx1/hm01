import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

const config = (patch = {}) => ({
    siteKey: 'HM_TEST', servingMode: 'HORUS_GAM', gamNetworkCode: '123456789',
    configVersion: 5, status: 'active', immediatePause: false, debug: false,
    allowedHostnames: ['publisher.example'], loader: { version: '1.1.0' },
    gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true },
    prebid: {
        enabled: true, gamConnectionId: 'gam-horus', gamConnectionType: 'HORUS_GAM',
        build: { version: '11.25.0', assetUrl: 'https://cdn.horusmedia.net/assets/prebid/prebid-11.25.0.min.js' },
        auctionTimeoutMs: 300, priceGranularity: 'medium', currency: { adServerCurrency: 'USD' },
        bidderSequence: 'fixed', consentManagement: {}, timeoutReporting: true, gamFallback: true,
        refresh: { enabled: true, auctionBeforeRefresh: true }, activeBidders: ['pubmatic'],
    },
    pageTargeting: { site: ['test'] },
    placements: [{
        code: 'top', name: 'Top', type: 'DISPLAY', status: 'active', enabled: true,
        adUnitPath: '/123456789/top', sizes: [[300, 250]],
        responsiveMappings: [], targeting: { position: ['top'] },
        lazyLoad: { enabled: true, fetchMarginPercent: 500, renderMarginPercent: 200, mobileScaling: 2 },
        refresh: { enabled: false }, collapseEmptyDiv: true, safeFrame: false, outOfPageFormat: null,
        prebid: { enabled: true, mediaTypes: { banner: { sizes: [[300, 250]] } },
            bids: [{ bidder: 'pubmatic', params: { publisherId: '123', adSlot: 'top' } }] },
    }],
    ...patch,
});

function harness(cfg, outcome = 'bid', hostname = 'publisher.example') {
    const m = { fetch: [], gpt: 0, prebid: 0, slots: [], refresh: 0, auctions: 0, targeting: 0, timeouts: 0, order: [] };
    const attrs = { 'data-placement': 'top' };
    const el = { id: '', getAttribute: k => attrs[k] ?? null, setAttribute: (k, v) => { attrs[k] = String(v); } };
    const scriptAttrs = { 'data-site-key': cfg.siteKey, 'data-config-base': 'https://cdn.horusmedia.net/configs', 'data-environment': 'production' };
    const script = { src: 'https://cdn.horusmedia.net/hm-loader.js', dataset: { siteKey: cfg.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute: k => scriptAttrs[k] ?? null, setAttribute: (k, v) => { scriptAttrs[k] = String(v); } };
    const pubads = { setTargeting() {}, enableLazyLoad() {}, enableSingleRequest() {}, disableInitialLoad() {},
        refresh() { m.refresh++; m.order.push('gam'); } };
    const q = { push(fn) { fn(); } };
    const googletag = { cmd: q, apiReady: false, pubads: () => pubads, sizeMapping: () => ({ addSize() { return this; }, build: () => [] }),
        defineSlot(path, sizes, id) { m.slots.push({ path, sizes, id }); const keys = {};
            return { id, setTargeting(k, v) { keys[k] = v; return this; }, getTargetingKeys: () => Object.keys(keys),
                clearTargeting(k) { delete keys[k]; }, defineSizeMapping() { return this; }, setForceSafeFrame() { return this; },
                setCollapseEmptyDiv() { return this; }, addService() { return this; } }; },
        defineOutOfPageSlot: () => null, enableServices() { googletag.apiReady = true; }, display() {},
        enums: { OutOfPageFormat: {} } };
    const loaded = {};
    const document = { currentScript: script, readyState: 'complete', visibilityState: 'visible', documentElement: {},
        querySelector: s => s.includes('prebid') ? loaded.pb ?? null : s.includes('gpt') ? loaded.gpt ?? null : null,
        querySelectorAll: s => s === '.hm-ad[data-placement]' ? [el] : s === 'script[data-site-key]' ? [script] : [],
        createElement() { const a = {}; return { src: '', async: false, setAttribute(k, v) { a[k] = String(v); },
            getAttribute: k => a[k], addEventListener(k, fn) { if (k === 'load') this.onload = fn; else this.onerror = fn; } }; },
        addEventListener() {}, head: { appendChild(node) {
            if (node.getAttribute('data-hm-gpt') === '1') { m.gpt++; loaded.gpt = node; queueMicrotask(() => node.onload()); }
            if (node.getAttribute('data-hm-prebid') === '1') { m.prebid++; loaded.pb = node;
                if (outcome === 'load-fail') queueMicrotask(() => node.onerror());
                else queueMicrotask(() => { installPb(); node.onload(); }); }
        } } };
    class Event { constructor(type) { this.type = type; } }
    class CustomEvent extends Event { constructor(type, init) { super(type); this.detail = init.detail; } }
    class MutationObserver { observe() {} }
    const listeners = {};
    const sandbox = { console, URL, Promise, setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        Event, CustomEvent, MutationObserver, googletag, pbjs: { que: [] }, document,
        location: { hostname, href: `https://${hostname}/` }, history: { pushState() {}, replaceState() {} },
        fetch: async u => { m.fetch.push(String(u)); return { ok: true, json: async () => structuredClone(cfg) }; },
        addEventListener: (k, fn) => (listeners[k] ||= []).push(fn),
        dispatchEvent(e) { if (e.type === 'horus:prebid-timeout') m.timeouts++; (listeners[e.type] || []).forEach(fn => fn(e)); },
        __HM_DISABLE_AUTOBOOT__: true };
    sandbox.window = sandbox;
    function installPb() {
        const queued = sandbox.pbjs.que.slice(), p = sandbox.pbjs;
        p.que = { push(fn) { fn(); } }; p.setConfig = () => {}; p.addAdUnits = u => { m.unit = u; };
        p.requestBids = o => { m.auctions++; m.order.push('auction'); if (outcome === 'throw') throw Error('boom');
            queueMicrotask(() => o.bidsBackHandler({}, outcome === 'timeout')); };
        p.setTargetingForGPTAsync = () => { m.targeting++; m.order.push('targeting'); };
        p.getHighestCpmBids = () => outcome === 'bid' ? [{ cpm: 1 }] : [];
        queued.forEach(fn => fn());
    }
    vm.runInNewContext(source, sandbox, { filename: 'hm-loader.js' });
    return { sandbox, m, el };
}

test('browser Prebid auction targets GPT before GAM', async () => {
    const { sandbox, m } = harness(config());
    await sandbox.HorusMediaLoader.boot();
    assert.equal(m.prebid, 1); assert.equal(m.auctions, 1); assert.equal(m.targeting, 1); assert.equal(m.refresh, 1);
    assert.deepEqual(m.order, ['auction', 'targeting', 'gam']);
    assert.equal(m.unit.code, m.slots[0].id);
});

test('no bid falls back to GAM', async () => {
    const { sandbox, m } = harness(config(), 'no-bid');
    await sandbox.HorusMediaLoader.boot();
    assert.equal(m.auctions, 1); assert.equal(m.refresh, 1);
});

test('Prebid load or auction failure never breaks GAM', async () => {
    for (const outcome of ['load-fail', 'throw']) {
        const { sandbox, m } = harness(config(), outcome);
        await sandbox.HorusMediaLoader.boot();
        assert.equal(m.refresh, 1);
    }
});

test('timeout is browser-only and falls back to GAM', async () => {
    const { sandbox, m } = harness(config(), 'timeout');
    await sandbox.HorusMediaLoader.boot();
    assert.equal(m.timeouts, 1); assert.equal(m.refresh, 1); assert.equal(m.fetch.length, 1);
});

test('Prebid disabled uses GAM without loading a build', async () => {
    const c = config(); c.prebid.enabled = false; c.placements[0].prebid.enabled = false;
    const { sandbox, m } = harness(c);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(m.prebid, 0); assert.equal(m.refresh, 1);
});

test('selected GAM network controls slot path and Prebid mapping', async () => {
    const c = config({ servingMode: 'MCM_PARTNER_GAM', gamNetworkCode: '987654321' });
    c.prebid.gamConnectionId = 'partner'; c.placements[0].adUnitPath = '/987654321/top';
    const { sandbox, m } = harness(c);
    await sandbox.HorusMediaLoader.boot();
    assert.equal(m.slots[0].path, '/987654321/top'); assert.equal(m.prebid, 1);
});

test('paused, disabled, and unauthorized pages make no ad calls', async () => {
    const paused = harness(config({ status: 'paused', immediatePause: true }));
    await paused.sandbox.HorusMediaLoader.boot(); assert.equal(paused.m.gpt, 0);
    const disabledCfg = config(); disabledCfg.placements[0].enabled = false;
    const disabled = harness(disabledCfg); await disabled.sandbox.HorusMediaLoader.boot(); assert.equal(disabled.m.gpt, 0);
    const badHost = harness(config(), 'bid', 'attacker.example');
    await badHost.sandbox.HorusMediaLoader.boot(); assert.equal(badHost.m.gpt, 0);
});

test('force refresh cache-busts only static CDN config', async () => {
    const { sandbox, m } = harness(config());
    await sandbox.HorusMediaLoader.boot(); await sandbox.HorusMediaLoader.refresh();
    assert.equal(m.fetch.length, 2); assert.match(m.fetch[1], /production\.json\?v=\d+$/);
    assert.ok(m.fetch.every(u => u.startsWith('https://cdn.horusmedia.net/configs/')));
});
