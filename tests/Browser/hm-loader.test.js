import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const loaderSource = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');

function activeConfig(overrides = {}) {
    return {
        siteKey: 'HM_TEST',
        servingMode: 'HORUS_GAM',
        gamNetworkCode: '123456789',
        configVersion: 5,
        status: 'active',
        immediatePause: false,
        debug: false,
        allowedHostnames: ['publisher.example'],
        loader: { version: '1.0.0', cacheBust: 5 },
        privacyDiagnostics: {
            endpoint: 'https://app.horusmedia.net/privacy-diagnostics/report',
            controlPlaneOrigin: 'https://app.horusmedia.net',
            explicitOnly: true,
        },
        gpt: { url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', tagVersion: '1.0.0', singleRequest: true },
        pageTargeting: { site: ['test'] },
        placements: [{
            code: 'article_top',
            name: 'Article Top',
            type: 'DISPLAY',
            status: 'active',
            enabled: true,
            adUnitPath: '/123456789/article_top',
            sizes: [[300, 250], [728, 90]],
            responsiveMappings: [{ viewport: [768, 0], device: 'DESKTOP', sizes: [[728, 90]] }],
            targeting: { position: ['article_top'] },
            lazyLoad: { enabled: true, fetchMarginPercent: 500, renderMarginPercent: 200, mobileScaling: 2 },
            refresh: { enabled: false, intervalSeconds: null, limit: null },
            collapseEmptyDiv: true,
            safeFrame: false,
            outOfPageFormat: null,
        }],
        ...overrides,
    };
}

function element(code) {
    const attributes = { 'data-placement': code };
    return {
        id: '',
        attributes,
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
    };
}

function createHarness(config, {
    hostname = 'publisher.example',
    placementCodes = ['article_top'],
    manifestMode = 'valid',
    manifestVersion = config.configVersion,
    immutableConfig = config,
    aliasConfig = config,
    unifiedConfig = false,
    deferredTcf = false,
    gppResponse = null,
    gpc = false,
    diagnosticToken = null,
} = {}) {
    const metrics = {
        fetches: [], gptLoads: 0, defined: [], displayed: [], services: 0,
        pageTargeting: {}, slotTargeting: {}, lazy: null, singleRequest: 0,
        pageConfigs: [], privacySettings: [], tcfCallback: null, diagnosticPosts: [], cleanedUrls: [],
    };
    const elements = placementCodes.map(element);
    const scriptAttributes = {
        'data-site-key': config.siteKey,
        'data-config-base': 'https://cdn.horusmedia.net/configs',
        'data-environment': 'production',
    };
    const script = {
        src: 'https://cdn.horusmedia.net/hm-loader.js',
        dataset: { siteKey: config.siteKey, configBase: 'https://cdn.horusmedia.net/configs', environment: 'production' },
        getAttribute(name) { return scriptAttributes[name] ?? null; },
        setAttribute(name, value) { scriptAttributes[name] = String(value); },
        hasAttribute(name) { return Object.hasOwn(scriptAttributes, name); },
    };

    const pubads = {
        setTargeting(key, values) { metrics.pageTargeting[key] = values; return this; },
        enableLazyLoad(value) { metrics.lazy = value; },
        enableSingleRequest() { metrics.singleRequest += 1; },
        setPrivacySettings(value) { metrics.privacySettings.push(value); return this; },
        refresh() {},
    };
    const immediateQueue = { push(callback) { callback(); return 1; } };
    const googletag = {
        cmd: immediateQueue,
        apiReady: false,
        pubadsReady: false,
        pubads() { return pubads; },
        sizeMapping() {
            const mappings = [];
            return { addSize(viewport, sizes) { mappings.push({ viewport, sizes }); return this; }, build() { return mappings; } };
        },
        defineSlot(path, sizes, id) {
            metrics.defined.push({ path, sizes, id });
            const slot = {
                setTargeting(key, values) { metrics.slotTargeting[key] = values; return slot; },
                defineSizeMapping(value) { slot.mapping = value; return slot; },
                setForceSafeFrame(value) { slot.safeFrame = value; return slot; },
                setCollapseEmptyDiv(value) { slot.collapse = value; return slot; },
                addService() { return slot; },
            };
            return slot;
        },
        defineOutOfPageSlot() { return null; },
        enableServices() { metrics.services += 1; googletag.apiReady = true; },
        display(value) { metrics.displayed.push(typeof value === 'string' ? value : 'out-of-page'); },
        enums: { OutOfPageFormat: { INTERSTITIAL: 'INTERSTITIAL', REWARDED: 'REWARDED' } },
    };
    if (unifiedConfig) googletag.setConfig = (value) => { metrics.pageConfigs.push(value); };

    const document = {
        currentScript: script,
        readyState: 'complete',
        visibilityState: 'visible',
        documentElement: {},
        head: {
            appendChild(node) {
                if (node.getAttribute && node.getAttribute('data-hm-gpt') === '1') {
                    metrics.gptLoads += 1;
                    queueMicrotask(() => node.onload?.());
                }
            },
        },
        createElement() {
            const attributes = {};
            return {
                attributes, async: false, src: '', onload: null, onerror: null,
                setAttribute(name, value) { attributes[name] = String(value); },
                getAttribute(name) { return attributes[name] ?? null; },
                addEventListener(name, callback) { if (name === 'load') this.onload = callback; if (name === 'error') this.onerror = callback; },
            };
        },
        querySelector(selector) { return selector === 'script[data-hm-gpt="1"]' ? null : null; },
        querySelectorAll(selector) {
            if (selector === '.hm-ad[data-placement]') return elements;
            if (selector === 'script[data-site-key]') return [script];
            return [];
        },
        addEventListener() {},
    };

    class MutationObserver { observe() {} disconnect() {} }
    class Event { constructor(type) { this.type = type; } }
    const listeners = {};
    const history = { state: null, pushState() {}, replaceState(state, title, url) { metrics.cleanedUrls.push(String(url)); } };
    const sandbox = {
        console,
        URL,
        Promise,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        queueMicrotask,
        MutationObserver,
        Event,
        googletag,
        document,
        location: { hostname, href: `https://${hostname}/article${diagnosticToken ? `?hm_privacy_diagnostic=${diagnosticToken}` : ''}` },
        navigator: { globalPrivacyControl: gpc },
        history,
        fetch: async (url, options = {}) => {
            metrics.fetches.push(String(url));
            if (String(url) === 'https://app.horusmedia.net/privacy-diagnostics/report') {
                metrics.diagnosticPosts.push({ url: String(url), options, payload: JSON.parse(options.body) });
                return { ok: true, status: 202, json: async () => ({ accepted: true }) };
            }
            if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: {} }) };
            if (String(url).includes('/manifest.json')) {
                if (manifestMode === 'missing') return { ok: false, status: 404, json: async () => ({}) };
                if (manifestMode === 'invalid') return { ok: true, json: async () => ({ siteKey: config.siteKey, environments: {} }) };
                return { ok: true, json: async () => ({
                    siteKey: config.siteKey,
                    environments: { production: {
                        version: manifestVersion,
                        path: `/configs/${config.siteKey}/production.v${manifestVersion}.${'a'.repeat(16)}.json`,
                        sha256: 'a'.repeat(64),
                    } },
                }) };
            }
            if (/production\.v\d+\.[a-f0-9]+\.json/.test(String(url))) {
                return immutableConfig ? { ok: true, json: async () => structuredClone(immutableConfig) } : { ok: false, status: 404, json: async () => ({}) };
            }
            return aliasConfig ? { ok: true, json: async () => structuredClone(aliasConfig) } : { ok: false, status: 404, json: async () => ({}) };
        },
        addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
        dispatchEvent(event) { (listeners[event.type] || []).forEach((callback) => callback(event)); },
        __HM_DISABLE_AUTOBOOT__: true,
    };
    if (deferredTcf) {
        sandbox.__tcfapi = (command, version, callback) => {
            assert.equal(command, 'addEventListener');
            assert.equal(version, 2);
            metrics.tcfCallback = callback;
        };
    }
    if (gppResponse) {
        sandbox.__gpp = (command, callback) => {
            assert.equal(command, 'ping');
            callback(structuredClone(gppResponse), true);
        };
    }
    sandbox.window = sandbox;
    vm.runInNewContext(loaderSource, sandbox, { filename: 'hm-loader.js' });

    return { sandbox, metrics, elements };
}

test('loads GPT once, defines the slot, applies mappings and targeting', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.scan();

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.defined.length, 1);
    assert.equal(metrics.defined[0].path, '/123456789/article_top');
    assert.deepEqual(metrics.pageTargeting.site, ['test']);
    assert.deepEqual(metrics.slotTargeting.position, ['article_top']);
    assert.equal(metrics.singleRequest, 1);
    assert.equal(metrics.services, 1);
    assert.equal(metrics.displayed.length, 1);
    assert.equal(metrics.fetches.length, 3);
    assert.match(metrics.fetches[1], /\/manifest\.json$/);
    assert.match(metrics.fetches[2], /production\.v5\.[a-f0-9]+\.json$/);
});

test('privacy gate waits for TCF and applies unified GPT privacy configuration', async () => {
    const selected = activeConfig({
        privacy: {
            mode: 'STRICT', requireConsentBeforeAds: true,
            cmp: { timeoutMs: 200, actionOnTimeout: 'LIMITED_ADS' },
            signals: { coppa: true, underAgeOfConsent: true },
        },
        gpt: {
            url: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', singleRequest: true,
            config: { threadYield: 'ENABLED_ALL_SLOTS', autoRefresh: { heavyAds: true } },
        },
    });
    const { sandbox, metrics } = createHarness(selected, { unifiedConfig: true, deferredTcf: true });
    const boot = sandbox.HorusMediaLoader.boot();
    for (let attempt = 0; attempt < 10 && !metrics.tcfCallback; attempt += 1) {
        await new Promise((resolve) => setImmediate(resolve));
    }

    assert.equal(metrics.gptLoads, 0);
    assert.equal(typeof metrics.tcfCallback, 'function');
    metrics.tcfCallback({ eventStatus: 'tcloaded', gdprApplies: true, purpose: { consents: { 1: false } } }, true);
    await boot;

    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.pageConfigs.length, 1);
    assert.equal(metrics.pageConfigs[0].disableInitialLoad, true);
    assert.equal(metrics.pageConfigs[0].singleRequest, true);
    assert.equal(metrics.pageConfigs[0].privacyTreatments.treatments[0], 'disablePersonalization');
    assert.equal(metrics.singleRequest, 0);
    assert.equal(metrics.privacySettings[0].childDirectedTreatment, true);
    assert.equal(metrics.privacySettings[0].underAgeOfConsent, true);
    assert.equal(metrics.pageTargeting.hm_limited_ads[0], '1');
});

test('strict privacy mode can block every advertising call after its bounded CMP timeout', async () => {
    const selected = activeConfig({
        privacy: {
            mode: 'STRICT', requireConsentBeforeAds: true,
            cmp: { timeoutMs: 100, actionOnTimeout: 'BLOCK_ADS' },
        },
    });
    const { sandbox, metrics } = createHarness(selected);

    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
    assert.equal(metrics.displayed.length, 0);
});

test('paused sites never load GPT or define an advertising slot', async () => {
    const { sandbox, metrics } = createHarness(activeConfig({ status: 'paused', immediatePause: true }));
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
    assert.equal(metrics.displayed.length, 0);
});

test('disabled placements generate no Google advertising calls', async () => {
    const config = activeConfig();
    config.placements[0].enabled = false;
    config.placements[0].status = 'disabled';
    const { sandbox, metrics } = createHarness(config);
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
});

test('unauthorized hostnames cannot load valid placements', async () => {
    const { sandbox, metrics } = createHarness(activeConfig(), { hostname: 'attacker.example' });
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.fetches.length, 3);
    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
});

test('force refresh cache-busts the static configuration without a Laravel request', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.refresh();

    assert.equal(metrics.fetches.length, 6);
    assert.match(metrics.fetches[1], /\/configs\/HM_TEST\/manifest\.json$/);
    assert.match(metrics.fetches[4], /manifest\.json\?v=\d+$/);
    assert.ok(metrics.fetches.every((url) => url.startsWith('https://cdn.horusmedia.net/configs/')));
    assert.equal(metrics.gptLoads, 1);
});

test('global ad-serving kill switch prevents GPT and slot activation', async () => {
    const { sandbox, metrics } = createHarness(activeConfig({ controls: { adServingDisabled: true } }));
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
    assert.equal(metrics.displayed.length, 0);
});

test('global CDN control stops before fetching site configuration', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    const originalFetch = sandbox.fetch;
    sandbox.fetch = async (url) => {
        metrics.fetches.push(String(url));
        if (String(url).includes('/_global/control.json')) return { ok: true, json: async () => ({ controls: { adServingDisabled: true } }) };
        return originalFetch(url);
    };
    metrics.fetches.length = 0;
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.fetches.length, 1);
    assert.equal(metrics.gptLoads, 0);
});

test('falls back to the compatibility production alias when manifest propagation is incomplete', async () => {
    const { sandbox, metrics } = createHarness(activeConfig(), { manifestMode: 'missing' });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.fetches.length, 3);
    assert.match(metrics.fetches[2], /\/production\.json$/);
});

test('stale manifest version mismatch safely revalidates through the current alias', async () => {
    const { sandbox, metrics } = createHarness(activeConfig(), { manifestVersion: 4, immutableConfig: activeConfig({ configVersion: 5 }) });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.gptLoads, 1);
    assert.equal(metrics.fetches.length, 4);
    assert.match(metrics.fetches[3], /\/production\.json$/);
});

test('missing or corrupted configuration stops safely without ad calls', async () => {
    const corrupted = activeConfig({ siteKey: 'WRONG_SITE' });
    const { sandbox, metrics } = createHarness(activeConfig(), { manifestMode: 'missing', aliasConfig: corrupted });
    await sandbox.HorusMediaLoader.boot();
    assert.equal(metrics.gptLoads, 0);
    assert.equal(metrics.defined.length, 0);
});

test('all Horus runtime requests stay on the loader static origin with no telemetry or app request', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();
    await sandbox.HorusMediaLoader.scan();
    assert.ok(metrics.fetches.every((url) => url.startsWith('https://cdn.horusmedia.net/configs/')));
    assert.ok(metrics.fetches.every((url) => !url.includes('app.horusmedia.net')));
    assert.ok(metrics.fetches.every((url) => !/telemetry|impression|event/i.test(url)));
});

test('normal Loader boot sends no privacy diagnostic request', async () => {
    const { sandbox, metrics } = createHarness(activeConfig());
    await sandbox.HorusMediaLoader.boot();

    assert.equal(metrics.diagnosticPosts.length, 0);
    assert.ok(metrics.fetches.every((url) => !url.includes('/privacy-diagnostics/report')));
});

test('explicit one-shot diagnostic sends only sanitized privacy evidence with credentials omitted', async () => {
    const token = 'd'.repeat(64);
    const selected = activeConfig({
        privacy: {
            mode: 'STRICT', requireConsentBeforeAds: true,
            cmp: { timeoutMs: 200, actionOnTimeout: 'LIMITED_ADS', tcfRequired: true, gppRequired: true },
            signals: { gpc: true },
        },
        prebid: {
            enabled: false,
            build: { modules: ['consentManagementTcf', 'consentManagementGpp', 'storageControl'] },
            auction: {
                consent: { gdpr: { cmpApi: 'iab' }, gpp: { cmpApi: 'iab' } },
                storageControl: { enforcement: 'strict' },
                allowActivities: { accessDevice: { default: false } },
            },
        },
    });
    const { sandbox, metrics } = createHarness(selected, {
        deferredTcf: true,
        gppResponse: { applicableSections: [7, 8], gppString: 'FULL_GPP_STRING_MUST_NOT_LEAVE_BROWSER' },
        gpc: true,
        diagnosticToken: token,
    });
    const boot = sandbox.HorusMediaLoader.boot();
    for (let attempt = 0; attempt < 10 && !metrics.tcfCallback; attempt += 1) await new Promise((resolve) => setImmediate(resolve));
    metrics.tcfCallback({
        eventStatus: 'tcloaded', cmpId: 42, gdprApplies: true,
        tcString: 'FULL_TC_STRING_MUST_NOT_LEAVE_BROWSER',
        purpose: { consents: { 1: true } },
    }, true);
    await boot;

    assert.equal(metrics.diagnosticPosts.length, 1);
    const report = metrics.diagnosticPosts[0];
    assert.equal(report.options.credentials, 'omit');
    assert.equal(report.options.headers['X-Horus-Diagnostic-Token'], token);
    assert.equal(report.payload.tcf.detected, true);
    assert.equal(report.payload.tcf.responded, true);
    assert.equal(report.payload.tcf.cmpId, 42);
    assert.deepEqual(report.payload.gpp.applicableSections, [7, 8]);
    assert.equal(report.payload.gpcDetected, true);
    assert.equal(report.payload.privacyGateRespected, true);
    assert.ok(metrics.cleanedUrls[0] && !metrics.cleanedUrls[0].includes(token));
    const encoded = JSON.stringify(report.payload);
    assert.ok(!encoded.includes('FULL_TC_STRING'));
    assert.ok(!encoded.includes('FULL_GPP_STRING'));
    assert.ok(!/cookie|userId|fingerprint|bidResponse|click/i.test(encoded));
});
