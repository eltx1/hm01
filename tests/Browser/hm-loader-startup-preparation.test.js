import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFile } from 'node:fs/promises';
import { applyTrafficGateTransform } from '../../scripts/transform-loader-traffic-gate.mjs';
import { applyShadowClickGuardTransform } from '../../scripts/transform-loader-shadow-click-guard.mjs';
import { applyPlacementPresetTransform } from '../../scripts/transform-loader-placement-presets.mjs';
import { applyDirectPreparationTransform } from '../../scripts/transform-loader-direct-preparation.mjs';
import { applyVideoPreparationTransform } from '../../scripts/transform-loader-video-preparation.mjs';

const base = await readFile(new URL('../../public/assets/hm-loader.js', import.meta.url), 'utf8');
const old = [applyTrafficGateTransform, applyShadowClickGuardTransform, applyPlacementPresetTransform].reduce((s, f) => f(s), base);
const source = applyVideoPreparationTransform(applyDirectPreparationTransform(old));
const asset = kind => `https://cdn.horusmedia.net/runtime/${kind}/hm-${kind}-direct.0123456789abcdef.js`;
function config(kind = 'video') {
    return {
        siteKey: 'STARTUP', status: 'active', allowedHostnames: ['reader.example'], controls: {},
        privacy: { requireConsentBeforeAds: true, mode: 'AUTO' }, trafficGate: { enabled: false },
        placements: [{ code: 'video', type: kind === 'video' ? 'VIDEO' : 'DISPLAY', enabled: true, status: 'active', renderer: 'DIRECT_JS',
            format: { settings: { position: 'inline_to_bottom_right', reserveSpace: true } } }],
        directDemand: { enabled: true, placements: { video: { enabled: true, candidates: [{ tag: {
            scripts: [{ url: asset(kind) }], container: { attributes: { [`data-hm-${kind}-direct`]: '1' } },
        } }] } } },
    };
}
function harness(initialTop = 80, initialHeight = 180) {
    const hints = [], scripts = [], listeners = new Map(), frames = [], timers = [];
    const style = () => {
        const values = {}, priorities = {};
        return { getPropertyValue: k => values[k] || '', getPropertyPriority: k => priorities[k] || '',
            setProperty(k, v, p = '') { values[k] = String(v); priorities[k] = p; }, removeProperty(k) { delete values[k]; } };
    };
    const node = { nodeType: 1, isConnected: true, top: initialTop, height: initialHeight, css: {}, style: style(),
        attributes: { 'data-placement': 'video' }, querySelector: () => null,
        getAttribute(k) { return this.attributes[k] || null; }, setAttribute(k, v) { this.attributes[k] = String(v); },
        getBoundingClientRect() { const h = this.height || (this.style.getPropertyValue('aspect-ratio') ? 180 : 0);
            return { top: this.top, bottom: this.top + h, left: 0, right: 320, width: 320, height: h }; },
    };
    const nodes = [node], parent = { appendChild(n) { (n.tagName === 'LINK' ? hints : scripts).push(n); } };
    const document = { readyState: 'complete', visibilityState: 'visible', head: parent, documentElement: parent,
        querySelectorAll: () => nodes, addEventListener() {}, removeEventListener() {},
        createElement(tag) { return { tagName: tag.toUpperCase(), attributes: {}, setAttribute(k, v) { this.attributes[k] = String(v); } }; },
    };
    const context = { URL, console, document, location: { hostname: 'reader.example', href: 'https://reader.example/article' },
        __HM_DISABLE_AUTOBOOT__: true, innerWidth: 390, innerHeight: 800, scrollY: 0,
        getComputedStyle: n => ({ display: 'block', visibility: 'visible', opacity: '1', overflowX: 'visible', overflowY: 'visible', ...n.css }),
        setTimeout(fn) { timers.push(fn); return timers.length; }, clearTimeout() {},
        requestAnimationFrame(fn) { frames.push(fn); return frames.length; }, cancelAnimationFrame() {},
        addEventListener(k, fn) { const values = listeners.get(k) || new Set(); values.add(fn); listeners.set(k, values); },
        removeEventListener(k, fn) { listeners.get(k)?.delete(fn); },
    };
    context.window = context;
    const exposed = source.replace('    window.HorusMediaLoader = {', '    window.prep = prepareStaticConnections; window.observeSlots = prepareInlineVideoHistory; window.stopPrep = stopInlineVideoPreparation;\n    window.HorusMediaLoader = {');
    vm.runInNewContext(exposed, context);
    return { context, document, node, nodes, hints, scripts, timers, listeners,
        preload: () => hints.filter(n => n.attributes.rel === 'preload').map(n => n.attributes.href),
        scroll(y) { context.scrollY = y; nodes.forEach(n => { n.top -= y; }); (listeners.get('scroll') || []).forEach(fn => fn({ type: 'scroll' })); this.flush(); },
        flush() { frames.splice(0).forEach(fn => fn()); },
    };
}
for (const kind of ['gpt', 'video']) {
    test(`approved ${kind} bytes overlap verification but never execute before privacy/admission`, () => {
        const h = harness(), c = config(kind);
        h.context.prep(c, false); assert.deepEqual(h.preload(), []);
        h.context.prep(c, true);
        assert.deepEqual(h.preload(), [asset(kind), kind === 'video' ? 'https://imasdk.googleapis.com/js/sdkloader/ima3.js' : 'https://securepubads.g.doubleclick.net/tag/js/gpt.js']);
        h.context.prep(c, true); assert.equal(h.preload().length, 2); assert.equal(h.scripts.length, 0);
        assert.equal(h.context.__HORUS_MEDIA_LOADER_STATE__.adInitializationStarted, false);
    });
}
for (const variant of ['host', 'paused', 'global-stop', 'direct-stop', 'legacy-stop', 'site-off', 'placement-off', 'gam-managed', 'invalid-gate']) {
    test(`preparation preserves ${variant} boundary`, () => {
        const c = config(), h = harness();
        if (variant === 'host') c.allowedHostnames = ['other.example'];
        if (variant === 'paused') c.status = 'paused';
        if (variant === 'global-stop') c.controls.adServingDisabled = true;
        if (variant === 'direct-stop') c.controls.directJsDisabled = true;
        if (variant === 'legacy-stop') c.controls.nativeDemandDisabled = true;
        if (variant === 'site-off') c.directDemand.enabled = false;
        if (variant === 'placement-off') c.placements[0].enabled = false;
        if (variant === 'gam-managed') c.directDemand.placements.video.candidates[0].gamManaged = true;
        if (variant === 'invalid-gate') c.trafficGate = { enabled: true };
        h.context.prep(c, true); assert.deepEqual(h.preload(), []); assert.equal(h.scripts.length, 0);
    });
}
test('unapproved source, credentials, fragments and lookalike hosts never produce preloads', () => {
    for (const url of [asset('video').replace('cdn.horusmedia.net', 'evil.example'), asset('video') + '?x=1', asset('video') + '#x',
        asset('video').replace('https:', 'http:'), asset('video').replace('cdn.', 'user:pass@cdn.'), asset('video').replace('/runtime/', '/api/'),
        asset('video').replace('0123456789abcdef', 'unknown'), asset('video').replace('horusmedia.net', 'horusmedia.net.evil.example')]) {
        const h = harness(), c = config(); c.directDemand.placements.video.candidates[0].tag.scripts[0].url = url;
        h.context.prep(c, true); assert.deepEqual(h.preload(), [], url);
    }
});
test('GAM engine stop is independent from valid Direct SDK preparation', () => {
    const c = config('gpt'), h = harness(); c.controls.gamDisabled = true;
    h.context.prep(c, true); assert.equal(h.preload().length, 2);
});
test('runtime preparation is bounded across many placements and avoids ready SDKs', () => {
    const c = config('gpt'), h = harness(); h.context.googletag = { apiReady: true };
    for (let i = 0; i < 100; i++) { c.placements.push({ ...c.placements[0], code: `extra${i}` }); c.directDemand.placements[`extra${i}`] = c.directDemand.placements.video; }
    h.context.prep(c, true); assert.deepEqual(h.preload(), [asset('gpt')]);
});
test('a visible slot keeps its scroll history across delayed configuration then runtime handoff', () => {
    const h = harness(); h.context.observeSlots(null);
    h.scroll(1800); h.context.prep(config(), false);
    assert.deepEqual(JSON.parse(JSON.stringify(h.node.__hmInlineVideoHistory.take())), { wasInlineVisible: true, scrolled: true });
    assert.equal(h.node.__hmInlineVideoHistory, undefined); assert.equal(h.scripts.length, 0);
});
test('empty video DIV records an approved anchor without reserving a blank video rectangle', () => {
    const h = harness(80, 0), c = config(); h.context.observeSlots(null);
    assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '');
    assert.equal(h.node.__hmInlineVideoHistory.wasInlineVisible, false);
    h.context.prep(c, false); assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '');
    h.scroll(1800); const history = h.node.__hmInlineVideoHistory.take();
    assert.equal(history.wasInlineVisible, false); assert.equal(history.anchorWasVisible, true); assert.equal(history.scrolled, true);
    assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '');
});
for (const scenario of ['below-fold', 'hidden', 'background', 'clipped', 'zero-area']) {
    test(`never invent inline visibility for ${scenario}`, () => {
        const h = harness(scenario === 'below-fold' ? 1400 : 80, scenario === 'zero-area' ? 0 : 180), c = config();
        if (scenario === 'hidden') h.node.css.display = 'none';
        if (scenario === 'background') h.document.visibilityState = 'hidden';
        if (scenario === 'zero-area') c.placements[0].format.settings.reserveSpace = false;
        if (scenario === 'clipped') h.node.parentElement = { nodeType: 1, css: { overflowY: 'hidden' }, getBoundingClientRect: () => ({ top: 700, bottom: 720 }) };
        h.context.prep(c, false); h.scroll(2500);
        assert.equal(h.node.__hmInlineVideoHistory.take().wasInlineVisible, false);
    });
}
test('unknown zero-area slot skipped during config loading does not become falsely seen', () => {
    const h = harness(80, 0); h.context.observeSlots(null); h.scroll(1800); h.context.prep(config(), false);
    assert.equal(h.node.__hmInlineVideoHistory.take().wasInlineVisible, false);
});
for (const change of ['disconnected', 'dismissed', 'renamed', 'config-replaced', 'stopped']) {
    test(`stale or dismissed slot history is invalidated: ${change}`, () => {
        const h = harness(), c = config(); h.context.prep(c, false); const oldHistory = h.node.__hmInlineVideoHistory;
        if (change === 'disconnected') h.node.isConnected = false;
        if (change === 'dismissed') h.node.setAttribute('data-hm-placement-dismissed', '1');
        if (change === 'renamed') h.node.setAttribute('data-placement', 'different');
        if (change === 'config-replaced') h.context.prep({ ...structuredClone(c), configVersion: 2 }, false);
        if (change === 'stopped') h.context.stopPrep();
        assert.equal(oldHistory.take(), null);
    });
}
test('expired observer cleanup cannot tear down a newer configuration observer', () => {
    const h = harness(), c = config(); h.context.prep(c, false); const expire = h.timers[0];
    h.context.prep({ ...structuredClone(c), configVersion: 2 }, false); const newHistory = h.node.__hmInlineVideoHistory;
    expire(); assert.equal(h.node.__hmInlineVideoHistory, newHistory);
});
test('the two production transforms are idempotent and require real boundaries', () => {
    assert.equal(applyVideoPreparationTransform(source), source); assert.equal(applyDirectPreparationTransform(source), source);
    assert.throws(() => applyDirectPreparationTransform(base)); assert.throws(() => applyVideoPreparationTransform(base));
});


test('the empty Quick Monetize anchor stays collapsed before config and hands off only after approval', () => {
    const h = harness(80, 0), c = config();
    h.node.setAttribute('data-placement', 'quick_video_floating');
    c.placements[0].code = 'quick_video_floating';
    h.context.observeSlots(null);
    assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '');
    assert.equal(h.node.__hmInlineVideoHistory.wasInlineVisible, false);
    assert.equal(h.node.__hmInlineVideoHistory.anchorWasVisible, true);
    h.scroll(1800); h.context.prep(c, false);
    const history = h.node.__hmInlineVideoHistory.take();
    assert.equal(history.wasInlineVisible, false); assert.equal(history.anchorWasVisible, true); assert.equal(history.scrolled, true);
    assert.equal(h.scripts.length, 0);
});

test('a reserved Quick embed is cleaned up when config denies the site', () => {
    const h = harness(80, 0), c = config(); h.node.setAttribute('data-placement', 'quick_video_floating');
    h.context.observeSlots(null); c.status = 'paused'; h.context.prep(c, false);
    assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '');
    assert.equal(h.node.__hmInlineVideoHistory, undefined);
});

test('handoff never removes a stronger style installed by the live player', () => {
    const h = harness(80, 0), c = config(); h.context.prep(c, false);
    h.node.style.setProperty('aspect-ratio', '16 / 9', 'important');
    h.node.__hmInlineVideoHistory.take();
    assert.equal(h.node.style.getPropertyPriority('aspect-ratio'), 'important');
    assert.equal(h.node.style.getPropertyValue('aspect-ratio'), '16 / 9');
});

// Config GETs parse fresh objects even when the server snapshot is unchanged.
// Geometry history must survive this, without carrying any PASS or ad permission.
test('identical config refetch preserves observed inline history after a scroll', () => {
    const h = harness(), c = config();
    h.context.prep(c, false); const history = h.node.__hmInlineVideoHistory;
    h.scroll(1800);
    h.context.prep(structuredClone(c), false);
    assert.equal(h.node.__hmInlineVideoHistory, history);
    assert.deepEqual(JSON.parse(JSON.stringify(history.take())), { wasInlineVisible: true, scrolled: true });
    assert.equal(h.scripts.length, 0);
});

test('in-place config revision invalidates old geometry history', () => {
    const h = harness(), c = config(); h.context.prep(c, false);
    const history = h.node.__hmInlineVideoHistory; h.scroll(1800);
    c.configVersion = 2; h.context.prep(c, false);
    assert.equal(history.take(), null);
    assert.equal(h.node.__hmInlineVideoHistory.take().wasInlineVisible, false);
});
