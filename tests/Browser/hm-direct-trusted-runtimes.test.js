import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const isolatedSource = await readFile(new URL('../../public/assets/hm-isolated-direct.js', import.meta.url), 'utf8');
const gptSource = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');

function container(attributes, id = '') {
    const frames = [];
    return {
        id,
        frames,
        getAttribute(name) { return attributes[name] ?? null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(frame) {
            frames.push(frame);
            queueMicrotask(() => frame.onload?.());
            return frame;
        },
    };
}

function iframe() {
    const attributes = {};
    const sandboxValues = [];
    return {
        tagName: 'IFRAME',
        attributes,
        style: {},
        srcdoc: '',
        onload: null,
        onerror: null,
        sandbox: { add(value) { sandboxValues.push(String(value)); } },
        sandboxValues,
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return attributes[name] ?? null; },
    };
}

function run(source, selector, selectedContainer) {
    const createdFrames = [];
    const document = {
        documentElement: {},
        querySelectorAll(query) { return query === selector ? [selectedContainer] : []; },
        createElement(tag) {
            assert.equal(tag, 'iframe');
            const frame = iframe();
            createdFrames.push(frame);
            return frame;
        },
    };
    class MutationObserver {
        constructor(callback) { this.callback = callback; }
        observe() {}
    }
    const sandbox = {
        document,
        MutationObserver,
        queueMicrotask,
        setTimeout,
        clearTimeout,
        console,
        atob(value) { return Buffer.from(String(value), 'base64').toString('binary'); },
    };
    sandbox.window = sandbox;
    vm.runInNewContext(source, sandbox, { filename: 'trusted-direct-runtime.js' });
    return { sandbox, createdFrames };
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

test('isolated Direct Demand runtime preserves placement dimensions and sandboxing', async () => {
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad"></div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html': Buffer.from(html, 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from(csp, 'utf8').toString('base64'),
        'data-hm-isolated-width': '728',
        'data-hm-isolated-height': '90',
    };
    const target = container(attributes, 'isolated-zone');

    const { createdFrames } = run(isolatedSource, '[data-hm-isolated-direct="1"]', target);
    await tick();

    assert.equal(createdFrames.length, 1);
    const frame = createdFrames[0];
    assert.equal(frame.getAttribute('width'), '728');
    assert.equal(frame.getAttribute('height'), '90');
    assert.deepEqual(frame.sandboxValues, ['allow-scripts']);
    assert.match(frame.srcdoc, /Content-Security-Policy/);
    assert.ok(frame.srcdoc.includes(csp));
    assert.ok(frame.srcdoc.includes(html));
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
    assert.equal(attributes['data-hm-isolated-status'], 'requested');
});

test('Google GPT direct runtime builds only Horus-controlled GPT markup from normalized data', async () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250],[320,100]]',
    };
    const target = container(attributes, 'div-gpt-ad-lordai-header');

    const { createdFrames } = run(gptSource, '[data-hm-gpt-direct="1"]', target);
    await tick();

    assert.equal(createdFrames.length, 1);
    const frame = createdFrames[0];
    assert.equal(frame.getAttribute('width'), '320');
    assert.equal(frame.getAttribute('height'), '250');
    assert.match(frame.srcdoc, /https:\/\/securepubads\.g\.doubleclick\.net\/tag\/js\/gpt\.js/);
    assert.ok(frame.srcdoc.includes('/1234567/lordai_header'));
    assert.ok(frame.srcdoc.includes('[[300,250],[320,100]]'));
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'loaded');
    assert.equal(attributes['data-hm-gpt-status'], 'requested');
});

test('Google GPT direct runtime rejects non-normalized container identifiers', () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
    };
    const target = container(attributes, 'bad.id');

    const { createdFrames } = run(gptSource, '[data-hm-gpt-direct="1"]', target);

    assert.equal(createdFrames.length, 0);
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'invalid');
});
