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

function runAtWidth(width, overrides = {}) {
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
    const definitions = [];
    const displayCalls = [];
    const listeners = [];
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
        cmd: { push(callback) { callback(); } },
        defineSlot(path, sizes, id) {
            const slot = { path, sizes, id, addService() { return slot; } };
            definitions.push(slot);
            return slot;
        },
        pubads() { return pubads; },
        enableServices() {},
        display(id) { displayCalls.push(id); },
        destroySlots() { return true; },
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
    const sandbox = { document, MutationObserver, console, innerWidth: width, innerHeight: 900, googletag };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'hm-gpt-direct.js' });
    return { target, attributes, definitions, displayCalls, listeners };
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
