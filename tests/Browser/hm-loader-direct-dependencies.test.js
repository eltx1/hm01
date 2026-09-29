import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';

const base = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const source = [applyTrafficGateTransform, applyShadowClickGuardTransform, applyPlacementPresetTransform,
    applyDirectPreparationTransform].reduce((s, transform) => transform(s), base);
const sdk = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
const runtime = 'https://cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.0123456789abcdef.js';

function harness() {
    const scripts = [];
    const parent = { appendChild(node) { scripts.push(node); } };
    const document = {
        readyState: 'complete', visibilityState: 'visible', head: parent, documentElement: parent,
        querySelectorAll: selector => selector === 'script[src]' ? scripts : [],
        querySelector: selector => scripts.find(n => selector === 'script[data-hm-gpt="1"]' && n.attributes?.['data-hm-gpt'] === '1') || null,
        createElement(tag) { return { tagName: tag.toUpperCase(), attributes: {},
            setAttribute(k, v) { this.attributes[k] = String(v); }, getAttribute(k) { return this.attributes[k] || null; } }; },
    };
    const context = { URL, console, document, location: { hostname: 'reader.example', href: 'https://reader.example/article' }, __HM_DISABLE_AUTOBOOT__: true };
    context.window = context;
    // White-box access is confined to the VM fixture; no test API is shipped.
    const exposed = source.replace('    window.HorusMediaLoader = {',
        '    window.__startDependency = typeof startTrustedDirectDependencies === "function" ? startTrustedDirectDependencies : function () {};\n    window.__loadGpt = loadGpt;\n    window.HorusMediaLoader = {');
    vm.runInNewContext(exposed, context);
    const config = { siteKey: 'DEPENDENCIES', status: 'active', allowedHostnames: ['reader.example'], controls: {} };
    const candidate = { tag: { executionMode: 'STRUCTURED', scripts: [{ url: runtime }],
        container: { attributes: { 'data-hm-gpt-direct': '1' } } } };
    const state = context.__HORUS_MEDIA_LOADER_STATE__;
    state.config = config;
    state.privacyDecision = { blocked: false };
    state.trafficGate = { status: 'PASSED' };
    return { context, config, candidate, state, scripts, start: () => context.__startDependency(config, candidate) };
}

test('trusted Direct starts the GPT SDK before its adapter and shares the pending promise with GAM', async () => {
    const h = harness(); h.start();
    assert.equal(h.scripts.length, 1);
    assert.equal(h.scripts[0].src, sdk);
    assert.equal(h.scripts[0].attributes.crossorigin, 'anonymous');
    assert.equal(h.scripts[0].attributes['data-hm-gpt'], '1');
    assert.equal(h.scripts[0].attributes['data-hm-gpt-library'], '1');
    for (let n = 0; n < 30; n++) h.start();
    assert.equal(h.scripts.length, 1);
    const pending = h.state.gptPromise;
    assert.equal(h.context.__loadGpt(h.config), pending);
    h.scripts[0].onload();
    assert.equal(await pending, h.context.googletag);
    assert.equal(h.scripts.length, 1);
});

for (const variant of ['PENDING', 'ERROR', 'TIMEOUT', 'UNAVAILABLE', 'BLOCKED', 'privacy-pending', 'privacy-denied', 'site-off', 'host', 'serving-off', 'direct-off', 'handoff-failed']) {
    test(`dependency execution respects admission: ${variant}`, () => {
        const h = harness();
        if (/^[A-Z]+$/.test(variant)) h.state.trafficGate.status = variant;
        if (variant === 'privacy-pending') h.state.privacyDecision = null;
        if (variant === 'privacy-denied') h.state.privacyDecision.blocked = true;
        if (variant === 'site-off') h.config.status = 'paused';
        if (variant === 'host') h.config.allowedHostnames = ['other.example'];
        if (variant === 'serving-off') h.config.controls.adServingDisabled = true;
        if (variant === 'direct-off') h.config.controls.directJsDisabled = true;
        if (variant === 'handoff-failed') h.context.__HM_RELEASE_HANDOFF_FAILED__ = true;
        h.start(); assert.equal(h.scripts.length, 0);
    });
}

for (const variant of ['third-party', 'url-query', 'url-userinfo', 'custom-gpt', 'limited-gpt', 'multiple-scripts', 'ordered', 'deferred', 'custom-init', 'isolated', 'gam-managed', 'video']) {
    test(`dependency overlap preserves unapproved or ordered integration: ${variant}`, () => {
        const h = harness(), entry = h.candidate;
        if (variant === 'third-party') entry.tag.scripts[0].url = runtime.replace('cdn.horusmedia.net', 'third-party.example');
        if (variant === 'url-query') entry.tag.scripts[0].url += '?v=untrusted';
        if (variant === 'url-userinfo') entry.tag.scripts[0].url = runtime.replace('https://', 'https://name:password@');
        if (variant === 'custom-gpt') h.config.gpt = { url: 'https://example.net/gpt.js' };
        if (variant === 'limited-gpt') h.config.gpt = { url: 'https://pagead2.googlesyndication.com/tag/js/gpt.js' };
        if (variant === 'multiple-scripts') entry.tag.scripts.push({ url: 'https://example.net/setup.js' });
        if (variant === 'ordered') entry.tag.scripts[0].async = false;
        if (variant === 'deferred') entry.tag.scripts[0].defer = true;
        if (variant === 'custom-init') entry.tag.initialization = { type: 'MGID_QUEUE_LOAD' };
        if (variant === 'isolated') entry.tag.executionMode = 'ISOLATED_IFRAME';
        if (variant === 'gam-managed') entry.gamManaged = true;
        if (variant === 'video') entry.tag.container.attributes = { 'data-hm-video-direct': '1' };
        h.start();
        assert.equal(h.scripts.length, 0);
        assert.equal(h.state.gptPromise, null);
    });
}

for (const existing of [sdk, sdk + '?v=publisher', 'https://pagead2.googlesyndication.com/tag/js/gpt.js']) {
    test(`an existing publisher library retains ownership: ${existing}`, () => {
        const h = harness(); h.scripts.push({ src: existing }); h.start();
        assert.equal(h.scripts.length, 1);
    });
}

test('the independent Direct dependency does not require the GAM engine', () => {
    const h = harness(); h.config.controls.gamDisabled = true; h.start();
    assert.equal(h.scripts.length, 1);
});

test('a blocked dependency is handled without retries or cross-placement rejection', async () => {
    const h = harness(); h.start(); h.scripts[0].onerror();
    await assert.rejects(h.state.gptPromise, /failed to load/);
    h.start(); assert.equal(h.scripts.length, 1);
});
