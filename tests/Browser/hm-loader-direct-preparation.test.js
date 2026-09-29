import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';

const base = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const composed = applyPlacementPresetTransform(applyShadowClickGuardTransform(applyTrafficGateTransform(base)));
const production = applyDirectPreparationTransform(composed);
const sdk = { gpt: 'https://securepubads.g.doubleclick.net/tag/js/gpt.js', video: 'https://imasdk.googleapis.com/js/sdkloader/ima3.js' };
const asset = kind => `https://cdn.horusmedia.net/runtime/${kind}/hm-${kind}-direct.0123456789abcdef.js`;

function config(kind = 'gpt') {
    const attrs = { [`data-hm-${kind}-direct`]: '1' };
    return {
        siteKey: 'PREPARATION_ONLY', status: 'active', allowedHostnames: ['reader.example'],
        controls: {}, privacy: { requireConsentBeforeAds: true, mode: 'AUTO' },
        trafficGate: { enabled: false },
        placements: [{ code: 'test', enabled: true, status: 'active', renderer: 'DIRECT_JS' }],
        directDemand: { enabled: true, placements: { test: { enabled: true, candidates: [{
            tag: { executionMode: 'STRUCTURED', scripts: [{ url: asset(kind) }], container: { attributes: attrs } },
        }] } } },
    };
}

function harness(source = production) {
    const hints = [], scripts = [];
    const parent = { appendChild(node) { (node.tagName === 'LINK' ? hints : scripts).push(node); } };
    const document = {
        readyState: 'complete', visibilityState: 'visible', head: parent, documentElement: parent,
        querySelectorAll: () => [],
        createElement(tag) {
            return { tagName: tag.toUpperCase(), attributes: {}, setAttribute(k, v) { this.attributes[k] = String(v); } };
        },
    };
    const context = { URL, console, document, location: { hostname: 'reader.example', href: 'https://reader.example/article' }, __HM_DISABLE_AUTOBOOT__: true };
    context.window = context;
    // Test-only read access to the real composed preparation function. Never
    // execute a production SDK, network request, auction or impression pixel.
    const exposed = source.replace('    window.HorusMediaLoader = {', '    window.__prepare = prepareStaticConnections;\n    window.HorusMediaLoader = {');
    vm.runInNewContext(exposed, context);
    return { context, hints, scripts, preload: () => hints.filter(n => n.attributes.rel === 'preload').map(n => n.attributes.href) };
}

for (const kind of ['gpt', 'video']) {
    test(`${kind} Quick Monetize bytes are prepared before gate completion but not before required privacy resolution`, () => {
        const h = harness(), c = config(kind);
        h.context.__prepare(c, false);
        assert.deepEqual(h.preload(), []);
        h.context.__prepare(c, true);
        assert.deepEqual(h.preload(), [asset(kind), sdk[kind]]);
        assert.equal(h.scripts.length, 0, 'preparation must not execute an SDK');
        assert.equal(h.context.__HORUS_MEDIA_LOADER_STATE__.adInitializationStarted, false);
        h.context.__prepare(c, true);
        assert.equal(h.preload().length, 2, 'duplicate preparation must reuse hints');
    });
}

test('previous production composition misses Direct GPT despite the exact approved runtime recipe', () => {
    const h = harness(composed);
    h.context.__prepare(config(), true);
    assert.deepEqual(h.preload(), []);
});

for (const variant of ['host', 'paused', 'global-stop', 'direct-stop', 'legacy-stop', 'site-off', 'placement-off', 'gam-managed', 'invalid-gate']) {
    test(`preparation cannot relax ${variant}`, () => {
        const c = config(), h = harness();
        if (variant === 'host') c.allowedHostnames = ['other.example'];
        if (variant === 'paused') c.status = 'paused';
        if (variant === 'global-stop') c.controls.adServingDisabled = true;
        if (variant === 'direct-stop') c.controls.directJsDisabled = true;
        if (variant === 'legacy-stop') c.controls.nativeDemandDisabled = true;
        if (variant === 'site-off') c.directDemand.enabled = false;
        if (variant === 'placement-off') c.placements[0].enabled = false;
        if (variant === 'gam-managed') c.directDemand.placements.test.candidates[0].gamManaged = true;
        if (variant === 'invalid-gate') c.trafficGate = { enabled: true };
        h.context.__prepare(c, true);
        assert.deepEqual(h.preload(), []);
        assert.equal(h.scripts.length, 0);
    });
}

test('only fingerprinted, reviewed Horus runtime URLs can trigger preparation', () => {
    for (const url of [
        'https://evil.example/runtime/gpt/hm-gpt-direct.0123456789abcdef.js',
        'https://cdn.horusmedia.net.evil.example/runtime/gpt/hm-gpt-direct.0123456789abcdef.js',
        'https://name:password@cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.0123456789abcdef.js',
        asset('gpt') + '?redirect=evil', asset('gpt') + '#fragment',
        asset('gpt').replace('https:', 'http:'),
        asset('gpt').replace('/runtime/', '/anything/'),
        asset('gpt').replace('0123456789abcdef', 'unreviewed'),
    ]) {
        const c = config(), h = harness();
        c.directDemand.placements.test.candidates[0].tag.scripts[0].url = url;
        h.context.__prepare(c, true);
        assert.deepEqual(h.preload(), [], url);
    }
});

test('ready SDKs and many placements do not cause duplicate or unbounded preparation', () => {
    const c = config(), h = harness();
    h.context.googletag = { apiReady: true };
    for (let n = 1; n < 60; n++) {
        c.placements.push({ ...c.placements[0], code: `another-${n}` });
        c.directDemand.placements[`another-${n}`] = structuredClone(c.directDemand.placements.test);
        c.directDemand.placements[`another-${n}`].candidates[0].tag.scripts[0].url = asset('gpt').replace('0123456789abcdef', n.toString(16).padStart(16, '0'));
    }
    h.context.__prepare(c, true);
    assert.deepEqual(h.preload(), [asset('gpt')]);
});

test('GAM engine stop does not accidentally disable the independent approved Direct engine', () => {
    const c = config(), h = harness(); c.controls.gamDisabled = true;
    h.context.__prepare(c, true);
    assert.deepEqual(h.preload(), [asset('gpt'), sdk.gpt]);
});

test('transform is idempotent and rejects a missing admission boundary', () => {
    assert.equal(applyDirectPreparationTransform(production), production);
    assert.throws(() => applyDirectPreparationTransform(base), /reviewed Traffic Gate/);
});
