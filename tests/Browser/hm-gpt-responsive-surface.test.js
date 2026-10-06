import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

function makeContainer(attributes) {
    return {
        id: 'hm-gpt-responsive-test',
        style: {},
        childNodes: [],
        innerHTML: '',
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
    };
}

function runAtWidth(width, overrides = {}, { contentWidth, padding = 0, queued = false } = {}) {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_anchor',
        'data-hm-gpt-sizes': '[[300,50],[300,100],[320,50],[320,100],[728,90],[950,90],[960,90],[970,90],[980,90]]',
        'data-hm-gpt-inner-id': 'gpt-passback',
        'data-hm-gpt-size-map': JSON.stringify([
            { viewport: [0, 0], maxViewport: [767, 65535], sizes: [[300, 50], [300, 100], [320, 50], [320, 100]] },
            { viewport: [768, 0], maxViewport: [0, 0], sizes: [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]] },
        ]),
        ...overrides,
    };
    const target = makeContainer(attributes);
    const root = contentWidth === undefined ? null : { clientWidth: contentWidth };
    target.closest = () => root;
    const definitions = [];
    const displayCalls = [];
    const listeners = [];
    const commands = [];
    const destroyedSlots = [];
    const pubads = {
        addEventListener(name, callback) { if (name === 'slotRenderEnded') listeners.push(callback); },
        removeEventListener(name, callback) {
            if (name !== 'slotRenderEnded') return;
            const index = listeners.indexOf(callback);
            if (index >= 0) listeners.splice(index, 1);
        },
    };
    const googletag = {
        apiReady: true,
        cmd: { push(callback) { if (queued) commands.push(callback); else callback(); } },
        defineSlot(path, sizes, id) {
            const slot = { path, sizes, id, addService() { return slot; } };
            definitions.push(slot);
            return slot;
        },
        pubads() { return pubads; },
        enableServices() {},
        display(id) { displayCalls.push(id); },
        destroySlots(slots) { destroyedSlots.push(...slots); return true; },
    };
    const document = {
        documentElement: { clientWidth: width, clientHeight: 900 },
        head: { appendChild() {} },
        querySelectorAll(query) { return query === '[data-hm-gpt-direct="1"]' ? [target] : []; },
        getElementsByTagName(tag) { return tag === 'script' ? [] : []; },
        createElement() { throw new Error('GPT library must not be reinjected when apiReady is true'); },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = { document, MutationObserver, console, innerWidth: width, innerHeight: 900, googletag,
        getComputedStyle() { return { paddingLeft: padding + 'px', paddingRight: padding + 'px' }; },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'hm-gpt-direct.js' });
    return { target, attributes, definitions, displayCalls, listeners, commands, root, sandbox, destroyedSlots };
}

test('trusted GPT runtime exposes only mobile-mapped sizes to GPT on a mobile viewport', () => {
    const { attributes, definitions, displayCalls, target } = runAtWidth(390);
    assert.equal(definitions.length, 1);
    assert.deepEqual(
        JSON.parse(JSON.stringify(definitions[0].sizes)),
        [[300, 50], [300, 100], [320, 50], [320, 100]],
    );
    assert.deepEqual(JSON.parse(attributes['data-hm-gpt-eligible-sizes']), [[300, 50], [300, 100], [320, 50], [320, 100]]);
    assert.equal(definitions[0].id, 'hm-gpt-responsive-test');
    assert.deepEqual(displayCalls, ['hm-gpt-responsive-test']);
    assert.equal(target.style.width, '300px');
    assert.equal(target.style.height, '50px');
    assert.equal(attributes['data-hm-gpt-document-context'], 'publisher');
});

test('trusted GPT runtime exposes only desktop-mapped sizes to GPT on a desktop viewport', () => {
    const { attributes, definitions, target } = runAtWidth(1440);
    assert.equal(definitions.length, 1);
    assert.deepEqual(
        JSON.parse(JSON.stringify(definitions[0].sizes)),
        [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]],
    );
    assert.deepEqual(JSON.parse(attributes['data-hm-gpt-eligible-sizes']), [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]]);
    assert.equal(target.style.width, '728px');
    assert.equal(target.style.height, '90px');
});

test('valid mobile mapping with no declared-size intersection fails closed instead of restoring an oversized desktop slot', () => {
    const { attributes, definitions, displayCalls } = runAtWidth(390, {
        'data-hm-gpt-sizes': '[[728,90]]',
        'data-hm-gpt-size-map': JSON.stringify([
            { viewport: [0, 0], maxViewport: [767, 65535], sizes: [[300, 250]] },
            { viewport: [768, 0], maxViewport: [0, 0], sizes: [[728, 90]] },
        ]),
    });

    assert.equal(definitions.length, 0);
    assert.equal(displayCalls.length, 0);
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'ineligible');
    assert.equal(attributes['data-hm-gpt-eligible-sizes'], undefined);
});

test('mobile-only mapping no-fills on desktop when no mapping applies to the viewport', () => {
    const { attributes, definitions, displayCalls } = runAtWidth(1440, {
        'data-hm-gpt-sizes': '[[320,50],[320,100]]',
        'data-hm-gpt-size-map': JSON.stringify([
            { viewport: [0, 0], maxViewport: [767, 65535], sizes: [[320, 50], [320, 100]] },
        ]),
    });

    assert.equal(definitions.length, 0);
    assert.equal(displayCalls.length, 0);
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'ineligible');
    assert.equal(attributes['data-hm-gpt-eligible-sizes'], undefined);
});

test('responsive GPT runtime contains no iframe or srcdoc execution path', () => {
    assert.doesNotMatch(source, /srcdoc/i);
    assert.doesNotMatch(source, /createElement\(['"]iframe['"]\)/);
    assert.doesNotMatch(source, /setForceSafeFrame/);
});


test('trusted GPT runtime accepts an official fluid-only native slot', () => {
    const { attributes, definitions, displayCalls, listeners, target } = runAtWidth(390, {
        'data-hm-gpt-sizes': '["fluid"]',
        'data-hm-gpt-size-map': '',
    });

    assert.equal(definitions.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), ['fluid']);
    assert.deepEqual(displayCalls, ['hm-gpt-responsive-test']);
    assert.equal(target.style.width, '100%');
    assert.equal(target.style.height, '');
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'requested');

    assert.equal(listeners.length, 1);
    listeners[0]({ slot: definitions[0], isEmpty: false, size: null });

    assert.equal(attributes['data-hm-gpt-runtime-state'], 'rendered');
    assert.equal(attributes['data-hm-gpt-status'], 'rendered');
});

test('trusted GPT runtime preserves fluid alongside fixed in-article sizes', () => {
    const { definitions, listeners, attributes } = runAtWidth(1440, {
        'data-hm-gpt-sizes': '[[300,250],[336,280],"fluid"]',
        'data-hm-gpt-size-map': '',
    });

    assert.equal(definitions.length, 1);
    assert.deepEqual(
        JSON.parse(JSON.stringify(definitions[0].sizes)),
        [[300, 250], [336, 280], 'fluid'],
    );

    listeners[0]({ slot: definitions[0], isEmpty: false, size: [320, 180] });
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'rendered');
    assert.equal(attributes['data-hm-gpt-rendered-width'], '320');
    assert.equal(attributes['data-hm-gpt-rendered-height'], '180');
});

const mobileResponsive = [[300, 250], [336, 280], [320, 100], [320, 50], [300, 100], [300, 50], [250, 250], [200, 200], [240, 400], [250, 360]];
const desktopResponsive = [[970, 250], [970, 90], [728, 90], [468, 60], ...mobileResponsive, [300, 600]];
const responsiveAttributes = {
    'data-hm-gpt-ad-unit-path': '/1234567,7654321/article/responsive',
    'data-hm-gpt-fit-container': '1',
    'data-hm-gpt-sizes': JSON.stringify(desktopResponsive),
    'data-hm-gpt-size-map': JSON.stringify([
        { viewport: [0, 0], maxViewport: [767, 65535], sizes: mobileResponsive },
        { viewport: [768, 0], maxViewport: [1023, 65535], sizes: desktopResponsive.filter(size => size[0] < 970) },
        { viewport: [1024, 0], maxViewport: [0, 0], sizes: desktopResponsive },
    ]),
};

for (const [viewport, contentWidth, returnedSize] of [
    [909, 909, [728, 600]], // Exact LordAI production response: filled, but formerly discarded.
    [1440, 820, [728, 600]],
    [1440, 740, [728, 600]],
    [1440, 686, [640, 600]],
    [390, 358, [320, 600]],
    [390, 240, [200, 600]],
    [1440, 909, [728, 1000]],
    [390, 358, [114, 30]],
]) {
    test(`filled GPT ${returnedSize.join('x')} survives in ${contentWidth}px without a requested-size ratio restriction`, () => {
        const runtime = runAtWidth(viewport, responsiveAttributes, { contentWidth });
        const requested = JSON.parse(JSON.stringify(runtime.definitions[0].sizes));
        runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: returnedSize });
        assert.equal(runtime.attributes['data-hm-gpt-runtime-state'], 'rendered');
        assert.equal(runtime.target.style.width, returnedSize[0] + 'px');
        assert.equal(runtime.target.style.height, returnedSize[1] + 'px');
        assert.deepEqual(JSON.parse(JSON.stringify(runtime.definitions[0].sizes)), requested);
        assert.deepEqual(runtime.destroyedSlots, []);
        vm.runInNewContext(source, runtime.sandbox);
        assert.equal(runtime.displayCalls.length, 1);
    });
}

for (const returnedSize of [null, [], [728], [728, 0], [0, 600], [-1, 600],
    [728, Infinity], [NaN, 600], [728, 600.5], [728, 10001], ['728', 600], [true, 600]]) {
    test(`fixed GPT rejects malformed rendered dimensions ${JSON.stringify(returnedSize)}`, () => {
        const runtime = runAtWidth(1440, responsiveAttributes, { contentWidth: 909 });
        runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: returnedSize });
        assert.equal(runtime.attributes['data-hm-gpt-runtime-state'], 'failed');
        assert.deepEqual(runtime.destroyedSlots, [runtime.definitions[0]]);
    });
}

test('responsive no-fill stays empty even when the event includes a valid expanded size', () => {
    const runtime = runAtWidth(909, responsiveAttributes, { contentWidth: 909 });
    runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: true, size: [728, 600] });
    assert.equal(runtime.attributes['data-hm-gpt-runtime-state'], 'empty');
    assert.deepEqual(runtime.destroyedSlots, [runtime.definitions[0]]);
});

test('fluid support does not turn malformed fixed dimensions into a rendered ad', () => {
    const runtime = runAtWidth(390, {
        'data-hm-gpt-sizes': '["fluid"]', 'data-hm-gpt-size-map': '', 'data-hm-gpt-fit-container': '1',
    });
    runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: [-1, 600] });
    assert.equal(runtime.attributes['data-hm-gpt-runtime-state'], 'failed');
    assert.deepEqual(runtime.destroyedSlots, [runtime.definitions[0]]);
});

for (const fitAttribute of [undefined, '0']) {
    test(`non-responsive GPT preserves its previous size policy with fit-container=${fitAttribute}`, () => {
        const attributes = { ...responsiveAttributes };
        if (fitAttribute === undefined) delete attributes['data-hm-gpt-fit-container'];
        else attributes['data-hm-gpt-fit-container'] = fitAttribute;
        const runtime = runAtWidth(909, attributes, { contentWidth: 909 });
        runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: [728, 600] });
        assert.equal(runtime.attributes['data-hm-gpt-runtime-state'], 'failed');
        assert.deepEqual(runtime.destroyedSlots, [runtime.definitions[0]]);
    });
}

test('expanded responsive display intersects desktop mapping with the publisher content width', () => {
    const { definitions } = runAtWidth(1440, responsiveAttributes, { contentWidth: 700 });
    assert.equal(definitions.length, 1);
    assert.equal(definitions[0].path, '/1234567,7654321/article/responsive');
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[468, 60], ...mobileResponsive, [300, 600]]);
});

test('mobile responsive requests exclude tall desktop demand and subtract placement padding', () => {
    const { definitions } = runAtWidth(390, responsiveAttributes, { contentWidth: 350, padding: 16 });
    assert.equal(definitions.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[300, 250], [300, 100], [300, 50], [250, 250], [200, 200], [240, 400], [250, 360]]);
});

test('a narrow responsive sidebar can request its 200-square fallback without overflowing', () => {
    const { definitions } = runAtWidth(1440, responsiveAttributes, { contentWidth: 240 });
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[200, 200], [240, 400]]);
});

test('hidden or too narrow responsive placements do not make an ad request', () => {
    for (const contentWidth of [0, 190]) {
        const { definitions, displayCalls, attributes } = runAtWidth(1440, responsiveAttributes, { contentWidth });
        assert.equal(definitions.length, 0);
        assert.equal(displayCalls.length, 0);
        assert.equal(attributes['data-hm-gpt-runtime-state'], 'ineligible');
    }
});

test('responsive width enforcement still applies when a provider supplies no size mapping', () => {
    const { definitions } = runAtWidth(1440, { ...responsiveAttributes, 'data-hm-gpt-size-map': '' }, { contentWidth: 240 });
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[200, 200], [240, 400]]);
});

test('responsive layout is rechecked after GPT loads but does not refresh after a request', () => {
    const { root, commands, sandbox, definitions, displayCalls } = runAtWidth(1440, responsiveAttributes, { contentWidth: 1000, queued: true });
    assert.equal(definitions.length, 0);
    root.clientWidth = 240;
    commands.shift()();
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[200, 200], [240, 400]]);
    root.clientWidth = 1000;
    vm.runInNewContext(source, sandbox);
    assert.equal(definitions.length, 1);
    assert.equal(displayCalls.length, 1);
});

test('creative expansion cannot overflow an explicitly width-constrained responsive placement', () => {
    const { definitions, listeners, attributes } = runAtWidth(1440, responsiveAttributes, { contentWidth: 320 });
    listeners[0]({ slot: definitions[0], isEmpty: false, size: [400, 250] });
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'failed');
});

test('existing sticky mappings are unchanged by an unrelated parent container measurement', () => {
    const { definitions } = runAtWidth(1440, {}, { contentWidth: 240 });
    assert.deepEqual(JSON.parse(JSON.stringify(definitions[0].sizes)), [[728, 90], [950, 90], [960, 90], [970, 90], [980, 90]]);
});

test('GPT paths reject malformed hierarchy or arbitrary URL input', () => {
    for (const path of ['/123//responsive', '/123/responsive/', '/123,456,789/responsive', 'https://example.test/path', '/123/<script>']) {
        const { definitions, attributes } = runAtWidth(390, { ...responsiveAttributes, 'data-hm-gpt-ad-unit-path': path });
        assert.equal(definitions.length, 0);
        assert.equal(attributes['data-hm-gpt-runtime-state'], 'invalid');
    }
});

const fluidResponsiveAttributes = {
    ...responsiveAttributes,
    'data-hm-gpt-responsive-fluid': '1',
    'data-hm-gpt-sizes': JSON.stringify([...desktopResponsive, 'fluid']),
};

for (const viewport of [390, 800, 1440]) {
    for (const contentWidth of [239, 240, 249, 250]) {
        test(`Responsive GAM paths retain portrait request sizes in ${contentWidth}px host at viewport ${viewport}`, () => {
            const runtime = runAtWidth(viewport, fluidResponsiveAttributes, { contentWidth });
            const sizes = JSON.parse(JSON.stringify(runtime.definitions[0].sizes));
            assert.equal(sizes.some(size => size[0] === 240 && size[1] === 400), true);
            assert.equal(sizes.some(size => size[0] === 250 && size[1] === 360), true);
            assert.equal(sizes.filter(size => size === 'fluid').length, 1);
            assert.equal(runtime.displayCalls.length, 1);
        });
    }
}

for (const [viewport, contentWidth] of [[390, 350], [800, 760], [1440, 1000], [1440, 190]]) {
    test(`Responsive GAM path retains fixed sizing and fluid at viewport ${viewport} in ${contentWidth}px`, () => {
        const runtime = runAtWidth(viewport, fluidResponsiveAttributes, { contentWidth });
        const fixed = runAtWidth(viewport, responsiveAttributes, { contentWidth: viewport });
        const fixedSizes = fixed.definitions[0]?.sizes || [];
        assert.deepEqual(JSON.parse(JSON.stringify(runtime.definitions[0].sizes)), [...JSON.parse(JSON.stringify(fixedSizes)), 'fluid']);
        assert.equal(runtime.target.style.width, '100%');
        assert.equal(runtime.target.style.height, '');
        runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: 'fluid' });
        assert.equal(runtime.attributes['data-hm-gpt-status'], 'rendered');
        assert.equal(runtime.target.style.width, '100%');
        assert.equal(runtime.target.style.height, '');
        assert.equal(runtime.target.style.minHeight, '');
        assert.deepEqual(runtime.destroyedSlots, []);
        vm.runInNewContext(source, runtime.sandbox);
        assert.equal(runtime.displayCalls.length, 1);
    });
}

test('fluid default requires both server opt-in and declared fluid, and never bypasses a hidden container', () => {
    const noDeclaredFluid = runAtWidth(390, { ...responsiveAttributes, 'data-hm-gpt-responsive-fluid': '1' }, { contentWidth: 350 });
    assert.equal(noDeclaredFluid.definitions[0].sizes.includes('fluid'), false);
    const providerTag = runAtWidth(390, { ...fluidResponsiveAttributes, 'data-hm-gpt-responsive-fluid': '0' }, { contentWidth: 350 });
    assert.equal(providerTag.definitions[0].sizes.includes('fluid'), false);
    const hidden = runAtWidth(390, fluidResponsiveAttributes, { contentWidth: 0 });
    assert.equal(hidden.definitions.length, 0);
    const unmatched = runAtWidth(1440, { ...fluidResponsiveAttributes, 'data-hm-gpt-size-map': JSON.stringify([{ viewport: [0, 0], maxViewport: [767, 0], sizes: mobileResponsive }]) }, { contentWidth: 350 });
    assert.equal(unmatched.definitions.length, 0);
});

test('mixed Responsive slots release reserved height for a smaller fixed creative and keep no-fill terminal', () => {
    const fixed = runAtWidth(390, fluidResponsiveAttributes, { contentWidth: 350 });
    fixed.listeners[0]({ slot: fixed.definitions[0], isEmpty: false, size: [320, 50] });
    assert.equal(fixed.target.style.width, '320px');
    assert.equal(fixed.target.style.height, '50px');
    assert.equal(fixed.target.style.minHeight, '');
    const empty = runAtWidth(390, fluidResponsiveAttributes, { contentWidth: 350 });
    empty.listeners[0]({ slot: empty.definitions[0], isEmpty: true, size: null });
    assert.equal(empty.attributes['data-hm-gpt-status'], 'empty');
    assert.deepEqual(empty.destroyedSlots, [empty.definitions[0]]);
});

test('delayed GPT keeps the viewport size set when a publisher column narrows', () => {
    const runtime = runAtWidth(1440, fluidResponsiveAttributes, { contentWidth: 1000, queued: true });
    runtime.root.clientWidth = 190;
    runtime.commands.shift()();
    assert.deepEqual(JSON.parse(JSON.stringify(runtime.definitions[0].sizes)), [...desktopResponsive, 'fluid']);
    runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: null });
    assert.equal(runtime.attributes['data-hm-gpt-status'], 'rendered');
});

test('delayed GPT rechecks viewport selection and hidden hosts before requesting', () => {
    const resized = runAtWidth(1440, fluidResponsiveAttributes, { contentWidth: 240, queued: true });
    resized.sandbox.innerWidth = 390;
    resized.commands.shift()();
    assert.deepEqual(JSON.parse(JSON.stringify(resized.definitions[0].sizes)), [...mobileResponsive, 'fluid']);
    const hidden = runAtWidth(1440, fluidResponsiveAttributes, { contentWidth: 600, queued: true });
    hidden.root.clientWidth = 0;
    hidden.commands.shift()();
    assert.equal(hidden.definitions.length, 0);
});

test('GAM path requests remain viewport-bounded and accept wider-than-host fixed winners', () => {
    const runtime = runAtWidth(320, fluidResponsiveAttributes, { contentWidth: 190, padding: 16 });
    const sizes = JSON.parse(JSON.stringify(runtime.definitions[0].sizes));
    assert.deepEqual(sizes, [...mobileResponsive.filter(size => size[0] <= 320), 'fluid']);
    runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: [300, 250] });
    assert.equal(runtime.attributes['data-hm-gpt-status'], 'rendered');
    assert.equal(runtime.target.style.width, '300px');
    assert.equal(runtime.target.style.height, '250px');
    assert.equal(runtime.target.style.maxWidth, 'none');
    assert.deepEqual(runtime.destroyedSlots, []);
});

test('viewport request bounds account for the scrollbar content width', () => {
    const runtime = runAtWidth(340, fluidResponsiveAttributes, { contentWidth: 190, queued: true });
    runtime.sandbox.document.documentElement.clientWidth = 325;
    runtime.commands.shift()();
    assert.equal(runtime.definitions[0].sizes.some(size => size[0] === 336), false);
    assert.equal(runtime.definitions[0].sizes.some(size => size[0] === 320), true);
});

test('outer layout preserves actual creative dimensions, repositions without refresh and cleans up on detach', () => {
    const runtime = runAtWidth(1280, fluidResponsiveAttributes, { contentWidth: 600 });
    const handlers = new Set();
    runtime.sandbox.addEventListener = (name, handler) => { if (name === 'resize') handlers.add(handler); };
    runtime.sandbox.removeEventListener = (name, handler) => { if (name === 'resize') handlers.delete(handler); };
    runtime.root.getBoundingClientRect = () => ({ left: 100, width: runtime.root.clientWidth });
    runtime.target.getBoundingClientRect = () => ({ left: 100 + (parseFloat(runtime.target.style.left) || 0) });
    runtime.listeners[0]({ slot: runtime.definitions[0], isEmpty: false, size: [970, 250] });
    assert.equal(runtime.target.style.width, '970px');
    assert.equal(runtime.target.style.height, '250px');
    assert.equal(runtime.target.style.left, '-100px');
    assert.equal(runtime.attributes['data-hm-gpt-layout'], 'publisher-container-too-narrow');
    assert.equal(handlers.size, 1);
    runtime.sandbox.document.documentElement.clientWidth = 390;
    handlers.forEach(handler => handler());
    assert.equal(runtime.attributes['data-hm-gpt-layout'], 'viewport-too-narrow');
    assert.equal(runtime.target.style.width, '970px');
    assert.equal(runtime.displayCalls.length, 1);
    runtime.target.isConnected = false;
    runtime.sandbox.__HORUS_GPT_DIRECT_RUNTIME_V5__.observer.callback([{ addedNodes: [], removedNodes: [runtime.target] }]);
    assert.equal(handlers.size, 0);
    runtime.target.isConnected = true;
    vm.runInNewContext(source, runtime.sandbox);
    assert.equal(handlers.size, 1);
    assert.equal(runtime.displayCalls.length, 1);
});
