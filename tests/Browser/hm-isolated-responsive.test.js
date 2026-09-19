import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/assets/hm-isolated-direct.js', import.meta.url), 'utf8');

function makeContainer(extra = {}) {
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html': Buffer.from(extra.html || '<script src="https://ads.example.org/ad.js"></script>', 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from(extra.csp || "default-src 'none'; script-src https://ads.example.org;", 'utf8').toString('base64'),
        'data-hm-isolated-width': String(extra.width || 300),
        'data-hm-isolated-height': String(extra.height || 250),
        ...(extra.attributes || {}),
    };
    const frames = [];
    return {
        attributes,
        frames,
        style: {},
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(frame) { frames.push(frame); queueMicrotask(() => frame.onload?.()); return frame; },
    };
}

function run(target, width, height = 800) {
    const document = {
        documentElement: { clientWidth: width, clientHeight: height },
        querySelectorAll(selector) { return selector === '[data-hm-isolated-direct="1"]' ? [target] : []; },
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const attrs = {};
            const sandboxValues = [];
            return {
                style: {}, srcdoc: '', onload: null, onerror: null,
                sandbox: { add(value) { sandboxValues.push(String(value)); } }, sandboxValues,
                setAttribute(name, value) { attrs[name] = String(value); },
                getAttribute(name) { return attrs[name] ?? null; },
            };
        },
    };
    class MutationObserver { constructor(callback) { this.callback = callback; } observe() {} }
    const sandbox = {
        document,
        MutationObserver,
        queueMicrotask,
        console,
        innerWidth: width,
        innerHeight: height,
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'hm-isolated-direct.js' });
    return target.frames[0];
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('isolated generic runtime selects a desktop size from reviewed responsive mappings', async () => {
    const target = makeContainer({ attributes: {
        'data-hm-isolated-sizes': '[[300,250],[970,250],[320,50]]',
        'data-hm-isolated-size-map': JSON.stringify([
            { minWidth: 1024, minHeight: 0, maxWidth: null, maxHeight: null, width: 970, height: 250, priority: 1 },
            { minWidth: 0, minHeight: 0, maxWidth: 767, maxHeight: null, width: 320, height: 50, priority: 1 },
        ]),
    }});

    const frame = run(target, 1440);
    await tick();

    assert.equal(frame.getAttribute('width'), '970');
    assert.equal(frame.getAttribute('height'), '250');
    assert.equal(target.attributes['data-hm-isolated-selected-width'], '970');
    assert.equal(target.style.width, '970px');
});

test('isolated generic runtime selects a mobile size from the same provider-agnostic placement', async () => {
    const target = makeContainer({ attributes: {
        'data-hm-isolated-sizes': '[[300,250],[970,250],[320,50]]',
        'data-hm-isolated-size-map': JSON.stringify([
            { minWidth: 1024, minHeight: 0, maxWidth: null, maxHeight: null, width: 970, height: 250, priority: 1 },
            { minWidth: 0, minHeight: 0, maxWidth: 767, maxHeight: null, width: 320, height: 50, priority: 1 },
        ]),
    }});

    const frame = run(target, 390);
    await tick();

    assert.equal(frame.getAttribute('width'), '320');
    assert.equal(frame.getAttribute('height'), '50');
    assert.equal(target.attributes['data-hm-isolated-selected-height'], '50');
});

test('isolated generic runtime remains backward compatible with legacy width and height attributes', async () => {
    const target = makeContainer({ width: 728, height: 90 });
    const frame = run(target, 1280);
    await tick();

    assert.equal(frame.getAttribute('width'), '728');
    assert.equal(frame.getAttribute('height'), '90');
    assert.deepEqual(frame.sandboxValues, ['allow-scripts']);
});

test('iframe-only provider markup is preserved inside the reviewed isolated document', async () => {
    const html = '<iframe src="https://ads.example.org/render" width="300" height="250"></iframe>';
    const csp = "default-src 'none'; script-src 'unsafe-inline'; frame-src https://ads.example.org;";
    const target = makeContainer({ html, csp });
    const frame = run(target, 1280);
    await tick();

    assert.ok(frame.srcdoc.includes(html));
    assert.ok(frame.srcdoc.includes(csp));
    assert.equal(target.attributes['data-hm-isolated-status'], 'loaded');
    assert.match(frame.srcdoc, /hm-isolated-rendered/);
});
