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
    const selectedContainers = Array.isArray(selectedContainer) ? selectedContainer : [selectedContainer];
    const document = {
        documentElement: {},
        querySelectorAll(query) { return query === selector ? selectedContainers : []; },
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

function chunkedAttributes(baseAttribute, value, chunkSize = 1800) {
    const encoded = Buffer.from(value, 'utf8').toString('base64');
    const parts = [];
    for (let offset = 0; offset < encoded.length; offset += chunkSize) {
        parts.push(encoded.slice(offset, offset + chunkSize));
    }
    const attributes = { [`${baseAttribute}-parts`]: String(parts.length) };
    parts.forEach((part, index) => { attributes[`${baseAttribute}-${index}`] = part; });
    return attributes;
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

test('isolated Direct Demand runtime reassembles payloads larger than public attribute limits', async () => {
    const marker = 'LONG-PROVIDER-PAYLOAD-';
    const html = '<script src="https://ads.example.com/ad.js"></script><div id="ad">' + marker.repeat(180) + '</div>';
    const csp = "default-src 'none'; script-src https://ads.example.com; connect-src https://ads.example.com; object-src 'none';";
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-width': '300',
        'data-hm-isolated-height': '250',
        ...chunkedAttributes('data-hm-isolated-html', html),
        ...chunkedAttributes('data-hm-isolated-csp', csp),
    };
    const target = container(attributes, 'chunked-isolated-zone');

    const { createdFrames } = run(isolatedSource, '[data-hm-isolated-direct="1"]', target);
    await tick();

    assert.ok(Object.keys(attributes).filter((key) => key.startsWith('data-hm-isolated-html-')).length > 2);
    assert.ok(Object.values(attributes).every((value) => String(value).length <= 2000));
    assert.equal(createdFrames.length, 1);
    assert.ok(createdFrames[0].srcdoc.includes(html));
    assert.ok(createdFrames[0].srcdoc.includes(csp));
    assert.deepEqual(createdFrames[0].sandboxValues, ['allow-scripts']);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'loaded');
});

test('isolated Direct Demand runtime rejects incomplete chunked payloads', () => {
    const attributes = {
        'data-hm-isolated-direct': '1',
        'data-hm-isolated-html-parts': '2',
        'data-hm-isolated-html-0': Buffer.from('<div>', 'utf8').toString('base64'),
        'data-hm-isolated-csp': Buffer.from("default-src 'none'; script-src https://ads.example.com;", 'utf8').toString('base64'),
    };
    const target = container(attributes, 'broken-isolated-zone');

    const { createdFrames } = run(isolatedSource, '[data-hm-isolated-direct="1"]', target);

    assert.equal(createdFrames.length, 0);
    assert.equal(attributes['data-hm-isolated-runtime-state'], 'invalid');
});

test('Google GPT direct runtime waits for slotRenderEnded instead of treating iframe load as success', async () => {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250],[320,100]]',
        'data-hm-gpt-inner-id': 'div-gpt-ad-lordai-header',
    };
    const target = container(attributes, 'hm-gpt-placement-1');

    const { createdFrames } = run(gptSource, '[data-hm-gpt-direct="1"]', target);
    await tick();

    assert.equal(createdFrames.length, 1);
    const frame = createdFrames[0];
    assert.equal(frame.getAttribute('width'), '320');
    assert.equal(frame.getAttribute('height'), '250');
    assert.match(frame.srcdoc, /https:\/\/securepubads\.g\.doubleclick\.net\/tag\/js\/gpt\.js/);
    assert.ok(frame.srcdoc.includes('/1234567/lordai_header'));
    assert.ok(frame.srcdoc.includes('[[300,250],[320,100]]'));
    assert.ok(frame.srcdoc.includes('div-gpt-ad-lordai-header'));
    assert.ok(frame.srcdoc.includes('hm-gpt-placement-1'));
    assert.ok(frame.srcdoc.includes('slotRenderEnded'));
    assert.ok(frame.srcdoc.includes('event.isEmpty?"empty":"rendered"'));
    assert.equal(attributes['data-hm-gpt-runtime-state'], 'loaded');
    assert.equal(attributes['data-hm-gpt-status'], undefined);
});

test('Google GPT runtime keeps outer placement state unique when provider inner ids repeat', async () => {
    const firstAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_a',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const secondAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/header_b',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-reused-div',
    };
    const first = container(firstAttributes, 'hm-gpt-placement-a');
    const second = container(secondAttributes, 'hm-gpt-placement-b');

    const { createdFrames } = run(gptSource, '[data-hm-gpt-direct="1"]', [first, second]);
    await tick();

    assert.equal(createdFrames.length, 2);
    assert.ok(createdFrames[0].srcdoc.includes('provider-reused-div'));
    assert.ok(createdFrames[1].srcdoc.includes('provider-reused-div'));
    assert.ok(createdFrames[0].srcdoc.includes('hm-gpt-placement-a'));
    assert.ok(!createdFrames[0].srcdoc.includes('hm-gpt-placement-b'));
    assert.ok(createdFrames[1].srcdoc.includes('hm-gpt-placement-b'));
    assert.ok(!createdFrames[1].srcdoc.includes('hm-gpt-placement-a'));
    assert.equal(firstAttributes['data-hm-gpt-runtime-state'], 'loaded');
    assert.equal(secondAttributes['data-hm-gpt-runtime-state'], 'loaded');
});

test('Google GPT direct runtime rejects non-normalized outer or inner identifiers', () => {
    const invalidOuterAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'provider-inner',
    };
    const invalidOuter = container(invalidOuterAttributes, 'bad.id');
    const invalidInnerAttributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/lordai_header',
        'data-hm-gpt-sizes': '[[300,250]]',
        'data-hm-gpt-inner-id': 'bad.inner',
    };
    const invalidInner = container(invalidInnerAttributes, 'hm-gpt-placement-valid');

    const { createdFrames } = run(gptSource, '[data-hm-gpt-direct="1"]', [invalidOuter, invalidInner]);

    assert.equal(createdFrames.length, 0);
    assert.equal(invalidOuterAttributes['data-hm-gpt-runtime-state'], 'invalid');
    assert.equal(invalidInnerAttributes['data-hm-gpt-runtime-state'], 'invalid');
});
